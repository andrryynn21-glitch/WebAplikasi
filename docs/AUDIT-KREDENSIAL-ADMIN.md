# Audit Hardening Kredensial Administrator

Tanggal: 22 Oktober 2026. Implementasi mengikuti `Promt8.md`. Semua mutasi test memakai `pengaduan_unpam_test`; tidak ada migration baru, perubahan `.env`, akun otomatis, commit, push, atau deployment.

## Perubahan

- Pengguna login dapat mengubah password dengan password saat ini, konfirmasi, dan aturan minimal 12 karakter + huruf besar/kecil + angka + simbol.
- Password baru harus berbeda. Password memakai cast hash Laravel; password/konfirmasi tidak dicatat.
- Token Sanctum sesi lain dicabut; token request saat ini dipertahankan. Reset publik mencabut seluruh token.
- Forgot/reset memakai Password Broker Laravel dan tabel `password_reset_tokens`: token di-hash, expiry 60 menit, throttle 60 detik, dan sekali pakai.
- Respons forgot selalu generik untuk email ada/tidak. Invalid/expired/reused token menghasilkan 422 generik.
- Mailer `log`, `array` non-testing, atau failover yang dapat jatuh ke log/array tidak mengirim/membuat token reset. Ini mencegah token masuk log. `array` hanya diizinkan dalam testing.
- URL email memakai fragment `#/reset-password`; token dikirim frontend ke API dalam body, bukan query endpoint API.
- Audit action baru: `password_changed`, `password_reset_requested`, `password_reset_completed`. In-app SecurityNotice tidak memuat token/password.
- Role demotion dan suspend mengunci row role Super Admin yang sama, recheck actor/target setelah lock, dan menolak hilangnya Super Admin aktif terakhir. Admin kedua tetap dapat ditambahkan. Tidak ada route delete user.

## Route Dan Komponen

| Route | Auth | Efek |
| --- | --- | --- |
| `POST /api/v1/auth/password/change` | Sanctum + active | Ubah password akun sendiri; pertahankan current token, revoke lainnya. |
| `POST /api/v1/auth/password/forgot` | Publik | Respons generik; kirim email hanya bila mailer aman/tersedia. |
| `POST /api/v1/auth/password/reset` | Publik | Validasi token/email/password; single-use dan revoke seluruh sesi. |

Backend: `CredentialController`, `CredentialService`, tiga FormRequest auth, `RoleAssignmentService`, `UserAdministrationService`, AuditAction, AppServiceProvider reset URL, dan route API. Frontend: link lupa password/login, form forgot/reset di `app.js`, serta form ubah password pada `account.js`. Build Vite diperbarui.

## Tes Aktual

- `php artisan test --compact --order-by=random`: **125 passed / 694 assertion**.
- Targeted credential/multi-admin mencakup current password salah/benar, mismatch, current-token retention, other-token revocation, forgot enumeration resistance, unsafe log mailer, valid/invalid/expired/reused reset, audit secret absence, self-only change, last-admin demotion/suspend, stale concurrent demotion/suspend, dan no delete route.
- `npm test`: **44 passed**. `npm run test:browser`: **62 checks passed** dengan fixture API terisolasi.
- `npm run build`: berhasil; JS 112,16 kB raw / 32,74 kB gzip, CSS 31,48 kB / 8,47 kB gzip.
- Pint app/routes/tests lulus. `composer audit` dan `npm audit`: tidak ada advisory yang dilaporkan.
- Sesudah suite: test DB 0 user/0 reset token. Utama tetap 1 user/1 Super Admin/1 token/0 reset token/1 audit/0 notifikasi/0 kelas/0 laporan.

## Konfigurasi Mail

Mailer utama saat audit adalah `log`; pemulihan email sengaja **belum operasional** dan token tidak dibuat agar tidak tercatat ke log. Tidak ada `.env` yang diubah.

Untuk pengujian lokal, gunakan SMTP catcher seperti Mailpit pada environment nonutama, misalnya host loopback/port Mailpit dan alamat from fiktif yang sah, lalu clear config cache/restart server. Jangan memakai mailer log untuk reset token. Untuk produksi, konfigurasikan SMTP/provider nyata, TLS, from domain, secret melalui secret manager, delivery/bounce monitoring, dan uji link tanpa mencetak token. Test otomatis memakai mailer array/Notification fake dan tidak mengirim email nyata.

## Risiko Tersisa

- Mail utama belum dikonfigurasi; forgot tampak berhasil tetapi tidak mengirim saat transport log.
- Token ada di fragment URL/browser history dan dapat dibaca extension/script same-origin; mitigasi: sekali pakai, expiry, CSP/HTTPS, hindari script pihak ketiga.
- Kompromi mailbox tetap memungkinkan reset. Pertimbangkan MFA/admin approval untuk akun istimewa.
- Lock last-admin melindungi route/service resmi, bukan SQL operator/host yang disusupi. Tidak ada endpoint delete user; bulk SQL berada di luar guard aplikasi.
- Current session dipertahankan setelah change karena request memerlukan token aktif + current password. Perangkat lain dicabut. Reset publik mencabut semuanya.

## Konfirmasi Database Utama

Sebelum/sesudah tes tetap `pengaduan_unpam`, 1 user/Super Admin, 1 active token, 0 reset token, 1 audit entry, 0 in-app notification, 0 kelas/laporan. Test suite dikonfirmasi memakai `pengaduan_unpam_test`. Tidak ada test yang dijalankan terhadap database utama.
