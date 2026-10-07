<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MealAnalysisResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'draft' => $this->draft,
            'error_code' => $this->error_code,
            'expires_at' => $this->expires_at->toIso8601String(),
            'image_url' => $this->image_path ? route('meal-analyses.image', $this->id, false) : null,
        ];
    }
}
