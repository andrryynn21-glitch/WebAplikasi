<?php

namespace App\Enums;

enum AuditAction: string
{
    case RoleAssigned = 'role_assigned';
    case RoleChangeRejected = 'role_change_rejected';
    case UserSuspended = 'user_suspended';
    case UserReactivated = 'user_reactivated';
    case ClassCreated = 'class_created';
    case ClassUpdated = 'class_updated';
    case ClassLeaderAssigned = 'class_leader_assigned';
    case ClassLeaderRevoked = 'class_leader_revoked';
    case LecturerAssigned = 'lecturer_assigned';
    case LecturerUnassigned = 'lecturer_unassigned';
    case InvitationIssued = 'invitation_issued';
    case InvitationRevoked = 'invitation_revoked';
    case MembershipApproved = 'membership_approved';
    case MembershipRejected = 'membership_rejected';
    case IdentityApproved = 'identity_approved';
    case IdentityRejected = 'identity_rejected';
    case ReportSubmitted = 'report_submitted';
    case ReportStatusChanged = 'report_status_changed';
    case ReportAssigned = 'report_assigned';
    case ReportForwarded = 'report_forwarded';
    case AppealDecided = 'appeal_decided';
    case ContentModerated = 'content_moderated';
    case ConfigChanged = 'config_changed';
    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case AttendanceCourseSaved = 'attendance_course_saved';
    case AttendanceCreated = 'attendance_created';
    case AttendanceUpdated = 'attendance_updated';
    case AttendanceFinalized = 'attendance_finalized';
    case AttendanceReopened = 'attendance_reopened';

    public function label(): string
    {
        return match ($this) {
            self::RoleAssigned => 'Role diberikan',
            self::RoleChangeRejected => 'Permintaan perubahan role ditolak',
            self::UserSuspended => 'Akun dinonaktifkan',
            self::UserReactivated => 'Akun diaktifkan kembali',
            self::ClassCreated => 'Kelas dibuat',
            self::ClassUpdated => 'Kelas diubah',
            self::ClassLeaderAssigned => 'Ketua kelas ditetapkan',
            self::ClassLeaderRevoked => 'Ketua kelas dicabut',
            self::LecturerAssigned => 'Dosen ditugaskan',
            self::LecturerUnassigned => 'Penugasan dosen dicabut',
            self::InvitationIssued => 'Kode undangan dibuat',
            self::InvitationRevoked => 'Kode undangan dicabut',
            self::MembershipApproved => 'Keanggotaan disetujui',
            self::MembershipRejected => 'Keanggotaan ditolak',
            self::IdentityApproved => 'Identitas disetujui',
            self::IdentityRejected => 'Identitas ditolak',
            self::ReportSubmitted => 'Pengaduan dikirim',
            self::ReportStatusChanged => 'Status pengaduan diubah',
            self::ReportAssigned => 'Pengaduan ditugaskan',
            self::ReportForwarded => 'Pengaduan dialihkan',
            self::AppealDecided => 'Banding diputuskan',
            self::ContentModerated => 'Konten dimoderasi',
            self::ConfigChanged => 'Konfigurasi diubah',
            self::PasswordChanged => 'Password diubah',
            self::PasswordResetRequested => 'Pemulihan password diminta',
            self::PasswordResetCompleted => 'Password dipulihkan',
            self::AttendanceCourseSaved => 'Mata kuliah absensi disimpan',
            self::AttendanceCreated => 'Pertemuan absensi dibuat',
            self::AttendanceUpdated => 'Draf absensi disimpan',
            self::AttendanceFinalized => 'Absensi difinalisasi',
            self::AttendanceReopened => 'Absensi dibuka untuk koreksi',
        };
    }
}
