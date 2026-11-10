<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BebasPustakaController;
use App\Http\Controllers\BukuController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DendaController;
use App\Http\Controllers\KatalogController;
use App\Http\Controllers\KategoriController;
use App\Http\Controllers\NotifikasiController;
use App\Http\Controllers\PenggunaController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\SirkulasiController;
use App\Http\Controllers\Soap\LibrarySoapController;
use App\Http\Controllers\UsulanController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.proses');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/profil', [ProfilController::class, 'index'])->name('profil.index');

    Route::get('/katalog', [KatalogController::class, 'index'])->name('katalog.index');
    Route::get('/katalog/{buku}', [KatalogController::class, 'show'])->name('katalog.show');
    Route::get('/baca/{buku}', [KatalogController::class, 'baca'])->name('katalog.baca');

    Route::get('/notifikasi', [NotifikasiController::class, 'index'])->name('notifikasi.index');
    Route::post('/notifikasi/baca-semua', [NotifikasiController::class, 'bacaSemua'])->name('notifikasi.baca-semua');
    Route::get('/notifikasi/{notifikasi}', [NotifikasiController::class, 'buka'])->name('notifikasi.buka');

    Route::get('/riwayat', [SirkulasiController::class, 'riwayat'])->name('riwayat.index');

    Route::middleware('role:mahasiswa,dosen,pustakawan')->group(function () {
        Route::get('/sirkulasi', [SirkulasiController::class, 'index'])->name('sirkulasi.index');
        Route::post('/sirkulasi/{id}/kembali', [SirkulasiController::class, 'kembali'])->whereNumber('id')->name('sirkulasi.kembali');
        Route::post('/sirkulasi/{peminjaman}/perpanjang', [SirkulasiController::class, 'perpanjang'])->name('sirkulasi.perpanjang');
        Route::get('/usulan', [UsulanController::class, 'index'])->name('usulan.index');
        Route::get('/denda', [DendaController::class, 'index'])->name('denda.index');
        Route::post('/denda/{peminjaman}/bayar', [DendaController::class, 'bayar'])->name('denda.bayar');
    });

    Route::middleware('role:mahasiswa,dosen')->group(function () {
        Route::post('/sirkulasi/pinjam/{id}', [SirkulasiController::class, 'pinjam'])->whereNumber('id')->name('sirkulasi.pinjam');
        Route::post('/usulan', [UsulanController::class, 'store'])->name('usulan.store');
    });

    Route::middleware('role:pustakawan')->group(function () {
        Route::post('/sirkulasi/catat', [SirkulasiController::class, 'catat'])->name('sirkulasi.catat');
        Route::post('/usulan/{usulan}/proses', [UsulanController::class, 'proses'])->name('usulan.proses');
        Route::post('/bebas-pustaka/{bebasPustaka}/proses', [BebasPustakaController::class, 'proses'])->name('bebas-pustaka.proses');
    });

    Route::middleware('role:mahasiswa,pustakawan')->group(function () {
        Route::get('/bebas-pustaka', [BebasPustakaController::class, 'index'])->name('bebas-pustaka.index');
        Route::get('/bebas-pustaka/{bebasPustaka}/surat', [BebasPustakaController::class, 'surat'])->name('bebas-pustaka.surat');
    });
    Route::post('/bebas-pustaka', [BebasPustakaController::class, 'ajukan'])->middleware('role:mahasiswa')->name('bebas-pustaka.ajukan');

    Route::get('/pengguna', [PenggunaController::class, 'index'])->middleware('role:staf_akademik')->name('pengguna.index');

    Route::resource('buku', BukuController::class)->middleware('role:pustakawan,staf_akademik');
    Route::resource('kategori', KategoriController::class)->middleware('role:pustakawan,staf_akademik');
});

// Layanan SOAP legacy (Minggu 9): publik, dikecualikan dari CSRF di bootstrap/app.php
Route::get('/soap/sipus.wsdl', [LibrarySoapController::class, 'wsdl']);
Route::post('/soap/sipus', [LibrarySoapController::class, 'handle']);