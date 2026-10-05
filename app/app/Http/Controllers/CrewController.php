<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use App\Models\ShortageCase;
use App\Services\LoadingService;
use Illuminate\Http\Request;

class CrewController extends Controller
{
    public function __construct(private LoadingService $service) {}

    /** 装机页：应收 vs 实收 + 未决缺口 */
    public function summary(Flight $flight)
    {
        return $this->service->summary($flight);
    }

    /** 扫码接收（本地模拟扫码接口）。重复扫码只计一份。 */
    public function scan(Request $request, Flight $flight)
    {
        $data = $request->validate([
            'meal_code'  => 'required|string',
            'scanned_by' => 'nullable|string|max:50',
        ]);

        [$result, $item] = $this->service->scan(
            $flight, $data['meal_code'], $data['scanned_by'] ?? 'crew'
        );

        return array_merge(
            $this->service->summary($flight),
            ['scan_result' => $result, 'scanned_code' => $data['meal_code'],
             'seat_no' => $item?->seat_no, 'meal_type' => $item?->meal_type]
        );
    }

    /** 机组确认处理缺餐缺口 */
    public function resolveShortage(Request $request, ShortageCase $shortage)
    {
        $data = $request->validate([
            'resolution'  => 'required|string',
            'note'        => 'nullable|string|max:500',
            'resolved_by' => 'nullable|string|max:50',
        ]);

        $case = $this->service->resolveShortage(
            $shortage, $data['resolution'], $data['note'] ?? null, $data['resolved_by'] ?? 'crew'
        );

        return $case;
    }

    /** 关闭装机：有未处理缺口时拒绝 */
    public function close(Flight $flight)
    {
        return $this->service->closeFlight($flight);
    }
}
