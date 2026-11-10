<?php

namespace App\Traits;

use App\Support\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait ApiResponse
{
    /**
     * Respons sukses standar (default: 200 OK).
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'Permintaan berhasil diproses.',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * 201 Created + header Location.
     */
    protected function createdResponse(
        mixed $data,
        string $locationUrl,
        string $message = 'Resource baru berhasil dibuat.'
    ): JsonResponse {
        return $this->successResponse($data, $message, 201)->header('Location', $locationUrl);
    }

    /**
     * 204 No Content (tanpa body sama sekali).
     */
    protected function noContentResponse(): Response
    {
        return response()->noContent();
    }

    /**
     * Respons galat RFC 7807 (application/problem+json).
     */
    protected function problemResponse(
        string $title,
        string $detail,
        int $status,
        string $errorSlug = 'general-error',
        array $errors = []
    ): JsonResponse {
        return ProblemDetails::make($title, $detail, $status, $errorSlug, $errors);
    }
}