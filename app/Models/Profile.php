<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['birth_date', 'height_cm', 'sex', 'body_build', 'body_fat_percent', 'goal', 'goal_weight_kg', 'activity_minutes', 'activity_type', 'timezone', 'unit_system', 'avatar_path'])]
class Profile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'height_cm' => 'float',
            'body_fat_percent' => 'float',
            'goal_weight_kg' => 'float',
            'activity_minutes' => 'integer',
        ];
    }
}
