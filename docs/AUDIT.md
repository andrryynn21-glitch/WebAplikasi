# Audit Final Ruang Dengar

Tanggal penyelesaian: 22 Oktober 2026. Lingkup: aplikasi lokal sesuai `Promt1.md`, `Promt2.md`, dan audit `Promt3.md`. Audit mencakup source, policy/service, kontrak API, frontend, migration, konfigurasi, log terklasifikasi, pengujian regresi, dan E2E dengan data fiktif. Workspace bukan repository Git; `git status --short` gagal karena `.git` tidak tersedia. Inventaris berikut bukan diff Git atau bukti review seluruh kombinasi perilaku.

## Kesimpulan

**Layak untuk demonstrasi lokal terkontrol dengan data fiktif pada lingkungan yang diuji. Belum dinyatakan siap produksi/data mahasiswa nyata.** Tidak ada temuan P0 yang berhasil dibuktikan dalam lingkup pengujian. Temuan P1 terkonfirmasi di bawah telah diperbaiki dan mempunyai regresi. Keamanan mutlak, anonimitas mutlak, kepatuhan hukum, maupun ketahanan terhadap operator SQL/host yang disusupi tidak dijanjikan.

Baseline awal audit: 85 tes Laravel/507 assertion, 17 tes JavaScript, 62 pemeriksaan browser fixture lulus. Regresi baru kemudian membuktikan bug yang tidak dicakup baseline tersebut. Sebelum penyelesaian pada 22 Oktober, 104 dari 112 tes Laravel lulus dan delapan kasus belum diperbaiki; seluruhnya kemudian diperbaiki. Hasil final adalah 114 tes Laravel/626 assertion, 42 tes JavaScript, 62 pemeriksaan browser, dan 12 tahap E2E nyata lulus.

## Temuan Dan Perbaikan

