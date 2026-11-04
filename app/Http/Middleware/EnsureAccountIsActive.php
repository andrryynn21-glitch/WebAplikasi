<?php

namespace App\Http\Middleware;

use App\Services\SecurityEventService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memblokir akun yang dinonaktifkan dan memastikan token masih sah.
 * Dijalankan sebelum otorisasi lain.
 */
class EnsureAccountIsActive
{
    public function __construct(private readonly SecurityEventService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Akun dinonaktifkan. Hubungi administrator.',
                'error' => ['code' => 'ACCOUNT_DISABLED'],
            ], 403);
        }

        if ($user !== null && ! $request->routeIs('auth.logout', 'auth.logout-all')) {
            $cooldown = $this->security->activeCooldown($user, 'repeated_denials');
            if ($cooldown !== null) {
                $seconds = max(1, (int) ceil(now()->diffInSeconds($cooldown->cooldown_until, false)));

                return response()->json([
                    'message' => 'Akses sementara dibatasi setelah penolakan berulang. Coba lagi nanti.',
                    'error' => ['code' => 'SECURITY_COOLDOWN'],
                    'meta' => ['retry_after' => $seconds],
                ], 429)->header('Retry-After', (string) $seconds);
            }
        }

        return $next($request);
    }
}
