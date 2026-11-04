import { createApi, createVoiceManager } from './api.js';
import { esc, icon, badge, date, field, formDataObject, formErrors, skeleton, empty, alertBox, navigation, statusLabels } from './ui.js';
import { reportsPage } from './pages/reports.js';
import { aspirationsPage } from './pages/aspirations.js';
import { accountPage } from './pages/account.js';
import { managementPage } from './pages/management.js';
import { attendancePage } from './pages/attendance.js';

const root = document.querySelector('#app');
const dialog = document.querySelector('#app-dialog');
const store = { get() { try { return sessionStorage.getItem('rd.token'); } catch { return null; } }, set(value) { try { value ? sessionStorage.setItem('rd.token', value) : sessionStorage.removeItem('rd.token'); } catch { /* An in-memory session still works when storage is disabled. */ } } };
let token = store.get();
let user = null;
let routeAbort = new AbortController();
let resolveDialog = null;
let dialogMotion = null;
let sessionNotice = '';
let audioEnabled = false;
let unsavedChanges = null;
let renderedHash = location.hash;
let confirmingNavigation = false;
const busyElements = new WeakSet();

function toast(message) {
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = message;
    document.querySelector('#toasts').append(el);
    setTimeout(() => el.remove(), 6500);
}
function closeDialog(result = false, animate = false) {
    if (animate && dialog.open && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        dialogMotion?.cancel();
        const motion = dialog.animate([{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(8px)' }], { duration: 140, easing: 'ease-in', fill: 'forwards' });
        dialogMotion = motion;
        motion.finished.then(() => { if (dialogMotion === motion) closeDialog(result); }).catch(() => {});
        return;
    }
    dialogMotion?.cancel();
    dialogMotion = null;
    dialog.close();
    dialog.innerHTML = '';
    const resolve = resolveDialog;
    resolveDialog = null;
    resolve?.(result);
}
dialog.addEventListener('cancel', (e) => { e.preventDefault(); closeDialog(false, true); });
function confirm({ title, message, confirmLabel = 'Lanjutkan', notice = false }) {
    if (dialog.open) closeDialog(false);
    dialog.innerHTML = `<div class="dialog-mark">${icon(notice ? 'shield' : 'alert')}</div><h2 id="dialog-title">${esc(title)}</h2><p id="dialog-message">${esc(message)}</p><div class="toolbar dialog-actions">${notice ? '' : '<button class="button secondary" data-cancel>Batal</button>'}<button class="button" data-confirm>${esc(confirmLabel)}</button></div>`;
    dialog.querySelector('[data-cancel]')?.addEventListener('click', () => closeDialog(false, true));
    dialog.querySelector('[data-confirm]').addEventListener('click', () => closeDialog(true, true));
    dialog.showModal();
    return new Promise((resolve) => { resolveDialog = resolve; });
}
const voice = createVoiceManager({ notify: toast });
function expireSession(message = 'Sesi Anda telah berakhir. Silakan masuk kembali.') {
    unsavedChanges = null;
    routeAbort.abort();
    token = null;
    user = null;
    store.set(null);
    voice.setEnabled(false);
    audioEnabled = false;
    closeDialog(false);
    sessionNotice = message;
    root.innerHTML = '';
    if (location.hash === '#/login') render(); else location.hash = '/login';
}
const api = createApi({ base: root.dataset.apiBase, credentials: root.dataset.apiCredentials, token: () => token, onError(error, path) {
    if (error.status === 401 && !path.startsWith('/auth/login') && !path.startsWith('/auth/register')) { expireSession(); return; }
    if (error.code === 'ACCOUNT_DISABLED' && token) { expireSession('Akun tidak aktif. Hubungi administrator sistem.'); return; }
    if (error.status === 403 || error.code === 'SECURITY_COOLDOWN') {
        const repeated = error.voice?.category === 'repeated_denials' || error.code === 'SECURITY_COOLDOWN';
        confirm({ title: repeated ? 'Akses dibatasi sementara' : 'Akses tidak diizinkan', message: `${error.message} Keputusan ini ditentukan oleh server, bukan pengaturan suara.`, confirmLabel: 'Saya mengerti', notice: true });
        voice.handle(error).catch(() => {});
    }
} });
const has = (permission) => Boolean(user?.permissions?.includes(permission));
const canClass = () => user?.identity_status === 'verified' && user?.membership?.status === 'active';
const brandMarkup = (href) => `<a class="brand" href="${href}"><span class="institution-mark"><img class="institution-logo" src="/images/logo-unpam.png" width="300" height="291" alt="Logo Universitas Pamulang"></span><span class="brand-copy">ruang dengar<small>PENGADUAN MAHASISWA</small></span></a>`;

function shell() {
    const links = navigation(user);
    const activeRoot = `/${location.hash.slice(2).split(/[/?]/)[0]}`;
    root.innerHTML = `<div class="app-shell"><button class="sidebar-overlay" aria-label="Tutup navigasi" tabindex="-1"></button><aside class="sidebar" id="sidebar" aria-label="Navigasi utama">${brandMarkup('#/dashboard')}<div class="sidebar-section-label">RUANG KERJA</div><nav>${links.map((link) => `<a href="#${link.path}" class="nav-link ${link.path === activeRoot ? 'active' : ''}" ${link.path === activeRoot ? 'aria-current="page"' : ''}>${icon(link.icon)}<span>${link.label}</span>${link.path === '/dashboard' ? '<span class="nav-marker"></span>' : ''}</a>`).join('')}</nav><div class="sidebar-note">${icon('lock')}<strong>Suara Anda, ruang yang tepat.</strong><p>Pengaduan privat terpisah dari aspirasi bersama.</p></div><div class="sidebar-user"><span class="avatar">${esc(user.name?.slice(0, 1).toUpperCase())}</span><div><strong>${esc(user.name)}</strong><small>${esc(user.role_name || 'Pengguna')}</small></div><button type="button" class="icon-button" data-logout aria-label="Keluar dari akun" title="Keluar">${icon('logout')}</button></div></aside><div class="workspace"><header class="topbar"><div class="toolbar"><button class="icon-button mobile-menu" aria-controls="sidebar" aria-expanded="false" aria-label="Buka navigasi">${icon('menu')}</button><span class="breadcrumb">Ruang Dengar <span>/</span> <strong>${esc(links.find((l) => l.path === activeRoot)?.label || 'Detail')}</strong></span></div><div class="toolbar"><span class="topbar-date">${new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())}</span><a class="icon-button" href="#/notifications" aria-label="Buka notifikasi">${icon('bell')}</a><a class="avatar avatar-small" href="#/account" aria-label="Buka akun">${esc(user.name?.slice(0, 1).toUpperCase())}</a></div></header><main id="main-content" class="main-content" tabindex="-1"></main><footer class="app-footer"><span>Ruang Dengar <span class="muted">/</span> Pengaduan Mahasiswa</span><span>Verifikasi identitas manual. Bukan layanan resmi kampus.</span></footer></div></div>`;
    const mobile = window.matchMedia('(max-width: 960px)');
    const sidebar = root.querySelector('.sidebar');
    const workspace = root.querySelector('.workspace');
    const menuButton = root.querySelector('.mobile-menu');
    const toggle = (open, focus = true) => {
        root.querySelector('.app-shell').classList.toggle('nav-open', open);
        menuButton.setAttribute('aria-expanded', String(open));
        sidebar.inert = mobile.matches && !open;
        workspace.inert = mobile.matches && open;
        if (focus && open) sidebar.querySelector('.nav-link').focus();
        else if (focus && mobile.matches) menuButton.focus();
    };
    toggle(false, false);
    mobile.addEventListener('change', () => toggle(false, false), { signal: routeAbort.signal });
    menuButton.addEventListener('click', () => toggle(!root.querySelector('.app-shell').classList.contains('nav-open')));
    root.querySelector('.sidebar-overlay').addEventListener('click', () => toggle(false));
    root.querySelector('[data-logout]').addEventListener('click', (e) => action(e.currentTarget, async () => {
        const sessionToken = token;
        if (!await confirm({ title: 'Keluar dari Ruang Dengar?', message: 'Sesi pada tab ini akan diakhiri. Perubahan formulir yang belum dikirim tidak disimpan.', confirmLabel: 'Keluar' })) return;
        let notice = 'Anda telah keluar dengan aman.';
        try { await api('/auth/logout', { method: 'POST' }); }
        catch { notice = 'Sesi lokal telah dihapus. Pencabutan token di server belum dapat dipastikan; token mungkin masih aktif sampai kedaluwarsa.'; }
        if (token === sessionToken) expireSession(notice);
    }));
    document.onkeydown = (e) => {
        const opened = mobile.matches && root.querySelector('.app-shell')?.classList.contains('nav-open');
        if (e.key === 'Escape' && !dialog.open && opened) toggle(false);
        if (e.key === 'Tab' && opened && !dialog.open) {
            const links = [...sidebar.querySelectorAll('a, button')];
            if (e.shiftKey && document.activeElement === links[0]) { e.preventDefault(); links.at(-1).focus(); }
            else if (!e.shiftKey && document.activeElement === links.at(-1)) { e.preventDefault(); links[0].focus(); }
        }
    };
    return root.querySelector('#main-content');
}

async function action(element, callback) {
    if (busyElements.has(element)) return;
    busyElements.add(element);
    const controls = element.matches('form') ? [...element.querySelectorAll('button[type="submit"], button:not([type])')] : [element];
    const states = controls.map((el) => el.disabled);
    controls.forEach((el) => { el.disabled = true; });
    element.setAttribute('aria-busy', 'true');
    if (element.matches('form')) formErrors(element, null);
    try { await callback(); }
    catch (error) {
        if (error.name !== 'AbortError' && element.isConnected) {
            if (element.matches('form')) formErrors(element, error);
            else toast(error.message || 'Aksi tidak dapat diselesaikan.');
        }
    } finally {
        busyElements.delete(element);
        controls.forEach((el, index) => { el.disabled = states[index]; });
        element.removeAttribute('aria-busy');
    }
}
function context(view, signal) {
    return { view, user, has, canClass, toast, confirm, action,
        api: (path, options = {}) => api(path, { ...options, signal }),
        navigate: (path) => { if (!signal.aborted) location.hash = path; },
        refresh: () => { if (!signal.aborted) render(); },
        guardUnsavedChanges: (check) => { if (!signal.aborted) unsavedChanges = check ? () => !signal.aborted && view.isConnected && check() : null; },
        refreshUser: async () => { const result = await api('/auth/me', { signal }); if (!signal.aborted) user = result.data; return result.data; },
        form: (form, callback) => { form.addEventListener('submit', (e) => { e.preventDefault(); if (form.reportValidity()) action(form, () => callback(form)); }); },
        page: (title, description, html, actions = '') => {
            if (signal.aborted) return;
            view.innerHTML = `<div class="page-heading"><div><span class="eyebrow">RUANG DENGAR / ${esc(user?.role_name || 'MAHASISWA')}</span><h1 tabindex="-1">${esc(title)}</h1><p>${esc(description)}</p></div><div class="toolbar">${actions}</div></div><div class="page-body">${html}</div>`;
            document.title = `${title} | Ruang Dengar`;
        },
        download: async (path, name = 'dokumen') => {
            const relative = path.startsWith('/api/v1/') ? path.slice(7) : path;
            if (!/^\/(attachments\/\d+|identity-verifications\/\d+\/ktm)$/.test(relative)) throw new Error('Tautan dokumen tidak dikenali.');
            const blob = await api(relative, { binary: true, signal });
            if (signal.aborted) return;
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a'); a.href = url; a.download = name; a.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        },
    };
}

function authPage(mode) {
    document.onkeydown = null;
    document.title = `${mode === 'register' ? 'Daftar' : 'Masuk'} | Ruang Dengar`;
    const register = mode === 'register';
    root.innerHTML = `<main id="main-content" class="auth-layout">
        <section class="auth-story">
            ${brandMarkup('#/login')}
            <div class="auth-editorial"><span class="eyebrow">RUANG UNTUK SETIAP SUARA</span>
                <h1>Didengar.<br>Ditangani.<br><em>Lebih baik.</em></h1>
                <p>Dari hal yang mengganggu, hingga ide yang membangun. Mulai percakapan untuk pengalaman kelas yang lebih baik.</p>
                <div class="auth-illustration" aria-hidden="true"><span class="orbit orbit-one"></span><span class="orbit orbit-two"></span><span class="quote-mark">&ldquo;</span><div class="illustration-label">Satu suara bisa<br>memulai perubahan.</div><span class="illustration-star">+</span></div>
            </div>
            <div class="auth-footnote">${icon('shield')}<span>Privat untuk pengaduan. Bersama untuk aspirasi.</span></div>
        </section>
        <section class="auth-form-side">
            <div class="auth-top">${register ? 'Sudah punya akun?' : 'Baru di sini?'} <a href="#/${register ? 'login' : 'register'}">${register ? 'Masuk' : 'Buat akun'} ${icon('arrow')}</a></div>
            <div class="auth-form-wrap"><span class="eyebrow">${register ? 'LANGKAH PERTAMA' : 'SELAMAT DATANG KEMBALI'}</span><h2>${register ? 'Mulai dari suara Anda.' : 'Masuk ke ruang Anda.'}</h2>
                <p class="muted">${register ? 'Daftarkan akun mahasiswa. Verifikasi identitas dan keanggotaan dilakukan setelah registrasi.' : 'Pantau pengaduan dan ambil bagian dalam aspirasi kelas Anda.'}</p>
                ${sessionNotice ? alertBox(sessionNotice) : ''}
                <form id="auth-form" class="stack" novalidate><div data-errors></div>
                    ${register ? field('name', 'Nama lengkap', { required: true, maxLength: 120, autocomplete: 'name', placeholder: 'Nama sesuai identitas' }) : ''}
                    ${field('email', 'Alamat email', { type: 'email', required: true, maxLength: 255, autocomplete: 'email', placeholder: 'nama@email.com' })}
                    ${register ? field('nim', 'Nomor Induk Mahasiswa', { required: true, maxLength: 32, placeholder: 'NIM Anda' }) : ''}
                    ${field('password', 'Kata sandi', { type: 'password', required: true, minLength: register ? 8 : undefined, maxLength: 255, autocomplete: register ? 'new-password' : 'current-password', help: register ? 'Minimal 8 karakter dengan huruf dan angka.' : undefined })}
                    ${register ? field('password_confirmation', 'Konfirmasi kata sandi', { type: 'password', required: true, autocomplete: 'new-password' }) : ''}
                    <label class="check-line"><input type="checkbox" id="show-password"> Tampilkan kata sandi</label>
                    <button class="button auth-submit" type="submit">${register ? 'Buat akun mahasiswa' : 'Masuk'} ${icon('arrow')}</button>
                    ${register ? '' : '<a class="text-link" href="#/forgot-password">Lupa password?</a>'}
                </form>
                <div class="auth-privacy">${icon('lock')}<p>${register ? 'Registrasi tidak langsung membuka fitur kelas. Unggah KTM dan tunggu verifikasi petugas.' : 'Gunakan akun pribadi. Tidak ada pilihan role saat masuk; akses ditentukan oleh server.'}</p></div>
            </div>
            <p class="auth-disclaimer">Aplikasi pengaduan mahasiswa. Verifikasi manual, tanpa integrasi resmi UNPAM.</p>
        </section>
    </main>`;
    sessionNotice = '';
    const form = root.querySelector('form');
    root.querySelector('#show-password').addEventListener('change', (e) => form.querySelectorAll('input[name^="password"]').forEach((input) => { input.type = e.target.checked ? 'text' : 'password'; }));
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!form.reportValidity()) return;
        action(form, async () => {
            const result = await api(`/auth/${register ? 'register' : 'login'}`, { method: 'POST', body: { ...formDataObject(form), device_name: 'ruang-dengar-web' }, signal: routeAbort.signal });
            token = result.data.token; store.set(token); user = result.data.user;
            form.reset();
            const destination = register ? '/account' : '/dashboard';
            if (location.hash === `#${destination}`) await render(); else location.hash = destination;
        });
    });
}

function passwordRecoveryPage(mode, params) {
    document.onkeydown = null;
    const reset = mode === 'reset-password';
    document.title = `${reset ? 'Atur ulang password' : 'Lupa password'} | Ruang Dengar`;
    root.innerHTML = `<main id="main-content" class="boot-screen"><section class="panel auth-form-wrap">${brandMarkup('#/login')}<div class="stack"><span class="eyebrow">KEAMANAN AKUN</span><h1>${reset ? 'Atur password baru.' : 'Pulihkan akses akun.'}</h1><p class="muted">${reset ? 'Tautan hanya dapat digunakan sekali dan memiliki masa berlaku.' : 'Jika email terdaftar dan layanan email tersedia, tautan pemulihan akan dikirim. Respons tidak mengungkap keberadaan akun.'}</p><form id="recovery-form" class="stack" novalidate><div data-errors></div>${field('email', 'Alamat email', { type: 'email', value: params.get('email') || '', required: true, maxLength: 255, autocomplete: 'email' })}${reset ? `${field('password', 'Password baru', { type: 'password', required: true, minLength: 12, autocomplete: 'new-password', help: 'Minimal 12 karakter, huruf besar/kecil, angka, dan simbol.' })}${field('password_confirmation', 'Konfirmasi password baru', { type: 'password', required: true, autocomplete: 'new-password' })}` : ''}<button class="button" type="submit">${reset ? 'Simpan password baru' : 'Kirim tautan pemulihan'}</button></form><a class="text-link" href="#/login">Kembali ke halaman masuk</a></div></section></main>`;
    const form = root.querySelector('#recovery-form');
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        action(form, async () => {
            const values = formDataObject(form);
            const path = reset ? '/auth/password/reset' : '/auth/password/forgot';
            const body = reset ? { ...values, token: params.get('token') || '' } : values;
            const result = await api(path, { method: 'POST', body, signal: routeAbort.signal });
            form.reset();
            if (reset) { sessionNotice = result.message; location.hash = '/login'; }
            else toast(result.message);
        });
    });
}

