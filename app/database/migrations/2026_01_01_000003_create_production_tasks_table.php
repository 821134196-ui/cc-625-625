<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_order_id')->constrained();
            $table->foreignId('flight_id')->constrained();  // 跟随订单当前航班
            $table->string('seat_no');                      // 跟随订单当前座位
            // pending -> in_progress -> labeled -> done ; cancelled
            $table->string('status')->default('pending');
            $table->unsignedInteger('label_version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_tasks');
    }
};
