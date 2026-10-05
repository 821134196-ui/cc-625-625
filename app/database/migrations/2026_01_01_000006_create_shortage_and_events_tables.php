<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 少餐缺口：机组必须看见并确认处理
        Schema::create('shortage_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained();
            $table->foreignId('meal_order_id')->constrained();
            $table->string('status')->default('open');      // open | resolved
            $table->string('resolution')->nullable();       // 见 config/mealtypes.php
            $table->string('note')->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['flight_id', 'meal_order_id']);
        });

        // 同一份订单记录上的确认链（审计）
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_order_id')->constrained();
            $table->string('actor_role');                   // cs | catering | crew | system
            $table->string('event');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('shortage_cases');
    }
};
