<?php

namespace App\Http\Controllers;

use App\Models\MealTask;
use App\Services\MealService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private MealService $svc) {}

    /** 配餐任务列表（可按航班过滤） */
    public function index(Request $request)
    {
        $query = MealTask::with(['flight', 'mealType', 'order.meal'])->latest('id');
        if ($flightId = $request->query('flight_id')) {
            $query->where('flight_id', $flightId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /** 制作完成并打印标签，返回餐食编号 */
    public function produce(MealTask $task)
    {
        return response()->json($this->svc->produce($task), 201);
    }
}
