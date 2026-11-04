<?php

use App\Enums\SecurityEventCategory;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureClassAccess;
use App\Http\Middleware\EnsurePermission;
use App\Services\SecurityEventService;
use App\Services\VoiceWarningManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(null);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'permission' => EnsurePermission::class,
            'class.access' => EnsureClassAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $isApi($request));
        $exceptions->report(function (QueryException $e): bool {
            Log::error('Operasi database gagal.', [
                'exception' => $e::class,
                'sql_state' => $e->errorInfo[0] ?? null,
            ]);

            return false;
        });

        // Semua error API memakai envelope yang sama dan tidak pernah
        // menampilkan stack trace atau detail internal.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Data yang dikirim tidak valid.',
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Autentikasi diperlukan.',
                'error' => ['code' => 'UNAUTHENTICATED'],
            ], 401);
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $user = $request->user();

            $event = $user !== null
                ? app(SecurityEventService::class)->recordDenial(
                    SecurityEventCategory::AccessDenied,
                    $user,
                    $request,
                    null,
                    'Otorisasi ditolak oleh policy/middleware.',
                )
                : null;

            $payload = [
                'message' => 'Anda tidak berwenang mengakses sumber daya ini.',
                'error' => ['code' => 'FORBIDDEN'],
            ];

            $voice = app(VoiceWarningManager::class)->forEvent($event);

            if ($voice['play']) {
                $payload['meta'] = ['voice_warning' => $voice];
            }

            return response()->json($payload, 403);
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            // Pesan generik: tidak mengungkap apakah resource ada tetapi
            // di luar wewenang (mencegah enumerasi/IDOR probing).
            return response()->json([
                'message' => 'Sumber daya tidak ditemukan.',
                'error' => ['code' => 'NOT_FOUND'],
            ], 404);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Terlalu banyak permintaan. Coba lagi nanti.',
                'error' => ['code' => 'RATE_LIMITED'],
            ], 429, ['Retry-After' => $e->getHeaders()['Retry-After'] ?? 60]);
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'message' => 'Permintaan tidak dapat diproses.',
                    'error' => ['code' => 'HTTP_ERROR'],
                ], $e->getStatusCode(), $e->getHeaders());
            }

            return response()->json([
                'message' => 'Terjadi kesalahan pada server.',
                'error' => ['code' => 'SERVER_ERROR'],
            ], 500);
        });
    })->create();
