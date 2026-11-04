# Kebijakan Pilot Ruang Dengar

Acuan keputusan: `Promt16.md`. Dokumen ini membedakan persetujuan pengguna dari saran teknis; tidak menyatakan seluruh kebijakan data telah disetujui.

| Area | Status | Aturan/batas |
| --- | --- | --- |
| Correction Option A | DISETUJUI | Identitas terakhir disetujui tetap berlaku ketika koreksi pending atau rejected. Submit tidak menimpa nama/NIM canonical. Approval independen memperbarui candidate yang benar. |
| Ownership report | DISETUJUI | Tetap user_id, tidak berpindah karena NIM sama atau koreksi NIM. |
| Pengajuan pertama | DISETUJUI | Tetap belum verified sampai approval. Syarat register/submit NIM tidak dihapus. |
| Akun suspended/role revoked | DISETUJUI | Option A tidak membatalkan suspensi atau memberi privilege staff yang telah dicabut; recheck backend setelah lock diperlukan. |
| Riwayat koreksi | DISETUJUI | Candidate/keputusan/history privat dipertahankan; tidak menyalin NIM/KTM mentah ke log/audit umum/notifikasi. |
| Legacy identity ambigu | DISETUJUI | Tidak menyimpulkan/mengarang identity approved dari candidate. Tidak ada backfill otomatis terhadap data nyata. |
| NIM namespace/normalisasi/unique | PERLU KONFIRMASI PEMILIK PILOT | Global/perperiode/perkelas belum dipilih. Tidak menambah global unique, auto merge/reject/delete. |
| Checksum same/cross-account | PERLU KONFIRMASI PEMILIK PILOT | SHA-256 byte bukan bukti keaslian/fraud. Scope viewer, tindakan review/override dan retensi match perlu diputuskan sebelum UI deteksi aktif. |
| Hak owner historical | DISETUJUI | Hak pemilik sah dipertahankan sesuai ReportPolicy; revoke staff tidak boleh menutup owner secara massal. |
| Metadata staf | DISETUJUI | Policy yang sudah ada tetap authoritative; daftar/notifikasi/statistik tidak memberi data yang dilarang detail policy, termasuk pihak berkonflik. |
| Detail identity historis yang ditampilkan | PERLU KONFIRMASI PEMILIK PILOT | Snapshot lama vs identitas terbaru pada report/membership belum ditentukan. Tidak mass-update snapshot historis. |
| Revoke lifecycle baru | PERLU VERIFIKASI TEKNIS | Jalur baru memerlukan policy, transaction, audit dan regresi; jangan mempublikasikan helper lama begitu saja. |
| Jenis dokumen/intake | PERLU KONFIRMASI PEMILIK PILOT | Perlu minimisasi jenis/isi KTM; format existing tidak diperluas. Structure/path checks boleh diperkuat; scanner/quarantine harus dibuktikan. |
| Scanner/local quarantine | PERLU VERIFIKASI TEKNIS | Tidak mengirim dokumen ke layanan eksternal. Tidak mengklaim MIME/structure sebagai malware scan; unconfigured scanner tetap blocker pilot. |
| Retensi/purge/legal hold | PERLU KONFIRMASI PEMILIK PILOT | Durasi file/hash/rejected history/report/appeal/log/backup belum diputuskan. Tidak menerapkan penghapusan otomatis/destruktif. |
| Backup/recovery | PERLU VERIFIKASI TEKNIS | Test hanya data fiktif terisolasi. Encryption/end-to-end restore target belum terbukti; key/backup nyata tidak diakses. |
| Hosting/database Rp0 | DISETUJUI | Tidak upgrade berbayar. Resource/akun/OTP/kartu/deploy memerlukan persetujuan terpisah. |
| Database online baru | DISETUJUI | Tidak impor akun/token/KTM/laporan laptop. Peserta mendaftar sendiri, bukan seed akun demo sebagai isi pilot. |
| Penanggung jawab, retensi, kapasitas/durasi pilot | PERLU KONFIRMASI PEMILIK PILOT | Belum ada identitas operator/angka cohort/kuota/RPO/RTO/durasi yang ditetapkan. |
| Insiden/stop intake | PERLU KONFIRMASI PEMILIK PILOT | Tetapkan operator, kontak dan prosedur penghentian penerimaan tanpa menghapus bukti. |
| Membuka pilot | DISETUJUI | Memerlukan persetujuan eksplisit sesudah gate keamanan/test/operasional; build/test saja bukan GO. |

## Urutan Persetujuan Dan Pengerjaan

Option A dan perbaikan yang menyelaraskan policy existing dikerjakan lokal beserta regresi. Kebijakan duplicate, snapshot historis, retensi, scanner operasional dan governance yang belum lengkap tidak ditebak; bagian independen tetap dilanjutkan. Migration tambahan hanya diuji pada schema sekali pakai, tidak di-main/demo. Laporan bukti aktual berada di `PILOT-READINESS-REPORT.md` setelah verifikasi.
