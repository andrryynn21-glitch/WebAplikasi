# Audit Aplikasi Utama Ruang Dengar

Tanggal audit: 22 Oktober 2026. Lingkup mengikuti `Promt7.md`. Pemeriksaan read-only aplikasi utama dilakukan terlebih dahulu; tidak ada akun/data massal dibuat, tidak ada migration/reset/drop dijalankan, dan tidak ada data pengguna utama diubah. Pengujian mutasi hanya memakai `pengaduan_unpam_test` yang telah diverifikasi terpisah.

## A. Kondisi Database Dan Konfigurasi Utama

- Laravel default, koneksi SQL langsung, dan server aktif menunjuk MySQL `127.0.0.1:3306`, database `pengaduan_unpam`; environment `local`, APP_URL `http://localhost:8000`, cache/session database, config tidak cached.
- Terdapat 39 tabel dan sembilan migration berstatus Ran. Tidak ada migration baru yang dijalankan.
- Agregat utama: 1 pengguna aktif ber-role `super_admin`, 1 token aktif, 1 pengguna memiliki `last_login_at`, 0 kelas, 0 membership, 0 pengajuan identitas, 0 laporan, 0 aspirasi, 1 audit log, dan 1 security event. Identitas, email, token, password, isi log, dan data pribadi tidak dicetak.
- Audit awal Super Admin melalui bootstrap tercatat sebagai `role_assigned`. Security event yang ada berkategori `suspicious_login`; source hanya membuat kategori itu ketika kredensial salah/percobaan berlebih. Audit ini tidak menganggapnya serangan tanpa konteks tambahan.
- Database `pengaduan_unpam_demo` tetap terpisah dan tidak dihapus/disalin. Server demo port 8010 dihentikan; server utama port 8000 tetap aktif sebagai proses terkelola.
- Login sukses didukung bukti agregat `last_login_at` dan token aktif serta pernyataan pengguna. Audit tidak memakai/mengambil token plaintext, sehingga tidak menjalankan request HTTP autentikasi memakai akun utama.

## B. Fitur Super Admin Yang Diuji

| Fitur | Backend nyata | UI | Bukti pengujian | Status/batas |
| --- | --- | --- | --- | --- |
| Dashboard | `DashboardController` + `DashboardService::forSuperAdmin` | `app.js::dashboard()` | Service dijalankan read-only memakai akun utama: system/security tersedia, tidak ada key reports. Suite dan browser fixture lulus. | Berfungsi; angka utama saat audit: 1 pengguna aktif, 0 kelas, 0 verifikasi pending, 1 event 24 jam. |
| Kelola kelas | GET classes/terms; POST/PATCH class; assign/revoke leader; assign lecturer; invitation; membership review | `management.js::classesPage()` | Route/policy/service dibaca; suite menguji create/update/assignment/review. Tes eksplisit role dosen → assignment kelas lulus. | Berfungsi untuk yang tersedia. Tidak ada create/update periode, daftar assignment aktif, route revoke dosen, revoke invitation/member. |
| Pengguna & role | List/filter, role, suspend/reactivate | `usersPage()` | Dua admin independen diuji pada DB test; self-role escalation/registrasi role injection diuji; audit log diverifikasi. | Berfungsi. Database utama tetap hanya satu admin; audit tidak menambah akun. |
| Keamanan | GET security-events, policy global admin/scoped staff | `logsPage('security')` | Source, policy, sanitasi output, suite, dan aggregate utama diperiksa. | Berfungsi sebagai daftar event, bukan monitor database/backup/deployment. |
| Audit aktivitas | GET admin/audit-logs, sanitizer metadata | `logsPage('audit')` | Bootstrap utama menghasilkan 1 role_assigned; suite menguji audit perubahan dan redaksi. | Read-only; bukan tamper-proof di level operator SQL/host. |
| Notifikasi | List, mark-one, mark-all; scope pemilik | `account.js::notifications()` | Source/policy dan suite lulus; browser fixture menguji UI. | Backend/UI nyata; akun utama saat audit mempunyai 0 notifikasi, jadi tampilan berisi data nyata belum diuji langsung. |
| Akun & verifikasi | GET own identity/membership, PATCH profile, own KTM flow | `accountPage()` | Source dan suite diuji. | Berfungsi. Super Admin tidak punya `identity.verify` dan tidak otomatis membaca KTM; status admin utama unverified tidak menghalangi administrasi. |

