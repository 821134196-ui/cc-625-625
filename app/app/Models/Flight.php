<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    protected $fillable = ['flight_no', 'origin', 'destination', 'departure_at', 'status'];

    protected function casts(): array
    {
        return ['departure_at' => 'datetime'];
    }

    public function mealOrders(): HasMany
    {
        return $this->hasMany(MealOrder::class);
    }
}
