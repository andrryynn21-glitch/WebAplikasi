<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Submitted = 'submitted';
    case NeedsReview = 'needs_review';
    case UnderReview = 'under_review';
    case NeedsClarification = 'needs_clarification';
    case InProgress = 'in_progress';
    case WaitingFollowUp = 'waiting_follow_up';
    case ResolutionProposed = 'resolution_proposed';
    case Forwarded = 'forwarded';
    case Rejected = 'rejected';
    case Resolved = 'resolved';
    case AppealPending = 'appeal_pending';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Dikirim',
            self::NeedsReview => 'Perlu peninjauan',
            self::UnderReview => 'Sedang ditinjau',
            self::NeedsClarification => 'Butuh klarifikasi',
            self::InProgress => 'Sedang ditangani',
            self::WaitingFollowUp => 'Menunggu tindak lanjut',
            self::ResolutionProposed => 'Usulan penyelesaian',
            self::Forwarded => 'Dialihkan',
            self::Rejected => 'Ditolak',
            self::Resolved => 'Selesai',
            self::AppealPending => 'Banding diajukan',
            self::Reopened => 'Dibuka kembali',
        };
    }

    /**
     * Transisi status yang sah. Divalidasi di ReportWorkflowService.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::NeedsReview, self::UnderReview, self::Rejected, self::Forwarded],
            self::NeedsReview => [self::UnderReview, self::Forwarded, self::Rejected],
            self::UnderReview => [self::InProgress, self::NeedsClarification, self::Forwarded, self::Rejected, self::ResolutionProposed],
            self::NeedsClarification => [self::UnderReview, self::InProgress, self::Rejected],
            self::InProgress => [self::WaitingFollowUp, self::Forwarded, self::ResolutionProposed, self::Rejected],
            self::WaitingFollowUp => [self::InProgress, self::ResolutionProposed, self::Rejected],
            self::ResolutionProposed => [self::Resolved, self::Reopened],
            self::Forwarded => [self::UnderReview, self::InProgress, self::Rejected],
            self::Rejected => [self::Reopened, self::AppealPending],
            self::Resolved => [self::Reopened, self::AppealPending],
            self::AppealPending => [self::UnderReview, self::Resolved, self::Rejected],
            self::Reopened => [self::UnderReview, self::InProgress, self::Rejected, self::ResolutionProposed],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Rejected;
    }

    /** Status yang masih dianggap aktif untuk keperluan statistik/dashboard. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function requiresReason(): bool
    {
        return $this === self::Rejected;
    }

    public function requiresSummary(): bool
    {
        return $this === self::Resolved;
    }
}
