<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MealResource;
use App\Models\Food;
use App\Models\Meal;
use App\NutritionCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

        $this->validateFoods($data['items']);
        $analysis = null;

        if (! empty($data['analysis_id'])) {
            $analysis = $request->user()->analyses()->whereKey($data['analysis_id'])->where('expires_at', '>', now())->firstOrFail();
            abort_if($analysis->status === 'canceled', 422, 'Analysis was canceled.');
        }

        try {
            $meal = DB::transaction(function () use ($request, $data, $hash, $analysis): Meal {
                $meal = $request->user()->meals()->create([
                    'client_request_id' => $data['client_request_id'],
                    'create_payload_sha256' => $hash,
                    'meal_date' => $data['meal_date'],
                    'meal_time' => $data['meal_time'],
                    'title' => $data['title'],
                    'source' => $analysis ? 'photo' : 'manual',
                ]);

                $this->replaceItems($meal, $data['items']);
                $analysis?->delete();

                return $meal;
            });
        } catch (QueryException $exception) {
            $existing = $request->user()->meals()->where('client_request_id', $data['client_request_id'])->first();

            if ($existing !== null) {
                return $this->replay($existing, $hash);
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

    public function update(Request $request, int $id): MealResource
    {
        $meal = $request->user()->meals()->findOrFail($id);
        $data = $request->validate($this->rules($request, false));
        $this->validateFoods($data['items']);

        DB::transaction(function () use ($meal, $data): void {
            $meal->update(['meal_date' => $data['meal_date'], 'meal_time' => $data['meal_time'], 'title' => trim($data['title'])]);
            $meal->items()->delete();
            $this->replaceItems($meal, $data['items']);
        });

        return new MealResource($meal->load('items'));
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->meals()->findOrFail($id)->delete();

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
            'items.*.food_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:160'],
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
    private function validateFoods(array $items): void
    {
        $ids = collect($items)->pluck('food_id')->filter()->unique()->all();
        $known = Food::whereIn('id', $ids)->pluck('id')->all();
        $errors = [];

        foreach ($items as $index => $item) {
            if (! empty($item['food_id']) && ! in_array((int) $item['food_id'], $known, true)) {
                $errors['items.'.$index.'.food_id'] = 'Choose a food from the catalog.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceItems(Meal $meal, array $items): void
    {
        foreach ($items as $position => $item) {
            $meal->items()->create([
                'position' => $position,
                'food_id' => $item['food_id'] ?? null,
                'name' => trim($item['name']),
                'grams' => $item['grams'] ?? null,
                ...$item['nutrients'],
            ]);
        }
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
