# Audit Readiness Fitur Akademik Dan Alur Mahasiswa

Tanggal audit: 22 Oktober 2026. Lingkup mengikuti `Promt9.md`. Audit ini read-only terhadap aplikasi utama dan demo. Satu-satunya file yang dibuat adalah laporan ini. Tidak ada source aplikasi, `.env`, APP_KEY, akun, migration, seeder, atau data akademik yang diubah.

## 1. Ringkasan Kesiapan

Aplikasi mempunyai fondasi yang kuat untuk alur mahasiswa dan kelas: registrasi student, verifikasi KTM manual, invitation + persetujuan keanggotaan, penetapan ketua/dosen, pengaduan privat, aspirasi kelas, moderasi, notifikasi, audit, serta isolasi role/kelas sudah diimplementasikan dan banyak yang memiliki regresi feature. E2E nyata 12 tahap pernah lulus pada database audit terpisah dan dibersihkan (`docs/AUDIT.md`), tetapi tidak diulang dalam audit read-only ini.

**Belum siap dianggap lifecycle akademik lengkap.** Periode akademik masih katalog read-only, NIM bukan identifier unik, dan pencabutan dosen/anggota/undangan belum memiliki endpoint/UI lengkap. Beberapa lifecycle hanya dapat dimulai tetapi tidak dapat dikelola hingga selesai oleh operator. Tidak ada P0 baru yang terbukti; terdapat gap P1 integritas/operasional yang perlu diselesaikan sebelum penggunaan akademik nyata berskala lebih luas.

Kondisi utama sebelum/sesudah suite tetap: database `pengaduan_unpam`, 1 Super Admin, 1 periode, 0 kelas/member/pengajuan identitas/laporan/aspirasi/lampiran/reset token. Demo tetap `pengaduan_unpam_demo`, 1 user, 0 kelas/laporan. Test memakai `pengaduan_unpam_test` dan kembali 0 user.

## 2. Matriks Fitur

Status memakai istilah wajib Promt9.

### A. Pengelolaan Akademik

