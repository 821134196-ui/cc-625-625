<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use App\Services\MealService;
use Illuminate\Http\Request;

class FlightController extends Controller
{
    public function __construct(private MealService $svc) {}

    /** 本地模拟航班动态接口 */
    public function index()
    {
        return Flight::orderBy('dep_time')->get();
    }

    /** 装机页：应收/实收/缺口 */
    public function loading(Flight $flight)
    {
        return $this->svc->loadingSummary($flight);
    }

    /** 少餐缺口确认处理 */
    public function ackShortage(Request $request, Flight $flight)
    {
        $data = $request->validate([
            'handler' => 'required|string|max:50',
            'note' => 'required|string|max:500',
        ]);

        $ack = $this->svc->ackShortage($flight, $data['handler'], $data['note']);

        return response()->json($ack, 201);
    }
}
