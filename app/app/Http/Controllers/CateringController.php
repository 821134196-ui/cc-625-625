<?php

namespace App\Http\Controllers;

use App\Models\MealItem;
use App\Models\ProductionTask;
use App\Services\CateringService;
use Illuminate\Http\Request;

class CateringController extends Controller
{
    public function __construct(private CateringService $service) {}

    /** 配餐任务列表（可按航班过滤） */
    public function tasks(Request $request)
    {
        $tasks = ProductionTask::with(['mealOrder.flight', 'flight'])
            ->when($request->query('flight_id'), fn ($q, $f) => $q->where('flight_id', $f))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('flight_id')->orderBy('seat_no')
            ->get();

        return $tasks->map(fn (ProductionTask $t) => [
            'id'             => $t->id,
            'order_no'       => $t->mealOrder->order_no,
            'passenger_name' => $t->mealOrder->passenger_name,
            'flight_id'      => $t->flight_id,
            'flight_no'      => $t->flight->flight_no,
            'seat_no'        => $t->seat_no,
            'meal_type'      => $t->mealOrder->meal_type,
            'meal_type_name' => $t->mealOrder->mealTypeName(),
            'is_restricted'  => $t->mealOrder->is_restricted,
            'status'         => $t->status,
            'label_version'  => $t->label_version,
            'active_item'    => $t->mealOrder->activeItem ? [
                'id'        => $t->mealOrder->activeItem->id,
                'meal_code' => $t->mealOrder->activeItem->meal_code,
                'status'    => $t->mealOrder->activeItem->status,
            ] : null,
        ]);
    }

    public function start(ProductionTask $task)
    {
        return $this->service->start($task);
    }

    /** 打印标签 → 返回标签内容（前端据此打印/展示） */
    public function printLabel(ProductionTask $task)
    {
        $item = $this->service->printLabel($task);
        $order = $item->mealOrder->load('flight');

        return [
            'meal_code'      => $item->meal_code,
            'label_version'  => $item->label_version,
            'flight_no'      => $order->flight->flight_no,
            'seat_no'        => $item->seat_no,
            'meal_type'      => $item->meal_type,
            'meal_type_name' => $order->mealTypeName(),
            'is_restricted'  => $order->is_restricted,
            'passenger_name' => $order->passenger_name,
            'status'         => $item->status,
        ];
    }

    /** 出库到航班（本地模拟出库接口） */
    public function dispatch(MealItem $item)
    {
        $item = $this->service->dispatch($item);

        return ['meal_code' => $item->meal_code, 'status' => $item->status, 'flight_id' => $item->flight_id];
    }
}
