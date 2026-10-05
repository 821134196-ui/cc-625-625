<?php

namespace App\Services;

use App\Models\MealItem;
use App\Models\MealOrder;
use App\Models\OrderEvent;
use App\Models\ProductionTask;
use Illuminate\Support\Facades\DB;

class MealOrderService
{
    /** 客服录入旅客的特殊餐申请 */
    public function createOrder(array $data): MealOrder
    {
        $type = config("mealtypes.types.{$data['meal_type']}");
        abort_unless($type, 422, '未知的餐食类别');

        return DB::transaction(function () use ($data, $type) {
            $order = MealOrder::create([
                'order_no'       => $this->nextOrderNo(),
                'passenger_name' => $data['passenger_name'],
                'pnr'            => $data['pnr'],
                'flight_id'      => $data['flight_id'],
                'seat_no'        => $data['seat_no'],
                'meal_type'      => $data['meal_type'],
                'is_restricted'  => $type['restricted'],
                'status'         => MealOrder::STATUS_PENDING_CS,
                'notes'          => $data['notes'] ?? null,
            ]);
            $this->log($order, 'cs', 'order_created', ['meal_type' => $order->meal_type]);

            return $order;
        });
    }

    /** 客服确认餐食类别 → 生成配餐任务 */
    public function confirm(MealOrder $order, string $mealType): MealOrder
    {
        $type = config("mealtypes.types.{$mealType}");
        abort_unless($type, 422, '未知的餐食类别');
        if ($order->status !== MealOrder::STATUS_PENDING_CS) {
            throw new DomainException('仅待确认的订单可以确认类别');
        }

        return DB::transaction(function () use ($order, $mealType, $type) {
            $order->update([
                'meal_type'     => $mealType,
                'is_restricted' => $type['restricted'],
                'status'        => MealOrder::STATUS_CONFIRMED,
            ]);
            ProductionTask::create([
                'meal_order_id' => $order->id,
                'flight_id'     => $order->flight_id,
                'seat_no'       => $order->seat_no,
                'status'        => ProductionTask::STATUS_PENDING,
            ]);
            $this->log($order, 'cs', 'cs_confirmed', ['meal_type' => $mealType]);
            $this->log($order, 'system', 'task_created', ['flight_id' => $order->flight_id, 'seat_no' => $order->seat_no]);

            return $order->fresh();
        });
    }

    /** 取消申请：释放待制作任务、作废未装机标签 */
    public function cancel(MealOrder $order): MealOrder
    {
        if ($order->status === MealOrder::STATUS_LOADED) {
            throw new DomainException('餐食已装机，无法取消');
        }
        if ($order->status === MealOrder::STATUS_CANCELLED) {
            throw new DomainException('订单已取消');
        }

        return DB::transaction(function () use ($order) {
            $order->update(['status' => MealOrder::STATUS_CANCELLED]);

            $task = $order->task;
            if ($task && $task->status !== ProductionTask::STATUS_CANCELLED) {
                $task->update(['status' => ProductionTask::STATUS_CANCELLED]);
                $this->log($order, 'system', 'task_released', ['task_id' => $task->id]);
            }
            $this->voidActiveItem($order, 'cancelled');
            $this->log($order, 'cs', 'order_cancelled');

            return $order->fresh();
        });
    }

    /**
     * 改签：未装机的餐食跟随订单调整到新航班。
     * 旧标签作废（旧航班不能再出库/装机），任务回到待制作。
     */
    public function rebook(MealOrder $order, int $newFlightId): MealOrder
    {
        $this->assertAdjustable($order);
        if ($order->flight_id === $newFlightId) {
            throw new DomainException('新航班与原航班相同');
        }

        return DB::transaction(function () use ($order, $newFlightId) {
            $from = $order->flight_id;
            $this->voidActiveItem($order, 'rebooked');
            $order->update(['flight_id' => $newFlightId]);
            $this->resetTask($order);
            $this->log($order, 'cs', 'rebooked', ['from_flight' => $from, 'to_flight' => $newFlightId]);

            return $order->fresh();
        });
    }

    /** 换座：标签与任务跟随新座位 */
    public function reseat(MealOrder $order, string $newSeat): MealOrder
    {
        $this->assertAdjustable($order);
        if ($order->seat_no === $newSeat) {
            throw new DomainException('新座位与原座位相同');
        }

        return DB::transaction(function () use ($order, $newSeat) {
            $from = $order->seat_no;
            $this->voidActiveItem($order, 'reseated');
            $order->update(['seat_no' => $newSeat]);
            $this->resetTask($order);
            $this->log($order, 'cs', 'reseated', ['from_seat' => $from, 'to_seat' => $newSeat]);

            return $order->fresh();
        });
    }

    private function assertAdjustable(MealOrder $order): void
    {
        if ($order->status === MealOrder::STATUS_LOADED) {
            throw new DomainException('餐食已装机，订单不能再调整');
        }
        if ($order->status === MealOrder::STATUS_CANCELLED) {
            throw new DomainException('订单已取消');
        }
    }

    /** 作废旧标签并记录 */
    private function voidActiveItem(MealOrder $order, string $reason): void
    {
        $item = $order->activeItem;
        if ($item) {
            $item->update(['status' => MealItem::STATUS_VOID]);
            $this->log($order, 'system', 'label_voided', [
                'meal_code' => $item->meal_code,
                'reason'    => $reason,
            ]);
        }
    }

    /** 任务跟随订单当前航班/座位，回到待制作（需重新贴标） */
    private function resetTask(MealOrder $order): void
    {
        $task = $order->task;
        if ($task && $task->status !== ProductionTask::STATUS_CANCELLED) {
            $task->update([
                'flight_id' => $order->flight_id,
                'seat_no'   => $order->seat_no,
                'status'    => ProductionTask::STATUS_PENDING,
            ]);
            $this->log($order, 'system', 'task_reset', [
                'flight_id' => $order->flight_id,
                'seat_no'   => $order->seat_no,
            ]);
        }
    }

    public function log(MealOrder $order, string $role, string $event, array $payload = []): void
    {
        OrderEvent::create([
            'meal_order_id' => $order->id,
            'actor_role'    => $role,
            'event'         => $event,
            'payload'       => $payload,
        ]);
    }

    private function nextOrderNo(): string
    {
        $max = MealOrder::max('id') ?? 0;

        return 'SPML-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