| ID | Prioritas | Temuan nyata dan dampak | Perbaikan/bukti | Status |
| --- | --- | --- | --- | --- |
| A01 | P1 | Role ketua yang masih tersimpan setelah penugasan dicabut tetap mendapat daftar metadata, dashboard, dan statistik kelas. Policy detail sudah menolak, tetapi list/agregasi tidak konsisten. | `User::managedClassIds()` membedakan penugasan aktif dari keanggotaan mahasiswa. Scope dipakai pada daftar, dashboard, statistik; penugasan masa depan tidak dipakai routing. Regresi revoked/future pada `FinalReportAuditTest` awalnya gagal lalu lulus. | Diperbaiki |
| A02 | P1 | Nama profil terverifikasi/penangan dapat diganti, sehingga identitas yang ditampilkan tidak terikat nama yang diperiksa dan pencocokan nama pihak terlapor dapat dilewati. Persetujuan KTM sebelumnya hanya menyelaraskan NIM. | PATCH profil mengunci nama pending/verified serta ketua/dosen di dalam transaksi; persetujuan menyalin reviewed `full_name`. Frontend readonly dan tidak mengirim nama terkunci. Regresi verified/pending/privileged-name/approval lulus. | Diperbaiki; heuristik nama tetap terbatas |
| A03 | P1 | Filter key-blacklist audit masih menyalin free-text alasan verifikasi/moderasi dan nested scalar kepada pembaca audit administratif. Ini dapat membuka catatan privat tanpa hak KTM. | Allow-list skalar saat write dan read; catatan privat tidak dipindahkan. API menyaring log historis tanpa mengubah baris append-only. Canary alasan identitas diuji lewat `GET /admin/audit-logs`. | Diperbaiki pada API/write baru |
| A04 | P1 | Seeder demo dapat dipanggil pada produksi/database berisi akun, mereset kredensial/role akun yang cocok, memakai password bersama, dan menghapus penugasan aktif. | Tolak selain local/testing atau jika users/classes sudah ada; hilangkan password bersama dan operasi delete history. Tes production/populated refusal lulus. Seeder normal tetap tidak memanggilnya. | Diperbaiki; bukan bootstrap login |
| A05 | P1 | Penugasan primary biasa ketika banding terbuka dapat membuat reviewer aktif dianggap mantan penangan, sehingga keputusan independen menjadi tidak mungkin. UI melarang tetapi backend sebelumnya menerima. | Service menolak penugasan biasa saat appeal_pending/open appeal dengan 422. Tes HTTP awal menerima 201, setelah perbaikan 422; reviewer tetap layak. | Diperbaiki |
| A06 | P2 | Klarifikasi dapat ditambahkan pada status in_progress, tetapi pelapor hanya bisa menjawab saat needs_clarification. Balasan update biasa juga dapat menghabiskan permintaan yang belum dijawab. | Policy membatasi under_review/needs_clarification; hanya clarification_response menutup pertanyaan. Frontend sama. Dua regresi red-to-green lulus. | Diperbaiki |
| A07 | P2 | Input tag-only lolos panjang sebelum sanitasi tetapi menjadi kosong/terlalu pendek ketika disimpan. | Validasi ulang teks sanitized title/body/comment dengan batas yang sama; 422 tanpa side effect. Empat kasus regresi lulus. | Diperbaiki |
| A08 | P2 | Pembatalan upvote memakai model stale; balasan komentar memeriksa parent sebelum menunggu lock. Moderasi yang menyela dapat membuat aksi terhadap konten tersembunyi diterima. | Lock aspiration lalu recheck policy/parent; urutan lock selaras moderasi. Interleaving deterministik dan stale-model regression lulus. Dua request dukungan bersamaan pada E2E menghasilkan satu upvote/counter. | Diperbaiki; bukan stress test |
| A09 | P2 | Upload privat tersimpan sebelum metadata; kegagalan create metadata atau save user setelahnya meninggalkan file KTM/lampiran orphan meskipun SQL rollback. | Compensating deletion di storage service dan outer identity transaction. Tiga fault-injection tests membuktikan file dihapus serta SQL rollback. | Diperbaiki untuk jalur exception yang diuji |
| A10 | P2 | Detail laporan memuat seluruh relasi update/history/assignment dahulu, lalu resource meminta ulang feed terbatas; batas 100 output tidak membatasi query awal. | Hapus eager loading duplikat, tetap fetch feed terbatas di resource. Tes query listener memastikan report_updates tidak diambil tanpa limit. | Diperbaiki; riwayat panjang tetap terbatas API |
| A11 | P2 | Abort saat parsing JSON/Blob tidak menghentikan efek respons lama; logout gagal meninggalkan state privat; hasil logout lama dapat menghapus sesi pengganti; BFCache percaya user cache. | Recheck abort/token setelah parsing, clear lokal dengan warning pada logout gagal, captured-token guard, revalidasi auth pada BFCache. Tes Node menjalankan callback implementasi aktual. | Diperbaiki |
| A12 | P2 | Validasi path menolak query sah `..` tetapi menerima encoded traversal; audio backslash dapat dinormalisasi browser menjadi origin lain. Response media/redirect juga kurang dibatasi. | Pisahkan path/query, same-origin canonical base, redirect:error, validasi media JSON/binary, URL audio dan boolean strict. Tidak ada klaim token theft yang berhasil dieksploitasi. | Diperbaiki/hardening |
| A13 | P2 | Limiter numerik route berbagi signature antar tindakan. Lima login role pada E2E membuat registrasi pertama terkena 429. | Tambah prefiks tindakan per limiter tanpa menaikkan batas. Regresi empat login lalu registrasi pertama 201; rerun E2E lulus. Limiter API global tetap bersama. | Diperbaiki |
| A14 | P3 | Pemeriksaan Pint keseluruhan menemukan import, indentasi, dan format kosong tidak konsisten. | Format otomatis pada source PHP; tidak mengubah perilaku. Suite diperiksa ulang sesudah formatting. | Diperbaiki |

P0 berarti bypass autentikasi/kebocoran berat/pengambilalihan akses, P1 berarti kontrol akses/workflow/integritas penting, P2 berarti fungsi/konsistensi/performa signifikan, P3 berarti kosmetik. Pengelompokan ini menilai konteks aplikasi, bukan skor CVSS tersertifikasi.

## Hasil Pengujian Aktual

