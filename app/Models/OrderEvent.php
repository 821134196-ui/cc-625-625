<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['meal_order_id', 'actor', 'action', 'detail'];
}
