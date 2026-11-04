<?php

/**
 * Katalog permission dan pemetaannya ke role.
 *
 * Prinsip: Super Admin mengelola sistem, tetapi TIDAK otomatis memiliki
 * reports.view_private atau reports.update_status. Isi pengaduan hanya
 * dapat dibaca oleh penangan yang ditugaskan.
 */
return [

    'permissions' => [
        'reports.create' => 'Membuat pengaduan',
        'reports.view.own' => 'Melihat pengaduan sendiri',
        'reports.view.class' => 'Melihat metadata pengaduan kelas',
        'reports.view.assigned' => 'Melihat pengaduan yang ditugaskan',
        'reports.view.private' => 'Membaca isi pengaduan privat',
        'reports.update_status' => 'Mengubah status pengaduan',
        'reports.assign' => 'Menugaskan penangan pengaduan',
        'reports.request_clarification' => 'Meminta klarifikasi',
        'reports.respond_clarification' => 'Menjawab permintaan klarifikasi',
        'reports.appeal_submit' => 'Mengajukan banding',
        'reports.appeal_review' => 'Meninjau banding',
        'reports.statistics' => 'Melihat statistik penanganan',

        'aspirations.create' => 'Membuat aspirasi',
        'aspirations.participate' => 'Berkomentar dan upvote aspirasi',
        'aspirations.flag' => 'Melaporkan konten aspirasi',
        'aspirations.moderate' => 'Memoderasi aspirasi',

        'identity.submit' => 'Mengajukan verifikasi identitas',
        'identity.verify' => 'Memeriksa dan memutuskan verifikasi identitas',

        'users.manage' => 'Mengelola akun pengguna',
        'roles.manage' => 'Mengelola role dan permission',
        'classes.manage' => 'Mengelola kelas dan tahun akademik',
        'classes.invite' => 'Mengelola kode undangan kelas',
        'memberships.review' => 'Menyetujui keanggotaan kelas',
        'attendance.manage' => 'Mengelola absensi kelas',

        'security.view' => 'Melihat security event',
        'audit.view' => 'Melihat audit log',
        'system.maintain' => 'Pemeliharaan sistem',
    ],

    'roles' => [
        'student' => [
            'name' => 'Mahasiswa',
            'permissions' => [
                'reports.create',
                'reports.view.own',
                'reports.respond_clarification',
                'reports.appeal_submit',
                'aspirations.create',
                'aspirations.participate',
                'aspirations.flag',
                'identity.submit',
            ],
        ],

        'class_leader' => [
            'name' => 'Ketua Kelas / Admin Kelas',
            'permissions' => [
                'reports.create',
                'reports.view.own',
                'reports.view.class',
                'attendance.manage',
                'reports.view.private',
                'reports.update_status',
                'reports.request_clarification',
                'reports.respond_clarification',
                'reports.appeal_submit',
                'reports.statistics',
                'aspirations.create',
                'aspirations.participate',
                'aspirations.flag',
                'aspirations.moderate',
                'identity.submit',
                'memberships.review',
                'classes.invite',
                'security.view',
            ],
        ],

        'lecturer_coordinator' => [
            'name' => 'Dosen Koordinator',
            'permissions' => [
                'reports.view.assigned',
                'reports.view.private',
                'reports.update_status',
                'reports.assign',
                'reports.request_clarification',
                'reports.appeal_review',
                'reports.statistics',
                'aspirations.moderate',
                'memberships.review',
                'classes.invite',
                'security.view',
            ],
        ],

        'identity_verifier' => [
            'name' => 'Petugas Verifikasi Identitas',
            'permissions' => [
                'identity.verify',
            ],
        ],

        'super_admin' => [
            'name' => 'Super Admin / Developer',
            'permissions' => [
                'users.manage',
                'roles.manage',
                'classes.manage',
                'classes.invite',
                'memberships.review',
                'security.view',
                'audit.view',
                'system.maintain',
            ],
        ],
    ],

];
