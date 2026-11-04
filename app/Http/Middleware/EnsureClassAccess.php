<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fitur kelas (pengaduan, aspirasi, komentar, upvote) hanya untuk:
 * - akun aktif,
 * - identitas terverifikasi,
 * - keanggotaan kelas aktif.
 *
 * Kode undangan TIDAK membuka middleware ini; hanya verifikasi identitas
 * dan persetujuan keanggotaan yang membukanya.
 */
class EnsureClassAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Autentikasi diperlukan.',
                'error' => ['code' => 'UNAUTHENTICATED'],
            ], 401);
        }

        // Staff actions retain their resource policy checks, without a student membership.
        if ($user->is_active && $user->hasRole(UserRole::LecturerCoordinator)
            && $request->routeIs('aspirations.*', 'reports.updates.store') && $user->accessibleClassIds() !== []) {
            return $next($request);
        }

        if (! $user->isVerified()) {
            return response()->json([
                'message' => 'Identitas belum terverifikasi. Fitur kelas belum dapat digunakan.',
                'error' => [
                    'code' => 'IDENTITY_NOT_VERIFIED',
                    'identity_status' => $user->identity_status?->value,
                ],
            ], 403);
        }

        if (! $user->activeMembership()->exists()) {
            return response()->json([
                'message' => 'Keanggotaan kelas belum aktif.',
                'error' => ['code' => 'MEMBERSHIP_NOT_ACTIVE'],
            ], 403);
        }

        return $next($request);
    }
}
