<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MealResource;
use App\Models\Food;
use App\Models\Meal;
use App\NutritionCalculator;
use GdImage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MealController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = $filters['date'] ?? now($request->user()->profile?->timezone ?? 'UTC')->toDateString();
        $meals = $request->user()->meals()->with('items')
            ->whereDate('meal_date', $date)
            ->orderByDesc('meal_date')->orderByDesc('meal_time')->orderByDesc('id')->paginate(20);

        return MealResource::collection($meals);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request, true));
        $data['title'] = trim($data['title']);

        foreach ($data['items'] as &$item) {
            $item['name'] = trim($item['name']);
        }
        unset($item);

        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));
        $existing = $request->user()->meals()->where('client_request_id', $data['client_request_id'])->first();

        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        $analysis = null;

        if (! empty($data['analysis_id'])) {
            $analysis = $request->user()->analyses()->whereKey($data['analysis_id'])->where('expires_at', '>', now())->firstOrFail();
            abort_if($analysis->status === 'canceled', 422, 'Analysis was canceled.');
            abort_if($analysis->status === 'succeeded' && (count($data['items']) !== 1 || empty(trim($data['items'][0]['description'] ?? ''))), 422, 'Confirm one dish and its description.');
        }

        // ponytail: Manual and older meals have no saved photo; add optional image upload when they need thumbnails.
        $thumbnailPath = $analysis?->image_path ? 'meal-thumbnails/'.Str::uuid().'.jpg' : null;

        try {
            if ($thumbnailPath !== null) {
                $this->saveThumbnail($analysis->image_path, $thumbnailPath);
            }

            $meal = DB::transaction(function () use ($request, $data, $hash, $analysis, $thumbnailPath): Meal {
                $meal = $request->user()->meals()->create([
                    'client_request_id' => $data['client_request_id'],
                    'create_payload_sha256' => $hash,
                    'meal_date' => $data['meal_date'],
                    'meal_time' => $data['meal_time'],
                    'title' => $data['title'],
                    'source' => $analysis ? 'photo' : 'manual',
                    'thumbnail_path' => $thumbnailPath,
                ]);

                $this->replaceItems($meal, $data['items']);
                if ($analysis?->status === 'succeeded') {
                    $this->syncDishReference($meal, $data['items']);
                }
                $analysis?->delete();

                return $meal;
            });
        } catch (\Throwable $exception) {
            if ($thumbnailPath !== null) {
                Storage::disk('local')->delete($thumbnailPath);
            }

            if ($exception instanceof QueryException) {
                $existing = $request->user()->meals()->where('client_request_id', $data['client_request_id'])->first();

                if ($existing !== null) {
                    return $this->replay($existing, $hash);
                }
            }

            throw $exception;
        }

        if ($analysis?->image_path) {
            Storage::disk('local')->delete($analysis->image_path);
        }

        return (new MealResource($meal->load('items')))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $id): MealResource
    {
        return new MealResource($request->user()->meals()->with('items')->findOrFail($id));
    }

    public function thumbnail(Request $request, int $id): Response
    {
        $meal = $request->user()->meals()->findOrFail($id);
        abort_unless($meal->thumbnail_path && Storage::disk('local')->exists($meal->thumbnail_path), 404);

        return response(Storage::disk('local')->get($meal->thumbnail_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, int $id): MealResource
    {
        $meal = $request->user()->meals()->findOrFail($id);
        $data = $request->validate($this->rules($request, false));

        DB::transaction(function () use ($meal, $data): void {
            $meal->update(['meal_date' => $data['meal_date'], 'meal_time' => $data['meal_time'], 'title' => trim($data['title'])]);
            $meal->items()->delete();
            $this->replaceItems($meal, $data['items']);
            if ($meal->source === 'photo') {
                $this->syncDishReference($meal, $data['items']);
            }
        });

        return new MealResource($meal->load('items'));
    }

    public function destroy(Request $request, int $id): Response
    {
        $meal = $request->user()->meals()->findOrFail($id);
        $thumbnailPath = $meal->thumbnail_path;
        DB::transaction(function () use ($meal): void {
            Food::query()->where('source', 'confirmed-photo')->where('source_id', (string) $meal->id)->delete();
            $meal->delete();
        });
        if ($thumbnailPath !== null) {
            Storage::disk('local')->delete($thumbnailPath);
        }

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, bool $create): array
    {
        $today = now($request->user()->profile?->timezone ?? 'UTC')->toDateString();
        $rules = [
            'meal_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:1900-01-01'],
            'meal_time' => ['required', 'date_format:H:i'],
            'title' => ['required', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.grams' => ['nullable', 'numeric', 'between:0.1,2000'],
        ];

        foreach (NutritionCalculator::LIMITS as $nutrient => $limit) {
            $required = in_array($nutrient, ['calories', 'protein', 'carbs', 'fat'], true);
            $rules['items.*.nutrients.'.$nutrient] = [$required ? 'required' : 'nullable', 'numeric', 'between:0,'.$limit];
        }

        if ($create) {
            $rules['client_request_id'] = ['required', 'uuid'];
            $rules['analysis_id'] = ['nullable', 'uuid'];
        }

        return $rules;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceItems(Meal $meal, array $items): void
    {
        foreach ($items as $position => $item) {
            $meal->items()->create([
                'position' => $position,
                'food_id' => null,
                'name' => trim($item['name']),
                'description' => isset($item['description']) ? trim($item['description']) : null,
                'grams' => $item['grams'] ?? null,
                ...$item['nutrients'],
            ]);
        }
    }

    private function saveThumbnail(string $sourcePath, string $thumbnailPath): void
    {
        $source = @imagecreatefromstring(Storage::disk('local')->get($sourcePath));

        if (! $source instanceof GdImage) {
            throw new RuntimeException('Meal photo could not be decoded.');
        }

        $size = min(imagesx($source), imagesy($source));
        $thumbnail = imagecreatetruecolor(240, 240);
        $copied = imagecopyresampled($thumbnail, $source, 0, 0, intdiv(imagesx($source) - $size, 2), intdiv(imagesy($source) - $size, 2), 240, 240, $size, $size);
        imagedestroy($source);

        if (! $copied) {
            imagedestroy($thumbnail);
            throw new RuntimeException('Meal thumbnail could not be prepared.');
        }

        ob_start();
        imagejpeg($thumbnail, null, 82);
        $bytes = ob_get_clean();
        imagedestroy($thumbnail);

        if (! is_string($bytes) || $bytes === '' || ! Storage::disk('local')->put($thumbnailPath, $bytes)) {
            throw new RuntimeException('Meal thumbnail could not be stored.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncDishReference(Meal $meal, array $items): void
    {
        $reference = Food::query()->where('source', 'confirmed-photo')->where('source_id', (string) $meal->id);

        if (count($items) !== 1 || empty(trim($items[0]['description'] ?? '')) || ($items[0]['grams'] ?? 0) < 1) {
            $reference->delete();

            return;
        }

        $item = $items[0];
        $grams = (float) $item['grams'];
        $nutrients = [];

        foreach (NutritionCalculator::NUTRIENTS as $nutrient) {
            $nutrients[$nutrient] = isset($item['nutrients'][$nutrient])
                ? round((float) $item['nutrients'][$nutrient] * 100 / $grams, 2) : null;
        }

        $food = Food::updateOrCreate(['source' => 'confirmed-photo', 'source_id' => (string) $meal->id], [
            'user_id' => $meal->user_id,
            'name' => trim($item['name']),
            'description' => trim($item['description']),
            'serving_grams' => $grams,
            ...$nutrients,
        ]);
        $meal->items()->firstOrFail()->update(['food_id' => $food->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function canonical(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $payload;
    }

    private function replay(Meal $meal, string $hash): JsonResponse
    {
        abort_unless($meal->create_payload_sha256 === $hash, 409, 'Request ID was already used with different meal data.');

        return (new MealResource($meal->load('items')))->response();
    }
}