| Perintah/pemeriksaan yang dijalankan | Hasil |
| --- | --- |
| `php artisan test --compact` | 114 passed, 626 assertion, sekitar 7,6 detik pada run final. |
| `php artisan test --compact --order-by=random` | 114 passed, 626 assertion pada pemeriksaan final sesudah penambahan kasus banding. |
| `npm test` | 42 passed, 0 failed. |
| `npm run build` | Berhasil; JS sekitar 109,43 kB/32,09 kB gzip, CSS 31,48 kB/8,47 kB gzip. Ukuran bukan target permanen. |
| `npm run test:browser` | 62 checks passed, Chromium via Playwright 1.63, 320/390/768/1440 px, tanpa exception JavaScript. Mayoritas API berupa fixture. |
| `npm run test:live` | 12 tahap passed, backend Laravel/MySQL nyata, tanpa mock/intersepsi API, tanpa exception browser; cleanup database dan referensi file berhasil. |
| `vendor/bin/pint --test app routes database tests --format agent` | Lulus setelah perapian otomatis. |
| `composer validate --strict` | Valid. |
| `composer audit --no-interaction` | Tidak ada advisory yang dilaporkan. |
| `npm audit` | 0 vulnerability yang dilaporkan. |
| `php tests/Support/audit-diagnostics.php` | Tidak ada pola secret yang terdeteksi dalam direktori source yang dipindai; tidak ada symlink dalam public. Nilai secret/log body tidak dicetak. |
| `git status --short` | Tidak dapat dijalankan sebagai pemeriksaan perubahan: workspace bukan repo Git. |

Lingkungan lokal: PHP 8.5.8, Node 26.4.0, Laravel 13, MySQL lokal TCP 3306. Suite feature memakai `pengaduan_unpam_test`; E2E memakai `pengaduan_unpam_audit_e2e_test`. Database aplikasi `pengaduan_unpam` tidak di-reset oleh audit. Akun/kredensial kampus nyata tidak digunakan.

Dua belas tahap E2E: login role dan verifikasi database terisolasi; registrasi frontend; upload/download KTM dan persetujuan petugas independen; undangan dan persetujuan keanggotaan; pengaduan privat plus file TXT nyata; klarifikasi UI; penolakan dan reviewer banding berbeda; penugasan/pengalihan/penyelesaian via API; aspirasi UI, duplicate upvote concurrent dan moderasi komentar; penolakan lintas kelas/admin terhadap pengaduan/KTM/lampiran; cooldown dan logout saat cooldown tanpa audio; notifikasi read-all dan pencabutan token logout. Sebagian tindakan lanjutan memakai API langsung, bukan klik UI seluruhnya.

## Log Dan Konfigurasi

Diagnostic membaca header log secara streaming, mengeluarkan kategori/jumlah/tanggal saja. Snapshot 22 Oktober mencakup rentang sejak 18 Oktober: satu database exception, historical resource import/workflow method/missing login route, banyak audit-failure sintetis yang sengaja diinjeksi test, serta dua entry lain (satu RuntimeException, satu ERROR umum). Entry lain tidak dinyatakan outage atau serangan tanpa investigasi tambahan; isi sensitif tidak ditampilkan. Log lama tidak dihapus agar bukti tidak hilang.

Pemindaian secret adalah pencarian pola terbatas pada `app`, `config`, `database`, `resources`, dan `routes`, bukan jaminan tidak ada secret di seluruh mesin/backup/history. `.env` nyata tidak dicetak, vendor/node_modules dan payload log tidak masuk output. Awalnya pola password literal menemukan cast `password => hashed`, yang merupakan false positive; parser mengecualikannya. Password bersama seeder sudah dihilangkan.

Directory public tidak mempunyai symlink ke private storage. Directory metadata tidak menunjukkan permission 777. Ini bukan bukti document root Apache MAMP/produksi sudah benar. `APP_DEBUG=true`, akun root lokal, dan single log merupakan konfigurasi pengembangan; operator wajib hardening sebelum data nyata. Tidak ada automatic purge semester atau perubahan jadwal retensi yang dikarang tanpa kebijakan pemilik data.

## File Yang Diubah

Perubahan fungsional audit:

```text
app/Models/User.php
app/Models/ClassRoom.php
app/Http/Controllers/Api/V1/AuthController.php
app/Http/Controllers/Api/V1/AdminController.php
app/Http/Controllers/Api/V1/ReportController.php
app/Policies/ReportPolicy.php
app/Services/AuditLogService.php
app/Services/AspirationService.php
app/Services/DashboardService.php
app/Services/StatisticsService.php
app/Services/IdentityVerificationService.php
app/Services/PrivateStorageService.php
app/Services/ReportService.php
app/Services/ReportRoutingService.php
app/Services/ReportAssignmentService.php
database/seeders/DemoDataSeeder.php
routes/api.php
resources/js/api.js
resources/js/app.js
resources/js/pages/account.js
resources/js/pages/reports.js
package.json
tests/Feature/FinalNonReportAuditTest.php
tests/Feature/FinalReportAuditTest.php
tests/Feature/NonReportSecurityAuditTest.php
tests/Frontend/final-audit.test.js
tests/Frontend/live-audit.mjs
tests/Support/live-audit.php
tests/Support/audit-diagnostics.php
README.md
docs/API.md
docs/FRONTEND.md
docs/AUDIT.md
public/build/manifest.json dan aset ber-hash hasil Vite
```

Formatting-only tambahan:

```text
app/Models/Report.php
app/Http/Middleware/EnsurePermission.php
app/Http/Requests/Aspirations/StoreAspirationRequest.php
app/Http/Controllers/Controller.php
app/Http/Controllers/Api/V1/VoiceWarningController.php
app/Http/Controllers/Api/V1/DashboardController.php
app/Http/Controllers/Api/V1/ReportAssignmentController.php
app/Http/Controllers/Api/V1/IdentityVerificationController.php
app/Http/Controllers/Api/V1/AttachmentController.php
app/Services/VoiceWarningManager.php
tests/Support/Domain.php
tests/Feature/AuthorizationIsolationTest.php
tests/Feature/ConflictAndSuperAdminPrivacyTest.php
tests/Feature/SecurityAspirationTest.php
```

Inventaris berdasarkan tindakan sesi, bukan pembandingan commit; perubahan pengguna tidak di-revert. Kontrak berubah terkontrol: profile name lock/approval canonical name, scope penugasan aktif, klarifikasi dua status, assignment saat banding 422, sanitized text revalidation, metadata audit allow-list, dan limiter khusus independen. Endpoint/shape utama tetap sama; `API.md` dan frontend diselaraskan.

## Risiko Tersisa

| Prioritas | Risiko/keterbatasan | Mitigasi atau tindak lanjut |
| --- | --- | --- |
| P2 operasional | Tidak ada kebijakan retensi per jenis data yang disetujui; single log lokal tidak otomatis rotate. | Tetapkan retensi KTM/evidence/security/log/backup, legal hold, proses otorisasi penghapusan, dan uji restore; jangan purge semua semester. |
| P2 operasional | Append-only hanya melalui Eloquent; SQL bulk/operator/host/backup dapat mengubah/membaca data. File/database tidak otomatis encrypted oleh APP_KEY. | Dedicated least-privilege DB, ACL host, encryption-at-rest/backup, TLS, audit archive independen bila diperlukan. |
| P2 | Deteksi pihak terlapor memakai nama/free-text dan conflict record; homonim, alias, salah eja, atau staff baru dapat tidak terdeteksi. Nama lock menutup bypass profil yang diuji, bukan seluruh kasus identitas. | Review manusia wajib untuk kasus ambigu/sensitif, provisioning reviewer independen, kemudian desain structured involved-user references dan workflow conflict yang diaudit. |
| P2 | Tidak ada malware scanning, rekonsiliasi orphan akibat process kill/power failure, atau redaksi otomatis. | Scanner/quarantine dan rekonsiliasi referensi dengan dry-run serta prosedur privasi; compensating exception cleanup tidak menjamin crash atomicity disk/SQL. |
| P2 | Riwayat laporan dibatasi 100, komentar 200; API lain belum ada untuk seluruh history/daftar banding/search staff/retensi/config/pencabutan tertentu. | Tambahkan endpoint scoped dengan contract/regression sebelum memperluas frontend; jangan membuat fallback data palsu. |
| P2 | Token sessionStorage rentan XSS same-origin; offline logout hanya menjamin clear lokal, bukan revoke server. | HTTPS, escaping/CSP sesuai deployment, hindari script pihak ketiga, expiry/revoke perangkat; pertimbangkan cookie flow resmi dengan CSRF bila kelak diubah. |
| P2 belum diuji | Apache/PHP/MySQL MAMP, produksi, browser non-Chromium, screen reader/kontras menyeluruh, stress/race lintas worker skala besar. | Uji pada target deployment; E2E concurrent dua request bukan load test/sertifikasi. |
| P3 | Audio keamanan masih placeholder; tema visual final belum ditetapkan. | Guard tetap bekerja lewat backend dan visual; sediakan rekaman resmi serta konfigurasi penyajian jika disetujui. |

