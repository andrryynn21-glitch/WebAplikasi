<?php

namespace App\Enums;

enum NotificationType: string
{
    case ReportStatusChanged = 'report_status_changed';
    case ClarificationRequested = 'clarification_requested';
    case ReportAssigned = 'report_assigned';
    case AppealSubmitted = 'appeal_submitted';
    case AppealDecided = 'appeal_decided';
    case AspirationComment = 'aspiration_comment';
    case ContentModerated = 'content_moderated';
    case IdentityVerification = 'identity_verification';
    case MembershipDecision = 'membership_decision';
    case SecurityNotice = 'security_notice';

    public function label(): string
    {
        return match ($this) {
            self::ReportStatusChanged => 'Status pengaduan berubah',
            self::ClarificationRequested => 'Permintaan klarifikasi',
            self::ReportAssigned => 'Penugasan penanganan',
            self::AppealSubmitted => 'Banding baru',
            self::AppealDecided => 'Keputusan banding',
            self::AspirationComment => 'Komentar baru',
            self::ContentModerated => 'Tindakan moderasi',
            self::IdentityVerification => 'Status verifikasi identitas',
            self::MembershipDecision => 'Keputusan keanggotaan kelas',
            self::SecurityNotice => 'Pemberitahuan keamanan',
        };
    }
}
