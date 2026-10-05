<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();          // SPML-0001
            $table->string('passenger_name');
            $table->string('pnr');
            $table->foreignId('flight_id')->constrained();
            $table->string('seat_no');
            $table->string('meal_type');                   // 见 config/mealtypes.php
            $table->boolean('is_restricted')->default(false);
            // pending_cs -> confirmed -> loaded ; 任意时刻可 cancelled（未装机前）
            $table->string('status')->default('pending_cs');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_orders');
    }
};
