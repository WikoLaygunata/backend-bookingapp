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
        Schema::create('booking_details', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('booking_header_id');
            $table->foreign('booking_header_id')->references('id')->on('booking_headers')->onDelete('cascade');

            $table->ulid('schedule_id');
            $table->foreign('schedule_id')->references('id')->on('schedules')->onDelete('cascade');

            $table->date('booking_date');
            $table->decimal('price', 10, 2);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['schedule_id', 'booking_date', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_details');
    }
};
