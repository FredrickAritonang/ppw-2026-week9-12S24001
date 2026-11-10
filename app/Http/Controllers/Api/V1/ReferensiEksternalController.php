<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Models\Buku;
use App\Traits\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SIPUS-Del bertindak sebagai REST SERVICE CONSUMER yang memanggil OpenLibrary API
 * untuk memperkaya metadata buku berdasarkan ISBN.
 */
class ReferensiEksternalController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/bukus/{id}/referensi-eksternal  (publik)
     */
    public function show(string $id): JsonResponse
    {
        $buku = Buku::find($id);

        if (! $buku) {
            throw new ApiProblemException(
                'Resource Not Found',
                "Buku dengan ID '{$id}' tidak ditemukan.",
                404,
                'book-not-found'
            );
        }

        $isbn = preg_replace('/[^0-9Xx]/', '', $buku->isbn);
        $baseUrl = rtrim(config('services.openlibrary.base_url'), '/');

        try {
            $response = Http::acceptJson()
                ->withUserAgent('SIPUS-Del/1.0 (Institut Teknologi Del)')
                ->timeout(8)
                ->get("{$baseUrl}/api/books", [
                    'bibkeys' => "ISBN:{$isbn}",
                    'format' => 'json',
                    'jscmd' => 'data',
                ]);
        } catch (ConnectionException $e) {
            // Timeout / DNS / koneksi putus -> 504 Gateway Timeout
            Log::warning('[OpenLibrary] koneksi gagal: '.$e->getMessage());

            throw new ApiProblemException(
                'Gateway Timeout',
                'Layanan OpenLibrary tidak merespons dalam 8 detik. Silakan coba lagi nanti.',
                504,
                'external-timeout'
            );
        }

        // Layanan eksternal merespons error (5xx / 4xx) -> 502 Bad Gateway
        if ($response->failed()) {
            throw new ApiProblemException(
                'Bad Gateway',
                "Layanan OpenLibrary mengembalikan status {$response->status()}.",
                502,
                'external-service-error'
            );
        }

        $payload = $response->json();
        $data = is_array($payload) ? ($payload["ISBN:{$isbn}"] ?? null) : null;

        // Buku tidak terdaftar di OpenLibrary -> 404 (bukan 500)
        if (empty($data)) {
            throw new ApiProblemException(
                'External Resource Not Found',
                "ISBN {$buku->isbn} tidak ditemukan pada katalog OpenLibrary.",
                404,
                'external-book-not-found'
            );
        }

        return $this->successResponse(
            data: [
                'buku_id' => $buku->id,
                'isbn' => $buku->isbn,
                'sumber' => 'OpenLibrary',
                'judul_eksternal' => $data['title'] ?? null,
                'penulis_eksternal' => collect($data['authors'] ?? [])->pluck('name')->values(),
                'penerbit_eksternal' => collect($data['publishers'] ?? [])->pluck('name')->values(),
                'jumlah_halaman' => $data['number_of_pages'] ?? null,
                'tanggal_terbit' => $data['publish_date'] ?? null,
                'subjek' => collect($data['subjects'] ?? [])->pluck('name')->take(5)->values(),
                'url_pratinjau' => $data['url'] ?? null,
                'url_sampul' => $data['cover']['medium'] ?? null,
            ],
            message: 'Metadata eksternal buku berhasil diambil dari OpenLibrary API.'
        );
    }
}