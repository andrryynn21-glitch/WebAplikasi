# Panduan Memahami, Mengelola, Dan Mempresentasikan Aplikasi

Panduan `Promt5.md` dan setup lanjutan `promtlanjutan.md`, diperiksa pada 22 Oktober 2026 berdasarkan source dan database aktif. Bahasa panduan ditujukan untuk pemula. Tidak ada akun dibuat, password diambil/dicetak, database di-reset, atau identitas aplikasi diganti ketika menulis panduan ini. Katalog database demo telah di-seed sesuai status aktual di bawah.

Penanda **BACA** berarti pemeriksaan tanpa perubahan data aplikasi. **JALANKAN** berarti proses server lokal. **UBAH** berarti perintah/form yang mengubah file, konfigurasi, atau data. Perintah diberikan sebagai instruksi untuk Anda, bukan berarti sudah dijalankan oleh asisten.

## A. Kondisi Saat Ini

| Pemeriksaan aktual | Hasil |
| --- | --- |
| Root proyek | `/Applications/MAMP/htdocs/WebAplikasi` |
| Laravel terpasang | **13.37.0**, diperiksa dengan Composer |
| PHP CLI / Composer | **8.5.8 / 2.10.2** |
| Node / npm | **26.4.0 / 11.17.0** |
| Database utama | MySQL `127.0.0.1:3306`, schema `pengaduan_unpam`; tidak diubah oleh setup demo |
| Database demo | MySQL `127.0.0.1:3306`, schema `pengaduan_unpam_demo` |
| APP_URL | Utama `http://localhost:8000`; demo `http://127.0.0.1:8010` |
| Nama konfigurasi Laravel | `Pengaduan Mahasiswa UNPAM` |
| Nama visual frontend | `Ruang Dengar` |
| Migration demo | Seluruh sembilan migration sudah Ran; 39 tabel terdeteksi |
| Katalog demo | 5 role, 26 permission, 45 relasi role-permission, 7 kategori, 1 periode `2026-1` |
| Data operasional demo | 0 pengguna/Super Admin, 0 kelas, 0 pengaduan |
| Database utama | Dipertahankan dan tidak diperiksa/mutasi ulang setelah instruksi lanjutan menyatakan datanya harus dijaga |
| Frontend | Production build tersedia; `public/hot` tidak ada |
| Server aplikasi | Utama aktif pada `127.0.0.1:8000`; server demo 8010 sudah dihentikan |
| Git | Workspace sebelumnya dikonfirmasi bukan repository Git |

Artinya: aplikasi demo dan katalog sudah tersedia, tetapi **belum ada akun yang dapat dipakai untuk login pada database demo**. Pembuatan Super Admin ditunda atas pilihan pengguna karena password harus dimasukkan sendiri melalui prompt tersembunyi. Akun test/E2E bukan akun presentasi; database E2E sudah dibersihkan. Jangan mencoba password contoh dari internet, dokumentasi, atau kode test.

Empat role yang dipresentasikan adalah mahasiswa, ketua kelas, dosen, dan Super Admin. Implementasi mempunyai role kelima, **petugas verifikasi identitas (`identity_verifier`)**, karena persetujuan KTM tidak boleh dilakukan oleh Super Admin tanpa izin verifikator. Satu akun mempunyai satu role, bukan gabungan dua role.

Hasil audit sebelumnya: 114 tes Laravel/626 assertion, 42 tes JavaScript, 62 pemeriksaan browser, dan 12 tahap E2E nyata lulus. Itu hasil audit yang tercatat, bukan semua test dijalankan ulang pada pekerjaan panduan ini. Baca `docs/AUDIT.md` untuk bukti dan batasnya. Kesiapan demo lokal dengan data fiktif berbeda dari kesiapan produksi.

### A1. Otomasi Setup Demo Yang Benar-Benar Dijalankan

1. Workspace diperiksa dan tetap bukan repository Git; Git tidak diinisialisasi.
2. `.env.demo` diverifikasi tanpa mencetak isinya: MySQL, host 127.0.0.1, port 3306, database aktif `pengaduan_unpam_demo`, URL 8010, konfigurasi tidak cached.
3. Database demo memiliki 39 tabel dan sembilan migration Ran; sebelum seeding seluruh katalog/pengguna masih kosong.
4. Source `DatabaseSeeder`, PermissionSeeder, ReportCategorySeeder, dan AcademicTermSeeder diperiksa. Seeder normal tidak membuat akun/password, tidak memanggil layanan eksternal, dan memakai operasi idempoten katalog. Guard target memeriksa database aktif sekali lagi sebelum memanggil `db:seed` pada environment demo.
5. Hasil seeding: 5 role, 26 permission, 45 relasi permission-role, 7 kategori, 1 periode; pengguna tetap 0. `DemoDataSeeder` **tidak** dijalankan.
6. Source command bootstrap diperiksa: password prompt tersembunyi, validasi kuat, cast hash model, transaksi/lock, menolak admin kedua, dan tidak mempunyai argumen password. Input manual ditunda, sehingga status Super Admin tetap 0 dan login kredensial belum diuji.
7. `.env.demo` ditambahkan ke `.gitignore` dan permission filesystem-nya dipersempit menjadi `600`. `.env` utama tidak diubah.
8. `npm test` lulus 42 tes, build Vite berhasil, dan `FrontendShellTest` lulus 2 tes/10 assertion tanpa RefreshDatabase. Full suite Laravel tidak dijalankan ulang karena suite memakai `RefreshDatabase` pada `pengaduan_unpam_test` dan izin eksplisit untuk mengosongkan database test belum diberikan.
9. Server demo dimulai dengan `php artisan serve --env=demo --host=127.0.0.1 --port=8010 --tries=1 --no-reload`. HTTP halaman login menghasilkan 200 dan API `/auth/me` tanpa token menghasilkan 401 JSON.
10. Chromium tanpa mock/API interception memuat login pada lebar 320, 390, 768, dan 1440 px: status 200, tidak overflow horizontal, tidak ada exception, dan API base mengarah ke port demo. Login akun tidak dicoba karena akun belum dibuat.

Jangan menjalankan seeder/migration/bootstrap ulang hanya karena membaca daftar ini. Lihat status aktual terlebih dahulu. Server demo yang dipakai pada tahap ini kemudian dihentikan ketika aplikasi utama diaktifkan melalui Promt6.

### A2. Aktivasi Aplikasi Utama (`Promt6.md`)

Permintaan terbaru menetapkan aplikasi utama, bukan demo, sebagai aplikasi yang dikelola. Status aktual setelah pemeriksaan:

1. Konfigurasi default Laravel dan query SQL langsung sama-sama menunjuk `pengaduan_unpam` pada MySQL `127.0.0.1:3306`. Environment `local`, APP_URL `http://localhost:8000`, cache/session memakai database, dan config tidak cached. `.env` utama tidak diubah.
2. Sembilan migration utama berstatus Ran. Katalog utama tersedia: 5 role, 26 permission, 7 kategori, dan 1 periode. Pada audit Promt7 terbaru, database utama mempunyai 1 Super Admin aktif yang telah login, 0 kelas, dan 0 pengaduan.
3. Database `pengaduan_unpam_demo` tetap terpisah dan tidak dihapus/disalin. Ketika Promt6 diperiksa, demo mempunyai satu Super Admin yang dibuat di luar langkah otomatis panduan; identitas/password tidak dibaca. Server demo port 8010 sudah dihentikan agar tidak disangka aplikasi utama.
4. Server utama aktif sebagai proses terkelola pada `http://localhost:8000/#/login`. Halaman login menghasilkan 200, API `/auth/me` tanpa token menghasilkan 401, dan browser tanpa mock lulus pada 320/390/768/1440 px tanpa exception/overflow.
5. Source mendukung lebih dari satu akun `super_admin`: role FK tidak unik, UI role menyediakan Super Admin, endpoint role dilindungi `roles.manage`, perubahan diri sendiri ditolak, dan perubahan tercatat audit. Admin kedua harus mendaftar/mempunyai akun pribadi lalu ditetapkan oleh admin utama; tidak ada password bersama.
6. Penetapan dosen memerlukan dua tindakan resmi: admin memberi role `lecturer_coordinator`, kemudian menugaskan akun aktif tersebut pada kelas. Form request, policy, service, role aktif, dan audit diperiksa di backend; bukan hanya tombol UI.
7. Dua tes eksplisit ditambahkan di `tests/Feature/MainAdminManagementTest.php`: admin dapat menetapkan admin kedua dengan audit/dashboard tanpa reports privat; admin dapat memberi role dosen lalu assignment kelas. Pemeriksaan final terkait bootstrap/auth/authorization/privacy berjumlah 21 tes/217 assertion dan lulus pada `pengaduan_unpam_test`; 42 tes JavaScript juga lulus. Database utama tetap 0 pengguna sesudah tes.
8. Super Admin utama kemudian dibuat dan login berhasil oleh pengguna. Jangan jalankan bootstrap lagi. Audit tidak membaca password/token; dukungan multi-admin dan role dosen diverifikasi pada database test. Rincian audit aplikasi utama terdapat di `docs/AUDIT-UTAMA.md`.

Catatan lama di bagian demo tetap berguna bila kelak memerlukan lingkungan latihan, tetapi aplikasi yang harus dibuka/dikelola sekarang adalah port 8000 dan database utama. Server utama dapat dihentikan dari process sidebar; jangan menjalankan server lain pada port yang sama.

## B. Menjalankan Aplikasi

### B1. Prasyarat

Gunakan PHP minimal 8.3, Composer 2, MySQL yang dapat dihubungi, dan Node kompatibel Vite 8 (`^20.19.0` atau `>=22.12.0`). MySQL lokal telah diuji; MariaDB dan PHP Apache MAMP harus diperiksa terpisah. CLI Homebrew PHP tidak membuktikan PHP yang dipakai Apache MAMP sama.

**BACA**, dari root proyek:

```sh
pwd
php -v
composer -V
node --version
npm --version
composer check-platform-reqs
php artisan migrate:status
```

Jika terminal belum berada di root, jalankan `cd /Applications/MAMP/htdocs/WebAplikasi` sekali terlebih dahulu. Sesudah itu jalankan setiap command dari folder ini.

Koneksi database aktif saat ini memakai port **3306**, bukan port MySQL MAMP yang sering **8889**. Jangan mengubahnya hanya karena proyek berada di folder MAMP. Periksa MAMP Preferences/Ports bila ingin pindah menggunakan MySQL bawaan MAMP. Port database tidak dimasukkan ke URL browser.

### B2. Jalur Paling Sederhana Sekarang

Dependensi dan build sudah tersedia. Untuk membuka aplikasi saat ini cukup **JALANKAN**:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Buka **`http://127.0.0.1:8000/#/login`**. APP_URL saat inspeksi adalah `http://localhost:8000`; untuk server dengan host di atas gunakan URL `127.0.0.1` secara konsisten. Jika terminal menyatakan port 8000 sibuk dan memakai port berikutnya, ikuti URL aktual yang dicetak, atau hentikan server lama yang Anda kenal. Jangan mematikan sembarang proses.

**Tidak perlu menjalankan Vite dev server** untuk build saat ini. Halaman Blade membaca `public/build/manifest.json`, lalu memuat aset hasil build. Mengubah file JS/CSS tidak mengubah aset ini sampai Anda build ulang.

Jika dependensi hilang pada instalasi/mesin baru, **UBAH file dependensi lokal**, bukan database:

```sh
composer install
npm ci
npm run build
```

Jangan menjalankan `composer setup` pada instalasi ini: script skeleton juga memanggil `key:generate`. Jangan mengganti APP_KEY yang sudah digunakan atau menimpa `.env` hanya untuk menjalankan proyek. Tidak diperlukan migration/seeding ulang untuk sekadar membuka database utama saat ini.

### B3. Saat Mengedit Tampilan

Terminal pertama tetap menjalankan Laravel. Terminal kedua **JALANKAN**:

```sh
npm run dev
```

Tetap buka URL Laravel, **bukan URL Vite sebagai halaman aplikasi**. Vite hanya menyediakan aset dan hot reload. Sesudah selesai mengedit, hentikan Vite dan **UBAH aset build**:

```sh
npm run build
```

### B4. Menghentikan Dengan Aman

Di terminal server Laravel tekan **Ctrl+C**. Di terminal Vite tekan **Ctrl+C** juga. Ini menghentikan server, bukan menghapus database. Jika memakai Apache MAMP, gunakan Stop Servers pada MAMP; pertimbangkan aplikasi lain yang juga menggunakan MySQL tersebut. Jangan force-kill MySQL saat transaksi/backup berlangsung.

### B5. Memakai Apache MAMP

Atur document root/vhost menjadi **`/Applications/MAMP/htdocs/WebAplikasi/public`**, bukan root proyek. Root proyek mengandung konfigurasi dan source yang tidak boleh dibuka web. `public/.htaccess` menyediakan rewriting dan meneruskan header Authorization. URL Apache mengikuti port aktual MAMP, sering 8888; konfigurasi Apache ini belum diverifikasi pada audit sebelumnya.

Build Vite tetap diperlukan, versi PHP MAMP harus memenuhi requirement, dan Apache harus dapat menulis `storage` serta `bootstrap/cache`. Jangan memakai `chmod 777` sebagai solusi. Jangan menautkan `storage/app/private` ke public.

### B6. Bila Terjadi Error

| Gejala | Langkah BACA dahulu | Tindakan yang mungkin diperlukan |
| --- | --- | --- |
| Halaman tidak terbuka | Lihat URL/status terminal Laravel; pastikan MySQL berjalan; coba `/up` | Jalankan server yang benar. `/up` hanya health Laravel, bukan tes database lengkap. |
| Login gagal | Database utama belum ada akun; periksa email/status akun lewat UI admin setelah tersedia | Bootstrap admin atau registrasi. Password hash tidak dapat dibaca kembali; tidak ada reset-password UI saat ini. |
| `401` | Token hilang/kedaluwarsa atau sesi tab lain menggantikannya | Login ulang. Jangan membagikan token dari DevTools. |
| `403` | Lihat status identitas, keanggotaan, role, penugasan, kepemilikan, konflik | Selesaikan tahap yang diperlukan; jangan menaikkan role hanya untuk melewati penolakan. |
| `429` | Baca waktu tunggu pada UI | Tunggu; jangan spam. Logout tetap bisa saat cooldown keamanan, tetapi limiter umum tetap berlaku. |
| Database connection refused | Periksa host/port nonrahasia dan status MySQL | Sesuaikan `.env` hanya jika koneksi memang salah, lalu `php artisan config:clear` dan restart server. |
| Unknown database/table | `php artisan migrate:status`; pastikan schema yang dipilih benar | Jangan membuat ulang/reset schema yang berisi data. Migration hanya setelah backup dan target diverifikasi. |
| CSS/JS hilang | Pastikan `public/build/manifest.json` ada; cek Network browser; periksa apakah Vite masih diperlukan oleh `public/hot` | Build ulang; hentikan Vite dengan baik. Jangan mengedit file aset ber-hash secara langsung. |
| KTM/lampiran ditolak | Periksa format/ukuran dan permission | KTM JPG/PNG/PDF 4 MB; laporan sampai lima file 8 MB/file. Batas multipart PHP/web server harus lebih besar dari total file. |

