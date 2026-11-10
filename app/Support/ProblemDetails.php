<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Pembentuk respons galat standar IETF RFC 7807 (Problem Details for HTTP APIs).
 * Dipakai bersama oleh trait ApiResponse, ApiProblemException, dan exception handler global.
 */
class ProblemDetails
{
    public static function make(
        string $title,
        string $detail,
        int $status,
        string $slug = 'general-error',
        array $errors = [],
        ?string $instance = null,
    ): JsonResponse {
        $problem = [
            'type' => url("/docs/errors/{$slug}"),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance ?? request()->getRequestUri(),
        ];

        if (! empty($errors)) {
            $problem['errors'] = $errors;
        }

        return response()
            ->json($problem, $status)
            ->header('Content-Type', 'application/problem+json');
    }
}