<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_request_id', 'create_payload_sha256', 'meal_date', 'meal_time', 'title', 'source', 'thumbnail_path'])]
class Meal extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return ['meal_date' => 'date:Y-m-d'];
    }
}
