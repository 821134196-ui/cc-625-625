<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MealOrder extends Model
{
    public const STATUSES = ['requested', 'confirmed', 'ready', 'loaded', 'cancelled'];

    protected $fillable = [
        'ref', 'passenger_name', 'pnr', 'flight_id', 'seat',
        'meal_type_id', 'requested_type_code', 'status', 'note',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }

    public function task(): HasOne
    {
        return $this->hasOne(MealTask::class)->latestOfMany();
    }

    public function meal(): HasOne
    {
        return $this->hasOne(Meal::class)->latestOfMany();
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }
}
