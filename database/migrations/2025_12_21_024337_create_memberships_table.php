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
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('phone');

            $table->ulid('field_id');
            $table->foreign('field_id')->references('id')->on('fields')->onDelete('cascade');

            $table->ulid('schedule_id');
            $table->foreign('schedule_id')->references('id')->on('schedules')->onDelete('cascade');

            $table->tinyInteger('booking_day')->comment('1=Senin, 2=Selasa, 3=Rabu, 4=Kamis, 5=Jumat, 6=Sabtu, 7=Minggu');

            $table->date('start_date');
            $table->date('end_date');

            $table->decimal('total', 10, 2);
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
        Schema::dropIfExists('memberships');
    }
};
