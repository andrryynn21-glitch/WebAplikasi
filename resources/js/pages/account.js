import { esc, icon, badge, date, field, selectField, empty, alertBox, pagination, formDataObject } from '../ui.js';

export async function accountPage(ctx, segments, params = new URLSearchParams()) {
    if (segments[0] === 'notifications') return notifications(ctx, params);

    const student = ctx.has('identity.submit');
    const [identityResult, membershipsResult, catalogResult] = await Promise.all([
        ctx.api('/identity/status'),
        ctx.api('/memberships'),
        student ? ctx.api('/classes') : Promise.resolve({ data: [] }),
    ]);
    if (!ctx.view.isConnected) return;
    const identity = identityResult.data;
    const memberships = membershipsResult.data;
    const classes = catalogResult.data;
    const latest = identity.latest_submission;
    const active = memberships.find((membership) => membership.status === 'active');
    const current = active || memberships[0];
    const verified = identity.identity_status === 'verified';
    const pending = identity.identity_status === 'pending_verification' || Boolean(identity.pending_submission_id) || latest?.status === 'pending_verification';
    const pendingCorrection = verified && pending;
    const nameLocked = verified || pending || ['class_leader', 'lecturer_coordinator'].includes(ctx.user.role);
    const canJoin = student && verified && !active;

    ctx.page('Akun & verifikasi', 'Identitas, keanggotaan, dan informasi pribadi Anda dalam satu tempat.', `
        <div class="stack">
            ${student ? `<section class="panel"><div class="panel-header"><h2>Langkah menuju ruang kelas</h2>${icon('shield')}</div><ol class="timeline">
                <li class="timeline-item"><strong>1. Akun terdaftar</strong><p class="muted">${esc(ctx.user.email)}</p>${badge(ctx.user.is_active ? 'active' : 'inactive', ctx.user.is_active ? 'Akun aktif' : 'Akun nonaktif')}</li>
                <li class="timeline-item"><strong>2. Pemeriksaan identitas</strong><p class="muted">${esc(identity.message || ctx.user.identity_message)}</p>${badge(identity.identity_status)}</li>
                <li class="timeline-item"><strong>3. Persetujuan keanggotaan kelas</strong><p class="muted">${esc(current?.class?.name || 'Belum ada keanggotaan kelas.')} ${!verified ? 'Identitas harus terverifikasi sebelum mengajukan keanggotaan.' : ''}</p>${badge(current?.status, current ? undefined : 'Belum diajukan')}</li>
            </ol>${alertBox('Pemeriksaan KTM dilakukan secara manual oleh petugas. Status terverifikasi di aplikasi ini bukan klaim validasi resmi melalui sistem atau API kampus.')}</section>` : ''}
            <div class="grid-2">
                <section class="panel"><div class="panel-header"><h2>Profil pribadi</h2>${icon('user')}</div><p><strong>${esc(ctx.user.role_name || ctx.user.role)}</strong></p><p class="muted">Email: ${esc(ctx.user.email)}<br>NIM: ${esc(ctx.user.nim || 'Belum tersedia')}</p>
                    <form id="profile-form" class="stack"><div data-errors></div>${field('name', 'Nama lengkap', { value: ctx.user.name, required: true, maxLength: 120, autocomplete: 'name', readonly: nameLocked, help: nameLocked ? 'Nama dikunci selama verifikasi atau penugasan penangan untuk menjaga identitas dan pemeriksaan konflik.' : undefined })}${field('phone', 'Nomor telepon', { value: ctx.user.phone, type: 'tel', maxLength: 24, autocomplete: 'tel' })}<p class="muted">${nameLocked ? 'Profil ini hanya dapat memperbarui telepon.' : 'Pembaruan profil hanya mendukung nama dan telepon.'} Perubahan email dan role tidak tersedia melalui formulir ini.</p><button class="button" type="submit">Simpan profil</button></form>
                </section>
                <section class="panel"><div class="panel-header"><h2>Identitas & KTM</h2>${badge(identity.identity_status)}</div><p>${esc(identity.message)}</p>
                    ${identity.history_state === 'unresolved_legacy' ? alertBox('Riwayat identitas lama belum dapat dipastikan. Data dan status yang tersimpan dipertahankan, bukan dianggap sebagai bukti persetujuan historis. Pengajuan lama memerlukan rekonsiliasi oleh petugas.', 'warning') : ''}
                    ${latest ? `<div class="stack"><p class="muted">Pengajuan #${esc(latest.id)} ${badge(latest.status, pendingCorrection ? 'Koreksi menunggu pemeriksaan' : undefined)}<br>Dikirim ${date(latest.submitted_at)}${latest.reviewed_at ? `<br>Ditinjau ${date(latest.reviewed_at)}` : ''}</p>${latest.status === 'rejected' && latest.rejection_reason ? alertBox(latest.rejection_reason, 'warning') : ''}<button type="button" class="button secondary" data-own-ktm>${icon('download')} Unduh KTM saya</button></div>` : '<p class="muted">Belum ada dokumen identitas yang diajukan.</p>'}
                    ${student && !pending ? `<details ${verified ? '' : 'open'}><summary>${verified ? 'Ajukan koreksi identitas' : 'Unggah KTM untuk diperiksa'}</summary><form id="identity-form" class="stack"><div data-errors></div>${verified ? alertBox('Koreksi tidak langsung mengganti nama atau NIM yang berlaku. Identitas terverifikasi tetap berlaku selama pemeriksaan dan jika koreksi ditolak. Hak kelas tetap mengikuti status akun dan keanggotaan.') : ''}${field('full_name', 'Nama lengkap sesuai KTM', { value: ctx.user.name, required: true, maxLength: 120 })}${field('nim', 'NIM sesuai KTM', { value: ctx.user.nim, required: true, maxLength: 32, help: 'Gunakan huruf, angka, titik, garis bawah, atau tanda hubung.' })}${field('ktm', 'Berkas KTM', { type: 'file', required: true, accept: '.jpg,.jpeg,.png,.pdf', help: 'JPG, PNG, atau PDF. Maksimal 4 MB. Berkas hanya diakses melalui izin privat.' })}<button class="button" type="submit">Kirim pengajuan identitas</button></form></details>` : pending ? alertBox(pendingCorrection ? 'Koreksi menunggu pemeriksaan; identitas dan status terverifikasi tetap berlaku. Pengajuan kedua tidak dapat dikirim selama koreksi ini diperiksa.' : 'Pengajuan identitas menunggu pemeriksaan. Anda belum terverifikasi dan fitur kelas belum aktif. Pengajuan kedua tidak dapat dikirim selama pemeriksaan.') : '<p class="muted">Akun ini tidak memiliki izin mengajukan verifikasi mahasiswa.</p>'}
                </section>
            </div>
            <section class="panel"><div class="panel-header"><h2>Ubah password</h2>${icon('lock')}</div><form id="password-form" class="stack"><div data-errors></div>${field('current_password', 'Password saat ini', { type: 'password', required: true, autocomplete: 'current-password' })}${field('password', 'Password baru', { type: 'password', required: true, minLength: 12, autocomplete: 'new-password', help: 'Minimal 12 karakter, huruf besar/kecil, angka, dan simbol.' })}${field('password_confirmation', 'Konfirmasi password baru', { type: 'password', required: true, autocomplete: 'new-password' })}<p class="muted">Setelah berhasil, sesi lain dicabut. Sesi pada tab ini tetap aktif.</p><button class="button" type="submit">Ubah password</button></form></section>
            ${student ? `<section class="panel"><div class="panel-header"><h2>Kelas & kode undangan</h2>${icon('class')}</div>${active ? alertBox(`Keanggotaan aktif: ${active.class?.name || 'kelas Anda'}. ${verified ? 'Fitur kelas mengikuti permission akun Anda.' : 'Fitur kelas belum terbuka karena identitas belum terverifikasi.'}`) : alertBox('Kode yang valid menghasilkan permintaan keanggotaan, bukan persetujuan otomatis. Tunggu keputusan pengelola kelas.')}
                ${classes.length ? `<form id="join-form" class="stack"><div data-errors></div>${selectField('catalog_class', 'Katalog kelas (referensi saja)', [{ value: '', label: 'Lihat kelas yang tersedia' }, ...classes.map((item) => ({ value: item.id, label: `${item.code} - ${item.name} / ${item.academic_term?.name || 'Periode tidak tersedia'}` }))], { value: '' })}<p class="muted">Pilihan katalog tidak dikirim sebagai kelas tujuan. Kode undangan menentukan kelas yang sebenarnya; pastikan kode sesuai dengan pengelola kelas Anda.</p>${canJoin ? `${field('invitation_code', 'Kode undangan dari pengelola kelas', { required: true, maxLength: 64, autocomplete: 'off' })}<button class="button" type="submit">Ajukan keanggotaan</button>` : `<p class="muted">${active ? 'Anda sudah memiliki keanggotaan aktif.' : 'Selesaikan verifikasi identitas sebelum mengirim kode undangan.'}</p>`}</form>` : empty('Katalog kelas masih kosong', 'Operator perlu menyiapkan kelas dan periode sebelum proses bergabung.')}
            </section>` : ''}
            <section class="panel"><div class="panel-header"><h2>Riwayat keanggotaan saya</h2></div>${memberships.length ? `<div class="content-list">${memberships.map((membership) => `<article class="list-item"><div><strong>${esc(membership.class?.name || 'Kelas tidak tersedia')}</strong><p class="muted">${esc(membership.class?.code)} / Diajukan ${date(membership.created_at)}</p>${membership.status === 'rejected' && membership.decision_note ? `<p>${esc(membership.decision_note)}</p>` : ''}</div>${badge(membership.status)}</article>`).join('')}</div>` : empty('Belum ada pengajuan keanggotaan', student ? 'Setelah identitas terverifikasi, gunakan kode undangan yang diberikan pengelola kelas.' : 'Akun petugas tidak memerlukan keanggotaan mahasiswa untuk kewenangan yang ditugaskan.')}</section>
        </div>`, '<button class="button secondary" type="button" data-refresh-account>Muat ulang status</button>');

    ctx.view.querySelector('[data-refresh-account]')?.addEventListener('click', () => ctx.action(ctx.view.querySelector('[data-refresh-account]'), async () => { await ctx.refreshUser(); if (ctx.view.isConnected) ctx.refresh(); }));
    const profileForm = ctx.view.querySelector('#profile-form');
    ctx.form(profileForm, async (form) => {
        if (!form.reportValidity()) return;
        const values = formDataObject(form);
        if (!nameLocked && !values.name.trim()) throw new Error('Nama lengkap tidak boleh kosong.');
        await ctx.api('/profile', { method: 'PATCH', body: { ...(!nameLocked ? { name: values.name.trim() } : {}), phone: values.phone.trim() || null } });
        if (!ctx.view.isConnected) return;
        await ctx.refreshUser();
        if (!ctx.view.isConnected) return;
        ctx.toast('Profil berhasil diperbarui.');
        ctx.refresh();
    });
    const passwordForm = ctx.view.querySelector('#password-form');
    ctx.form(passwordForm, async (form) => {
        const result = await ctx.api('/auth/password/change', { method: 'POST', body: formDataObject(form) });
        if (!ctx.view.isConnected) return;
        form.reset();
        ctx.toast(result.message);
    });
    const identityForm = ctx.view.querySelector('#identity-form');
    if (identityForm) ctx.form(identityForm, async (form) => {
        if (!form.reportValidity()) return;
        const body = new FormData(form);
        if (!String(body.get('full_name')).trim()) throw new Error('Nama lengkap sesuai KTM tidak boleh kosong.');
        if (!/^[A-Za-z0-9._-]+$/.test(String(body.get('nim')))) throw new Error('Format NIM hanya mendukung huruf, angka, titik, garis bawah, dan tanda hubung.');
        if (body.get('ktm').size > 4 * 1024 * 1024) throw new Error('Ukuran KTM maksimal 4 MB.');
        if (verified && !await ctx.confirm({ title: 'Ajukan koreksi identitas?', message: 'Nama dan NIM yang berlaku tidak berubah sampai koreksi disetujui. Status terverifikasi tetap berlaku selama pemeriksaan atau jika koreksi ditolak.', confirmLabel: 'Kirim koreksi' })) return;
        if (!ctx.view.isConnected) return;
        await ctx.api('/identity/submissions', { method: 'POST', body });
        if (!ctx.view.isConnected) return;
        form.reset();
        await ctx.refreshUser();
        if (!ctx.view.isConnected) return;
        ctx.toast('KTM terkirim. Menunggu pemeriksaan manual oleh petugas.');
        ctx.refresh();
    });
    const ownDownload = ctx.view.querySelector('[data-own-ktm]');
    ownDownload?.addEventListener('click', () => ctx.action(ownDownload, () => ctx.download(`/identity-verifications/${encodeURIComponent(latest.id)}/ktm`, `ktm-${latest.id}`)));
    const joinForm = ctx.view.querySelector('#join-form');
    if (joinForm) ctx.form(joinForm, async (form) => {
        if (!canJoin || !form.reportValidity()) return;
        const invitation_code = String(new FormData(form).get('invitation_code')).trim();
        if (!invitation_code) throw new Error('Isi kode undangan yang diberikan pengelola kelas.');
        const result = await ctx.api('/memberships/join', { method: 'POST', body: { invitation_code } });
        if (!ctx.view.isConnected) return;
        form.reset();
        await ctx.refreshUser();
        if (!ctx.view.isConnected) return;
        ctx.toast(`Permintaan untuk ${result.data.class?.name || 'kelas tujuan kode undangan'} terkirim. Menunggu persetujuan.`);
        ctx.refresh();
    });
}

function notificationRoute(ctx, notification) {
    const data = notification.data || {};
    const numeric = (value) => /^[1-9]\d*$/.test(String(value ?? ''));
    if (notification.type === 'identity_verification' || notification.type === 'membership_decision') return '/account';
    if (notification.type === 'security_notice' && ctx.has('security.view')) return '/security';
    if (['aspiration_comment', 'content_moderated'].includes(notification.type) && numeric(data.aspiration_id) && (ctx.canClass() || ctx.user.role === 'lecturer_coordinator')) return `/aspirations/${data.aspiration_id}`;
    if (['appeal_submitted', 'appeal_decided'].includes(notification.type) && numeric(data.appeal_id) && (ctx.has('reports.appeal_review') || ctx.has('reports.appeal_submit'))) return `/appeals/${data.appeal_id}${notification.type === 'appeal_submitted' && ctx.has('reports.appeal_review') ? '?mode=assign' : ''}`;
    if (['report_status_changed', 'clarification_requested', 'report_assigned', 'appeal_submitted', 'appeal_decided'].includes(notification.type) && /^PGD-[A-Za-z0-9]+$/.test(String(data.report_ref ?? '')) && ['reports.view.own', 'reports.view.class', 'reports.view.assigned'].some((permission) => ctx.has(permission))) return `/reports/${encodeURIComponent(data.report_ref)}`;
    return null;
}

async function notifications(ctx, params) {
    const query = new URLSearchParams();
    query.set('page', /^[1-9]\d*$/.test(params.get('page') || '') ? params.get('page') : '1');
    if (params.get('unread_only') === '1') query.set('unread_only', '1');
    const { data, meta } = await ctx.api(`/notifications?${query}`);
    if (!ctx.view.isConnected) return;
    ctx.page('Notifikasi', 'Pembaruan yang relevan, hanya untuk akun dan ruang akses Anda.', `
        <section class="panel"><div class="panel-header"><h2>${esc(meta?.unread_count ?? 0)} belum dibaca</h2><div class="toolbar"><a class="button ${query.has('unread_only') ? 'secondary' : ''}" href="#/notifications" ${query.has('unread_only') ? '' : 'aria-current="page"'}>Semua</a><a class="button ${query.has('unread_only') ? '' : 'secondary'}" href="#/notifications?unread_only=1" ${query.has('unread_only') ? 'aria-current="page"' : ''}>Belum dibaca</a></div></div>
            ${data.length ? `<div class="content-list">${data.map((item) => {
                const route = notificationRoute(ctx, item);
                return `<article class="list-item"><div class="stack"><div class="toolbar"><strong>${esc(item.title)}</strong>${badge(item.read_at ? 'read' : 'pending', item.read_at ? 'Sudah dibaca' : 'Belum dibaca')}${['high', 'critical'].includes(item.severity) ? badge(item.severity) : ''}</div>${item.message ? `<p class="prose">${esc(item.message)}</p>` : ''}<small class="muted">${date(item.created_at)}</small><div class="toolbar">${route ? `<a class="button secondary" href="#${esc(route)}">Buka terkait ${icon('arrow')}</a>` : ''}${!item.read_at ? `<button class="button ghost" type="button" data-read="${esc(item.id)}">Tandai dibaca</button>` : ''}</div></div></article>`;
            }).join('')}</div>` : empty('Tidak ada notifikasi di sini', query.has('unread_only') ? 'Semua pembaruan yang terlihat sudah dibaca.' : 'Pembaruan akan muncul saat ada aktivitas yang relevan.')}
            ${pagination(meta?.pagination, '/notifications', query)}
        </section><p class="muted">Notifikasi tidak memberikan izin tambahan untuk membuka dokumen atau pengaduan. Akses diperiksa kembali oleh server.</p>`, `<button class="button secondary" type="button" data-read-all ${Number(meta?.unread_count) > 0 ? '' : 'disabled'}>Tandai semua dibaca</button>`);
    ctx.view.querySelectorAll('[data-read]').forEach((button) => button.addEventListener('click', () => ctx.action(button, async () => {
        await ctx.api(`/notifications/${encodeURIComponent(button.dataset.read)}/read`, { method: 'POST' });
        if (!ctx.view.isConnected) return;
        ctx.refresh();
    })));
    const all = ctx.view.querySelector('[data-read-all]');
    all?.addEventListener('click', () => ctx.action(all, async () => {
        await ctx.api('/notifications/read-all', { method: 'POST' });
        if (!ctx.view.isConnected) return;
        ctx.toast('Semua notifikasi yang terlihat telah ditandai dibaca.');
        ctx.refresh();
    }));
}
