# Rencana Perbaikan Keamanan Sebelum Pilot Ruang Dengar

Tanggal: 25 Oktober 2026. Acuan awal: `docs/DEPLOYMENT-READINESS-RUANG-DENGAR.md`. Source terkait diperiksa ulang secara statis pada pekerjaan ini, bukan hanya menyalin kesimpulan audit lama.

## 1. Keputusan Saat Ini

**NO-GO untuk mulai menerima KTM dan pengaduan mahasiswa sungguhan sekarang.** Ini keputusan kesiapan berdasarkan blocker yang belum ditutup, bukan klaim telah terjadi kebocoran data aktual. Sistem juga belum dinyatakan siap produksi.

Target tetap **pilot terbatas teman sekelas, biaya hosting dan database Rp0, database online baru yang tidak mengimpor akun/token/KTM/laporan dari laptop**. Peserta nantinya mendaftar dan mengisi data sendiri. Data fiktif hanya digunakan untuk QA terisolasi, bukan pengganti tujuan pilot.

Oracle Always Free dan alwaysdata Free tetap kandidat bersyarat sebagaimana laporan deployment. Belum ada platform yang disetujui/diprovision untuk pekerjaan ini. Kontrol keamanan tidak boleh diturunkan untuk menyesuaikan hosting gratis. Jika resource gratis tidak dapat menjalankan storage privat, backup, atau pemeriksaan file yang disepakati, pilot ditunda atau scope dinegosiasikan; bukan upgrade berbayar otomatis.

**Yang dilakukan sekarang:** membaca source/migration/test/dokumentasi dan menulis laporan ini. **Yang tidak dilakukan:** membaca `.env` atau nilai credential/token, membuka KTM, query database, menjalankan test/build/Artisan/migration, upload, membuat akun/resource cloud, memasukkan kartu, atau deployment. Semua langkah implementasi dan pengujian di bawah adalah rencana yang memerlukan persetujuan terpisah.

## 2. Cara Menilai Temuan

| Istilah | Makna |
| --- | --- |
| Terbukti pada source | Alur/ketiadaan kontrol dapat dilihat langsung pada kode; belum berarti insiden runtime pada data nyata |
| Indikasi yang harus direproduksi | Kombinasi policy/query/state menunjukkan kemungkinan perilaku salah; butuh test terisolasi sebelum kesimpulan eksploitasi |
| Belum diverifikasi | Tidak ada pemeriksaan lingkungan/data aktual pada audit ini |
| Keputusan kebijakan | Pemilik pilot belum memutuskan aturan; asisten tidak menetapkannya sebagai keputusan final |
| P0 / kritis | Bypass atau kebocoran berat terkonfirmasi; hentikan penerimaan data dan tangani insiden |
| P1 / tinggi | Risiko integritas/privasi/kontrol akses atau kewajiban operasional yang menghalangi pilot |
| P2 / sedang | Risiko terbatas/defense-in-depth/kelengkapan yang perlu mitigasi dan penilaian konteks |
| P3 / rendah | Penyempurnaan non-esensial yang tidak menutup blocker keamanan |

**Tidak ada P0 baru yang dikonfirmasi.** Tidak ada tes mutasi atau pemeriksaan dokumen/data nyata untuk menyimpulkan exploit terjadi. Nilai prioritas tidak menggantikan penilaian hukum atau sertifikasi keamanan. P2 tertentu tetap menjadi syarat pilot apabila merupakan bukti minimum perlindungan data.

## 3. Kontrol Yang Sudah Ada Dan Harus Dipertahankan

- Registrasi memaksa role student/unverified; NIM wajib pada request register/submit. Email mempunyai constraint unik. Akun privileged tidak boleh mengubah role sendiri melalui API.
- `ReportPolicy` membatasi pemilik, penugasan, kelas, permission dan konflik. Super Admin tidak otomatis memperoleh permission isi laporan atau KTM.
- `AttachmentPolicy` mengikuti izin private detail induk report, bukan hanya siapa yang mengunggah. KTM melalui `IdentityVerificationPolicy` hanya pemilik atau petugas berwenang; self-review ditolak.
- File disimpan pada disk private dengan nama acak, MIME terdeteksi server, batas ukuran, `serve=false`; download memakai no-store dan nosniff. Cleanup exception untuk kegagalan metadata/transaksi sudah ada.
- Judul/isi report dipisahkan dari response metadata, update internal disembunyikan dari reporter, audit memakai allow-list. Ini tetap perlu regresi lintas semua jalur, bukan jaminan semua metadata aman.
- Hardening terdahulu sudah menambahkan `class_id` pada helper notifikasi kelas dan menghapus fallback ID numerik report. Jangan mengulang klaim bahwa dua perubahan tersebut belum dibuat.
- Workflow mempunyai transaksi/history, reviewer banding independen, guard last active admin, password hashing/revokasi token, rate limit, dan client escaping/stale-response guard.

Test yang dibaca mencakup `AuthorizationIsolationTest`, `ReportPrivacyRegressionTest`, `ConflictAndSuperAdminPrivacyTest`, `FinalReportAuditTest`, `FinalNonReportAuditTest`, `NonReportSecurityAuditTest`, dan `IntegratedHardeningTest`. **Tidak ada test dijalankan pada pekerjaan ini.** Hasil historis 127 tes Laravel/702 assertion pada laporan hardening bukan hasil baru, bukan persentase aman, dan tidak membuktikan kasus tambahan di bawah sudah lulus.

## 4. Daftar Temuan Prioritas

