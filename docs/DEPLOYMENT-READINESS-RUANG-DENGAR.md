# Deployment Readiness Ruang Dengar

## Pembaruan Persiapan: 4 November 2026

Pengguna melalui `Promt20.md` mengizinkan publikasi kode ke repository
`https://github.com/andrryynn21-glitch/WebAplikasi` dan deployment gratis, kemudian
memilih **alwaysdata Free**. Batas izin lama di bawah adalah catatan audit historis,
bukan status persetujuan saat ini. Izin ini tidak mencakup paket berbayar atau
publikasi database, akun, kredensial, KTM, laporan, dan identitas mahasiswa lokal.

**Status: persiapan deployment, belum ada URL aplikasi publik yang diverifikasi.**
Akun alwaysdata terverifikasi dan akses SSH masih diperlukan. Gunakan
[pendaftaran Free](https://www.alwaysdata.com/en/register/?p=2012), bukan trial Plus.
Form registrasi saat pemeriksaan meminta verifikasi kartu tanpa biaya; pengguna
menangani data kartu, persetujuan ketentuan, email, dan OTP sendiri. Free ditujukan
untuk penggunaan personal/non-profit dengan kuota bersama 1 GB, RAM 256 MB,
1/4 CPU, subdomain alwaysdata.net, dan backup 3 hari. Kuota/kebijakan dapat berubah.

### Kode Dan Paket Rilis

- `.gitignore` mengecualikan semua environment nyata, data runtime/storage,
  database dump, key, instruksi internal berisi identitas, dan folder output.
- `.env.production.example` hanya template tanpa kredensial. `.env` lokal tidak
  diubah atau dikirim. Semua `CHANGE_ME` dan nilai rahasia harus diisi di server.
- Workflow **Validate and Package** menguji backend secara terpisah pada MySQL 8.4
  dan MariaDB 11.8, lalu menguji frontend dan membangun paket dengan PHP 8.5.
  Status workflow GitHub menjadi bukti hasilnya, bukan keberadaan file workflow.
- Artifact `ruang-dengar-<commit-sha>` berisi archive runtime dan `SHA256SUMS`.
  Archive memasukkan `vendor` produksi serta `public/build`, bukan vendor laptop,
  test helper, akun demo, `.env`, `node_modules`, cache lokal, atau data pengguna.
  Artifact tersedia 7 hari; jalankan workflow lagi untuk membangun ulang.
- Periksa checksum sebelum ekstraksi. Upload archive ke direktori privat melalui
  SFTP/SSH; jangan ke document root. Jangan mengekstrak rilis di atas storage atau
  `.env` instalasi yang sudah berjalan.

### Aktivasi alwaysdata Setelah Akses Tersedia

1. Dashboard **Environment > PHP**: pilih PHP 8.5 untuk CLI dan situs, periksa
   `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`, dan GD. Verifikasi CLI melalui
   `php -v` dan `composer check-platform-reqs --no-dev` di direktori rilis.
2. Dashboard **Databases > MySQL**: buat database baru dan user khusus, jangan
   mengimpor database laptop. Pilih/konfirmasi MariaDB 11.8 yang diuji CI; jika
   engine akun berbeda, uji versi tersebut sebelum menerima data peserta.
3. Gunakan layout rilis dan shared storage pada bagian C2, di bawah direktori
   home akun `/home/<akun>/www/ruang-dengar/`. Atur **Web > Sites** ke situs PHP
   dengan root hanya `/home/<akun>/www/ruang-dengar/current/public`.
4. Buat `.env` privat dari `.env.production.example`, isi hostname HTTPS, DB dan
   SMTP yang benar dari dashboard. Pada instalasi baru saja, buat APP_KEY sekali
   dengan `php artisan key:generate --force --no-interaction`. Lindungi file
   dengan permission 600 dan simpan backup key di tempat rahasia.
5. Setelah memastikan target database baru benar, jalankan `php artisan migrate
   --force --no-interaction` dan `php artisan db:seed --force --no-interaction`
   untuk katalog normal. Jangan seed ulang setiap deploy karena permission
   dapat tersinkron ulang. Jangan menjalankan test suite pada database hosting.
6. Buat Super Admin pertama melalui SSH interaktif memakai
   `php artisan app:bootstrap-admin --name="Administrator Sistem"
   --email="EMAIL_OPERATOR"`; password dimasukkan tersembunyi oleh operator,
   tidak di chat, argument password, Git, atau workflow.
7. Bangun cache **di server** dengan `php artisan config:cache`,
   `php artisan route:cache`, dan `php artisan view:cache`. Pastikan tidak ada
   `public/hot`. Jangan menjalankan `composer setup` pada server produksi.
8. Aktifkan **Force HTTPS** di dashboard. Uji URL/aset/API HTTPS same-origin.
   Jangan menambahkan wildcard trusted proxies; sesuaikan hanya jika topologi
   aktual dan header proxy memang memerlukannya.
9. Set PHP `display_errors=Off`, `upload_max_filesize=8M`, `post_max_size=48M`,
   `max_file_uploads=5` dan OPcache melalui konfigurasi PHP dashboard. Verifikasi
   GD dan limit pada PHP web, bukan hanya CLI; jangan membuat phpinfo publik.
10. Jalankan seluruh pemeriksaan pada bagian 7, termasuk penolakan akses `.env`,
    storage privat, otorisasi lintas kelas, mail recovery, serta persistensi
    setelah update. Baru undang peserta setelah batas privasi/operasional jelas.

Free tidak menyediakan service background permanen; template menggunakan queue
sinkron sesuai alur aplikasi saat ini. Jangan menjanjikan worker berjalan.
Pantau kuota karena file + database + email + rilis berbagi 1 GB; jangan menghapus
KTM/laporan otomatis untuk menghemat ruang. Backup 3 hari dari penyedia belum
menggantikan uji restore, kebijakan retensi, dan backup independen terenkripsi.
Tanpa SMTP terotorisasi dan uji pengiriman, reset password belum dinyatakan siap.

---

**Tanggal audit: 25 Oktober 2026. Fase yang dilakukan: A–B dari `Promt15.md`.** Belum ada login akun hosting, provisioning, domain, pembelian, upload, deployment, migration remote, import database, atau URL produksi yang dibuat. Dokumen ini adalah rencana untuk disetujui, bukan bukti aplikasi sudah online.

**Koreksi cakupan oleh pengguna:** sasaran pertama adalah pilot terbatas kelompok mahasiswa/teman sekelas dengan akun dan data yang mereka masukkan sendiri, bukan demo akhir berisi mock data. Biaya hosting dan database wajib Rp0; trial tidak dianggap gratis permanen. Database online direncanakan baru dan kosong, hanya diinisialisasi schema/katalog serta administrator yang berwenang setelah persetujuan. Akun, token, KTM, report dan database laptop tidak disalin. Pengguna hanya menyetujui audit/rekomendasi pada tahap ini; belum menyetujui resource cloud, input kartu, upload kode, perubahan konfigurasi atau migration apa pun.

## 1. Keputusan Singkat

**InfinityFree: NO-GO untuk aplikasi ini dalam bentuk sekarang dan dengan persyaratan privasi saat ini.** PHP/MySQL tersedia, tetapi aturan file di luar htdocs, keterbatasan CLI/cron, dan browser security challenge bertentangan dengan kebutuhan document root Laravel, file KTM privat, serta API client yang secara eksplisit tidak mengirim cookie. Tidak direkomendasikan mengatasi ini dengan memindahkan seluruh source/private storage ke area web dan mengandalkan rewrite semata.

**Pilihan terbaik secara kapasitas/kontrol: Oracle Cloud Always Free Compute, KANDIDAT BERSYARAT, belum izin deploy.** VM memberi kontrol PHP, MySQL, webroot public, storage persistent, firewall, TLS dan backup. Namun belum ada akun/resource yang diverifikasi; Always Free mempunyai batas kapasitas, verifikasi kartu, potensi reclaim idle, dan tidak mempunyai SLA. Tidak ada jaminan resource tersedia atau biaya nol sebelum halaman provisioning diperiksa. Jangan upgrade ke paid/Pay As You Go atau memakai resource trial-only.

**Alternatif jika Oracle tidak layak: alwaysdata Free untuk pilot sangat kecil, juga bersyarat.** Dokumentasi resmi saat audit menawarkan1GB storage,256MB RAM,1/4CPU, MariaDB, PHP hingga8.5, SSH, subdomain alwaysdata.net/HTTPS dan backup harian3hari. Lebih sederhana secara operasional, tetapi kuota jauh lebih kecil dan kebijakan Free non-profit harus cocok dengan pilot. Bukan paket Plus trial30hari, diskon mahasiswa berbayar, atau janji aplikasi ini sudah diuji di sana.

**Status penggunaan:** target adalah pilot data pengguna sendiri, tetapi kesiapan infrastruktur berbeda dari kesiapan pemrosesan KTM/report nyata. Hosting yang cocok tidak menyelesaikan gap canonical NIM, duplicate identity, retensi, scanner dokumen, atau lifecycle akademik. Sebelum peserta bergabung diperlukan gerbang keamanan/privasi pada bagian4, bukan mengganti tujuan akhir dengan data palsu.

Jika akun/region Oracle tidak menyediakan resource berlabel Always Free dengan estimasi biaya nol, jangan membuat resource paid. Evaluasi alwaysdata Free terhadap jumlah peserta, ukuran release dan pertumbuhan upload; jika tidak muat, audit menyatakan belum ada solusi Rp0 yang tervalidasi untuk scope tersebut. Persetujuan pengguna tetap diperlukan sebelum memilih/menyiapkan salah satu kandidat.

## 2. Bukti Lokal Dan Kebutuhan Runtime

| Area | Fakta yang diperiksa | Implikasi deployment |
| --- | --- | --- |
| Framework | Laravel 13.37.0, Sanctum 4.3.3 dari lockfile/source | Deploy dari lockfile, jangan update dependency/downgrade framework diam-diam |
| PHP minimum efektif | Composer root `^8.3`, tetapi `symfony/http-kernel` dan `symfony/mailer` locked meminta `>=8.4.1`; `vendor/composer/platform_check.php` menegakkannya | Target aktual **PHP >=8.4.1**, disarankan branch 8.5 yang masih didukung dengan patch aman. Klaim README PHP8.3 adalah floor manifest, bukan kemampuan lockfile ini. Tidak menonaktifkan platform_check |
| Toolchain lokal | PHP8.5.8, Composer2.10.2, Node26.4.0, npm11.17.0 | Hanya versi lokal yang diverifikasi, bukan versi server hosting |
| Ekstensi | Native ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, PDO, pdo_mysql, session, tokenizer, xml terdeteksi | Wajib verifikasi CLI **dan FPM/web SAPI** tujuan; Composer bisa menerima polyfill tetapi fileinfo/PDO/MySQL tetap kebutuhan aplikasi. BCMath/runtime Laravel lain sesuai official requirements [S1] juga diperiksa di target |
| Composer | `composer validate --strict` dan `composer check-platform-reqs --no-dev` lokal lulus | Build dependency produksi perlu `composer install --no-dev --prefer-dist --optimize-autoloader`, bukan menyalin vendor development apa adanya |
| Database | Default dan SQL aktual: `pengaduan_unpam`; driver mysql, localhost TCP3306, version string server `26.7.0`; ada1user, 0report | Version string dicatat apa adanya. Tidak menganggap target MySQL8/MariaDB11 identik; wajib test migration/query/lock pada engine tujuan |
| SQL spesifik | `StatisticsService` memakai TIMESTAMPDIFF; migration FK/index/transaction; workflow row locks | PostgreSQL bukan pengganti langsung tanpa port/test. Gunakan MySQL pada deployment yang direncanakan |
| APP_ENV / debug / URL | Aktif lokal, debug=true, URL localhost:8000, config tidak cached | Produksi harus env=production/debug=false/HTTPS URL baru; jangan mengubah .env lokal sekarang |
| Cache/session | Keduanya database | Tabel cache/cache_locks/sessions harus ada dan DB persistent; tidak perlu Redis saat ini |
| Sanctum/API | Bearer di sessionStorage; `credentials: omit`, `redirect: error`, JSON media check, same-origin base | Bukan cookie SPA; jangan mengubah ke cookie/CORS lintas origin untuk memaksa kompatibilitas host. TLS wajib |
| Routing | `/` Blade app; API `/api/v1`; frontend hash routes | Nginx/Apache harus meneruskan request ke **public/index.php** dan Authorization header, bukan root proyek |
| Frontend | Vite8/Tailwind4/ES modules; public/build/manifest.json dan dua entry aset tersedia; public/hot tidak ada saat audit | Build lokal/CI; upload build, bukan node_modules/hot/dev server. Node tidak diperlukan runtime request production |
| Asset URL | Blade memakai url('/api/v1') dan @vite; JS logo memakai `/images/logo-unpam.png` | Gunakan root dedicated domain/subdomain. Deployment di subfolder memerlukan uji/penyesuaian base asset, bukan asumsi langsung berfungsi |
| File privat | `storage/app/private`, semua AttachmentType private, serve=false, download via controller/policy; original bytes disimpan | Persistent volume di luar document root; jangan symlink private ke public, jangan direct URL. File + record DB harus bertahan redeploy/restart |
| Upload | KTM max4096KiB; report max5file x8192KiB; fileinfo MIME allow-list | PHP/FPM/proxy post-body harus memadai. Nilai lokal CLI **upload_max_filesize=2M/post_max_size=8M** lebih rendah dari fitur; jangan menyalinnya sebagai setting final |
| Saran limit target | upload_max_filesize=8M, post_max_size=48M, proxy client_max_body_size>=48M, max_file_uploads>=5; memory sesuai beban | Nilai rencana, belum diterapkan/diuji. Uji boundary multipart dan pesan413/422 tanpa file orphan; jangan menaikkan unlimited |
| Queue | Config database; service notification in-app menulis DB sinkron; tidak ditemukan app job ShouldQueue/dispatch untuk alur utama | Worker **bukan kebutuhan alur inti saat ini**, tetapi diperlukan bila email/notifikasi nanti dijadikan async. Jangan menjanjikan queue berjalan hanya karena ada jobs table |
| Scheduler | routes/console.php hanya command contoh; belum ada retention/prune/backup schedule | Ketiadaan cron tidak langsung mematikan alur inti, tetapi membatasi backup, prune token/log dan kebutuhan operasional pilot |
| Mail | MAIL_MAILER efektif log; CredentialService menahan pembuatan token bila transport tidak aman | Forgot password tidak benar-benar mengirim saat ini. SMTP/provider aman perlu approval secret dan uji. Jangan memakai log mail untuk melihat token |
| Log | Stack default single; file logs privat; exception SQL diringkas tetapi bukan semua data log dijamin bersih | Target memakai rotasi yang disepakati, APP_DEBUG=false, display_errors Off, akses log privat; redaksi log sebelum dibagikan |
| Provisioning awal | `DatabaseSeeder`: role/permission, category, term. PermissionSeeder melakukan sync pivot. `app:bootstrap-admin`: CLI interaktif | Seed hanya sekali pada DB hosting baru terverifikasi; rerun bisa menyelaraskan ulang permission. Bootstrap hanya akun pertama, password operator langsung, bukan secret di script HTTP |
| Git | `git status` menyatakan bukan repo | Jangan git init/commit/push diam-diam. Release dapat memakai manifest+checksum/archive terkurasi atau Git setelah persetujuan |

Sumber source utama: `composer.json`, `composer.lock`, `vendor/composer/platform_check.php`, `config/{database,filesystems,auth,sanctum,cache,session,queue,mail,logging}.php`, `routes/{web,api,console}.php`, `bootstrap/app.php`, `resources/js/api.js`, `resources/views/app.blade.php`, `PrivateStorageService`, `CredentialService`, `StatisticsService`, `NotificationService`, `BootstrapAdmin`, dan seeder normal.

### Catatan keamanan sebelum internet publik

- Debug masih aktif lokal, mail belum deliverable, dan limit PHP lebih kecil daripada batas fitur; tiga hal ini harus diselesaikan pada konfigurasi release/target, bukan dengan membuka .env sekarang.
- `storage/app/private` dan detail database tidak otomatis terenkripsi oleh APP_KEY. Retensi, backup, otorisasi operator, dan hosting data mahasiswa memerlukan keputusan pemilik.
- API registration publik dapat menambah akun dan mengonsumsi kuota. Pilot dimulai dengan database baru, peserta mendaftar sendiri, dan keanggotaan/identity disetujui petugas yang benar. Sebelum onboarding peserta, verifikasi environment dengan data uji di tempat terpisah yang disetujui; test data bukan isi/tujuan akhir pilot.
- Audit sebelumnya mencatat gap candidate/canonical NIM, checksum match, lifecycle revoke, dan ID appeal/attachment. Tidak diperbaiki dalam fase deployment-readiness ini.

## 3. Matriks Kompatibilitas InfinityFree

| Persyaratan | Dokumentasi/bukti | Penilaian untuk Ruang Dengar |
| --- | --- | --- |
| PHP | Homepage resmi [S2] mengiklankan PHP8.4 | Kandidat secara versi mayor, tetapi patch >=8.4.1 dan semua ekstensi/disabled function web SAPI belum diverifikasi. Bukan bukti situs ini sudah bisa boot |
| MySQL | [S2] menyebut MySQL8.0/MariaDB11.4,400database | Engine/kuota database aktual per akun belum diperiksa; query/transaksi tetap perlu test |
| Disk | [S2] menyebut5GB storage | Angka kapasitas bukan jaminan private layout, backup atau persistensi di luar htdocs |
| Document root | [S3] menyatakan **semua file di luar folder htdocs otomatis dihapus**; [S4] menjelaskan domain terkait htdocs | **Blocker** untuk menempatkan source/.env/storage di parent terpisah dengan public-only root sesuai Laravel. Custom public docroot/symlink belum dibuktikan tersedia |
| API browser | [S5] challenge mandatory JS+cookie `__test`, bisa memberi HTML200 sebelum code. Same-domain AJAX didukung bila syarat cookie terpenuhi | **Risiko kompatibilitas tinggi:** fetch aplikasi `credentials: omit` tidak membawa cookie dan menolak HTML/redirect. Auth bearer tidak menyelesaikan challenge host. Tidak boleh mematikan/mengelabui security host |
| File size | [S6]: HTML/PHP/JS1MB, .htaccess10KB, lainnya10MB; oversized file dihapus; PHP upload limit20MB | Build JS kecil mungkin muat; release vendor dan setiap PHP harus diinventarisasi. Batas per-file tidak membuktikan post_max_size agregat: total 5x8MiB harus diverifikasi di akun tujuan. Jangan mengubah batas fitur diam-diam |
| Jenis file/download | [S3] membatasi file tertentu dan menyarankan layanan khusus untuk file sharing/video | KTM/evidence attachment bukan sama dengan hosting file umum, tetapi kebijakan allowance perlu konfirmasi. Diskusi PDF saling bertentangan; tidak mengklaim semua PDF pasti dibolehkan/ditolak |
| CLI/Composer/Artisan | Forum resmi support [S7a/S7b] menyatakan SSH tidak tersedia; Composer lokal+upload dapat dipakai untuk library | Tidak tersedia jalur terminal untuk bootstrap admin interaktif/migration/worker. Composer lokal tidak menyelesaikan root privat atau CLI provisioning. **Tidak membuat migration/bootstrap PHP publik** |
| FTP/control panel | Guide Admin [S8] memakai FTP/FileZilla; control panel dan phpMyAdmin tersedia | Tidak sama dengan SFTP/SSH; enkripsi/permission/subpath aktual perlu diuji jika dipertimbangkan. Tidak mengirim credential hosting di chat |
| Cron/background | Pengumuman Admin [S9] cron dinonaktifkan dan automated periodic requests dilarang | Tidak memakai cron HTTP eksternal sebagai workaround. Queue/backup scheduled tidak terbukti mungkin |
| Persistensi | Free hosting bukan ephemeral redeploy seperti container gratis, tetapi aturan auto-delete file berlaku | Upload privat di parent luar htdocs tidak aman; retensi database/file lengkap belum dapat dijamin untuk arsitektur ini |
| Backup | Admin [S10]: tidak menyediakan backup akun gratis; FTP + export phpMyAdmin tanggung jawab pengguna | Tidak ada automated snapshot/restore yang bisa diasumsikan. Risk tinggi untuk bukti laporan/KTM |
| HTTPS/domain | [S2] menyebut free subdomain, domain sendiri, free SSL | Sertifikat/renewal actualhost belum diverifikasi. HTTPS saja tidak menyelesaikan file/privacy/API blocker |
| SMTP/API outbound | [S5] menyatakan outbound request tersedia | Port/credential/SMTP transport dan delivery tidak diverifikasi; tidak menjanjikan reset email. Bukan alasan mengubah mailer ke log |
| Inode/CPU/hit/DB quota/remoteDB | Belum terverifikasi lewat akun/dokumen tepat yang dapat diakses | Tidak menebak nilai kuota atau menganggap “unlimited” tanpa limit. Sejumlah pencarian docs terkena429; berhenti daripada spam pencarian |

**NO-GO berarti tidak cocok untuk kebutuhan aplikasi ini sekarang**, bukan klaim semua Laravel mustahil di semua shared hosting. Workaround meletakkan semua source di htdocs dengan deny rules akan mengubah model keamanan serta memerlukan pembuktian terpisah; tidak disetujui sebagai jalur deploy ini.

## 4. Alternatif Gratis Yang Diperiksa

| Kandidat | PHP/MySQL/file privat | Gratis/limit yang terbukti | Keputusan |
| --- | --- | --- | --- |
| **Oracle Cloud Always Free Compute** | VM dapat menjalankan Nginx/Apache + PHP-FPM >=8.4.1 + MySQL lokal; volume persistent dan webroot public dikendalikan operator | [S11–S13] Always Free berbeda dari trial30hari; Block Volume hingga200GB, idle instance bisa direclaim, kapasitas home region tidak dijamin; registrasi meminta verifikasi kartu dan tidak ada SLA | **Rekomendasi bersyarat.** Tidak menjalankan Autonomous OracleDB sebagai pengganti MySQL aplikasi; gunakan MySQL pada VM. Perlu operator sysadmin, budget control, backup independen |
| **alwaysdata Free** | Managed Apache/PHP hingga8.5, php.ini dapat dikonfigurasi, MariaDB10.11/11.4/11.8, SSH/SFTP, root situs dapat dipilih, persistent filesystem, HTTPS | [S18–S24]: Free €0/bulan tanpa batas waktu;1GB shared disk(files+mail+DB),256MB RAM,1/4CPU; backup harian3hari di datacenter terpisah di luar kuota. Website hanya alamat akun alwaysdata.net, non-profit, tidak boleh menjalankan service background Free | **Alternatif bersyarat untuk pilot sangat kecil.** Bukan klaim kapasitas cukup. Composer/build sebaiknya dilakukan di staging, PHP8.5+native PDO diverifikasi, migration/SQL diuji pada MariaDB. Tidak ada akun/konfigurasi/test host yang dilakukan |
| Render Free | PHP via container memungkinkan; free service filesystem ephemeral dan tidak punya persistent disk. Free managed DB PostgreSQL, bukan MySQL | [S14] service tidur setelah15menit, filesystem lokal hilang saat restart/redeploy/spindown, free PostgreSQL berakhir30hari | **NO-GO tanpa perubahan arsitektur** storage privat dan DB yang belum disetujui. Bukan target end-to-end gratis berkelanjutan untuk source ini |
| Railway Free | Bisa PHP/container/MySQL/volume, tetapi resource dibebankan menurut penggunaan | [S15/S16] Free trial $5 maksimal30hari, kemudian Free credit $1/bulan, VM gratis anonymous belum diklaim hanya sementara; volume trial dapat dihapus setelah masa retensi | **Tidak dipilih:** tidak ada bukti app+MySQL+storage ini dapat terus berjalan dalam kredit tanpa biaya. Jangan upgrade Hobby/trial-paid otomatis |

### Ukuran awal Oracle yang diusulkan, bukan resource yang sudah ada

Satu VM Ampere A1 **1 OCPU/6GB RAM**, satu boot/persistent volume sekitar50GB, MySQL pada VM yang sama untuk menyederhanakan topologi dan pengendalian kuota. Ini usulan konservatif, bukan hasil sizing/load test. Pembacaan ulang sumber detail [S12] menyebut1500OCPU-hour/9000GB-hour per bulan, ekuivalen **2OCPU/12GB** untuk Always Free, dan200GB gabungan boot/block volume. **Jangan mengandalkan angka blog lama atau maksimum agregat**; konsol akun dan label Always Free/estimasi nol setiap resource menjadi gerbang persetujuan. Usulan1/6 berada di bawah ringkasan tersebut, tetapi kapasitas/ketersediaan tetap belum terbukti. [S12] juga mencantumkan MySQL HeatWave Always Free, tetapi service tambahan itu tidak dipilih atau diprovision dalam rencana satu-VM ini.

Gunakan image OS gratis, bukan paid Marketplace, tanpa load balancer/managed DB tambahan. Pastikan tidak memakai trial credit untuk kapasitas yang akan ditagih/diterminasi sesudah trial. Billing alarm membantu memantau, bukan hard cap; jangan pernah menganggap alarm mencegah tagihan. Jika konsol meminta upgrade untuk resource yang dibutuhkan, berhenti. Outbound SMTP port25 diblok secara default menurut [S12]; pilih provider terotorisasi dengan transport/port aman yang tersedia dan uji delivery, bukan menambah mail relay publik.

Domain bisa subdomain gratis yang Anda kuasai (contoh layanan DuckDNS [S17]) atau domain Anda yang sudah ada tanpa pembelian baru. Login domain/DNS, hostname yang tersedia, TLS issuance dan renewal perlu verifikasi. Tidak ada hostname contoh yang diklaim sudah tersedia atau URL final yang dibuat.

### Pertimbangan alwaysdata Free

Paket Free Rp0 berbeda dari trial Plus30hari. Source [S18/S19] menyatakan1GB disk bersama,256MB RAM,1/4CPU dan3hari backup, bukan100MB yang sering tertulis pada artikel lama. Database termasuk kuota disk, bukan tambahan1GB. Source PHP/SSH [S20/S22] memungkinkan Composer/CLI/bootstrap; tanpa root berarti operator mengikuti kontrol platform, bukan memasang daemon arbitrer. PHP app dilayani Apache/FastCGI, `.htaccess` didukung, sehingga struktur source di home dengan root situs hanya `.../public` merupakan kandidat yang wajar; harus dibuktikan melalui test akses file sebelum real onboarding.

Backups [S23] mencakup file/database/email harian,3hari untuk Free, di datacenter terpisah dan tidak menghitung quota. Itu bukan alasan menghilangkan backup terenkripsi independen atau restore drill. MySQL events tidak didukung; dokumentasi MariaDB menyebut40connection/user di Public Cloud [S21]. Service background tidak boleh pada Free [S19], tetapi alur sinkron saat ini tidak memerlukannya. Claim HTTP/API/SMTP, patch PHP aktual, package size, total memory, dan account eligibility belum diuji.

Contoh perhitungan, **bukan ukuran kelompok yang ditetapkan**:20peserta dengan1KTM4MiB dan total20laporan masing-masing5lampiran8MiB menghasilkan80+800=880MiB hanya untuk upload. Ini hampir memenuhi1GB sebelum code/vendor/DB/log/release cadangan. Bahkan pilot kecil bisa tidak muat bila semua batas maksimum digunakan. Jumlah peserta/report dan retensi perlu disepakati; jangan menurunkan limit upload atau menghapus data pengguna otomatis agar masuk paket gratis. Quota harus dipantau sebelum penuh, dengan kebijakan menunda upload/menerima data baru yang jelas bila diperlukan setelah approval.

Free diperuntukkan non-profit; kebijakan legal [S25] mengizinkan suspension/deletion jika legitimasi free account dipersoalkan. Klaim “gratis selamanya” bukan SLA atau jaminan account tidak ditutup. Kebutuhan verifikasi akun/kartu actual belum dipastikan. Program mahasiswa yang menawarkan50%diskon tetap berbayar [S26], jadi tidak dianggap memenuhi Rp0. Infrastruktur yang dipublikasikan berada di Prancis [S27]; untuk KTM/data mahasiswa perlu penilaian penyimpanan lintas negara dan persetujuan operator yang sesuai, bukan klaim kepatuhan hukum otomatis.

### Gerbang Pilot Dengan Data Pengguna Sendiri

1. Tetapkan penanggung jawab data dan operator, kelompok peserta, durasi pilot, tujuan pemrosesan KTM/laporan, serta kontak pengaduan/insiden. Data sendiri bukan berarti bebas risiko privasi.
2. Database hosting baru tidak mengimpor akun/token/localdata. Setelah bootstrap admin awal secara manual, pengguna mendaftar sendiri; role petugas/verifikator dan kelas diberi melalui jalur resmi. Jangan menjalankan DemoDataSeeder atau memasukkan mock account sebagai isi pilot.
3. Jelaskan informasi yang dikumpulkan, siapa dapat membaca, lokasi hosting/backup, masa retensi, serta prosedur koreksi/penghapusan. Full KTM menyimpan PII; kebutuhan setiap field harus dibenarkan dan dibatasi. Jangan menyatakan afiliasi resmi kampus hanya karena logo.
4. Selesaikan atau mitigasi risiko identitas yang sudah dibuktikan: submit mengubah NIM sebelum approval, duplicate checksum belum diperiksa, dan tidak ada correction trail/retention lengkap. Jika belum aman menyimpan KTM, **tunda pembukaan pengumpulan dokumen**, bukan memakai status verified palsu atau menghapus guard.
5. Uji target PHP/DB, root public, private file denial, header Authorization, limits, mail recovery, role/cross-class, backup/restore dan update rollback pada environment pengujian terpisah yang disetujui. Pengujian memakai data fiktif seperlunya; itu hanya QA, bukan tujuan akhir pilot atau clone data laptop.
6. Siapkan recovery operator/akun admin, minimal privilege, HTTPS, secret management, pemantauan quota/cost dan downtime. Layanan gratis tetap bisa berhenti; catat risiko kepada peserta sebelum onboarding.

**Belum GO untuk penerimaan data nyata saat audit ini.** Bukan karena pilot harus menjadi mock, tetapi karena provider target dan gerbang operasional/privasi belum diverifikasi. Pilihan hosting terbaik saat ini tetap conditional, tidak ada kewajiban berlangganan sebagai jalan pintas.

## 5. Rencana Deployment Aman Setelah Persetujuan

**Bagian ini belum dijalankan.** Persetujuan Fase C tidak mengizinkan paid resource atau pembelian domain. Aplikasi lokal/master database tetap tidak diubah.

### C1. Akses Dan Akun

1. Pengguna menyetujui platform/rencana untuk pilot terbatas, batas peserta/data/retensi, serta biaya hosting+database Rp0. Koreksi tujuan pengguna bukan persetujuan deploy.
2. Pengguna login/registrasi hosting sendiri; CAPTCHA, OTP, MFA, email, card verification dilakukan manual. Asisten tidak mencari credential di browser/history/keychain dan tidak memakai izin “full access” sebagai pengganti persetujuan spesifik.
3. Periksa tenancy/home region/quota/Always Free/current resources secara read-only. Jika belum ada akses sah atau kapasitas tidak tersedia, laporkan berhenti, bukan mencoba membuat akun berulang.
4. Setelah kapasitas/free-cost terbukti, buat VM/volume/network **sekali** hanya sesuai yang disetujui. SSH key khusus deployment, private key tetap lokal/secret store. Tidak mengirim key/password melalui chat atau file repo.
5. Firewall: HTTPS443, HTTP80 untuk redirect/ACME; SSH22 terbatas IP operator. MySQL3306 tidak dibuka ke internet. Service berjalan dengan user/DB privilege minimum.

### C2. Release Dan Layout

```text
/srv/ruang-dengar/
  releases/<release-id>/
    app/ bootstrap/ config/ database/ resources/ routes/ vendor/
    artisan composer.json composer.lock
    public/             <- SATU-SATUNYA document root web
      index.php build/ images/ .htaccess (untuk Apache)
    storage/            -> shared/storage (symlink di luar webroot)
    .env                -> shared/.env (di luar webroot)
  shared/
    .env
    storage/app/private/
    storage/framework/{cache,sessions,views}/
    storage/logs/
  current -> releases/<release-id>
```

Webroot `/srv/ruang-dengar/current/public`; `.env`, database dump, logs, storage privat, vendor dan artisan tidak boleh served. PHP handler hanya mengeksekusi front controller yang dimaksud. Nginx `try_files` meneruskan URL API ke index, Authorization diteruskan, path dotfile ditolak kecuali ACME. Apache memakai rewrite AllowOverride yang sesuai. Jangan mengganti document root ke parent supaya URL lebih pendek.

Periksa owner/group FPM dan akses minimum pada storage/bootstrap/cache, bukan chmod777. Direct storage outsidepublic bukan berarti harus `storage:link`; **tidak membuat public symlink untuk KTM/lampiran**. Persistensikan storage dan DB; code release harus dapat diganti tanpa menimpa evidence/uploads.

Build di staging/CI terpisah, bukan menjalankan composer no-dev yang menghapus tooling dari workspace aktif. Candidate command setelah otorisasi release: `composer install --no-dev --prefer-dist --optimize-autoloader`, `npm ci`, `npm run build`. Wajib `composer check-platform-reqs --no-dev` pada target PHP web/CLI yang sesuai. Jangan `composer update`, menghapus dependency lock, atau `--ignore-platform-reqs` untuk lolos hosting.

Untuk upload manual tanpa Git, buat release allow-list + SHA-256 manifest pada fase berikutnya. `.gitignore` bukan filter otomatis FTP/ZIP; `public/build` bahkan di-ignore Git tetapi **harus** ada di release.

**Jangan ikutkan:** `.env`, `.env.demo`, `.env.backup`, `.env.production` lokal; credential/auth.json/private key; SQLite/dump DB; storage runtime/logs/private existing; cache config/route/view lokal; `public/hot`; node_modules; vendor dev dari laptop; `.kilo`, `.claude`, IDE metadata; tests dan helper create/drop database; output video/capture/sandbox; prompt Promt*.md dan audit dokumen privat; source-map atau debug tool yang tidak diperlukan. Jangan salin seluruh folder proyek secara buta.

Video final sekitar12,7MB berada di output dan bukan dependency aplikasi. Tidak diunggah ke hosting aplikasi ini. Bila kelak perlu publikasi video, minta target asset/video dan hak publikasi terpisah.

### C3. Database Dan Konfigurasi Pilot

Target yang diminta pengguna adalah **database hosting baru dan kosong**, bukan clone database laptop. Tidak memindahkan admin lokal, token, KTM, atau laporan. `.env` pilot dibuat di target/secret store setelah hostname/DB benar, bukan mengubah `.env` utama lokal. “Kosong” berarti tidak diisi akun demo/report fiktif; schema/katalog awal dan administrator bootstrap tetap diperlukan, kemudian peserta mendaftar dan memasukkan data mereka sendiri.

| Kunci/setting | Rencana, bukan value yang diterapkan |
| --- | --- |
| APP_ENV / APP_DEBUG | production / false |
| APP_URL | Origin HTTPS final yang Anda kuasai |
| APP_KEY | Key baru pada deployment baru tanpa data terenkripsi lama; jika migrasi instalasi berisi data, keputusan preservasi key/rotasi harus eksplisit dan dibackup aman |
| DB_* | MySQL target baru, credentials khusus dari operator; jangan root publik |
| CACHE_STORE / SESSION_DRIVER | database; tabel dari migration harus tersedia |
| SESSION_SECURE_COOKIE / SAME_SITE | true / lax pada HTTPS. Client sekarang memakai Bearer, jadi tidak mengklaim setting cookie mengamankan sessionStorage |
| QUEUE_CONNECTION | Tetap database boleh; alur inti sinkron. Worker/prune hanya dikonfigurasi bila diperlukan dan didokumentasikan |
| FILESYSTEM_DISK | Disk privat tetap lokalpersistent sebagaimana config; disk default tidak mengubah call `Storage::disk('private')` |
| LOG_CHANNEL / LOG_LEVEL | daily dan level yang disepakati, contoh warning; jangan log password/token/request body. Retensi log contoh14hari perlu approval operator |
| MAIL_* | SMTP/provider nyata yang diotorisasi, TLS/port/from diverifikasi; bila belum ada, reset email dinyatakan belum tersedia dan jangan mengeluarkan token ke log |
| PHP/FPM | display_errors Off, limit multipart sesuai8M/48M, memory dan timeout diuji pada VM; hindari debug page/info publik |

Jika memakai proxy/CDN, trustworthy proxy/HTTPS detection harus dikonfigurasi spesifik setelah topologi diketahui. Sumber sekarang tidak mendefinisikan trust-proxy tambahan; jangan memakai wildcard trust untuk mengatasi mixed content tanpa audit. Semua frontend/API pada origin yang sama, bukan API subdomain baru atau CORS `*`.

Pada database hosting baru dan target yang telah diperiksa: `php artisan migrate --force`, lalu `php artisan db:seed --force` untuk **katalog normal saja**. Tidak menjalankan `migrate:fresh`, `migrate:refresh`, `db:wipe`, DemoDataSeeder atau helper test. Bootstrap Super Admin pertama memakai CLI SSH `php artisan app:bootstrap-admin` dengan password tersembunyi yang Anda ketik; tidak melalui route web atau credential di argumen. Seed/command tersebut belum dijalankan di hosting.

Sesudah env/DB/aset/permission benar, verifikasi config/route/view caching pada target. `php artisan config:cache` dan `view:cache` harus dibuat di target, bukan membawa path laptop. Cache route diuji terpisah; kegagalan bukan alasan mengubah otorisasi. Jangan memakai `composer setup` karena script lokal itu juga menjalankan key:generate/migration/npm.

## 6. Backup Dan Rollback

### Sebelum deployment pertama

- Baca/backup hanya data yang Anda setujui; defaultnya tidak memindahkan data utama/demo. Tidak membuat dump berisi PII tanpa lokasi private/enkripsi yang disepakati.
- Simpan release manifest/checksum dan backup konfigurasi produksi dalam secret store privat. Jangan meletakkan `.sql`, archive, `.env` di public, Git, atau file manager publik.
- Untuk instalasi yang telah berjalan, ambil snapshot konsisten DB **dan** private files saat mutasi dihentikan atau dengan teknik snapshot terkoordinasi. DB dump saja tidak berisi KTM/file.
- Verifikasi restore ke database/direktori terpisah sebelum menyatakan backup berhasil. RPO/RTO harus disepakati, bukan mengklaim zero-loss tanpa restore drill.

### Rollback code

Kembali ke release sebelumnya melalui current symlink/release pointer, pulihkan konfigurasi yang sesuai, rebuild target cache, restart FPM/worker terkontrol. Jangan menghapus shared storage atau mengganti APP_KEY sembarangan. Code rollback tidak otomatis membalik migration.

### Rollback schema/data

Migration baru harus backward-compatible dan diuji pada salinan terotorisasi. Untuk migration irreversible/merusak atau restore snapshot, berhenti dan minta otorisasi spesifik; tindakan itu dapat membuang perubahan sejak backup. Jangan menjalankan rollback/reset SQL sebagai troubleshooting pertama. Simpan bukti failure teredaksi.

### Bila host gratis dihentikan/direclaim

Hentikan akses mutasi bila memungkinkan, ekspor salinan terenkripsi yang masih bisa diakses, dan pindahkan dari backup ke target yang disetujui. Oracle idle reclaim, kehilangan akun free, atau provider policy tidak dapat diselesaikan aplikasi sendiri; sediakan backup eksternal yang dikuasai pengguna. Jangan menambah traffic palsu untuk menghindari aturan provider atau membuat resource paid otomatis.

## 7. Rencana Pengujian Fase D

**Semua status berikut NOT RUN pada hosting.** Gunakan database/akun uji terpisah yang diotorisasi dan batasi akses selama pengujian. Sesudah gerbang lulus, pilot memakai data baru peserta sendiri; tidak dipresentasikan sebagai mock app.

| Pemeriksaan | Bukti lulus yang harus dikumpulkan |
| --- | --- |
| DNS/TLS | Nama publik benar, sertifikat valid, HTTP redirect, renewal berhasil; tidak sekadar /up200 |
| Isolasi webroot | Request `/.env`, `/composer.json`, `/vendor/`, `/storage/logs/`, path file KTM nyata, backup/test script tidak pernah membuka isi; index listing disabled |
| Platform | PHP FPM dan CLI >=8.4.1, ekstensi lengkap, Composer platform check pass, MySQL transactions/FK/SQL statistics bekerja |
| Entry/frontend | Login/register/dashboard/aset CSS JS logo dimuat HTTPS same-origin, tidak ada mixed content/public hot/localhost URL, console bebas error |
| Auth/roles | Login/logout tiap role, 401/403, role injection/self-role deny, admin tidak otomatis membaca laporan/KTM, revoked tokens tidak valid |
| Database | Migration katalog sesuai; create/update data fiktif, restart/redeploy tidak kehilangan DB; tidak ada mutasi ke database laptop |
| KTM/lampiran | File kecil dan batas ukuran4/8MiB, MIME invalid, multipart total, upload/download/delete-compensation, file hilang/tolak akses aman |
| Privasi | Cross-user/cross-class denial; no private body pada list/notification/log; original filename bukan path public |
| Workflow | Identity independent approval, membership, routine/sensitive report, assignment/status/clarification/appeal independent, aspiration moderation |
| Error/rate | 404/403/422/429 dan5xx sanitized; tidak ada challenge HTML mematahkan JSON; kegagalan upload tidak mengumumkan sukses |
| Mail | Sender verified, reset ke mailbox test diterima, expired/reused token ditolak, tidak ada token di access/app logs; jika belum lulus, fitur email belum boleh diklaim |
| Persistensi | Upload + record bertahan restart/redeploy; maintenance/cache/worker bila ada tidak menghilangkan data |
| Backup/restore | Restore terisolasi SQL + file, key/config tersedia aman, hash/integrity check, retention/cleanup disetujui |
| Akun/data uji | Inventaris yang dibuat selama smoke; cleanup hanya testrecords yang disetujui, bukan truncate/purge umum |

Full PHPUnit menggunakan RefreshDatabase dan helper E2E dapat create/drop schema; **tidak dijalankan pada DB hosting produksi atau DB utama/demo lokal**. Test suite boleh dijalankan pada fase persiapan di database test terverifikasi setelah otorisasi. Browser fixture bukan bukti koneksi database hosting; label bukti harus jelas.

## 8. Temuan Dan Batas Kepastian

### Blocker sebelum GO deployment

1. Platform/resource/domain belum disetujui dan belum diverifikasi akun Anda.
2. InfinityFree tidak memenuhi layout private/public dan client API saat ini; tidak ada upload percobaan yang dilakukan.
3. Effective minimum PHP8.4.1 berbeda dari floor PHP8.3 di dokumen lama; perlu PHP target tepat dan Composer check.
4. Debug lokal aktif dan limit upload lebih kecil; konfigurasi target belum dibuat.
5. SMTP aman, persistent storage, backup/restore dan TLS belum diuji di host.
6. Resource Oracle berlabel Always Free, akun manual/card verification, DNS hostname serta biaya nol belum terbukti. Kapasitas/rules gratis dapat berubah.
7. Alwaysdata Free perlu konfirmasi eligibility non-profit, quota256MB/1GB cukup, PHP/MariaDB compatibility, layout privat, dan biaya tetap nol; bukan fallback otomatis.
8. Pilot memproses data peserta sendiri, sehingga retensi, penanggung jawab, lokasi data/backup, koreksi NIM dan acceptance keamanan merupakan syarat sebelum pengumpulan dokumen nyata, bukan sekadar checklist demo.

### Yang belum diperiksa dan tidak dikarang

Runtime PHP/limits InfinityFree per akun, daftar disabled functions, batas inode/CPU/hit/database sesungguhnya, customdocroot/symlink, SMTP ports, FTPS transport, cloud console Oracle milik pengguna, region capacity, external mail/domain account, validitas TLS, dan performance di server publik. Research menemukan beberapa404 dan429; itu keterbatasan dokumentasi, bukan hasil load test atau bukti sebuah layanan sedang down.

## 9. Sumber Resmi Yang Diakses

Semua URL di bawah diakses pada audit 25 Oktober 2026. Isi/kuota dapat diperbarui provider; cek ulang sebelum provisioning. Forum Admin knowledge-base dipisahkan dari jawaban community support.

| Ref | URL | Bukti yang dipakai |
| --- | --- | --- |
| S1 | https://laravel.com/docs/13.x/deployment | Public-only webroot, PHP/extensions, permissions, config caching, debugfalse |
| S2 | https://www.infinityfree.com/ | PHP8.4, MySQL8.0/MariaDB11.4,5GB, SSL/free subdomain, free hosting |
| S3 | https://forum.infinityfree.com/t/why-are-my-files-deleted-after-uploading-them/49310 | Admin KB: file luar htdocs dihapus; tipe/size restrictions. Komentar PDF tidak dianggap kepastian terbaru |
| S4 | https://forum.infinityfree.com/t/how-to-move-a-website-to-a-different-directory/88900 | Admin KB: domain terhubung htdocs; FTP copies/inodes concern |
| S5 | https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353 | Admin KB: mandatory JS/cookie, HTML200 challenge, same-domain AJAX, API/automation restrictions |
| S6 | https://forum.infinityfree.com/t/file-size-and-upload-limits/49308 | Admin KB:1MB PHP/JS/HTML,10KB htaccess,10MB other; PHP upload limit20MB, bukan bukti aggregate post_max_size |
| S7a | https://forum.infinityfree.com/t/how-to-use-ssh-connection/108430 | Community support resmi hosted forum,6April2025: free tidak menyediakan SSH |
| S7b | https://forum.infinityfree.com/t/ssh/112637 | Forum support,3Juli2025: SSH unavailable; Composer lokal+FTP merupakan saran library, bukan proof keamanan Laravel |
| S8 | https://forum.infinityfree.com/t/how-to-upload-files-with-ftp/49306 | Admin guide: FTP/FileZilla dan htdocs; tidak membuktikan SSH/SFTP |
| S9 | https://forum.infinityfree.com/t/cron-job-feature-is-now-disabled/80495/1 | Admin announcement9Agustus2023: cron/background periodic requests disabled/prohibited |
| S10 | https://forum.infinityfree.com/t/how-to-backup-your-website/49304 | Admin KB: provider tidak membuat backup free account; FTP/phpMyAdmin manual |
| S11 | https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier.htm | Free tier overview/trial vs Always Free |
| S12 | https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm | Compute2OCPU/12GB total setara kuota, block200GB, idle reclaim dan batas port25; kuota aktual tetap perlu konfirmasi konsol |
| S13 | https://www.oracle.com/cloud/free/faq/ | Verifikasi kartu/temporary authorization hold, region capacity, no SLA, no auto paid tanpa upgrade |
| S14 | https://render.com/docs/free | Free service spindown/ephemeral FS/no persistentdisk/free PG30hari |
| S15 | https://docs.railway.com/pricing/plans | Free $1 credit/bulan dan billing penggunaan |
| S16 | https://docs.railway.com/reference/pricing/free-trial | Trial30hari/$5, limitations, trial volume retention, temporary anonymous VM |
| S17 | https://www.duckdns.org/about.jsp | Pilihan dynamic-DNS/subdomain gratis, bukan domain yang sudah disediakan untuk aplikasi |
| S18 | https://www.alwaysdata.com/en/pricing/ | Dialihkan ke offers: Free0Euro/mo lifetime personal,1GB,256MB,1/4CPU,3hari backup; Plus30hari bukan paket Free |
| S19 | https://help.alwaysdata.com/en/docs/admin-billing/billing/public-cloud-prices/ | Free non-profit, hanya address alwaysdata.net, no services; quota files+mail+database |
| S20 | https://help.alwaysdata.com/en/docs/web-hosting/languages/php/ | PHP hingga8.5, php.ini configurable, Apache/FastCGI, Composer/extensions |
| S21 | https://help.alwaysdata.com/en/docs/web-hosting/databases/mariadb/ | MariaDB10.11/11.4/11.8,40connections/user, events tidak supported, backup |
| S22 | https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/ | SSH di semua lingkungan, tanpa root; permission key/user penting |
| S23 | https://help.alwaysdata.com/en/docs/web-hosting/backups/ | Daily file/database/mail, Free3hari, datacenter terpisah, backup tidak masuk disk quota |
| S24 | https://help.alwaysdata.com/en/docs/web-hosting/sites/ssl-tls/lets-encrypt/ | HTTPS renewal otomatis; subdomain alwaysdata.net dilayani wildcard platform |
| S25 | https://www.alwaysdata.com/en/terms-and-legal/ | Free account bisa suspended/deleted bila legitimacy dipersoalkan; legal/data obligations |
| S26 | https://www.alwaysdata.com/en/academic-cloud/ | Program pendidikan, diskon50% bukan gratis permanen hosting paid |
| S27 | https://www.alwaysdata.com/en/environment/ | Provider menyatakan infrastrukturnya berada di Prancis |
| S28 | https://help.alwaysdata.com/en/docs/web-hosting/sites/add-a-site/ | Konfigurasi site/addresses/root directory, PHP via Apache, SSL controls |

## 10. Rekam Tindakan Dan Persetujuan

Dilakukan: baca source/config/lockfile tanpa mencetak secret; `php -v`, Composer/Node/npm version; `composer validate --strict`; `composer check-platform-reqs --no-dev`; query SQL read-only database version dan agregat 1user/0report; baca dokumentasi resmi. Tidak menjalankan test mutasi, build, install dependency, update config, dump DB, provisioning/SSH/FTP/upload atau pemeriksaan dokumen KTM.

Fase A–B hanya menambahkan file ini. Fase C–E belum dimulai. Tidak ada URL final/SSL/admin production/password/resource ID untuk dibagikan. Credential hosting/DB/mail, OTP, data kartu dan private key tidak dicari atau diminta di chat.

### Keputusan Yang Sudah Diberikan

- Target adalah pilot terbatas mahasiswa/teman sekelas dengan akun/data sendiri, bukan tujuan akhir mock/demo.
- Hosting+database wajib Rp0. Verifikasi kartu hanya bersedia dipertimbangkan, bukan izin memasukkan data kartu atau membuat tagihan.
- Database online baru/kosong, peserta register sendiri; tidak ada migrasi data/akun/KTM/report laptop.
- **Hanya audit dan rekomendasi.** Tidak ada persetujuan membuat resource, upload, konfigurasi, migration, atau deployment.

### Persetujuan Masa Depan Yang Masih Diperlukan

Pilih platform yang layak setelah mempertimbangkan syarat dan risiko di atas; verifikasi akun/kartu manual hanya setelah diizinkan. Setujui cohort, retensi dan penanggung jawab data serta rencana update/rollback. Sesudah itu berikan otorisasi deployment yang terpisah. Sampai ada instruksi tersebut, pekerjaan berhenti pada laporan, bukan meminta credential atau membuat resource.

## 11. Pembaruan Setelah Kode Berubah

Prosedur berikut menjaga data pilot tetap milik peserta dan tidak ditimpa setiap release. **Belum dijalankan.**

1. Catat perubahan, nomor release dan checksum. Jika kelak memakai Git, jangan memasukkan .env/filedata/output; pembuatan repo/remote masih tindakan terpisah yang memerlukan izin.
2. Tinjau perubahan schema/permission/fitur dan efek pada kontrak frontend. Jalankan regression pada database test terisolasi, bukan production/pilot. Perubahan dependency memakai lockfile yang direview, tidak update bebas di server.
3. Build release produksi di staging/CI dengan dependency no-dev dan aset Vite. Periksa secret/package size/PHP platform. Biarkan .env production, storage upload dan database di shared persistent path; jangan sertakan salinan dari laptop.
4. Sebelum perubahan yang memengaruhi data, backup SQL+file secara konsisten dan simpan konfigurasi/key secara privat. Pastikan ada ruang dua release dan log/backup tanpa keluar quota gratis. Jangan menghapus upload pengguna agar release muat.
5. Deploy ke direktori release baru. Jalankan hanya migration baru yang telah disetujui, dengan pendekatan expand-contract bila code lama perlu tetap kompatibel. Jangan `migrate:fresh`, import dump lokal, rerun DemoDataSeeder, atau seed permission yang akan menimpa role policy tanpa review.
6. Buat cache config/route/view pada target dengan env benar, alihkan root/release pointer, restart proses yang diperlukan. Untuk VM, operator menangani FPM; untuk alwaysdata, memakai kontrol site/SSH yang tersedia, bukan daemon root.
7. Smoke HTTPS/aset/login/role/private-download/upload/reset/persistence sesuai perubahan dengan akun uji yang disetujui. Hindari membuat/mengubah laporan nyata untuk test otomatis. Catat keberhasilan dan error tanpa credential/PII.
8. Jika gagal, alihkan code ke release sebelumnya. Jangan otomatis menurunkan schema atau restore database, karena bisa menghapus data baru peserta. Rollback data membutuhkan maintenance dan persetujuan spesifik; shared files dan APP_KEY dipertahankan.
9. Pantau error, quota storage/DB/RAM, backup, sertifikat dan free-usage setelah update. Hapus hanya release lama yang dipilih secara aman sesuai kebijakan, bukan seluruh storage/history. Jika Rp0 tidak lagi dapat dipenuhi, hentikan perluasan/penerimaan data menurut prosedur yang disepakati dan minta keputusan, bukan upgrade otomatis.
