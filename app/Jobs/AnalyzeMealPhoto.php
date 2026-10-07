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

    public function __construct(public string $analysisId, public string $foodContext = '') {}

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
        // ponytail: Recent confirmed dishes help when users give no details; add image similarity search when dish history outgrows this window.
        $references = $this->foodContext === '' ? Food::query()->where('source', 'confirmed-photo')->latest('id')->limit(12)->get()
            ->filter(fn (Food $food) => ! preg_match('/\p{Han}/u', $food->name.' '.$food->description))
            ->map(fn (Food $food) => [
                'name' => $food->name,
                'description' => $food->description,
                'serving_grams' => $food->serving_grams,
                'nutrients_per_serving' => $food->serving_grams ? $calculator->fromFood($food, $food->serving_grams) : null,
            ])->values()->all() : [];
        $language = $analysis->language === 'id' ? 'Indonesian' : 'English';
        $foodContext = $this->foodContext === '' ? '' : ' User-described dish and serving: '.json_encode($this->foodContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).'. Prefer this identity and named toppings when compatible with the photo.';
        $response = MealPhotoAgent::make()->prompt(
            "Analyze this photo as one dish. Return title and description in {$language} only, using Latin letters. Estimate calories and nutrients for the whole visible serving. Use reference dishes only if they genuinely match the photo; otherwise use general food knowledge.{$foodContext} Reference dishes: ".json_encode($references, JSON_THROW_ON_ERROR),
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
            'description' => ['required', 'string', 'max:200'],
            'grams' => ['required', 'numeric', 'between:1,2000'],
            'nutrients' => ['required', 'array'],
            'nutrients.calories' => ['required', 'numeric', 'between:0,6000'],
            'nutrients.protein' => ['required', 'numeric', 'between:0,1000'],
            'nutrients.carbs' => ['required', 'numeric', 'between:0,1500'],
            'nutrients.fat' => ['required', 'numeric', 'between:0,800'],
            'nutrients.fiber' => ['nullable', 'numeric', 'between:0,200'],
            'nutrients.sodium' => ['nullable', 'numeric', 'between:0,20000'],
            'nutrients.potassium' => ['nullable', 'numeric', 'between:0,20000'],
            'nutrients.calcium' => ['nullable', 'numeric', 'between:0,10000'],
            'nutrients.iron' => ['nullable', 'numeric', 'between:0,500'],
        ])->validate();

        if (preg_match('/\p{Han}/u', $result['title'].' '.$result['description'])) {
            $analysis->update(['status' => 'failed', 'error_code' => 'language_mismatch']);

            return;
        }

        $nutrients = [];

        foreach (NutritionCalculator::NUTRIENTS as $nutrient) {
            $nutrients[$nutrient] = isset($result['nutrients'][$nutrient]) ? round((float) $result['nutrients'][$nutrient], 2) : null;
        }

        MealAnalysis::query()->whereKey($this->analysisId)->where('status', 'processing')->where('expires_at', '>', now())
            ->whereHas('user')->update([
                'status' => 'succeeded',
                'draft' => json_encode(['title' => trim($result['title']), 'items' => [[
                    'food_id' => null,
                    'name' => trim($result['title']),
                    'description' => trim($result['description']),
                    'grams' => (float) $result['grams'],
                    'nutrients' => $nutrients,
                ]]], JSON_THROW_ON_ERROR),
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
