<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\Meal;
use App\Models\MealOrder;
use App\Models\MealTask;
use App\Models\MealType;
use App\Models\OrderEvent;
use App\Models\ScanEvent;
use App\Models\ShortageAck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 特殊餐全链路业务规则。
 *
 * 关键约束：
 *  - 同一份订单记录贯穿 客服→配餐→机组，每次确认都写 order_events。
 *  - 改签/换座：未装机餐食跟随订单，任务与标签同步改属；旧航班扫码一律拒绝。
 *  - 过敏/医疗/宗教类特殊餐不得用普通餐（或偏好餐）替代。
 *  - 扫码幂等：同一份餐重复扫码只计一份，实收量不增加。
 */
class MealService
{
    /* ---------------- 旅客申请 ---------------- */

    public function apply(array $data): MealOrder
    {
        $order = DB::transaction(function () use ($data) {
            $order = MealOrder::create([
                'ref' => $this->nextRef(),
                'passenger_name' => $data['passenger_name'],
                'pnr' => $data['pnr'],
                'flight_id' => $data['flight_id'],
                'seat' => strtoupper($data['seat']),
                'requested_type_code' => $data['meal_type_code'],
                'status' => 'requested',
                'note' => $data['note'] ?? null,
            ]);
            $this->log($order, 'passenger', 'applied',
                "旅客申请特殊餐 {$data['meal_type_code']}，{$order->flight->flight_no} 座位 {$order->seat}");

            return $order;
        });

        return $order->fresh(['flight', 'mealType']);
    }

    /* ---------------- 客服确认类别 ---------------- */

    public function confirm(MealOrder $order, string $mealTypeCode): MealOrder
    {
        if ($order->status !== 'requested') {
            throw ValidationException::withMessages(['status' => '仅待确认状态的申请可以确认类别']);
        }
        $type = MealType::where('code', $mealTypeCode)->firstOrFail();

        return DB::transaction(function () use ($order, $type) {
            $order->update(['meal_type_id' => $type->id, 'status' => 'confirmed']);
            MealTask::create([
                'meal_order_id' => $order->id,
                'flight_id' => $order->flight_id,
                'seat' => $order->seat,
                'meal_type_id' => $type->id,
                'status' => 'pending',
            ]);
            $this->log($order, 'cs', 'confirmed',
                "客服确认餐食类别 {$type->code}（{$type->name_zh}），生成制作任务");

            return $order->fresh(['flight', 'mealType', 'task']);
        });
    }

    /* ---------------- 取消申请：释放待制作任务 ---------------- */

    public function cancel(MealOrder $order): MealOrder
    {
        if ($order->status === 'loaded') {
            throw ValidationException::withMessages(['status' => '餐食已装机，无法取消']);
        }

        return DB::transaction(function () use ($order) {
            $order->update(['status' => 'cancelled']);
            $released = 0;
            if ($task = $order->task) {
                if ($task->status === 'pending') {
                    $task->update(['status' => 'cancelled']);
                    $released++;
                }
            }
            if (($meal = $order->meal) && $meal->status === 'prepared') {
                $meal->update(['status' => 'voided']);
            }
            $this->log($order, 'cs', 'cancelled',
                $released ? '申请取消，释放待制作任务' : '申请取消');

            return $order->fresh(['flight', 'mealType', 'task', 'meal']);
        });
    }

    /* ---------------- 改签 / 换座：未装机餐食跟随订单 ---------------- */