| Fitur | Status | Bukti implementasi/test | Alasan/batas |
| --- | --- | --- | --- |
| Katalog periode akademik | IMPLEMENTED AND TESTED | `GET /api/v1/academic-terms`; `MembershipController::academicTerms`; `AcademicTermSeeder`; `BootstrapAdminTest::test_academic_term_catalog_requires_authentication_and_preserves_dates` | Read authenticated, termasuk periode nonaktif. |
| CRUD periode akademik | NOT IMPLEMENTED | Hanya route GET; `resources/js/pages/management.js` mengambil katalog tanpa form term | Periode berikutnya perlu seeder/operator. Tidak ada create/update/activate/deactivate policy/audit. |
| Buat kelas | IMPLEMENTED BUT INSUFFICIENTLY TESTED | `POST /admin/classes`; `AdminController::storeClass`; `StoreClassRequest`; `ClassroomService::create`; `ClassRoomPolicy` | Backend/UI ada, tetapi tidak ditemukan test PHP langsung untuk POST; E2E sebelumnya menyiapkan kelas lewat helper sebelum flow. |
| Ubah kelas | IMPLEMENTED AND TESTED | `PATCH /admin/classes/{class}`; service/policy/request yang sama; `NonReportSecurityAuditTest::test_class_patch_accepts_partial_payload`, `test_duplicate_class_code_returns_validation_error` | Code unik per term, semester dan FK term tervalidasi. |
| Aktif/nonaktif kelas | NOT IMPLEMENTED | Kolom/model `is_active` ada dan katalog hanya membaca kelas aktif, tetapi `StoreClassRequest`/UI tidak menerima status | Tidak ada route deactivate/reactivate/delete. |
| Validasi term aktif/tanggal saat kelas/join | PARTIALLY IMPLEMENTED | `academic_term_id` hanya `exists`; kelas menyimpan FK term | Tidak ada check term aktif/rentang tanggal atau `starts_on <= ends_on` pada lifecycle akademik. |
| Penugasan dosen | IMPLEMENTED AND TESTED | `POST /admin/classes/{class}/lecturers`; `ClassroomService::assignLecturer`; `ClassRoomPolicy`; `MainAdminManagementTest::test_admin_can_assign_lecturer_role_then_assign_that_lecturer_to_a_class` | Target harus aktif dan role dosen. |
| Pencabutan/list penugasan dosen | PARTIALLY IMPLEMENTED | `ClassroomService::removeLecturer` ada | Tidak ada route/controller/UI/list assignment untuk menjalankan revoke resmi. |
| Penetapan/pergantian ketua | IMPLEMENTED BUT INSUFFICIENTLY TESTED | `POST /admin/classes/{class}/leader`; `ClassroomService::assignLeader`; unique active assignment; invalid/unverified target tests | Verified + member aktif dan replacement transaction tersedia; tidak ada test sukses replacement bernama khusus. |
| Pencabutan ketua | IMPLEMENTED BUT INSUFFICIENTLY TESTED | `DELETE /admin/class-leaders/{assignment}`; `AdminController::revokeLeader`; UI membutuhkan assignment ID | Former-leader denial banyak diuji, tetapi endpoint revoke sukses tidak mempunyai test bernama khusus dan tidak ada list assignment UI. Role ketua tidak otomatis kembali student. |
| Buat/pakai undangan | IMPLEMENTED AND TESTED | `POST /classes/{class}/invitations`; `MembershipService::requestJoin` dengan lock; invitation/identity test; E2E audit | Kode hanya membuka request pending, bukan verifikasi/persetujuan otomatis. |
| Kedaluwarsa/kuota undangan | IMPLEMENTED BUT INSUFFICIENTLY TESTED | `ClassInvitationCode::isUsable` memeriksa expiry/max uses; consume mengunci row | Tidak ditemukan test boundary expiry, kuota habis, atau concurrent last-use khusus. |
| Pencabutan/list undangan | NOT IMPLEMENTED | Model mempunyai `revoked_at`, tetapi tidak ada route list/revoke | Kode hanya berhenti via expiry/kuota kecuali operator SQL, yang tidak direkomendasikan. |
| Join dan approve membership | IMPLEMENTED AND TESTED | `/memberships/join`, pending list/decide; `MembershipService`; `ClassMembershipPolicy`; current leader/class scope/stale decision tests; E2E audit | Self-review ditolak, identity verified wajib, active membership lintas kelas dicek service. |
| Reject membership | IMPLEMENTED BUT INSUFFICIENTLY TESTED | `MembershipService::reject`, decision=reject, reason wajib | Tidak ditemukan test sukses rejection endpoint khusus. |
| Pencabutan membership aktif | PARTIALLY IMPLEMENTED | Enum/status dan `MembershipService::revoke` tersedia | Tidak ada route/controller/UI/test langsung; audit action masih memakai `membership_rejected` dan tidak ada notifikasi revoke. |
| Validasi role/account/scope kelas | IMPLEMENTED AND TESTED | `EnsureAccountIsActive`, `EnsureClassAccess`, policies, `User::isClassLeaderOf/isLecturerOf/managedClassIds`; Authorization/NonReport/FinalReport tests | Assignment harus sudah mulai dan belum berakhir; role tersisa tidak cukup. |

### B. Pendaftaran Dan Verifikasi Mahasiswa