Tidak ada temuan P0/P1 terkonfirmasi yang dibiarkan tanpa perbaikan dalam lingkup audit ini. Risiko operasional P2 harus dinilai ulang terhadap konteks produksi; prioritas dapat meningkat bila data nyata/host publik digunakan.

## Menjalankan Dan Menguji

Instalasi dan provisioning admin/kelas ada di README. Untuk aplikasi lokal biasa:

```sh
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Buka `http://127.0.0.1:8000/#/login` dengan akun pribadi. Tidak ada kredensial demo bersama. Test PHP harus menunjuk database sekali pakai sesuai phpunit.xml:

```sh
php artisan test --compact
npm test
vendor/bin/pint --test app routes database tests
```

Browser fixture memerlukan Playwright eksternal dan server8000:

```sh
PLAYWRIGHT_MODULE="/path/node_modules/playwright" FRONTEND_URL="http://127.0.0.1:8000" npm run test:browser
```

E2E nyata, server terpisah terlebih dahulu:

```sh
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=pengaduan_unpam_audit_e2e_test PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8017 --no-reload
```

Terminal lain:

```sh
PLAYWRIGHT_MODULE="/path/node_modules/playwright" npm run test:live
```

`PLAYWRIGHT_EXECUTABLE` opsional bila discovery browser tidak cocok. DB user memerlukan create/drop schema. Helper menolak database selain nama audit yang tetap, konfigurasi cached, serta database audit yang sudah ada. Jangan jalankan helper prepare langsung: credential harus dikonsumsi runner di memori. Setelah abort/kill, periksa database audit terpisah dan jalankan cleanup eksplisit bila memang hasil audit yang boleh dihapus:

```sh
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=pengaduan_unpam_audit_e2e_test php tests/Support/live-audit.php cleanup
```

Hentikan server audit setelah selesai. Helper cleanup hanya menghapus file yang direferensikan database audit dan schema audit, bukan seluruh private storage/database aplikasi. Jangan menyimpan data berharga pada schema audit. Tidak ada commit/push dibuat karena tidak diminta dan workspace bukan Git.

## Checklist Demo

- [x] Frontend dibangun dan login/registrasi nyata berjalan.
- [x] KTM privat dan keputusan identitas independen diuji.
- [x] Undangan serta persetujuan kelas diuji.
- [x] Pengaduan, lampiran, klarifikasi, timeline, penugasan, pengalihan, penolakan, banding berbeda, dan penyelesaian diuji.
- [x] Aspirasi/comment/upvote/flag/moderation serta counter diuji.
- [x] Isolasi kelas, larangan mahasiswa lain/admin membaca privat, dan scope penugasan dicabut mempunyai regresi.
- [x] Notifikasi, logout, cooldown tanpa audio, dan sesi/frontend error diuji.
- [x] Test kritis lulus dan kontrak diperbarui.
- [x] Data fiktif/database/files E2E dibersihkan.
- [x] Risiko belum selesai serta batas demo lokal dinyatakan.
- [ ] Deployment Apache MAMP/produksi, data nyata, retensi hukum, backup/restore dan hardening operator disetujui serta diverifikasi.

Rekomendasi berikutnya: prioritaskan hardening target deployment dan kebijakan privasi/retensi, kemudian structured conflict workflow/endpoint scoped yang masih kurang, test aksesibilitas serta load/concurrency, baru penyempurnaan tema dan audio. Jangan mengartikan checklist demo lokal sebagai sertifikat keamanan produksi.
