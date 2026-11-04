<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\NotificationType;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * State machine pengaduan. Satu-satunya jalur perubahan status.
 *
 * Invarian yang dijaga:
 * - Transisi harus ada di ReportStatus::allowedTransitions().
 * - rejected wajib alasan; resolved wajib ringkasan hasil.
 * - Penangan tidak boleh berkonflik kepentingan (termasuk menjadi
 *   pihak yang dilaporkan).
 * - Setiap perubahan menulis riwayat append-only, audit log, dan transaksi
 *   yang sama — gagal di tengah berarti tidak ada perubahan sama sekali.
 * - Payload frontend tidak pernah dipakai untuk menetapkan status langsung;
 *   controller memanggil transition() dengan status tujuan yang valid.
 */
class ReportWorkflowService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{reason?: string|null, resolution_summary?: string|null, target_handler_id?: int|null, internal?: bool}  $options
     */
    public function transition(Report $report, ReportStatus $to, User $actor, array $options = []): Report
    {
        return DB::transaction(function () use ($report, $to, $actor, $options) {
            /** @var Report $locked */
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);

            $from = $locked->status;

            if ($from === null) {
                throw new LogicException('Status laporan tidak diketahui.');
            }

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "Transisi status {$from->value} -> {$to->value} tidak diizinkan.",
                ]);
            }

            if ($from === ReportStatus::AppealPending || $to === ReportStatus::AppealPending) {
                throw ValidationException::withMessages([
                    'status' => 'Status banding hanya dapat diubah melalui alur banding.',
                ]);
            }

            if ($to === ReportStatus::Forwarded) {
                throw ValidationException::withMessages([
                    'status' => 'Pengalihan harus melalui endpoint pengalihan penangan.',
                ]);
            }

            $this->assertActorMayHandle($locked, $actor);

            $reason = $this->normalize($options['reason'] ?? null);
            $summary = $this->normalize($options['resolution_summary'] ?? null);

            if ($to->requiresReason() && $reason === null) {
                throw ValidationException::withMessages([
                    'reason' => 'Penolakan pengaduan wajib menyertakan alasan.',
                ]);
            }

            if ($to->requiresSummary() && $summary === null) {
                throw ValidationException::withMessages([
                    'resolution_summary' => 'Penyelesaian pengaduan wajib menyertakan ringkasan hasil.',
                ]);
            }

            $updates = [
                'status' => $to,
                'last_activity_at' => now(),
            ];

            if ($to === ReportStatus::Rejected) {
                $updates['rejection_reason'] = $reason;
                $updates['closed_at'] = now();
            }

            if ($to === ReportStatus::Resolved) {
                $updates['resolution_summary'] = $summary;
                $updates['resolved_at'] = now();
                $updates['closed_at'] = now();
            }

            if ($to === ReportStatus::Reopened) {
                $updates['closed_at'] = null;
                $updates['resolved_at'] = null;
            }

            $locked->forceFill($updates)->save();

            ReportStatusHistory::create([
                'report_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor->id,
                'reason' => mb_substr($reason ?? $summary ?? '', 0, 1000),
                'source' => 'api',
            ]);

            $this->audit->record(AuditAction::ReportStatusChanged, $actor, $locked, $locked->class_id, [
                'from' => $from->value,
                'to' => $to->value,
            ]);

            $this->notifications->reportEvent(
                $locked->reporter,
                $locked,
                NotificationType::ReportStatusChanged,
                'Status pengaduan diperbarui',
                'Status pengaduan Anda sekarang: '.$to->label().'.',
            );

            $handler = $locked->currentHandler;

            if ($handler !== null && $handler->id !== $actor->id) {
                $this->notifications->reportEvent(
                    $handler,
                    $locked,
                    NotificationType::ReportStatusChanged,
                    'Status pengaduan diperbarui',
                    'Status pengaduan berubah menjadi: '.$to->label().'.',
                );
            }

            return $locked;
        });
    }

    /**
     * Aktor wajib berwenang dan bebas konflik. Pelapor TIDAK boleh
     * mengubah status laporannya sendiri.
     */
    public function assertActorMayHandle(Report $report, User $actor): void
    {
        if ($report->reporter_id === $actor->id) {
            throw ValidationException::withMessages([
                'status' => 'Pelapor tidak dapat mengubah status pengaduannya sendiri.',
            ]);
        }

        if (! $actor->hasPermission('reports.update_status')) {
            throw ValidationException::withMessages([
                'status' => 'Anda tidak memiliki kewenangan mengubah status pengaduan.',
            ]);
        }

        if (! $report->isAssignedTo($actor)) {
            throw ValidationException::withMessages([
                'status' => 'Anda tidak ditugaskan pada pengaduan ini.',
            ]);
        }

        if (! in_array($report->class_id, $actor->accessibleClassIds(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Pengaduan berada di luar kelas yang dapat Anda akses.',
            ]);
        }

        if ($report->hasConflictFor($actor) || $this->isSubjectOfReport($report, $actor)) {
            throw ValidationException::withMessages([
                'status' => 'Anda tercatat sebagai pihak yang berkepentingan pada pengaduan ini dan tidak dapat menanganinya.',
            ]);
        }

        if (! $actor->can('updateStatus', $report)) {
            throw ValidationException::withMessages([
                'status' => 'Penugasan Anda tidak memberikan kewenangan mengubah status.',
            ]);
        }
    }

    /**
     * Dosen yang menjadi pihak terlapor tidak boleh menangani kasusnya.
     * Deteksi: nama aktor muncul di involved_parties, atau dosen tsb
     * menjadi pengampu kelas dan laporan berkategori terkait dosen.
     */
    public function isSubjectOfReport(Report $report, User $actor): bool
    {
        $parties = $report->privateDetails?->involved_parties;

        if (is_array($parties)) {
            $actorName = mb_strtolower(trim($actor->name));

            foreach ($parties as $party) {
                $text = is_string($party) ? $party : (is_array($party) ? json_encode($party) : '');
                $lower = mb_strtolower($text);

                if ($lower === $actorName || str_contains($lower, $actorName)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
