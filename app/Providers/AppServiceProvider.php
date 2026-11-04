<?php

namespace App\Providers;

use App\Models\AppNotification;
use App\Models\Aspiration;
use App\Models\AspirationComment;
use App\Models\Attachment;
use App\Models\ClassMembership;
use App\Models\ClassRoom;
use App\Models\IdentityVerification;
use App\Models\Report;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Policies\AppNotificationPolicy;
use App\Policies\AspirationCommentPolicy;
use App\Policies\AspirationPolicy;
use App\Policies\AttachmentPolicy;
use App\Policies\ClassRoomPolicy;
use App\Policies\IdentityVerificationPolicy;
use App\Policies\ReportPolicy;
use App\Policies\SecurityEventPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Pemetaan policy eksplisit: jangan mengandalkan auto-discovery agar
     * perubahan namespace tidak diam-diam membuka akses.
     */
    protected array $policies = [
        User::class => UserPolicy::class,
        Report::class => ReportPolicy::class,
        Aspiration::class => AspirationPolicy::class,
        AspirationComment::class => AspirationCommentPolicy::class,
        ClassRoom::class => ClassRoomPolicy::class,
        ClassMembership::class => ClassRoomPolicy::class,
        Attachment::class => AttachmentPolicy::class,
        IdentityVerification::class => IdentityVerificationPolicy::class,
        AppNotification::class => AppNotificationPolicy::class,
        SecurityEvent::class => SecurityEventPolicy::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim((string) config('app.url'), '/')
            .'#/reset-password?token='.rawurlencode($token).'&email='.rawurlencode($user->email));

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Endpoint pengaduan: batas per pengguna, bukan per IP, agar satu
        // akun tidak melampaui batas dengan ganti jaringan.
        RateLimiter::for('reports', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
