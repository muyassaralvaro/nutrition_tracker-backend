<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'name_id', 'source', 'source_id', 'source_url', 'calories', 'protein', 'carbs', 'fat', 'fiber', 'sodium', 'potassium', 'calcium', 'iron'])]
class Food extends Model
{
    protected $table = 'foods';

    protected function casts(): array
    {
        return [
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