**BACA** untuk struktur route dan log yang aman:

```sh
php artisan route:list --path=api --except-vendor
php tests/Support/audit-diagnostics.php
```

Diagnostic hanya mencetak kategori log, bukan isi/credential. Log operasional berada di `storage/logs/laravel.log`. Bila perlu melihatnya, lakukan lokal; jangan menampilkan seluruh log saat proyektor aktif atau mengirim stack trace/PII ke teman. DevTools Network boleh dipakai untuk melihat status response, tetapi sembunyikan header Authorization, password request, KTM, dan isi laporan.

## C. Memperoleh Akun Dan Login Empat Role

### C1. Urutan Setup Yang Tidak Buntu

1. Buat **Super Admin pertama** lewat command interaktif.
2. Daftarkan akun terpisah untuk petugas verifikasi dan dosen menggunakan form registrasi biasa; default keduanya masih mahasiswa.
3. Admin mengubah role masing-masing melalui **Pengguna & role**. Pemilik akun logout/login lagi sesudah perubahan agar UI segar.
4. Admin membuat kelas dengan periode yang tersedia, kemudian menugaskan dosen ke kelas.
5. Calon mahasiswa dan calon ketua mendaftar, mengunggah KTM, lalu petugas verifikasi yang berbeda menyetujui.
6. Admin/dosen menerbitkan kode undangan. Mahasiswa/calon ketua memasukkan kode tersebut lalu admin/dosen menyetujui keanggotaan.
7. Admin menetapkan salah satu anggota aktif terverifikasi sebagai ketua. Penetapan ini juga mengubah role mahasiswa menjadi ketua.

Jangan menunggu ketua menyetujui keanggotaan ketua pertama: admin/dosen yang telah berwenang dapat melakukannya. Jangan memberi role `class_leader` saja dan menganggap penugasan kelas otomatis ada.

### C2. Super Admin Pertama

Database utama belum mempunyai admin; role sudah tersedia. Jika Anda memilih memakai database utama, **UBAH database dengan akun baru**, jalankan:

```sh
php artisan app:bootstrap-admin
```

Terminal meminta nama, email pilihan Anda, password, dan konfirmasi. Password tidak terlihat ketika diketik; itu perilaku yang benar. Gunakan password unik minimal 12 karakter dengan huruf besar/kecil, angka, dan simbol, lalu simpan di password manager pribadi. Tidak ada password produksi dalam panduan ini.

Command membuat akun baru, tidak mengambil alih akun yang sudah ada. Command menolak email yang sudah dipakai, role belum tersedia, terminal non-interaktif, atau admin telah tersedia. Transaksi/lock melakukan pemeriksaan admin ulang sehingga pemanggilan ulang tidak membuat admin pertama kedua. Password di-hash melalui model User; audit menyimpan referensi role, bukan password. Akses command bergantung pada operator terminal/host, sehingga terminal itu harus dijaga.

Alternatif `--name` dan `--email` tersedia, tetapi Anda boleh memakai pertanyaan interaktif seluruhnya. Tidak ada opsi `--password`; jangan menaruh password di argumen terminal/history. Jika ingin database demo terpisah, **jangan bootstrap utama dahulu**; ikuti bagian D.

### C3. Tabel Empat Role

| Peran | Cara memperoleh akun | Prasyarat | Cara login | Dashboard | Fitur utama |
| --- | --- | --- | --- | --- | --- |
| Mahasiswa (`student`) | Registrasi mandiri `#/register`; tidak memilih role | Untuk fitur kelas: akun aktif, KTM disetujui, anggota kelas aktif | Email/password milik akun di `#/login` | Ringkasan laporan sendiri dan aspirasi kelas; onboarding bila belum lengkap | Pengaduan sendiri, klarifikasi, banding, aspirasi/comment/upvote/flag |
| Ketua (`class_leader`) | Admin menetapkan mahasiswa melalui Kelola kelas → Tetapkan ketua | Aktif, identitas verified, anggota aktif kelas tersebut, record penugasan ketua aktif | Email/password akun mahasiswa yang sama | Tugas penanganan, permintaan keanggotaan, flag kelas | Laporan routine yang ditugaskan, moderasi, undangan, persetujuan anggota |
| Dosen (`lecturer_coordinator`) | Registrasi biasa → admin mengubah role → admin menugaskan kelas | Aktif, role dosen dan penugasan dosen yang telah mulai; laporan privat memerlukan penugasan laporan | Email/password akun dosen | Laporan perlu perhatian, distribusi status, banding | Penugasan/pengalihan, penanganan, reviewer banding eligible, moderasi/anggota kelas |
| Super Admin (`super_admin`) | Bootstrap pertama; admin berwenang dapat menetapkan role akun lain sesudahnya | Aktif dan role admin; tidak memerlukan KTM/anggota untuk administrasi sistem | Email/password bootstrap atau akun sendiri | Jumlah akun/kelas/verifikasi pending dan security teknis | Pengguna/role, kelas/penugasan, audit/security, suspend/reactivate |

**Petugas verifikasi:** registrasi biasa, lalu admin memilih role `identity_verifier`. Login menampilkan antrean KTM. Petugas dapat mengunduh dan memutuskan identitas selain dirinya sendiri, bukan menangani laporan privat. Jangan mencampur akun ini dengan admin atau ketua.

### C4. Mahasiswa Langkah Demi Langkah

1. Buka `#/register`, isi nama, email unik, NIM, password, dan konfirmasi. Akun awal selalu mahasiswa/unverified; input role dari klien tidak memberi hak tambahan.
2. Pada **Akun & verifikasi**, unggah KTM sesuai format dan batas. Untuk demo gunakan gambar/PDF berlabel **DOKUMEN FIKTIF**, bukan KTM mahasiswa asli.
3. Status berubah **Menunggu verifikasi**, bukan langsung verified. Akun petugas lain membuka **Verifikasi identitas**, memeriksa file, lalu setuju atau tolak dengan alasan aman.
4. Mahasiswa muat ulang akun. Persetujuan menyelaraskan nama/NIM dengan dokumen pengajuan yang diperiksa; nama terverifikasi tidak bisa diganti lewat profile biasa.
5. Mahasiswa memasukkan **kode undangan** dari admin/dosen/ketua. Katalog kelas hanya referensi; pilihan dropdown tidak menentukan kelas. Kode undanganlah yang menentukan kelas.
6. Status keanggotaan pending sampai admin/dosen/ketua aktif menyetujuinya. Kode `join_code` hasil create class bukan kode yang dipakai join; gunakan kode dari **Buat kode undangan**.
7. Setelah verified + anggota aktif + permission sesuai, fitur kelas terbuka.

Login dapat dilakukan sebelum verified, tetapi akses kelas belum tersedia. Mahasiswa tidak bisa menyetujui KTM/anggota sendiri, memberi role, membaca laporan mahasiswa lain, atau mengubah status penanganan laporan. Aman untuk menguji penolakan satu kali terhadap laporan fiktif mahasiswa lain; jangan berulang-ulang sampai presentasi terkunci cooldown.

### C5. Ketua Langkah Demi Langkah

1. Selesaikan onboarding calon ketua seperti mahasiswa.
2. Admin buka **Kelola kelas**, pilih kelas yang benar.
3. Cari ID calon pada **Pengguna & role**; ID pengguna bukan NIM.
4. Masukkan ID pada **Tetapkan ketua**, baca dialog, lalu konfirmasi.
5. Simpan **ID penugasan** hasil penetapan sebagai referensi operasional non-password. Logout/login calon ketua.

