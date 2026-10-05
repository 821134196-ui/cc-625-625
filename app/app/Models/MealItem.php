<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealItem extends Model
{
    public const STATUS_LABELED    = 'labeled';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_RECEIVED   = 'received';
    public const STATUS_VOID       = 'void';

    protected $fillable = [
        'meal_code', 'meal_order_id', 'flight_id', 'seat_no',
        'meal_type', 'label_version', 'status',
    ];

    public function mealOrder(): BelongsTo
    {
        return $this->belongsTo(MealOrder::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
