<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Booking;

class BookingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $page = $request->page ?? 1;
        $per_page = $request->per_page ?? 10;
        $search = $request->search;
        $customer_id = $request->customer_id;

        $bookings = Booking::with(['customer', 'schedule.field']) // Load relasi Customer dan Schedule beserta Field
            ->when($customer_id, function ($query) use ($customer_id) {
                return $query->where('customer_id', $customer_id);
            })
            ->when($search, function ($query) use ($search) {
                return $query->where('status', 'like', '%' . $search . '%')
                             ->orWhereHas('customer', function ($q) use ($search) {
                                 $q->where('name', 'like', '%' . $search . '%');
                             });
            })
            ->orderBy('booking_date', 'desc')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data pemesanan',
            'data' => $bookings
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'schedule_id' => 'required|exists:schedules,id',
            'booking_date' => 'required|date|after_or_equal:today',
            'total_price' => 'required|numeric|min:0',
            'status' => ['required', Rule::in(['dp', 'lunas'])],
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        // Contoh validasi tambahan: memastikan jadwal pada tanggal tersebut belum di-booking
        $is_booked = Booking::where('schedule_id', $request->schedule_id)
            ->where('booking_date', $request->booking_date)
            ->where('status', '!=', 'lunas') // Asumsikan status 'cancelled' tidak menghalangi booking baru
            ->exists();
            
        if ($is_booked) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jadwal pada tanggal ini sudah dipesan.'
            ], 400);
        }


        $booking = Booking::create($request->all());

        if (!$booking) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan data pemesanan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menambahkan data pemesanan',
            'data' => $booking->load(['customer', 'schedule.field'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Booking $booking)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $booking->load(['customer', 'schedule.field'])
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Booking $booking)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'schedule_id' => 'required|exists:schedules,id',
            'booking_date' => 'required|date|after_or_equal:today',
            'total_price' => 'required|numeric|min:0',
            'status' => ['required', Rule::in(['dp', 'lunas'])],
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }
        
        // Pengecekan duplikasi saat update (mengabaikan booking ini sendiri)
        $is_booked = Booking::where('schedule_id', $request->schedule_id)
            ->where('booking_date', $request->booking_date)
            ->where('status', '!=', 'lunas')
            ->where('id', '!=', $booking->id)
            ->exists();

        if ($is_booked) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jadwal pada tanggal ini sudah dipesan oleh pihak lain.'
            ], 400);
        }

        if (!$booking->update($request->all())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data pemesanan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil mengubah data pemesanan',
            'data' => $booking->load(['customer', 'schedule.field'])
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Booking $booking)
    {
        if (!$booking->delete()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus data pemesanan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus data pemesanan'
        ], 200);
    }
}