const metricLabels = {
    total: 'Total pengaduan', open: 'Dalam proses', resolved: 'Telah selesai', needs_clarification: 'Butuh klarifikasi', assigned_open: 'Tugas dalam proses', waiting_clarification: 'Menunggu klarifikasi', resolved_by_me: 'Selesai ditangani', needs_review: 'Perlu peninjauan', under_review: 'Sedang ditinjau', waiting_follow_up: 'Menunggu tindak lanjut', resolution_proposed: 'Usulan penyelesaian', active_users: 'Pengguna aktif', inactive_users: 'Pengguna nonaktif', classes: 'Kelas aktif', pending_identity_verifications: 'Antrean identitas', pending: 'Menunggu verifikasi',
};
async function dashboard(ctx) {
    const { data } = await ctx.api('/dashboard');
    const student = data.role === 'student';
    const admin = data.role === 'super_admin';
    const verifier = data.role === 'identity_verifier';
    const leader = data.role === 'class_leader';
    const stats = admin ? data.system : verifier ? data.identity_verifications : data.reports;
    const metrics = Object.entries(stats || {}).map(([key, value], index) => `<article class="metric metric-${index % 4}"><div class="metric-heading"><span>${esc(metricLabels[key] || key)}</span>${icon(index === 2 ? 'check' : index === 1 ? 'clock' : 'report')}</div><strong class="metric-number">${esc(value)}</strong><small>${admin ? 'Data sistem' : verifier ? 'Pemeriksaan oleh petugas' : 'Sesuai akses Anda'}</small></article>`).join('');
    const reports = leader ? (await ctx.api('/reports?scope=class&per_page=5')).data : data.recent_reports || data.needs_attention || [];
    const reportList = reports.length ? `<div class="content-list">${reports.map((r) => `<a class="list-item report-row" href="#/reports/${encodeURIComponent(r.public_ref)}"><span class="row-icon">${icon('report')}</span><span class="list-main"><strong>${esc(r.public_ref)}</strong><small>Diperbarui ${date(r.last_activity_at)}</small></span>${badge(r.status)}<span class="row-arrow">${icon('arrow')}</span></a>`).join('')}</div>` : empty('Belum ada pengaduan di daftar ini', student ? 'Pengaduan yang Anda kirim akan muncul di sini. Setiap proses dapat dipantau.' : 'Daftar akan terisi ketika ada pengaduan yang memerlukan perhatian.');
    const chartData = Object.entries(data.statistics?.by_status || {});
    const chart = chartData.length ? `<div class="status-chart" aria-label="Distribusi status pengaduan">${chartData.map(([status, value]) => `<div class="chart-row"><span>${esc(statusLabels[status] || status)}</span><div class="chart-track"><span style="--bar:${Math.max(0, Math.min(100, Number(value) / Math.max(1, Number(data.statistics.total_reports)) * 100))}%"></span></div><strong>${esc(value)}</strong></div>`).join('')}</div>` : empty('Belum ada distribusi status', 'Statistik akan terbentuk dari pengaduan yang diterima, bukan data contoh.');
    const aspirations = data.aspirations?.length ? `<div class="content-list">${data.aspirations.map((a) => `<a class="list-item" href="#/aspirations/${a.id}"><span class="row-icon green">${icon('chat')}</span><span class="list-main"><strong>${esc(a.title)}</strong><small>${esc(a.supports_count)} dukungan <span aria-hidden="true">/</span> ${esc(a.comments_count)} komentar</small></span>${icon('arrow')}</a>`).join('')}</div>` : empty('Percakapan berikutnya dimulai dari Anda', 'Belum ada aspirasi yang dapat ditampilkan.');
    const welcome = admin ? 'Ruang kelola, bukan ruang baca privat.' : verifier ? 'Identitas yang diperiksa dengan cermat.' : 'Suara kecil. Perubahan berarti.';
    const tasks = leader
        ? `<div class="toolbar workload"><a href="#/classes">${icon('users')} <strong>${esc(data.pending_memberships ?? 0)}</strong> permintaan keanggotaan ${icon('arrow')}</a><a href="#/aspirations?include_hidden=1">${icon('chat')} <strong>${esc(data.open_flags ?? 0)}</strong> laporan aspirasi terbuka ${icon('arrow')}</a></div>`
        : data.role === 'lecturer_coordinator' ? `<div class="toolbar workload"><a href="#/reports">${icon('report')} <strong>${esc(data.appeals?.pending ?? 0)}</strong> banding menunggu peninjauan. Buka handoff banding ${icon('arrow')}</a></div>` : '';
    ctx.page(`Halo, ${ctx.user.name.split(' ')[0]}.`, 'Ini kabar terbaru dari ruang Anda. Semua informasi mengikuti akses akun Anda.', `
        <section class="welcome-banner"><div><span class="eyebrow">${admin ? 'ADMINISTRASI SISTEM' : verifier ? 'VERIFIKASI MANUAL' : 'BERSAMA MEMBANGUN KELAS'}</span><h2>${welcome}</h2><p>${admin ? 'Kelola akun, kelas, dan keamanan tanpa membuka isi pengaduan rahasia.' : verifier ? 'Periksa KTM sesuai prosedur yang berlaku. Dokumen bukan bukti integrasi resmi kampus.' : 'Sampaikan dengan tenang. Ikuti prosesnya dengan jelas.'}</p>${has('reports.create') && canClass() ? `<a href="#/reports/new" class="button">${icon('plus')} Buat pengaduan</a>` : `<a href="#/${verifier ? 'verifications' : admin ? 'users' : 'account'}" class="button">${verifier ? 'Buka antrean' : admin ? 'Kelola pengguna' : 'Lihat status akun'} ${icon('arrow')}</a>`}</div><div class="banner-art" aria-hidden="true"><span class="banner-orbit"></span><span class="banner-orbit inner"></span><span class="banner-quote">“</span><span class="banner-label">setiap suara<br>punya ruang.</span></div></section>
        ${student && !ctx.canClass() ? `<div class="onboarding-note">${alertBox(ctx.user.identity_status !== 'verified' ? 'Verifikasi identitas diperlukan. Unggah KTM di Akun & verifikasi untuk membuka langkah berikutnya.' : 'Identitas sudah terverifikasi. Ajukan keanggotaan kelas dengan kode undangan dan tunggu persetujuan.')}<a href="#/account">Lengkapi akun ${icon('arrow')}</a></div>` : ''}
        <div class="metrics-grid">${metrics}</div>
        ${tasks}
        ${leader && ctx.canClass() && ctx.has('attendance.manage') ? `<section class="panel"><div class="panel-header"><div><span class="eyebrow">KEHADIRAN KELAS</span><h2>Catat pertemuan. Pantau kehadiran.</h2></div>${icon('check')}</div><p class="muted">Kelola mata kuliah, dosen, dan absensi mahasiswa. Hanya pertemuan yang sudah difinalisasi masuk ke rekap.</p><a class="button secondary" href="#/attendance">Buka absensi kelas ${icon('arrow')}</a></section>` : ''}
        ${admin ? `<div class="grid-2"><section class="panel"><div class="panel-header"><h2>Keamanan sistem</h2>${icon('shield')}</div><div class="system-stats"><div><strong>${esc(data.security?.events_last_24h ?? 0)}</strong><span>Kejadian dalam 24 jam</span></div><div><strong>${esc(data.security?.high_severity_last_7d ?? 0)}</strong><span>Prioritas tinggi dalam 7 hari</span></div></div><a class="text-link" href="#/security">Tinjau kejadian keamanan ${icon('arrow')}</a></section><section class="panel"><div class="panel-header"><h2>Batas akses administratif</h2>${icon('lock')}</div><p class="prose">${esc(data.note)}</p><a class="text-link" href="#/audit">Lihat audit aktivitas ${icon('arrow')}</a></section></div>` : verifier ? `<section class="panel">${alertBox('Tidak ada verifikasi otomatis. Setiap persetujuan dan penolakan dicatat oleh backend.')}<a class="text-link" href="#/verifications">Periksa dokumen yang menunggu ${icon('arrow')}</a></section>` : `<div class="dashboard-columns"><section class="panel"><div class="panel-header"><div><span class="eyebrow">PANTAU PERKEMBANGAN</span><h2>${student ? 'Pengaduan terbaru' : 'Perlu perhatian'}</h2></div><a class="text-link" href="#/reports">Lihat semua ${icon('arrow')}</a></div>${reportList}</section><section class="panel side-panel"><span class="eyebrow">RUANG YANG TERJAGA</span><div class="privacy-art">${icon('shield')}</div><h2>Privat bukan berarti sendiri.</h2><p>Identitas dan isi pengaduan hanya dapat diakses sesuai kewenangan serta penugasan. Isi laporan dapat mengungkap identitas secara tidak langsung.</p><a class="text-link" href="#/account">Tentang akses Anda ${icon('arrow')}</a></section></div><div class="grid-2"><section class="panel"><div class="panel-header"><h2>${student ? 'Aspirasi kelas' : 'Distribusi status'}</h2>${icon(student ? 'chat' : 'grid')}</div>${student ? aspirations : chart}</section><section class="panel guidance"><span class="eyebrow">SEBELUM MENYAMPAIKAN</span><h2>Jelas ceritanya.<br>Tepat ruangnya.</h2><ol><li><span>01</span><div><strong>Pengaduan untuk hal pribadi</strong><p>Jelaskan kejadian, pihak terlibat, dan hasil yang Anda harapkan.</p></div></li><li><span>02</span><div><strong>Aspirasi untuk ide bersama</strong><p>Hindari identitas, dokumen, atau isi pengaduan privat di ruang kelas.</p></div></li><li><span>03</span><div><strong>Ikuti proses, lengkapi informasi</strong><p>Periksa notifikasi dan jawab klarifikasi bila diminta penangan.</p></div></li></ol></section></div>`}
    `, '<button class="button secondary" id="refresh-dashboard">Muat ulang</button>');
    ctx.view.querySelector('#refresh-dashboard').addEventListener('click', ctx.refresh);
    if (leader) ctx.view.querySelector('.dashboard-columns .panel-header h2').textContent = 'Pengaduan kelas terbaru';
}

