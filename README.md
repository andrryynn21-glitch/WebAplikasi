# Pengaduan Mahasiswa: Ruang Dengar

Aplikasi Laravel untuk pengaduan privat, aspirasi bersama, dan absensi dalam lingkup kelas. Frontend Ruang Dengar menggunakan Blade, JavaScript ES modules, Vite, dan Tailwind CSS. Halaman `/` menyajikan aplikasi dengan navigasi hash, dashboard sesuai role, dan integrasi API yang tersedia, bukan lagi halaman welcome Laravel.

- Kontrak endpoint, validasi, scope, dan contoh JSON: [docs/API.md](docs/API.md).
- Setup frontend, route, endpoint yang dipakai, privasi sesi, hasil pengujian, dan batas integrasi: [docs/FRONTEND.md](docs/FRONTEND.md).
- Audit final, perbaikan, hasil E2E nyata, risiko tersisa, dan checklist demo: [docs/AUDIT.md](docs/AUDIT.md).
- Audit aplikasi utama setelah Super Admin aktif, matriks fitur dan prioritas berikutnya: [docs/AUDIT-UTAMA.md](docs/AUDIT-UTAMA.md).
- Hardening perubahan/reset password dan perlindungan Super Admin aktif terakhir: [docs/AUDIT-KREDENSIAL-ADMIN.md](docs/AUDIT-KREDENSIAL-ADMIN.md).
- Panduan pemula, setup empat role, administrasi, branding, dan skenario presentasi: [docs/PANDUAN-ADMIN-DEMO.md](docs/PANDUAN-ADMIN-DEMO.md).
- Persiapan hosting dan batas kesiapan produksi: [docs/DEPLOYMENT-READINESS-RUANG-DENGAR.md](docs/DEPLOYMENT-READINESS-RUANG-DENGAR.md).
- Autentikasi integrasi saat ini: personal access token Laravel Sanctum, prefix `/api/v1`.
- Verifikasi KTM dilakukan manusia melalui aplikasi. Tidak ada integrasi API resmi UNPAM, OCR pembuktian identitas, scraping portal, atau permintaan password SSO kampus.

## Lingkungan

`composer.json` mensyaratkan PHP `^8.3`, Laravel `^13.17`, Sanctum `^4.0`, dan PHPUnit `^12.5.12`, tetapi dependensi terkunci mempunyai minimum efektif **PHP 8.4.1**. Gunakan **PHP 8.5**, Composer 2, serta MySQL/MariaDB yang sudah diuji. Versi PHP CLI dan PHP web server harus sama-sama memenuhi lockfile; jangan memakai `--ignore-platform-reqs`.

Periksa CLI dan ekstensi sebelum instalasi:

```sh
php -v
composer -V
php -m
composer check-platform-reqs
```

