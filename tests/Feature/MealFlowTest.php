<?php

namespace Tests\Feature;

use App\Models\Flight;
use App\Models\Meal;
use App\Models\MealOrder;
use App\Models\MealTask;
use App\Models\MealType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 需求验证测试：
 *  - 改签/换座后标签、任务归属一致，旧航班不能继续出库
 *  - 过敏/医疗/宗教类特殊餐不得擅自用普通餐替代
 *  - 重复装机扫码只计一份，缺餐情况不会因此消失
 *  - 取消申请释放待制作任务
 *  - 装机页应收/实收/缺口可见，少餐须确认处理
 */
class MealFlowTest extends TestCase
{
    use RefreshDatabase;

    private Flight $flightA;

    private Flight $flightB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flightA = Flight::create(['flight_no' => 'CA100', 'origin' => '北京', 'destination' => '上海', 'dep_time' => '08:00', 'arr_time' => '10:00']);
        $this->flightB = Flight::create(['flight_no' => 'MU200', 'origin' => '北京', 'destination' => '广州', 'dep_time' => '11:00', 'arr_time' => '14:00']);

        foreach ([
            ['REGULAR', '普通餐', 'regular'],
            ['VGML', '素食餐', 'preference'],
            ['MOML', '清真餐', 'religious'],
            ['GFML', '无麸质餐', 'medical'],
            ['ALML', '坚果过敏餐', 'allergy'],
        ] as [$code, $zh, $class]) {
            MealType::create(['code' => $code, 'name_zh' => $zh, 'restriction_class' => $class]);
        }
    }

    /** 申请→确认→制作贴标→扫码装机 全链路 */
    public function test_full_flow(): void
    {
        $order = $this->applyOrder('ALML');
        $this->postJson("/api/orders/{$order->id}/confirm", ['meal_type_code' => 'ALML'])->assertOk();
        $this->assertDatabaseHas('meal_tasks', ['meal_order_id' => $order->id, 'status' => 'pending']);

        $task = MealTask::where('meal_order_id', $order->id)->sole();
        $meal = $this->postJson("/api/tasks/{$task->id}/produce")->assertCreated()->json();
        $this->assertSame('prepared', Meal::find($meal['id'])->status);

        $this->postJson('/api/scan', ['code' => $meal['code'], 'flight_id' => $this->flightA->id])
            ->assertOk()->assertJsonPath('result', 'accepted');
        $this->assertSame('loaded', $order->fresh()->status);
    }

    /** 改签后：订单/任务/标签归属新航班，旧航班不能出库 */
    public function test_rebook_moves_task_and_meal_and_blocks_old_flight(): void
    {
        $order = $this->applyOrder('MOML', '12A');
        $this->confirmAndProduce($order);
        $meal = $order->fresh()->meal;
        $oldCode = $meal->code;

        // 改签 A→B 并换座 12A→5C
        $this->postJson("/api/orders/{$order->id}/rebook", [
            'flight_id' => $this->flightB->id, 'seat' => '5C',
        ])->assertOk();

        // 标签与任务归属一致
        $order = $order->fresh();
        $this->assertSame($this->flightB->id, $order->flight_id);
        $this->assertSame('5C', $order->seat);
        $this->assertSame($this->flightB->id, $order->meal->flight_id);
        $this->assertSame('5C', $order->meal->seat);
        $this->assertSame(2, $order->meal->label_version); // 需重贴标签
        $this->assertSame($this->flightB->id, $order->task->flight_id);
        $this->assertSame('5C', $order->task->seat);

        // 旧航班不能继续出库
        $this->postJson('/api/scan', ['code' => $oldCode, 'flight_id' => $this->flightA->id])
            ->assertStatus(422)->assertJsonPath('result', 'wrong_flight');

        // 应收量跟随订单移动
        $this->assertSame(0, $this->loading($this->flightA)['expected']);
        $this->assertSame(1, $this->loading($this->flightB)['expected']);

        // 新航班可以正常装机
        $this->postJson('/api/scan', ['code' => $oldCode, 'flight_id' => $this->flightB->id])
            ->assertOk()->assertJsonPath('result', 'accepted');
        $this->assertSame(1, $this->loading($this->flightB)['received']);
    }

    /** 仅换座：标签座位与任务座位同步 */
    public function test_seat_change_updates_label_and_task(): void
    {
        $order = $this->applyOrder('GFML', '12A');
        $this->confirmAndProduce($order);

        $this->postJson("/api/orders/{$order->id}/rebook", [
            'flight_id' => $this->flightA->id, 'seat' => '30D',
        ])->assertOk();

        $order = $order->fresh();
        $this->assertSame('30D', $order->meal->seat);
        $this->assertSame('30D', $order->task->seat);
        $this->assertSame($this->flightA->id, $order->meal->flight_id);
    }

    /** 待制作任务在改签后同步改派 */
    public function test_rebook_before_production_moves_pending_task(): void
    {
        $order = $this->applyOrder('MOML');
        $this->postJson("/api/orders/{$order->id}/confirm", ['meal_type_code' => 'MOML']);

        $this->postJson("/api/orders/{$order->id}/rebook", [
            'flight_id' => $this->flightB->id, 'seat' => '7B',
        ])->assertOk();

        $task = $order->fresh()->task;
        $this->assertSame($this->flightB->id, $task->flight_id);
        $this->assertSame('7B', $task->seat);
        $this->assertSame('pending', $task->status);
    }

    /** 过敏/医疗/宗教类特殊餐不得用普通餐（或偏好餐）替代 */
    public function test_restricted_meal_cannot_be_substituted_with_regular(): void
    {
        foreach (['ALML', 'GFML', 'MOML'] as $code) {
            $order = $this->applyOrder($code);
            $this->postJson("/api/orders/{$order->id}/confirm", ['meal_type_code' => $code]);

            $this->postJson("/api/orders/{$order->id}/substitute", ['meal_type_code' => 'REGULAR'])
                ->assertStatus(422);
            $this->postJson("/api/orders/{$order->id}/substitute", ['meal_type_code' => 'VGML'])
                ->assertStatus(422);

            // 类别未被篡改
            $this->assertSame($code, $order->fresh()->mealType->code);
        }

        // 受限→受限 允许（过敏餐缺货时换医疗餐）
        $order = $this->applyOrder('ALML');
        $this->postJson("/api/orders/{$order->id}/confirm", ['meal_type_code' => 'ALML']);
        $this->postJson("/api/orders/{$order->id}/substitute", ['meal_type_code' => 'GFML'])->assertOk();
        $this->assertSame('GFML', $order->fresh()->mealType->code);

        // 偏好类（素食）不受此限
        $order = $this->applyOrder('VGML');
        $this->postJson("/api/orders/{$order->id}/confirm", ['meal_type_code' => 'VGML']);
        $this->postJson("/api/orders/{$order->id}/substitute", ['meal_type_code' => 'REGULAR'])->assertOk();
    }

    /** 重复扫码只计一份，缺口不会消失 */
    public function test_duplicate_scan_counts_once_and_shortage_remains(): void
    {
        $o1 = $this->applyOrder('MOML', '1A');
        $o2 = $this->applyOrder('GFML', '1B');
        $m1 = $this->confirmAndProduce($o1);
        $this->confirmAndProduce($o2);

        $this->postJson('/api/scan', ['code' => $m1->code, 'flight_id' => $this->flightA->id])
            ->assertOk()->assertJsonPath('result', 'accepted');

        // 重复扫同一份餐
        $this->postJson('/api/scan', ['code' => $m1->code, 'flight_id' => $this->flightA->id])
            ->assertOk()->assertJsonPath('result', 'duplicate');

        $summary = $this->loading($this->flightA);
        $this->assertSame(2, $summary['expected']);
        $this->assertSame(1, $summary['received']);   // 实收不重复计数
        $this->assertSame(1, $summary['shortage']);   // 缺口仍在
    }

    /** 取消申请：释放待制作任务；已备餐作废且不能装机 */
    public function test_cancel_releases_task_and_voids_meal(): void
    {
        // 待制作任务被释放
        $o1 = $this->applyOrder('MOML');
        $this->postJson("/api/orders/{$o1->id}/confirm", ['meal_type_code' => 'MOML']);
        $this->postJson("/api/orders/{$o1->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $o1->fresh()->task->status);
        $this->assertSame(0, $this->loading($this->flightA)['expected']);

        // 已贴标餐食作废，扫码拒绝
        $o2 = $this->applyOrder('GFML');
        $meal = $this->confirmAndProduce($o2);
        $this->postJson("/api/orders/{$o2->id}/cancel")->assertOk();
        $this->assertSame('voided', $meal->fresh()->status);
        $this->postJson('/api/scan', ['code' => $meal->code, 'flight_id' => $this->flightA->id])
            ->assertStatus(422)->assertJsonPath('result', 'voided');
        $this->assertSame(0, $this->loading($this->flightA)['received']);
    }

    /** 少餐缺口可见，机组须确认处理 */
    public function test_shortage_visible_and_acknowledged(): void
    {
        $o1 = $this->applyOrder('MOML', '1A');
        $o2 = $this->applyOrder('GFML', '1B');
        $this->confirmAndProduce($o1);
        // 第二份只确认不制作 → 缺口 2
        $this->postJson("/api/orders/{$o2->id}/confirm", ['meal_type_code' => 'GFML']);

        $summary = $this->loading($this->flightA);
        $this->assertSame(2, $summary['expected']);
        $this->assertSame(0, $summary['received']);
        $this->assertSame(2, $summary['shortage']);

        $this->postJson("/api/flights/{$this->flightA->id}/shortage-ack", [
            'handler' => '乘务长-王莉', 'note' => '配餐车晚到，落地后补送旅客',
        ])->assertCreated();

        $acks = $this->loading($this->flightA)['acks'];
        $this->assertCount(1, $acks);
        $this->assertSame(2, $acks[0]['missing_count']);
    }

    /** 已装机订单不能改签或取消 */
    public function test_loaded_order_is_locked(): void
    {
        $order = $this->applyOrder('MOML');
        $meal = $this->confirmAndProduce($order);
        $this->postJson('/api/scan', ['code' => $meal->code, 'flight_id' => $this->flightA->id]);

        $this->postJson("/api/orders/{$order->id}/rebook", [
            'flight_id' => $this->flightB->id, 'seat' => '9A',
        ])->assertStatus(422);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(422);
    }

    /** 扫不存在的编号 */
    public function test_scan_unknown_code(): void
    {
        $this->postJson('/api/scan', ['code' => 'ML-999999', 'flight_id' => $this->flightA->id])
            ->assertStatus(422)->assertJsonPath('result', 'not_found');
    }

    /* ---------------- 辅助 ---------------- */

    private function applyOrder(string $typeCode, string $seat = '12A'): MealOrder
    {
        $res = $this->postJson('/api/orders', [
            'passenger_name' => '测试旅客',
            'pnr' => 'ABC123',
            'flight_id' => $this->flightA->id,
            'seat' => $seat,
            'meal_type_code' => $typeCode,
        ])->assertCreated();

        return MealOrder::findOrFail($res->json('id'));
    }

    private function confirmAndProduce(MealOrder $order): Meal
    {
        $this->postJson("/api/orders/{$order->id}/confirm", [
            'meal_type_code' => $order->requested_type_code,
        ])->assertOk();
        $task = $order->fresh()->task;
        $res = $this->postJson("/api/tasks/{$task->id}/produce")->assertCreated();

        return Meal::findOrFail($res->json('id'));
    }

    private function loading(Flight $flight): array
    {
        return $this->getJson("/api/flights/{$flight->id}/loading")->assertOk()->json();
    }
}
