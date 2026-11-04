# Frontend Ruang Dengar

Frontend Pengaduan Mahasiswa sesuai lingkup `Promt2.md`, disajikan oleh Laravel pada `/`. Antarmuka menggunakan Bahasa Indonesia dan membaca API yang sudah tersedia, bukan data demo. Kontrak payload, policy, validasi, dan rate limit tetap mengikuti [API.md](API.md); setup PHP, MySQL, akun awal, dan penyimpanan privat mengikuti [README.md](../README.md).

## Stack Dan Arsitektur

- Blade `resources/views/app.blade.php` menyediakan shell, elemen dialog/toast, dan `data-api-base` dari `url('/api/v1')`. Shell tidak menyertakan token, profil, atau isi pengaduan.
- JavaScript menggunakan ES modules tanpa React, Vue, Inertia, atau framework runtime baru. `app.js` mengelola sesi, router hash, shell, dashboard, dialog, dan konteks halaman; empat modul `pages/` menangani fitur lainnya.
- Vite 8, Laravel Vite plugin, dan Tailwind CSS 4 membangun aset. Grafik distribusi status memakai HTML/CSS dengan angka dari respons dashboard, bukan library grafik atau persentase kemajuan rekaan.
- State pengguna, respons API, dan formulir ada di memori halaman. Filter/pagination ada pada hash URL. Tidak ada service worker, cache offline data privat, atau penyimpanan draft otomatis.
- Tampilan awal memakai hijau/ivory, tipografi editorial, dan ilustrasi CSS/SVG. Ini arah awal yang dapat disesuaikan, bukan penguncian tema final. Variabel di `:root`, `@theme`, dan aturan komponen berada di `resources/css/app.css`.
- Font memakai font sistem, termasuk fallback serif Georgia. Tidak diperlukan unduhan font Bunny atau layanan font eksternal. Ikon memakai SVG inline; tidak ada dependensi ikon atau animasi runtime baru.

## Menjalankan Aplikasi

Jalankan dari `/Applications/MAMP/htdocs/WebAplikasi` setelah setup backend pada README selesai. Node harus memenuhi engine Vite, yaitu `^20.19.0 || >=22.12.0`; gunakan versi Node yang masih didukung dan kompatibel dengan lockfile. PHP/Composer diperlukan untuk Laravel, bukan digantikan oleh server Vite.

### Aset Produksi Lokal

```sh
npm ci && npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Buka `http://127.0.0.1:8000/#/login`. `npm ci` mengikuti `package-lock.json`; build menghasilkan `public/build/manifest.json` dan aset ber-hash. Build ini adalah mode aset produksi, bukan bukti deployment produksi telah diuji. Gunakan akun pribadi yang disiapkan melalui alur backend; frontend tidak menyediakan akun atau kredensial demo.

### Pengembangan Dengan HMR

Biarkan Laravel berjalan, lalu jalankan berikut di terminal terpisah:

```sh
npm run dev
```

Buka URL Laravel, bukan halaman server Vite. Vite hanya menyajikan aset/HMR, sedangkan request API tetap menuju origin Laravel. Untuk kembali memakai build, hentikan Vite dan pastikan `public/hot` tidak menunjuk server development yang sudah mati. Manifest yang hilang atau referensi HMR yang tidak aktif dapat menyebabkan halaman/aset gagal dimuat.

### Apache MAMP

Document root/vhost yang direkomendasikan tetap `/Applications/MAMP/htdocs/WebAplikasi/public`, dengan versi PHP, rewriting, dan permission storage sesuai README. Gunakan port HTTP aktual MAMP, bukan port MySQL. Setelah `npm run build`, Apache dapat menyajikan aset tanpa server Vite.