Multi-Super Admin didukung tanpa SQL manual. Tabel user memakai FK role biasa, UI menawarkan `super_admin`, route memerlukan `roles.manage`, service menolak perubahan diri sendiri dan mencatat audit. `MainAdminManagementTest` membuktikan admin pertama tetap admin, akun kedua menjadi admin, dashboard kedua berfungsi, dan tidak memuat reports privat. Akun/password kedua tetap harus dimiliki individu terpisah; audit ini tidak membuatnya.

## C. Alur Kelas, Mahasiswa, Identitas, Ketua, Dan Dosen

1. **Periode dan kelas:** Super Admin dapat membaca katalog periode serta membuat/mengubah kelas yang merujuk periode tersedia. Periodenya hanya read API/seed; belum ada CRUD periode.
2. **Registrasi mahasiswa:** endpoint publik selalu memaksa role student, identity unverified, akun aktif; payload role/status dari klien diabaikan. Limiter register terpisah dari login.
3. **Verifikasi identitas:** mahasiswa unggah KTM privat; petugas role `identity_verifier` terpisah meninjau dan tidak boleh memutuskan pengajuan sendiri. Super Admin tidak memperoleh permission ini secara otomatis.
4. **Keanggotaan:** akun verified memakai invitation code; hasil selalu pending. Pengelola kelas yang scoped menyetujui/menolak dan tidak boleh memutuskan miliknya sendiri.
5. **Ketua:** Super Admin menetapkan student/class leader yang verified dan anggota aktif kelas. Ketua lama diakhiri dalam transaksi, role student dapat berubah ke class leader, dan audit/notifikasi dibuat.
6. **Dosen:** Super Admin memberi role `lecturer_coordinator`, lalu menugaskan akun aktif tersebut pada kelas. Dua tahap ini diuji eksplisit hingga record assignment/audit.
7. **Laporan dosen:** dosen kelas dapat melihat metadata sesuai scope, tetapi isi privat memerlukan permission + active report assignment + kelas aktif dan bebas konflik. Super Admin tidak memiliki `reports.view.private`.

Alur di atas telah diuji pada database test dan juga pernah diuji E2E nyata terisolasi dalam audit sebelumnya (`docs/AUDIT.md`). Pada database utama belum ada kelas/mahasiswa/laporan, sehingga audit Promt7 tidak mengklaim alur tersebut telah digunakan langsung dengan data utama.

## D. Keamanan, Bug, Dan Kekurangan

Tidak ada bypass autentikasi/otorisasi baru yang terkonfirmasi pada audit ini. Kontrol yang dibuktikan:

- pengguna biasa tidak dapat memilih role saat registrasi atau mengubah role sendiri;
- route sensitif memakai Sanctum, account-active/cooldown, limiter, permission, FormRequest, policy, dan/atau service recheck;
- scope kelas dicek melalui membership/assignment aktif;
- laporan/lampiran privat memakai ReportPolicy/AttachmentPolicy dan tidak dibuka kepada Super Admin;
- KTM hanya pemilik atau petugas `identity.verify`, dan review tidak boleh milik sendiri;
- notifikasi dibatasi pemilik serta tidak memberi izin resource tambahan;
- perubahan role, kelas, assignment, verifikasi, workflow, dan moderasi memakai audit metadata yang disanitasi;
- error API tidak menampilkan stack/internal SQL; rate limiting dan cooldown tetap aktif.

Kekurangan nyata:

1. **P1 operasional akun:** belum ada UI/API authenticated password change atau password-recovery lengkap, walau tabel reset token skeleton ada. Saat ini hanya satu Super Admin utama; dukungan admin kedua tersedia tetapi akun kedua belum dibuat.
2. **P1 administrasi kelas:** permission bernama “mengelola kelas dan tahun akademik”, tetapi AcademicTerm hanya dapat dibaca. Tahun/periode baru masih perlu seeder/operator.
3. **P1 lifecycle assignment:** service `removeLecturer()` ada, tetapi route/controller/UI revoke dosen dan daftar assignment aktif belum ada. UI leader revoke memerlukan ID manual. Tidak ada revoke invitation/member.
4. **P2 UX/operasional:** tidak ada pencarian staf khusus pada form assignment, list banding umum, atau daftar kode undangan. Admin harus memakai ID pengguna yang diketahui.
5. **P2 verifikasi langsung:** request autentikasi ke halaman admin utama tidak dijalankan oleh auditor karena token/password tidak boleh diambil. Login sukses hanya diverifikasi dari state agregat utama; fitur HTTP autentikasi admin diuji di database test dan browser fixture.
6. **P2 deployment:** server Artisan lokal aktif, tetapi Apache MAMP/produksi, backup/restore, retensi, encryption-at-rest, mail, browser non-Chromium, dan accessibility audit lengkap belum diverifikasi.

Satu kegagalan saat menulis tes baru berasal dari ekspektasi HTTP test yang salah (`assign lecturer` benar mengembalikan 201), bukan bug aplikasi. Ekspektasi tes diperbaiki; source aplikasi tidak diubah untuk menutupi kegagalan.

## E. Tes Yang Dijalankan

| Pemeriksaan | Hasil aktual |
| --- | --- |
| Verifikasi main/demo/test lewat Laravel + SQL/PDO | Ketiganya terpisah; test menunjuk `pengaduan_unpam_test`, utama menunjuk `pengaduan_unpam`. |
| `php artisan migrate:status --no-interaction` | Sembilan migration utama Ran; tidak ada migration dijalankan. |
| Direct read-only `DashboardService::forSuperAdmin` pada akun utama | Role/system/security/note tersedia; reports tidak tersedia; permission private report dan identity verify false. |
| `php artisan test --compact --order-by=random` | **116 passed, 639 assertions** pada database test. |
| `npm test` | **42 passed**. |
| `npm run test:browser` | **62 checks passed**, Chromium, API fixture terisolasi, tanpa page error. |
| `composer audit` dan `npm audit` | Tidak ada advisory/vulnerability yang dilaporkan. |
| Pint scoped app/routes/test baru | Lulus. |
| Verifikasi utama sesudah suite | Tetap 1 admin/token/login, 0 kelas/laporan, 1 audit/security event. Test DB kembali 0 user. |
| Server utama | Ready pada `http://localhost:8000/#/login`. |

Pemeriksaan browser 62 checks menggunakan fixture kontrak API untuk role/page dan bukan login akun utama. E2E nyata dari audit sebelumnya memakai database audit sementara yang sudah dibersihkan. Kedua hasil dibedakan dari pemeriksaan source dan agregat utama.

## F. Tahap Berikutnya Yang Direkomendasikan

**Prioritas pertama: lengkapi lifecycle kredensial dan kontinuitas administrator.**

Urutan minimal:

1. Tambah perubahan password terautentikasi: current password, policy/rate limit, password confirmation, hash Laravel, revoke token lain, audit aman, notifikasi keamanan, dan tes sesi lama.
2. Tambah pemulihan password ber-token satu kali dan expiry setelah mail/transport yang benar disiapkan; pesan tidak mengungkap apakah email terdaftar.
3. Melalui mekanisme resmi yang sudah ada, buat akun pribadi Super Admin tepercaya kedua; jangan akun/password bersama. Uji login/logout dan simpan credential masing-masing di password manager.
4. Tambah guard operasional agar sistem tidak dapat ditinggalkan tanpa satu Super Admin aktif melalui demotion/suspension lintas-admin.

Alasan prioritas: aplikasi utama baru memiliki satu administrator dan tidak mempunyai pemulihan/ubah password di UI. Kehilangan credential atau satu akun dinonaktifkan akan menghentikan administrasi, meskipun multi-admin secara teknis sudah didukung.

Tahap kedua setelah itu: lengkapi administrasi kelas—CRUD AcademicTerm dengan constraint, list/revoke lecturer/leader assignment scoped, revoke invitation/member, audit dan tes. Jangan menggabungkan seluruh gap ke satu perubahan besar tanpa kontrak/policy terpisah.

Tidak ada commit/push/deployment publik pada audit ini. File aplikasi yang ditambahkan hanya tes `tests/Feature/MainAdminManagementTest.php`; source produksi tidak memerlukan perbaikan pada tahap Promt7.