| ID | Prioritas/status | Risiko dan bukti source | Yang diperlukan | Gerbang pilot |
| --- | --- | --- | --- | --- |
| SEC-01 | P1, terbukti | `IdentityVerificationService.php:38–61` mengganti `users.nim` saat submit; reject `:121–136` tidak mengembalikan nilai sebelumnya | Pisahkan kandidat pengajuan dari identitas yang disetujui, sesuai keputusan canonical/state | Wajib diperbaiki sebelum verifikasi nyata |
| SEC-02 | P1, terbukti + kebijakan | Approval `IdentityVerificationService.php:89–98` menyalin nama/NIM tanpa riwayat koreksi privat lengkap; membership memakai snapshot pada `MembershipService.php:88–94` | Versi/keterkaitan keputusan identitas, alasan koreksi, semantik snapshot membership dan identitas pada laporan lama | Wajib diputuskan dan diimplementasikan sebelum koreksi nyata |
| SEC-03 | P1, terbukti + kebijakan | `PrivateStorageService.php:47` menghitung SHA-256; pencarian referensi app hanya menemukan penyimpanan/hidden model. NIM tidak unique pada migration users `:18–21`; membership hanya unique user+class `:57–60` | Duplicate-review policy NIM/KTM, sinyal same-account vs cross-account, pengujian race; bukan langsung unique global atau tuduhan fraud | Wajib ada penanganan duplikasi yang disepakati sebelum approval mahasiswa |
| SEC-04 | P1, indikasi race | Otorisasi submit dan Gate review identity dilakukan di luar lock; setelah lock service memeriksa pending, tetapi tidak me-refresh dan mengoordinasikan kembali hak reviewer/pemohon dengan pencabutan role/akun | Reproduksi revoke-vs-submit/approve/reject; desain linearization/urutan lock dan recheck otorisasi yang konsisten | Wajib dibuktikan aman sebelum verifikasi nyata |
| SEC-05 | P1, perbedaan source terkonfirmasi; dampak perlu reproduksi | `ReportController.php:64–69` memfilter daftar berdasarkan managed class, tidak conflict per report; `ReportPolicy.php:51–53` menolak view bagi pihak berkonflik | Samakan keputusan metadata/detail/list/statistics/notification; putuskan siapa boleh mengetahui keberadaan kasus | Wajib diselesaikan sebelum pengaduan nyata, bukan hanya title=null |
| SEC-06 | P1, indikasi akses metadata setelah revoke | `AppNotification.php:43–49` menerima class membership, semua MembershipDecision, atau class_id null. Tidak membedakan bekas penangan yang masih anggota dari pemilik pemberitahuan personal | Resource-aware visibility, tujuan penerima dan scope notifikasi; jangan blanket-hide pemberitahuan pemilik yang masih sah | Wajib reproduksi dan tutup jalur metadata staf yang tidak lagi berwenang |
| SEC-07 | P1, kontrol belum lengkap | MIME/size/random path ada, tetapi tidak ada quarantine/scanner/retention state pada `PrivateStorageService.php:28–84,110–125`; private disk belum diuji pada host | Validasi isi yang disepakati, karantina/fail-closed untuk file berisiko, batas kapasitas, no direct URL, host isolation dan private backup | Wajib kontrol file minimum sebelum upload peserta |
| SEC-08 | P1 operasional/kebijakan | Tidak ada jadwal retention pada `routes/console.php`; identity cascade user pada migration akademik `:112–131`; report/history punya hook larangan delete dan SQL cascade | Retensi per jenis data, legal hold, penghapusan terotorisasi, metadata tombstone/restore handling, backup dan restore drill | Wajib sebelum pengumpulan KTM/laporan nyata |
| SEC-09 | P1 operasional, sebagian source | Revoke lecturer/member/invitation belum punya route lengkap; helper remove/revoke tidak boleh langsung dipublikasikan tanpa policy/recheck. Conflict lifecycle juga belum ada | Mekanisme penarikan akses/penangan konflik yang dapat dioperasikan dan diuji; bukan SQL manual atau role toggle sembarang | Wajib cara operasional aman sebelum pilot; seluruh UI lifecycle boleh bertahap sesuai syarat bagian8 |
| SEC-10 | P2 privasi, source terkonfirmasi | Report sudah public_ref; appeal/attachment/KTM masih numeric. Unauthorized response403 vs missing404 pada binding/controller | Concealment kontrak response bagi pihak tidak berhak; identifikasi publik baru opsional jika diperlukan | Keputusan dan regresi concealment minimum wajib sebelum dokumen/kasus privat; migrasi semua ID bisa ditunda |
| SEC-11 | P1 gerbang deployment, belum diverifikasi pada target | Laporan readiness mencatat minimum PHP efektif8.4.1, debug lokal aktif, limit upload di bawah fitur, dan mail log. Belum ada target HTTPS/storage/backup yang diuji | Konfigurasi target yang disetujui, persistent disk+DB, secret handling, recovery akun, TLS, pemeriksaan boundary hosting, capacity plan Rp0 | Wajib sebelum membuka pilot online, tanpa provisioning pada tahap ini |
| SEC-12 | P2 defense-in-depth, sebagian ada | JSON response error dan QueryException logging disanitasi, tetapi log lain/server/access/CDN/download client berada di boundary berbeda | Canary test tanpa secret nyata, no-store response sensitif, audit download minimum, redaksi/cookie/session/CSP sesuai target | Bukti minimum wajib; SIEM/MFA/tamper-evident archive penuh dievaluasi terpisah |

### 4.1 Mengapa SEC-01/02 bukan sekadar masalah label UI

Alur saat ini: pendaftaran menerima NIM kandidat → submit mengganti NIM profil dan menurunkan identity_status ke pending → approve menyalin lagi nama/NIM → reject mengubah status menjadi rejected. Fitur kelas memang tertutup selama pending/rejected, tetapi itu tidak mempertahankan identitas terakhir yang sudah disetujui.

