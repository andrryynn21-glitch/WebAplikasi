<?php

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use App\Services\VoiceWarningManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Envelope response konsisten:
     * {
     *   "message": "...",
     *   "data": {...},
     *   "meta": {...},
     *   "error": {"code": "...", "details": {...}}
     * }
     *
     * Voice warning hanya disertakan jika ada kejadian yang memenuhi aturan.
     */
    protected function respond(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = [],
        ?SecurityEvent $securityEvent = null,
    ): JsonResponse {
        $payload = [
            'message' => $message,
            'data' => $data,
        ];

        $voice = app(VoiceWarningManager::class)->forEvent($securityEvent);

        if ($voice['play']) {
            $meta['voice_warning'] = $voice;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Error response seragam tanpa stack trace atau detail internal.
     */
    protected function fail(
        string $message,
        string $code,
        int $status = 400,
        ?array $details = null,
        array $meta = [],
        ?SecurityEvent $securityEvent = null,
    ): JsonResponse {
        $payload = [
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ];

        $voice = app(VoiceWarningManager::class)->forEvent($securityEvent);

        if ($voice['play']) {
            $meta['voice_warning'] = $voice;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Pagination meta yang dipakai semua endpoint list.
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min((int) $request->integer('per_page', $default), $max));
    }
}
