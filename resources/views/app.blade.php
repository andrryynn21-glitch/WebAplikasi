<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="referrer" content="same-origin">
    <meta name="description" content="Ruang pengaduan privat dan aspirasi mahasiswa. Sampaikan, pantau, dan ikut membangun kelas yang lebih baik.">
    <title>Ruang Dengar | Pengaduan Mahasiswa</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">Lewati ke konten utama</a>
    <div id="app" data-api-base="{{ url('/api/v1') }}">
        <main id="main-content" class="boot-screen" tabindex="-1"><span class="institution-mark"><img class="institution-logo" src="{{ asset('images/logo-unpam.png') }}" width="300" height="291" alt="Logo Universitas Pamulang"></span><p>Menyiapkan ruang Anda...</p></main>
    </div>
    <div id="toasts" class="toast-region" aria-live="polite" aria-atomic="false"></div>
    <dialog id="app-dialog" aria-labelledby="dialog-title" aria-describedby="dialog-message"></dialog>
    <noscript><p class="callout">Aplikasi memerlukan JavaScript untuk mengakses API secara aman. Aktifkan JavaScript pada browser Anda.</p></noscript>
</body>
</html>
