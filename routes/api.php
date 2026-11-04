<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AppealController;
use App\Http\Controllers\Api\V1\AspirationController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\IdentityVerificationController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReportAssignmentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SecurityEventController;
use App\Http\Controllers\Api\V1\VoiceWarningController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Semua endpoint memakai prefix /api/v1 (lihat bootstrap/app.php).
| Autentikasi: Bearer token Sanctum.
| Envelope response: { message, data, meta?, error? }
|
*/

// ---------------------------------------------------------------- publik

Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:10,1,register:')
    ->name('auth.register');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1,login:')
    ->name('auth.login');
Route::post('/auth/password/forgot', [CredentialController::class, 'forgot'])
    ->middleware('throttle:3,60,password-forgot:')->name('auth.password.forgot');
Route::post('/auth/password/reset', [CredentialController::class, 'reset'])
    ->middleware('throttle:5,60,password-reset:')->name('auth.password.reset');

Route::get('/security/voice-warnings', [VoiceWarningController::class, 'index'])
    ->name('security.voice-warnings');

// ------------------------------------------------------------ terautentikasi

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {

    // Akun & profil
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/password/change', [CredentialController::class, 'change'])
        ->middleware('throttle:5,60,password-change:')->name('auth.password.change');
    Route::patch('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');

    // Verifikasi identitas (milik sendiri)
    Route::get('/identity/status', [IdentityVerificationController::class, 'status'])->name('identity.status');
    Route::post('/identity/submissions', [IdentityVerificationController::class, 'submit'])
        ->middleware('throttle:5,60,identity-submit:')
        ->name('identity.submit');
    Route::get('/identity-verifications/{verification}/ktm', [IdentityVerificationController::class, 'download'])
        ->name('identity.ktm.download');

    // Verifikasi identitas (petugas)
    Route::middleware('permission:identity.verify')->group(function () {
        Route::get('/identity-verifications', [IdentityVerificationController::class, 'index'])
            ->name('identity.index');
        Route::post('/identity-verifications/{verification}/approve', [IdentityVerificationController::class, 'approve'])
            ->name('identity.approve');
        Route::post('/identity-verifications/{verification}/reject', [IdentityVerificationController::class, 'reject'])
            ->name('identity.reject');
    });

    // Kelas & keanggotaan
    Route::get('/classes', [MembershipController::class, 'classes'])->name('classes.index');
    Route::get('/academic-terms', [MembershipController::class, 'academicTerms'])->name('academic-terms.index');
    Route::get('/memberships', [MembershipController::class, 'myMemberships'])->name('memberships.mine');
    Route::post('/memberships/join', [MembershipController::class, 'join'])
        ->middleware('throttle:10,60,membership-join:')
        ->name('memberships.join');
    Route::get('/classes/{class}/memberships/pending', [MembershipController::class, 'pending'])
        ->name('memberships.pending');
    Route::post('/memberships/{membership}/decide', [MembershipController::class, 'decide'])
        ->name('memberships.decide');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');

    Route::prefix('attendance')->name('attendance.')->middleware('permission:attendance.manage')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::post('/courses', [AttendanceController::class, 'storeCourse'])->name('courses.store');
        Route::patch('/courses/{course}', [AttendanceController::class, 'updateCourse'])->whereNumber('course')->name('courses.update');
        Route::get('/sessions', [AttendanceController::class, 'sessions'])->name('sessions.index');
        Route::post('/sessions', [AttendanceController::class, 'store'])->name('sessions.store');
        Route::get('/sessions/{session}', [AttendanceController::class, 'show'])->whereNumber('session')->name('sessions.show');
        Route::patch('/sessions/{session}', [AttendanceController::class, 'update'])->whereNumber('session')->name('sessions.update');
        Route::post('/sessions/{session}/finalize', [AttendanceController::class, 'finalize'])->whereNumber('session')->name('sessions.finalize');
        Route::post('/sessions/{session}/reopen', [AttendanceController::class, 'reopen'])->whereNumber('session')->name('sessions.reopen');
        Route::get('/summary', [AttendanceController::class, 'summary'])->name('summary');
        Route::get('/export', [AttendanceController::class, 'export'])->name('export');
    });

    // Pengaduan — daftar & kategori aman untuk semua pengguna aktif
    Route::get('/report-categories', [ReportController::class, 'categories'])->name('reports.categories');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/statistics', [ReportController::class, 'statistics'])->name('reports.statistics');

    // Pengaduan — fitur kelas
    Route::middleware('class.access')->group(function () {
        Route::post('/reports', [ReportController::class, 'store'])
            ->middleware('throttle:15,60,report-submit:')
            ->name('reports.store');
        Route::post('/reports/{report}/updates', [ReportController::class, 'addUpdate'])
            ->name('reports.updates.store');

        // Aspirasi
        Route::get('/aspirations', [AspirationController::class, 'index'])->name('aspirations.index');
        Route::post('/aspirations', [AspirationController::class, 'store'])
            ->middleware('throttle:10,60,aspiration-submit:')
            ->name('aspirations.store');
        Route::get('/aspirations/{aspiration}', [AspirationController::class, 'show'])->name('aspirations.show');
        Route::get('/aspirations/{aspiration}/comments', [AspirationController::class, 'comments'])->name('aspirations.comments.index');
        Route::post('/aspirations/{aspiration}/comments', [AspirationController::class, 'storeComment'])
            ->middleware('throttle:20,1,aspiration-comment:')
            ->name('aspirations.comments.store');
        Route::post('/aspirations/{aspiration}/support', [AspirationController::class, 'support'])
            ->middleware('throttle:60,1,aspiration-support:')
            ->name('aspirations.support');
        Route::delete('/aspirations/{aspiration}/support', [AspirationController::class, 'unsupport'])
            ->name('aspirations.unsupport');
        Route::post('/aspirations/{aspiration}/flag', [AspirationController::class, 'flag'])
            ->middleware('throttle:10,60,aspiration-flag:')
            ->name('aspirations.flag');
        Route::post('/aspirations/{aspiration}/moderate', [AspirationController::class, 'moderate'])
            ->name('aspirations.moderate');
        Route::get('/aspirations/{aspiration}/flags', [AspirationController::class, 'flags'])
            ->name('aspirations.flags.index');
        Route::post('/aspirations/{aspiration}/comments/{comment}/flag', [AspirationController::class, 'flagComment'])
            ->middleware('throttle:10,60,comment-flag:')
            ->scopeBindings()
            ->name('aspirations.comments.flag');
        Route::post('/aspirations/{aspiration}/comments/{comment}/moderate', [AspirationController::class, 'moderateComment'])
            ->scopeBindings()
            ->name('aspirations.comments.moderate');
    });

    // Pengaduan — detail dan aksi
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::patch('/reports/{report}/status', [ReportController::class, 'updateStatus'])
        ->name('reports.status');
    Route::post('/reports/{report}/clarification-requests', [ReportController::class, 'requestClarification'])
        ->name('reports.clarification');
    Route::post('/reports/{report}/assignments', [ReportAssignmentController::class, 'store'])
        ->name('reports.assignments.store');
    Route::post('/reports/{report}/forward', [ReportAssignmentController::class, 'forward'])
        ->name('reports.forward');

    // Banding
    Route::post('/reports/{report}/appeals', [AppealController::class, 'store'])
        ->middleware('throttle:5,60,appeal-submit:')
        ->name('reports.appeals.store');
    Route::get('/appeals/{appeal}', [AppealController::class, 'show'])->name('appeals.show');
    Route::get('/appeals/{appeal}/eligible-reviewers', [AppealController::class, 'eligibleReviewers'])
        ->name('appeals.reviewers');
    Route::post('/appeals/{appeal}/reviewer', [AppealController::class, 'assignReviewer'])
        ->name('appeals.reviewer.assign');
    Route::post('/appeals/{appeal}/decision', [AppealController::class, 'decide'])
        ->name('appeals.decide');

    // File privat
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    // Notifikasi
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');

    // Security event
    Route::get('/security-events', [SecurityEventController::class, 'index'])
        ->name('security-events.index');

    // Administrasi (Super Admin)
    Route::middleware('permission:users.manage,roles.manage,audit.view')->group(function () {
        Route::get('/admin/users', [AdminController::class, 'users'])->name('admin.users.index');
        Route::get('/admin/audit-logs', [AdminController::class, 'auditLogs'])->name('admin.audit.index');
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::post('/admin/users/{user}/suspend', [AdminController::class, 'suspend'])->name('admin.users.suspend');
        Route::post('/admin/users/{user}/reactivate', [AdminController::class, 'reactivate'])->name('admin.users.reactivate');
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::post('/admin/users/{user}/role', [AdminController::class, 'assignRole'])->name('admin.users.role');
    });

    Route::middleware('permission:classes.manage')->group(function () {
        Route::post('/admin/classes', [AdminController::class, 'storeClass'])->name('admin.classes.store');
        Route::patch('/admin/classes/{class}', [AdminController::class, 'updateClass'])->name('admin.classes.update');
        Route::post('/admin/classes/{class}/leader', [AdminController::class, 'assignClassLeader'])->name('admin.classes.leader');
        Route::delete('/admin/class-leaders/{assignment}', [AdminController::class, 'revokeClassLeader'])->name('admin.classes.leader.revoke');
        Route::post('/admin/classes/{class}/lecturers', [AdminController::class, 'assignLecturer'])->name('admin.classes.lecturers');
    });

    Route::middleware('permission:classes.invite')->group(function () {
        Route::post('/classes/{class}/invitations', [AdminController::class, 'issueInvitation'])->name('classes.invitations.store');
    });
});