PHP membutuhkan ekstensi yang ditentukan dependensi Composer, termasuk `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `ctype`, `tokenizer`, XML/DOM untuk PHPUnit, dan dukungan JSON. **GD wajib tersedia untuk pemrosesan unggahan JPEG/PNG.** PHP CLI dan PHP yang dipilih pada Apache MAMP harus sama-sama memenuhi persyaratan. MAMP lama yang hanya menyediakan PHP 8.2 tidak cukup untuk Laravel 13.

MySQL merupakan target konfigurasi dan test suite. Beberapa query statistik memakai `TIMESTAMPDIFF`; jangan mengganti database pengujian menjadi SQLite dan menganggap perilakunya setara. MariaDB perlu pemeriksaan kompatibilitas terpisah pada versi yang akan digunakan.

Build frontend memerlukan Node/npm yang kompatibel dengan Vite 8 (`^20.19.0 || >=22.12.0`). Font memakai font sistem; tidak ada kebutuhan layanan font eksternal atau framework runtime frontend tambahan.

## Setup Lokal

Jalankan perintah dari root proyek `/Applications/MAMP/htdocs/WebAplikasi`. Perintah berikut adalah petunjuk untuk operator, bukan perintah yang dijalankan ketika menulis dokumentasi ini.

1. Instal dependensi PHP dengan `composer install`.
2. Jika belum ada `.env`, buat dari `.env.example`; jangan menimpa `.env` yang sudah berisi konfigurasi lokal.
3. Sesuaikan koneksi MySQL dan `APP_URL` sebelum migration.
4. Jalankan `php artisan key:generate` hanya pada instalasi baru yang belum mempunyai `APP_KEY`. Jangan mengganti key instalasi yang sudah digunakan tanpa rencana migrasi data/sesi.
5. Jalankan `php artisan config:clear`, `php artisan migrate`, lalu `php artisan db:seed`.
6. Buat Super Admin pertama melalui command interaktif di bagian berikut.
7. Instal dan bangun aset frontend dengan `npm ci && npm run build`.
8. Jalankan `php artisan serve --host=127.0.0.1 --port=8000`, lalu buka `http://127.0.0.1:8000/#/login`, atau gunakan URL Apache MAMP dengan document root `public/`. Untuk HMR, jalankan `npm run dev` pada terminal terpisah dan tetap buka URL Laravel.

Contoh membuat `.env` hanya bila belum tersedia:

```sh
cp -n .env.example .env
```

`.env` dan `.env.example` saat pemeriksaan menunjuk MySQL lokal pada TCP `127.0.0.1:3306`, database `pengaduan_unpam`. Ini berbeda dari MySQL bawaan MAMP yang lazimnya memakai port `8889`.

| Pilihan | Host | Port MySQL | Kredensial | URL HTTP |
| --- | --- | --- | --- | --- |
| MySQL lokal/Homebrew yang dikonfigurasi proyek | `127.0.0.1` | `3306` | Sesuaikan akun lokal; template saat ini memakai `root` dengan password kosong | Artisan: `http://127.0.0.1:8000` |
| MySQL bawaan MAMP | `127.0.0.1` | Biasanya `8889`; periksa Preferences/Ports | Gunakan kredensial yang benar dari konfigurasi MAMP, bukan asumsi kredensial produksi | Apache MAMP biasanya `8888`, atau port aktual |

Contoh `.env` untuk MySQL lokal:

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pengaduan_unpam
DB_USERNAME=akun_lokal_anda
DB_PASSWORD="password_lokal_anda"
FILESYSTEM_DISK=local
SANCTUM_TOKEN_TTL_MINUTES=480
```

Untuk MAMP, ganti `DB_PORT=8889` jika itu port aktual, sesuaikan username/password, dan gunakan `APP_URL` HTTP yang benar. Port MySQL bukan port URL browser. `127.0.0.1` memilih koneksi TCP; `localhost` dapat memilih Unix socket dengan perilaku berbeda. Jangan menjalankan dua server MySQL pada port yang sama.

Buat database kosong menggunakan akun yang memiliki izin, lewat phpMyAdmin MAMP atau klien MySQL. Contoh SQL untuk lingkungan lokal baru:

```sql
CREATE DATABASE pengaduan_unpam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE pengaduan_unpam_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Database `_test` harus terpisah dan boleh dikosongkan oleh pengujian. Jangan memakai database pengaduan riil sebagai database test.

### Apache MAMP

