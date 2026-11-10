<?php

use App\Exceptions\ApiProblemException;
use App\Support\ProblemDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Endpoint SOAP dipanggil mesin eksternal, bukan form browser (tanpa token @csrf)
        $middleware->validateCsrfTokens(except: [
            'soap/*',
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\CekPeran::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 1. Semua rute /api/* selalu dirender sebagai JSON (bukan redirect 302 / halaman HTML).
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // 2. 422 Unprocessable Content (validasi input)
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ProblemDetails::make(
                    'Unprocessable Content',
                    'Satu atau lebih atribut input yang dikirim tidak memenuhi aturan validasi.',
                    422,
                    'validation-failed',
                    $e->errors(),
                    $request->getRequestUri(),
                );
            }
        });

        // 3. 401 Unauthorized (token tidak ada / salah / kedaluwarsa / sudah dicabut)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ProblemDetails::make(
                    'Unauthorized',
                    'Akses ditolak. Sertakan Bearer Token yang valid pada header Authorization.',
                    401,
                    'unauthenticated',
                    [],
                    $request->getRequestUri(),
                );
            }
        });

        // 4. 403 Forbidden (token valid, tetapi peran tidak berhak)
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ProblemDetails::make(
                    'Forbidden',
                    $e->getMessage() ?: 'Anda tidak memiliki hak akses terhadap resource ini.',
                    403,
                    'forbidden',
                    [],
                    $request->getRequestUri(),
                );
            }
        });

        // 5. 404 Not Found (model tidak ada / endpoint salah ketik)
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ProblemDetails::make(
                    'Not Found',
                    'Resource yang diminta tidak ditemukan.',
                    404,
                    'resource-not-found',
                    [],
                    $request->getRequestUri(),
                );
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ProblemDetails::make(
                    'Not Found',
                    'Alamat URI endpoint atau resource yang diminta tidak ditemukan.',
                    404,
                    'endpoint-not-found',
                    [],
                    $request->getRequestUri(),
                );
            }
        });

        // 6. 405 Method Not Allowed
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ProblemDetails::make(
                    'Method Not Allowed',
                    'Metode HTTP yang dipakai tidak diizinkan untuk endpoint ini.',
                    405,
                    'method-not-allowed',
                    [],
                    $request->getRequestUri(),
                )->withHeaders($e->getHeaders());
            }
        });

        // 7. Galat HTTP lain (403 dari middleware peran, 429 throttle, dst.)
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();

                return ProblemDetails::make(
                    \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'HTTP Error',
                    $e->getMessage() ?: 'Permintaan tidak dapat diproses.',
                    $status,
                    'http-error-'.$status,
                    [],
                    $request->getRequestUri(),
                )->withHeaders($e->getHeaders());
            }
        });

        // 8. 500 Internal Server Error (bug kode / koneksi DB putus)
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! $e instanceof ApiProblemException) {
                return ProblemDetails::make(
                    'Internal Server Error',
                    config('app.debug')
                        ? $e->getMessage()
                        : 'Terjadi kesalahan pada server. Silakan coba lagi beberapa saat.',
                    500,
                    'server-error',
                    [],
                    $request->getRequestUri(),
                );
            }
        });
    })->create();