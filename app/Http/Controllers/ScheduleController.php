<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Schedule;

class ScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $page = $request->page ?? 1;
        $per_page = $request->per_page ?? 10;
        $search = $request->search;
        $field_id = $request->field_id;

        $schedules = Schedule::with('field') // Load relasi Field
            ->when($field_id, function ($query) use ($field_id) {
                return $query->where('field_id', $field_id);
            })
            ->when($search, function ($query) use ($search) {
                return $query->where('status', 'like', '%' . $search . '%');
            })
            ->orderBy('start_time')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data jadwal',
            'data' => $schedules
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'field_id' => 'required|exists:fields,id',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'status' => ['required', Rule::in(['available', 'booked', 'maintenance'])],
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        $schedule = Schedule::create($request->all());

        if (!$schedule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan data jadwal'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menambahkan data jadwal',
            'data' => $schedule->load('field')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Schedule $schedule)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $schedule->load('field')
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Schedule $schedule)
    {
        $validator = Validator::make($request->all(), [
            'field_id' => 'required|exists:fields,id',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
            'status' => ['required', Rule::in(['available', 'booked', 'maintenance'])],
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        if (!$schedule->update($request->all())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data jadwal'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil mengubah data jadwal',
            'data' => $schedule->load('field')
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Schedule $schedule)
    {
        // Pengecekan relasi ke Booking (opsional)
        if ($schedule->bookings()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus jadwal yang masih terhubung dengan booking'
            ], 400);
        }

        if (!$schedule->delete()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus data jadwal'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus data jadwal'
        ], 200);
    }
}
