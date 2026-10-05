<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MealOrder extends Model
{
    public const STATUS_PENDING_CS = 'pending_cs';
    public const STATUS_CONFIRMED  = 'confirmed';
    public const STATUS_LOADED     = 'loaded';
    public const STATUS_CANCELLED  = 'cancelled';

    protected $fillable = [
        'order_no', 'passenger_name', 'pnr', 'flight_id', 'seat_no',
        'meal_type', 'is_restricted', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_restricted' => 'boolean'];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function task(): HasOne
    {
        return $this->hasOne(ProductionTask::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }

    /** 当前有效（未作废）的餐食标签 */
    public function activeItem(): HasOne
    {
        return $this->hasOne(MealItem::class)->whereNot('status', MealItem::STATUS_VOID)
            ->latestOfMany('label_version');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function mealTypeName(): string
    {
        return config("mealtypes.types.{$this->meal_type}.name", $this->meal_type);
    }
}
