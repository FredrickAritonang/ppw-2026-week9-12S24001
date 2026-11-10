<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representasi JSON publik sebuah Buku (terpisah dari struktur tabel `bukus`).
 */
class BukuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tersedia = $this->stok > 0;

        // Kontrol Hypermedia HATEOAS (Richardson Maturity Model Level 3)
        $links = [
            'self' => [
                'href' => url("/api/v1/bukus/{$this->id}"),
                'method' => 'GET',
            ],
            'referensi_eksternal' => [
                'href' => url("/api/v1/bukus/{$this->id}/referensi-eksternal"),
                'method' => 'GET',
            ],
            'update_full' => [
                'href' => url("/api/v1/bukus/{$this->id}"),
                'method' => 'PUT',
            ],
            'update_partial' => [
                'href' => url("/api/v1/bukus/{$this->id}"),
                'method' => 'PATCH',
            ],
            'delete' => [
                'href' => url("/api/v1/bukus/{$this->id}"),
                'method' => 'DELETE',
            ],
        ];

        // Link 'pinjam_buku' HANYA muncul bila stok masih tersedia (> 0)
        if ($tersedia) {
            $links['pinjam_buku'] = [
                'href' => url('/api/v1/peminjamans'),
                'method' => 'POST',
            ];
        }

        return [
            'id' => $this->id,
            'isbn' => $this->isbn,
            'judul' => $this->judul,
            'penulis' => $this->penulis,
            'penerbit' => $this->penerbit,
            'tahun_terbit' => (int) $this->tahun_terbit,
            'jumlah_halaman' => $this->jumlah_halaman,
            'stok' => (int) $this->stok,
            'status_label' => $tersedia ? 'TERSEDIA' : 'HABIS',
            'sinopsis' => $this->sinopsis,
            'sampul' => $this->sampul,
            // Hanya ada jika controller memanggil withCount('peminjamans')
            'total_peminjaman' => $this->whenCounted('peminjamans'),
            // Hanya ada jika controller memanggil with('kategori') / with('peminjamans')
            'kategori' => $this->whenLoaded('kategori', fn () => new KategoriResource($this->kategori)),
            'riwayat_peminjaman' => $this->whenLoaded(
                'peminjamans',
                fn () => PeminjamanResource::collection($this->peminjamans)
            ),
            'updated_at' => $this->updated_at?->toIso8601String(),
            '_links' => $links,
        ];
    }
}