<?php

namespace App\Jobs;

use App\Ai\Agents\MealPhotoAgent;
use App\Models\Food;
use App\Models\MealAnalysis;
use App\NutritionCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class AnalyzeMealPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public string $analysisId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5];
    }

    public function handle(NutritionCalculator $calculator): void
    {
        $analysis = MealAnalysis::with('user')->find($this->analysisId);

        if ($analysis === null || $analysis->user === null || $analysis->expires_at->isPast() || ! in_array($analysis->status, ['queued', 'processing'], true)) {
            return;
        }

        if (! config('ai.nutrition_enabled') || ! Storage::disk('local')->exists($analysis->image_path)) {
            $analysis->update(['status' => 'failed', 'error_code' => 'provider_unavailable']);

            return;
        }

        if (! $this->reserveAttempt()) {
            $analysis->update(['status' => 'failed', 'error_code' => 'quota_exhausted']);

            return;
        }

        $analysis->update(['status' => 'processing']);
        $response = MealPhotoAgent::make()->prompt(
            'Describe the visible meal for an editable food log. List dishes or visible ingredients and estimated grams. Do not report nutrients.',
            attachments: [Image::fromStorage($analysis->image_path, 'local')],
            provider: 'nine_router',
            model: config('ai.providers.nine_router.models.text.default'),
            timeout: 30,
        );

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('Provider returned no structured meal response.');
        }

        $result = Validator::make($response->toArray(), [
            'title' => ['required', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:12'],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.grams' => ['nullable', 'numeric', 'between:0.1,2000'],
            'items.*.uncertain' => ['required', 'boolean'],
            'items.*.note' => ['present', 'string', 'max:200'],
        ])->validate();

        $items = [];

        foreach ($result['items'] as $item) {
            $name = trim($item['name']);
            $grams = $item['grams'] ?? null;
            $matches = Food::query()->where('name', $name)->orWhere('name_id', $name)->limit(5)->get();
            $exact = $matches->count() === 1 ? $matches->first() : null;

            if ($matches->isEmpty()) {
                $matches = Food::query()->where('name', 'like', '%'.$name.'%')->orWhere('name_id', 'like', '%'.$name.'%')->limit(5)->get();
            }

            $items[] = [
                'name' => $name,
                'grams' => $grams,
                'food_id' => $exact?->id,
                'nutrients' => $exact && $grams ? $calculator->fromFood($exact, (float) $grams) : array_fill_keys(NutritionCalculator::NUTRIENTS, null),
                'uncertain' => $item['uncertain'] || $exact === null || $grams === null,
                'note' => $item['note'],
                'candidates' => $matches->map(fn (Food $food) => [
                    'id' => $food->id,
                    'name' => $food->name,
                    'name_id' => $food->name_id,
                    'source' => $food->source,
                    'source_id' => $food->source_id,
                    'nutrients_per_100g' => $food->only(NutritionCalculator::NUTRIENTS),
                ])->all(),
            ];
        }

        MealAnalysis::query()->whereKey($this->analysisId)->where('status', 'processing')->where('expires_at', '>', now())
            ->whereHas('user')->update([
                'status' => 'succeeded',
                'draft' => json_encode(['title' => trim($result['title']), 'items' => $items], JSON_THROW_ON_ERROR),
                'error_code' => null,
            ]);
    }

    public function failed(Throwable $exception): void
    {
        MealAnalysis::query()->whereKey($this->analysisId)->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'failed', 'error_code' => 'analysis_failed']);
    }

    private function reserveAttempt(): bool
    {
        return DB::transaction(function (): bool {
            $today = now('UTC')->toDateString();
            DB::table('ai_usage_days')->insertOrIgnore(['usage_date' => $today, 'attempts' => 0]);
            $usage = DB::table('ai_usage_days')->where('usage_date', $today)->lockForUpdate()->first();

            if ($usage->attempts >= config('ai.daily_attempt_limit')) {
                return false;
            }

            DB::table('ai_usage_days')->where('usage_date', $today)->increment('attempts');

            return true;
        });
    }
}