Base API tidak di-hard-code ke port Artisan: shell menghasilkan `data-api-base` sesuai URL Laravel. Jika instalasi lokal diakses melalui prefiks seperti `/WebAplikasi/public`, base API mengikuti prefiks request tersebut; hash navigasi tetap berada pada URL shell yang sama. Jangan mengekspos root proyek, `.env`, atau storage privat untuk mengatasi masalah path. Cocokkan `APP_URL` dan konfigurasi vhost/reverse proxy dengan URL akses, lalu periksa URL aset dan API pada deployment sebenarnya. Konfigurasi Apache MAMP/subdirektori belum merupakan hasil uji end-to-end yang diklaim di sini.

Tidak ada API CORS baru, proxy API terpisah, atau alur autentikasi cookie SPA. Integrasi ini menggunakan same-origin dengan bearer token Sanctum. Menjalankan frontend pada origin API yang berbeda memerlukan rancangan CORS/deployment tersendiri; jangan menganggap `SANCTUM_STATEFUL_DOMAINS` sudah menyelesaikannya.

## Halaman Dan Navigasi

Semua path di bawah merupakan fragmen browser, bukan route HTML Laravel terpisah. Contoh: `/#/reports/PGD-...`; refresh tetap meminta shell `/`. Query filter mengikuti hash, misalnya `/#/reports?status=resolved&page=2`.

| Route frontend | Fitur yang tersedia |
| --- | --- |
| `#/login`, `#/register` | Login, registrasi mahasiswa, tampil/sembunyikan password, validasi dan pesan sesi. Tidak ada pemilih role. |
| `#/forgot-password`, `#/reset-password?token=...&email=...` | Permintaan pemulihan generik dan pengaturan password baru dengan token sekali pakai. |
| `#/dashboard` | Ringkasan sesuai role, pengaduan/aspirasi yang dikirim backend, statistik status bila tersedia, dan muat ulang manual. Super Admin mendapat ringkasan administratif, bukan isi laporan. |
| `#/account` | Profil nama/telepon, perubahan password akun sendiri, status identitas, unggah/unduh KTM sendiri, alasan penolakan, katalog kelas sebagai referensi, pengajuan kode undangan, riwayat keanggotaan, dan aktivasi suara keamanan. |
| `#/reports` | Daftar milik sendiri atau metadata kelas sesuai permission; filter status, kategori, kelas, urutan; pagination. Tidak ada pencarian teks isi privat. |
| `#/reports/new` | Pengaduan privat dengan kategori aktual, uraian, harapan, pihak terlibat, dan lampiran. |
| `#/reports/:ref` | Metadata atau detail privat sesuai respons; timeline, pembaruan, klarifikasi, lampiran, status, penugasan/alih penangan, dan pengajuan banding sesuai kewenangan. `:ref` memakai `public_ref`. |
| `#/appeals/:id` | Detail banding milik pelapor atau peninjau yang sudah ditetapkan; keputusan hanya untuk peninjau yang berwenang. |
| `#/appeals/:id?mode=assign` | Kandidat peninjau dari API dan penetapan peninjau tanpa terlebih dahulu meminta isi banding. ID berasal dari notifikasi, pengajuan/detail laporan, atau handoff resmi. |
| `#/aspirations`, `#/aspirations/new` | Daftar/sort/filter aspirasi kelas dan pembuatan aspirasi berupa teks biasa. |
| `#/aspirations/:id` | Detail, komentar/balasan, dukungan dan pembatalannya, pelaporan konten, serta moderasi sesuai permission. |
| `#/aspirations/:id?tab=flags&status=open` | Antrean laporan konten aspirasi dan komentarnya, filter/pagination, serta tindakan moderator. |
| `#/notifications` | Notifikasi yang diizinkan, filter belum dibaca, pagination, tandai satu/semua dibaca, dan tautan internal yang diperiksa. |
| `#/classes`, `#/classes/new`, `#/classes/:id` | Katalog/kelas penugasan, buat/ubah kelas oleh admin, undangan, keputusan keanggotaan, penetapan ketua/dosen, dan pencabutan ketua berdasarkan ID penugasan yang diketahui. |
| `#/users` | Pencarian pengguna administratif, filter role/identitas, perubahan role eksplisit, suspensi, dan aktivasi kembali. |
| `#/verifications` | Antrean KTM untuk verifikator, unduh privat, persetujuan atau penolakan beralasan. Bukan fitur otomatis Super Admin. |
| `#/security`, `#/audit` | Kejadian keamanan sesuai scope dan audit administratif baca saja, dengan filter/pagination. Bukan monitor kesehatan layanan. |