async function render() {
    if (unsavedChanges?.() && location.hash !== renderedHash) {
        if (confirmingNavigation) return;
        confirmingNavigation = true;
        const previous = routeAbort;
        const leave = await confirm({ title: 'Tinggalkan absensi yang belum disimpan?', message: 'Perubahan pada absensi ini belum tersimpan. Pilih Batal untuk kembali menyimpan, atau tinggalkan halaman untuk membuang perubahan.', confirmLabel: 'Tinggalkan halaman' });
        confirmingNavigation = false;
        if (previous !== routeAbort || previous.signal.aborted) return;
        if (!leave) {
            history.replaceState(history.state, '', `${location.pathname}${location.search}${renderedHash}`);
            return;
        }
    }
    unsavedChanges = null;
    renderedHash = location.hash;
    routeAbort.abort();
    routeAbort = new AbortController();
    const signal = routeAbort.signal;
    closeDialog(false);
    const [route, query = ''] = (location.hash.slice(1) || (token ? '/dashboard' : '/login')).split('?');
    const segments = route.split('/').filter(Boolean).map((s) => { try { return decodeURIComponent(s); } catch { return s; } });
    const params = new URLSearchParams(query);
    if (!token) {
        if (['forgot-password', 'reset-password'].includes(segments[0])) passwordRecoveryPage(segments[0], params);
        else authPage(segments[0] === 'register' ? 'register' : 'login');
        return;
    }
    if (!user) {
        root.innerHTML = `<main id="main-content" class="boot-screen">${skeleton()}</main>`;
        try { const result = await api('/auth/me', { signal }); if (signal.aborted) return; user = result.data; }
        catch (error) {
            if (signal.aborted) return;
            root.innerHTML = `<main id="main-content" class="boot-screen">${alertBox(error.message, 'error')}<button class="button" id="retry-session">Coba lagi</button><button class="button secondary" id="clear-session">Kembali ke halaman masuk</button></main>`;
            root.querySelector('#retry-session').onclick = render;
            root.querySelector('#clear-session').onclick = () => expireSession('Sesi lokal dibersihkan. Token di server mungkin masih aktif sampai kedaluwarsa.');
            return;
        }
    }
    if (['login', 'register'].includes(segments[0])) { location.hash = '/dashboard'; return; }
    const view = shell();
    const ctx = context(view, signal);
    const allowed = navigation(user).some((n) => n.path === `/${segments[0]}`) || segments[0] === 'appeals' && (has('reports.appeal_review') || has('reports.appeal_submit'));
    if (!allowed) { ctx.page('Halaman tidak tersedia', 'Tidak ada halaman yang dapat dibuka pada alamat ini untuk akun Anda.', empty('Kembali ke ruang Anda', 'Navigasi disesuaikan dengan akses akun. Backend tetap memeriksa setiap permintaan.', '<a class="button" href="#/dashboard">Ke ringkasan</a>')); return; }
    view.innerHTML = skeleton();
    try {
        if (segments[0] === 'dashboard') await dashboard(ctx);
        else if (['reports', 'appeals'].includes(segments[0])) await reportsPage(ctx, segments, params);
        else if (segments[0] === 'aspirations') await aspirationsPage(ctx, segments, params);
        else if (segments[0] === 'attendance') await attendancePage(ctx, segments, params);
        else if (['account', 'notifications'].includes(segments[0])) await accountPage(ctx, segments, params);
        else await managementPage(ctx, segments, params);
        if (signal.aborted) return;
        view.querySelector('h1')?.focus({ preventScroll: true });
        if (segments[0] === 'account') {
            const settings = document.createElement('section'); settings.className = 'panel voice-settings';
            settings.innerHTML = `<div class="panel-header"><h2>Suara peringatan keamanan</h2>${icon('volume')}</div><p class="muted">Hanya untuk penolakan akses yang dikategorikan server. Tidak ada suara untuk klik, pengiriman berhasil, atau notifikasi biasa.</p><label class="check-line"><input type="checkbox" id="security-audio"> Aktifkan suara pada sesi ini</label><p class="muted" id="audio-status">Rekaman belum tersedia. Peringatan visual tetap bekerja.</p>`;
            view.querySelector('.page-body')?.append(settings);
            settings.querySelector('input').checked = audioEnabled;
            settings.querySelector('input').addEventListener('change', (e) => { audioEnabled = e.target.checked; voice.setEnabled(audioEnabled); });
            ctx.api('/security/voice-warnings').then(({ data }) => { voice.setCatalogue(data); if (data.sounds?.some((s) => s.available)) settings.querySelector('#audio-status').textContent = 'Rekaman yang tersedia akan diputar hanya jika browser mengizinkan dan server meminta.'; }).catch(() => {});
        }
    } catch (error) {
        if (signal.aborted || error.name === 'AbortError') return;
        ctx.page('Halaman belum dapat dimuat', 'Data tidak diganti dengan contoh ketika server tidak dapat diakses.', `${alertBox(error.message || 'Terjadi kesalahan pada antarmuka.', 'error')}<button class="button" id="retry-page">Coba lagi</button>`);
        view.querySelector('#retry-page').onclick = render;
    }
}
window.addEventListener('hashchange', render);
window.addEventListener('beforeunload', (event) => { if (unsavedChanges?.()) { event.preventDefault(); event.returnValue = ''; } });
window.addEventListener('pageshow', (e) => { if (e.persisted) { token = store.get(); user = null; render(); } });
window.addEventListener('pagehide', () => routeAbort.abort());
api('/security/voice-warnings').then(({ data }) => voice.setCatalogue(data)).catch(() => {});
render();
