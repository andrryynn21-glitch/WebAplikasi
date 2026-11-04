# Audit Hardening Terpadu

Tanggal: 22 Oktober 2026. Pekerjaan mengikuti `Promt10.md` dan berhenti pada batas aman ketika Tahap C memerlukan keputusan kebijakan identitas/migration.

## Tahap A — Privasi Notifikasi

**Ditutup untuk producer berbasis kelas.** `NotificationService::toClassLeaders()` dan `toClassLecturers()` sekarang selalu menambahkan `class_id` otoritatif dari `ClassRoom`, sehingga routing laporan dan pengajuan banding tidak bergantung pada caller untuk scope. Payload tetap melewati allow-list scalar dan tidak memuat isi laporan/KTM/token/password.

Regression `IntegratedHardeningTest::test_real_routing_notification_is_class_scoped_and_hidden_after_assignment_revocation` memakai producer routing nyata, memastikan class_id ada, description tidak ada, notifikasi terlihat saat assignment aktif dan hilang setelah assignment berakhir.

## Tahap B — Konsistensi ID

**Ditutup sebagian yang kompatibel.** Binding `{report}` sekarang hanya menerima `public_ref`; fallback ID numerik dihapus. Frontend sudah memakai public_ref. Regression membuktikan numeric ID404 dan public_ref milik sendiri200.

Appeal/attachment masih memakai ID numerik karena model belum mempunyai public identifier. Mengubahnya membutuhkan migration, backfill, perubahan notifikasi/frontend/kontrak. Itu tidak dipaksakan; existence concealment 403/404 masih terbuka.

## Tahap C — Integritas Identitas

**Dihentikan pada batas aman.** NIM tidak unik dan checksum KTM belum dipakai duplicate detection, tetapi kebijakan belum menentukan global/per-class uniqueness, mahasiswa tanpa NIM, perubahan/re-enrollment, koreksi verifier, atau checksum dokumen revisi. Tidak dibuat unique constraint/migration. Diperlukan keputusan bisnis tertulis, audit duplicate dry-run, desain merge/reject, baru migration/test concurrency.

## Tahap D — Lifecycle Akademik

**Belum dimulai karena Tahap C berhenti.** Sesuai urutan prompt, CRUD AcademicTerm, class activation, list/revoke assignment/member/invitation, duplicate lecturer guard, dan conflict lifecycle tidak dipaksakan. Baseline tetap di `docs/AUDIT-READINESS-AKADEMIK.md`.

## Test, Dampak, Dan Risiko

- Tidak ada migration baru. Kontrak laporan berubah kompatibel: hanya public_ref.
- Test mutasi hanya memakai `pengaduan_unpam_test`; E2E live tidak dijalankan pada hardening parsial ini.
- Hasil aktual: 127 tes Laravel/702 assertion, 44 tes JavaScript, 62 browser checks fixture, build Vite, Pint targeted, composer audit dan npm audit lulus. Browser fixture bukan E2E live.
- Risiko terbuka: ID numerik appeal/attachment, kebijakan duplicate identity, dan seluruh Tahap D.
- Langkah lanjut: setujui kebijakan NIM/KTM; threat-model public identifier/concealment appeal/attachment; kemudian lanjut Tahap C dan D bertahap dengan migration review/backup.

File terdampak: `app/Services/NotificationService.php`, `routes/api.php`, `tests/Feature/IntegratedHardeningTest.php`, `docs/API.md`, laporan ini, serta aset build ber-hash hasil Vite. Tidak ada `.env`, APP_KEY, migration, akun, atau konfigurasi produksi yang diubah.