Hubungan utama menggunakan `user_id`, bukan NIM; perlindungan ini harus dipertahankan. NIM tidak boleh menjadi pengganti ownership report. `ReportDetailResource.php:53–57` membaca nama/NIM profil saat ini, sedangkan membership menyimpan snapshot terpisah. Dengan koreksi nama/NIM, tampilan historis dapat berbeda. Belum diputuskan apakah tampilan laporan harus menunjukkan identitas terbaru atau versi saat submit. Jangan menganggap memperbarui semua snapshot historis otomatis adalah perbaikan yang benar.

Usulan aman untuk dibahas: pengajuan baru menyimpan kandidat sendiri; referensi identitas terakhir disetujui tetap utuh sampai approve. Pengajuan pertama belum punya identitas canonical. Rejected/cancelled/expired correction tidak menimpa canonical. Hak kelas saat correction pending harus diputuskan eksplisit, bukan otomatis dipertahankan atau ditutup tanpa kebijakan.

Di sini **canonical** berarti identitas terakhir yang telah disetujui, sedangkan **candidate** adalah identitas yang sedang diajukan. Keberadaan keduanya dan pilihan field/tabel masih rancangan, bukan skema yang telah dibuat.

### 4.2 Checksum: deteksi kecocokan, bukan penghakiman

SHA-256 sekarang berasal dari byte upload, bukan nilai yang dikirim klien. Itu dasar yang baik, tetapi belum ada pencocokan pada seluruh riwayat yang masih sah dipertahankan. Kecocokan dapat berarti pengajuan ulang pemilik yang sama, file contoh, atau penggunaan lintas akun yang harus diperiksa. Ketidakcocokan hash tidak membuktikan dokumen berbeda karena scan/kompresi/crop dapat mengubah byte.

**Jangan otomatis menolak sebagai penipuan, memblokir pemilik sah, menampilkan pemilik checksum kepada pemohon lain, atau menambahkan unique checksum/NIM global.** Usulan mekanisme awal: hash asli dihitung server, sinyal match terpisah dari status verifikasi, examiner melihat informasi minimum berdasarkan scope yang disepakati, dan keputusan/override dicatat privat. NIM dengan match juga diperlakukan sesuai namespace/aturan normalisasi yang masih menunggu keputusan. Riwayat rejected tidak boleh diam-diam diabaikan; umur riwayat itu dibatasi kebijakan retensi yang disetujui.

### 4.3 Area otorisasi yang belum dicakup tes lama

- Mantan ketua yang masih menjadi mahasiswa aktif kelas masih mempunyai accessibleClassIds. Ini berbeda dari managedClassIds. Scope notifikasi hanya menggunakan accessibleClassIds; karena itu test lecturer yang benar-benar kehilangan kelas tidak membuktikan kasus mantan ketua aman.
- `MembershipDecision` dipakai untuk pemberitahuan personal dan pengelola. Pengecualian seluruh tipe dapat mempertahankan metadata permintaan orang lain. Pisahkan tujuan/recipient relation sebelum memutuskan apakah pesan boleh tetap terlihat.
- `ReportPolicy::view` menolak pihak berkonflik, tetapi daftar kelas belum mengulang kriteria itu. Yang mungkin terlihat adalah reference/status/category/waktu, **bukan bukti isi report sudah bocor**. Metadata satu kelas kecil tetap dapat mengidentifikasi kejadian.
- `AppealService::submit` menghitung eligible reviewers tetapi mengirim notice ke helper seluruh dosen kelas. Penerima saat create, visibility saat read, konflik baru, serta revoke sesudahnya harus diuji bersama.
- Owner aktif tetap dapat membaca laporannya sendiri meskipun membership lama tidak aktif, sesuai policy sekarang. Jangan mengubahnya menjadi bug atau menutup akses owner secara massal; perbedaan owner history vs staff privilege harus menjadi keputusan kebijakan dan test positif.
- Controller download sudah memanggil policy. ID acak/concealment adalah defense-in-depth, bukan pengganti policy dan bukan alasan membiarkan cross-class response berisi private data.

## 5. Keputusan Kebijakan Yang Menjadi Prasyarat

Semua baris di bawah berstatus **MENUNGGU PERSETUJUAN**, bukan keputusan final dari audit. Tidak menafsirkan prompt lama sebagai izin memilih detail yang belum disepakati.

| Kode | Pertanyaan keputusan | Rekomendasi untuk dibahas, bukan diterapkan |
| --- | --- | --- |
| K01 | Identitas canonical disimpan di mana dan kapan dapat berubah? | Pisahkan candidate submission dan approved version; jangan overwrite approved identity pada submit |
| K02 | NIM unik pada namespace apa, case/leading zero/format apa, re-enrollment bagaimana? | Perlakukan string, hindari normalisasi kehilangan makna, gunakan duplicate review dahulu; constraint hanya sesudah keputusan/audit data |
| K03 | Same-account reuse, cross-account match, rejected/verified resubmit dan review conflict bagaimana? | Sinyal bukan fraud; review terpisah, anti-enumeration, override beralasan dan informasi minimum |
| K04 | Hak akun/member saat correction pending, suspended, rejected, class transfer? | Putuskan state table yang membedakan approved identity sebelumnya, candidate, membership, role dan account active |
| K05 | Snapshot NIM/name pada membership/report/audit lama harus tetap historis atau mengikuti koreksi? | Referensi identity version; jangan backfill atau rewrite seluruh history tanpa alasan dan persetujuan |
| K06 | Apakah seluruh isi KTM dan semua jenis attachment benar-benar diperlukan? | Minimalkan field/jenis file; bila mengurangi upload atau memakai metode verifikasi lain, perlukan perubahan resmi dengan test, bukan bypass permission |
| K07 | Berapa retensi file, metadata/hash, history rejected, laporan/banding, security logs dan backup? Apa pemicu hitung dan legal hold? | Durasi tidak dikarang di sini; tetapkan batas, akses, alasan, dan penghapusan/backup-expiry yang dapat diaudit |
| K08 | Siapa boleh melihat metadata kasus, conflict, match candidate dan riwayat koreksi? | Pisahkan verifier/handler/admin, owner personal history dan bekas staf; tanpa self-clear conflict |
| K09 | Siapa penanggung jawab data, operator, reviewer independen dan kontak insiden? | Nama/peran ditetapkan pengguna; pilot teman sekelas tetap memproses data pribadi dan mungkin tuduhan terhadap orang lain |
| K10 | Jumlah peserta, durasi pilot, kuota upload/storage, RPO/RTO dan cara menghentikan penerimaan? | Tetapkan angka sebelum memilih resource gratis; jangan purge data atau upgrade biaya otomatis saat kuota penuh |

