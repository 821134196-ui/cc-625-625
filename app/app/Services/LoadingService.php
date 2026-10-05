<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\MealItem;
use App\Models\MealOrder;
use App\Models\ScanEvent;
use App\Models\ShortageCase;
use Illuminate\Support\Facades\DB;

class LoadingService
{
    public function __construct(private MealOrderService $orders) {}

    /**
     * 机组装机扫码。幂等：同一条码重复扫码只计一份，实收量不增加。
     * 返回 [scan_event_result, meal_item|null]
     */
    public function scan(Flight $flight, string $mealCode, string $scannedBy = 'crew'): array
    {
        return DB::transaction(function () use ($flight, $mealCode, $scannedBy) {
            $item = MealItem::where('meal_code', $mealCode)->first();

            $result = match (true) {
                !$item                                        => ScanEvent::NOT_FOUND,
                $item->status === MealItem::STATUS_VOID       => ScanEvent::VOID_LABEL,
                $item->flight_id !== $flight->id              => ScanEvent::WRONG_FLIGHT,
                $item->status === MealItem::STATUS_RECEIVED   => ScanEvent::DUPLICATE,
                $item->status !== MealItem::STATUS_DISPATCHED => ScanEvent::NOT_DISPATCHED,
                default                                       => ScanEvent::ACCEPTED,
            };

            ScanEvent::create([
                'meal_code'    => $mealCode,
                'meal_item_id' => $item?->id,
                'flight_id'    => $flight->id,
                'scanned_by'   => $scannedBy,
                'result'       => $result,
            ]);

            if ($result === ScanEvent::ACCEPTED) {
                $item->update(['status' => MealItem::STATUS_RECEIVED]);
                $order = $item->mealOrder;
                $order->update(['status' => MealOrder::STATUS_LOADED]);
                $this->orders->log($order, 'crew', 'received_onboard', [
                    'meal_code' => $item->meal_code, 'flight_id' => $flight->id,
                ]);
                // 装机完成，若此前开过缺口则关闭
                ShortageCase::where('flight_id', $flight->id)
                    ->where('meal_order_id', $order->id)
                    ->where('status', ShortageCase::STATUS_OPEN)
                    ->delete();
            }

            return [$result, $item];
        });
    }

    /**
     * 装机页汇总：应收（已出库到本航班）vs 实收（已扫码装机）。
     * 同时为每份缺餐建立/清理未决缺口，机组必须看见并确认处理。
     */
    public function summary(Flight $flight): array
    {
        $expected = MealItem::with('mealOrder')
            ->where('flight_id', $flight->id)
            ->whereIn('status', [MealItem::STATUS_DISPATCHED, MealItem::STATUS_RECEIVED])
            ->get();

        $received = $expected->where('status', MealItem::STATUS_RECEIVED);
        $missing  = $expected->where('status', MealItem::STATUS_DISPATCHED);

        // 同步缺口：缺餐 → 确保有 open 缺口；不再缺的 → 移除未决缺口
        foreach ($missing as $item) {
            ShortageCase::firstOrCreate([
                'flight_id'     => $flight->id,
                'meal_order_id' => $item->meal_order_id,
            ]);
        }
        ShortageCase::where('flight_id', $flight->id)
            ->where('status', ShortageCase::STATUS_OPEN)
            ->whereNotIn('meal_order_id', $missing->pluck('meal_order_id')->all() ?: [0])
            ->delete();

        $openCases = ShortageCase::with('mealOrder')
            ->where('flight_id', $flight->id)
            ->where('status', ShortageCase::STATUS_OPEN)
            ->get();

        return [
            'flight'          => $flight,
            'expected_count'  => $expected->count(),
            'received_count'  => $received->count(),
            'shortage_count'  => $missing->count(),
            'items'           => $expected->map(fn (MealItem $i) => [
                'meal_code'      => $i->meal_code,
                'seat_no'        => $i->seat_no,
                'meal_type'      => $i->meal_type,
                'meal_type_name' => $i->mealOrder->mealTypeName(),
                'is_restricted'  => $i->mealOrder->is_restricted,
                'passenger_name' => $i->mealOrder->passenger_name,
                'status'         => $i->status,
            ])->values(),
            'open_shortages'  => $openCases->map(fn (ShortageCase $c) => [
                'id'             => $c->id,
                'order_no'       => $c->mealOrder->order_no,
                'seat_no'        => $c->mealOrder->seat_no,
                'meal_type'      => $c->mealOrder->meal_type,
                'meal_type_name' => $c->mealOrder->mealTypeName(),
                'is_restricted'  => $c->mealOrder->is_restricted,
            ])->values(),
        ];
    }

    /**
     * 机组确认处理缺口。
     * 受限餐（过敏/医疗/宗教）不得擅自用普通餐替代。
     */
    public function resolveShortage(ShortageCase $case, string $resolution, ?string $note, string $resolvedBy): ShortageCase
    {
        if ($case->status === ShortageCase::STATUS_RESOLVED) {
            throw new DomainException('缺口已处理');
        }
        abort_unless(config("mealtypes.shortage_resolutions.{$resolution}"), 422, '未知的处理方式');

        $order = $case->mealOrder;
        if ($resolution === 'substitute_regular' && $order->is_restricted) {
            $this->orders->log($order, 'crew', 'substitution_rejected', [
                'reason' => '受限餐（过敏/医疗/宗教）不得以普通餐替代',
            ]);
            throw new DomainException('该餐为过敏/医疗/宗教受限餐，不得擅自用普通餐替代');
        }

        return DB::transaction(function () use ($case, $order, $resolution, $note, $resolvedBy) {
            $case->update([
                'status'      => ShortageCase::STATUS_RESOLVED,
                'resolution'  => $resolution,
                'note'        => $note,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
            ]);
            $this->orders->log($order, 'crew', 'shortage_resolved', [
                'resolution' => $resolution, 'note' => $note,
            ]);

            return $case->fresh();
        });
    }

    /** 关闭航班装机：仍有未处理缺口时禁止关闭 */
    public function closeFlight(Flight $flight): Flight
    {
        $open = ShortageCase::where('flight_id', $flight->id)
            ->where('status', ShortageCase::STATUS_OPEN)->count();
        if ($open > 0) {
            throw new DomainException("仍有 {$open} 个缺餐缺口未确认处理，不能关闭装机");
        }
        $flight->update(['status' => 'closed']);

        return $flight->fresh();
    }
}
