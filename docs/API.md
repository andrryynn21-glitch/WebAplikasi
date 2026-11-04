# Kontrak Backend API v1

Kontrak ini diturunkan dari `routes/api.php`, controller, FormRequest, resource, policy, service, model, migration, dan konfigurasi aktual. Contoh adalah contoh sintetis sesuai bentuk serializer, bukan data mahasiswa nyata atau hasil request yang dijalankan dalam pekerjaan dokumentasi. Tidak ada klaim frontend selesai atau integrasi resmi API UNPAM.

## Konvensi

Base URL contoh: `http://127.0.0.1:8000/api/v1`. Semua path pada tabel di bawah relatif terhadap `/api/v1`. Gunakan `Accept: application/json`. Request biasa menggunakan `Content-Type: application/json`; upload menggunakan `multipart/form-data`.

Semua endpoint kecuali registrasi, login, dan katalog voice warning membutuhkan token Sanctum dan akun aktif:

```http
Authorization: Bearer <token-dari-login>
Accept: application/json
```

Token opaque adalah string rahasia. `device_name` default `web`; login dengan nama perangkat sama mencabut seluruh token lama bernama sama. Logout mencabut token yang dipakai request, logout-all semua token user. Tidak ada refresh token. TTL token adalah integer menit dari `SANCTUM_TOKEN_TTL_MINUTES` (template 480); jika konfigurasi tidak diisi, `expires_in_minutes` dapat `null` dan masa berlaku global Sanctum tidak dibatasi konfigurasi itu. Waktu kedaluwarsa bukan field timestamp di response auth.

Aturan tipe dalam dokumen: `int` adalah angka JSON integer, `bool` adalah boolean JSON, `string?` berarti string atau `null`, `T[]` array, `object?` object atau `null`. Timestamp merupakan string ISO-8601 dengan offset zona waktu atau `null`; jangan mengandalkan offset selalu `Z`. Field bertanda opsional dapat tidak hadir, bukan otomatis bernilai `null`. ID resource selain laporan berupa angka; gunakan `public_ref` laporan untuk integrasi. Binding `{report}` hanya menerima `public_ref`; ID numerik laporan bukan kontrak route.

### Envelope Dan Error

Sukses JSON selalu mempunyai `message: string` dan `data: object|array|null`; `meta` hanya hadir bila diperlukan. Resource tidak mempunyai envelope `data` kedua di dalam `data`.

```json
{"message":"Logout berhasil.","data":null}
```

Error mempunyai `message: string` dan `error.code: string`. `error.details` dapat tidak hadir, `null`, atau map nama field ke array pesan string. Error tidak selalu memiliki `data`. Frontend harus mengandalkan status HTTP/code dan membaca key opsional secara defensif.

```json
{
  "message": "Data yang dikirim tidak valid.",
  "error": {
    "code": "VALIDATION_FAILED",
    "details": {"description": ["Uraian pengaduan minimal 30 karakter agar dapat diproses."]}
  }
}
```

| HTTP | Code umum | Kondisi |
| --- | --- | --- |
| 401 | `UNAUTHENTICATED` | Token hilang, tidak sah, dicabut, atau kedaluwarsa. |
| 401 | `INVALID_CREDENTIALS` | Email/password login tidak cocok. |
| 403 | `ACCOUNT_DISABLED` | Akun nonaktif; token saat ini dapat dicabut middleware. |
| 403 | `PERMISSION_DENIED`, `FORBIDDEN` | Permission route/FormRequest/policy tidak terpenuhi. |
| 403 | `IDENTITY_NOT_VERIFIED` | Fitur kelas belum terbuka; `error.identity_status: string?` disertakan. |
| 403 | `MEMBERSHIP_NOT_ACTIVE` | Keanggotaan kelas aktif diperlukan. |
| 404 | `NOT_FOUND` | Binding/resource/file tidak ditemukan, pesan generik. Penolakan terhadap resource yang ada dapat tetap 403; tidak semua jalur menyamarkan keberadaannya. |
| 422 | `VALIDATION_FAILED` | Validasi field atau aturan bisnis service gagal. Key detail dapat berupa `file`, `verification`, `membership`, `class_id`, `appeal`, atau field lain, bukan hanya field FormRequest. |
| 429 | `RATE_LIMITED` | Throttle route; header `Retry-After` berupa detik. |
| 429 | `TOO_MANY_ATTEMPTS` | Limit percobaan login email+IP; `meta.retry_after: int` berupa detik. |
| 429 | `SECURITY_COOLDOWN` | Cooldown penolakan berulang pada endpoint privat; `meta.retry_after: int` dan header `Retry-After` dalam detik. Logout/logout-all dikecualikan. |
| 500 | `SERVER_ERROR` | Kegagalan internal/database/storage. Jangan memperlakukan sebagai validasi yang sukses. |
| Sesuai HTTP | `HTTP_ERROR` | HTTP exception lain, misalnya method tidak didukung (405) atau request terlalu besar (413); pesan generik. Penolakan web server sebelum Laravel dapat berbeda bentuk. |

Semua endpoint di bawah dapat menghasilkan error umum yang relevan di tabel ini. Kolom error endpoint mencantumkan penolakan khusus di luar error umum. File sukses merupakan binary stream, sedangkan file gagal tetap error JSON.

`meta.voice_warning` dapat hadir pada penolakan tertentu dan berbentuk `{play: bool, sound: string?, placeholder: bool, category: string?, cooldown_seconds: int}`. Middleware permission dapat menyertakan object ini walaupun `play=false`. Itu hanya instruksi presentasi; tidak pernah memberikan izin akses. Jangan mengarang URL audio dari `sound`.

### Pagination Dan Query

Hanya endpoint yang ditandai **P** dalam tabel memakai pagination. Query `page` adalah nomor halaman (default 1), `per_page` default 20 dan di-clamp menjadi 1..100, bukan ditolak 422 ketika melewati batas. Bentuk `data` adalah array langsung, tanpa `links` atau resource paginator standar Laravel:

```json
{
  "message": "Daftar pengaduan.",
  "data": [],
  "meta": {"pagination": {"current_page": 1, "per_page": 20, "total": 0, "last_page": 1}}
}
```

Semua nilai pagination adalah `int`. Query boolean memakai parsing boolean Laravel, misalnya `1` atau `true`; bukan string literal sembarang. Sebagian besar filter list tidak menggunakan FormRequest/enum validation: nilai status/category yang tidak cocok biasanya menghasilkan array kosong, bukan 422. Sorting hanya menerima allow-list yang dijelaskan; nilai lain kembali ke default. Endpoint tanpa keterangan filter/sort tidak mendukung filter/sort tersebut. Tidak ada full-text pencarian laporan privat.

## Scope Dan Role

| Istilah | Definisi aktual |
| --- | --- |
| Aktif | Terautentikasi dan `is_active=true`. Semua endpoint privat memakai middleware ini. |
| Fitur kelas mahasiswa | Akun aktif, `identity_status=verified`, dan keanggotaan berstatus `active`. Kode undangan tidak menggantikan kedua keputusan. |
| Ketua aktif | Role ketua, identitas terverifikasi, anggota aktif kelas target, dan penugasan ketua saat ini yang sudah mulai dan belum diakhiri. Role ketua yang tersisa setelah pencabutan bukan penugasan aktif. |
| Dosen kelas | Role dosen koordinator, akun aktif, dan penugasan dosen kelas yang sudah mulai serta belum diakhiri. Tidak otomatis menjadi mahasiswa kelas. |
| Baca privat laporan | Pemilik, atau pemegang `reports.view.private` dengan penugasan laporan aktif, scope kelas yang sesuai, dan bebas konflik/pihak terlapor. |
| Menangani laporan | Bukan pelapor; izin baca privat dan `reports.update_status`; penugasan aktif `primary`/`secondary`, scope kelas, serta bebas konflik. Penugasan `appeal_reviewer` saja tidak mengizinkan status biasa. |
| Moderasi | `aspirations.moderate` dan ketua aktif/dosen target; bukan penulis konten. |

Permission awal mengikuti `config/permissions.php`: mahasiswa membuat laporan/aspirasi, komentar/upvote/flag, menjawab klarifikasi dan banding; ketua menangani laporan yang ditugaskan, moderasi, undangan dan keputusan keanggotaan; dosen dapat menugaskan laporan, menangani/banding pada kelasnya, moderasi, undangan dan keputusan keanggotaan; verifikator hanya `identity.verify`; Super Admin mengelola akun/role/kelas/audit/security, **tidak** mempunyai izin baca isi pengaduan, mengubah hasil penanganan, atau melihat KTM secara otomatis. Permission aktual dikirim `User.permissions`, tetapi policy resource tetap sumber keputusan akhir.