Kebijakan privasi perlu menjelaskan tujuan, data yang dikumpulkan, penerima akses, lokasi penyimpanan/backup, prosedur koreksi/penghapusan dan insiden. Penilaian legal/afiliasi kampus tidak digantikan oleh audit kode. Logo UNPAM bukan bukti layanan resmi kampus. Jika butuh persetujuan institusi/pihak ketiga, peroleh sebelum pilot terkait, bukan menulis klaim sendiri.

RPO berarti batas kehilangan data yang dapat diterima setelah insiden; RTO berarti batas waktu pemulihan layanan. Keduanya harus disepakati dan dibuktikan melalui restore drill, bukan dijanjikan tanpa pengujian.

## 6. Rencana Implementasi Berurutan

**Semua paket belum dilaksanakan.** Nama field/status/file di bagian ini adalah desain usulan. Setiap paket dibuat kecil, direview, diuji pada lingkungan terisolasi, dan dapat dihentikan apabila keputusan/input belum tersedia. Persetujuan menulis kode tidak sekaligus mengizinkan deployment atau perubahan database laptop.

### Paket 0 — Keputusan, threat model dan kontrak data

- Selesaikan K01–K10, petakan alur data (register, identity, member, report, notification, backup/delete) dan aktor biasa/staf/operator.
- Sepakati field response minimum dan akses owner historical. Sahkan limit peserta/volume serta prosedur insiden/penarikan akses.
- Buat contoh state/input-output fiktif untuk candidate/approval/rejection/reuse/conflict. Tandai pengujian berisiko serta siapa yang menyetujuinya.
- **Lulus jika:** keputusan tertulis, semua cabang identitas/retensi jelas, tidak ada asumsi keunikan atau default purge yang belum disetujui. Tanpa ini, Paket 1 tidak boleh menetapkan schema final.

### Paket 1 — Identitas canonical/candidate dan koreksi atomik

- Desain migrasi tambahan yang memisahkan approved reference/version, candidate NIM/nama dan keputusan reviewer; pilih bentuk paling kecil yang memenuhi policy, bukan rewrite keseluruhan user.
- Submit menyimpan candidate tanpa menimpa canonical; akun tanpa approval tidak memperoleh status verified. Status candidate pending dan hak akun mengikuti K04. Approve/reject bekerja terhadap versi yang benar dan pending yang belum diputuskan.
- Approval memperbarui hanya referensi/nilai yang disepakati; alasan koreksi dan previous/new version tercatat pada history privat. Audit admin cukup ID tindakan/status, bukan NIM mentah/KTM/reason bebas.
- Sinkronisasi atau pelestarian snapshot membership/report mengikuti K05; ownership tetap user_id. Jangan mengganti identitas pemilik report berdasarkan kecocokan NIM.
- Refresh aktor/target, reauthorize setelah lock, dan koordinasikan urutan lock dengan suspend/role-change/revoke. Harus ada titik urutan keputusan yang jelas: operasi yang sudah selesai sah tidak dibatalkan retroaktif, operasi sesudah revoke tidak boleh memakai state lama.
- **Lulus jika:** submit/reject tidak mengubah canonical; approval versi aktif saja; satu keputusan/pending sesuai policy; no self-review; revoke-vs-approve mempunyai hasil konsisten; rollback tidak meninggalkan perubahan parsial/file.

### Paket 2 — Duplicate review NIM/KTM yang menjaga privasi

- Hitung SHA-256 server-side pada file asli; bila dibuat turunan aman, tandai checksum asli vs turunan, jangan mengubah makna historis diam-diam.
- Query match pada riwayat yang dipertahankan sesuai K03/K07, termasuk rejected/superseded bila masih dalam retensi. Record tanpa hash diberi status unknown, bukan dianggap unik.
- Catat sinyal same-user/cross-user dan review outcome terpisah; dukung pengajuan ulang sah. Respons pemohon tidak mengungkap akun/NIM/dokumen orang lain.
- Atur indeks dan mekanisme serialisasi/recheck saat dua upload/approval bersamaan. Lock satu user tidak mencakup dua akun; jangan menjadikan hash unik sebagai pengganti desain review.
- Pertimbangkan exact normalized-NIM match sesuai namespace disepakati, tanpa auto merge/delete akun.
- **Lulus jika:** matriks reuse/history/race tertangani, tindakan admin/verifier hanya dalam scope, tidak ada decision fraud otomatis atau kebocoran identitas match.

### Paket 3 — Isolasi akses lintas user/kelas dan revokasi

