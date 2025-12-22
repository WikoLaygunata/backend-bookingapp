<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Membership;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MembershipController extends Controller
{
    /**
     * Display public membership availability matrix for a specific day of the week (1-7)
     * Membership will repeat the same schedule every week
     * Similar to getPublicDailyAvailabilityMatrix but only checks membership
     */
    public function publicMembershipMatrix(Request $request)
    {
        // Validasi day (1-7: Senin-Minggu)
        $validator = Validator::make($request->all(), [
            'day' => 'required|integer|min:1|max:7',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $day = $request->day;

        // 1. Ambil Semua Schedules & Fields
        $schedules = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price')
            ->with(['field' => fn($q) => $q->select('id', 'name')->orderBy('name')])
            ->orderBy('start_time')
            ->orderBy('field_id')
            ->get();

        // 2. Ambil semua schedule_id yang ada di membership aktif untuk hari tersebut
        // Membership akan repeat setiap minggu pada hari yang sama
        $today = Carbon::today();
        $membershipScheduleIds = Membership::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where('booking_day', $day)
            ->pluck('schedule_id');

        // 3. Data Structuring
        $fields = $schedules->pluck('field')->unique('id')->map(fn($f) => [
            'id' => $f->id,
            'name' => $f->name
        ])->sortBy('name')->values();

        // 4. Matrix Generation: Group by Fields, then Time Slots
        $dataByField = $schedules->groupBy('field_id')->map(function ($fieldSchedules, $fieldId) use ($membershipScheduleIds, $fields) {
            $field = $fields->firstWhere('id', $fieldId);

            $schedulesData = $fieldSchedules->map(function ($schedule) use ($membershipScheduleIds) {
                $timeSlot = substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5);
                $scheduleId = $schedule->id;

                $slotData = [
                    'time_slot' => $timeSlot,
                    'schedule_id' => $scheduleId,
                    'price' => $schedule->price,
                    'status' => 'available',
                ];

                // Cek apakah ada membership aktif
                if ($membershipScheduleIds->contains($scheduleId)) {
                    $slotData['status'] = 'booked';
                }

                return $slotData;
            })->sortBy(function ($item) {
                return $item['time_slot'];
            })->values();

            return [
                'field_id' => $fieldId,
                'field_name' => $field['name'] ?? null,
                'schedules' => $schedulesData
            ];
        })->sortBy(function ($item) {
            return $item['field_name'];
        })->values();

        // Map day number to day name
        $dayNames = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
        $dayName = $dayNames[(int)$day] ?? '';

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan harian berhasil ditampilkan',
            'day' => (int)$day,
            'day_name' => $dayName,
            'data' => $dataByField,
        ], 200);
    }
    /**
     * Display membership availability matrix for a specific day of the week (1-7)
     * Membership will repeat the same schedule every week
     */
    public function adminMembershipMatrix(Request $request)
    {
        // Validasi day (1-7: Senin-Minggu)
        $validator = Validator::make($request->all(), [
            'day' => 'required|integer|min:1|max:7',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $day = $request->day; // 1 = Senin, 7 = Minggu

        // 1. Ambil Semua Schedules & Fields
        $schedules = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price')
            ->with(['field' => fn($q) => $q->select('id', 'name')->orderBy('name')])
            ->orderBy('start_time')
            ->orderBy('field_id')
            ->get();

        // 2. Ambil semua membership yang aktif untuk hari tersebut (akan repeat setiap minggu)
        // Cek apakah ada membership yang aktif berdasarkan tanggal hari ini atau masa depan
        $today = Carbon::today();
        $activeMemberships = Membership::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where('booking_day', $day)
            ->select('id', 'name', 'schedule_id')
            ->get();

        // 3. Data Structuring: Lookup Table untuk membership
        $membershipsLookup = $activeMemberships->keyBy('schedule_id');
        $fields = $schedules->pluck('field')->unique('id')->map(fn($f) => [
            'id' => $f->id,
            'name' => $f->name
        ])->sortBy('name')->values();

        // 4. Matrix Generation: Group by Fields, then Time Slots
        $dataByField = $schedules->groupBy('field_id')->map(function ($fieldSchedules, $fieldId) use ($membershipsLookup, $fields) {
            $field = $fields->firstWhere('id', $fieldId);

            $schedulesData = $fieldSchedules->map(function ($schedule) use ($membershipsLookup) {
                $timeSlot = substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5);
                $scheduleId = $schedule->id;

                $slotData = [
                    'time_slot' => $timeSlot,
                    'schedule_id' => $scheduleId,
                    'price' => $schedule->price,
                    'status' => 'available',
                    'membership_info' => null,
                ];

                // Cek apakah ada membership aktif yang menggunakan schedule ini
                $membership = $membershipsLookup->get($scheduleId);
                if ($membership) {
                    $slotData['status'] = 'booked';
                    $slotData['membership_info'] = [
                        'id' => $membership->id,
                        'name' => 'Member ' . $membership->name,
                    ];
                }

                return $slotData;
            })->sortBy(function ($item) {
                return $item['time_slot'];
            })->values();

            return [
                'field_id' => $fieldId,
                'field_name' => $field['name'] ?? null,
                'schedules' => $schedulesData
            ];
        })->sortBy(function ($item) {
            return $item['field_name'];
        })->values();

        // Map day number to day name
        $dayNames = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
        $dayName = $dayNames[$day] ?? '';

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan membership untuk ' . $dayName . ' berhasil ditampilkan',
            'day' => $day,
            'day_name' => $dayName,
            'data' => $dataByField,
        ], 200);
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $page = $request->page ?? 1;
        $per_page = $request->per_page ?? 10;
        $search = $request->search;
        $field_id = $request->field_id;

        // Filter Rentang Tanggal (Berdasarkan start_date atau end_date)
        $date_from = $request->date_from;
        $date_to = $request->date_to;

        // Validasi opsional untuk memastikan format tanggal benar jika ada
        if ($date_from || $date_to) {
            $validator = Validator::make($request->all(), [
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'message' => 'Format tanggal tidak valid.'], 422);
            }
        }

        $memberships = Membership::with(['field', 'schedule'])
            // 1. Filter Field ID
            ->when($field_id, function ($query) use ($field_id) {
                return $query->where('field_id', $field_id);
            })
            // 2. Filter Tanggal Berdasarkan start_date atau end_date
            ->when($date_from || $date_to, function ($query) use ($date_from, $date_to) {
                if ($date_from && $date_to) {
                    return $query->where(function ($q) use ($date_from, $date_to) {
                        $q->whereBetween('start_date', [Carbon::parse($date_from)->format('Y-m-d'), Carbon::parse($date_to)->format('Y-m-d')])
                            ->orWhereBetween('end_date', [Carbon::parse($date_from)->format('Y-m-d'), Carbon::parse($date_to)->format('Y-m-d')])
                            ->orWhere(function ($subQ) use ($date_from, $date_to) {
                                $subQ->where('start_date', '<=', Carbon::parse($date_from)->format('Y-m-d'))
                                    ->where('end_date', '>=', Carbon::parse($date_to)->format('Y-m-d'));
                            });
                    });
                } elseif ($date_from) {
                    return $query->where('end_date', '>=', Carbon::parse($date_from)->format('Y-m-d'));
                } elseif ($date_to) {
                    return $query->where('start_date', '<=', Carbon::parse($date_to)->format('Y-m-d'));
                }
            })
            // 3. Filter Search (Name dan Phone)
            ->when($search, function ($query) use ($search) {
                return $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            })
            // Urutan default
            ->orderBy('created_at', 'desc')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data membership',
            'data' => $memberships
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'field_id' => 'required|exists:fields,id',
            'schedule_id' => 'required|exists:schedules,id',
            'booking_day' => 'required|integer|min:1|max:7',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        $membership = Membership::create($request->all());

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan data membership'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menambahkan data membership',
            'data' => $membership->load(['field', 'schedule'])
        ], 201);
    }

    /**
     * Membuat multiple membership sekaligus.
     * Request Body:
     * {
     * "name": "John Doe",
     * "phone": "081234567890",
     * "booking_day": 1,
     * "start_date": "2025-01-01",
     * "end_date": "2025-12-31",
     * "total": 5000000,
     * "notes": "Optional notes",
     * "selected_schedules": ["schedule_id1", "schedule_id2", "schedule_id3"]
     * }
     */
    public function storeMultiple(Request $request)
    {
        // 1. Validasi Data Utama
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'booking_day' => 'required|integer|min:1|max:7',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'selected_schedules' => 'required|array|min:1',
            'selected_schedules.*' => 'required|exists:schedules,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $scheduleIds = collect($request->selected_schedules)->unique();

        // 2. Ambil data schedule untuk mendapatkan field_id
        $schedulesData = Schedule::whereIn('id', $scheduleIds)
            ->select('id', 'field_id')
            ->get()
            ->keyBy('id');

        // Validasi semua schedule_id valid
        if ($schedulesData->count() !== $scheduleIds->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Beberapa schedule_id tidak valid atau tidak ditemukan.'
            ], 400);
        }

        // 3. Validasi Ketersediaan (Per Schedule)
        // Cek apakah ada membership aktif yang sudah menggunakan schedule_id tersebut pada hari yang sama
        // Membership akan repeat setiap minggu, jadi cek apakah ada overlap dengan periode yang diminta
        foreach ($scheduleIds as $scheduleId) {
            $hasConflict = Membership::where('schedule_id', $scheduleId)
                ->where('booking_day', $request->booking_day)
                ->where(function ($query) use ($request) {
                    $query->where(function ($q) use ($request) {
                        // Overlap: start_date <= request.end_date AND end_date >= request.start_date
                        $q->where('start_date', '<=', $request->end_date)
                            ->where('end_date', '>=', $request->start_date);
                    });
                })
                ->exists();

            if ($hasConflict) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Schedule ID ' . $scheduleId . ' sudah digunakan oleh membership aktif pada periode yang sama.'
                ], 400);
            }
        }

        // 4. Simpan Data dalam Transaksi
        DB::beginTransaction();

        try {
            $createdMemberships = [];

            foreach ($scheduleIds as $scheduleId) {
                $schedule = $schedulesData->get($scheduleId);

                $membership = Membership::create([
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'field_id' => $schedule->field_id,
                    'schedule_id' => $scheduleId,
                    'booking_day' => $request->booking_day,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                    'total' => $request->total,
                    'notes' => $request->notes,
                ]);

                $createdMemberships[] = $membership;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil menambahkan ' . count($createdMemberships) . ' membership.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan membership. Terjadi kesalahan server.',
                'error_detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Membership $membership)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $membership->load(['field', 'schedule'])
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Membership $membership)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'field_id' => 'required|exists:fields,id',
            'schedule_id' => 'required|exists:schedules,id',
            'booking_day' => 'required|integer|min:1|max:7',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        if (!$membership->update($request->all())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data membership'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil mengubah data membership',
            'data' => $membership->load(['field', 'schedule'])
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Membership $membership)
    {
        if (!$membership->delete()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus data membership'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus data membership'
        ], 200);
    }
}