    public function rebook(MealOrder $order, int $newFlightId, string $newSeat): MealOrder
    {
        if (in_array($order->status, ['loaded', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => '已装机或已取消的申请不能改签']);
        }
        $newFlight = Flight::findOrFail($newFlightId);
        $newSeat = strtoupper($newSeat);

        return DB::transaction(function () use ($order, $newFlight, $newSeat) {
            $oldFlight = $order->flight;
            $oldSeat = $order->seat;

            $order->update(['flight_id' => $newFlight->id, 'seat' => $newSeat]);

            // 任务归属同步：任务记录始终跟随订单当前归属（已取消的除外）
            if (($task = $order->task) && $task->status !== 'cancelled') {
                $task->update(['flight_id' => $newFlight->id, 'seat' => $newSeat]);
            }

            // 餐食跟随：已备未装机的餐改属新航班/座位，标签版本+1 需重贴；
            // 此后旧航班对该餐的扫码会被 wrong_flight 拒绝（旧航班不能继续出库）。
            if (($meal = $order->meal) && $meal->status === 'prepared') {
                $meal->update([
                    'flight_id' => $newFlight->id,
                    'seat' => $newSeat,
                    'label_version' => $meal->label_version + 1,
                ]);
            }

            $this->log($order, 'cs', 'rebooked',
                "改签/换座：{$oldFlight->flight_no} {$oldSeat} → {$newFlight->flight_no} {$newSeat}，未装机餐食与任务同步跟随");

            return $order->fresh(['flight', 'mealType', 'task', 'meal']);
        });
    }

    /* ---------------- 替代限制：受限餐不得用普通餐替代 ---------------- */

    public function substitute(MealOrder $order, string $newTypeCode): MealOrder
    {
        if (in_array($order->status, ['loaded', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => '已装机或已取消的申请不能变更餐食']);
        }
        $current = $order->mealType;
        $target = MealType::where('code', $newTypeCode)->firstOrFail();

        if ($current && $current->isRestricted() && ! $target->isRestricted()) {
            $this->log($order, 'cs', 'substitute_blocked',
                "拦截替代：{$current->code}（{$current->name_zh}）→ {$target->code}，受限餐不得用普通餐替代");
            throw ValidationException::withMessages([
                'meal_type' => "该申请为{$current->name_zh}（过敏/医疗/宗教类），不得擅自用普通餐替代",
            ]);
        }

        return DB::transaction(function () use ($order, $current, $target) {
            $order->update(['meal_type_id' => $target->id]);
            if (($task = $order->task) && $task->status === 'pending') {
                $task->update(['meal_type_id' => $target->id]);
            }
            if (($meal = $order->meal) && $meal->status === 'prepared') {
                $meal->update([
                    'meal_type_id' => $target->id,
                    'label_version' => $meal->label_version + 1,
                ]);
            }
            $this->log($order, 'cs', 'substituted',
                '餐食类别变更：'.($current?->code ?? '-')." → {$target->code}（{$target->name_zh}）");

            return $order->fresh(['flight', 'mealType', 'task', 'meal']);
        });
    }

    /* ---------------- 配餐员制作并贴标签 ---------------- */

    public function produce(MealTask $task): Meal
    {
        if ($task->status !== 'pending') {
            throw ValidationException::withMessages(['status' => '任务不在待制作状态']);
        }

        return DB::transaction(function () use ($task) {
            $meal = Meal::create([
                'code' => $this->nextMealCode(),
                'meal_order_id' => $task->meal_order_id,
                'meal_task_id' => $task->id,
                'flight_id' => $task->flight_id,
                'seat' => $task->seat,
                'meal_type_id' => $task->meal_type_id,
                'label_version' => 1,
                'status' => 'prepared',
            ]);
            $task->update(['status' => 'done']);
            $task->order->update(['status' => 'ready']);
            $this->log($task->order, 'kitchen', 'produced',
                "配餐完成并贴标 {$meal->code}（{$meal->flight->flight_no} 座位 {$meal->seat}）");

            return $meal->fresh(['flight', 'mealType', 'order']);
        });
    }

    /* ---------------- 机组装机扫码（幂等） ---------------- */