- Selaraskan query list, detail, stats, download, updates, appeals, notification, unread_count, mark-read/all terhadap kontrak metadata K08.
- Terapkan recipient-purpose/resource reference pada notifikasi staf; helper class_id dipertahankan tetapi bukan satu-satunya kriteria. Pesan personal keputusan membership tidak boleh hilang atau bocor hanya karena enum yang sama.
- Jangan kirim broadcast metadata kasus sensitif kepada pihak berkonflik; periksa saat penentuan penerima dan saat dibaca karena akses dapat berubah. Tangani legacy unscoped secara fail-safe jika ditemukan, bukan menebak dari teks.
- Sediakan penarikan akses yang benar-benar dapat dipakai operator untuk pilot, lengkap dengan role/target/class check, transaksi, idempotency dan audit. Helper revoke lama tidak dipasang route mentah tanpa guard.
- Tetapkan concealment resource privat: guest401 seragam; authenticated unauthorized terhadap resource tersembunyi dapat memakai404 generik tanpa meta khusus yang membedakan keberadaan. Pertahankan klasifikasi denial di log internal yang aman. Kebijakan action-denied pada resource yang memang sudah boleh dilihat bisa berbeda jika disepakati.
- Pertahankan public_ref report. Public ID baru untuk appeal/attachment bukan syarat mutlak jika concealment+policy sudah terbukti; jangan migration ID tanpa kebutuhan.
- **Lulus jika:** tidak ada row/reference/body/file/count yang di luar kontrak pada matriks dua user/dua kelas; revoke menutup privilege staf tanpa merusak hak owner yang disepakati; reporter/internal note/admin boundaries tetap berlaku.

### Paket 4 — Intake berkas privat dan kapasitas

- Tetapkan jenis file minimum K06. Berkas yang belum dinyatakan aman tetap di quarantine nonpublic dan tidak dapat diunduh verifier/handler melalui endpoint biasa.
- Pilih kontrol isi yang cocok: batas ukuran/count aktual, MIME dan struktur file, decoder yang aman untuk gambar, pemindaian malware untuk format berisiko, metadata/EXIF, resource limit, dan penanganan polyglot/PDF aktif. Re-encoding dokumen dapat mengubah bukti; simpan/tidak simpan original harus diputuskan terlebih dahulu.
- Scanner open-source lokal dapat dipertimbangkan tanpa biaya lisensi, tetapi penggunaan RAM/disk/CPU harus muat paket Rp0. Jangan mengirim KTM ke layanan scan publik/eksternal tanpa persetujuan data; jika scanner tidak tersedia/down, fail-closed pada intake terkait atau batasi scope lewat perubahan yang disetujui, bukan melewati pemeriksaan.
- Bedakan state uploaded/quarantined/accepted/rejected/deleted menurut desain minimal. Fail write/hash/metadata/DB harus rollback/cleanup; janitor orphan perlu dry-run dan referensi yang diverifikasi, termasuk kasus process kill.
- Periksa path traversal/header filename, Content-Disposition attachment/no-store/nosniff, tidak ada executable route/direct public link. Uji batas PHP/FPM/web server dan kapasitas tanpa menulis dokumen nyata.
- **Lulus jika:** tidak ada akses file sebelum verdict, file invalid/oversize tidak menghasilkan sukses, error/rollback/cleanup terkendali, tidak ada PII pada path yang dipublikasikan atau log, dan kapasitas pilot terukur.

### Paket 5 — Retensi, audit minimum, backup dan pemulihan

- Implementasikan policy yang telah disetujui, bukan angka default baru. Dokumen/file, metadata hash, keputusan, report, log, cache, backup dan salinan operator perlu lifecycle masing-masing.
- Dry-run menyebut jumlah/ID internal tersamarkan, bukan mencetak KTM/NIM/path rahasia. Purge hanya record eligible, mengecualikan hold/pending action yang diwajibkan, memeriksa otorisasi dan dapat diulang aman.
- Desain record removed/tombstone dan `ktm_path` lifecycle agar file yang dihapus tidak membuat endpoint crash atau meninggalkan pointer salah. Update schema/history diperlukan hanya setelah review.
- Larangan delete pada model report/history dan SQL cascade harus dipetakan. Jangan menonaktifkan hook atau `truncate` sebagai solusi retensi. Tentukan data minimum yang tetap disimpan versus dihapus/pseudonymized sesuai tujuan/ketentuan yang disepakati.
- Backup terenkripsi dan akses terbatas, key terpisah, restore pada lokasi terisolasi. Restore tidak boleh membuat file yang sudah harus dihapus muncul kembali ke pengguna; terapkan daftar penghapusan/masa berlaku sesuai policy. Jangan menjanjikan penghapusan backup seketika jika retention provider berbeda.
- Audit download/purge/review cukup actor/resource reference/hasil/waktu sesuai policy, tanpa raw token/hash/NIM/isi. APP_KEY bukan bukti semua data terenkripsi; volume/database/backup encryption perlu bukti terpisah.
- **Lulus jika:** policy lengkap, job atau runbook manual terjadwal benar-benar dijalankan/diuji pada data fiktif, hold/retry/partial failure aman, restore drill berhasil dan ruang backup tetap dalam budget.

### Paket 6 — Quality gate lokal/CI terisolasi dan review independen

- Periksa config efektif/target sebelum test. Buat regresi red lebih dahulu terhadap bug yang terkonfirmasi, lalu green; jangan menghapus assertion agar angka suite tetap hijau.
- Gunakan dua user/dua kelas/five roles dan timestamp terkontrol. Test race menggunakan dua koneksi/proses/barrier nyata pada engine tujuan, bukan hanya dua panggilan berurutan; tetap pertahankan stale-object unit regression.
- Run suite lama + baru, randomized order, frontend unit, browser fixture, serta E2E backend nyata terisolasi. Label fixture vs live dan API langsung vs klik browser secara jelas.
- Siapkan release dari lockfile; platform check efektif PHP>=8.4.1. Scan dependency/secrets pada artifact tanpa mencetak nilainya. Review perubahan policy/resources/model oleh orang berbeda jika memungkinkan.
- **Lulus jika:** seluruh kasus wajib bagian7 pass, tidak ada flaky critical test atau P0/P1 terbuka. Belum berarti deployment diizinkan.

### Paket 7 — Verifikasi lingkungan dan persetujuan buka pilot