| Fitur | Status | Bukti | Alasan/batas |
| --- | --- | --- | --- |
| Registrasi mandiri student | IMPLEMENTED AND TESTED | `POST /auth/register`; `RegisterRequest`; `AuthController::register`; `AuthorizationIsolationTest::test_user_cannot_escalate_own_role_via_registration_or_admin_endpoint`; bearer login test | Role/status dipaksa server; limiter register terpisah. |
| Pencegahan email ganda | IMPLEMENTED BUT INSUFFICIENTLY TESTED | Unique DB `users.email`, rule `unique:users,email` | Kontrak jelas, tetapi tidak ditemukan test feature bernama khusus duplicate registration email. |
| Keunikan NIM | NOT IMPLEMENTED | `users.nim` nullable/index biasa; request hanya regex; README menyatakan bukan pembuktian/unique global | Dua akun dapat memakai NIM sama. Verifikasi manusia dapat mendeteksi, tetapi integritas tidak dijaga DB/service. |
| Submit KTM privat | IMPLEMENTED AND TESTED | `/identity/submissions`; `SubmitIdentityRequest`; `IdentityVerificationService`; private disk; `SecurityAspirationTest::test_private_ktm_is_not_accessible_without_authorization`; orphan cleanup tests | MIME server/ukuran/checksum; bukan antivirus/OCR/integrasi kampus. |
| Review identity verifier | IMPLEMENTED AND TESTED | list/download/approve/reject routes; `IdentityVerificationPolicy`; stale-decision/self-review tests; E2E audit | Hanya role `identity.verify`, bukan Super Admin otomatis. |
| Penolakan dan pengajuan ulang | IMPLEMENTED BUT INSUFFICIENTLY TESTED | Status/reason API dan `account.js` menampilkan upload ulang saat tidak pending; service menerima submission baru jika tidak ada pending | Reject/decision diuji, tetapi tidak ditemukan satu test E2E khusus reject → koreksi → resubmit → approve. |
| Duplikasi pengajuan pending | IMPLEMENTED AND TESTED | Service lock/user pending check; `NonReportSecurityAuditTest::test_identity_decision_rechecks_persisted_state` dan audit identity tests | Pengajuan verified dapat diajukan ulang dan sementara menutup fitur kelas; ini perilaku terdokumentasi. Checksum KTM disimpan tetapi belum dipakai mendeteksi dokumen yang sama pada akun lain. |
| Pemisahan student/leader/lecturer/verifier/admin | IMPLEMENTED AND TESTED | `config/permissions.php`, middleware/policies, AuthorizationIsolation, ConflictAndSuperAdminPrivacy, MainAdminManagement | Admin tidak otomatis melihat KTM/report privat; verifier tidak diberi semua kelas. |

### C. Pengaduan Dan Aspirasi

