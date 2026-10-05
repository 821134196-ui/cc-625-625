<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_events', function (Blueprint $table) {
            $table->id();
            $table->string('meal_code');
            $table->foreignId('meal_item_id')->nullable()->constrained();
            $table->foreignId('flight_id')->constrained();  // 正在装机的航班
            $table->string('scanned_by')->default('crew');
            // accepted | duplicate | wrong_flight | void_label | not_dispatched | not_found
            $table->string('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_events');
    }
};
