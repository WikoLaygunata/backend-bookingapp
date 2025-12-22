<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Package;

class PackageController extends Controller
{
    public function all()
    {
        $packages = Package::with('field')->select(['id', 'name', 'price', 'description'])->get();
        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data paket',
            'data' => $packages
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

        $packages = Package::with('field') // Load relasi Field
            ->when($field_id, function ($query) use ($field_id) {
                return $query->where('field_id', $field_id);
            })
            ->when($search, function ($query) use ($search) {
                return $query->where('name', 'like', '%' . $search . '%');
            })->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data paket',
            'data' => $packages
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'field_id' => 'required|exists:fields,id',
            'name' => ['required', 'string', 'max:255', Rule::unique('packages')->where(fn($query) => $query->where('field_id', $request->field_id)->whereNull('deleted_at'))],
            'duration_slots' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        $package = Package::create($request->all());

        if (!$package) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan data paket'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menambahkan data paket',
            'data' => $package->load('field')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Package $package)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $package->load('field')
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Package $package)
    {
        $validator = Validator::make($request->all(), [
            'field_id' => 'required|exists:fields,id',
            'name' => ['required', 'string', 'max:255', Rule::unique('packages')->ignore($package->id)->where(fn($query) => $query->where('field_id', $request->field_id)->whereNull('deleted_at'))],
            'duration_slots' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        if (!$package->update($request->all())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data paket'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil mengubah data paket',
            'data' => $package->load('field')
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Package $package)
    {
        if (!$package->delete()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus data paket'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus data paket'
        ], 200);
    }
}
