<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    protected $fillable = ['meal_order_id', 'actor_role', 'event', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function mealOrder(): BelongsTo
    {
        return $this->belongsTo(MealOrder::class);
    }
}
