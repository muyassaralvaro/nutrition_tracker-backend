<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['food_id', 'position', 'name', 'grams', 'calories', 'protein', 'carbs', 'fat', 'fiber', 'sodium', 'potassium', 'calcium', 'iron'])]
class MealItem extends Model
{
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'grams' => 'float',
            'calories' => 'float',
            'protein' => 'float',
            'carbs' => 'float',
            'fat' => 'float',
            'fiber' => 'float',
            'sodium' => 'float',
            'potassium' => 'float',
            'calcium' => 'float',
            'iron' => 'float',
        ];
    }
}
