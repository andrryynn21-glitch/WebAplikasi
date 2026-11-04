<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cooldown dan deteksi pengulangan
    |--------------------------------------------------------------------------
    |
    | Penolakan biasa dicatat sebagai security event ringan. Jika jumlah
    | penolakan melewati ambang dalam jendela waktu tertentu, kejadian
    | dieskalasi menjadi repeated_denials.
    |
    */

    'cooldown_seconds' => (int) env('SECURITY_COOLDOWN_SECONDS', 900),
    'repeat_threshold' => (int) env('SECURITY_REPEAT_THRESHOLD', 5),
    'repeat_window_seconds' => (int) env('SECURITY_REPEAT_WINDOW_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Voice Warning Manager
    |--------------------------------------------------------------------------
    |
    | Suara hanya untuk presentasi. Keputusan akses sudah final sebelum
    | audio disentuh. File rekaman tidak disertakan dalam repository;
    | isi path absolut/nama file di sini bila rekaman sudah tersedia.
    |
    */

    'voice_warnings' => [
        'enabled' => (bool) env('SECURITY_VOICE_ENABLED', true),
        'cooldown_seconds' => 30,

        // Kategori audio. Placeholder dipakai jika file belum tersedia.
        'sounds' => [
            'access_denied' => ['file' => null, 'placeholder' => 'akses-ditolak'],
            'repeated_denials' => ['file' => null, 'placeholder' => 'percobaan-berulang'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batas upload lampiran (KB per file, bytes untuk total)
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'identity_max_kb' => 4096,
        'report_max_kb' => 8192,
        'report_max_total_bytes' => (int) env('REPORT_MAX_TOTAL_BYTES', 26214400),
        'aspiration_max_kb' => 4096,
        'max_report_attachments' => 5,
        'max_aspiration_attachments' => 3,
    ],

];
