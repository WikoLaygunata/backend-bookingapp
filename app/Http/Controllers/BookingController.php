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
    //public
    public function getPublicAvailabilityMatrix(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'field_id' => 'nullable|exists:fields,id', // 🟢 REVISI: Dijadikan nullable
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $fieldId = $request->field_id;
        $today = Carbon::today();
        $days = 7;

        // 1. Ambil semua template jadwal (row)
        $schedulesQuery = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price')
            // 🟢 FILTER: Hanya terapkan jika field_id diberikan
            ->when($fieldId, function ($query) use ($fieldId) {
                return $query->where('field_id', $fieldId);
            })
            ->with(['field' => fn($q) => $q->select('id', 'name')]) // Load relasi Field untuk grouping
            ->orderBy('field_id')
            ->orderBy('start_time');

        $schedules = $schedulesQuery->get();

        // 2. Tentukan periode 7 hari ke depan (Tidak ada perubahan di sini)
        $dateRange = [];
        $dateRangeFormatted = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $dateRange[] = $date->format('Y-m-d');
            $dateRangeFormatted[] = [
                'date' => $date->format('Y-m-d'),
                'day_name' => $date->isToday() ? 'Hari Ini' : $date->translatedFormat('l')
            ];
        }

        // 3. Ambil semua slot yang SUDAH TERISI (booked)
        $allBookings = BookingDetail::whereIn('booking_date', $dateRange)
            // 🟢 FILTER: Filter booking detail berdasarkan field yang sama
            ->when($fieldId, function ($query) use ($fieldId) {
                return $query->whereHas('schedule', fn($q) => $q->where('field_id', $fieldId));
            })
            ->get(['schedule_id', 'booking_date']);

        $bookingsLookup = $allBookings->mapToGroups(function ($item) {
            return [$item->booking_date . '_' . $item->schedule_id => true];
        });

        // 4. Generate Matriks Final (Mengelompokkan berdasarkan Lapangan)
        $availabilityByField = $schedules->groupBy('field.name')->map(function ($schedules, $fieldName) use ($dateRangeFormatted, $bookingsLookup) {

            $matrix = $schedules->map(function ($schedule) use ($dateRangeFormatted, $bookingsLookup) {
                $row = [
                    'schedule_id' => $schedule->id,
                    'time_slot' => substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5),
                    'price' => (float) $schedule->price,
                ];

                foreach ($dateRangeFormatted as $dateInfo) {
                    $date = $dateInfo['date'];
                    $key = $date . '_' . $schedule->id;
                    $isBooked = $bookingsLookup->has($key);

                    $row[$date] = ['status' => $isBooked ? 'booked' : 'available'];
                }
                return $row;
            });

            return [
                'field_id' => $schedules->first()->field_id,
                'field_name' => $fieldName,
                'matrix_data' => $matrix,
            ];
        })->values(); // Reset keys numeric

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan 7 hari berhasil ditampilkan',
            'date_headers' => $dateRangeFormatted,
            'data' => $availabilityByField,
        ], 200);
    }


    public function getPublicDailyAvailabilityMatrix(Request $request)
    {
        // 1. Validasi & Date Setup
        $validator = Validator::make($request->all(), [
            // booking_date adalah optional, jika tidak ada, default hari ini
            'booking_date' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        // Tentukan tanggal target
        $targetDate = $request->booking_date
            ? Carbon::parse($request->booking_date)->format('Y-m-d')
            : Carbon::today()->format('Y-m-d');

        // 2. Ambil Semua Schedules & Fields
        $schedules = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price')
            // Eager load Field untuk mendapatkan nama lapangan
            ->with(['field' => fn($q) => $q->select('id', 'name')])
            ->orderBy('start_time')
            ->orderBy('field_id')
            ->get();

        // 3. Ambil semua slot yang sudah dibooking pada tanggal target
        $bookedScheduleIds = BookingDetail::where('booking_date', $targetDate)
            ->pluck('schedule_id'); // Mengambil list Schedule ID yang sudah terisi

        // 4. Data Structuring: Tentukan Baris dan Kolom

        // A. Kolom (Field Headers)
        $fields = $schedules->pluck('field')->unique('id')->map(fn($f) => [
            'id' => $f->id,
            'name' => $f->name
        ])->values();

        // B. Baris (Time Slots)
        // Ambil semua waktu yang unik
        $timeSlots = $schedules->pluck('start_time', 'end_time')->unique()->map(function ($startTime, $endTime) {
            return substr($startTime, 0, 5) . ' - ' . substr($endTime, 0, 5);
        })->values()->unique();

        // C. Lookup Schedule ID berdasarkan slot waktu dan field ID
        $scheduleLookup = $schedules->groupBy(function ($schedule) {
            return substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5);
        })->map(function ($group) {
            return $group->keyBy('field_id');
        });

        // 5. Matrix Generation: Isi Baris dengan Ketersediaan per Kolom
        $matrix = $timeSlots->map(function ($timeSlot) use ($fields, $scheduleLookup, $bookedScheduleIds) {
            $row = [
                'time_slot' => $timeSlot,
            ];

            $scheduleByField = $scheduleLookup->get($timeSlot);

            foreach ($fields as $field) {
                // Gunakan format field_[nama_lapangan] sebagai key kolom
                $columnKey = 'field_' . str_replace(' ', '_', $field['name']);

                // Cari Schedule ID untuk kombinasi jam dan lapangan ini
                $scheduleData = $scheduleByField->get($field['id']);
                $scheduleId = optional($scheduleData)->id;

                $slotData = [
                    'field_id' => $field['id'],
                    'schedule_id' => $scheduleId,
                    'status' => 'unavailable', // Default: Tidak ada jadwal
                    'price' => null,
                ];

                if ($scheduleId) {
                    $isBooked = $bookedScheduleIds->contains($scheduleId);

                    $slotData['status'] = $isBooked ? 'booked' : 'available';
                    $slotData['price'] = (float) optional($scheduleData)->price;
                }

                $row[$columnKey] = $slotData;
            }

            return $row;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan harian berhasil ditampilkan',
            'target_date' => $targetDate,
            'field_headers' => $fields, // List Lapangan untuk Frontend membuat Header Kolom
            'matrix_data' => $matrix,
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
     * Tampilan 2: Generate Availability Matrix (7-Day View).
     * Kolom: Jam | Hari 0 (Today) | Hari 1 | ... | Hari 6
     * Digunakan Admin untuk melihat detail Customer dan Status.
     */
    public function getAdminAvailabilityMatrix(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'field_id' => 'required|exists:fields,id', // Harus memilih 1 lapangan
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()->first()], 422);
        }

        $fieldId = $request->field_id;
        $today = Carbon::today();
        $days = 7;

        // 1. Ambil semua template jadwal (row) untuk lapangan tersebut
        $schedules = Schedule::where('field_id', $fieldId)
            ->orderBy('start_time')
            ->get(['id', 'start_time', 'end_time', 'price']);

        // 2. Tentukan periode 7 hari ke depan (Tidak Berubah)
        $dateRange = [];
        $dateRangeFormatted = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $dateRange[] = $date->format('Y-m-d');
            $dateRangeFormatted[] = [
                'date' => $date->format('Y-m-d'),
                'day_name' => $date->isToday() ? 'Hari Ini' : $date->translatedFormat('l')
            ];
        }

        // 3. Ambil semua slot yang dibooking untuk 7 hari di lapangan ini.
        // 🟢 REVISI: Menggunakan BookingDetail dan Eager Load Header & Customer
        $allBookings = BookingDetail::whereIn('booking_date', $dateRange)
            // Filter hanya schedule yang terhubung dengan field_id ini
            ->whereHas('schedule', fn($q) => $q->where('field_id', $fieldId))
            // Load Header dan Customer untuk mendapatkan detail transaksi
            ->with(['header' => fn($q) => $q->select('id', 'customer_id', 'status')->with('customer:id,name')])
            ->get(['id', 'schedule_id', 'booking_header_id', 'booking_date']);

        // Kelompokkan data booking agar mudah dicari (lookup table)
        // Key: Tanggal_ScheduleID. Value: Object BookingDetail (dengan relasi Header/Customer)
        $bookingsByDateAndSchedule = $allBookings->mapToGroups(function ($item) {
            return [$item->booking_date . '_' . $item->schedule_id => $item];
        });

        // 4. Generate Matriks Final
        $matrix = $schedules->map(function ($schedule) use ($dateRangeFormatted, $bookingsByDateAndSchedule) {
            $row = [
                'schedule_id' => $schedule->id,
                'time_slot' => substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5),
                'price' => (float) $schedule->price,
            ];

            foreach ($dateRangeFormatted as $dateInfo) {
                $date = $dateInfo['date'];
                $key = $date . '_' . $schedule->id;

                $bookingData = $bookingsByDateAndSchedule->get($key);

                if ($bookingData->isNotEmpty()) {
                    // Ambil detail dari detail slot pertama (karena hanya boleh 1 booking per slot)
                    $detail = $bookingData->first();
                    $header = $detail->header;

                    $row[$date] = [
                        'status' => 'booked',
                        'booking_header_id' => $header->id,
                        'customer_name' => $header->customer->name, // Ambil dari relasi Customer
                        'booking_status' => $header->status, // dp atau lunas
                    ];
                } else {
                    $row[$date] = ['status' => 'available'];
                }
            }
            return $row;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan 7 hari berhasil ditampilkan',
            'date_headers' => $dateRangeFormatted,
            'matrix_data' => $matrix,
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

        // 2. Ambil Semua Schedules & Fields (Tidak berubah)
        $schedules = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price') // price tetap di-select untuk proses internal
            ->with(['field' => fn($q) => $q->select('id', 'name')])
            ->orderBy('start_time')
            ->orderBy('field_id')
            ->get();

        // 3. Ambil data booking detail LENGKAP untuk tanggal target (Tidak berubah)
        $allBookings = BookingDetail::where('booked_date', $targetDate)
            ->with(['header' => fn($q) => $q->select('id', 'customer_id', 'status')->with('customer:id,name')])
            ->get(['id', 'booking_header_id', 'schedule_id']);

        // 4. Data Structuring: Lookup Table LENGKAP (Tidak berubah)
        $bookingsLookup = $allBookings->keyBy('schedule_id');
        $fields = $schedules->pluck('field')->unique('id')->map(fn($f) => ['id' => $f->id, 'name' => $f->name])->values();
        $timeSlots = $schedules->pluck('start_time', 'end_time')->unique()->map(function ($startTime, $endTime) {
            return substr($startTime, 0, 5) . ' - ' . substr($endTime, 0, 5);
        })->values()->unique();
        $scheduleLookup = $schedules->groupBy(function ($schedule) {
            return substr($schedule->start_time, 0, 5) . ' - ' . substr($schedule->end_time, 0, 5);
        })->map(fn($group) => $group->keyBy('field_id'));

        // 5. Matrix Generation: Isi Baris dengan Detail Admin
        $availabilityByField = $schedules->groupBy('field.name')->map(function ($schedules, $fieldName) use ($timeSlots, $fields, $scheduleLookup, $bookingsLookup) {

            $matrix = $timeSlots->map(function ($timeSlot) use ($schedules, $scheduleLookup, $bookingsLookup, $fields) {
                $row = ['time_slot' => $timeSlot];
                $scheduleByField = $scheduleLookup->get($timeSlot);

                foreach ($fields as $field) {
                    $columnKey = 'field_' . str_replace(' ', '_', $field['name']);
                    $scheduleData = $scheduleByField->get($field['id']);
                    $scheduleId = optional($scheduleData)->id;

                    $slotData = [
                        'field_id' => $field['id'],
                        'schedule_id' => $scheduleId,
                        'status' => 'unavailable',
                        // ❌ PRICE DIHAPUS DARI OUTPUT
                        'booking_header_id' => null,
                        'customer_info' => null,
                    ];

                    if ($scheduleId) {
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
                        } else {
                            $slotData['status'] = 'available';
                        }
                    } else {
                        // Jika schedule_id null, status tetap 'unavailable'
                    }

                    $row[$columnKey] = $slotData;
                }
                return $row;
            });
            return $matrix;
        })->flatten(1)->unique('time_slot')->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Matriks ketersediaan harian untuk Admin berhasil ditampilkan',
            'target_date' => $targetDate,
            'field_headers' => $fields,
            'matrix_data' => $availabilityByField,
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
                'created_at' => now(),
                'updated_at' => now(),
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
            $header = BookingHeader::create([
                'customer_id' => $request->customer_id,
                'subtotal' => $totalBasePrice, // Harga Kotor
                'discount' => $manualDiscount,  // Diskon
                'total' => $finalPrice,         // Harga Final
                'status' => $request->status,
                'notes' => $request->notes,
            ]);

            // B. Simpan Booking Details (Mass Insert)
            $detailsToInsert = collect($bookingsDetailsData)->map(function ($detail) use ($header) {
                // Tambahkan FK booking_header_id ke setiap detail
                $detail['booking_header_id'] = $header->id;
                return $detail;
            })->toArray();

            BookingDetail::insert($detailsToInsert); // Insert massal untuk efisiensi

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Booking (Header ID: ' . $header->id . ') berhasil ditambahkan dengan ' . count($detailsToInsert) . ' slot.',
                'data' => $header->load('details.schedule.field')
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
    // public function show(Booking $booking)
    // {
    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Data berhasil ditampilkan',
    //         'data' => $booking->load(['customer', 'schedule.field'])
    //     ], 200);
    // }

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
        $detailsToCreate = [];
        $scheduleIds = collect($slots)->pluck('schedule_id')->unique();

        // Ambil data harga semua schedule yang dipilih
        $schedulesData = Schedule::whereIn('id', $scheduleIds)->pluck('price', 'id');

        // 2. Validasi Ketersediaan dan Hitung Harga Baru

        // Loop 1: Cek Ketersediaan dan Hitung Subtotal Baru
        foreach ($slots as $slot) {
            $scheduleId = $slot['schedule_id'];
            $date = $slot['booking_date'];
            $pricePerSlot = $schedulesData->get($scheduleId);

            // Cek Ketersediaan di BookingDetail:
            // Slot harus kosong ATAU slot tersebut adalah bagian dari BookingHeader yang sedang di-update.
            $isBookedByOther = BookingDetail::where('schedule_id', $scheduleId)
                ->where('booking_date', $date)
                ->where('booking_header_id', '!=', $bookingHeader->id)
                ->exists();

            if ($isBookedByOther) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Slot jadwal tidak tersedia. Jadwal ' . $date . ' sudah dipesan oleh pihak lain.'
                ], 400);
            }

            // Akumulasi subtotal
            $totalBasePrice += $pricePerSlot;

            // Siapkan data detail untuk dimasukkan
            $detailsToCreate[] = [
                'schedule_id' => $scheduleId,
                'booking_date' => $date,
                'price' => $pricePerSlot,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // 3. Hitung Harga Final
        $finalPrice = $totalBasePrice - $manualDiscount;

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
                'subtotal' => $totalBasePrice,
                'discount' => $manualDiscount,
                'total' => $finalPrice,
                'status' => $request->status,
                'notes' => $request->notes,
            ]);

            // B. Update Booking Details (Simulasi Sync: Delete Lama, Create Baru)

            // 4.1. Hapus semua detail lama yang terhubung ke Header ini (Soft Delete)
            $bookingHeader->details()->delete();

            // 4.2. Buat detail baru (CreateMany)
            // Note: Karena menggunakan mass insert, timestamps manual harus disertakan.
            $bookingHeader->details()->createMany($detailsToCreate);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil mengubah data pemesanan',
                'data' => $bookingHeader->load('details.schedule.field')
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
