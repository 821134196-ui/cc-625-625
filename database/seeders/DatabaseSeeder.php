<?php

namespace Database\Seeders;

use App\Models\Flight;
use App\Models\MealOrder;
use App\Models\MealType;
use App\Services\MealService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 本地模拟航班动态
        $flights = collect([
            ['flight_no' => 'CA1823', 'origin' => '北京首都', 'destination' => '上海虹桥', 'dep_time' => '08:30', 'arr_time' => '10:45'],
            ['flight_no' => 'MU5106', 'origin' => '北京首都', 'destination' => '广州白云', 'dep_time' => '10:15', 'arr_time' => '13:35'],
            ['flight_no' => 'CZ3158', 'origin' => '北京大兴', 'destination' => '深圳宝安', 'dep_time' => '13:40', 'arr_time' => '17:05'],
            ['flight_no' => 'HU7710', 'origin' => '北京首都', 'destination' => '成都天府', 'dep_time' => '16:20', 'arr_time' => '19:30'],
        ])->map(fn ($f) => Flight::create($f));

        // 餐食类别字典
        $types = collect([
            ['code' => 'REGULAR', 'name_zh' => '普通餐', 'restriction_class' => 'regular'],
            ['code' => 'VGML', 'name_zh' => '素食餐', 'restriction_class' => 'preference'],
            ['code' => 'KSML', 'name_zh' => '犹太餐', 'restriction_class' => 'religious'],
            ['code' => 'MOML', 'name_zh' => '清真餐', 'restriction_class' => 'religious'],
            ['code' => 'GFML', 'name_zh' => '无麸质餐', 'restriction_class' => 'medical'],
            ['code' => 'DBML', 'name_zh' => '糖尿病餐', 'restriction_class' => 'medical'],
            ['code' => 'ALML', 'name_zh' => '坚果过敏餐', 'restriction_class' => 'allergy'],
        ])->map(fn ($t) => MealType::create($t));

        $svc = app(MealService::class);
        $type = fn (string $code) => $types->firstWhere('code', $code);

        // 演示数据：覆盖链路各状态
        // 1) 已装机
        $o1 = $svc->apply(['passenger_name' => '张伟', 'pnr' => 'K8X2QD', 'flight_id' => $flights[0]->id, 'seat' => '12A', 'meal_type_code' => 'ALML', 'note' => '花生严重过敏']);
        $svc->confirm($o1, 'ALML');
        $meal1 = $svc->produce($o1->fresh()->task);
        $svc->scan($meal1->code, $flights[0]->id);

        // 2) 已贴标待装机
        $o2 = $svc->apply(['passenger_name' => '李芳', 'pnr' => 'K8X2QD', 'flight_id' => $flights[0]->id, 'seat' => '12C', 'meal_type_code' => 'MOML']);
        $svc->confirm($o2, 'MOML');
        $svc->produce($o2->fresh()->task);

        // 3) 已确认待制作
        $o3 = $svc->apply(['passenger_name' => '王强', 'pnr' => 'P3M9ZT', 'flight_id' => $flights[0]->id, 'seat' => '21F', 'meal_type_code' => 'DBML', 'note' => '低糖']);
        $svc->confirm($o3, 'DBML');

        // 4) 待客服确认
        $svc->apply(['passenger_name' => '陈静', 'pnr' => 'R7N2HA', 'flight_id' => $flights[1]->id, 'seat' => '8A', 'meal_type_code' => 'KSML']);

        // 5) 另一航班已贴标
        $o5 = $svc->apply(['passenger_name' => '刘洋', 'pnr' => 'R7N2HA', 'flight_id' => $flights[1]->id, 'seat' => '9C', 'meal_type_code' => 'GFML']);
        $svc->confirm($o5, 'GFML');
        $svc->produce($o5->fresh()->task);

        // 6) 素食（偏好类，可替代演示用）
        $o6 = $svc->apply(['passenger_name' => '赵敏', 'pnr' => 'T5W8VB', 'flight_id' => $flights[2]->id, 'seat' => '33B', 'meal_type_code' => 'VGML']);
        $svc->confirm($o6, 'VGML');

        $this->command->info('演示数据就绪：'.MealOrder::count().' 份特殊餐申请');
    }
}
