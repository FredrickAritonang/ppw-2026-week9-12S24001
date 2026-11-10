<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\BukuApiController;
use App\Http\Controllers\Api\V1\PeminjamanApiController;
use App\Http\Controllers\Api\V1\ReferensiEksternalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SIPUS-Del REST API v1 (stateless, awalan URL otomatis /api)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'success' => true,
        'message' => 'SIPUS-Del REST API v1 aktif.',
    ]));

    // ---- Publik: login (dibatasi 10x/menit per IP) ----
    Route::post('/auth/login', [AuthTokenController::class, 'login'])->middleware('throttle:10,1');

    // ---- Publik (read-only): katalog buku ----
    Route::get('/bukus', [BukuApiController::class, 'index']);
    Route::get('/bukus/{identifier}', [BukuApiController::class, 'show']);

    // ---- Publik: SIPUS-Del sebagai REST Service Consumer (OpenLibrary API) ----
    Route::get('/bukus/{id}/referensi-eksternal', [ReferensiEksternalController::class, 'show'])->whereNumber('id');

    // ---- Terproteksi Bearer Token (auth:sanctum) ----
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthTokenController::class, 'me']);
        Route::post('/auth/logout', [AuthTokenController::class, 'logout']);

        // Mutasi katalog: hanya pustakawan / staf akademik (403 bila peran lain)
        Route::middleware('role:pustakawan,staf_akademik')->group(function () {
            Route::post('/bukus', [BukuApiController::class, 'store']);
            Route::put('/bukus/{id}', [BukuApiController::class, 'update'])->whereNumber('id');
            Route::patch('/bukus/{id}', [BukuApiController::class, 'update'])->whereNumber('id');
            Route::delete('/bukus/{id}', [BukuApiController::class, 'destroy'])->whereNumber('id');
        });

        // Transaksi sirkulasi: hanya pustakawan (mencatat peminjaman & pengembalian)
        Route::middleware('role:pustakawan')->group(function () {
            Route::get('/peminjamans', [PeminjamanApiController::class, 'index']);
            Route::get('/peminjamans/{id}', [PeminjamanApiController::class, 'show'])->whereNumber('id');
            Route::post('/peminjamans', [PeminjamanApiController::class, 'store']);
            Route::patch('/peminjamans/{id}/kembalikan', [PeminjamanApiController::class, 'kembalikan'])->whereNumber('id');
        });
    });
});