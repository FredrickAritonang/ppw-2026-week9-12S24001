<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BukuResource;
use App\Models\Buku;
use App\Models\Peminjaman;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BukuApiController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/bukus  (publik)
     * Search, filter kategori, filter ketersediaan, sorting, dan paginasi. Bebas N+1 Query.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'kategori_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        // Whitelist kolom sort (mencegah injeksi pada ORDER BY). Awalan "-" berarti descending.
        $allowedSorts = ['judul', 'penulis', 'isbn', 'tahun_terbit', 'stok', 'created_at'];
        $sortParam = (string) $request->query('sort', 'judul');
        $sortDir = str_starts_with($sortParam, '-') ? 'desc' : strtolower((string) $request->query('dir', 'asc'));
        $sortBy = ltrim($sortParam, '-');
        $sortBy = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'judul';
        $sortDir = $sortDir === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $paginator = Buku::query()
            ->with('kategori')              // eager loading: 1 query tambahan untuk SEMUA kategori
            ->withCount('peminjamans')      // 1 subquery COUNT, bukan N query
            ->when($request->query('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('judul', 'like', "%{$search}%")
                        ->orWhere('penulis', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%");
                });
            })
            ->when($request->query('kategori_id'), fn ($q, $id) => $q->where('kategori_id', (int) $id))
            ->when($request->boolean('tersedia'), fn ($q) => $q->where('stok', '>', 0))
            ->orderBy($sortBy, $sortDir)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return $this->successResponse(
            data: BukuResource::collection($paginator->items()),
            message: 'Daftar koleksi buku berhasil diambil.',
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
     * GET /api/v1/bukus/{identifier}  (publik) : berdasarkan ID atau ISBN
     */
    public function show(string $identifier): JsonResponse
    {
        $buku = Buku::query()
            ->with([
                'kategori',
                'peminjamans' => fn ($q) => $q->latest('tanggal_pinjam')->latest('id'),
            ])
            ->withCount('peminjamans')
            ->where(function ($q) use ($identifier) {
                if (ctype_digit($identifier)) {
                    $q->where('id', (int) $identifier)->orWhere('isbn', $identifier);
                } else {
                    $q->where('isbn', $identifier);
                }
            })
            ->first();

        if (! $buku) {
            throw $this->bukuTidakDitemukan($identifier);
        }

        return $this->successResponse(
            data: new BukuResource($buku),
            message: 'Detail buku berhasil ditemukan.'
        );
    }

    /**
     * POST /api/v1/bukus  (Bearer Token) -> 201 Created + header Location
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $buku = Buku::create($validated);
        $buku->load('kategori')->loadCount('peminjamans');

        return $this->createdResponse(
            data: new BukuResource($buku),
            locationUrl: url("/api/v1/bukus/{$buku->id}"),
            message: 'Buku baru berhasil ditambahkan.'
        );
    }

    /**
     * PUT / PATCH /api/v1/bukus/{id}  (Bearer Token)
     *  - PUT   : Full Replacement. Seluruh atribut wajib dikirim; atribut nullable yang tidak dikirim di-reset ke null.
     *  - PATCH : Partial Update. Hanya atribut yang dikirim yang divalidasi dan diubah.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $buku = Buku::find($id) ?? throw $this->bukuTidakDitemukan($id);
        $isPut = $request->isMethod('PUT');

        if ($isPut) {
            $validated = $request->validate($this->rules($buku));
            // Semantik PUT murni: field opsional yang tidak dikirim dianggap dikosongkan.
            $validated['sinopsis'] = $request->input('sinopsis');
            $validated['jumlah_halaman'] = $request->input('jumlah_halaman');
        } else {
            $validated = $request->validate($this->rules($buku, partial: true));

            if (empty($validated)) {
                throw new ApiProblemException(
                    'Bad Request',
                    'Permintaan PATCH wajib menyertakan minimal satu atribut yang ingin diperbarui.',
                    400,
                    'empty-patch-payload'
                );
            }
        }

        $buku->update($validated);
        $buku->load('kategori')->loadCount('peminjamans');

        return $this->successResponse(
            data: new BukuResource($buku),
            message: $isPut
                ? 'Seluruh representasi buku berhasil diganti (PUT).'
                : 'Atribut buku berhasil diperbarui sebagian (PATCH).'
        );
    }

    /**
     * DELETE /api/v1/bukus/{id}  (Bearer Token)
     * 409 Conflict bila masih ada peminjaman aktif, 204 No Content bila berhasil.
     */
    public function destroy(string $id): Response
    {
        DB::transaction(function () use ($id) {
            $buku = Buku::where('id', $id)->lockForUpdate()->first()
                ?? throw $this->bukuTidakDitemukan($id);

            $aktif = Peminjaman::aktif()->where('buku_id', $buku->id)->count();

            if ($aktif > 0) {
                throw new ApiProblemException(
                    'Conflict - Book Has Active Loans',
                    "Buku '{$buku->judul}' tidak dapat dihapus karena masih dipinjam oleh {$aktif} peminjam.",
                    409,
                    'book-has-active-loans'
                );
            }

            $buku->delete();
        });

        return $this->noContentResponse();
    }

    private function rules(?Buku $buku = null, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes|' : '';

        return [
            'isbn' => [
                $partial ? 'sometimes' : 'required', 'string', 'max:20',
                Rule::unique('bukus', 'isbn')->ignore($buku?->id),
            ],
            'judul' => $sometimes.'required|string|max:200',
            'penulis' => $sometimes.'required|string|max:150',
            'penerbit' => $sometimes.'required|string|max:150',
            'tahun_terbit' => $sometimes.'required|integer|between:1900,'.date('Y'),
            'kategori_id' => $sometimes.'required|integer|exists:kategoris,id',
            'stok' => $sometimes.'required|integer|min:0',
            'sinopsis' => ($partial ? 'sometimes|' : '').'nullable|string',
            'jumlah_halaman' => ($partial ? 'sometimes|' : '').'nullable|integer|min:1|max:65535',
        ];
    }

    private function bukuTidakDitemukan(string $identifier): ApiProblemException
    {
        return new ApiProblemException(
            'Resource Not Found',
            "Buku dengan pengenal '{$identifier}' tidak ditemukan di basis data.",
            404,
            'book-not-found'
        );
    }
}