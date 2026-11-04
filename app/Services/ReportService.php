<?php

namespace App\Services;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\ReportSensitivity;
use App\Enums\ReportStatus;
use App\Enums\ReportUpdateType;
use App\Enums\RoutingMode;
use App\Models\ClassRoom;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\ReportPrivateDetail;
use App\Models\ReportStatusHistory;
use App\Models\ReportUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pembuatan dan pembaruan pengaduan.
 *
 * Isi laporan ditulis ke report_private_details, metadata ke reports.
 * Pembuatan + routing berjalan dalam satu transaksi sehingga kegagalan
 * tidak meninggalkan laporan tanpa riwayat status.
 */
class ReportService
{
    public function __construct(
        private readonly ReportRoutingService $routing,
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
        private readonly SecurityEventService $security,
        private readonly PrivateStorageService $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $reporter, array $data): Report
    {
        $classId = (int) ($data['class_id'] ?? $reporter->activeClassId() ?? 0);

        if ($classId === 0 || ! $reporter->canUseClassFeatures() || $reporter->activeClassId() !== $classId) {
            throw ValidationException::withMessages([
                'class_id' => 'Anda hanya dapat membuat pengaduan untuk kelas yang Anda ikuti dan sudah aktif.',
            ]);
        }

        $category = ReportCategory::query()->findOrFail($data['category_id']);
        $class = ClassRoom::query()->findOrFail($classId);

        if (isset($data['academic_term_id']) && (int) $data['academic_term_id'] !== $class->academic_term_id) {
            throw ValidationException::withMessages([
                'academic_term_id' => 'Periode akademik harus sesuai dengan kelas pengaduan.',
            ]);
        }

        $sensitivity = $category->is_sensitive || $category->code->isSensitiveByDefault()
            ? ReportSensitivity::Sensitive
            : ReportSensitivity::Routine;

        $paths = [];

        try {
            return DB::transaction(function () use ($reporter, $data, $class, $classId, $category, $sensitivity, &$paths) {
                $reporter = User::query()->lockForUpdate()->findOrFail($reporter->id);
                if (! $reporter->canUseClassFeatures() || $reporter->activeClassId() !== $classId) {
                    throw ValidationException::withMessages(['class_id' => 'Keanggotaan kelas tidak lagi aktif.']);
                }

                $report = Report::create([
                    'class_id' => $classId,
                    'academic_term_id' => $class->academic_term_id,
                    'reporter_id' => $reporter->id,
                    'category_id' => $category->id,
                    'title' => $data['title'],
                    'sensitivity' => $sensitivity,
                    'routing_mode' => RoutingMode::Manual,
                    'last_activity_at' => now(),
                ]);

                ReportPrivateDetail::create([
                    'report_id' => $report->id,
                    'description' => $data['description'],
                    'involved_parties' => $data['involved_parties'] ?? null,
                    'desired_outcome' => $data['desired_outcome'] ?? null,
                    'reporter_notes' => $data['reporter_notes'] ?? null,
                ]);

                // Status awal submitted. Baris riwayat awal wajib ada sehingga
                // seluruh perubahan setelahnya dapat direkonstruksi.
                $report->forceFill([
                    'status' => ReportStatus::Submitted,
                    'last_activity_at' => now(),
                ])->save();

                ReportStatusHistory::create([
                    'report_id' => $report->id,
                    'from_status' => null,
                    'to_status' => ReportStatus::Submitted->value,
                    'actor_id' => $reporter->id,
                    'reason' => 'Pengaduan dikirim.',
                    'source' => 'api',
                ]);

                $this->routing->route($report);

                foreach ($data['attachments'] ?? [] as $file) {
                    $attachment = $this->storage->storeAttachment($file, $report, $reporter, AttachmentType::ReportAttachment);
                    $paths[] = $attachment->path;
                }

                $this->audit->record(AuditAction::ReportSubmitted, $reporter, $report, $classId, [
                    'category' => $category->code->value,
                    'sensitivity' => $sensitivity->value,
                    'routing_mode' => $report->routing_mode->value,
                ]);

                return $report->fresh();
            });
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                $this->storage->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Tambah pembaruan. Mahasiswa hanya boleh menambah pembaruan pada
     * laporan miliknya; catatan internal hanya untuk penangan.
     */
    public function addUpdate(
        Report $report,
        User $author,
        string $body,
        ReportUpdateType $type = ReportUpdateType::Update,
        bool $internal = false,
        ?int $replyToId = null,
    ): ReportUpdate {
        Gate::forUser($author)->authorize('addUpdate', $report);

        if ($internal && ($report->reporter_id === $author->id || ! $author->hasPermission('reports.view.private'))) {
            throw ValidationException::withMessages([
                'body' => 'Anda tidak berwenang menulis catatan internal.',
            ]);
        }

        return DB::transaction(function () use ($report, $author, $body, $type, $internal, $replyToId) {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            Gate::forUser($author)->authorize('addUpdate', $report);

            if ($replyToId !== null && ! $report->updates()->whereKey($replyToId)
                ->when($report->reporter_id === $author->id, fn ($query) => $query->where('is_internal', false))->exists()) {
                throw ValidationException::withMessages(['reply_to_id' => 'Pembaruan tujuan tidak tersedia pada pengaduan ini.']);
            }

            if ($type === ReportUpdateType::ClarificationRequest) {
                Gate::forUser($author)->authorize('requestClarification', $report);
            }

            if ($type === ReportUpdateType::ClarificationResponse) {
                Gate::forUser($author)->authorize('respondClarification', $report);
                $request = $report->updates()->where('type', ReportUpdateType::ClarificationRequest->value)
                    ->where('is_internal', false)
                    ->whereNotIn('id', $report->updates()->where('type', ReportUpdateType::ClarificationResponse->value)
                        ->whereNotNull('reply_to_id')->select('reply_to_id'))
                    ->when($replyToId !== null, fn ($query) => $query->whereKey($replyToId))
                    ->latest('id')->first();

                if ($request === null) {
                    throw ValidationException::withMessages(['reply_to_id' => 'Tidak ada permintaan klarifikasi yang terbuka.']);
                }

                $replyToId = $request->id;
            }

            $update = $report->updates()->create([
                'author_id' => $author->id,
                'type' => $type,
                'body' => $body,
                'is_internal' => $internal,
                'reply_to_id' => $replyToId,
            ]);

            $report->forceFill(['last_activity_at' => now()])->save();

            if ($type === ReportUpdateType::ClarificationRequest) {
                $this->notifications->reportEvent(
                    $report->reporter,
                    $report,
                    NotificationType::ClarificationRequested,
                    'Permintaan klarifikasi',
                    'Penangan meminta klarifikasi tambahan untuk pengaduan Anda.',
                );
            } elseif ($type === ReportUpdateType::ClarificationResponse) {
                $handler = $report->currentHandler;

                if ($handler !== null) {
                    $this->notifications->reportEvent(
                        $handler,
                        $report,
                        NotificationType::ReportStatusChanged,
                        'Klarifikasi diterima',
                        'Pelapor mengirim jawaban klarifikasi.',
                    );
                }
            }

            return $update;
        });
    }
}