- Setelah persetujuan hosting/deployment terpisah, verifikasi platform Rp0, persistent disk/DB, root public, HTTPS, debug off, secret isolation, no public artifacts, limits dan recovery/SMTP aman pada target.
- Database hosting baru hanya schema/katalog dan admin awal yang diotorisasi; tidak mengimpor akun/token/KTM/report laptop. Akun/data test dari preflight tidak menjadi isi final pilot.
- Smoke security/persistence/backup di environment target terotorisasi. Sesudah sign-off, peserta nyata mendaftar sendiri dan menerima pemberitahuan privasi. Jangan menambahkan peserta otomatis atau memalsukan verified.
- **Lulus jika:** semua gate G0–G7 terpenuhi, bukti tersedia dan pemilik pilot menyetujui pembukaan. Bila hosting Rp0 tidak mencukupi, kembali ke keputusan platform/scope, bukan membuka berkas atau melewati kontrol.

## 7. Pengujian Regresi Dan Bukti Kelulusan

**Status seluruh kasus tambahan: BELUM DIJALANKAN.** Tidak ada jumlah test baru yang diklaim lulus. Test data berikut harus fiktif dan database/filesystem terpisah, setelah izin implementasi/test diberikan.

| ID test/rancangan | Skenario | Kriteria lulus yang harus dibuktikan |
| --- | --- | --- |
| T01 | Registrasi tanpa NIM dan spoof role/status | Validasi/policy server sesuai K02; tidak membuat verified/privileged user; pesan tidak membocorkan NIM orang lain |
| T02 | First identity submission | Candidate tercatat; tidak ada approved identity sebelum keputusan; kelas tetap tertahan sesuai state policy |
| T03 | Verified user mengajukan koreksi | Canonical tidak berubah pada submit; candidate berbeda dan dapat dilacak; hak sementara sesuai K04 |
| T04 | Koreksi ditolak/dibatalkan/kedaluwarsa jika state tersebut disetujui | Canonical tidak tertimpa; alasan/history privat tersedia, tidak masuk audit global/notifikasi |
| T05 | Koreksi disetujui | Hanya candidate pending yang sah menjadi approved; version/actor/time/reason jelas; snapshot/historical display mengikuti K05 |
| T06 | Approve/reject ganda dan request stale | Satu keputusan final, keputusan kedua ditolak aman; semua perubahan/audit/notice atomik |
| T07 | Pemohon/reviewer disuspend, permission dicabut atau role berubah saat menunggu lock | Hasil mengikuti urutan commit yang jelas; tidak ada tindakan setelah revoke memakai kewenangan lama, tidak ada deadlock tak tertangani |
| T08 | Pemohon mencoba menyetujui sendiri atau memilih reviewer/identity_status via payload | Ditolak server; nilai terlarang tidak mempengaruhi record |
| T09 | Same-account checksum: pending, rejected, verified, superseded dalam retensi | Signal/allow/review sesuai K03; tidak tuduhan fraud; history yang diwajibkan tetap utuh |
| T10 | Cross-account checksum/NIM match | Sinyal hanya untuk reviewer berwenang; pemohon lain tidak menerima identitas/hash/daftar akun; tidak auto merge atau delete |
| T11 | Re-encoding/scan berbeda/checksum null/legacy | Hash berbeda tidak diklaim bukti unik; unknown tidak diisi hash rekaan; review fallback jelas |
| T12 | Dua upload/approval match bersamaan | Tidak kehilangan sinyal/decision atau melewati aturan duplicate; hasil konsisten dengan policy tanpa constraint global tak disetujui |
| T13 | User A/B pada kelas sama dan kelas lain | List/detail/update/appeal/file hanya sesuai ownership dan permission; metadata/count/filter tidak membuka resource terlarang |
| T14 | Handler assigned/unassigned, revoked leader yang masih member, lecturer kelas lain | Privilege staf hilang sesuai policy; current owner tetap memperoleh akses historis yang disepakati |
| T15 | Conflict existing/new, sensitive report, reviewer sebelumnya | Pihak berkonflik tidak mendapat data/action/metadata terlarang melalui daftar/statistik/notice; reviewer independent tetap berfungsi |
| T16 | Real notification producers: auto-routing, manual review, appeal, membership pending/personal decision | Payload aman; class_id+purpose/resource benar; revoke/role-change/conflict mengubah list/unread/count/mark-read dengan konsisten |
| T17 | Notifikasi historis tanpa class_id atau referensi tidak valid | Fail-safe sesuai policy; tidak menebak dari teks dan tidak mengubah history tanpa otorisasi |
| T18 | Super Admin, identity verifier dan orang luar membaca KTM/report/file | Admin tidak mendapat private otomatis; verifier hanya scope yang disepakati; unauth401, unauthorized concealment konsisten |
| T19 | Numeric/malformed/unknown ID, report public_ref, appeal/attachment/KTM | Policy tidak bergantung pada kerahasiaan ID; existing denied vs missing tidak membocorkan existence melalui body/header/meta yang berbeda sesuai kontrak |
| T20 | Internal note, assignment reason, reporter identity pada detail | Hanya field minimum bagi audience yang sah; internal note tidak ke reporter; canary rahasia tidak ada di list/notif/audit/log |
| T21 | Valid/invalid MIME, extension spoof, polyglot, malicious PDF, EXIF, image resource exhaustion | Safe/deny verdict sesuai kebijakan; quarantine tidak dapat diunduh; tidak mengeksekusi file atau mengirim data ke scanner eksternal |
| T22 | Oversize/count limit/post-body limit/disk penuh/scanner offline | Error413/422/503 sesuai kontrak, tanpa sukses palsu/partial public file; tidak unlimited upload atau bypass scanner |
| T23 | Direct public/storage URL, traversal, filename/header injection | File/root/config/log tidak dapat diambil tanpa izin; download authorised tetap benar dan no-store/nosniff/attachment |
| T24 | Metadata write fail, audit fail, transaction rollback, process kill | Kompensasi untuk exception; orphan reconciliation dry-run/execute hanya menyentuh file eligible setelah izin |
| T25 | Retensi rejected/approved/KTM/hash/report/log, hold/retry/purge partial | Jadwal hasil disetujui; tidak menghapus evidence yang masih wajib; tombstone/endpoint tetap aman; audit tidak berisi PII |
| T26 | Backup dan restore setelah purge | SQL+file+key pulih konsisten, salinan di luar webroot, data terhapus tidak terekspos kembali, RPO/RTO yang disepakati dibuktikan |
| T27 | Kredensial/token/browser private state | Current password benar, reset one-use, session revoke/expiration, stale JSON/Blob/BFCache/logout tidak menghidupkan akses lama; SMTP tidak mencetak token |
| T28 | Target HTTPS/config/redeploy/restart | PHP/DB kompatibel, tidak ada .env/log/testhelper terbuka, tidak mixed content/challengeHTML, record/file tetap ada setelah release, full rollback teruji |
| T29 | Biaya/kapasitas/mekanisme stop intake | Paket benar-benar Rp0 tanpa trial/upgrade, headroom terukur; saat threshold atau insiden, pengumpulan baru dapat dihentikan tanpa menghapus data |

