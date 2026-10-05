<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meal extends Model
{
    protected $fillable = [
        'code', 'meal_order_id', 'meal_task_id', 'flight_id', 'seat',
        'meal_type_id', 'label_version', 'status', 'loaded_at',
    ];

    protected $casts = ['loaded_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(MealOrder::class, 'meal_order_id');
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }
}
