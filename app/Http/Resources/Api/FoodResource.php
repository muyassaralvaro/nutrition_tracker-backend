<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FoodResource extends JsonResource
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
            'name' => $this->name,
            'name_id' => $this->name_id,
            'source' => $this->source,
            'source_id' => $this->source_id,
            'source_url' => $this->source_url,
            'nutrients_per_100g' => $this->only(['calories', 'protein', 'carbs', 'fat', 'fiber', 'sodium', 'potassium', 'calcium', 'iron']),
        ];
    }
}