Navigasi mengikuti `User.permissions`, role, identitas, dan status keanggotaan yang tersedia. Mahasiswa baru belum memperoleh fitur kelas sebelum identitas terverifikasi dan keanggotaan aktif. Kelas petugas di halaman pengelolaan berasal dari `/dashboard.classes`, bukan asumsi akses ke semua kelas. Penyembunyian tombol bukan otorisasi: policy backend memeriksa kembali setiap request, termasuk penugasan aktif, status, dan konflik kepentingan.

## Endpoint Yang Digunakan

Path berikut relatif terhadap `/api/v1`. Tabel ini inventaris panggilan frontend, bukan perubahan kontrak backend. Notasi `{ref}`, `{id}`, `{class}`, `{user}`, `{membership}`, `{assignment}`, dan `{comment}` diisi referensi aktual.

| Area | Endpoint frontend |
| --- | --- |
| Sesi/profil | `POST /auth/login`, `POST /auth/register`, `POST /auth/logout`, `GET /auth/me`, `PATCH /profile` |
| Ringkasan | `GET /dashboard` |
| Identitas sendiri | `GET /identity/status`, `POST /identity/submissions`, `GET /identity-verifications/{id}/ktm` |
| Katalog/keanggotaan | `GET /classes`, `GET /academic-terms`, `GET /memberships`, `POST /memberships/join`, `GET /classes/{class}/memberships/pending`, `POST /memberships/{membership}/decide` |
| Pengaduan | `GET /report-categories`, `GET /reports`, `POST /reports`, `GET /reports/{ref}`, `PATCH /reports/{ref}/status` |
| Penanganan | `POST /reports/{ref}/updates`, `POST /reports/{ref}/clarification-requests`, `POST /reports/{ref}/assignments`, `POST /reports/{ref}/forward`, `GET /attachments/{id}` |
| Banding | `POST /reports/{ref}/appeals`, `GET /appeals/{id}`, `GET /appeals/{id}/eligible-reviewers`, `POST /appeals/{id}/reviewer`, `POST /appeals/{id}/decision` |
| Aspirasi/diskusi | `GET /aspirations`, `POST /aspirations`, `GET /aspirations/{id}`, `GET /aspirations/{id}/comments`, `POST /aspirations/{id}/comments`, `POST /aspirations/{id}/support`, `DELETE /aspirations/{id}/support` |
| Pelaporan/moderasi | `POST /aspirations/{id}/flag`, `POST /aspirations/{id}/moderate`, `GET /aspirations/{id}/flags`, `POST /aspirations/{id}/comments/{comment}/flag`, `POST /aspirations/{id}/comments/{comment}/moderate` |
| Notifikasi | `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` |
| Administrasi pengguna | `GET /admin/users`, `POST /admin/users/{user}/role`, `POST /admin/users/{user}/suspend`, `POST /admin/users/{user}/reactivate` |
| Administrasi kelas | `POST /admin/classes`, `PATCH /admin/classes/{class}`, `POST /admin/classes/{class}/leader`, `DELETE /admin/class-leaders/{assignment}`, `POST /admin/classes/{class}/lecturers`, `POST /classes/{class}/invitations` |
| Verifikator | `GET /identity-verifications`, `GET /identity-verifications/{id}/ktm`, `POST /identity-verifications/{id}/approve`, `POST /identity-verifications/{id}/reject` |
| Security/audit | `GET /security-events`, `GET /admin/audit-logs`, `GET /security/voice-warnings` |

