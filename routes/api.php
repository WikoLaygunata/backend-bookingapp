<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BookingController;


Route::get('fields', [FieldController::class, 'index']);
Route::get('fields/{field}', [FieldController::class, 'show']);

Route::get('packages', [PackageController::class, 'index']);
Route::get('packages/{package}', [PackageController::class, 'show']);

Route::get('schedules', [ScheduleController::class, 'index']);
Route::get('schedules/{schedule}', [ScheduleController::class, 'show']);

Route::middleware(['auth:api'])->prefix('dashboard')->group(function () {

    Route::apiResource('customers', CustomerController::class);

    Route::apiResource('fields', FieldController::class)->except(['index', 'show']);
    Route::apiResource('packages', PackageController::class)->except(['index', 'show']);
    Route::apiResource('schedules', ScheduleController::class)->except(['index', 'show']);
    Route::apiResource('bookings', BookingController::class);
});

Route::middleware(['auth:api', 'admin'])->prefix('dashboard')->group(function () {
    Route::apiResource('users', UserController::class);
});

Route::fallback(function () {
    return response()->json([
        'status' => 'error',
        'message' => 'Rute tidak ditemukan.'
    ], 404);
});
