<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['effective_on', 'source', 'formula_version', 'calories', 'protein', 'carbs', 'fat', 'fiber', 'sodium', 'potassium', 'calcium', 'iron'])]
class NutrientTarget extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'effective_on' => 'date:Y-m-d',
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