Test lama wajib dipertahankan: `AuthorizationIsolationTest`, `ReportPrivacyRegressionTest`, `ConflictAndSuperAdminPrivacyTest`, `FinalReportAuditTest`, `FinalNonReportAuditTest`, `NonReportSecurityAuditTest`, `IntegratedHardeningTest`, credential/admin/workflow tests, serta frontend tests. Nama file baru dan jumlah kasus baru ditentukan saat implementasi; tabel ini acceptance criteria, bukan file yang sudah dibuat.

**Format bukti nanti:** release/commit atau manifest source, environment terisolasi, engine/SAPI/version, case ID, expected/actual, exit status, timestamp, hasil race, dan artifact tersamarkan. Jangan menyimpan raw password/token, NIM/KTM nyata, header Authorization, dump privat, atau key di laporan/test screenshot. Record lama hanya diuji dengan fixture fiktif; tidak mengimpor data laptop ke host.

## 8. Wajib Sebelum Pilot Versus Dapat Ditunda

| Wajib sebelum pilot data pengguna sendiri | Boleh ditunda dengan batas eksplisit |
| --- | --- |
| Keputusan identitas/canonical/duplicate/retensi dan penanggung jawab data | Integrasi resmi SSO/OCR kampus; aplikasi tidak boleh mengaku memiliki verifikasi resmi |
| Candidate correction, approve/reject atomik, sinyal duplicate-review sesuai policy | Constraint unique NIM global: bukan default perbaikan; menunggu keputusan namespace/data dan mungkin tidak diperlukan |
| Isolasi metadata/detail/file/notification serta revoke yang benar-benar bisa dijalankan | UI lifecycle lengkap dan staff search dapat bertahap jika minimum penarikan akses resmi sudah aman/diuji. Admin suspend yang ada hanya dapat menjadi mitigasi sementara jika dampak menutup seluruh akun disetujui; bukan pengganti revoke scoped selamanya |
| Mekanisme conflict independen yang dapat dioperasikan untuk laporan yang diizinkan | Editor conflict canggih/full workflow dapat ditunda hanya jika jalur minimum official dan independen tersedia; jangan self-clear atau SQL manual |
| Private storage, quarantine/validasi isi, batas upload, no direct URL, backup/restore dan retensi minimum | Otomasi scanner enterprise/CDR lanjutan dapat ditunda bila kontrol minimum yang disepakati benar-benar tersedia. Bila file tak dapat diperiksa aman, pengumpulan tipe itu tidak dibuka |
| Concealment yang disepakati bagi resource privat, plus policy tetap kuat | UUID/public ID migration seluruh tabel jika bisa ditutup dengan kontrak concealment yang lebih kecil dan kompatibel |
| Konfigurasi target HTTPS/debug-off/persistent-data, recovery admin dan monitoring quota | Dashboard monitoring canggih/SIEM; runbook minimum dan alert sederhana tetap wajib |
| Satu periode/kelas pilot yang benar, peserta/masa pilot terbatas dan kemampuan penghentian intake | CRUD periode penuh, multi-program/multi-periode, mass onboarding, export, public search, pin/edit aspirasi, animasi/audio baru |
| Keamanan sesi dasar dan prosedur recovery yang diuji | MFA sebagai implementasi tambahan dapat ditunda hanya setelah penilaian risiko; akun hosting/operator tetap wajib memakai MFA bila provider menyediakan/menuntutnya |
| Bukti regresi/race/target dan persetujuan risiko tertulis | Benchmark skala besar/certification formal; beban kecil pilot dan resource-limit test tidak boleh dilewati |

Penundaan tidak boleh berarti menerima kebocoran, menonaktifkan policy, menambah role sementara sembarangan, atau menyimpan file publik. Jika syarat mitigasi tidak terpenuhi, item menjadi blocker kembali. Pilot satu kelas tetap harus lulus tes negatif kelas kedua pada environment test, agar isolasi tidak hanya tampak benar karena dataset tunggal.

## 9. Keamanan Migration, Retensi Dan Database Online Baru

Tidak ada migration dibuat/dijalankan sekarang. Setelah persetujuan, gunakan perubahan additive/expand-contract bila memungkinkan dan uji forward/rollback di schema sekali pakai. Jangan mengubah migration lama agar database existing dianggap otomatis sesuai.

Untuk **database online baru**, initialise schema/katalog yang sudah lulus tanpa backfill data laptop. Akun/record peserta baru masuk lewat UI setelah gate. Tidak ada alasan mengimpor historical KTM untuk membuktikan duplicate detection; fixture fiktif terisolasi cukup untuk test.