Statistik tampilan berasal dari `/dashboard`; frontend saat ini tidak meminta `/reports/statistics` secara terpisah. `/auth/logout-all` tersedia di API tetapi tidak mempunyai tombol pada frontend ini. Tidak ada endpoint yang dikarang untuk daftar banding, pencarian staf oleh dosen, atau konfigurasi sistem.

## Sesi Dan Privasi

- Token disimpan hanya pada `sessionStorage` dengan key `rd.token`, dalam lingkup sesi tab/origin, agar reload dapat memulihkan sesi lewat `/auth/me`. Jika penyimpanan browser tidak tersedia, sesi berjalan di memori sampai halaman dimuat ulang. Tidak ada token di `localStorage`, query URL, source, atau cookie autentikasi frontend.
- `sessionStorage` bukan perlindungan terhadap XSS same-origin: skrip berbahaya pada origin yang sama tetap dapat membaca token. Gunakan HTTPS, hindari skrip pihak ketiga yang tidak diperlukan, dan pertahankan output escaping. Browser dapat memulihkan sesi tab; menutup tab bukan jaminan pencabutan token di server.
- Login memakai `device_name=ruang-dengar-web`. Karena backend mencabut token lama dengan nama perangkat sama, login baru pada tab lain dapat membuat token tab sebelumnya tidak berlaku. Lingkup penyimpanan per-tab tidak berarti sesi server antar-tab independen.
- Logout memanggil API untuk mencabut token, kemudian menghapus state/token lokal. Bila API logout gagal, sesi lokal tetap dibersihkan dengan peringatan bahwa token server belum tentu dicabut. Hasil logout terlambat tidak menghapus sesi pengganti. Pada 401 atau akun nonaktif, UI menghapus state sesi dan kembali ke login; pemulihan BFCache memeriksa `/auth/me` kembali.
- Request API memakai `Accept: application/json`, header Bearer saat ada token, `credentials: 'omit'`, `cache: 'no-store'`, dan `redirect: 'error'`. URL API harus same-origin dan canonical. JSON dikirim untuk aksi biasa; `FormData` tidak dipasangi boundary multipart manual. Path traversal/encoded separator ditolak tanpa melarang query pencarian yang mengandung titik.
- Isi laporan/KTM tidak disimpan sebagai payload di penyimpanan browser oleh aplikasi. Dokumen yang sengaja diunduh pengguna tetap menjadi file lokal dan perlu ditangani secara aman. Unduhan dilakukan lewat fetch ber-token, lalu Blob URL sementara yang dicabut kembali; tidak ada tautan storage privat publik.
- Teks API/pengguna di-escape sebelum masuk template HTML. Pesan 5xx dan kegagalan jaringan diganti pesan generik; stack trace tidak ditampilkan. Notifikasi tidak mempercayai `action_url` mentah: tujuan dibangun dari tipe serta referensi yang dikenal.
- Respons metadata pengaduan dengan `private_details: null` tidak dirender sebagai detail privat. Judul null ditampilkan sebagai informasi dilindungi, bukan diganti judul demo. Pelapor tidak mendapat catatan internal; Super Admin tidak mendapat isi laporan atau KTM hanya karena role administratif.
- Aspirasi tetap konten bersama dalam kelas, bukan forum publik/anonim. UI menampilkan nama penulis yang diizinkan, tidak menambahkan NIM atau identitas pelapor dari laporan privat. Pengaduan tidak otomatis disalin menjadi aspirasi.
- Tidak ada autosave formulir yang belum dikirim. Navigasi, reload, atau logout dapat menghilangkan isian; token sesi bukan penyimpanan draft. Jangan menaruh data rahasia dalam filter URL.

## Formulir Dan State

Komponen bersama mencakup sidebar/header, field dengan ID label unik, status badge berteks, data table semantik, pagination, timeline interaktif, dialog konfirmasi native, toast/live region, skeleton, empty state, dan error state. Tabel pengaduan memakai caption, header berscope, dan region horizontal yang dapat difokuskan pada layar kecil. Usia laporan dihitung dari `created_at`. Timeline dapat dibuka/ditutup dengan keyboard. Filter hanya menggunakan parameter yang didukung API.