Dosen yang ditugaskan dapat membaca/memoderasi aspirasi dan menambah update laporan tanpa keanggotaan mahasiswa melalui pengecualian middleware; policy penugasan/private tetap diperiksa pada update laporan. Dosen tidak mendapat izin membuat aspirasi, komentar, upvote, atau flag hanya karena pengecualian middleware. Pengaduan privat tidak pernah otomatis menjadi aspirasi. Ketua/dosen awal juga mempunyai `security.view` untuk kelas penugasan aktif; admin melihat kejadian teknis secara global.

## Autentikasi Dan Profil

| Method dan path | Auth/scope | Request/validasi | Sukses `data` | Error khusus |
| --- | --- | --- | --- | --- |
| `POST /auth/register` | Publik; role dipaksa `student`, identitas `unverified` | `name` wajib string max120; `email` wajib string email max255 unik users; `password` wajib string min8, huruf+angka, harus sama dengan `password_confirmation`; `nim` wajib string max32 pola `^[A-Za-z0-9._-]+$`; `phone` opsional nullable string max24; `device_name` opsional nullable string max100 | 201 `Auth` | Email duplikat/password tidak memenuhi aturan 422. |
| `POST /auth/login` | Publik | `email` wajib string email max255; `password` wajib string max255; `device_name` opsional nullable string max100 | 200 `Auth` | `INVALID_CREDENTIALS` 401, `ACCOUNT_DISABLED` 403, `TOO_MANY_ATTEMPTS` 429. |
| `POST /auth/password/forgot` | Publik | `email` wajib email max255 | 200 `null`, pesan selalu generik | Mailer log/tidak aman tidak membuat token; keberadaan akun tidak diungkap. |
| `POST /auth/password/reset` | Publik | `email`, `token`, `password` confirmed; password min12 mixed-case+angka+simbol | 200 `null`; seluruh token sesi dicabut | Token invalid/expired/reused: 422 generik. |
| `POST /auth/logout` | Aktif | Tidak ada body | 200 `null` | Error umum. |
| `POST /auth/logout-all` | Aktif | Tidak ada body | 200 `null` | Error umum. |
| `GET /auth/me` | Aktif, milik sendiri | Tidak ada query | 200 `User`; membership dimuat | Error umum. |
| `POST /auth/password/change` | Aktif, milik sendiri | `current_password`; `password` confirmed min12 mixed-case+angka+simbol | 200 `null`; sesi lain dicabut | Current salah/sama dengan password lama/mismatch: 422. |
| `PATCH /profile` | Aktif, milik sendiri | `name` opsional string max120; `phone` opsional nullable string max24 | 200 `User`; membership tidak dimuat | Perubahan nama ditolak 422 saat identitas pending/verified atau role ketua/dosen. Nama yang tidak berubah tetap diterima; telepon dapat diperbarui. Field email/password/NIM/role/status bukan field pembaruan. |

`Auth = {token: string, token_type: "Bearer", expires_in_minutes: int|null, user: User}`.

`User` mempunyai `id: int`, `name: string`, `email: string`, `nim: string?`, `phone: string?`, `role: string?`, `role_name: string?`, `identity_status: string?`, `identity_message: string?`, `is_active: bool`, `created_at: timestamp?`, dan `permissions: string[]` ketika role dimuat. `membership` opsional `{status: string?, class_id: int|null}`. Bila relasi dimuat tetapi tidak ada anggota aktif, object berisi kedua nilai `null`, bukan `membership: null`. Profil PATCH tidak memuat membership; jangan menganggap key selalu ada. Password, path KTM, alasan suspensi, dan token lama tidak terdapat pada `User`.

Registrasi tidak menerima KTM, kelas, kode undangan, maupun pilihan role: tahap tersebut terpisah. Field tak dikenal umumnya tidak diteruskan oleh `validated()`; jangan menganggap mengirim role ilegal selalu menghasilkan 422. Backend tetap tidak menerapkan role tersebut.

Contoh base URL dan login (password/token adalah variabel runtime akun pribadi, bukan kredensial demo; jangan memasukkannya ke history shell atau repository):

```sh
export API_BASE=http://127.0.0.1:8000/api/v1
```

```sh
curl "$API_BASE/auth/login" -X POST \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  --data "{\"email\":\"$ACCOUNT_EMAIL\",\"password\":\"$ACCOUNT_PASSWORD\",\"device_name\":\"integrasi-web\"}"
```

```json
{
  "message": "Login berhasil.",
  "data": {
    "token": "<token-opaque-hanya-untuk-klien>",
    "token_type": "Bearer",
    "expires_in_minutes": 480,
    "user": {
      "id": 17,
      "name": "Mahasiswa Contoh",
      "email": "mahasiswa@example.test",
      "nim": "2310000017",
      "phone": null,
      "role": "student",
      "role_name": "Mahasiswa",
      "identity_status": "unverified",
      "identity_message": "Unggah KTM dan ajukan verifikasi identitas untuk membuka fitur kelas.",
      "is_active": true,
      "membership": {"status": null, "class_id": null},
      "permissions": ["reports.create", "reports.view.own", "reports.respond_clarification", "reports.appeal_submit", "aspirations.create", "aspirations.participate", "aspirations.flag", "identity.submit"],
      "created_at": "2026-10-21T04:00:00+00:00"
    }
  }
}
```

Urutan permission array tidak dijamin kontrak. Saat registrasi, kirim payload misalnya `{name: "Mahasiswa Contoh", email: "mahasiswa@example.test", nim: "2310000017", password: <password-pribadi>, password_confirmation: <password-yang-sama>, device_name: "integrasi-web"}`. Placeholder password bukan nilai literal untuk dikirim. Gunakan JSON serializer untuk email/password berkarakter khusus; command curl ringkas di atas memerlukan escaping JSON yang benar pada nilai variabel.

## Identitas Dan KTM

Status user dapat `unverified`, `pending_verification`, `verified`, atau `rejected`. NIM regex hanya batas format input, bukan validasi identitas resmi kampus.

| Method dan path | Auth/scope | Request/validasi | Sukses `data` | Error khusus |
| --- | --- | --- | --- | --- |
| `GET /identity/status` | Aktif, milik sendiri | Tidak ada | 200 `{identity_status: string?, message: string?, latest_submission: SubmissionStatus\|null}` | Error umum. |
| `POST /identity/submissions` | Aktif + `identity.submit`, milik sendiri | Multipart `ktm` wajib file JPG/JPEG/PNG/PDF max4096 KB; `nim` wajib string max32 pola NIM; `full_name` wajib string max120 | 201 `{id: int, status: string, submitted_at: timestamp?}` | Pengajuan pending lain 422; tidak mempunyai permission 403. |
| `GET /identity-verifications/{verification}/ktm` | Aktif, pemilik atau `identity.verify` | ID pengajuan, tanpa body | 200 binary; nama unduh `ktm-{id}.{extension}` | Policy 403; berkas tidak tersedia 404. |
| `GET /identity-verifications` | Aktif + `identity.verify`, global verifikasi | **P**; filter `status` string, sort tetap `submitted_at desc` | 200 `VerificationItem[]` | Error umum. |
| `POST /identity-verifications/{verification}/approve` | Aktif + `identity.verify`, bukan pemilik | Tidak ada body | 200 `{id: int, status: "verified"}` | Sudah diputuskan 422. |
| `POST /identity-verifications/{verification}/reject` | Aktif + `identity.verify`, bukan pemilik | `reason` wajib string 10..500 | 200 `{id: int, status: "rejected"}` | Sudah diputuskan 422. |

`SubmissionStatus = {id: int, status: string?, submitted_at: timestamp?, reviewed_at: timestamp?, rejection_reason: string?}`. Alasan dikirim hanya pada status rejected.

`VerificationItem = {id: int, user: {id: int, name: string, nim: string?, email: string}|null, nim: string, full_name: string, status: string?, submitted_at: timestamp?, ktm_download_url: string}`. URL adalah path API privat; tidak ada `ktm_path`, checksum, atau URL public storage.

