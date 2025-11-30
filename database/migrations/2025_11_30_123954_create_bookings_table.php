<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->ulid('schedule_id');
            $table->foreign('schedule_id')->references('id')->on('schedules')->onDelete('cascade');

            $table->date('booking_date');
            $table->decimal('total_price', 10, 2);
            $table->enum('status', ['dp', 'lunas'])->default('dp');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
