<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IdentityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\RejectIdentityRequest;
use App\Http\Requests\Identity\SubmitIdentityRequest;
use App\Models\IdentityVerification;
use App\Services\IdentityVerificationService;
use App\Services\PrivateStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdentityVerificationController extends Controller
{
    public function __construct(
        private readonly IdentityVerificationService $verifications,
        private readonly PrivateStorageService $storage,
    ) {}

    /**
     * Status verifikasi milik pengguna sendiri. Tidak pernah memuat path file.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();
        $latest = $user->latestIdentityVerification()->first();
        $pending = $user->identityVerifications()->where('status', IdentityStatus::PendingVerification->value)->first();
        $approvedId = IdentityVerification::recordedApprovalIdFor($user);
        $legacy = ($user->isVerified() && $approvedId === null)
            || $user->identityVerifications()->where(fn ($query) => $query
                ->whereNull('retains_verified')
                ->orWhere('before_snapshot->history_state', 'unresolved_legacy'))->exists();
        $message = $user->identity_status?->publicMessage();
        if ($user->isVerified() && $pending !== null) {
            $message = 'Koreksi identitas menunggu pemeriksaan. Status terverifikasi dan identitas yang berlaku tidak berubah.';
        } elseif ($user->isVerified() && $latest?->status === IdentityStatus::Rejected) {
            $message = 'Koreksi identitas ditolak. Status terverifikasi dan identitas yang berlaku tetap dipertahankan.';
        }

        return $this->respond([
            'identity_status' => $user->identity_status?->value,
            'message' => $message,
            'pending_submission_id' => $pending?->id,
            'pending_correction' => $pending !== null && $user->isVerified(),
            'approved_submission_id' => $approvedId,
            'history_state' => $legacy ? 'unresolved_legacy' : ($approvedId === null ? 'no_approval' : 'recorded'),
            'latest_submission' => $latest === null ? null : [
                'id' => $latest->id,
                'status' => $latest->status?->value,
                'retains_verified' => $latest->retains_verified,
                'requires_legacy_reconciliation' => $latest->retains_verified === null,
                'submitted_at' => $latest->submitted_at?->toIso8601String(),
                'reviewed_at' => $latest->reviewed_at?->toIso8601String(),
                'rejection_reason' => $latest->status === IdentityStatus::Rejected
                    ? $latest->rejection_reason
                    : null,
            ],
        ], 'Status verifikasi identitas.');
    }

    public function submit(SubmitIdentityRequest $request): JsonResponse
    {
        $data = $request->validated();

        $verification = $this->verifications->submit(
            $request->user(),
            $request->file('ktm'),
            $data['nim'],
            $data['full_name'],
        );

        return $this->respond([
            'id' => $verification->id,
            'status' => $verification->status?->value,
            'submitted_at' => $verification->submitted_at?->toIso8601String(),
        ], 'Berkas verifikasi diterima dan menunggu pemeriksaan.', 201);
    }

    /**
     * Unduh KTM. Hanya pemilik dan petugas verifikasi.
     */
    public function download(Request $request, IdentityVerification $verification): StreamedResponse
    {
        $this->authorize('download', $verification);

        return $this->storage->download(
            $verification->ktm_path,
            'ktm-'.$verification->id.'.'.pathinfo($verification->ktm_path, PATHINFO_EXTENSION),
            $verification->ktm_mime ?? 'application/octet-stream',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IdentityVerification::class);

        $query = IdentityVerification::query()
            ->with('user:id,name,nim,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('submitted_at');

        $paginator = $query->paginate($this->perPage($request));

        return $this->respond(
            $paginator->getCollection()->map(fn (IdentityVerification $v) => [
                'id' => $v->id,
                'user' => $v->user?->only(['id', 'name', 'nim', 'email']),
                'nim' => $v->nim,
                'full_name' => $v->full_name,
                'status' => $v->status?->value,
                'retains_verified' => $v->retains_verified,
                'requires_legacy_reconciliation' => $v->retains_verified === null,
                'submitted_at' => $v->submitted_at?->toIso8601String(),
                'ktm_download_url' => '/api/v1/identity-verifications/'.$v->id.'/ktm',
            ]),
            'Daftar pengajuan verifikasi.',
            200,
            ['pagination' => $this->paginationMeta($paginator)],
        );
    }

    public function approve(Request $request, IdentityVerification $verification): JsonResponse
    {
        $this->authorize('review', $verification);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $approved = $this->verifications->approve($verification, $request->user(), $data['note'] ?? null);

        return $this->respond([
            'id' => $approved->id,
            'status' => $approved->status?->value,
        ], 'Identitas disetujui.');
    }

    public function reject(RejectIdentityRequest $request, IdentityVerification $verification): JsonResponse
    {
        $this->authorize('review', $verification);

        $rejected = $this->verifications->reject($verification, $request->user(), $request->string('reason'));

        return $this->respond([
            'id' => $rejected->id,
            'status' => $rejected->status?->value,
        ], 'Pengajuan identitas ditolak.');
    }
}
