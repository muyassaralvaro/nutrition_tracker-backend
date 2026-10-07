<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'name_id', 'description', 'serving_grams', 'source', 'source_id', 'source_url', 'calories', 'protein', 'carbs', 'fat', 'fiber', 'sodium', 'potassium', 'calcium', 'iron'])]
class Food extends Model
{
    protected $table = 'foods';

    protected function casts(): array
    {
        return [
            'serving_grams' => 'float',
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
