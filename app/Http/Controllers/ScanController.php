<?php

namespace App\Http\Controllers;

use App\Services\MealService;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(private MealService $svc) {}

    /** 本地模拟扫码枪接口：POST {code, flight_id} */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'flight_id' => 'required|exists:flights,id',
        ]);

        $result = $this->svc->scan($data['code'], (int) $data['flight_id']);

        $status = match ($result['result']) {
            'accepted' => 200,
            'duplicate' => 200,   // 幂等：不视为错误，实收量不变
            default => 422,
        };

        return response()->json($result, $status);
    }
}