Jika perubahan perlu dipakai pada instalasi lokal existing di masa depan, perlukan otorisasi migrasi terpisah. Audit data saat itu hanya agregat yang disetujui: berapa akun tanpa approved record, checksum null, beberapa approval, candidate terakhir rejected/pending, mismatch snapshot dan file hilang. Jangan merekonstruksi canonical dari `users.nim` begitu saja; field tersebut pernah menerima candidate. Record ambigu perlu review, bukan dihapus, di-merge atau diisi nilai tebak.

Hash dan ID internal tetap bisa menghubungkan dokumen/orang; bukan data anonim bebas retensi. Penghapusan file, penyimpanan hash, audit minimal dan backup harus punya tujuan serta waktu yang disahkan masing-masing. Perubahan key/encryption tidak otomatis membuang seluruh backup; lifecycle cryptographic key adalah prosedur yang perlu review.

## 10. Gerbang Kelulusan Dan Tanggung Jawab

Semua gate berikut **BELUM LULUS pada audit ini**. Tim/pemilik pilot perlu menetapkan nama penanggung jawab; tidak ada nama orang diasumsikan.

| Gate | Penanggung jawab yang ditetapkan nanti | Bukti minimum | Keputusan |
| --- | --- | --- | --- |
| G0 Kebijakan | Pemilik pilot/penanggung jawab data | K01–K10 disetujui, ruang lingkup/retensi dan informasi peserta jelas | Tanpa ini, jangan tetapkan migration identity final |
| G1 Integritas identity | Backend + reviewer independen | SEC-01–04, T01–12 lulus sesuai policy; history privat dan no takeover via NIM | Wajib sebelum approval peserta |
| G2 Privasi/resource scope | Backend/security + QA | SEC-05/06/09/10, T13–20 lulus dengan dua kelas/role/revoke/conflict | Wajib sebelum laporan nyata |
| G3 Berkas | Backend/operator + QA | SEC-07, T21–24 lulus; scanner/format policy dan quota muat Rp0 | Wajib sebelum menerima upload nyata |
| G4 Data lifecycle | Penanggung jawab data/operator | SEC-08/12, T25–26, jadwal retensi, restore dan insiden diuji | Wajib sebelum menyimpan data peserta |
| G5 Target | Operator hosting | SEC-11, T27–29; HTTPS/root/secret/storage/DB/version/cost diverifikasi | Belum bisa dipenuhi tanpa izin deployment/test target terpisah |
| G6 Regresi/review | QA + reviewer bukan pelaksana utama | Semua tes wajib pass, exception tercatat bukan diabaikan, source/artifact yang diuji sama dengan release | Tidak ada P0/P1 terbuka atau critical flaky test |
| G7 Buka pilot | Pemilik pilot | Semua gate lain, risiko tertunda/mitigasi/durasi disetujui, kontak insiden dan stop intake tersedia | Persetujuan eksplisit, bukan otomatis setelah build hijau |

Mekanisme penghentian jika ditemukan leak/akses salah/malware/storage tidak persisten: hentikan intake/tindakan berisiko, revoke akses/isolasi sesuai runbook, lindungi log bukti tanpa membuka data, informasikan penanggung jawab, lakukan investigasi dan recovery. **Tidak menghapus seluruh database/log untuk menyembunyikan insiden**, tidak restore snapshot secara diam-diam, dan tidak mengubah kuota berbayar tanpa persetujuan.

## 11. Hubungan Dengan Rencana Hosting Rp0

- Audit ini tidak mengubah pilihan provider. Oracle Always Free tetap kandidat kapasitas/kontrol, alwaysdata Free alternatif sangat kecil sesuai laporan readiness; semua syarat/quota/eligibility perlu diverifikasi ulang nanti, bukan dianggap resource sudah tersedia.
- Kebutuhan scanner, private storage, dua release, database, log dan backup ikut sizing. Jika total kontrol aman tidak muat gratis, scope/jumlah peserta/jenis data harus dibahas ulang, bukan memakai disk ephemeral, root publik, atau menghapus evidence.
- Database online baru harus terpisah dari schema test dan main/demo laptop. Production secrets hanya pada kanal aman setelah izin, tidak ada pada artifact/rencana ini.
- Phase deployment/hosting tetap pekerjaan dan persetujuan terpisah. Tidak ada login dashboard, kartu/OTP/MFA, DNS, resource cloud, atau perubahan .env yang dilakukan.

## 12. Hasil Audit Ini Dan Langkah Berikutnya

Source sekarang masih memperlihatkan blocker identitas, perbedaan policy metadata/notification, intake file/retensi yang belum lengkap, serta gate hosting/pilot yang belum terbukti. Kontrol lama yang kuat diakui, tetapi tidak dipakai untuk mengklaim semua kasus terbaru lulus.

**Langkah berikutnya yang direkomendasikan:** bahas dan setujui Paket 0 terlebih dahulu, lalu beri izin implementasi Paket 1 beserta regresinya pada lingkungan test terpisah. Paket lain mengikuti dependensi. Bila keputusan identitas belum disepakati, pekerjaan tetap berhenti pada desain; jangan mengubah uniqueness, overwrite canonical, atau menghapus riwayat.

**Konfirmasi batas:** satu-satunya file yang ditulis pada tugas ini adalah `docs/RENCANA-PERBAIKAN-KEAMANAN-PILOT.md`. Source aplikasi, konfigurasi dan file migration hanya dibaca, tidak diedit/dijalankan. `.env`, nilai credential/token dan isi KTM tidak dibaca. Tidak ada test, query database, build, upload, proses background baru, pembuatan resource/akun hosting, input kartu atau deployment yang dilakukan. Database utama/demo/test tidak disentuh oleh audit ini. File test dibaca secara statis; fixture bukan data pengguna dan tidak disalin sebagai credential. Tidak ada klaim sistem siap produksi atau pilot telah dibuka.