Sistem mengakhiri penugasan ketua aktif lama, bukan menghapus riwayat, dan menjaga satu ketua aktif per kelas melalui service/model/constraint. Ketua tidak mempunyai hak semua kelas, tidak otomatis boleh membaca laporan sensitif atau laporan yang melibatkan dirinya, dan tidak boleh memoderasi konten sendiri. Laporan routine dapat diarahkan otomatis hanya jika ketua aktif/layak dan bukan pelapor/pihak terlapor.

Untuk mencabut: admin pada Kelola kelas menggunakan **Cabut penugasan ketua berdasarkan ID**, memasukkan ID penugasan (bukan ID user), alasan, dan konfirmasi. Endpoint ini tidak otomatis terikat kelas yang sedang tampil; periksa referensi. Pencabutan tidak otomatis mengubah role kembali menjadi mahasiswa; hak pengelolaan ditutup oleh pemeriksaan penugasan aktif. Setelah itu ubah role ke mahasiswa jika diperlukan. Mengubah role saja juga tidak menghapus record penugasan lama, jadi kelola keduanya.

### C6. Dosen Langkah Demi Langkah

1. Dosen mendaftar lewat form biasa dengan akun sendiri. Saat belum diberi role, status default masih mahasiswa; tidak diperlukan KTM mahasiswa untuk kewenangan dosen yang ditugaskan.
2. Admin buka **Pengguna & role**, cari akun, buka Kelola akses, pilih Dosen koordinator dan isi alasan perubahan minimal 10 karakter.
3. Admin buka kelas, masukkan ID dosen pada **Tugaskan dosen**, pilih koordinator/asisten, dan konfirmasi. Akun target harus aktif dan role dosen.
4. Dosen logout/login; kelas di dashboard berasal dari penugasan, bukan keanggotaan mahasiswa.
5. Untuk baca privat/tindak lanjut, dosen perlu **penugasan laporan** yang sesuai. Melihat metadata kelas bukan berarti isi terbuka. Form penugasan menerima ID yang sudah diketahui; tidak ada pencarian staff dosen yang dibuat-buat.

Jika laporan menyebut dosen sebagai pihak terlibat, policy/konflik menolak baca/penanganan sesuai aturan. Gunakan dosen lain yang ditugaskan ke kelas dan bebas konflik. Nama/free-text masih heuristik: homonim/alias perlu pemeriksaan manusia, bukan klaim deteksi sempurna.

Banding setelah rejected/resolved harus ditinjau reviewer berbeda dari pelapor/penangan terdahulu/pihak berkonflik. Satu dosen tambahan perlu disiapkan bila dosen pertama menangani laporan awal. Super Admin bukan reviewer pengganti otomatis. Form banding membutuhkan ID handoff; mode `#/appeals/ID?mode=assign` dapat mengambil calon eligible sebelum isi dibuka. Penugasan penangan biasa ditolak selama banding terbuka.

**Pencabutan penugasan dosen belum ada di UI/API.** Admin dapat menonaktifkan akun agar akses dan token dicabut, atau mengubah role untuk menutup kewenangan terkait, tetapi itu bukan penghapusan assignment. Jangan mengedit SQL secara sembarang; usulan endpoint revoke scoped dengan audit adalah gap prioritas tinggi.

### C7. Super Admin Dan Batasnya

Admin melihat pengguna/role, kelas/penugasan, undangan, permintaan anggota, security dan audit yang diizinkan. Admin **tidak otomatis dapat membaca isi laporan privat, KTM, atau memutuskan hasil/banding**. Admin juga tidak dapat mengubah role/suspend dirinya sendiri melalui endpoint yang tersedia.

Mengubah role pengguna lain adalah tindakan eksplisit dengan alasan dan audit. Akun nonaktif dapat diaktifkan kembali; suspensi mencabut seluruh token. Pengguna login ulang sesudah reaktivasi. Tidak ada tombol delete account. Jangan menggunakan akun administrator sebagai mahasiswa/dosen untuk semua tahap demo.

## D. Menyiapkan Akun Demo Dengan Aman

### D0. Status Prosedur Sekarang

Database `pengaduan_unpam_demo`, `.env.demo`, sembilan migration, katalog normal, build, dan server 8010 **sudah siap**. Jangan ulangi langkah create database, migration, atau seed di bawah pada environment ini. Langkah yang masih tertunda adalah:

```sh
php artisan app:bootstrap-admin --env=demo
```

Jalankan sendiri pada terminal interaktif ketika siap, isi nama/email/password pribadi, lalu jangan mengirim password ke chat. Setelah command berhasil, muat login port 8010. Asisten dapat memverifikasi jumlah/role akun dan respons login tanpa membaca hash/password, tetapi login credential memerlukan input Anda sendiri.

### D1. Mekanisme Yang Sudah Ada

`DemoDataSeeder` hanya untuk database local/testing tanpa users/classes. Password akun acak tidak dicetak dan tidak diketahui, sehingga **bukan mekanisme akun presentasi yang siap login**. `tests/Support/Domain.php` adalah helper test, bukan jalur operator. `npm run test:live` membuat akun fiktif sementara lalu membersihkan database/file; **jangan memakai schema audit sebagai database presentasi**.

Belum ada command interaktif khusus yang menyiapkan beberapa akun demo persisten dengan password yang Anda atur. Jalur tanpa kode baru adalah memakai database demo terpisah dan onboarding normal. Tidak ada akun demo dibuat pada pekerjaan ini; password/input manual merupakan langkah Anda sendiri.

### D2. Prosedur Manual Pada Database Terpisah

**UBAH**, sebelum menjalankan: pilih nama schema baru yang belum ada. Contoh nama di bawah adalah usulan database, **bukan database yang sudah dibuat**. Lewat klien MySQL/phpMyAdmin, buat:

```sql
CREATE DATABASE pengaduan_unpam_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Jika schema sudah ada, berhenti dan periksa isinya, jangan reset/drop. Biarkan `pengaduan_unpam` tetap utuh. Tidak ada perintah reset dalam prosedur ini.

**UBAH file lokal**, salin konfigurasi yang sudah berfungsi tanpa menimpa file lain:

```sh
cp -n .env .env.demo
```

Periksa `.env.demo` secara lokal tanpa menampilkan rahasianya. Ubah hanya target demo dan nama/URL yang diperlukan:

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8010
DB_DATABASE=pengaduan_unpam_demo
CACHE_STORE=database
SESSION_DRIVER=database
```

Pertahankan koneksi/password dari konfigurasi lokal yang benar; jangan menyalinnya ke laporan. Simpan `.env.demo` privat, jangan bagikan atau commit. Private disk pada source masih memakai root `storage/app/private`; data demo dipisah schema, bukan otomatis seluruh filesystem. Gunakan file fiktif, jangan hapus seluruh folder private untuk membersihkan demo.

**BACA** target sebelum migration:

```sh
php artisan tinker --env=demo --execute='dump(config("database.connections.mysql.database"));'
```

Hasil wajib `pengaduan_unpam_demo`. Jika bukan, berhenti. Konfigurasi cached/external environment dapat menimpa target. Saat ini cache konfigurasi tidak aktif; jika perlu `php artisan config:clear` menghapus cache global konfigurasi, jadi hentikan server lain dahulu dan periksa ulang. Jangan melanjutkan migration hanya berdasarkan nama file `.env.demo`.

**UBAH database demo baru saja**:

```sh
php artisan migrate --env=demo
php artisan db:seed --env=demo
php artisan app:bootstrap-admin --env=demo
```

Command terakhir memerlukan nama/email/password Anda sendiri. **Berhenti pada prompt ini untuk input manual**. Tidak ada password contoh yang ditetapkan oleh panduan.

