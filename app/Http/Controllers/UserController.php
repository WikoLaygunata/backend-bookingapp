<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $page = $request->page ?? 1;
        $per_page = $request->per_page ?? 10;
        $search = $request->search;

        $users = User::when($search, function ($query) use ($search) {
            return $query->where('username', 'like', '%' . $search . '%')
                ->orWhere('role', 'like', '%' . $search . '%');
        })->paginate($per_page, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'message' => 'Menampilkan data pengguna',
            'data' => $users
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:255', Rule::unique('users')->where(fn($query) => $query->whereNull('deleted_at'))],
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(['admin', 'manager', 'user'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        $user = User::create([
            'username' => $request->username,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan data pengguna'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menambahkan data pengguna',
            'data' => $user->makeHidden('password')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil ditampilkan',
            'data' => $user->makeHidden('password')
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)->where(fn($query) => $query->whereNull('deleted_at'))],
            'password' => 'nullable|string|min:6',
            'role' => ['required', Rule::in(['admin', 'manager', 'user'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'message' => $validator->errors()->first()
            ], 422);
        }

        $user->username = $request->username;
        $user->role = $request->role;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        if (!$user->save()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengubah data pengguna'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil mengubah data pengguna',
            'data' => $user->makeHidden('password')
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        if (!$user->delete()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus data pengguna'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menghapus data pengguna'
        ], 200);
    }
}
