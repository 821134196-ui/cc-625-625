<?php

namespace Database\Seeders;

use App\Models\Flight;
use App\Models\MealOrder;
use App\Services\CateringService;
use App\Services\MealOrderService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $f1 = Flight::create(['flight_no' => 'CA1234', 'origin' => 'PEK', 'destination' => 'SHA', 'departure_at' => now()->addHours(3), 'status' => 'boarding']);
        $f2 = Flight::create(['flight_no' => 'CA1235', 'origin' => 'PEK', 'destination' => 'SHA', 'departure_at' => now()->addHours(6), 'status' => 'scheduled']);
        $f3 = Flight::create(['flight_no' => 'MU5110', 'origin' => 'PEK', 'destination' => 'CAN', 'departure_at' => now()->addHours(8), 'status' => 'scheduled']);

        $orders = app(MealOrderService::class);
        $catering = app(CateringService::class);

        // 待客服确认
        $orders->createOrder(['passenger_name' => '张伟',   'pnr' => 'PNR001', 'flight_id' => $f1->id, 'seat_no' => '12A', 'meal_type' => 'NFML']);
        // 已确认 → 待制作
        $o2 = $orders->createOrder(['passenger_name' => '李娜', 'pnr' => 'PNR002', 'flight_id' => $f1->id, 'seat_no' => '14C', 'meal_type' => 'KSML']);
        $orders->confirm($o2, 'KSML');
        // 已贴标出库（等待机组装机）
        $o3 = $orders->createOrder(['passenger_name' => '王强', 'pnr' => 'PNR003', 'flight_id' => $f1->id, 'seat_no' => '8B',  'meal_type' => 'DBML']);
        $orders->confirm($o3, 'DBML');
        $catering->start($o3->task);
        $item3 = $catering->printLabel($o3->task->fresh());
        $catering->dispatch($item3);
        // 普通餐（非受限）已出库
        $o4 = $orders->createOrder(['passenger_name' => '陈晨', 'pnr' => 'PNR004', 'flight_id' => $f1->id, 'seat_no' => '21F', 'meal_type' => 'CHML']);
        $orders->confirm($o4, 'CHML');
        $catering->start($o4->task);
        $item4 = $catering->printLabel($o4->task->fresh());
        $catering->dispatch($item4);

        $this->command?->info('演示餐食条码: '.$item3->meal_code.' (DBML 8B), '.$item4->meal_code.' (CHML 21F)');
    }
}
