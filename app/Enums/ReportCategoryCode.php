<?php

namespace App\Enums;

enum ReportCategoryCode: string
{
    case Academic = 'academic';
    case LecturerConduct = 'lecturer_conduct';
    case ClassDisruption = 'class_disruption';
    case Facilities = 'facilities';
    case Grouping = 'grouping';
    case Interpersonal = 'interpersonal';
    case Suggestion = 'suggestion';

    public function label(): string
    {
        return match ($this) {
            self::Academic => 'Masalah akademik dan metode pembelajaran',
            self::LecturerConduct => 'Masalah terkait dosen atau perkuliahan',
            self::ClassDisruption => 'Gangguan kelas dan perilaku',
            self::Facilities => 'Fasilitas kelas',
            self::Grouping => 'Pembagian kelompok dan kegiatan kelas',
            self::Interpersonal => 'Masalah interpersonal',
            self::Suggestion => 'Saran dan aspirasi',
        };
    }

    /**
     * Kategori yang menyinggung perilaku seseorang dianggap sensitif:
     * wajib ditinjau manusia, tidak boleh dirutekan otomatis.
     */
    public function isSensitiveByDefault(): bool
    {
        return in_array($this, [self::LecturerConduct, self::Interpersonal], true);
    }
}
