<?php

namespace Tests\Feature;

use App\Models\Flight;
use App\Models\MealItem;
use App\Models\MealOrder;
use App\Models\ProductionTask;
use App\Models\ScanEvent;
use App\Models\ShortageCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealFlowTest extends TestCase
{
    use RefreshDatabase;

    private Flight $f1;
    private Flight $f2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f1 = Flight::create(['flight_no' => 'CA100', 'origin' => 'PEK', 'destination' => 'SHA', 'departure_at' => now()->addHour()]);
        $this->f2 = Flight::create(['flight_no' => 'CA200', 'origin' => 'PEK', 'destination' => 'CAN', 'departure_at' => now()->addHours(3)]);
    }

    /** 建单 → 客服确认 → 配餐开始 → 贴标 → 出库，返回餐食实物 */
    private function makeDispatchedMeal(string $type = 'DBML', string $seat = '10A'): MealItem
    {
        $order = MealOrder::create([
            'order_no' => 'SPML-T'.fake()->unique()->numberBetween(100, 999),
            'passenger_name' => '测试旅客', 'pnr' => 'PNRT'.fake()->unique()->numberBetween(10, 99),
            'flight_id' => $this->f1->id, 'seat_no' => $seat, 'meal_type' => $type,
            'is_restricted' => config("mealtypes.types.$type.restricted"),
        ]);
        $this->postJson("/api/orders/{$order->id}/confirm", ['meal_type' => $type])->assertOk();
        $task = $order->fresh()->task;
        $this->postJson("/api/catering/tasks/{$task->id}/start")->assertOk();
        $label = $this->postJson("/api/catering/tasks/{$task->id}/label")->assertOk()->json();
        $item = MealItem::where('meal_code', $label['meal_code'])->sole();
        $this->postJson("/api/catering/items/{$item->id}/dispatch")->assertOk();

        return $item->fresh();
    }

    public function test_full_flow_cs_confirm_to_crew_receive(): void
    {
        $item = $this->makeDispatchedMeal('MOML', '5C');

        $resp = $this->postJson("/api/flights/{$this->f1->id}/scan", ['meal_code' => $item->meal_code])
            ->assertOk()->json();
        $this->assertSame('accepted', $resp['scan_result']);
        $this->assertSame(1, $resp['received_count']);
        $this->assertSame(0, $resp['shortage_count']);
        $this->assertSame(MealOrder::STATUS_LOADED, $item->mealOrder->fresh()->status);

        // 同一份记录上留有完整确认链
        $events = $this->getJson("/api/orders/{$item->meal_order_id}")->json('events');
        $this->assertContains('cs_confirmed', array_column($events, 'event'));
        $this->assertContains('label_printed', array_column($events, 'event'));
        $this->assertContains('dispatched', array_column($events, 'event'));
        $this->assertContains('received_onboard', array_column($events, 'event'));
    }

    public function test_rebook_moves_task_and_label_old_flight_blocked(): void
    {
        $item = $this->makeDispatchedMeal('KSML', '7A');
        $order = $item->mealOrder;
        $oldCode = $item->meal_code;

        $this->postJson("/api/orders/{$order->id}/rebook", ['flight_id' => $this->f2->id])->assertOk();

        // 订单与任务归属新航班
        $order->refresh();
        $this->assertSame($this->f2->id, $order->flight_id);
        $this->assertSame($this->f2->id, $order->task->flight_id);
        $this->assertSame(ProductionTask::STATUS_PENDING, $order->task->status);

        // 旧标签作废：旧航班不能继续出库，扫码也被拒
        $this->assertSame(MealItem::STATUS_VOID, $item->fresh()->status);
        $this->postJson("/api/catering/items/{$item->id}/dispatch")->assertStatus(422);
        $resp = $this->postJson("/api/flights/{$this->f1->id}/scan", ['meal_code' => $oldCode])->assertOk()->json();
        $this->assertSame('void_label', $resp['scan_result']);

        // 旧航班应收清零，新航班重新制作后应收为 1
        $this->assertSame(0, $this->getJson("/api/flights/{$this->f1->id}/loading")->json('expected_count'));
        $task = $order->task;
        $this->postJson("/api/catering/tasks/{$task->id}/start")->assertOk();
        $label = $this->postJson("/api/catering/tasks/{$task->id}/label")->assertOk()->json();
        $this->assertSame(2, $label['label_version']);
        $this->assertNotSame($oldCode, $label['meal_code']);
        $newItem = MealItem::where('meal_code', $label['meal_code'])->sole();
        $this->assertSame($this->f2->id, $newItem->flight_id);
        $this->postJson("/api/catering/items/{$newItem->id}/dispatch")->assertOk();
        $this->assertSame(1, $this->getJson("/api/flights/{$this->f2->id}/loading")->json('expected_count'));
    }

    public function test_reseat_updates_task_and_label(): void
    {
        $item = $this->makeDispatchedMeal('VGML', '9D');
        $order = $item->mealOrder;

        $this->postJson("/api/orders/{$order->id}/reseat", ['seat_no' => '9E'])->assertOk();

        $order->refresh();
        $this->assertSame('9E', $order->seat_no);
        $this->assertSame('9E', $order->task->seat_no);
        $this->assertSame(MealItem::STATUS_VOID, $item->fresh()->status);

        // 重打标签后新标签座位一致
        $task = $order->task;
        $this->postJson("/api/catering/tasks/{$task->id}/start");
        $label = $this->postJson("/api/catering/tasks/{$task->id}/label")->assertOk()->json();
        $this->assertSame('9E', $label['seat_no']);
    }

    public function test_loaded_meal_cannot_be_rebooked_or_cancelled(): void
    {
        $item = $this->makeDispatchedMeal('DBML', '3B');
        $order = $item->mealOrder;
        $this->postJson("/api/flights/{$this->f1->id}/scan", ['meal_code' => $item->meal_code])->assertOk();

        $this->postJson("/api/orders/{$order->id}/rebook", ['flight_id' => $this->f2->id])->assertStatus(422);
        $this->postJson("/api/orders/{$order->id}/reseat", ['seat_no' => '3C'])->assertStatus(422);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(422);
    }

    public function test_cancel_releases_pending_task(): void
    {
        $order = MealOrder::create([
            'order_no' => 'SPML-T901', 'passenger_name' => '取消旅客', 'pnr' => 'PNRX1',
            'flight_id' => $this->f1->id, 'seat_no' => '1A', 'meal_type' => 'CHML', 'is_restricted' => false,
        ]);
        $this->postJson("/api/orders/{$order->id}/confirm", ['meal_type' => 'CHML'])->assertOk();

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $order->refresh();
        $this->assertSame(MealOrder::STATUS_CANCELLED, $order->status);
        $this->assertSame(ProductionTask::STATUS_CANCELLED, $order->task->status);
        // 已取消订单的任务不能再制作
        $this->postJson("/api/catering/tasks/{$order->task->id}/start")->assertStatus(422);
    }

    public function test_restricted_meal_cannot_be_substituted_with_regular(): void
    {
        // 受限餐（糖尿病餐）缺餐 → 不可替代
        $restricted = $this->makeDispatchedMeal('DBML', '11A');
        $this->getJson("/api/flights/{$this->f1->id}/loading"); // 机组查看装机页 → 生成缺口
        $case = ShortageCase::sole();
        $this->postJson("/api/shortages/{$case->id}/resolve", ['resolution' => 'substitute_regular'])
            ->assertStatus(422);
        $this->assertSame(ShortageCase::STATUS_OPEN, $case->fresh()->status);

        // 受限餐可以用其他方式处理
        $this->postJson("/api/shortages/{$case->id}/resolve", ['resolution' => 'deliver_after', 'note' => '落地补发'])
            ->assertOk();
        $this->assertSame(ShortageCase::STATUS_RESOLVED, $case->fresh()->status);

        // 非受限餐（儿童餐）允许替代
        $normal = $this->makeDispatchedMeal('CHML', '12A');
        $this->getJson("/api/flights/{$this->f1->id}/loading");
        $case2 = ShortageCase::where('status', 'open')->sole();
        $this->postJson("/api/shortages/{$case2->id}/resolve", ['resolution' => 'substitute_regular'])
            ->assertOk();
    }

    public function test_duplicate_scan_counts_once_and_shortage_stays(): void
    {
        $a = $this->makeDispatchedMeal('MOML', '20A');
        $b = $this->makeDispatchedMeal('NFML', '20B');

        // 同一份餐重复扫码
        $r1 = $this->postJson("/api/flights/{$this->f1->id}/scan", ['meal_code' => $a->meal_code])->json();
        $r2 = $this->postJson("/api/flights/{$this->f1->id}/scan", ['meal_code' => $a->meal_code])->json();

        $this->assertSame('accepted', $r1['scan_result']);
        $this->assertSame('duplicate', $r2['scan_result']);
        // 实收仍为 1，缺餐缺口不因重复扫码消失
        $this->assertSame(1, $r2['received_count']);
        $this->assertSame(2, $r2['expected_count']);
        $this->assertSame(1, $r2['shortage_count']);
        $this->assertSame(1, ScanEvent::where('result', 'accepted')->count());
        $this->assertSame(1, MealItem::where('status', 'received')->count());
    }

    public function test_shortage_visible_and_blocks_flight_close_until_resolved(): void
    {
        $a = $this->makeDispatchedMeal('KSML', '30A');
        $b = $this->makeDispatchedMeal('CHML', '30B');
        // 只装一份
        $this->postJson("/api/flights/{$this->f1->id}/scan", ['meal_code' => $a->meal_code]);

        $summary = $this->getJson("/api/flights/{$this->f1->id}/loading")->json();
        $this->assertSame(2, $summary['expected_count']);
        $this->assertSame(1, $summary['received_count']);
        $this->assertSame(1, $summary['shortage_count']);
        $this->assertCount(1, $summary['open_shortages']);
        $this->assertSame($b->mealOrder->order_no, $summary['open_shortages'][0]['order_no']);

        // 有未处理缺口时不能关闭装机
        $this->postJson("/api/flights/{$this->f1->id}/close")->assertStatus(422);

        // 确认处理后可以关闭
        $caseId = $summary['open_shortages'][0]['id'] ?? ShortageCase::sole()->id;
        $this->postJson("/api/shortages/{$caseId}/resolve", ['resolution' => 'passenger_declined'])->assertOk();
        $this->postJson("/api/flights/{$this->f1->id}/close")->assertOk();
        $this->assertSame('closed', $this->f1->fresh()->status);
    }

    public function test_scan_rejects_wrong_flight_and_unknown_code(): void
    {
        $item = $this->makeDispatchedMeal('GFML', '15C');

        $resp = $this->postJson("/api/flights/{$this->f2->id}/scan", ['meal_code' => $item->meal_code])->json();
        $this->assertSame('wrong_flight', $resp['scan_result']);
        $this->assertSame(0, $resp['received_count']);

        $resp = $this->postJson("/api/flights/{$this->f1->id}/scan", ['meal_code' => 'M-NOPE99'])->json();
        $this->assertSame('not_found', $resp['scan_result']);
    }
}
