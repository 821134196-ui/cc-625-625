<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 航班特殊餐食确认与装机核对 —— 领域表结构
 *
 * 数据流：旅客申请(meal_orders.requested)
 *   → 客服确认类别(confirmed，生成 meal_tasks.pending)
 *   → 配餐员制作贴标(meals.prepared，任务 done)
 *   → 机组装机扫码(meals.loaded，scan_events 留痕)
 * 改签/换座：未装机餐食跟随订单，任务与标签同步改属新航班/座位。
 * 取消：释放待制作任务，已备餐作废。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->string('flight_no')->unique();
            $table->string('origin');
            $table->string('destination');
            $table->string('dep_time');
            $table->string('arr_time');
            $table->string('status')->default('scheduled');
            $table->timestamps();
        });

        // 餐食类别字典；restriction_class 决定替代规则
        // regular=普通餐 preference=偏好类 allergy=过敏 medical=医疗 religious=宗教
        Schema::create('meal_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_zh');
            $table->string('restriction_class')->default('preference');
            $table->timestamps();
        });

        Schema::create('meal_orders', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique();            // 申请编号，全链路共用同一份记录
            $table->string('passenger_name');
            $table->string('pnr');
            $table->foreignId('flight_id')->constrained('flights');
            $table->string('seat');
            $table->foreignId('meal_type_id')->nullable()->constrained('meal_types');
            $table->string('requested_type_code');       // 旅客申请时的原始类别
            $table->string('status')->default('requested'); // requested|confirmed|ready|loaded|cancelled
            $table->text('note')->nullable();            // 过敏源/医嘱等说明
            $table->timestamps();
        });

        Schema::create('meal_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_order_id')->constrained('meal_orders');
            $table->foreignId('flight_id')->constrained('flights');
            $table->string('seat');
            $table->foreignId('meal_type_id')->constrained('meal_types');
            $table->string('status')->default('pending'); // pending|done|cancelled
            $table->timestamps();
        });

        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();            // 标签上的餐食编号（扫码对象）
            $table->foreignId('meal_order_id')->constrained('meal_orders');
            $table->foreignId('meal_task_id')->constrained('meal_tasks');
            $table->foreignId('flight_id')->constrained('flights');
            $table->string('seat');
            $table->foreignId('meal_type_id')->constrained('meal_types');
            $table->unsignedInteger('label_version')->default(1); // 改签/换座后需重贴，版本+1
            $table->string('status')->default('prepared'); // prepared|loaded|voided
            $table->timestamp('loaded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->nullable()->constrained('meals');
            $table->string('code_scanned');
            $table->foreignId('flight_id')->constrained('flights');
            // accepted|duplicate|wrong_flight|not_found|voided
            $table->string('result');
            $table->timestamp('created_at')->useCurrent();
        });

        // 同一份记录上的全程留痕：客服/配餐/机组的每次确认都写在这里
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_order_id')->constrained('meal_orders');
            $table->string('actor');   // passenger|cs|kitchen|crew|system
            $table->string('action');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('shortage_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights');
            $table->unsignedInteger('missing_count');
            $table->string('handler');
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortage_acks');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('scan_events');
        Schema::dropIfExists('meals');
        Schema::dropIfExists('meal_tasks');
        Schema::dropIfExists('meal_orders');
        Schema::dropIfExists('meal_types');
        Schema::dropIfExists('flights');
    }
};
