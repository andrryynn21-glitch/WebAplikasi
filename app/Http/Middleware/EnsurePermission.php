<?php

namespace App\Http\Middleware;

use App\Enums\SecurityEventCategory;
use App\Services\SecurityEventService;
use App\Services\VoiceWarningManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemeriksaan permission di level route. Policy tetap menjadi pemeriksaan
 * utama untuk resource individual; middleware ini menolak lebih awal
 * dan mencatat security event untuk akses tidak sah.
 */
class EnsurePermission
{
    public function __construct(
        private readonly SecurityEventService $security,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Autentikasi diperlukan.',
                'error' => ['code' => 'UNAUTHENTICATED'],
            ], 401);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        $event = $this->security->recordDenial(
            SecurityEventCategory::AccessDenied,
            $user,
            $request,
            null,
            'Permission tidak dimiliki: '.implode('|', $permissions),
        );

        return response()->json([
            'message' => 'Anda tidak memiliki wewenang untuk tindakan ini.',
            'error' => ['code' => 'PERMISSION_DENIED'],
            'meta' => [
                'voice_warning' => app(VoiceWarningManager::class)->forEvent($event),
            ],
        ], 403);
    }
}