Validasi browser memberi umpan balik awal, sedangkan keputusan akhir tetap milik backend. KTM dibatasi 4 MB dalam format JPG/PNG/PDF. Pengaduan menerima maksimal lima lampiran, masing-masing 8 MB, dalam format JPG/PNG/PDF/TXT; batas multipart PHP/web server juga harus memadai. Nama pihak terlibat dikirim sebagai array; kelas dan perutean pengaduan ditentukan server. Pilihan katalog kelas pada akun hanya referensi, sedangkan join mengirim `invitation_code` tanpa memaksakan `class_id`.

Submit dinonaktifkan selama request berjalan dan aksi elemen yang sama dijaga agar tidak terkirim ganda. Ini perlindungan UX, bukan idempotensi atau pencegahan race di server. Navigasi membatalkan request dengan `AbortController`; hasil terlambat tidak boleh mengganti halaman yang sudah ditinggalkan. Pembatalan fetch tidak menjamin mutasi yang telah diterima server ikut dibatalkan.

Pembatalan dan identitas token diperiksa kembali setelah parsing JSON/Blob, sehingga respons sesi lama tidak membuka dialog, melakukan logout, atau mengunduh data ke sesi pengganti. Nama profil readonly dan tidak dikirim dalam PATCH saat identitas pending/verified atau role ketua/dosen; backend juga menegakkan aturan tersebut. Formulir klarifikasi hanya tersedia pada status under_review/needs_clarification.

| Kondisi | Perilaku |
| --- | --- |
| Loading | Skeleton atau indikator busy mengikuti request aktual. |
| Kosong | Empty state menjelaskan bahwa tidak ada data/filter cocok; tidak diganti fixture. |
| Sukses | Toast/hasil aksi baru muncul setelah respons sukses, kemudian data dimuat ulang atau navigasi ke resource hasil. |
| 422 | Ringkasan error dengan focus, pesan field, dan `aria-invalid`; isian tidak dianggap sukses. |
| 401 | Sesi privat dibersihkan dan kembali ke login; kredensial login yang salah tetap ditangani pada formulir login. |
| 403 | Dialog penolakan akses dari keputusan backend, ditambah error halaman/form yang relevan. |
| 429 | Pesan tunggu berdasarkan `Retry-After` atau `meta.retry_after`; `SECURITY_COOLDOWN` mendapat dialog pembatasan. Tidak ada retry mutasi otomatis. |
| 5xx/jaringan | Pesan aman dan tombol coba lagi untuk pemuatan halaman; data palsu tidak digunakan sebagai fallback. |

## Suara Keamanan

Suara default **nonaktif**. Aktivasi ada di `#/account` dan hanya disimpan di memori sesi halaman, bukan browser storage; reload/logout mengembalikannya ke nonaktif.

`createVoiceManager()` membaca `/security/voice-warnings` serta `meta.voice_warning` dari penolakan backend. Hanya kategori `access_denied` dan `repeated_denials` dengan instruksi `play`, status keamanan yang sesuai, katalog aktif, dan opt-in pengguna yang dipertimbangkan. Frontend tidak menghitung kegagalan untuk menyebut pengguna sebagai penyerang. Klik, submit sukses, update status biasa, dan notifikasi normal tidak memicu suara.

Cooldown presentasi minimal 30 detik atau lebih lama sesuai metadata peringatan. Pemutaran memerlukan file tersedia dalam katalog yang cocok dengan `sound`, berupa path same-origin `.mp3`, `.wav`, atau `.ogg` yang valid; nama placeholder tidak diubah menjadi URL rekaan. Saat ini rekaman dan endpoint penyajian audio belum tersedia. UI menjelaskan ketidaktersediaan tersebut, tidak membuat suara sintetis atau membacakan konten privat. Rekaman yang kelak disediakan perlu konfigurasi penyajian dan pengujian terpisah. Autoplay yang ditolak atau audio gagal tidak merusak halaman dan tidak mengubah keputusan akses.