| Fitur | Status | Bukti | Alasan/batas |
| --- | --- | --- | --- |
| Create/list/detail laporan privat | IMPLEMENTED AND TESTED | report routes/controller/service/resources/policy; `AuthorizationIsolationTest`, `ReportPrivacyRegressionTest`, real private disk test | Backend meredaksi title/detail; UI bukan kontrol utama. |
| Routing routine/sensitive | IMPLEMENTED AND TESTED | `ReportRoutingService`; ReportCategory sensitivity; future/reported leader tests; E2E audit | Routine dapat auto ke ketua eligible; sensitive/conflict/no handler menjadi manual needs_review. |
| Assignment/forward/status workflow | IMPLEMENTED AND TESTED | `ReportAssignmentService`, `ReportWorkflowService`, state enum; ReportWorkflow/FinalReport/rollback tests | Tidak ada revoke assignment manual; assignment biasa ditolak selama appeal open. |
| Konflik kepentingan | PARTIALLY IMPLEMENTED | Enforcement melalui `conflict_of_interest_records`, `Report::hasConflictFor`, policy/service dan ConflictAndSuperAdminPrivacy tests | Tidak ada API lifecycle declare/confirm/clear; test memasukkan record langsung. Name/free-text match tetap heuristik. |
| Klarifikasi/update/internal note | IMPLEMENTED AND TESTED | update/clarification endpoints; policy/service; reply-link/internal visibility tests | Feed detail maksimum 100 dan tidak mempunyai pagination terpisah. |
| Banding independen | IMPLEMENTED AND TESTED | appeal routes/service/policy; reviewer eligibility; ReportWorkflow/Privacy/E2E tests | Tidak ada GET daftar banding; ID berasal dari report/notifikasi/handoff. |
| Notifikasi workflow | PARTIALLY IMPLEMENTED | `NotificationService`, scoped policy/routes; payload scrub/security tests | Routing review dan appeal submission tertentu tidak menyertakan `class_id`, sehingga revocation visibility tidak berlaku pada producer tersebut. Reviewer assignment juga tanpa notifikasi. |
| Lampiran/unduhan privat | IMPLEMENTED AND TESTED | `PrivateStorageService`, `AttachmentPolicy`, `AttachmentController`; real disk download/denial/orphan cleanup tests | Tidak ada malware scanner/encryption-at-rest otomatis; ID attachment numerik dapat membedakan forbidden/not-found. |
| Redaksi list/detail | IMPLEMENTED AND TESTED | `ReportResource`, `ReportSummaryResource`, `ReportDetailResource`, privacy regression tests | Isi/title dilindungi, tetapi binding report menerima ID numerik dan 403/404 membocorkan existence. Assignment/timeline mengirim nama/reason kepada reporter; least-privilege perlu review. |
| Search laporan privat | NOT IMPLEMENTED | Filter hanya status/category/class/sort | Tidak ada full-text search; ini mengurangi surface kebocoran tetapi merupakan gap UX. |
| Export laporan/aspirasi | NOT IMPLEMENTED | Tidak ada route/controller/frontend export | Tidak ada bukti ekspor aman berbasis scope. |
| Aspirasi/list/detail/comment/upvote | IMPLEMENTED AND TESTED | aspiration routes/service/policies; SecurityAspiration/NonReport/FinalNonReport tests; E2E audit | Kelas scoped, duplicate support idempotent, body sanitized sebagai plain text. |
| Flag/moderasi aspirasi+comment | IMPLEMENTED AND TESTED | flag/moderate/flags routes; `ModerationService`; banyak NonReportSecurityAudit tests | Moderator tidak memoderasi sendiri; cross-resource/class ditolak. |
| Pagination riwayat panjang | PARTIALLY IMPLEMENTED | List utama paginated; detail report maksimum 100, comments maksimum 200 | Sisa timeline/update/comment tidak dapat diambil melalui endpoint pagination. |

### D. Kualitas Dan Integrasi

| Area | Status | Bukti | Batas |
| --- | --- | --- | --- |
| Suite backend terisolasi | IMPLEMENTED AND TESTED | 12 file Feature; 125 test/694 assertion lulus pada `pengaduan_unpam_test` | Bukan load/production test. |
| Unit/contract frontend | IMPLEMENTED AND TESTED | `tests/Frontend/api.test.js`, `final-audit.test.js`; 44 test lulus | Tidak merender seluruh browser flow. |
| Browser role/page fixture | IMPLEMENTED AND TESTED | `tests/Frontend/browser.mjs`; 62 checks, empat viewport | Mayoritas `/api/v1` diintersepsi fixture; bukan backend live. |
| E2E backend nyata akademik | IMPLEMENTED BUT INSUFFICIENTLY TESTED | `tests/Frontend/live-audit.mjs`; audit sebelumnya mencatat 12 tahap lulus dan cleanup | Tidak diulang audit ini; sebagian aksi lanjutan lewat API langsung dan hanya Chromium/MySQL lokal. |
| Workflow akademik pada database utama | NOT VERIFIED | Main hanya berisi 1 Super Admin + 1 term, tanpa kelas/member/report | Audit dilarang membuat data; tidak ada klaim alur digunakan di main. |
| Rate limiting/error envelope | IMPLEMENTED AND TESTED | login/comment/api/cooldown tests; `bootstrap/app.php`, route throttles, API client tests | Reaksi reverse proxy/web server sebelum Laravel dapat berbeda. |
| Apache MAMP/produksi/browser lain/accessibility lengkap | NOT VERIFIED | Dokumentasi hanya memberi setup; audit lokal memakai Artisan/Chromium | Perlu target deployment dan audit terpisah. |

