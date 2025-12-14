<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Schedule;
use App\Models\BookingHeader;
use App\Models\BookingDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{

    public function getPublicDailyAvailabilityMatrix(Request $request)
    {
        // 1. Validasi & Date Setup
        $validator = Validator::make($request->all(), [
            'booking_date' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $targetDate = $request->booking_date
            ? Carbon::parse($request->booking_date)->format('Y-m-d')
            : Carbon::today()->format('Y-m-d');

        // 2. Ambil Semua Schedules & Fields
        $schedules = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price')
            ->with(['field' => fn($q) => $q->select('id', 'name')->orderBy('name')])
            ->orderBy('start_time')
            ->orderBy('field_id')
            ->get();

        // 3. Ambil semua slot yang sudah dibooking pada tanggal target
        $bookedScheduleIds = BookingDetail::where('booking_date', $targetDate)
            ->pluck('schedule_id');

        // 4. Data Structuring
        $fields = $schedules->pluck('field')->unique('id')->map(fn($f) => [
            'id' => $f->id,
            'name' => $f->name
        ])->sortBy('name')->values();

        // 5. Matrix Generation: Group by Fields, then Time Slots
        $dataByField = $schedules->groupBy('field_id')->map(function ($fieldSchedules, $fieldId) use ($bookedScheduleIds, $fields) {
            $field = $fields->firstWhere('id', $fieldId);

            $schedulesData = $fieldSchedules->map(function ($schedule) use ($bookedScheduleIds) {
                $timeSlot = substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5);
                $scheduleId = $schedule->id;

                $slotData = [
                    'time_slot' => $timeSlot,
                    'schedule_id' => $scheduleId,
                    'price' => $schedule->price,
                    'status' => 'available',
                ];

                // Cek apakah sudah dibooking
                if ($bookedScheduleIds->contains($scheduleId)) {
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

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan harian berhasil ditampilkan',
            'target_date' => $targetDate,
            'data' => $dataByField,
        ], 200);
    }


    /**
     * Tampilan 1: Display a listing of the resource (Table Data View).
     * Filtering berdasarkan created_at dan field_id.
     */
    public function index(Request $request)
    {
        $page = $request->page ?? 1;
        $per_page = $request->per_page ?? 10;
        $search = $request->search;
        $customer_id = $request->customer_id;
        $field_id = $request->field_id;

        // --- Filter Rentang Tanggal (Berdasarkan created_at) ---
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
        // --------------------------------------------------------

        $bookings = BookingHeader::with([
            'customer',
            'details' => fn($q) => $q->with('schedule.field')
        ])

            // 1. Filter Customer ID
            ->when($customer_id, function ($query) use ($customer_id) {
                return $query->where('customer_id', $customer_id);
            })

            // 2. Filter Tanggal Berdasarkan created_at (Kapan data diinput)
            ->when($date_from || $date_to, function ($query) use ($date_from, $date_to) {
                if ($date_from && $date_to) {
                    // Gunakan created_at untuk rentang
                    return $query->whereBetween('created_at', [Carbon::parse($date_from)->startOfDay(), Carbon::parse($date_to)->endOfDay()]);
                } elseif ($date_from) {
                    return $query->where('created_at', '>=', Carbon::parse($date_from)->startOfDay());
                } elseif ($date_to) {
                    return $query->where('created_at', '<=', Carbon::parse($date_to)->endOfDay());
                }
            })

            // 3. Filter Berdasarkan Lapangan (Melalui Detail)
            ->when($field_id, function ($query) use ($field_id) {
                return $query->whereHas('details.schedule', fn($q) => $q->where('field_id', $field_id));
            })

            // 4. Filter Search (Status dan Customer Name)
            ->when($search, function ($query) use ($search) {
                return $query->where('status', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', fn($q) => $q->where('name', 'like', '%' . $search . '%'));
            })

            // Urutan default
            ->orderBy('created_at', 'desc')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data pemesanan',
            'data' => $bookings
        ], 200);
    }

    /**
     * Menampilkan Matriks Ketersediaan Harian (Slot Jam x Semua Lapangan) untuk Admin.
     * Termasuk detail customer dan status booking.
     */
    public function getAdminDailyAvailabilityMatrix(Request $request)
    {
        // 1. Validasi & Date Setup (Tidak berubah)
        $validator = Validator::make($request->all(), [
            'booking_date' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $targetDate = $request->booking_date
            ? Carbon::parse($request->booking_date)->format('Y-m-d')
            : Carbon::today()->format('Y-m-d');

        // 2. Ambil Semua Schedules & Fields
        $schedules = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price')
            ->with(['field' => fn($q) => $q->select('id', 'name')->orderBy('name')])
            ->orderBy('start_time')
            ->orderBy('field_id')
            ->get();

        // 3. Ambil data booking detail LENGKAP untuk tanggal target
        $allBookings = BookingDetail::where('booking_date', $targetDate)
            ->with(['header' => fn($q) => $q->select('id', 'customer_id', 'status')->with('customer:id,name')])
            ->get(['id', 'booking_header_id', 'schedule_id']);

        // 4. Data Structuring: Lookup Table LENGKAP
        $bookingsLookup = $allBookings->keyBy('schedule_id');
        $fields = $schedules->pluck('field')->unique('id')->map(fn($f) => [
            'id' => $f->id,
            'name' => $f->name
        ])->sortBy('name')->values();

        // 5. Matrix Generation: Group by Fields, then Time Slots
        $dataByField = $schedules->groupBy('field_id')->map(function ($fieldSchedules, $fieldId) use ($bookingsLookup, $fields) {
            $field = $fields->firstWhere('id', $fieldId);

            $schedulesData = $fieldSchedules->map(function ($schedule) use ($bookingsLookup) {
                $timeSlot = substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5);
                $scheduleId = $schedule->id;

                $slotData = [
                    'time_slot' => $timeSlot,
                    'schedule_id' => $scheduleId,
                    'price' => $schedule->price,
                    'status' => 'available',
                    'booking_header_id' => null,
                    'customer_info' => null,
                ];

                $bookedDetail = $bookingsLookup->get($scheduleId);
                if ($bookedDetail) {
                    $header = $bookedDetail->header;
                    $slotData['status'] = 'booked';
                    $slotData['booking_header_id'] = $header->id;
                    $slotData['booking_status'] = $header->status; // dp atau lunas
                    $slotData['customer_info'] = [
                        'id' => $header->customer->id,
                        'name' => $header->customer->name,
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

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan harian untuk Admin berhasil ditampilkan',
            'target_date' => $targetDate,
            'data' => $dataByField,
        ], 200);
    }


    /**
     * Membuat multiple booking dari Matriks Ketersediaan Admin.
     * Request Body:
     * {
     * "customer_id": "...",
     * "total_price": 500000, // Harga total untuk semua slot (dihitung FE/BE)
     * "status": "dp",
     * "selected_slots": [
     * {"schedule_id": "id1", "booking_date": "2025-12-05"},
     * {"schedule_id": "id2", "booking_date": "2025-12-05"},
     * {"schedule_id": "id3", "booking_date": "2025-12-06"}
     * ]
     * }
     */
    public function storeMultiple(Request $request)
    {
        // 1. Validasi Data Utama (Sesuaikan dengan kolom Header baru)
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            // Kita terima total harga dari FE untuk disimpan di Header
            'discount' => 'nullable|numeric|min:0', // Diskon manual (opsional, kolom 'discount' di header)
            'status' => ['required', Rule::in(['dp', 'lunas'])],
            'notes' => 'nullable|string',
            'selected_slots' => 'required|array|min:1',
            'selected_slots.*.schedule_id' => 'required|exists:schedules,id',
            'selected_slots.*.booking_date' => 'required|date_format:Y-m-d|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $slots = $request->selected_slots;
        $manualDiscount = $request->discount ?? 0;
        $totalBasePrice = 0;
        $bookingsDetailsData = [];
        $scheduleIds = collect($slots)->pluck('schedule_id')->unique();

        // Ambil data harga semua schedule yang dipilih
        // Gunakan pluck('price', 'id') untuk lookup cepat: [schedule_id => price]
        $schedulesData = Schedule::whereIn('id', $scheduleIds)->pluck('price', 'id');

        // 2. Validasi Ketersediaan (Per Slot) & Penghitungan Harga Dasar
        foreach ($slots as $slot) {
            $scheduleId = $slot['schedule_id'];
            $date = $slot['booking_date'];

            // Cek Ketersediaan di BookingDetail
            $isBooked = BookingDetail::where('schedule_id', $scheduleId)
                ->where('booking_date', $date)
                ->exists(); // Cek keberadaan record (status pasti 'dp' atau 'lunas' karena admin yang input)

            if ($isBooked) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Slot jadwal tidak tersedia. Jadwal ' . $date . ' sudah terisi.'
                ], 400);
            }

            // Hitung Harga Dasar dan siapkan data detail
            $pricePerSlot = $schedulesData->get($scheduleId);
            if ($pricePerSlot === null) {
                return response()->json(['status' => 'error', 'message' => "Harga schedule ID $scheduleId tidak ditemukan."], 400);
            }

            $totalBasePrice += $pricePerSlot; // Akumulasi subtotal

            $bookingsDetailsData[] = [
                'schedule_id' => $scheduleId,
                'booking_date' => $date,
                'price' => $pricePerSlot,
            ];
        }

        // 3. Hitung Harga Final
        $finalPrice = $totalBasePrice - $manualDiscount;

        if ($finalPrice < 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Diskon manual melebihi harga dasar (' . number_format($totalBasePrice, 2) . ').'
            ], 400);
        }

        // 4. Simpan Data dalam Transaksi
        DB::beginTransaction();

        try {
            // A. Simpan Booking Header
            // Gunakan booking_date dari slot pertama (atau earliest date jika berbeda)
            $bookingDate = collect($slots)->pluck('booking_date')->min();

            $header = BookingHeader::create([
                'customer_id' => $request->customer_id,
                'booking_date' => $bookingDate,
                'subtotal' => $totalBasePrice, // Harga Kotor
                'discount' => $manualDiscount,  // Diskon
                'total' => $finalPrice,         // Harga Final
                'status' => $request->status,
                'notes' => $request->notes,
            ]);

            // B. Simpan Booking Details (Mass Insert)
            // Gunakan createMany untuk auto-generate ULIDs dan timestamps
            $header->details()->createMany($bookingsDetailsData);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Booking (Header ID: ' . $header->id . ') berhasil ditambahkan dengan ' . count($bookingsDetailsData) . ' slot.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan booking. Terjadi kesalahan server.',
                'error_detail' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Store a newly created resource in storage.
     */
    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'customer_id' => 'required|exists:customers,id',
    //         'schedule_id' => 'required|exists:schedules,id',
    //         'booking_date' => 'required|date|after_or_equal:today',
    //         'total_price' => 'required|numeric|min:0',
    //         'status' => ['required', Rule::in(['dp', 'lunas'])],
    //         'notes' => 'nullable|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'errors' => $validator->errors(),
    //             'message' => $validator->errors()->first()
    //         ], 422);
    //     }

    //     // Contoh validasi tambahan: memastikan jadwal pada tanggal tersebut belum di-booking
    //     $is_booked = Booking::where('schedule_id', $request->schedule_id)
    //         ->where('booking_date', $request->booking_date)
    //         ->where('status', '!=', 'lunas') // Asumsikan status 'cancelled' tidak menghalangi booking baru
    //         ->exists();

    //     if ($is_booked) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Jadwal pada tanggal ini sudah dipesan.'
    //         ], 400);
    //     }


    //     $booking = Booking::create($request->all());

    //     if (!$booking) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Gagal menambahkan data pemesanan'
    //         ], 400);
    //     }

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Berhasil menambahkan data pemesanan',
    //         'data' => $booking->load(['customer', 'schedule.field'])
    //     ], 201);
    // }

    /**
     * Display the specified resource.
     */
    public function show(BookingHeader $bookingHeader)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $bookingHeader->load(['customer'])
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BookingHeader $bookingHeader)
    {
        // 1. Validasi Data Header dan Detail
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'discount' => 'nullable|numeric|min:0',
            'status' => ['required', Rule::in(['dp', 'lunas'])],
            'notes' => 'nullable|string',
            'selected_slots' => 'nullable|array|min:1',
            'selected_slots.*.schedule_id' => 'required_with:selected_slots|exists:schedules,id',
            'selected_slots.*.booking_date' => 'required_with:selected_slots|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $manualDiscount = $request->discount ?? 0;
        $updateSlots = $request->has('selected_slots');

        // Jika selected_slots tidak disediakan, gunakan detail yang sudah ada
        if ($updateSlots) {
            $slots = $request->selected_slots;
            $totalBasePrice = 0;
            $detailsToCreate = [];
            $scheduleIds = collect($slots)->pluck('schedule_id')->unique();

            // Ambil data harga semua schedule yang dipilih
            $schedulesData = Schedule::whereIn('id', $scheduleIds)->pluck('price', 'id');

            // Loop: Hitung Subtotal Baru dari slots yang dikirim
            foreach ($slots as $slot) {
                $scheduleId = $slot['schedule_id'];
                $date = $slot['booking_date'];
                $pricePerSlot = $schedulesData->get($scheduleId);

                if ($pricePerSlot === null) {
                    return response()->json(['status' => 'error', 'message' => "Harga schedule ID $scheduleId tidak ditemukan."], 400);
                }

                // Akumulasi subtotal
                $totalBasePrice += $pricePerSlot;

                // Siapkan data detail untuk dimasukkan
                // booking_header_id akan otomatis di-set oleh createMany melalui relationship
                $detailsToCreate[] = [
                    'booking_header_id' => $bookingHeader->id,
                    'schedule_id' => $scheduleId,
                    'booking_date' => $date,
                    'price' => $pricePerSlot,
                ];
            }

            // 3. Hitung Harga Final
            $finalPrice = $totalBasePrice - $manualDiscount;
            $bookingDate = collect($slots)->pluck('booking_date')->min();
        } else {
            // Jika tidak update slots, gunakan data existing untuk hitung ulang harga
            $existingDetails = $bookingHeader->details()->get();
            $totalBasePrice = $existingDetails->sum('price');
            $finalPrice = $totalBasePrice - $manualDiscount;
            $bookingDate = $bookingHeader->booking_date;
        }

        if ($finalPrice < 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Diskon manual melebihi harga dasar.'
            ], 400);
        }

        // 4. Simpan Perubahan dalam Transaksi
        DB::beginTransaction();

        try {
            // A. Update Booking Header (Data Utama)
            $bookingHeader->update([
                'customer_id' => $request->customer_id,
                'booking_date' => $bookingDate,
                'subtotal' => $totalBasePrice,
                'discount' => $manualDiscount,
                'total' => $finalPrice,
                'status' => $request->status,
                'notes' => $request->notes,
            ]);

            // B. Update Booking Details hanya jika selected_slots disediakan
            if ($updateSlots) {
                // 4.1. Hapus semua detail lama yang terhubung ke Header ini (Soft Delete)
                $bookingHeader->details()->delete();

                // 4.2. Buat detail baru menggunakan createMany (otomatis generate ULIDs dan timestamps)
                if (!empty($detailsToCreate)) {
                    $bookingHeader->details()->createMany($detailsToCreate);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil mengubah data pemesanan',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data pemesanan.',
                'error_detail' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BookingHeader $bookingHeader)
    {
        if ($bookingHeader->delete()) {
            return response()->json(['status' => 'success', 'message' => 'Berhasil menghapus data pemesanan'], 200);
        }
        return response()->json(['status' => 'error', 'message' => 'Gagal menghapus data pemesanan'], 400);
    }
}