Validasi file juga memeriksa MIME terdeteksi server: `image/jpeg`, `image/png`, `application/pdf`. Ukuran efektif mengikuti config upload dan hard ceiling tipe file 4096 KB. Full name pengajuan merupakan field pengajuan, bukan otomatis perubahan `User.name`. Pengguna yang sudah verified dapat mengajukan kembali jika tidak ada pengajuan pending; submit memperbarui NIM profil dan mengembalikan status user menjadi pending_verification, sehingga fitur kelas kembali tertutup selama review.

```sh
curl "$API_BASE/identity/submissions" -X POST \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" \
  -F 'nim=2310000017' -F 'full_name=Mahasiswa Contoh' -F 'ktm=@/path/lokal/ktm.png'
```

```json
{
  "message": "Berkas verifikasi diterima dan menunggu pemeriksaan.",
  "data": {"id": 9, "status": "pending_verification", "submitted_at": "2026-10-21T04:10:00+00:00"}
}
```

## Kelas Dan Keanggotaan

| Method dan path | Auth/scope | Request/validasi | Sukses `data` | Error khusus |
| --- | --- | --- | --- | --- |
| `GET /classes` | Aktif; katalog kelas aktif, tanpa data anggota | Tanpa pagination/filter; `name asc` | 200 `ClassItem[]` | Error umum. |
| `GET /academic-terms` | Aktif; katalog periode, termasuk nonaktif | Tanpa pagination/filter; `starts_on desc` | 200 `{id: int, code: string, name: string, starts_on: string?, ends_on: string?, is_active: bool}[]`; tanggal `YYYY-MM-DD`, bukan timestamp | Error umum. |
| `GET /memberships` | Aktif, seluruh record milik sendiri | Tanpa pagination/filter; `created_at desc` | 200 `MembershipItem[]` | Error umum. |
| `POST /memberships/join` | Aktif, identitas verified; belum punya anggota aktif | `invitation_code` wajib string max64; tidak ada `class_id` | 202 `{id: int, status: "pending", class: ClassRef\|null}` | Kode invalid/kedaluwarsa/kuota/pencabutan, identitas belum verified, kelas tidak tersedia, anggota aktif lain, permintaan pending duplikat: 422. |
| `GET /classes/{class}/memberships/pending` | `memberships.review` + ketua aktif/dosen/admin kelas | **P**; status selalu pending; `created_at desc` | 200 `{id: int, user: IdentityUser\|null, status: "pending", created_at: timestamp?}[]` | Scope/policy 403. |
| `POST /memberships/{membership}/decide` | `memberships.review` + pengelola kelas target, bukan pemilik | `decision` wajib `approve`/`reject`; `note` nullable string max500, wajib jika reject | 200 `{id: int, status: "active"\|"rejected"}` | Keputusan non-pending, user nonaktif/belum verified, anggota aktif lain, alasan reject kosong: 422; self-review 403. |

`ClassRef = {id: int, code: string, name: string}`; `IdentityUser = {id: int, name: string, nim: string?, email: string}`.

`ClassItem = {id: int, code: string, name: string, program_study: string, semester: int, academic_term: ClassRef|null}`. Katalog `/classes` dapat dipakai memilih kelas sebelum aktivasi; katalog bukan izin membaca isi kelas.

`MembershipItem = {id: int, status: "pending"|"active"|"rejected"|"revoked", class: ClassRef|null, decision_note: string?, created_at: timestamp?}`. `decision_note` hanya ditampilkan saat rejected. Backend menggunakan kode dari `class_invitation_codes`, bukan `classes.join_code`. Pengajuan tetap pending walau kode valid. Identitas wajib terverifikasi **sebelum** join dalam implementasi ini.

Contoh body join dan respons:

```json
{"invitation_code":"KODEDARIADMIN"}
```

```json
{
  "message": "Permintaan bergabung dikirim. Menunggu persetujuan pengelola kelas.",
  "data": {"id": 21, "status": "pending", "class": {"id": 4, "code": "IF-23-A", "name": "Informatika 2023 Kelas A"}}
}
```

## Dashboard Dan Statistik

| Method dan path | Auth/scope | Query | Sukses |
| --- | --- | --- | --- |
| `GET /dashboard` | Aktif; bentuk menurut role backend, bukan query pilihan role | Tidak ada pagination/filter | 200 payload role di bawah. |
| `GET /reports/statistics` | Aktif + `reports.statistics`; kelas yang dapat diakses | `class_id` opsional integer; tanpa pagination/sort | 200 `ReportStatistics`. Kelas di luar akses menghasilkan ringkasan kosong (`class_ids: []`), bukan 403 dari service saat ini. |

`ReportStatistics = {class_ids: int[], total_reports: int, open_reports: int, resolved_reports: int, rejected_reports: int, by_status: map<string,int>, by_category: map<string,int>, average_resolution_hours: number|null, open_older_than_7_days: int}`. Map berisi key yang memang memiliki record; bila kosong serializer collection dapat menghasilkan `[]`, bukan `{}`. Open berarti bukan resolved/rejected, sehingga appeal_pending termasuk open. Rata-rata dihitung memakai `resolved_at-created_at` dalam menit lalu dibagi 60 dan dibulatkan satu desimal; bukan SLA resmi kampus. Angka kelompok dari driver DB tidak mempunyai cast eksplisit di service, sehingga klien defensif dapat menormalisasi nilai count jika driver menghasilkan string numerik.

`ReportCard = {public_ref: string, status: string?, category_id: int, routing_mode: string?, last_activity_at: timestamp?, created_at: timestamp?}`. Tidak ada judul, deskripsi, atau identitas.

`AspirationCard = {id: int, title: string, supports_count: int, comments_count: int, last_activity_at: timestamp?}`.

| Role dashboard | Seluruh field `data` |
| --- | --- |
| `student` | `role`; `identity: {status: string, message: string}`; `membership: {status: string, class: ClassRef\|null}\|null` (record terbaru, bukan selalu aktif); `reports: {total: int, open: int, resolved: int, needs_clarification: int}` milik sendiri; `recent_reports: ReportCard[]` maksimal5, aktivitas terbaru; `aspirations: AspirationCard[]` maksimal5, hanya jika fitur kelas tersedia. |
| `class_leader` | `role`; `classes: ClassRef[]`; `reports: {assigned_open: int, waiting_clarification: int, resolved_by_me: int}` berdasarkan penugasan aktif; `pending_memberships: int`; `open_flags: int` untuk flag aspirasi (bukan flag komentar); `aspirations: AspirationCard[]` maksimal5; `statistics: ReportStatistics`. `resolved_by_me` menghitung laporan resolved dengan penugasan aktif, bukan bukti aktor pemutus histori. |
| `lecturer_coordinator` | `role`; `classes: ClassRef[]`; `reports: {needs_review: int, under_review: int, waiting_follow_up: int, resolution_proposed: int}`; `appeals: {pending: int}` termasuk pending/under_review; `needs_attention: ReportCard[]` maksimal10 (needs_review/waiting_follow_up/resolution_proposed), aktivitas terbaru; `statistics: ReportStatistics`. |
| `super_admin` | `role`; `system: {active_users: int, inactive_users: int, classes: int, pending_identity_verifications: int}`; `security: {events_last_24h: int, high_severity_last_7d: int}`; `note: string`. Tidak ada daftar atau isi pengaduan. |
| `identity_verifier` | `role`; `identity_verifications: {pending: int}`. |

Semua `role` pada tabel berupa string literal role tersebut. Tidak tersedia endpoint statistik aspirasi terpisah hanya karena service internal memiliki `aspirationSummary()`.

## Pengaduan Privat

### Endpoint

