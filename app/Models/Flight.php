<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    protected $fillable = ['flight_no', 'origin', 'destination', 'dep_time', 'arr_time', 'status'];

    public function orders(): HasMany
    {
        return $this->hasMany(MealOrder::class);
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }
}