## 3. Bukti Otorisasi, Privasi, Dan Integritas

- `AuthorizationIsolationTest`: laporan mahasiswa lain, kelas lain, role escalation, unverified features, invitation tanpa identity, leader tanpa assignment.
- `ConflictAndSuperAdminPrivacyTest`: pihak terlapor/conflict tidak menangani, Super Admin tidak membaca atau mengubah laporan privat.
- `ReportPrivacyRegressionTest`: title metadata null, lecturer lintas kelas, internal note, reply cross-report, transaction rollback, private disk dan appeal.
- `NonReportSecurityAuditTest`: review membership scoped, former leader, identity state, attachment revoked, security/notification class scope, cooldown, flags/comment moderation.
- `FinalNonReportAuditTest`: canonical identity, audit redaction, sanitized content, lock recheck, future leader, upload cleanup, limiter independence.
- `CredentialLifecycleTest`: password/token/reset dan last-admin continuity; tidak mengubah fitur akademik tetapi relevan terhadap account security.
- Policy backend (`ReportPolicy`, `AttachmentPolicy`, `IdentityVerificationPolicy`, `ClassMembershipPolicy`, `AspirationPolicy`) dipanggil route/controller/service. Menyembunyikan tombol frontend bukan sumber izin.
- Migration menyediakan FK/index/unique penting: email, term+class code, membership user+class, active leader surrogate, support user+aspiration, flag uniqueness. Sejumlah invariants tetap bergantung transaksi/service, bukan trigger SQL.

## 4. Celah Berdasarkan Prioritas

### P0

Tidak ada P0 baru yang dibuktikan. Audit ini tidak menyatakan aplikasi aman menyeluruh.

### P1

1. **Scope notifikasi belum konsisten.** `ReportRoutingService::notifyReviewers` dan `AppealService::submit` membuat notifikasi kelas tanpa `class_id`, sedangkan `AppNotification::scopeVisibleTo` hanya mencabut visibilitas berdasarkan class_id yang ada. Metadata reference/status dapat bertahan setelah akses kelas dicabut.
2. **Binding numerik membuka existence oracle.** Report dapat diakses melalui ID numerik selain public_ref; resource existing-but-forbidden dan missing menghasilkan 403/404. Appeal/attachment juga memakai ID sekuensial. Tidak ada isi privat langsung yang terbukti bocor, tetapi desain public_ref nonsekuensial melemah.
3. **NIM/dokumen tidak mempunyai duplicate policy.** Email unik, tetapi NIM hanya regex/index biasa dan checksum KTM tidak dibandingkan. Risiko dua akun mewakili identitas sama meningkat.
4. **AcademicTerm dan class activity lifecycle tidak ada.** Kelas masa depan/inaktif tidak dapat dikelola penuh dan term aktif/tanggal belum ditegakkan.
5. **Revoke lifecycle akademik tidak lengkap.** Dosen, membership aktif, dan invitation tidak dapat dicabut melalui endpoint resmi; leader revoke membutuhkan ID manual tanpa list.
6. **Duplicate active lecturer assignment mungkin terjadi.** Tidak ada unique constraint/service idempotency untuk active assignment lecturer/class/role.
7. **Conflict record tidak dapat dikelola resmi.** Deteksi nama membantu, tetapi clear/declare/confirm record tidak memiliki workflow UI/API terotorisasi.

### P2

1. Tidak ada list/search staf dan assignment, list banding, list/revoke invitation, export, atau search report.
2. Feed detail panjang terpotong tanpa pagination lanjutan.
3. Reject membership, identity reject → correction → resubmit, invitation expiry/quota concurrency, successful leader replacement/revoke, dan class create belum mempunyai test HTTP/E2E khusus yang memadai.
4. Leader revoke tidak memvalidasi assignment masih aktif sebelum menulis ulang waktu/alasan akhir.
5. Mail reset utama masih transport log dan sengaja tidak mengirim token; onboarding yang lupa password terhenti sampai SMTP aman dikonfigurasi.
6. E2E nyata 12 tahap tidak diulang dalam audit ini; bukti berasal audit sebelumnya. Browser 62 checks memakai fixture API untuk mayoritas alur.
7. Reporter menerima nama/reason assignment dan actor timeline; belum ada regression eksplisit yang menilai kebutuhan minimum setiap field. Penetapan reviewer banding juga tidak mengirim notifikasi.
8. Boundary rate limit report/appeal/flag dan malformed pagination/filter belum dicakup selengkap login/comment/global API.

