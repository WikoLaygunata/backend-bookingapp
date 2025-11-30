<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $page = $request->page ?? 1;
        $per_page = $request->per_page ?? 10;
        $search = $request->search;

        $customers = Customer::when($search, function ($query) use ($search) {
            return $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('phone', 'like', '%' . $search . '%');
        })->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data pelanggan',
            'data' => $customers
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        $customer = Customer::create($request->all());

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan data pelanggan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menambahkan data pelanggan',
            'data' => $customer
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $customer
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        if (!$customer->update($request->all())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data pelanggan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil mengubah data pelanggan',
            'data' => $customer
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        // Pengecekan relasi ke Booking (opsional, tergantung logic bisnis)
        if ($customer->bookings()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus pelanggan yang masih memiliki riwayat booking'
            ], 400);
        }

        if (!$customer->delete()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus data pelanggan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus data pelanggan'
        ], 200);
    }
}
