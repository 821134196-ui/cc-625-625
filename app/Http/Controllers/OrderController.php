<?php

namespace App\Http\Controllers;

use App\Models\MealOrder;
use App\Models\MealType;
use App\Services\MealService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private MealService $svc) {}

    public function index(Request $request)
    {
        $query = MealOrder::with(['flight', 'mealType', 'task', 'meal'])->latest('id');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($flightId = $request->query('flight_id')) {
            $query->where('flight_id', $flightId);
        }

        return $query->get();
    }

    /** 同一份记录：订单详情 + 全程留痕 */
    public function show(MealOrder $order)
    {
        return $order->load(['flight', 'mealType', 'task', 'meal', 'events']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'passenger_name' => 'required|string|max:100',
            'pnr' => 'required|string|max:10',
            'flight_id' => 'required|exists:flights,id',
            'seat' => 'required|string|max:4',
            'meal_type_code' => 'required|exists:meal_types,code',
            'note' => 'nullable|string|max:500',
        ]);

        return response()->json($this->svc->apply($data), 201);
    }

    /** 客服确认餐食类别 */
    public function confirm(Request $request, MealOrder $order)
    {
        $data = $request->validate(['meal_type_code' => 'required|exists:meal_types,code']);

        return $this->svc->confirm($order, $data['meal_type_code']);
    }

    /** 取消申请，释放待制作任务 */
    public function cancel(MealOrder $order)
    {
        return $this->svc->cancel($order);
    }

    /** 改签 / 换座 */
    public function rebook(Request $request, MealOrder $order)
    {
        $data = $request->validate([
            'flight_id' => 'required|exists:flights,id',
            'seat' => 'required|string|max:4',
        ]);

        return $this->svc->rebook($order, (int) $data['flight_id'], $data['seat']);
    }

    /** 餐食替代（受限餐拦截普通餐替代） */
    public function substitute(Request $request, MealOrder $order)
    {
        $data = $request->validate(['meal_type_code' => 'required|exists:meal_types,code']);

        return $this->svc->substitute($order, $data['meal_type_code']);
    }

    public function mealTypes()
    {
        return MealType::orderBy('id')->get();
    }
}