**JALANKAN** setelah target verified:

```sh
php artisan serve --env=demo --host=127.0.0.1 --port=8010 --no-reload
```

`--no-reload` dipakai agar environment demo yang sudah dibaca diwariskan ke proses server; jangan mengubah konfigurasi ketika server berjalan. Buka `http://127.0.0.1:8010/#/login`. Jangan memakai Apache MAMP dengan asumsi ia otomatis membaca `.env.demo`; command ini khusus Artisan. Gunakan build yang sudah ada, tidak perlu Vite untuk demo.

### D3. Jumlah Akun Dan Data Fiktif

Empat akun utama: mahasiswa, calon ketua, dosen, admin. Untuk onboarding nyata tambahkan **satu petugas verifikasi**. Untuk banding/konflik independen tambahkan **satu dosen alternatif**. Untuk bukti lintas kelas yang bermakna tambahkan mahasiswa kelas kedua bila diperlukan. Jangan mengarang bahwa empat akun saja cukup untuk seluruh tahap verifikasi dan banding.

Daftarkan semua non-admin lewat `#/register`; password unik dipilih oleh pemilik/operator manual. Form registrasi mensyaratkan NIM; untuk akun demo staff gunakan identifier fiktif yang jelas, bukan NIM asli. Itu batas form umum, bukan klaim dosen/verifikator adalah mahasiswa resmi. Jangan menyimpan password di Git/source/log atau slide; gunakan password manager dan profil browser terpisah.

**Batas nyata:** registrasi publik maksimal tiga request per 60 menit per signature IP/domain, login maksimal sepuluh request/menit, ditambah pembatasan internal login. Empat atau lebih akun non-admin perlu disiapkan jauh sebelum presentasi, mengikuti jeda kuota; jangan memalsukan IP atau menaikkan limiter di produksi agar demo cepat. Bootstrap admin tidak memakai route registrasi. Perubahan role tidak memerlukan registrasi ulang.

| Akun demo | Dibuat lewat | Pengaturan tambahan | Penanggung jawab password |
| --- | --- | --- | --- |
| Super Admin | Bootstrap di database demo | Tidak ada KTM/anggota untuk administrasi | Anda/operator pemilik terminal |
| Mahasiswa | Registrasi | KTM fiktif, verified, anggota kelas A aktif | Pemilik/operator akun |
| Ketua | Registrasi | KTM fiktif, verified, anggota A, ditetapkan admin sebagai ketua | Pemilik/operator akun |
| Dosen | Registrasi → role dosen | Assignment kelas A, penugasan laporan bila menangani | Pemilik/operator akun |
| Verifikator pendukung | Registrasi → role verifikator | Independen dari pemilik KTM | Pemilik/operator akun |
| Dosen alternatif pendukung | Registrasi → role dosen | Assignment A, belum menjadi penangan awal laporan banding | Pemilik/operator akun |

Contoh data presentasi: kelas bernama **Kelas Demo A**, laporan **Proyektor ruang kelas tidak berfungsi**, isi kejadian fiktif, dan aspirasi **Jadwal belajar bersama**. Gunakan tanggal/waktu fiktif yang jelas. Tidak perlu memasukkan data siswa/dosen sebenarnya.

### D4. Seusai Presentasi

Logout setiap akun lalu hentikan server demo. Anda dapat menonaktifkan akun non-admin melalui UI; itu menghapus token akses, bukan semua riwayat. Tidak ada fitur delete account/cleanup demo lengkap. Jangan mencoba menghapus admin sendiri melalui UI atau menghapus data utama.

Untuk membuang seluruh environment demo, lakukan hanya setelah schema yang tepat diverifikasi, backup disepakati, dan Anda memberi otorisasi penghapusan eksplisit. Pembersihan file harus berdasarkan referensi database demo, bukan seluruh private root. Panduan ini sengaja tidak memberi prosedur drop otomatis atau menjalankannya.

**Usulan minimal, belum diimplementasikan:** command `app:prepare-demo` yang hanya menerima schema demo yang ditentukan, menolak produksi/config cached/database berisi akun, meminta password melalui `secret()`, membuat akun baru/assignment fiktif dalam transaksi, tanpa credential log/default; cleanup terpisah dengan dry-run dan persetujuan. Nama command ini **usulan**, bukan command yang dapat dijalankan sekarang. Implementasi yang memutasi database memerlukan persetujuan Anda.

## E. Mengganti Nama Dan Identitas Visual

Tidak ada nama/logo institusi yang diubah pada pekerjaan ini. “Ruang Dengar” masih statis pada beberapa tempat; `APP_NAME` tidak otomatis mengubah semua teks frontend. Hindari mengganti seluruh string JavaScript secara buta: nama field, API, status, permission, dan route bukan branding.

| Yang diubah | File/lokasi aktual | Jenis dan contoh perubahan | Cara cek / build |
| --- | --- | --- | --- |
| Nama Laravel | `.env` atau `.env.demo`; `config/app.php:16` membaca APP_NAME | Contoh usulan `APP_NAME="Ruang Aspirasi Demo"`; jangan ubah APP_KEY | `php artisan config:clear`, restart server; tidak perlu build hanya untuk setting ini |
| Logo teks di boot/login/sidebar | `resources/views/app.blade.php:15`; `resources/js/app.js` fungsi `shell()` dan `authPage()`; CSS `.brand-symbol` | Teks saat ini `rd.`; dapat mengganti monogram milik sendiri. Gambar logo belum dipasang | JS/CSS perlu `npm run build`; Blade refresh browser |
| Logo gambar | Belum ada image logo aplikasi | Usulan file milik sendiri di public dan `<img ... alt="Nama aplikasi">` pada tempat logo. Jangan memakai logo resmi tanpa izin | Perubahan struktur UI memerlukan edit terkontrol, build JS, cek mobile/alt |
| Favicon | `public/favicon.ico` ada; Blade belum memasang link favicon eksplisit | Ganti file milik sendiri dengan format sesuai. Browser sering meminta root favicon; untuk subpath disarankan link Blade `{{ asset('favicon.ico') }}` | Refresh/cache browser; file public tidak butuh Vite. Tambah link merupakan perubahan kode yang belum dilakukan |
| Judul browser awal | `resources/views/app.blade.php:9` | `<title>Ruang Dengar \| Pengaduan Mahasiswa</title>` | Refresh; JS kemudian dapat menimpa title |
| Judul browser setelah navigasi | `resources/js/app.js` `context().page()` dan `authPage()` | Suffix `\| Ruang Dengar`, judul Masuk/Daftar | Build JS, buka login dan dashboard, cek tab browser |
| Navbar/sidebar/brand | `resources/js/app.js` `shell()`; label nav di `resources/js/ui.js::navigation()` | Ganti teks brand/breadcrumb yang statis, jangan `path` atau permission | Build, cek semua role dan mobile |
| Login/register | `resources/js/app.js:161–193` fungsi `authPage()` | “Didengar. Ditangani. Lebih baik.”, judul/deskripsi formulir, disclaimer | Build; cek kedua mode dan ukuran kecil |
| Warna/font | `resources/css/app.css:6–22`, selector komponen berikutnya | Ubah `--green`, `--ink`, font sistem, lalu cek selector yang masih mempunyai warna literal | Build CSS; periksa kontras/focus/reduced motion |
| Footer | `resources/js/app.js::shell()` class app-footer dan auth-disclaimer | Ganti teks footer yang statis tanpa menambah klaim resmi kampus | Build JS, lihat login/dashboard |
| Teks dashboard | `resources/js/app.js::dashboard()` dan `metricLabels` | Heading/welcome/deskripsi statis dapat diedit; angka tetap dari `/dashboard` | Build JS; jangan hard-code jumlah untuk memalsukan data |
| Kelas/semester | UI Kelola kelas, `resources/js/pages/management.js`, `ClassroomService` | Data DB `classes` dan `academic_terms`, bukan string tampilan umum; modelnya ClassRoom | Simpan lewat UI admin untuk kelas; tidak perlu build. Periode baru belum ada UI tulis |
| Kontak/profil | `#/account` → profile; `account.js` dan `AuthController::updateProfile` | Hanya profil pengguna nama/telepon. Belum ada halaman kontak organisasi atau config profil aplikasi | Nama terkunci pada kondisi tertentu; telepon lewat UI tanpa build |