| Method dan path | Auth/scope | Request/query | Sukses `data` | Error khusus |
| --- | --- | --- | --- | --- |
| `GET /report-categories` | Aktif | Tanpa pagination/filter; `name asc` | 200 `{id: int, code: string?, name: string, sensitive: bool}[]` | Error umum. |
| `GET /reports` | Aktif; milik sendiri atau metadata kelas yang diizinkan | **P**; `scope` default `mine`; `class_id` integer opsional; `status` string; `category_id` integer; `sort=last_activity_at\|created_at\|status` default aktivitas; `direction=asc` atau default desc | 200 `ReportListItem[]` | `CLASS_ACCESS_DENIED` 403 untuk filter kelas di luar scope. |
| `POST /reports` | Fitur kelas + `reports.create`; kelas dari anggota aktif | Field `CreateReport` di bawah; JSON tanpa file, multipart bila lampiran | 201 `ReportListItem` dengan category/class | Validasi dan routing service 422; upload/storage 500 jika gagal. |
| `GET /reports/{report}` | Policy view; privat hanya setelah policy baca privat | Referensi laporan; tanpa filter/pagination | 200 `ReportSummary` atau `ReportDetail` | `REPORT_ACCESS_DENIED` 403. |
| `PATCH /reports/{report}/status` | Penangan berwenang | `status` wajib enum status laporan; `reason` nullable string max1000; `resolution_summary` nullable string max5000; `internal` nullable bool diterima validasi tetapi tidak diteruskan controller | 200 `ReportListItem` dengan category; class biasanya tidak dimuat | `STATUS_CHANGE_DENIED` 403; transisi ilegal/alasan/ringkasan/konflik/status banding: 422. |
| `POST /reports/{report}/updates` | Fitur kelas atau dosen ditugaskan; policy addUpdate (pemilik/penangan privat) | `body` wajib string 3..5000; `internal` nullable bool default false; `reply_to_id` nullable int exists report_updates; `type` nullable `update`/`clarification_response` default update | 201 `{id: int, type: string, body: string, is_internal: bool, created_at: timestamp?}` | `UPDATE_DENIED` 403; catatan internal pelapor, reply salah laporan/internal tidak terlihat, tidak ada klarifikasi terbuka: 422/policy403. |
| `POST /reports/{report}/clarification-requests` | Penangan berwenang + `reports.request_clarification`, status under_review/needs_clarification | `body` wajib string 5..2000 | 201 `{id: int, created_at: timestamp?}` | `CLARIFICATION_DENIED` 403 untuk status/kewenangan tidak sesuai; service 422. |
| `POST /reports/{report}/assignments` | `reports.assign` + dosen kelas aktif, bukan pelapor/pihak berkonflik | `assignee_id` wajib int exists users; `assignment_type` nullable enum default primary; `reason` wajib string 10..500 | 201 `{id: int, assignee: {id: int, name: string}, type: string, assigned_at: timestamp?}` | `ASSIGNMENT_DENIED` 403; target tidak layak/primary duplikat/appeal_reviewer via endpoint ini/banding masih terbuka: 422. |
| `POST /reports/{report}/forward` | Policy assign yang sama | Field sama seperti assign; `assignment_type` diabaikan, selalu primary | 200 `{id: int, assignee: {id: int, name: string}}` | `FORWARD_DENIED` 403; status tidak mengizinkan forwarded/target invalid: 422. |

Untuk list: pengguna tanpa `reports.view.class`/`reports.view.assigned` dipaksa scope milik sendiri meskipun mengirim `scope` lain (termasuk mahasiswa, admin, dan verifikator awal). Pemilik permission tersebut memakai metadata kelas ketika `scope` bukan `mine`; bukan hanya string `class` yang diterima. Perubahan scope tidak membuka isi privat. Filter/sorting tidak mencari teks judul/description dan tidak ada endpoint daftar publik pengaduan. Policy tetap memeriksa detail satu per satu.

Scope pengelolaan daftar, dashboard, dan statistik memerlukan penugasan ketua/dosen aktif, bukan hanya role yang masih tersimpan atau keanggotaan mahasiswa. Ketua yang dicabut masih dapat membaca laporan miliknya sebagai pelapor, tetapi tidak memperoleh daftar/agregasi kelas. Penugasan yang belum mencapai `starts_at` tidak aktif untuk routing.

`CreateReport`:

| Field | Wajib | Tipe/validasi |
| --- | --- | --- |
| `category_id` | Ya | int, exists `report_categories.id`; ambil ID dari katalog, jangan hard-code urutan seeder. |
| `title` | Ya | string 5..200; judul juga privat ketika tidak ada izin baca. |
| `description` | Ya | string 30..20000. |
| `desired_outcome` | Tidak | nullable string max5000. |
| `involved_parties` | Tidak | nullable string[] max20; setiap string max200. Ini input manusia untuk deteksi konflik, bukan user ID resmi. |
| `academic_term_id` | Tidak | nullable int exists academic_terms; jika diberikan harus sama dengan periode kelas pelapor, mismatch422; jika tidak diberikan/null backend memakai periode kelas. |
| `attachments` | Tidak | nullable array max5 (config); setiap file JPG/JPEG/PNG/PDF/TXT max8192 KB (config + ceiling tipe); MIME image/jpeg, image/png, application/pdf, text/plain. |

Tidak ada input valid `class_id`, `reporter_id`, `status`, `sensitivity`, `routing_mode`, `current_handler_id`, `public_ref`, `resolution_summary`, `reporter_notes`, atau URL lampiran pada pembuatan. Backend menentukan kelas/pemilik/status/routing. Tidak ada upload tambahan melalui endpoint updates, edit/delete laporan, atau endpoint membaca privateDetails secara terpisah.

Pembuatan mula-mula mencatat submitted; routing langsung dapat menghasilkan `under_review` + `auto` untuk kasus rutin ke ketua aktif yang layak, atau `needs_review` + `manual` untuk sensitif/konflik/tidak ada penangan otomatis. Karena itu response POST bukan selalu submitted. Kategori awal: academic, lecturer_conduct, class_disruption, facilities, grouping, interpersonal, suggestion; lecturer_conduct/interpersonal sensitif.

### Bentuk JSON Laporan

`ReportListItem`:

| Field | Tipe/catatan |
| --- | --- |
| `public_ref` | string |
| `title` | string?; null jika peminta hanya berhak metadata. |
| `status`, `status_label`, `sensitivity`, `routing_mode` | string?; enum/label. |
| `category` | opsional `{id: int, code: string?, name: string}`; dimuat di list/create/status. |
| `class` | opsional `ClassRef`; dimuat di list/create, tidak selalu pada status. |
| `is_mine` | bool |
| `has_open_appeal` | bool opsional, hanya jika relasi openAppeal dimuat. Jangan menganggap key selalu dikirim list. |
| `last_activity_at`, `created_at`, `resolved_at` | timestamp? |

`ReportSummary` mempunyai `public_ref`, `title: null`, `status`, `status_label`, `sensitivity`, `routing_mode`, `category: object|null`, `class: ClassRef|null`, `private_details: null`, `can_read_private: false`, `created_at`, `last_activity_at`. **Tidak** memiliki `is_mine`, `reporter`, timeline, updates, assignments, attachments, resolved_at, atau open_appeal.

`ReportDetail` mempunyai `public_ref: string`, `title: string`, `status`, `status_label`, `sensitivity`, `routing_mode` string?, `category: {id: int|null, code: string?, name: string?}`, `class: {id: int|null, code: string?, name: string?}`, `private_details: {description: string?, involved_parties: string[]|null, desired_outcome: string?}`, `resolution_summary: string?`, `rejection_reason: string?`, `reporter: {id: int|null, name: string?, nim: string?}|null`, `current_handler: {id: int, name: string}|null`, `timeline: TimelineItem[]`, `updates: UpdateItem[]`, `assignments: AssignmentItem[]`, `attachments: AttachmentItem[]`, `open_appeal: {id: int, status: string?, created_at: timestamp?}|null`, `created_at`, `last_activity_at`. Detail tidak mempunyai `can_read_private=true`, `is_mine`, `id`, `academic_term_id`, atau `resolved_at`.

`reporter` null ketika peminta adalah pelapor sendiri; identitas terbatas dikirim pada pembaca privat lain yang berwenang. Timeline maksimal100 terbaru (`created_at desc`); updates maksimal100 terbaru, catatan internal tidak dikirim kepada pelapor. Assignment semua record, tanpa sort/pagination eksplisit. Lampiran semua record, tanpa sort/pagination eksplisit.

| Nested schema | Seluruh field |
| --- | --- |
| `TimelineItem` | `id: int`, `from_status: string?`, `to_status: string?`, `to_status_label: string?`, `actor: {id: int, name: string}\|null`, `reason: string?`, `source: string` (api/system/routing), `created_at: timestamp?`. |
| `UpdateItem` | `id: int`, `type: string?`, `body: string`, `is_internal: bool`, `author: {id: int, name: string}\|null`, `reply_to_id: int\|null`, `created_at: timestamp?`. |
| `AssignmentItem` | `id: int`, `assignee: {id: int, name: string}\|null`, `assigned_by: {id: int, name: string}\|null`, `type: string?`, `reason: string?`, `assigned_at: timestamp?`, `revoked_at: timestamp?`. |
| `AttachmentItem` | `id: int`, `original_name: string`, `mime: string`, `size: int` dalam byte, `download_url: string` path API privat. Tidak ada path/checksum. |

