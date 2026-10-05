<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanEvent extends Model
{
    public const ACCEPTED        = 'accepted';
    public const DUPLICATE       = 'duplicate';
    public const WRONG_FLIGHT    = 'wrong_flight';
    public const VOID_LABEL      = 'void_label';
    public const NOT_DISPATCHED  = 'not_dispatched';
    public const NOT_FOUND       = 'not_found';

    protected $fillable = ['meal_code', 'meal_item_id', 'flight_id', 'scanned_by', 'result'];

    public function mealItem(): BelongsTo
    {
        return $this->belongsTo(MealItem::class);
    }
}
