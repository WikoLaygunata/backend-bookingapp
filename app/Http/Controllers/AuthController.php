<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\User;

class AuthController extends Controller
{
    // Login a user
    public function login(Request $request)
    {
        $credentials = $request->only('username', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials'
            ], 401);
        }

        /** @var User $user */
        $user = auth()->user();
        $userData = $user ? $user->toArray() : [];

        $basePermissionName = ($userData['role'] ?? null) === 'staf' ? 'staf' : 'admin';

        // Bentuk array of object, sesuai ekspektasi frontend
        $userData['access_permissions'] = [
            [
                'id'    => 1,                 // bisa disesuaikan/dari DB
                'title' => ucfirst($basePermissionName),
                'name'  => $basePermissionName,
            ],
        ];

        return response()->json([
            'token' => $token,
            'user' => $userData,
        ]);
    }

    // Logout a user
    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json(['message' => 'Successfully logged out']);
    }

    // Get the authenticated user
    public function me()
    {
        /** @var User $user */
        $user = auth()->user();
        $userData = $user ? $user->toArray() : [];

        $basePermissionName = ($userData['role'] ?? null) === 'staf' ? 'staf' : 'admin';

        // Bentuk array of object, sesuai ekspektasi frontend
        $userData['access_permissions'] = [
            [
                'id'    => 1,                 // bisa disesuaikan/dari DB
                'title' => ucfirst($basePermissionName),
                'name'  => $basePermissionName,
            ],
        ];

        return response()->json([
            'data' => $userData,
        ]);
    }
}