Contoh create tanpa file (`category_id` dan periode gunakan data aktual):

```json
{
  "category_id": 4,
  "title": "Proyektor kelas tidak dapat digunakan",
  "description": "Proyektor pada ruang kelas tidak menampilkan gambar selama dua pertemuan terakhir.",
  "desired_outcome": "Pemeriksaan dan perbaikan proyektor sebelum pertemuan berikutnya.",
  "involved_parties": []
}
```

```json
{
  "message": "Pengaduan terkirim.",
  "data": {
    "public_ref": "PGD-A1B2C3D4E5",
    "title": "Proyektor kelas tidak dapat digunakan",
    "status": "under_review",
    "status_label": "Sedang ditinjau",
    "sensitivity": "routine",
    "routing_mode": "auto",
    "category": {"id": 4, "code": "facilities", "name": "Fasilitas kelas"},
    "class": {"id": 4, "code": "IF-23-A", "name": "Informatika 2023 Kelas A"},
    "is_mine": true,
    "last_activity_at": "2026-10-21T04:20:00+00:00",
    "created_at": "2026-10-21T04:20:00+00:00",
    "resolved_at": null
  }
}
```

Contoh summary dari pemegang akses metadata saja:

```json
{
  "message": "Detail pengaduan (tanpa isi privat).",
  "data": {
    "public_ref": "PGD-A1B2C3D4E5",
    "title": null,
    "status": "needs_review",
    "status_label": "Perlu peninjauan",
    "sensitivity": "sensitive",
    "routing_mode": "manual",
    "category": {"id": 6, "code": "interpersonal", "name": "Masalah interpersonal"},
    "class": {"id": 4, "code": "IF-23-A", "name": "Informatika 2023 Kelas A"},
    "private_details": null,
    "can_read_private": false,
    "created_at": "2026-10-21T04:20:00+00:00",
    "last_activity_at": "2026-10-21T04:20:00+00:00"
  }
}
```

Contoh GET detail sebagai pelapor pada laporan rutin yang telah diarahkan ke penangan:

```json
{
  "message": "Detail pengaduan.",
  "data": {
    "public_ref": "PGD-A1B2C3D4E5",
    "title": "Proyektor kelas tidak dapat digunakan",
    "status": "under_review",
    "status_label": "Sedang ditinjau",
    "sensitivity": "routine",
    "routing_mode": "auto",
    "category": {"id": 4, "code": "facilities", "name": "Fasilitas kelas"},
    "class": {"id": 4, "code": "IF-23-A", "name": "Informatika 2023 Kelas A"},
    "private_details": {
      "description": "Proyektor pada ruang kelas tidak menampilkan gambar selama dua pertemuan terakhir.",
      "involved_parties": [],
      "desired_outcome": "Pemeriksaan dan perbaikan proyektor sebelum pertemuan berikutnya."
    },
    "resolution_summary": null,
    "rejection_reason": null,
    "reporter": null,
    "current_handler": {"id": 20, "name": "Ketua Contoh"},
    "timeline": [
      {"id": 32, "from_status": "submitted", "to_status": "under_review", "to_status_label": "Sedang ditinjau", "actor": null, "reason": "Dirutekan otomatis ke ketua kelas.", "source": "routing", "created_at": "2026-10-21T04:20:01+00:00"},
      {"id": 31, "from_status": null, "to_status": "submitted", "to_status_label": "Dikirim", "actor": {"id": 17, "name": "Mahasiswa Contoh"}, "reason": "Pengaduan dikirim.", "source": "api", "created_at": "2026-10-21T04:20:00+00:00"}
    ],
    "updates": [],
    "assignments": [
      {"id": 18, "assignee": {"id": 20, "name": "Ketua Contoh"}, "assigned_by": null, "type": "primary", "reason": "Routing otomatis kategori rutin.", "assigned_at": "2026-10-21T04:20:01+00:00", "revoked_at": null}
    ],
    "attachments": [],
    "open_appeal": null,
    "created_at": "2026-10-21T04:20:00+00:00",
    "last_activity_at": "2026-10-21T04:20:00+00:00"
  }
}
```

Peminta penangan berwenang menerima object reporter terbatas, bukan null seperti contoh pemilik. File response hanya berisi download_url jika ada attachment, tidak pernah path private.

### Workflow

Enum status: submitted, needs_review, under_review, needs_clarification, in_progress, waiting_follow_up, resolution_proposed, forwarded, rejected, resolved, appeal_pending, reopened.

Transisi pada state machine (izin penangan tetap wajib; forwarded harus memakai aksi forward, appeal_pending harus memakai alur banding):

| Dari | Tujuan |
| --- | --- |
| submitted | needs_review, under_review, rejected, forwarded |
| needs_review | under_review, forwarded, rejected |
| under_review | in_progress, needs_clarification, forwarded, rejected, resolution_proposed |
| needs_clarification | under_review, in_progress, rejected |
| in_progress | waiting_follow_up, forwarded, resolution_proposed, rejected |
| waiting_follow_up | in_progress, resolution_proposed, rejected |
| resolution_proposed | resolved, reopened |
| forwarded | under_review, in_progress, rejected |
| rejected / resolved | reopened; appeal_pending hanya melalui submit banding |
| reopened | under_review, in_progress, rejected, resolution_proposed |
| appeal_pending | Tidak melalui status endpoint; keputusan banding accepted menuju reopened, rejected mengembalikan status rejected/resolved yang tersimpan saat pengajuan. |

Status sama bukan no-op, melainkan transisi tidak diizinkan. rejected wajib alasan tidak kosong setelah trim; resolved wajib ringkasan tidak kosong setelah trim. Ringkasan hasil sampai5000 karakter tersimpan lengkap pada laporan, tetapi salinan reason timeline dibatasi1000 karakter. Catatan keputusan banding lengkap berada di banding; timeline memakai pesan keputusan generik. Penangan tidak boleh pelapor, pihak terlapor, atau konflik declared/confirmed. Dosen harus menugaskan dirinya melalui assignment sebelum menangani laporan manual; privilege koordinator tidak langsung membuka detail privat. Secondary dapat menangani; appeal_reviewer hanya akses banding/privat.

`PATCH /reports/{report}/status` menolak tujuan forwarded dengan422 meskipun ada di tabel transisi; gunakan `POST /reports/{report}/forward` agar penugasan dan history tetap konsisten. Tujuan/asal appeal_pending juga ditolak oleh endpoint status normal.

Clarification-request menulis update `clarification_request`; ketika status under_review backend juga berpindah needs_clarification. Respons klarifikasi memakai updates `type=clarification_response`; `reply_to_id` bila tidak diberikan dipilih dari permintaan terbuka terbaru. Pelapor harus pada status needs_clarification dan memiliki permission; menjawab tidak otomatis memindahkan status laporan ke under_review. Internal note memakai `internal=true` pada update biasa, bukan menerima enum `internal_note` dari endpoint ini.

Hanya `clarification_response` yang menutup permintaan klarifikasi. Balasan `update` biasa tidak membuat permintaan tersebut dianggap terjawab. Permintaan klarifikasi pada status lain ditolak, bukan ditambahkan sebagai pertanyaan yang tidak dapat dijawab.

```json
{"status":"rejected","reason":"Data yang diberikan belum cukup untuk memastikan kejadian yang dilaporkan."}
```

```json
{"status":"resolved","resolution_summary":"Proyektor diperbaiki dan fungsi tampilan telah diperiksa oleh pengelola fasilitas."}
```

Enum assignment di model: primary, secondary, appeal_reviewer. FormRequest mengenal semua enum, tetapi service menolak appeal_reviewer di endpoint penugasan biasa. Target wajib aktif, memiliki kewenangan status, akses kelas, bukan pelapor/pihak berkonflik. Ketua hanya menerima laporan rutin pada kelas penugasan aktifnya, bukan sensitif. Forward mencabut/mengganti primary, mencatat history, dan memindahkan status forwarded hanya dari state yang memungkinkan. Tidak ada endpoint mencabut assignment manual.

## Banding

