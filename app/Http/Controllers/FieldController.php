<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Field;

class FieldController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $page = $request->page ?? 1;
        $per_page = $request->per_page ?? 10;
        $search = $request->search;

        $fields = Field::when($search, function ($query) use ($search) {
            return $query->where('name', 'like', '%' . $search . '%');
        })->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data lapangan',
            'data' => $fields
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('fields')->where(fn($query) => $query->whereNull('deleted_at'))],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        $field = Field::create($request->all());

        if (!$field) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan data lapangan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menambahkan data lapangan',
            'data' => $field
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Field $field)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $field
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Field $field)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('fields')->ignore($field->id)->where(fn($query) => $query->whereNull('deleted_at'))],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        if (!$field->update($request->all())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data lapangan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil mengubah data lapangan',
            'data' => $field
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Field $field)
    {
        // Pengecekan relasi ke Package atau Schedule sebelum dihapus secara permanen (opsional)
        // Soft delete umumnya menangani ini dengan baik tanpa menghapus data terkait

        if (!$field->delete()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus data lapangan'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus data lapangan'
        ], 200);
    }
}
