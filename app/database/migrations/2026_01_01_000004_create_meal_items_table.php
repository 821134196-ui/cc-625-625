<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 一行 = 一次标签签发（一份可扫码的实物餐）
        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->string('meal_code')->unique();         // 扫码条码，如 M-8F3K2Q
            $table->foreignId('meal_order_id')->constrained();
            $table->foreignId('flight_id')->constrained();
            $table->string('seat_no');
            $table->string('meal_type');
            $table->unsignedInteger('label_version');
            // labeled -> dispatched -> received ; void（改签/换座/取消后作废旧标签）
            $table->string('status')->default('labeled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_items');
    }
};