| Method dan path | Auth/scope | Request | Sukses `data` | Error khusus |
| --- | --- | --- | --- | --- |
| `POST /reports/{report}/appeals` | Pemilik dengan fitur kelas dan `reports.appeal_submit`; laporan rejected/resolved | `reason` wajib string 20..5000 | 201 `{id: int, status: "pending"}` | `APPEAL_DENIED` 403; banding terbuka lain/aturan state 422. |
| `GET /appeals/{appeal}` | Pemilik laporan atau reviewer yang telah ditetapkan, masih berwenang sebagai dosen kelas dan bebas konflik | Tidak ada | 200 `AppealDetail` | `APPEAL_ACCESS_DENIED` 403. |
| `GET /appeals/{appeal}/eligible-reviewers` | `reports.appeal_review`, scope kelas, bukan pelapor/mantan penangan/pemutus/konflik | Tanpa query, pagination, atau sort | 200 `{id: int, name: string}[]` | `APPEAL_REVIEW_DENIED` 403. |
| `POST /appeals/{appeal}/reviewer` | Policy reviewAppeal; calon harus layak pada kelas laporan | `reviewer_id` wajib int exists users | 200 `{id: int, reviewer: {id: int, name: string}, status: "under_review"}` | `APPEAL_REVIEW_DENIED` 403; kandidat/status/reviewer lain 422. |
| `POST /appeals/{appeal}/decision` | Policy reviewAppeal dan reviewer yang sudah ditetapkan | `status` wajib accepted/rejected; `decision_note` wajib string 10..5000 | 200 `{id: int, status: "accepted"\|"rejected"}` | `APPEAL_DECISION_DENIED` 403; belum ditetapkan/sudah diputuskan/tidak layak 422. |

`AppealDetail = {id: int, status: string?, reason: string?, reviewer: {id: int, name: string}|null, decision_note: string?, decided_at: timestamp?, created_at: timestamp?}`. Tidak ada `report_id`, reporter, atau seluruh isi pengaduan di response banding. Tidak ada `GET /appeals` list; simpan ID dari submit atau baca open_appeal di detail laporan.

Reviewer wajib aktif, memiliki `reports.appeal_review`, dosen yang ditugaskan pada kelas laporan, bukan pelapor, bukan mantan primary/secondary, bukan pemutus rejected/resolved, bukan pemutus banding sebelumnya, serta tidak berkonflik/disebut sebagai pihak terlapor. Daftar eligible dapat kosong; backend tidak otomatis mengangkat Super Admin untuk memutuskan. Penetapan reviewer membuat assignment `appeal_reviewer` agar ia dapat membaca detail privat; assignment itu dicabut ketika keputusan dicatat. Permission reviewAppeal tidak sama dengan assignment read-private, sehingga petugas jangan membaca detail sebelum ditetapkan.

Contoh keputusan:

```json
{"status":"accepted","decision_note":"Bukti tambahan memerlukan pemeriksaan ulang oleh penangan berbeda yang tidak berkonflik."}
```

```json
{"message":"Keputusan banding tercatat.","data":{"id":12,"status":"accepted"}}
```

## Aspirasi Dan Komentar

| Method dan path | Auth/scope | Request/query | Sukses `data` | Error khusus |
| --- | --- | --- | --- | --- |
| `GET /aspirations` | Fitur kelas mahasiswa, atau dosen yang ditugaskan | **P**; `class_id` opsional int min1; default kelas anggota bagi mahasiswa/ketua atau seluruh kelas penugasan bagi dosen; `include_hidden` boolean untuk moderator aktif; `sort=last_activity_at\|supports_count\|comments_count\|created_at`, default aktivitas; pinned dahulu, lalu desc, ID desc sebagai tiebreak | 200 `AspirationItem[]` | `CLASS_ACCESS_DENIED` 403 untuk kelas di luar akses, class_id invalid422; identitas/keanggotaan403. |
| `POST /aspirations` | Fitur kelas + `aspirations.create`; kelas anggota aktif | `title` wajib string 5..200; `body` wajib string 10..10000 | 201 `AspirationItem` | Error umum. |
| `GET /aspirations/{aspiration}` | Kelas sama atau dosen ditugaskan; hidden/removed hanya penulis/moderator aktif | Tidak ada | 200 `AspirationItem` ditambah `supported_by_me: bool` | Policy 403. |
| `GET /aspirations/{aspiration}/comments` | Policy view aspirasi | Tanpa pagination/filter; published saja, `created_at asc`, maksimal200 | 200 `CommentItem[]` | Policy 403. |
| `POST /aspirations/{aspiration}/comments` | Fitur kelas + `aspirations.participate`; kelas sama; published | `body` wajib string 1..3000; `parent_id` nullable int exists aspiration_comments, parent terlihat pada aspirasi yang sama | 201 `{id: int, body: string, created_at: timestamp?}` | Locked/tidak berwenang 403; parent salah/tidak terlihat 422. |
| `POST /aspirations/{aspiration}/support` | Fitur kelas + `aspirations.participate`; kelas sama, published/locked | Tanpa body | 200 `{supported: true, created: bool, supports_count: int}` | Policy 403. Upvote duplikat sukses idempotent `created=false`. |
| `DELETE /aspirations/{aspiration}/support` | Policy support yang sama | Tanpa body | 200 `{supported: false, removed: bool, supports_count: int}` | Policy 403; hidden/removed tidak tersedia untuk aksi ini. |
| `POST /aspirations/{aspiration}/flag` | Fitur kelas + `aspirations.flag`; kelas sama; konten terlihat, bukan milik sendiri | `reason` wajib spam/harassment/offensive/private_information/other; `note` nullable string max500 | 201 `{id: int}`; flag duplikat mengembalikan ID yang sama, tidak memperbarui reason/note atau membuka flag kembali | Policy403, validasi422. |
| `POST /aspirations/{aspiration}/moderate` | Moderator aktif kelas, bukan penulis | `action` wajib hide/restore/remove/lock/dismiss_flag/resolve_flag; `reason` wajib string 5..500; `flag_id` nullable int exists content_flags, harus milik aspirasi yang sama dan masih open, wajib untuk dismiss_flag/resolve_flag | 200 `{id: int, action: string, resulting_status: string?}` | Policy403; flag terkait resource lain/sudah diputuskan/aksi tanpa flag wajib422. |
| `GET /aspirations/{aspiration}/flags` | `aspirations.moderate` + ketua aktif/dosen kelas; dapat melihat daftar pada aspirasi sendiri tetapi tidak memoderasi konten sendiri | **P**; `status` opsional enum open/resolved/dismissed (divalidasi); `created_at desc`, ID desc | 200 `ContentFlagItem[]`, hanya aspirasi induk dan komentarnya | Policy403; status filter invalid422. |
| `POST /aspirations/{aspiration}/comments/{comment}/flag` | Fitur kelas + aspirations.flag; komentar published, aspirasi published/locked, kelas sama; bukan penulis komentar | `reason` wajib spam/harassment/offensive/private_information/other; `note` nullable string max500 | 201 `{id: int}`; duplikat idempotent dengan ID sama | Komentar bukan anak aspirasi404; policy403; validasi422. |
| `POST /aspirations/{aspiration}/comments/{comment}/moderate` | `aspirations.moderate` + ketua aktif/dosen kelas; bukan penulis komentar | `action`, `reason`, `flag_id` sama seperti moderasi aspirasi; lock ditolak untuk komentar | 200 `{id: int, action: string, resulting_status: string?}`; status komentar published/hidden/removed atau null | Komentar bukan anak aspirasi404; policy403; flag/tindakan invalid422. |

`AspirationItem = {id: int, title: string?, body: string?, status: "published"|"locked"|"hidden"|"removed"|null, is_pinned: bool, supports_count: int, comments_count: int, author: {id: int, name: string}|null, is_mine: bool, last_activity_at: timestamp?, created_at: timestamp?}`. Tidak ada `class_id`, file, kategori, atau referensi laporan privat. Key `supported_by_me` hanya pada detail, bukan list/create. Published/locked terlihat; hidden/removed tidak muncul list biasa. Isi/judul hidden/removed tersedia untuk penulis atau moderator aktif yang berwenang, null bila hanya memperoleh metadata tanpa kewenangan itu. Locked menutup komentar, bukan otomatis menutup upvote.