- Atur document root/vhost ke `/Applications/MAMP/htdocs/WebAplikasi/public`, bukan root proyek. Root proyek berisi `.env`, source, dan dokumen privat yang tidak boleh disajikan web.
- Aktifkan URL rewriting sesuai konfigurasi Apache dan `.htaccess` di `public/` agar `/api/v1/...` masuk ke `public/index.php`.
- Pilih PHP 8.5 pada MAMP, dengan minimum efektif 8.4.1 dari lockfile. Bila memakai binary PHP MAMP di terminal, gunakan path binary yang benar dari instalasi lokal; jangan mengasumsikan nomor direktori versi tertentu tersedia.
- Pastikan proses Apache/PHP dapat menulis `storage/` dan `bootstrap/cache/`. Jangan menyelesaikan masalah permission dengan membuka seluruh proyek memakai `chmod 777`.
- Periksa `upload_max_filesize` minimal 8 MB dan `post_max_size` lebih besar dari total multipart. Lima lampiran laporan masing-masing sampai 8 MB memerlukan batas request lebih dari 40 MB ditambah overhead. Batas PHP/web server dapat menolak request sebelum validasi Laravel.
- Endpoint health Laravel adalah `/up`; halaman ini bukan pengujian end-to-end koneksi MySQL, otorisasi, atau penyimpanan KTM.

## Data Awal

`DatabaseSeeder` menjalankan `PermissionSeeder`, `ReportCategorySeeder`, dan `AcademicTermSeeder`. Seeder normal menyinkronkan role/permission, tujuh kategori pengaduan, dan periode awal `2026-1` (Semester Ganjil 2026/2027, 2026-09-01 sampai 2027-01-31). Seeder ini tidak membuat akun dengan password bersama, kelas mahasiswa, keanggotaan, atau laporan privat.

Role adalah `student`, `class_leader`, `lecturer_coordinator`, `identity_verifier`, dan `super_admin`. Katalog kewenangan ada di `config/permissions.php`; pengulangan `db:seed` menyinkronkan permission role dengan katalog tersebut. Perubahan permission manual di database dapat tertimpa oleh sinkronisasi katalog.

### Super Admin Pertama

Command aktual tersedia:

```sh
php artisan app:bootstrap-admin --name="Administrator Sistem" --email="administrator@example.test"
```

Command meminta password dan konfirmasi secara tersembunyi di terminal interaktif. Password minimal 12 karakter, harus mempunyai huruf besar/kecil, angka, dan simbol. Tidak ada opsi `--password`, password bawaan, atau token yang perlu disimpan di README. Command menolak bila role belum di-seed, email telah digunakan, terminal non-interaktif, atau sudah ada akun Super Admin. Command membuat akun baru, bukan menaikkan akun mahasiswa yang sudah ada. Akun admin awal tetap `unverified` dan tidak memperoleh akses isi pengaduan atau KTM karena role admin.

Setiap developer/operator harus memiliki akun sendiri. Setelah bootstrap, akun lain dapat didaftarkan secara individual lalu role-nya ditetapkan admin yang berwenang lewat API. Pengguna tidak dapat mengubah role dirinya sendiri.

### Menyiapkan Kelas

1. Login sebagai admin, buat kelas melalui `POST /api/v1/admin/classes` dengan ID periode akademik aktual.
2. Akun dosen/verifikator didaftarkan secara individual, lalu admin menetapkan role melalui `POST /api/v1/admin/users/{user}/role`.
3. Tetapkan dosen melalui `POST /api/v1/admin/classes/{class}/lecturers`.
4. Admin/dosen yang berwenang menerbitkan undangan melalui `POST /api/v1/classes/{class}/invitations`.
5. Mahasiswa mengunggah KTM; verifikator selain pemilik memutuskan pengajuan. Mahasiswa terverifikasi mengajukan keanggotaan memakai `invitation_code`; admin/dosen/ketua aktif menyetujui permintaan.
6. Setelah calon ketua mempunyai identitas terverifikasi dan keanggotaan aktif pada kelas tersebut, admin menetapkan ketua kelas. Penetapan ini juga memberikan role ketua bila sebelumnya mahasiswa.

ID periode jangan diasumsikan selalu `1`; gunakan katalog `GET /api/v1/academic-terms` untuk ID aktual. Periode berikutnya membutuhkan provisioning operator hingga tersedia endpoint tulis periode akademik. Katalog read-only bukan CRUD periode lengkap.

`classes.join_code` yang dikembalikan ketika membuat kelas bukan kode yang dibaca oleh `/memberships/join`. Alur join memakai baris `class_invitation_codes` dari endpoint undangan terpisah.