## Motion Dan Aksesibilitas

CSS menyediakan transisi halaman, kemunculan metrik dan timeline bertahap, batang statistik, hover/press, dialog masuk, toast, indikator busy, dan skeleton. Dialog keluar memakai Web Animations API selama 140 ms; navigasi/logout menutupnya segera agar konten lama tidak tertinggal. Nilai metrik langsung mengikuti backend; animasi bukan simulasi progress penanganan. `prefers-reduced-motion: reduce` menonaktifkan animasi/transisi terkait, termasuk animasi keluar dialog.

Shell memakai `lang="id"`, landmark navigasi/main, focus-visible, label field unik, status berteks, live region, dan dialog native dengan dukungan Escape. Menu mobile menggunakan `aria-expanded`, `inert` pada area yang tidak aktif, dan pengelolaan focus keyboard. Pemuatan halaman mengarahkan focus ke heading; error formulir mengarahkan focus ke ringkasan error. Tidak ada klaim sertifikasi WCAG atau audit aksesibilitas menyeluruh. Pemeriksaan screen reader, zoom/kontras menyeluruh, browser lain, dan perangkat nyata tetap perlu dilakukan.

## Pengujian

Perintah berikut disediakan untuk pengulangan oleh operator. Penulisan dokumentasi ini tidak menjalankan ulang instalasi atau test; hasil yang dicatat berasal dari pemeriksaan implementasi utama.

```sh
npm test
npm run build
```

`npm test` menggunakan test runner bawaan Node pada `tests/Frontend/*.test.js`, tanpa dependency test runtime baru. Cakupannya meliputi envelope/header API, multipart, validasi 422, sesi 401, sanitasi pesan 5xx/jaringan, 429, abort sebelum/sesudah parsing, pergantian sesi, logout offline, BFCache, Blob privat, validasi URL/content-type/audio, escaping, ID label, navigasi role, pagination, opt-in/cooldown suara, placeholder, dan kontrak formulir privat.

### Browser Fixture

`npm run test:browser` menjalankan `tests/Frontend/browser.mjs`. Script membutuhkan Laravel dengan aset tersedia serta instalasi Playwright/Chromium eksternal yang sudah ada; Playwright tidak ditambahkan ke dependency repository. Tanpa `PLAYWRIGHT_MODULE`, Node mencoba import `playwright` yang tersedia dalam resolusi modul normal. `PLAYWRIGHT_MODULE` adalah direktori absolut paket yang berisi `index.mjs`, bukan path file itu sendiri.

Contoh penggunaan dengan lokasi lokal yang sudah tersedia:

```sh
PLAYWRIGHT_MODULE="/path/absolut/node_modules/playwright" \
FRONTEND_URL="http://127.0.0.1:8000" \
npm run test:browser
```

`FRONTEND_URL` default adalah `http://127.0.0.1:8016`. `PLAYWRIGHT_EXECUTABLE` opsional menunjuk executable Chromium bila discovery browser bawaan tidak cocok. `E2E_SCREENSHOT_DIR` opsional menunjuk direktori keluaran screenshot pengujian. Tidak diperlukan instalasi dependency repository baru untuk memakai Playwright eksternal, tetapi `npm ci` sendiri tidak memasang Playwright atau browser.

Browser memuat shell/aset aplikasi, lalu mengintersepsi request `/api/v1` dengan fixture terisolasi. Script memeriksa login/logout, sesi habis, role, pengaduan dan validasi, submit/dukungan ganda, unduhan, escaping, notifikasi, loading/empty/error, pergantian route saat request lambat, layout/label/ID, menu mobile, focus dialog, reduced motion, metadata tanpa isi privat, serta pembatasan identitas pending. Konteks browser tambahan tanpa intersepsi memverifikasi login asli dan `GET /api/v1/auth/me` tanpa token menghasilkan 401. Ini **bukan E2E mutasi dengan API dan database live**. Fixture, akun sintetis, dan token pengujian hanya berada di `tests/Frontend/`, tidak menjadi fallback halaman produksi.