`CommentItem = {id: int, parent_id: int|null, body: string, author: {id: int, name: string}|null, created_at: timestamp?}`. Response create komentar lebih kecil daripada list, tanpa parent_id/author. Array komentar datar dengan parent_id, bukan tree rekursif. Tidak ada endpoint edit/delete komentar oleh penulis; penghapusan tampilan melalui moderasi remove, bukan DELETE record.

`ContentFlagItem = {id: int, content_type: "aspiration"|"comment", content_id: int, content_status: string?, body: string, can_moderate: bool, reason: string, note: string?, status: "open"|"resolved"|"dismissed"|null, reviewed_at: timestamp?, created_at: timestamp?}`. Tidak ada identitas pelapor flag. Body adalah konten yang dilaporkan, sehingga endpoint ini hanya untuk moderator kelas. `can_moderate=false` pada konten milik moderator sendiri; hak melihat flags bukan hak memoderasi sendiri. Tidak ada endpoint GET satu moderation-action walaupun ID action dikirim notifikasi.

Sanitasi title/body aspirasi dan body komentar memakai `trim(strip_tags(...))`; tidak mendukung rich HTML. Backend memvalidasi ulang teks yang akan disimpan dengan batas panjang yang sama; teks kosong/tag-only atau terlalu pendek setelah sanitasi ditolak 422. Counter comments_count diperbarui saat komentar dibuat dan dihitung ulang untuk komentar visible dalam transaksi ketika hide/remove/restore; jangan menghitung total semua record termasuk hidden sebagai komentar tampil. Komentar induk diperiksa ulang sesudah lock aspirasi; pembatalan dukungan juga mengambil lock aspirasi dan memeriksa ulang policy. `dismiss_flag`/`resolve_flag` tidak mengubah status konten; `resulting_status` dapat null. hide/restore/remove/lock mengubah status konten. Tidak ada endpoint pin/unpin meskipun `is_pinned` ada.

```json
{"title":"Tambahkan jadwal pemeliharaan kelas","body":"Kami mengusulkan jadwal pemeliharaan rutin agar fasilitas kelas tetap dapat digunakan."}
```

```json
{
  "message": "Aspirasi dibuat.",
  "data": {
    "id": 8,
    "title": "Tambahkan jadwal pemeliharaan kelas",
    "body": "Kami mengusulkan jadwal pemeliharaan rutin agar fasilitas kelas tetap dapat digunakan.",
    "status": "published",
    "is_pinned": false,
    "supports_count": 0,
    "comments_count": 0,
    "author": {"id": 17, "name": "Mahasiswa Contoh"},
    "is_mine": true,
    "last_activity_at": "2026-10-21T04:30:00+00:00",
    "created_at": "2026-10-21T04:30:00+00:00"
  }
}
```

```json
{"message":"Anda sudah mendukung aspirasi ini.","data":{"supported":true,"created":false,"supports_count":3}}
```

## File Privat

| Method dan path | Scope | Response |
| --- | --- | --- |
| `GET /attachments/{attachment}` | Aktif + policy download target. File pengaduan membutuhkan hak baca privat laporan; status uploader bukan bypass scope. | 200 binary dengan MIME server, Content-Disposition attachment, filename asli, `Cache-Control: private, no-store, max-age=0`, `X-Content-Type-Options: nosniff`; `ATTACHMENT_ACCESS_DENIED` 403 atau NOT_FOUND404. |

Tidak ada upload/delete/download-public endpoint attachment mandiri. Lampiran laporan dikirim saat create. Keberadaan tipe `aspiration_image` pada enum bukan bukti endpoint upload image aspirasi tersedia. KTM memakai endpoint identitas, bukan `/attachments`.

## Notifikasi Dan Security

| Method dan path | Auth/scope | Request/query | Sukses `data` |
| --- | --- | --- | --- |
| `GET /notifications` | Aktif, milik sendiri dan masih terlihat pada scope saat ini | **P**; `unread_only` boolean; `created_at desc` | 200 `NotificationItem[]`; `meta.unread_count: int` menghitung semua notifikasi belum dibaca yang terlihat, bukan hanya halaman. |
| `POST /notifications/{notification}/read` | Pemilik dan policy visibility saat ini | Tanpa body | 200 null; idempotent terhadap read_at. |
| `POST /notifications/read-all` | Aktif, notifikasi sendiri yang masih terlihat | Tanpa body | 200 `{updated: int}`. |
| `GET /security-events` | Aktif + `security.view`; Super Admin global, non-admin dibatasi kelas penugasan ketua/dosen aktual | **P**; `category` string, `severity` string, `user_id` int; `created_at desc` | 200 `SecurityEventItem[]`. Tidak ada raw context/user_agent. |
| `GET /security/voice-warnings` | Publik | Tanpa request/query | 200 `VoiceCatalog`. Tidak menyajikan file audio. |

Policy yang tidak terpenuhi menghasilkan FORBIDDEN403; binding notification yang tidak ada NOT_FOUND404. Notifikasi bukan hak membaca resource. Data notifikasi reference-only dapat berupa object atau `[]` jika kosong; key yang ada bergantung event. Keputusan keanggotaan tetap dapat dibaca penerimanya walaupun belum mempunyai scope kelas aktif. Notifikasi kelas lain tidak otomatis terbaca setelah akses kelas dicabut.

`NotificationItem = {id: int, type: string?, title: string, message: string?, data: object|array|null, severity: string, action_url: string?, read_at: timestamp?, created_at: timestamp?}`. Type enum: report_status_changed, clarification_requested, report_assigned, appeal_submitted, appeal_decided, aspiration_comment, content_moderated, identity_verification, membership_decision, security_notice. Severity adalah string presentasi (misalnya info/warning/high/critical), tidak selalu enum yang sama dengan SecuritySeverity. Allow-list key `data` di service adalah report_ref/status/class_id/membership_id/aspiration_id/moderation_action_id/security_event_id/severity/eligible_reviewers/appeal_id, dengan nilai scalar atau null; key lain dan object nested dibuang.

`SecurityEventItem = {id: int, category: string?, category_label: string?, severity: string?, user: {id: int, name: string, email: string}|null, class: ClassRef|null, resource_type: string?, resource_ref: string?, denial_reason: string?, route: string?, method: string?, ip_address: string?, created_at: timestamp?}`. Category enum: access_denied, unauthorized_access_attempt, cross_class_access_attempt, privilege_escalation_attempt, repeated_denials, rate_limit_exceeded, suspicious_login, identity_verification_bypass_attempt, private_file_access_attempt, role_change, conflict_of_interest_violation. Severity low/medium/high/critical. Resource_ref adalah string public_ref atau ID yang dijadikan string, bukan isi laporan.

`VoiceCatalog = {enabled: bool, cooldown_seconds: int, sounds: {key: string, available: bool, file: string?, placeholder: string?}[]}`. Konfigurasi awal:

```json
{
  "message": "Katalog peringatan suara.",
  "data": {
    "enabled": true,
    "cooldown_seconds": 30,
    "sounds": [
      {"key": "access_denied", "available": false, "file": null, "placeholder": "akses-ditolak"},
      {"key": "repeated_denials", "available": false, "file": null, "placeholder": "percobaan-berulang"}
    ]
  }
}
```

## Administrasi

Semua endpoint administratif tetap aktif+token. Permission route dapat memakai OR untuk beberapa permission, tetapi policy tambahan tetap berlaku. Tidak ada endpoint admin membaca isi semua pengaduan.

