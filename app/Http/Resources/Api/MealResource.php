<?php

namespace App\Http\Resources\Api;

use App\NutritionCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MealResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meal_date' => $this->meal_date->toDateString(),
            'meal_time' => substr($this->meal_time, 0, 5),
            'title' => $this->title,
            'source' => $this->source,
            'thumbnail_url' => $this->thumbnail_path ? route('meals.thumbnail', $this->id, false) : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'food_id' => $item->food_id,
                'name' => $item->name,
                'description' => $item->description,
                'grams' => $item->grams,
                'nutrients' => $item->only(NutritionCalculator::NUTRIENTS),
            ])),
            'nutrition' => $this->whenLoaded('items', fn () => (new NutritionCalculator)->summarize($this->items, null, true)),
        ];
    }
}