Contoh perubahan CSS yang aman untuk diuji, **belum diterapkan**:

```css
:root {
    --green: #245b63;
    --ink: #20393c;
}
```

Banyak warna literal masih ada di CSS, jadi dua variabel bukan jaminan tema seluruh halaman berubah. Periksa status badge, hover, disabled, dan kontras. Jangan menghilangkan `prefers-reduced-motion`, label/focus, atau error UI ketika mengubah desain.

Untuk menghindari duplikasi branding ke depan, usulan minimal adalah Blade mengirim nama/monogram dari config nonrahasia ke shell, kemudian JS membaca nilai itu untuk brand dan document.title. **Belum dilakukan**, karena permintaan sekarang panduan dan belum ada identitas baru yang Anda berikan. Jangan menambahkan logo resmi atau mengubah identitas institusi diam-diam.

Sesudah edit source JS/CSS:

```sh
npm run build
```

Reload browser dan periksa login, register, dashboard setiap role, footer, favicon, serta layar 390 px. Jangan edit `public/build/assets/app-*.js`/CSS hasil build: perubahan hilang ketika Vite membangun ulang.

## F. Administrasi Sehari-hari

| Tugas | Menu / mekanisme aktual | Siapa / batas |
| --- | --- | --- |
| Daftar/cari pengguna | Pengguna & role; `/admin/users` | Admin; data akun administratif, bukan KTM/isi laporan |
| Setujui/tolak identitas | Verifikasi identitas; `/identity-verifications/{id}/approve` atau reject | Petugas `identity.verify`, bukan pemilik; menolak KTM tidak otomatis menghapus akun |
| Buat/ubah kelas | Kelola kelas; `/admin/classes`, PATCH id | Admin; periode ID diambil katalog, bukan asumsi selalu 1 |
| Setujui/tolak anggota | Kelas → Permintaan keanggotaan | Admin/dosen/ketua aktif dalam scope; tidak menyetujui sendiri; penolakan membutuhkan catatan |
| Tetapkan ketua | Kelas → Penugasan pengelola | Admin; verified + anggota aktif target; ketua lama diakhiri |
| Tetapkan dosen | Kelas → Tugaskan dosen | Admin; role dosen harus diberikan dahulu |
| Buat undangan | Kelas → Terbitkan kode undangan | Admin/dosen/ketua aktif; isi masa berlaku/batas penggunaan; kode ditampilkan sementara, bagikan terbatas |
| Ganti role | Pengguna & role → Kelola akses | Admin ke akun lain dengan alasan; bukan assignment otomatis |
| Nonaktifkan/aktifkan | Pengguna & role | Admin ke akun lain; suspend mencabut token, reactivate tidak mengembalikan token lama |
| Moderasi aspirasi/comment | Ruang aspirasi → detail → laporan/moderasi | Ketua/dosen ditugaskan; tidak moderasi sendiri; harus beda konten/kelas sesuai scope |
| Notifikasi | Notifikasi | Masing-masing penerima; mark read/all bukan izin baru terhadap resource |
| Security | Keamanan; `/security-events` | Admin teknis global, ketua/dosen kelas dalam scope aktif; bukan monitor kesehatan server |
| Audit | Audit aktivitas; `/admin/audit-logs` | Admin, baca saja, metadata aman; tidak edit/delete audit |
| Status dasar | `/up`, terminal server, `migrate:status` | Operator; `/up` bukan monitor DB/backup/queue menyeluruh |
| Config nonrahasia | `config/`, `.env` lokal yang dijaga | Operator terminal; belum ada UI config. Periksa/restart setelah perubahan |

### F1. Backup Dan Uji Pemulihan

Belum ada fitur backup/restore UI atau jadwal backup yang dikonfigurasi. Klien `mysqldump` dan `mysql` tersedia pada mesin saat inspeksi. **Bagian ini petunjuk, backup/restore belum dijalankan atau dibuktikan pada pekerjaan panduan.** Jangan menjanjikan backup selesai sebelum memeriksa filenya dan restore di schema terpisah.

Pilih folder pribadi **di luar public/root proyek yang disajikan web**, dengan permission terbatas dan enkripsi. Nama akun `NAMA_AKUN_DB_ANDA` dan path `/path/pribadi/...` di bawah adalah placeholder yang harus Anda ganti, bukan kredensial tersedia. `-p` meminta password secara tersembunyi; jangan menuliskan password setelah flag atau di history shell.

**BACA database, TULIS file backup**, contoh schema utama saat ini:

```sh
mysqldump --host=127.0.0.1 --port=3306 --user=NAMA_AKUN_DB_ANDA -p --single-transaction --no-tablespaces --routines --events --triggers --hex-blob --result-file="/path/pribadi/pengaduan-20261022.sql" pengaduan_unpam
```

Siapkan direktori privat yang benar dahulu; gunakan nama file baru agar backup lama tidak tertimpa. Jika operator tidak mempunyai izin dump routines/events, minta prosedur administrator, jangan memakai password root publik. MySQL MAMP 8889 memerlukan port dan binary klien yang sesuai. Snapshot SQL tidak mencakup file KTM/lampiran.

**BACA file privat, TULIS arsip**, contoh:

```sh
tar -czf "/path/pribadi/private-files-20261022.tar.gz" storage/app/private
```

Arsip ini belum terenkripsi hanya karena format tar.gz; enkripsikan dengan tooling yang disetujui. Lindungi konfigurasi/key di secret store terpisah. Untuk snapshot DB+file konsisten, hentikan mutasi/server aplikasi selama backup atau gunakan prosedur maintenance/backup terkoordinasi. Jangan menghapus file privat setelah backup.

**UBAH hanya schema restore baru**, setelah nama dipilih/verifikasi tidak ada, buat melalui klien:

```sql
CREATE DATABASE pengaduan_unpam_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Lalu restore ke schema baru, **bukan database utama**:

```sh
mysql --host=127.0.0.1 --port=3306 --user=NAMA_AKUN_DB_ANDA -p pengaduan_unpam_restore_check < "/path/pribadi/pengaduan-20261022.sql"
```

Bandingkan jumlah/relasi tabel melalui klien lokal, jalankan aplikasi pada environment restore terpisah, dan pulihkan file ke root terisolasi yang disepakati. Config private disk sekarang hard-coded shared path, jadi jangan menjalankan percobaan restore file ke storage aktif. Detail isolasi filesystem dan restore lengkap perlu prosedur operator sebelum menyatakan backup tervalidasi. Jangan menampilkan isi dump atau file KTM saat presentasi.

## G. Skenario Presentasi 10–15 Menit

Selesaikan provisioning sebelum presentasi. Gunakan profil browser berbeda untuk mahasiswa/ketua/dosen/admin agar tidak perlu mengetik password di layar atau berbagi sessionStorage. Login baru dengan `device_name` sama pada akun yang sama dapat mencabut token tab sebelumnya. Akun berbeda tidak memiliki masalah itu. Jangan memakai empat tab sebagai empat role pada akun yang sama.

| Waktu | Skenario | Kalimat/kontrol yang ditunjukkan |
| --- | --- | --- |
| 0–1 menit | Tujuan dan batas | “Pengaduan bersifat privat; aspirasi ruang bersama per kelas. Verifikasi manual, bukan API resmi kampus.” |
| 1–3 menit | Mahasiswa terverifikasi/anggota aktif login, membuat laporan fiktif | Jelaskan status/timeline/lampiran. Jangan unggah identitas nyata atau tampilkan token. |
| 3–4 menit | Mahasiswa membuat aspirasi fiktif | Bedakan isi bersama dan identitas penulis dari laporan privat; jangan salin laporan sensitif. |
| 4–6 menit | Ketua login | Lihat routine report yang ditugaskan, minta klarifikasi atau tampilkan timeline. Hak terbatas kelas/assignment dan bukan laporan sensitif otomatis. |
| 6–8 menit | Dosen login | Lihat metadata, tetapkan penangan yang layak, tampilkan tindak lanjut dan usulan penyelesaian sesuai status. |
| 8–10 menit | Konflik dan banding | Dengan laporan rejected/resolved yang disiapkan, pelapor ajukan banding; dosen alternatif eligible ditetapkan. Reviewer bukan pelapor/penangan awal. Jangan mengarang reviewer jika belum tersedia. |
| 10–12 menit | Super Admin login | Pengguna/role, kelas/undangan, audit/security; tekankan admin tidak otomatis membaca KTM/isi laporan. Jangan mengganti role/suspend akun aktif saat demo hanya untuk efek. |
| 12–13 menit | Contoh penolakan aman | Sekali mencoba report fiktif kelas lain dari akun yang tak berhak; tunjukkan 403. Jangan menjalankan brute force/spam; cooldown dapat mengganggu demo. |
| 13–15 menit | Teknologi dan penutup | Laravel 13.37, MySQL, Blade, Vite/Tailwind/JS, Sanctum, policy+transaksi+audit. Jelaskan gap, hasil test, serta rencana deployment/retensi/accessibility. |

Tidak perlu seluruh workflow real-time dari nol. Persiapkan satu laporan routine baru, satu kasus banding, dan satu contoh kelas lain. Rekaman audio belum tersedia; penolakan visual dan backend tetap bekerja. Jika waktu sempit, jelaskan konflik/banding dengan timeline data fiktif yang sudah diproses, bukan berpura-pura keputusan terjadi.

## H. Peta File Dan Alur Program

### H1. File Penting

| File/folder | Fungsi | Kapan perlu diubah | Risiko jika salah mengubah |
| --- | --- | --- | --- |
| `routes/web.php` | `/` menyajikan Blade app | Entry halaman/server route | Salah root membuat aplikasi tidak terbuka |
| `routes/api.php` | Endpoint `/api/v1`, auth/permission/throttle | Tambah aksi API yang benar-benar diperlukan | Menghapus middleware dapat membuka akses atau mencampur kuota |
| `bootstrap/app.php` | Register route/alias middleware dan error JSON | Wiring/error API | Detail exception dapat bocor, auth berubah |
| `app/Http/Controllers/Api/V1/` | Menerima request, authorize, memanggil service, response | Aksi pengguna/API | Bypass policy atau envelope tak sesuai frontend |
| `app/Http/Requests/` | Validasi field/form server | Tambah/ubah field dan batas | Input tidak valid, atau input sah ditolak |
| `app/Http/Middleware/` | Cek aktif, identity/member, permission/cooldown | Aturan pintu masuk | Role/status tak berhak dapat lolos |
| `app/Policies/` | Izin resource berdasarkan user/kelas/assignment/owner | Aturan siapa boleh membaca/mengubah | Kebocoran privat/lintas kelas |
| `app/Providers/AppServiceProvider.php` | Map policy dan limiter bernama | Model/policy baru | Policy tidak dipakai, keputusan salah |
| `app/Services/` | Bisnis workflow/role/membership/routing/storage/audit | Perubahan alur transaksi | History/assignment/counter tidak konsisten |
| `app/Models/` | Eloquent tabel, relasi, casts, hidden fields | Data/relasi baru | Mass assignment, serialization rahasia, integrity |
| `app/Enums/` | Kode role/status/aksi/transition | Aturan state baru | Frontend/API/status lama tidak kompatibel |
| `app/Http/Resources/` | Bentuk JSON dan redaksi privat | API response berubah | Frontend rusak/identitas tersebar |
| `database/migrations/` | Struktur/constraint/index | Perubahan schema dengan migration baru | Jangan edit/reset migration lama untuk menghapus data |
| `database/seeders/` | Role/permission/category/term awal; demo terpisah | Katalog awal/provisioning | Seeder bisa menyinkronkan ulang permission; demo bukan akun login |
| `app/Console/Commands/BootstrapAdmin.php` | Membuat admin pertama aman | Bootstrap/provisioning resmi | Salah guard/password dapat mengambil alih akun |
| `resources/views/app.blade.php` | HTML awal/Vite/API base/title | Shell/brand awal | Aset/path API gagal |
| `resources/views/welcome.blade.php` | Artefak skeleton, bukan route `/` | Biasanya tidak perlu | Mengeditnya tidak mengubah aplikasi utama |
| `resources/js/app.js` | Router hash, auth/session, shell/dashboard/dialog | Teks/UI/sesi | Sesi stale, data privat di UI |
| `resources/js/api.js` | Fetch envelope/bearer/errors/audio | Integrasi API | Token/redirect/path/response tak aman |
| `resources/js/ui.js` | Helper field/badge/table/escaping/nav | Komponen reusable | Hilang escaping/label/accessibility |
| `resources/js/pages/*.js` | Reports, aspirations, account, management | Konten/form per modul | Endpoint/payload/status tidak cocok |
| `resources/css/app.css` | Theme, responsive, motion | Warna/font/layout | Kontras/mobile/reduced motion rusak |
| `vite.config.js` dan `package.json` | Entry/build/HMR/dependensi | Aset/toolchain | Build gagal atau script tidak sesuai |
| `.env`, `config/` | Koneksi/key/settings/permission/storage | Operator mengatur environment | Secret bocor atau menunjuk database salah |
| `public/build/` | Hasil produksi Vite | Dibangun otomatis | Edit manual hilang; bukan source |
| `storage/app/private` | KTM/lampiran yang dilindungi | Operator backup/retensi terotorisasi | Jangan link public/delete sembarang |
| `storage/logs`, `bootstrap/cache` | Log/cache framework | Diagnosis/maintenance | Log dapat mengandung data teknis; cache stale |
| `tests/`, `phpunit.xml` | Test di database sekali pakai | Regresi fitur | Salah target test dapat menghapus data |
| `docs/API.md`, `FRONTEND.md`, `AUDIT.md` | Contract/operasional/bukti | Perilaku berubah | Panduan stale memberi langkah salah |

### H2. Tabel Database Yang Relevan

`users` → akun dengan `role_id`, status identitas, aktif/nonaktif. `roles`, `permissions`, `permission_role` → katalog hak. `academic_terms` dan `classes` → periode/kelas. Model `ClassRoom` secara eksplisit memakai tabel `classes`, bukan `class_rooms`. `class_memberships` → status anggota. `class_administrator_assignments`, `lecturer_assignments` → tugas ketua/dosen. `class_invitation_codes` → kode undangan, berbeda dari kolom class join_code.

`identity_verifications` → riwayat pengajuan KTM. `reports` dan `report_private_details` → metadata/judul serta isi privat. `report_assignments`, `report_status_histories`, `report_updates`, `conflict_of_interest_records`, `appeals` → penanganan/jejak/konflik/banding. `aspirations`, `aspiration_comments`, `aspiration_supports`, `content_flags`, `moderation_actions` → ruang bersama. `attachments`, `notifications`, `audit_logs`, `security_events`, `personal_access_tokens` → file/notifikasi/audit/security/token. Model `AppNotification` memakai tabel `notifications`, bukan `app_notifications`.

Jangan mengubah status/role/assignment lewat SQL untuk menjalankan aplikasi: service memuat aturan yang tidak tersirat hanya dari kolom. Tampilan “private” bukan berarti database/backup otomatis terenkripsi.

### H3. Contoh Tombol Kirim Pengaduan

1. Form `resources/js/pages/reports.js` memeriksa field wajib, jumlah/ukuran file, dan menyiapkan FormData. Aksi yang sama dikunci agar klik ganda tidak mengirim dua kali.
2. `resources/js/api.js` mengirim `POST /api/v1/reports` dengan Bearer sesi; bukan cookie login frontend dan bukan token di URL.
3. Route memanggil middleware auth Sanctum, account aktif/cooldown, limiter umum/khusus, dan `class.access`. Token yang sah tidak otomatis menjamin izin kelas.
4. Laravel menyelesaikan `StoreReportRequest`: authorize/validasi input server dilakukan **sebelum body method controller berjalan**. Urutan ini bukan controller dahulu baru semua validasi.
5. `ReportController::store()` membaca data tervalidasi dan mengecek policy create, lalu memanggil `ReportService::create()`.
6. Service memeriksa ulang kelas/anggota/term, lock user dan transaksi DB. `reports` serta `report_private_details` disimpan, status awal submitted dan history dibuat.
7. `ReportRoutingService` menentukan routine/sensitive dan ketua eligible. Laporan dapat langsung under_review atau needs_review sesuai fakta, bukan semua status awal selalu sama pada response.
8. File disimpan lewat PrivateStorageService, metadata attachment ditulis, audit dicatat. Jika exception, transaksi rollback dan file yang direferensikan dibersihkan sesuai jalur service.
9. Controller mengirim JSON resource dengan status HTTP **201**. API client mengurai envelope; frontend menunjukkan hasil lalu navigasi ke detail. Bila 422/403/429/5xx, UI menampilkan kegagalan, bukan data sukses palsu.

### H4. Istilah Sederhana

| Istilah | Arti praktis |
| --- | --- |
| Route | Alamat dan metode yang menentukan aksi, misalnya POST reports |
| Controller | Penghubung HTTP dengan aturan bisnis dan response |
| Service | Tempat proses bisnis multi-langkah, misalnya routing/transaksi |
| Model | Representasi record tabel dan hubungan data di PHP |
| Migration | Catatan perubahan struktur tabel; bukan data mahasiswa |
| Seeder | Mengisi katalog awal; tidak otomatis akun login |
| Middleware | Pemeriksaan sebelum aksi seperti token/akun aktif/batas request |
| Policy | Aturan apakah user tertentu boleh mengakses resource tertentu |
| Gate | Cara Laravel menjalankan keputusan otorisasi/policy, bukan role baru |
| Request validation | Pemeriksaan input di server, terpisah dari browser |
| API | Kontrak komunikasi browser-server melalui request/JSON/binary |
| Session frontend | Token Bearer per-tab di sessionStorage; bukan menyimpan isi pengaduan |
| Database | Penyimpanan tabel permanen; perubahan harus terkontrol |
| Transaksi | Sekelompok operasi SQL berhasil bersama atau rollback saat gagal |

## I. Fitur Belum Tersedia Atau Belum Terverifikasi

| Prioritas | Gap nyata | Rekomendasi minimal, belum dijalankan |
| --- | --- | --- |
| Tinggi operasional | Belum ada akun; perlu input password admin/operator | Bootstrap manual sekarang atau environment demo baru setelah target diverifikasi |
| Tinggi demo | Persistent demo command belum ada; registrasi rate-limited | Siapkan onboarding lebih awal; command interaktif isolated demo hanya dengan persetujuan |
| Tinggi keamanan | Retensi/backup restore/enkripsi/operator produksi belum disetujui/terverifikasi | Prosedur tertulis, backup terisolasi dan restore drill, least privilege/TLS |
| Tinggi administrasi | Revoke dosen/anggota/undangan belum ada UI/API | Endpoint scoped transactional dengan alasan/audit, policy dan regresi |
| Sedang | List assignment/banding/staff-search, konflik terstruktur, periode CRUD belum ada | Tambah contract/policy yang dibutuhkan sebelum menambah UI |
| Sedang | Reset/change password dan email verification belum ada meskipun tabel reset token skeleton ada | Flow resmi secure token+expiry+notification+tests, bukan edit hash manual |
| Sedang | Tidak ada monitor layanan/config editor/backup UI | Tetap mekanisme operator; tambahkan hanya setting nonrahasia dengan permission/audit |
| Sedang | Nama/monogram frontend belum terpusat dengan APP_NAME | Config brand nonrahasia dikirim Blade ke JS setelah identitas disepakati |
| Sedang verifikasi | Apache MAMP/MariaDB/semua browser/screen reader/produksi belum diuji | Uji target sebenarnya; jangan samakan localhost Artisan dengan produksi |
| Rendah | Audio rekaman/halaman kontak organisasi belum ada | Tambah setelah materi/kebijakan diberi; visual security sudah aktif |

Tidak ada integrasi resmi kampus/SSO, scanner dokumen, anonimitas absolut, atau penghapusan otomatis akhir semester. Batas timeline 100 dan komentar 200 tetap ada. Jangan mengaku fitur tersebut sudah tersedia dalam presentasi.

## J. Checklist Yang Anda Lakukan Sendiri

- [ ] Pilih apakah memakai database utama kosong atau schema demo baru; jangan campur environment.
- [ ] Jalankan server dan buka URL yang benar; build saat ini sudah tersedia.
- [ ] Buat admin melalui terminal interaktif dan atur password unik pribadi.
- [ ] Simpan credential di password manager, bukan source/slide/chat/log.
- [ ] Daftarkan dosen/verifikator dan calon mahasiswa/ketua dengan data fiktif bila demo.
- [ ] Ikuti kuota registrasi; lakukan setup jauh sebelum presentasi.
- [ ] Admin memberi role staff, membuat kelas, dan menugaskan dosen.
- [ ] Petugas independen menyetujui KTM calon mahasiswa/ketua.
- [ ] Terbitkan kode undangan, ajukan keanggotaan, dan setujui melalui pengelola.
- [ ] Tetapkan ketua aktif; simpan referensi ID penugasan secara aman.
- [ ] Siapkan dosen alternatif jika menjelaskan banding/konflik lengkap.
- [ ] Gunakan profil browser/akun berbeda dan data fiktif saja.
- [ ] Latih demo 10–15 menit, termasuk satu contoh penolakan akses aman.
- [ ] Jangan menjalankan test/reset/cleanup schema ketika presentasi atau target DB belum dipastikan.
- [ ] Jika mengganti branding, siapkan nama/logo milik sendiri, edit source yang benar dan build ulang.
- [ ] Logout setiap akun dan hentikan server setelah selesai; hapus data demo hanya dengan prosedur terotorisasi.
- [ ] Minta persetujuan sebelum implementasi command demo, endpoint revoke, atau perubahan branding yang belum disepakati.

**Titik berhenti saat ini:** Anda perlu memilih environment lalu memasukkan nama/email/password admin melalui command interaktif. Panduan ini tidak membuat akun atau meminta Anda mengirim password lewat percakapan. Sesudah bootstrap, lakukan provisioning melalui UI sesuai urutan C1.