### P3

1. `classes.join_code` masih ada tetapi join aktual memakai `class_invitation_codes`; sudah terdokumentasi namun berpotensi membingungkan operator/integrator.
2. `MembershipService::revoke` mencatat action membership_rejected; `InvitationRevoked` ada tanpa workflow.
3. Tidak ada pin/unpin aspirasi, edit/delete komentar penulis, atau image upload aspirasi meskipun beberapa enum/field mendukung konsep terkait.

## 5. Alur Yang Dapat Dijalankan End-to-End

Berikut didukung source dan pernah lulus E2E terisolasi: registrasi student → upload KTM → approval verifier berbeda → invitation → request membership → approval pengelola → pengaduan + attachment → auto/manual routing → clarification/response → reject → appeal → reviewer independen → reopen/assignment/forward/resolution → aspirasi/comment/upvote/flag/moderation → notifikasi/logout. E2E memakai data fiktif/database sementara dan dibersihkan; bukan bukti alur telah dipakai pada database utama yang masih kosong untuk data akademik.

Alur kelas minimal juga didukung pada test: admin memberi role lecturer → assignment kelas; student verified/member aktif → leader assignment; former leader kehilangan scope. Multi-Super Admin dan credential lifecycle bukan bagian akademik, tetapi regresinya lulus.

## 6. Alur Yang Terhenti Atau Membutuhkan Eksternal

- Membuka periode akademik baru/menonaktifkan periode melalui UI: terhenti, endpoint tulis tidak ada.
- Mengeluarkan dosen/member atau mencabut invitation melalui UI: terhenti.
- Memastikan NIM resmi/unik: memerlukan kebijakan uniqueness/migrasi data dan idealnya sumber identitas kampus; verifikasi sekarang manual.
- Forgot password: route/UI ada, tetapi mail utama `log` sengaja tidak menghasilkan token; perlu SMTP/provider aman.
- Scanner malware/OCR/verifikasi kampus, SSO, push realtime, backup/restore produksi, Apache MAMP, dan browser selain Chromium: eksternal/belum diverifikasi.
- Export/search/full history: endpoint belum ada, bukan sekadar UI belum selesai.

## 7. Risiko

- **Privasi:** KTM/report berada di private disk dan policy API, tetapi bukan enkripsi at-rest; host/DB/backup/operator tetap trust boundary. Isi/nama file/konteks dapat mengungkap identitas tidak langsung.
- **Otorisasi:** role saja tidak cukup; assignment/membership/identity/conflict diperiksa. Risiko muncul bila operator bypass service lewat SQL, endpoint revoke tidak tersedia, atau notifikasi tanpa class_id tidak ikut hilang saat scope dicabut.
- **Integritas:** transaksi/lock diuji untuk workflow penting, tetapi NIM duplicate dan beberapa invariant service-only belum dijaga constraint global.
- **Operasional:** satu periode seed, satu admin utama, mail reset belum deliverable, retensi/backup belum disahkan, dan lifecycle revoke tidak lengkap.
- **Pengujian:** test suite kuat tetapi bukan proof seluruh kombinasi concurrent/load, production deployment, screen reader, atau layanan eksternal.

## 8. Urutan Implementasi Selanjutnya

