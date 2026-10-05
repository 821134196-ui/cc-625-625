<?php

namespace App\Http\Controllers;

use App\Models\Flight;

/**
 * 本地模拟的航班接口：真实环境中由航班系统提供，
 * 这里直接读本地 flights 表。
 */
class MockFlightController extends Controller
{
    public function index()
    {
        return Flight::orderBy('departure_at')->get()->map(fn (Flight $f) => [
            'id'           => $f->id,
            'flight_no'    => $f->flight_no,
            'origin'       => $f->origin,
            'destination'  => $f->destination,
            'departure_at' => $f->departure_at?->toDateTimeString(),
            'status'       => $f->status,
        ]);
    }

    public function show(Flight $flight)
    {
        return [
            'id'           => $flight->id,
            'flight_no'    => $flight->flight_no,
            'origin'       => $flight->origin,
            'destination'  => $flight->destination,
            'departure_at' => $flight->departure_at?->toDateTimeString(),
            'status'       => $flight->status,
        ];
    }
}