    public function scan(string $code, int $flightId): array
    {
        $flight = Flight::findOrFail($flightId);
        $code = strtoupper(trim($code));
        $meal = Meal::where('code', $code)->first();

        $record = function (string $result, ?Meal $meal) use ($code, $flight) {
            ScanEvent::create([
                'meal_id' => $meal?->id,
                'code_scanned' => $code,
                'flight_id' => $flight->id,
                'result' => $result,
            ]);
        };

        if (! $meal) {
            $record('not_found', null);

            return ['result' => 'not_found', 'message' => "餐食编号 {$code} 不存在"];
        }
        if ($meal->status === 'voided') {
            $record('voided', $meal);

            return ['result' => 'voided', 'message' => "餐食 {$code} 已作废（申请取消），不得装机", 'meal' => $meal];
        }
        // 旧航班不能继续出库：改签后餐食已改属新航班
        if ($meal->flight_id !== $flight->id) {
            $record('wrong_flight', $meal);

            return [
                'result' => 'wrong_flight',
                'message' => "餐食 {$code} 属于 {$meal->flight->flight_no}，不能装上 {$flight->flight_no}",
                'meal' => $meal,
            ];
        }
        // 幂等：重复扫码只计一份
        if ($meal->status === 'loaded') {
            $record('duplicate', $meal);

            return ['result' => 'duplicate', 'message' => "餐食 {$code} 已接收过，实收量不重复计数", 'meal' => $meal];
        }

        DB::transaction(function () use ($meal, $record) {
            $meal->update(['status' => 'loaded', 'loaded_at' => now()]);
            $meal->order->update(['status' => 'loaded']);
            $record('accepted', $meal);
            $this->log($meal->order, 'crew', 'loaded',
                "机组扫码接收 {$meal->code}（{$meal->flight->flight_no} 座位 {$meal->seat}）");
        });

        return ['result' => 'accepted', 'message' => "已接收 {$code} → 座位 {$meal->seat}", 'meal' => $meal->fresh()];
    }

    /* ---------------- 装机页：应收 / 实收 / 缺口 ---------------- */

    public function loadingSummary(Flight $flight): array
    {
        $orders = MealOrder::with(['mealType', 'meal'])
            ->where('flight_id', $flight->id)
            ->whereIn('status', ['confirmed', 'ready', 'loaded'])
            ->orderBy('seat')
            ->get();

        $items = $orders->map(fn (MealOrder $o) => [
            'ref' => $o->ref,
            'seat' => $o->seat,
            'passenger' => $o->passenger_name,
            'meal_type' => $o->mealType?->code,
            'meal_type_zh' => $o->mealType?->name_zh,
            'restricted' => $o->mealType?->isRestricted() ?? false,
            'meal_code' => $o->meal?->code,
            'label_version' => $o->meal?->label_version,
            'state' => match (true) {
                $o->status === 'loaded' => 'loaded',
                $o->meal?->status === 'prepared' => 'ready',
                default => 'awaiting_production',
            },
        ]);

        $expected = $items->count();
        $received = $items->where('state', 'loaded')->count();

        return [
            'flight' => $flight,
            'expected' => $expected,
            'received' => $received,
            'shortage' => max(0, $expected - $received),
            'items' => $items->values(),
            'acks' => ShortageAck::where('flight_id', $flight->id)->latest('id')->get(),
        ];
    }

    public function ackShortage(Flight $flight, string $handler, string $note): ShortageAck
    {
        $summary = $this->loadingSummary($flight);

        return ShortageAck::create([
            'flight_id' => $flight->id,
            'missing_count' => $summary['shortage'],
            'handler' => $handler,
            'note' => $note,
        ]);
    }

    /* ---------------- 工具 ---------------- */

    private function log(MealOrder $order, string $actor, string $action, string $detail): void
    {
        OrderEvent::create([
            'meal_order_id' => $order->id,
            'actor' => $actor,
            'action' => $action,
            'detail' => $detail,
        ]);
    }

    private function nextRef(): string
    {
        return 'SPML-'.str_pad((string) (MealOrder::max('id') + 1), 4, '0', STR_PAD_LEFT);
    }

    private function nextMealCode(): string
    {
        return 'ML-'.str_pad((string) (Meal::max('id') + 1), 6, '0', STR_PAD_LEFT);
    }
}
