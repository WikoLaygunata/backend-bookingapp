<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MembershipController;

Route::post('dashboard/login', [AuthController::class, 'login']);

Route::get('public-fields', [BookingController::class, 'getPublicDailyAvailabilityMatrix']);

Route::get('public-memberships/matrix', [MembershipController::class, 'publicMembershipMatrix']);

Route::get('schedules', [ScheduleController::class, 'index']);
Route::get('schedules/{field_id}', [ScheduleController::class, 'show']);

Route::get('all-packages', [PackageController::class, 'all']);

Route::middleware(['auth:api'])->prefix('dashboard')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::get('home/table', [HomeController::class, 'table']);
    Route::get('home/matrix', [HomeController::class, 'matrix']);

    Route::get('all-customers', [CustomerController::class, 'all']);
    Route::get('all-fields', [FieldController::class, 'all']);

    Route::apiResource('customers', CustomerController::class);

    Route::get('memberships/admin-matrix', [MembershipController::class, 'adminMembershipMatrix']);
    Route::post('memberships/store-multiple', [MembershipController::class, 'storeMultiple']);
    Route::apiResource('memberships', MembershipController::class);

    Route::apiResource('fields', FieldController::class);
    Route::apiResource('packages', PackageController::class);

    Route::apiResource('schedules', ScheduleController::class);
    Route::post('schedules/{field_id}', [ScheduleController::class, 'store']);

    Route::apiResource('bookings', BookingController::class, ['except' => ['show', 'update']]);
    Route::put('bookings/{bookingHeader}', [BookingController::class, 'update']);

    Route::get('bookings/admin-matrix', [BookingController::class, 'getAdminDailyAvailabilityMatrix']);
    Route::post('bookings/store-multiple', [BookingController::class, 'storeMultiple']);

    // DP Booking routes
    Route::get('bookings/dp', [BookingController::class, 'getDPBooking']);
    Route::put('bookings/{bookingHeader}/payment', [BookingController::class, 'paymentBooking']);
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