### E2E Backend Nyata

`npm run test:live` menjalankan `tests/Frontend/live-audit.mjs` dan helper `tests/Support/live-audit.php`. Server harus memakai `APP_ENV=testing`, koneksi MySQL, dan database khusus `pengaduan_unpam_audit_e2e_test`. Helper menolak konfigurasi cached, database lain, atau database audit yang sudah ada; jangan menjalankan helper prepare langsung karena runner harus menjaga kredensial acak hanya di memori.

```sh
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=pengaduan_unpam_audit_e2e_test \
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8017 --no-reload
```

Di terminal lain:

```sh
PLAYWRIGHT_MODULE="/path/absolut/node_modules/playwright" \
LIVE_AUDIT_URL="http://127.0.0.1:8017" npm run test:live
```

Runner memastikan login akun seed cocok dengan database terisolasi sebelum mendaftarkan pengguna melalui UI. Dua belas tahap menguji registrasi, KTM/verifikator, undangan/keanggotaan, pengaduan/lampiran, klarifikasi, banding independen, penugasan/pengalihan/penyelesaian, aspirasi/upvote bersamaan/moderasi, akses lintas kelas/admin, cooldown tanpa audio, notifikasi, dan pencabutan token logout. Sebagian tindakan lanjutan memakai API langsung ber-token, bukan semuanya klik UI. Tidak ada intersepsi API; kredensial tidak dicetak, database serta file unggahan yang direferensikan dibersihkan pada finally. DB username test memerlukan izin create/drop database. Hentikan server audit setelah selesai; jangan memakai nama database tersebut untuk data yang ingin dipertahankan.

### Hasil Tercatat

| Pemeriksaan final 22 Oktober 2026 | Hasil aktual |
| --- | --- |
| `npm test` | 42 test lulus. |
| `php artisan test` | 114 test / 626 assertion lulus. |
| Browser | 62 checks lulus menggunakan Chromium melalui Playwright 1.63; viewport 320, 390, 768, dan 1440 piksel; tanpa exception JavaScript. Alur fixture ditambah smoke HTTP asli terbatas. |
| Build Vite | Build aset berhasil; ukuran bundle dapat berubah setelah penyempurnaan CSS dan tidak dipatok dalam dokumen ini. |
| `npm audit` | Tidak ada vulnerability yang dilaporkan. |
| `npm run test:live` | 12 tahap lulus dengan backend/MySQL nyata dan cleanup berhasil; tanpa exception browser. |

`tests/Feature/FrontendShellTest.php` memeriksa shell Bahasa Indonesia tanpa payload privat dan memastikan API tetap menolak pengguna tanpa autentikasi. Suite PHP memakai database test terpisah sesuai README; jangan menjalankannya terhadap data nyata. Jumlah checks browser bukan klaim semua kombinasi halaman/role/ukuran diuji. E2E nyata mencakup alur utama serta dua request upvote bersamaan, bukan uji beban menyeluruh. Apache MAMP, produksi, browser selain Chromium, serta sertifikasi aksesibilitas belum diverifikasi.

## Batas Dan Handoff

