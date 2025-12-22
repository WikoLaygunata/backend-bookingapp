<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BookingHeader;
use App\Models\BookingDetail;
use App\Models\Schedule;
use App\Models\Membership;
use Carbon\Carbon;

class HomeController extends Controller
{
    /**
     * Display all booking headers that have booking details with today's date
     */
    public function table(Request $request)
    {
        $today = Carbon::today()->format('Y-m-d');
        $todayDayOfWeek = Carbon::parse($today)->dayOfWeekIso; // 1 = Senin, 7 = Minggu

        // Ambil semua booking hari ini
        $bookings = BookingHeader::with([
            'customer',
            'details' => fn($q) => $q->with('schedule.field')
        ])
            ->whereHas('details', function ($query) use ($today) {
                $query->where('booking_date', $today);
            })
            ->get();

        // Ambil semua membership yang aktif untuk hari ini (booking_day sama dan range tanggal meng-cover hari ini)
        $membershipsToday = Membership::with(['field', 'schedule'])
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where('booking_day', $todayDayOfWeek)
            ->get();

        // Merge bookings dan memberships, lalu sort by field name dan schedule start_time
        $combined = $bookings
            ->concat($membershipsToday)
            ->sortBy(function ($item) {
                // BookingHeader: ambil schedule & field dari detail pertama
                if ($item instanceof \App\Models\BookingHeader) {
                    $firstDetail = $item->details->first();
                    $schedule = $firstDetail?->schedule;
                    $field = $schedule?->field;
                } else {
                    // Membership: langsung gunakan relasi schedule & field
                    $schedule = $item->schedule ?? null;
                    $field = $item->field ?? null;
                }

                $fieldName = $field->name ?? '';
                $startTime = $schedule->start_time ?? '';

                return $fieldName . ' ' . $startTime;
            })
            ->values()
            // Normalisasi struktur agar frontend bisa selalu pakai struktur BookingHeader
            ->map(function ($item) use ($today) {
                if ($item instanceof \App\Models\Membership) {
                    // 1) Bungkus nama member ke dalam customer
                    $item->setAttribute('customer', [
                        'id' => $item->id,
                        'name' => 'Member ' . $item->name,
                    ]);

                    // 2) Tandai status khusus untuk membership
                    $item->setAttribute('status', 'membership');

                    // 3) Bentuk struktur details agar mirip BookingHeader::details
                    $schedule = $item->schedule;
                    $field = $item->field;

                    $fakeDetail = [
                        'id' => null,
                        'booking_header_id' => null,
                        'schedule_id' => $schedule?->id,
                        'booking_date' => $today,
                        'price' => $item->total,
                        'schedule' => [
                            'id' => $schedule?->id,
                            'field_id' => $field?->id,
                            'start_time' => $schedule?->start_time,
                            'end_time' => $schedule?->end_time,
                            'status' => $schedule?->status,
                            'price' => $schedule?->price,
                            'field' => $field ? $field->toArray() : null,
                        ],
                    ];

                    $item->setAttribute('details', [$fakeDetail]);
                }
                return $item;
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data pemesanan dan membership hari ini',
            'data' => $combined
        ], 200);
    }

    /**
     * Display daily availability matrix for today (fixed date, no filters)
     */
    public function matrix()
    {
        $targetDate = Carbon::today()->format('Y-m-d');

        // 1. Ambil Semua Schedules & Fields
        $schedules = Schedule::select('id', 'field_id', 'start_time', 'end_time', 'price')
            ->with(['field' => fn($q) => $q->select('id', 'name')->orderBy('name')])
            ->orderBy('start_time')
            ->orderBy('field_id')
            ->get();

        // 2. Ambil data booking detail LENGKAP untuk tanggal hari ini
        $allBookings = BookingDetail::where('booking_date', $targetDate)
            ->with(['header' => fn($q) => $q->select('id', 'customer_id', 'status')->with('customer:id,name')])
            ->get(['id', 'booking_header_id', 'schedule_id']);

        // 2.1. Ambil data membership yang aktif untuk tanggal hari ini
        // Cek booking_day sesuai dengan hari dari tanggal target (1=Senin, 7=Minggu)
        $targetDayOfWeek = Carbon::parse($targetDate)->dayOfWeekIso; // 1=Senin, 7=Minggu
        $allMemberships = Membership::where('start_date', '<=', $targetDate)
            ->where('end_date', '>=', $targetDate)
            ->where('booking_day', $targetDayOfWeek)
            ->select('id', 'name', 'schedule_id')
            ->get();

        // 3. Data Structuring: Lookup Table LENGKAP
        $bookingsLookup = $allBookings->keyBy('schedule_id');
        $membershipsLookup = $allMemberships->keyBy('schedule_id');
        $fields = $schedules->pluck('field')->unique('id')->map(fn($f) => [
            'id' => $f->id,
            'name' => $f->name
        ])->sortBy('name')->values();

        // 4. Matrix Generation: Group by Fields, then Time Slots
        $dataByField = $schedules->groupBy('field_id')->map(function ($fieldSchedules, $fieldId) use ($bookingsLookup, $membershipsLookup, $fields) {
            $field = $fields->firstWhere('id', $fieldId);

            $schedulesData = $fieldSchedules->map(function ($schedule) use ($bookingsLookup, $membershipsLookup) {
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

                // Prioritas: Booking lebih dulu, baru Membership
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
                    // Cek membership jika tidak ada booking
                    $membership = $membershipsLookup->get($scheduleId);
                    if ($membership) {
                        $slotData['status'] = 'booked';
                        $slotData['booking_header_id'] = null; // Membership tidak punya booking_header_id
                        $slotData['booking_status'] = 'membership';
                        $slotData['customer_info'] = [
                            'id' => $membership->id,
                            'name' => 'Member ' . $membership->name,
                        ];
                    }
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
            'message' => 'Matriks ketersediaan harian untuk hari ini berhasil ditampilkan',
            'target_date' => $targetDate,
            'data' => $dataByField,
        ], 200);
    }
}