1. **Tutup celah privacy-scope:** wajibkan `class_id` pada seluruh notifikasi kelas, test revocation dari producer nyata, batasi report binding ke public_ref atau samakan denial/not-found, lalu threat-model ID appeal/attachment.
2. **Integritas identitas:** tentukan duplicate policy NIM/checksum, audit data, penanganan legitimate correction/re-enrollment, lalu test reject → correct → resubmit. Jangan menambah unique constraint sebelum audit data.
3. **Lifecycle akses kelas:** endpoint list + revoke lecturer, leader, membership, invitation; active-state check, alasan, audit yang tepat, notification, lock, dan frontend tanpa ID manual.
4. **Lifecycle akademik inti:** CRUD AcademicTerm (tanggal/active/uniqueness/audit), class activation, serta aturan term aktif ketika create/join.
5. **Idempotency assignment dosen dan conflict management:** active uniqueness/concurrency; declare/confirm/clear conflict dengan role independen dan larangan self-clear.
6. **UX/operasional:** staff/assignment search, list banding, pagination history, SMTP aman, scanner/retensi/backup, deployment dan accessibility/load test; export hanya setelah threat model/redaction.

Setiap tahap harus memiliki FormRequest, policy/service authorization, transaksi/lock bila multi-record, audit metadata aman, feature test allow/deny/lintas kelas, dan kontrak frontend sebelum tahap berikutnya.

## 9. Pengujian Tambahan Yang Dibutuhkan

- AcademicTerm create/update/activate/deactivate, overlap/tanggal invalid, term dipakai kelas, concurrent active-term change.
- Create class terhadap term inactive/expired sesuai keputusan bisnis.
- List/revoke lecturer/leader/member/invitation, cross-class denial, repeated/revoked token use, stale/concurrent revoke.
- Duplicate NIM registration/submission/approval dan strategi merge/reject.
- Notifikasi dari auto-routing/manual-review/appeal submit lalu akses kelas dicabut; payload harus hilang dan tidak membawa metadata privat.
- Binding report numeric/public_ref serta ID appeal/attachment: existing unauthorized vs missing harus mengikuti keputusan concealment yang konsisten.
- Identity reject → resubmit dengan file baru → approve; file lama/baru dan status fitur kelas.
- Conflict declare/confirm/clear, independent reviewer, name alias/homonim.
- Pagination timeline/update/comment setelah batas 100/200.
- Export/search redaction per role bila fitur ditambahkan.
- Least-privilege reporter timeline/assignment fields dan reviewer-assignment notification.
- Boundary limiter report submit/appeal/flag dan validasi pagination/filter malformed.
- E2E nyata diulang setelah lifecycle akademik berubah; SMTP catcher, Apache MAMP, browser/accessibility dan load/concurrency terpisah.

## 10. Hasil Pengujian Aktual Audit Ini

- Main sebelum/sesudah: `pengaduan_unpam`, 1 Super Admin, 1 term, 0 kelas/member/identity/report/aspiration/attachment/reset token.
- Demo sebelum/sesudah: `pengaduan_unpam_demo`, 1 user, 0 kelas/laporan.
- Test diverifikasi `pengaduan_unpam_test`, 39 tabel/9 migration/0 user sebelum; kembali 0 user setelah suite.
- `php artisan test --compact --order-by=random`: **125 passed / 694 assertion**.
- `npm test`: **44 passed**.
- `npm run test:browser`: **62 checks passed**, API fixture; bukan E2E live.
- E2E 12 tahap dari audit sebelumnya: tidak diulang, hanya bukti historis yang terdokumentasi.

## 11. Konfirmasi Read-only

Database utama `pengaduan_unpam` tidak berubah. Database demo `pengaduan_unpam_demo` tidak berubah dan tetap terpisah. `.env`, APP_KEY, konfigurasi credential, akun pengguna, migration, seeder, serta source aplikasi tidak diubah. Tidak ada commit, push, deploy, drop, delete, `migrate:fresh`, `migrate:refresh`, atau `db:wipe`. Satu-satunya file yang dibuat adalah `docs/AUDIT-READINESS-AKADEMIK.md`.