- Tidak ada API pencarian staf yang boleh digunakan dosen, daftar banding terpisah, atau daftar penugasan ketua/dosen. Input ID yang sudah diketahui merupakan batas integrasi yang disengaja, bukan pencarian palsu. Admin dapat memperoleh ID pengguna dari halaman pengguna; ID penugasan ketua berasal dari hasil penetapan atau referensi audit yang telah diperiksa.
- `#/appeals/:id?mode=assign` hanya mengambil kandidat sebelum penetapan. Tidak ada upaya membaca isi banding lebih awal. Peninjau harus dinyatakan layak oleh backend; Super Admin tidak dijadikan pengganti otomatis.
- Endpoint pencabutan ketua memakai ID penugasan, bukan ID pengguna, dan tidak terikat otomatis pada kelas yang sedang ditampilkan. Dialog menyatakan hal ini; operator wajib memeriksa referensinya.
- Tidak ada endpoint tulis periode akademik, hapus/nonaktifkan kelas, pencabutan dosen/anggota/undangan, manajemen konflik, perubahan konfigurasi, atau monitor database/queue/backup. Fitur tersebut tidak ditampilkan sebagai aksi simulasi yang seolah berhasil.
- Tidak ada reset/change password, verifikasi email, refresh token, SSO/API resmi kampus, ekspor, websocket, atau push notification. Notifikasi/data dimuat saat halaman dibuka atau dimuat ulang, bukan real-time.
- Timeline/update laporan dibatasi 100 record terbaru oleh API; komentar aspirasi dibatasi 200 record pertama. Frontend tidak dapat mengambil sisa data dengan pagination yang tidak disediakan.
- Tidak ada edit/hapus laporan oleh pelapor, upload lampiran tambahan pada pembaruan, unggah gambar aspirasi, pin/unpin, atau edit/hapus komentar oleh penulis. Moderasi mengikuti endpoint yang memang tersedia.
- Rekaman keamanan belum tersedia dan tema final belum diputuskan. E2E live-database alur utama sudah lulus; deployment MAMP serta penilaian aksesibilitas/keamanan menyeluruh tetap pekerjaan lanjutan.

## Inventaris Frontend

Inventaris ini mencatat artefak implementasi, bukan diff Git; workspace tidak mempunyai repository Git.

| File | Peran/perubahan |
| --- | --- |
| `routes/web.php` | Route `/` menyajikan view aplikasi, menggantikan pemakaian welcome. |
| `resources/views/app.blade.php` | Shell Blade baru, `data-api-base`, Vite, dialog, toast, dan pesan tanpa JavaScript. |
| `resources/js/app.js` | Entry, sesi/router, shell responsif, dashboard, konfirmasi, request lifecycle, dan unduhan. |
| `resources/js/api.js` | API client dan voice manager baru. |
| `resources/js/ui.js` | Helper komponen, escaping, label, error, pagination, dan navigasi baru. |
| `resources/js/pages/reports.js` | Halaman pengaduan, penanganan, dan banding baru. |
| `resources/js/pages/aspirations.js` | Halaman aspirasi, diskusi, pelaporan, dan moderasi baru. |
| `resources/js/pages/account.js` | Halaman akun/identitas/keanggotaan dan notifikasi baru. |
| `resources/js/pages/management.js` | Halaman kelas, pengguna, verifikator, security, dan audit baru. |
| `resources/css/app.css` | Tema/komponen, layout responsif, motion/reduced-motion, dan sumber pemindaian Tailwind terbatas. |
| `vite.config.js` | Entry CSS/JS Laravel dan plugin Tailwind; HMR mengabaikan compiled Blade views. |
| `package.json`, `package-lock.json` | Script build/dev/test/browser serta dependency build terkunci; tidak menambah framework runtime. |
| `tests/Frontend/api.test.js` | Unit test client/UI/voice baru. |
| `tests/Frontend/browser.mjs` | Harness browser dengan API fixture terisolasi dan Playwright eksternal. |
| `tests/Feature/FrontendShellTest.php` | Test shell dan batas autentikasi API baru. |
| `public/build/` | Manifest/aset hasil `npm run build`, bukan source yang diedit manual. |
| `docs/FRONTEND.md`, `README.md`, `docs/API.md` | Handoff frontend baru, setup/ringkasan terkini, dan tautan scope frontend pada kontrak API. |

`resources/js/bootstrap.js` tidak tersedia ketika pemeriksaan awal dan tidak diperlukan oleh implementasi ini. `resources/views/welcome.blade.php` tetap merupakan artefak skeleton, tetapi bukan halaman `/` dan bukan bagian shell aplikasi. Tidak ada perubahan kontrak endpoint API yang diperlukan untuk frontend ini.