| Method dan path | Permission/scope | Request/query | Sukses `data` | Error khusus |
| --- | --- | --- | --- | --- |
| `GET /admin/users` | Route salah satu users.manage/roles.manage/audit.view; policy tetap users.manage | **P**; `role` string, `identity_status` string, `search` string LIKE name/email/nim; `created_at desc` | 200 `User[]` dengan membership/permissions | Policy403. |
| `GET /admin/audit-logs` | users.manage + audit.view | **P**; `action` string; `created_at desc` | 200 `AuditItem[]` | `AUDIT_ACCESS_DENIED` 403. |
| `POST /admin/users/{user}/suspend` | users.manage; bukan diri sendiri | `reason` wajib string 5..500 | 200 null; semua token target dicabut | `SELF_SUSPEND_DENIED` 403. |
| `POST /admin/users/{user}/reactivate` | users.manage | Tidak ada body | 200 null | Error umum. |
| `POST /admin/users/{user}/role` | roles.manage; bukan diri sendiri | `role` wajib super_admin/lecturer_coordinator/class_leader/student/identity_verifier; `reason` wajib string 10..500 | 200 null (service assign tidak mengembalikan User); ambil ulang /auth/me atau list bila perlu | `SELF_ROLE_CHANGE_DENIED` 403. |
| `POST /admin/classes` | classes.manage, global administrasi | Semua `ClassWrite` wajib | 201 `{id: int, code: string, name: string, join_code: string?}` | Validasi/duplikat kode periode422; constraint concurrent/internal500. |
| `PATCH /admin/classes/{class}` | classes.manage | `ClassWrite` partial, field yang dihilangkan dipertahankan | 200 `{id: int, code: string, name: string}` | Validasi/duplikat kode periode422; constraint concurrent/internal500. |
| `POST /admin/classes/{class}/leader` | classes.manage | `user_id` wajib int exists users; target mahasiswa/ketua aktif terverifikasi dalam kelas target | 200 `{id: int, user_id: int, class_id: int, starts_at: timestamp?}` | Target role/keanggotaan/verifikasi tidak memenuhi aturan422. |
| `DELETE /admin/class-leaders/{assignment}` | classes.manage terhadap kelas assignment | Body JSON `reason` wajib string 5..500 | 200 null | Validasi422. |
| `POST /admin/classes/{class}/lecturers` | classes.manage | `lecturer_id` wajib int exists users, target dosen koordinator aktif; `role` nullable coordinator/assistant default coordinator | 201 `{id: int, lecturer_id: int, class_id: int}` | Target bukan dosen/akun nonaktif/salah aturan422. |
| `POST /classes/{class}/invitations` | classes.invite + ketua aktif/dosen/admin kelas | `expires_in_days` nullable int1..365; `max_uses` nullable int1..1000 | 201 `{id: int, code: string, expires_at: timestamp?, max_uses: int\|null}` | Scope403, validasi422. |

`ClassWrite` field: `academic_term_id` int exists academic_terms; `code` string max64; `name` string max120; `program_study` string max128; `semester` int1..14. Semua wajib untuk POST, semua opsional untuk PATCH tetapi tetap divalidasi jika ada; tidak nullable. `is_active`, `join_code`, `created_by` bukan request field. Kombinasi periode/code unik dengan validasi scoped422, menggunakan nilai record saat PATCH menghilangkan salah satu field. DB tetap unique untuk request bersamaan. Tidak ada endpoint read single/admin class list khusus; `/classes` mengembalikan kelas aktif.

`AuditItem = {id: int, actor: {id: int, name: string}|null, action: string?, action_label: string?, subject_type: string?, subject_id: int|null, class_id: int|null, changes: object|array|null, created_at: timestamp?}`. `subject_type` dapat class model lengkap; tidak diserialisasi sebagai resource yang terbuka. `changes` metadata berbeda per aksi, bukan kontrak seluruh kolom database. Tidak ada ip/user_agent pada response audit ini. Nilai filter action mengikuti `App\Enums\AuditAction`; aksi awal antara lain role_assigned, user_suspended, user_reactivated, class_created, class_updated, class_leader_assigned, class_leader_revoked, lecturer_assigned, lecturer_unassigned, invitation_issued, membership_approved, membership_rejected, identity_approved, identity_rejected, report_submitted, report_status_changed, report_assigned, report_forwarded, appeal_decided, content_moderated, config_changed (katalog dapat mencakup tambahan; tidak semua enum memiliki endpoint pemicu).

Metadata audit memakai allow-list skalar pada penulisan dan pembacaan. Free-text `reason`/`note`, nilai nested tanpa label, dan key tidak dikenal tidak dikirim kepada pembaca audit. `before`/`after` hanya mempertahankan metadata yang diizinkan. Log lama disaring saat dibaca tanpa menulis ulang riwayat database; salinan lama dan akses SQL/operator tetap memerlukan pengendalian operasional.

Contoh pembuatan undangan:

```json
{"expires_in_days":7,"max_uses":40}
```

```json
{
  "message": "Kode undangan dibuat.",
  "data": {"id": 6, "code": "ABCD1234EF", "expires_at": "2026-10-28T04:30:00+00:00", "max_uses": 40}
}
```

## Rate Limit Aktual

Semua route terautentikasi memakai `throttle:api`,120 request/menit per user, ditambah limit khusus di tabel berikut. Limit numerik pada route menggunakan jumlah request dan durasi menit Laravel; endpoint publik menggunakan signature domain/IP. Limit login internal terpisah per email lowercase+IP. Definisi limiter bernama `reports`10/menit tidak dipasang ke route sehingga bukan batas efektif endpoint laporan saat ini.

Setiap limiter numerik route mempunyai prefiks tindakan sendiri (register/login/identity-submit/dan seterusnya). Request login tidak mengonsumsi kuota registrasi, dan aktivitas pada endpoint berbeda tidak berbagi penghitung khusus. Limiter global API tetap berlaku bersama untuk semua endpoint terautentikasi.

| Endpoint | Limit route |
| --- | --- |
| POST auth/register | 3 per60 menit |
| POST auth/login | 10 per1 menit; tambahan 5 kredensial gagal per email+IP dalam60 detik sebelum TOO_MANY_ATTEMPTS |
| POST identity/submissions | 5 per60 menit |
| POST memberships/join | 10 per60 menit |
| POST reports | 15 per60 menit |
| POST aspirations | 10 per60 menit |
| POST aspiration comments | 20 per1 menit |
| POST aspiration support | 60 per1 menit |
| POST aspiration flag | 10 per60 menit |
| POST nested comment flag | 10 per60 menit |
| POST report appeals | 5 per60 menit |

Endpoint privat lainnya tetap mengikuti limiter global120/menit walaupun tidak memasang throttle numerik khusus. `SECURITY_REPEAT_THRESHOLD=5`, jendela600 detik, dan `SECURITY_COOLDOWN_SECONDS=900` mengatur deteksi/pencatatan penolakan berulang. Middleware aktif memeriksa cooldown `repeated_denials` pada semua endpoint privat kecuali logout/logout-all, mengembalikan429 `SECURITY_COOLDOWN` dan sisa detik pada header/meta. Logout/logout-all dikecualikan dari cooldown tetapi tetap mempunyai rate limit global. Setelah kedaluwarsa akses kembali diperiksa normal; token baru dari login publik tidak menghapus cooldown user. Audio mempunyai cooldown presentasi30 detik yang berbeda dari cooldown security.

## Gap Dan Batas Integrasi

- Verifikasi final: 114 test/626 assertion Laravel, 42 test JavaScript, 62 pemeriksaan browser, dan 12 tahap E2E backend/MySQL nyata lulus. Hasil serta batas cakupan dicatat di `AUDIT.md`. Contoh payload kontrak tetap sintetis; deployment Apache MAMP/produksi belum diuji.
- Frontend dashboard dan integrasinya didokumentasikan di [FRONTEND.md](FRONTEND.md). Tidak ada password reset/change API, email verification API, refresh token, integrasi SSO/API kampus, export, websocket, atau endpoint file audio.
- Periode akademik mempunyai katalog GET, tetapi belum ada endpoint tulis periode. Tidak ada API manajemen record konflik, pencabutan dosen/anggota/undangan, detail tindakan moderasi individual, atau endpoint pin aspirasi.
- Tidak ada feed terpisah timeline/update/assignment berpagination. Batas100/200 tidak dapat dilewati dengan query page.
- Body pengaduan/filename/pihak terlibat dapat mengungkap identitas. API privat bukan anonimitas mutlak atau enkripsi database; akses server/backup harus diatur di luar endpoint.
- `academic_term_id` laporan mengikuti periode kelas; field opsional dapat dihilangkan agar backend menentukan nilai konsisten. Kelas/periode berikutnya tetap perlu disiapkan operator sebelum onboarding.
- Beberapa response menggunakan key opsional dan array kosong untuk map kosong. Jangan membangun klien dengan asumsi semua respons mempunyai bentuk `data` identik.
- Definisi service yang tidak memiliki route bukan endpoint publik. Jangan mengintegrasikan method internal, role-change request table, aspiration images, atau derived_from_report_id sebagai fitur API tersedia.
- Constraint unik tetap dapat menghasilkan SERVER_ERROR ketika terjadi race di luar penanganan service khusus. Aspirasi/komentar memvalidasi ulang panjang setelah tag dibuang; frontend tetap melakukan escaping. Validasi ini bukan scanner malware, redaksi otomatis, atau sanitizer rich HTML.
