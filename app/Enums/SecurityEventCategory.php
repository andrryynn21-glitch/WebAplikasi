<?php

namespace App\Enums;

enum SecurityEventCategory: string
{
    case AccessDenied = 'access_denied';
    case UnauthorizedAccessAttempt = 'unauthorized_access_attempt';
    case CrossClassAccessAttempt = 'cross_class_access_attempt';
    case PrivilegeEscalationAttempt = 'privilege_escalation_attempt';
    case RepeatedDenials = 'repeated_denials';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case SuspiciousLogin = 'suspicious_login';
    case IdentityVerificationBypassAttempt = 'identity_verification_bypass_attempt';
    case PrivateFileAccessAttempt = 'private_file_access_attempt';
    case RoleChange = 'role_change';
    case ConflictOfInterestViolation = 'conflict_of_interest_violation';

    public function label(): string
    {
        return match ($this) {
            self::AccessDenied => 'Akses ditolak',
            self::UnauthorizedAccessAttempt => 'Percobaan akses tanpa otentikasi',
            self::CrossClassAccessAttempt => 'Percobaan akses lintas kelas',
            self::PrivilegeEscalationAttempt => 'Percobaan peningkatan hak akses',
            self::RepeatedDenials => 'Penolakan berulang',
            self::RateLimitExceeded => 'Melebihi batas laju',
            self::SuspiciousLogin => 'Percobaan login mencurigakan',
            self::IdentityVerificationBypassAttempt => 'Percobaan melewati verifikasi identitas',
            self::PrivateFileAccessAttempt => 'Percobaan akses file privat',
            self::RoleChange => 'Perubahan role',
            self::ConflictOfInterestViolation => 'Pelanggaran konflik kepentingan',
        };
    }

    /** Kategori yang merupakan respons presentasi (memutar suara peringatan). */
    public function triggersVoiceWarning(): bool
    {
        return $this === self::RepeatedDenials
            || $this === self::CrossClassAccessAttempt
            || $this === self::PrivilegeEscalationAttempt
            || $this === self::IdentityVerificationBypassAttempt
            || $this === self::PrivateFileAccessAttempt
            || $this === self::ConflictOfInterestViolation;
    }

    public function severity(): SecuritySeverity
    {
        return match ($this) {
            self::AccessDenied => SecuritySeverity::Low,
            self::RateLimitExceeded, self::SuspiciousLogin, self::RoleChange => SecuritySeverity::Medium,
            self::CrossClassAccessAttempt,
            self::PrivilegeEscalationAttempt,
            self::IdentityVerificationBypassAttempt,
            self::PrivateFileAccessAttempt,
            self::ConflictOfInterestViolation => SecuritySeverity::High,
            self::UnauthorizedAccessAttempt, self::RepeatedDenials => SecuritySeverity::Critical,
        };
    }
}
