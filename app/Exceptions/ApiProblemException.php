<?php

namespace App\Exceptions;

use App\Support\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Galat bisnis yang langsung dirender sebagai application/problem+json.
 * Jika dilempar di dalam DB::transaction(), transaksi otomatis di-rollback.
 */
class ApiProblemException extends RuntimeException
{
    public function __construct(
        public readonly string $title,
        string $detail,
        public readonly int $status,
        public readonly string $slug = 'general-error',
        public readonly array $errors = [],
    ) {
        parent::__construct($detail);
    }

    public function render(Request $request): JsonResponse
    {
        return ProblemDetails::make($this->title, $this->getMessage(), $this->status, $this->slug, $this->errors);
    }

    /** Galat bisnis yang diharapkan tidak perlu masuk laravel.log. */
    public function report(): bool
    {
        return false;
    }
}