`DemoDataSeeder` adalah artefak data fiktif terpisah, tidak dipanggil oleh seeder normal. Seeder menolak produksi serta database yang sudah berisi pengguna/kelas, tidak menghapus penugasan, dan membuat password acak yang tidak dicetak. Jangan gunakan untuk bootstrap/login atau menggantikan alur verifikasi: gunakan command admin dan onboarding normal. Guard seeder bukan alasan menjalankannya pada data nyata.

## Pengujian

Konfigurasi `phpunit.xml` menggunakan MySQL TCP `127.0.0.1:3306`, database `pengaduan_unpam_test`, username `root`, password kosong. Environment pengujian memakai cache/session array dan queue sync. Sesuaikan lingkungan test secara eksplisit bila menggunakan MAMP 8889 atau akun database lain; perubahan `.env` aplikasi tidak otomatis mengubah semua nilai konfigurasi test.

Jangan menjalankan suite sebelum memastikan koneksi test menunjuk database sekali pakai. Test feature memakai reset/migration database; data test dapat dihapus. Backup database pengembangan/produksi tidak menggantikan kewajiban isolasi test.

Perintah yang tersedia:

```sh
php artisan config:clear
php artisan test
composer test
vendor/bin/pint --test
```

`composer test` membersihkan cache konfigurasi lalu menjalankan `php artisan test`; tidak perlu menjalankan kedua command test untuk satu pemeriksaan yang sama. Pemeriksaan satu kelompok dapat memakai `php artisan test --filter=AuthorizationIsolationTest`, atau filter nama test yang benar-benar tersedia di `tests/Feature/`.

Suite di repository mencakup isolasi/otorisasi, workflow laporan, konflik dan privasi Super Admin, aspirasi/security, regresi privasi, moderasi komentar, serta bootstrap admin. Pemeriksaan dijalankan pada backend lokal; hasilnya tidak menggantikan uji beban concurrent, penilaian keamanan independen, atau verifikasi deployment produksi.

Hasil tahap backend sebelum integrasi frontend pada 21 Oktober 2026: `php artisan test --compact`, `php artisan test --compact --order-by=random`, dan `composer test` masing-masing lulus 83 test/497 assertion, sekitar 5 detik per eksekusi. Pint pada file aplikasi yang diubah lulus; opsi `--dirty` tidak tersedia karena workspace bukan repository Git sehingga pemformatan memakai daftar file eksplisit. `php artisan migrate --no-interaction` menyatakan tidak ada migration baru, tiga seeder katalog normal berhasil, seluruh migration berstatus Ran, `composer validate --strict` dan platform requirements valid, serta `composer audit` tidak menemukan advisory. `php artisan route:list --path=api --except-vendor` mencatat 63 route API. HTTP smoke menghasilkan `/up` 200, katalog voice warning 200 dengan rekaman unavailable, dan `/api/v1/auth/me` tanpa header Accept menghasilkan 401 JSON UNAUTHENTICATED. Lingkungan yang diuji adalah PHP 8.5.8/MySQL 26.7.0 pada 127.0.0.1:3306. Hasil ini tidak mencakup Apache MAMP/MySQL MAMP 8889 atau deployment produksi.

