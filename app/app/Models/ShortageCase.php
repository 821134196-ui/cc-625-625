<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortageCase extends Model
{
    public const STATUS_OPEN     = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'flight_id', 'meal_order_id', 'status',
        'resolution', 'note', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function mealOrder(): BelongsTo
    {
        return $this->belongsTo(MealOrder::class);
    }
}
