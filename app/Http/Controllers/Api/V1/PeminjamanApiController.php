<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PeminjamanResource;
use App\Models\Buku;
use App\Models\Peminjaman;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeminjamanApiController extends Controller
{
    use ApiResponse;

    private const DURASI_DEFAULT_HARI = 7;

    /**
     * GET /api/v1/peminjamans  (Bearer Token)
     * Filter ?status=dipinjam|dikembalikan dan ?nim=...
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:dipinjam,dikembalikan',
            'nim' => 'nullable|string|max:20',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $paginator = Peminjaman::query()
            ->with('buku:id,isbn,judul')   // eager loading, bebas N+1
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('nim'), fn ($q, $nim) => $q->where('nim_peminjam', $nim))
            ->latest('tanggal_pinjam')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return $this->successResponse(
            data: PeminjamanResource::collection($paginator->items()),
            message: 'Daftar transaksi peminjaman berhasil diambil.',
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total_items' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
                'links' => [
                    'first' => $paginator->url(1),
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                    'last' => $paginator->url($paginator->lastPage()),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/peminjamans/{id}  (Bearer Token) : target header Location dari POST.
     */
    public function show(string $id): JsonResponse
    {
        $peminjaman = Peminjaman::with('buku:id,isbn,judul')->find($id)
            ?? throw $this->peminjamanTidakDitemukan($id);

        return $this->successResponse(
            data: new PeminjamanResource($peminjaman),
            message: 'Detail peminjaman berhasil ditemukan.'
        );
    }

    /**
     * POST /api/v1/peminjamans  (Bearer Token)
     * Transaksi ACID + Pessimistic Locking pada baris buku.
     *  - 422 : stok habis
     *  - 409 : NIM yang sama masih meminjam buku yang sama
     *  - 201 : sukses + header Location
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'buku_id' => 'required|integer',
            'nim_peminjam' => 'required|string|max:20',
            'nama_peminjam' => 'required|string|max:150',
            'durasi_hari' => 'nullable|integer|min:1|max:60',
        ]);

        $nim = trim($validated['nim_peminjam']);
        $durasi = (int) ($validated['durasi_hari'] ?? self::DURASI_DEFAULT_HARI);

        // Setiap `throw` di dalam closure otomatis membatalkan (rollback) transaksi.
        $peminjaman = DB::transaction(function () use ($validated, $nim, $durasi) {
            // 1. Kunci baris buku (SELECT ... FOR UPDATE) agar stok aman dari race condition
            $buku = Buku::where('id', $validated['buku_id'])->lockForUpdate()->first();

            if (! $buku) {
                throw new ApiProblemException(
                    'Resource Not Found',
                    "Buku dengan ID '{$validated['buku_id']}' tidak ditemukan.",
                    404,
                    'book-not-found'
                );
            }

            // 2. Stok habis -> 422 Unprocessable Content
            if ($buku->stok < 1) {
                throw new ApiProblemException(
                    'Stok Buku Habis',
                    "Stok buku sedang habis. Peminjaman \"{$buku->judul}\" ditolak dan dibatalkan.",
                    422,
                    'stock-exhausted'
                );
            }

            // 3. NIM yang sama masih meminjam buku yang sama -> 409 Conflict
            $masihDipinjam = Peminjaman::aktif()
                ->where('nim_peminjam', $nim)
                ->where('buku_id', $buku->id)
                ->exists();

            if ($masihDipinjam) {
                throw new ApiProblemException(
                    'Duplicate Loan Conflict',
                    "NIM '{$nim}' masih meminjam buku \"{$buku->judul}\" dan belum mengembalikannya.",
                    409,
                    'duplicate-loan'
                );
            }

            // 4. Kurangi stok (-1) dan catat peminjaman secara atomik
            $buku->decrement('stok');

            $userId = User::where('nomor_induk', $nim)->orWhere('username', $nim)->value('id');

            $p = Peminjaman::create([
                'kode_pinjam' => Peminjaman::buatKode(),
                'buku_id' => $buku->id,
                'user_id' => $userId,
                'nim_peminjam' => $nim,
                'nama_peminjam' => trim($validated['nama_peminjam']),
                'tanggal_pinjam' => today(),
                'tanggal_jatuh_tempo' => today()->addDays($durasi),
                'status' => 'dipinjam',
            ]);
            $p->setRelation('buku', $buku);

            return $p;
        }, 3);

        return $this->createdResponse(
            data: new PeminjamanResource($peminjaman),
            locationUrl: url("/api/v1/peminjamans/{$peminjaman->id}"),
            message: "Peminjaman \"{$peminjaman->buku->judul}\" berhasil dicatat. Sisa stok kini: {$peminjaman->buku->stok}."
        );
    }

    /**
     * PATCH /api/v1/peminjamans/{id}/kembalikan  (Bearer Token)
     * Transaksi ACID: status -> dikembalikan, tanggal_kembali diisi, stok buku +1.
     */
    public function kembalikan(string $id): JsonResponse
    {
        $peminjaman = DB::transaction(function () use ($id) {
            $p = Peminjaman::where('id', $id)->lockForUpdate()->first()
                ?? throw $this->peminjamanTidakDitemukan($id);

            if ($p->status === 'dikembalikan') {
                throw new ApiProblemException(
                    'Already Returned Conflict',
                    "Peminjaman {$p->kode_pinjam} sudah berstatus 'dikembalikan' sebelumnya.",
                    409,
                    'already-returned'
                );
            }

            $buku = Buku::where('id', $p->buku_id)->lockForUpdate()->firstOrFail();

            $p->update([
                'status' => 'dikembalikan',
                'tanggal_kembali' => today(),
            ]);
            $buku->increment('stok');

            $p->setRelation('buku', $buku);

            return $p;
        }, 3);

        return $this->successResponse(
            data: new PeminjamanResource($peminjaman),
            message: "Buku \"{$peminjaman->buku->judul}\" berhasil dikembalikan. Stok kini: {$peminjaman->buku->stok}."
        );
    }

    private function peminjamanTidakDitemukan(string $id): ApiProblemException
    {
        return new ApiProblemException(
            'Resource Not Found',
            "Data peminjaman dengan ID '{$id}' tidak ditemukan.",
            404,
            'loan-not-found'
        );
    }
}