Setelah penambahan frontend, `php artisan test --compact` lulus 85 test/507 assertion, `npm test` lulus 17 test, dan build Vite berhasil. Pengujian browser lulus 62 checks dengan Chromium melalui Playwright 1.63 pada viewport 320/390/768/1440, tanpa exception JavaScript. Mayoritas alur browser memakai API mock terisolasi; smoke tambahan tanpa intersepsi memverifikasi halaman login asli dan respons API tanpa token 401. Ini bukan alur E2E mutasi dengan database live. `npm audit` tidak menemukan vulnerability. Perintah `npm run test:browser` membutuhkan Playwright eksternal yang tersedia; konfigurasi dan batas cakupannya dijelaskan di [docs/FRONTEND.md](docs/FRONTEND.md#pengujian).

Audit final 22 Oktober 2026: **114 tes Laravel / 626 assertion**, **42 tes JavaScript**, **62 pemeriksaan browser fixture**, dan **12 tahap E2E dengan backend/MySQL nyata** lulus. E2E memakai `pengaduan_unpam_audit_e2e_test`, akun fiktif dengan password acak dalam memori, tanpa intersepsi API; database dan unggahan referensinya dibersihkan setelah run. Pint, build, validasi Composer, `composer audit`, dan `npm audit` berhasil. Perintah/reproduksi serta batas pengujian terdapat di [docs/AUDIT.md](docs/AUDIT.md); hasil tersebut bukan jaminan kesiapan produksi.

## Inventaris Backend

Modul implementasi dan lokasi handoff:

| Modul | File/lokasi utama |
| --- | --- |
| Routing/error JSON | `routes/api.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Controller.php` |
| Auth/profile | `app/Http/Controllers/Api/V1/AuthController.php`, `app/Http/Requests/Auth/`, `app/Http/Resources/UserResource.php`, `app/Models/User.php` |
| Verifikasi/keanggotaan | `IdentityVerificationController.php`, `MembershipController.php` dalam controller API; `IdentityVerificationService.php`, `MembershipService.php`, `ClassroomService.php` dalam services; request Identity/Classes/Admin dan policy terkait |
| Pengaduan/workflow/banding | `ReportController.php`, `ReportAssignmentController.php`, `AppealController.php`; services ReportService/ReportRoutingService/ReportWorkflowService/ReportAssignmentService/AppealService; `ReportPolicy.php` dan resource Report* |
| Aspirasi/moderasi | `AspirationController.php`, `AspirationService.php`, `ModerationService.php`, `AspirationPolicy.php`, `AspirationCommentPolicy.php`, request Aspirations, model Aspiration* dan ContentFlag/ModerationAction |
| Privacy/security | `AttachmentController.php`, `PrivateStorageService.php`, `NotificationService.php`, `SecurityEventService.php`, `AuditLogService.php`, `VoiceWarningManager.php`; middleware EnsureAccountIsActive/EnsureClassAccess/EnsurePermission; policy Attachment/AppNotification/SecurityEvent |
| Dashboard/admin | `DashboardController.php`, `AdminController.php`, `DashboardService.php`, `StatisticsService.php`, `RoleAssignmentService.php` |
| Setup/data | `app/Console/Commands/BootstrapAdmin.php`, `database/migrations/`, `database/seeders/DatabaseSeeder.php`, `PermissionSeeder.php`, `ReportCategorySeeder.php`, `AcademicTermSeeder.php`; `config/permissions.php`, `config/security.php`, `config/filesystems.php`, `config/sanctum.php`, `.env.example`, `phpunit.xml` |
| Test/regresi | `tests/Feature/AuthorizationIsolationTest.php`, `ConflictAndSuperAdminPrivacyTest.php`, `ReportWorkflowTest.php`, `SecurityAspirationTest.php`, `ReportPrivacyRegressionTest.php`, `BootstrapAdminTest.php`, `NonReportSecurityAuditTest.php`; `tests/Support/Domain.php` |
| Dokumentasi | `README.md` menggantikan skeleton Laravel; `docs/API.md` kontrak endpoint yang diimplementasikan |

Nama file ringkas pada tabel berada di namespace/direktori yang dijelaskan kolom modul; tabel ini inventaris handoff, bukan diff Git (workspace tidak mempunyai repository Git).

## Integrasi Frontend

Frontend tersedia pada `/` dan memerlukan aset Vite yang sudah dibangun atau server development Vite aktif. Gunakan `npm ci && npm run build` untuk aset produksi lokal; `npm run dev` bersifat opsional untuk HMR. API JSON tetap dapat digunakan klien lain tanpa frontend. Inventaris file, halaman, endpoint aktual, serta panduan same-origin/MAMP ada di [docs/FRONTEND.md](docs/FRONTEND.md).

Shell Blade mengisi `data-api-base` dari URL Laravel sehingga API mengikuti origin dan prefiks instalasi; tidak ada konfigurasi API CORS baru atau Inertia. Token Sanctum berada di `sessionStorage` key `rd.token` untuk sesi tab, bukan penyimpanan payload privat. Penyimpanan ini tetap rentan terhadap XSS same-origin. Logout normal mencabut token melalui API sebelum membersihkan sesi; formulir yang belum dikirim tidak di-autosave. Suara keamanan default nonaktif, hanya opt-in dalam memori sesi, dan tidak mengarang rekaman ketika katalog belum menyediakannya.

- Gunakan base URL yang dikonfigurasi, misalnya `http://127.0.0.1:8000/api/v1`. Kirim `Accept: application/json` dan `Authorization: Bearer <token>` untuk endpoint privat.
- Login/registrasi mengembalikan token. Logout mencabut token saat ini; login ulang dengan `device_name` sama mencabut token lama bernama sama. `expires_in_minutes` berasal konfigurasi Sanctum, bukan timestamp kedaluwarsa absolut.
- Pakai JSON untuk request biasa dan `FormData` untuk KTM/lampiran. Jangan menetapkan boundary `Content-Type` multipart secara manual.
- Render `message` dan `error.details` sebagai teks. Tangani 401 sebagai sesi tidak valid, 403 sebagai keputusan akses, 422 sebagai validasi, dan 429 sesuai `Retry-After` atau `meta.retry_after`.
- `data` dapat berupa object, array, atau `null`. Key relasi tertentu dapat tidak hadir; `title: null` pada metadata pengaduan tidak berarti judul kosong. Endpoint file mengembalikan binary, bukan envelope JSON saat sukses.
- Unduh file dengan fetch ber-token lalu Blob. Jangan memakai link publik atau menyimpan token di query string; hyperlink biasa tidak menyertakan header Bearer.
- Jangan memasukkan teks pengguna langsung ke `innerHTML`. Aspirasi/komentar disanitasi sebagai teks oleh backend, tetapi frontend tetap wajib output escaping; judul dan isi pengaduan privat juga merupakan teks tidak tepercaya.
- Preferensikan same-origin atau proxy development. Integrasi lintas origin membutuhkan konfigurasi CORS yang sesuai; daftar stateful domain Sanctum saja bukan konfigurasi CORS dan bukan bukti flow cookie SPA telah diimplementasikan.
- Tidak ada refresh token, password-reset API, email-verification API, websocket, atau push notification dalam kontrak saat ini. Notifikasi in-app dapat diambil ulang secara berkala.
- Audio hanya presentasi. `sound` adalah nama file/`null`, bukan URL rekaman yang dijamin tersedia. Kegagalan playback tidak boleh mengubah keputusan backend.

## Model Dan Integritas

| Kelompok | Relasi dan aturan penting |
| --- | --- |
| Akun | Satu `role_id` per user; role mempunyai banyak permission melalui pivot. Password di-hash, password/remember token tidak diserialisasi. `role_id`, status identitas, dan aktif/nonaktif tidak fillable melalui profile/registrasi. NIM adalah string input, bukan pembuktian kampus dan bukan kunci unik global. |
| Akademik | Kelas milik periode akademik; kombinasi `(academic_term_id, code)` unik. Keanggotaan unik per `(user_id, class_id)`. Persetujuan identitas tidak otomatis menyetujui keanggotaan. |
| Penugasan kelas | Ketua/dosen memiliki record dengan waktu mulai/akhir. Ketua aktif kelas dijaga unique `active_class_id`, yang diisi model ketika menyimpan record aktif. Penetapan ketua memerlukan anggota aktif terverifikasi dan mengakhiri penugasan lama dalam transaksi. |
| Undangan | Kode unik, kuota pemakaian, kedaluwarsa, dan pencabutan. Konsumsi kode terkunci dalam transaksi; kode bukan bukti identitas atau persetujuan kelas. |
| Verifikasi | User mempunyai riwayat KTM; pengajuan/putusan tersimpan terpisah dari status user. Pemilik dapat mengunduh berkasnya, tetapi tidak dapat menyetujui sendiri. Path/checksum KTM disembunyikan dari serialization. |
| Pengaduan | `public_ref` acak berformat `PGD-` + 10 karakter, unik. `reports` menyimpan status/judul/metadata; isi berada pada `report_private_details` satu-ke-satu. Pemilik, kategori, dan kelas menggunakan FK dengan restriksi penghapusan yang relevan. |
| Workflow | State machine memvalidasi transisi; service memakai transaksi dan row lock untuk perubahan multi-langkah. History status append-only pada operasi Eloquent. Penugasan lama dicabut, bukan hilang; reviewer banding mendapat jenis penugasan terpisah. |
| Banding/konflik | Banding mempunyai record, reviewer, hasil, snapshot status asal; satu banding terbuka dijaga alur service dengan lock laporan. Record konflik unik per laporan/user; `declared`/`confirmed` memblokir penanganan, `cleared` tidak. Deteksi pihak terlapor juga memakai pencocokan nama dalam input. |
| Aspirasi | Terpisah dari pengaduan privat; API tidak menerima pengubahan laporan menjadi aspirasi. Upvote unik `(aspiration_id, user_id)` dan pembatalan menghapus baris; counter diperbarui dalam transaksi. Parent komentar harus berasal dari aspirasi yang sama dan terlihat. |
| Moderasi | Flag unik per konten/pelapor; tindakan dicatat. Moderator tidak memoderasi sendiri; `flag_id` harus terkait konten yang sama. Route flag/moderasi komentar terikat aspirasi induk; pergantian ID komentar tidak boleh membuka konten kelas lain. |
| File | Attachment memakai relasi polymorphic; FK tidak dapat menjamin keberadaan semua target polymorphic, sehingga policy dan service tetap dibutuhkan. Path/checksum attachment tidak dikirim API. |
| Audit/security | Audit, security event, dan notifikasi menyimpan metadata/referensi aman, bukan seluruh isi privat. Security event tetap mengandung PII teknis terbatas seperti IP/email aktor untuk petugas berwenang. |

Unique aktif ketua dan aturan append-only model bukan perlindungan terhadap operator yang mengubah SQL langsung. Aturan satu keanggotaan aktif dan satu banding terbuka jangan disamakan dengan unique constraint database yang tidak ada di migration awal; keduanya juga bergantung pada service/lock. Foreign key, indeks, dan transaksi harus tetap aktif. Jangan mengubah status/role/penugasan lewat SQL atau mass assignment untuk melewati workflow.

## Penyimpanan Dan Privasi

KTM dan lampiran menggunakan disk `private` dengan root `storage/app/private`, nama berkas acak, validasi MIME terdeteksi server, serta batas ukuran. Unduhan harus melalui API yang memeriksa policy; respons file menggunakan `Cache-Control: private, no-store, max-age=0` dan `X-Content-Type-Options: nosniff`.

- Jangan menghubungkan `storage/app/private` ke `public/`, jangan memakai `Storage::url` untuk identitas, dan jangan menambahkan URL KTM publik. `php artisan storage:link` hanya untuk disk public, bukan kebutuhan KTM/pengaduan.
- Admin teknis tidak otomatis dapat membaca isi privat atau KTM. Dosen/ketua baru dapat membaca isi laporan jika penugasannya aktif, memenuhi scope/permission, dan bebas konflik. Judul bebas-teks juga dirahasiakan untuk metadata yang tidak punya izin baca.
- Pelapor dapat membaca laporan sendiri; catatan internal penangan tidak dikirim kepada pelapor walaupun pelapor mempunyai role ketua kelas.
- Aspirasi/komentar adalah konten bersama dalam kelas dan menampilkan identitas penulis terbatas (`id`, `name`). Itu bukan mode anonim, dan bukan forum publik lintas kelas.
- Notifikasi tidak memberikan hak akses. URL/referensi di notifikasi tetap diperiksa kembali; notifikasi kelas mengikuti scope akses saat dibaca, dengan pengecualian pemberitahuan keputusan keanggotaan untuk penerimanya.
- Pemisahan tabel dan disk bukan enkripsi data-at-rest. Administrator server/database, backup, atau host yang disusupi masih dapat membaca data. Siapkan izin server, TLS, backup terenkripsi, kontrol operator, dan kebijakan retensi sebelum menangani data nyata.
- Jangan menjanjikan anonimitas mutlak: nama, isi, pihak terlibat, filename, waktu, dan konteks kelas dapat mengungkap identitas tidak langsung. Penangan berwenang menerima identitas pelapor untuk penanganan.
- Validasi MIME/checksum bukan antivirus atau jaminan dokumen asli. Belum tersedia pemindai malware, OCR terverifikasi, auto-redaction, atau kebijakan penghapusan KTM otomatis.
- Gunakan `APP_DEBUG=false`, credential DB khusus, dan `LOG_LEVEL` sesuai produksi. Jangan mengirim token/password/isi laporan ke log browser, analytics, error monitoring, atau URL.

## Batasan Saat Ini

- Frontend sudah memakai API yang tersedia. E2E nyata memverifikasi alur utama pada server Artisan dan MySQL lokal terisolasi, tetapi bukan seluruh kombinasi UI atau deployment Apache MAMP/produksi. Integrasi resmi kampus, SSO, dan flow verifikasi resmi UNPAM belum ada; service manual belum menjadi adapter resmi kampus.
- Belum ada endpoint tulis periode akademik, pencabutan dosen/keanggotaan/undangan, manajemen konflik kepentingan, daftar banding terpisah, ekspor, atau feed timeline/update berpagination terpisah.
- Detail laporan membatasi timeline dan update sampai 100 record; komentar aspirasi sampai 200 record pertama. Klien tidak dapat mengambil seluruh riwayat panjang melalui pagination endpoint tersebut.
- Katalog audio masih placeholder dan tidak ada endpoint penyajian rekaman. Backend tetap mengotorisasi tanpa audio.
- Semua endpoint terautentikasi memakai limiter `api`120 request/menit per pengguna, ditambah limit route khusus dan limit login. Cooldown `repeated_denials` diterapkan middleware aktif pada endpoint terautentikasi dan mengembalikan 429; logout/logout-all dikecualikan dari cooldown, tetapi tetap mengikuti limiter API. Endpoint publik tidak mengikuti middleware cooldown ini.
- Permission role saja bukan jaminan akun dapat memakai kelas: role, verifikasi, keanggotaan/penugasan aktif, kepemilikan, status, dan konflik diperiksa berbeda menurut endpoint.
- Beberapa aturan integritas hanya berlaku lewat model/service, bukan trigger database. Penanganan request bersamaan dan perubahan operator langsung tetap membutuhkan pengujian pada engine database aktual.

Nama profil tidak dapat diganti ketika identitas pending/verified atau pengguna menjadi penangan ketua/dosen; persetujuan identitas menyelaraskan nama dengan `full_name` yang diperiksa. Telepon tetap dapat diubah. Catatan verifikasi/moderasi tidak disalin ke audit administratif; riwayat audit lama disaring pada API, bukan dihapus dari database/backup. Tidak ada penghapusan massal akhir semester.

Dokumentasi mencatat kontrak source yang tersedia dan tidak mengklaim semua kriteria produksi `Promt1.md` telah diverifikasi. Gap kontrak yang masih relevan untuk integrasi dicantumkan pada bagian akhir [docs/API.md](docs/API.md).
