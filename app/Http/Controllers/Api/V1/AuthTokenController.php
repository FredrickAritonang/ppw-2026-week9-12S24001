<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthTokenController extends Controller
{
    use ApiResponse;

    /** Peran yang boleh menerbitkan token API (sama dengan peran pengelola di aplikasi web). */
    private const PERAN_API = ['pustakawan', 'staf_akademik'];

    private const TOKEN_TTL_JAM = 8;

    /**
     * POST /api/v1/auth/login
     * Verifikasi kredensial lalu terbitkan Stateless Bearer Token (Sanctum).
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return $this->problemResponse(
                title: 'Invalid Credentials',
                detail: 'Kombinasi email atau password yang diberikan tidak cocok.',
                status: 401,
                errorSlug: 'invalid-credentials'
            );
        }

        if (! in_array($user->role, self::PERAN_API, true)) {
            return $this->problemResponse(
                title: 'Forbidden',
                detail: 'Akun ini tidak memiliki hak mengakses API pengelolaan perpustakaan.',
                status: 403,
                errorSlug: 'role-not-allowed'
            );
        }

        $deviceName = $validated['device_name'] ?? 'postman-client';
        $expiresAt = now()->addHours(self::TOKEN_TTL_JAM);
        $token = $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken;

        return $this->successResponse(
            data: [
                'token_type' => 'Bearer',
                'access_token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => $this->userPayload($user),
            ],
            message: 'Otentikasi API berhasil. Lampirkan access_token pada header Authorization: Bearer <token>.'
        );
    }

    /**
     * GET /api/v1/auth/me (Bearer Token)
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: $this->userPayload($request->user()),
            message: 'Profil pemilik token aktif berhasil diambil.'
        );
    }

    /**
     * POST /api/v1/auth/logout (Bearer Token): mencabut (revoke) token yang sedang dipakai.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(
            data: null,
            message: 'Bearer Token berhasil dicabut (revoked). Sesi API diakhiri.'
        );
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
}