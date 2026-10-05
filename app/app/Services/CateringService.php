<?php

namespace App\Services;

use App\Models\MealItem;
use App\Models\MealOrder;
use App\Models\ProductionTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CateringService
{
    public function __construct(private MealOrderService $orders) {}

    /** 配餐员开始制作 */
    public function start(ProductionTask $task): ProductionTask
    {
        if ($task->status !== ProductionTask::STATUS_PENDING) {
            throw new DomainException('仅待制作任务可以开始制作');
        }
        $task->update(['status' => ProductionTask::STATUS_IN_PROGRESS]);
        $this->orders->log($task->mealOrder, 'catering', 'task_started', ['task_id' => $task->id]);

        return $task->fresh();
    }

    /**
     * 打印标签：按任务当前航班+座位签发新条码，旧标签作废。
     * 返回新签发的餐食实物记录。
     */
    public function printLabel(ProductionTask $task): MealItem
    {
        if (in_array($task->status, [ProductionTask::STATUS_DONE, ProductionTask::STATUS_CANCELLED])) {
            throw new DomainException('任务已完成或已取消，不能打印标签');
        }
        $order = $task->mealOrder;
        if ($order->status !== MealOrder::STATUS_CONFIRMED) {
            throw new DomainException('订单状态不允许打印标签');
        }

        return DB::transaction(function () use ($task, $order) {
            // 重打标签时作废旧标签
            $old = $order->activeItem;
            if ($old) {
                $old->update(['status' => MealItem::STATUS_VOID]);
                $this->orders->log($order, 'system', 'label_voided', [
                    'meal_code' => $old->meal_code, 'reason' => 'reprinted',
                ]);
            }

            $task->increment('label_version');
            $task->update(['status' => ProductionTask::STATUS_LABELED]);

            $item = MealItem::create([
                'meal_code'     => $this->nextMealCode(),
                'meal_order_id' => $order->id,
                'flight_id'     => $task->flight_id,
                'seat_no'       => $task->seat_no,
                'meal_type'     => $order->meal_type,
                'label_version' => $task->label_version,
                'status'        => MealItem::STATUS_LABELED,
            ]);
            $this->orders->log($order, 'catering', 'label_printed', [
                'meal_code' => $item->meal_code,
                'flight_id' => $item->flight_id,
                'seat_no'   => $item->seat_no,
                'version'   => $item->label_version,
            ]);

            return $item;
        });
    }

    /**
     * 出库到航班。关键约束：只能出库到订单当前航班 ——
     * 改签后旧航班上的标签已作废，此处再次拦截，旧航班不能继续出库。
     */
    public function dispatch(MealItem $item): MealItem
    {
        $order = $item->mealOrder;
        if ($item->status === MealItem::STATUS_VOID) {
            throw new DomainException('标签已作废，不能出库');
        }
        if ($item->status !== MealItem::STATUS_LABELED) {
            throw new DomainException('仅已贴标未出库的餐食可以出库');
        }
        if ($item->flight_id !== $order->flight_id) {
            throw new DomainException('餐食航班与订单当前航班不一致，禁止出库');
        }

        return DB::transaction(function () use ($item, $order) {
            $item->update(['status' => MealItem::STATUS_DISPATCHED]);
            $order->task?->update(['status' => ProductionTask::STATUS_DONE]);
            $this->orders->log($order, 'catering', 'dispatched', [
                'meal_code' => $item->meal_code, 'flight_id' => $item->flight_id,
            ]);

            return $item->fresh();
        });
    }

    private function nextMealCode(): string
    {
        do {
            $code = 'M-'.strtoupper(Str::random(6));
        } while (MealItem::where('meal_code', $code)->exists());

        return $code;
    }
}
