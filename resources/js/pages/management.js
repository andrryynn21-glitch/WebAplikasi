import { esc, icon, badge, date, field, textarea, selectField, empty, alertBox, pagination, formDataObject } from '../ui.js';

const roles = [
    { value: 'student', label: 'Mahasiswa' },
    { value: 'class_leader', label: 'Ketua kelas' },
    { value: 'lecturer_coordinator', label: 'Dosen koordinator' },
    { value: 'identity_verifier', label: 'Petugas verifikasi identitas' },
    { value: 'super_admin', label: 'Super Admin' },
];
const identityStatuses = [
    { value: 'unverified', label: 'Belum diverifikasi' },
    { value: 'pending_verification', label: 'Menunggu verifikasi' },
    { value: 'verified', label: 'Terverifikasi' },
    { value: 'rejected', label: 'Ditolak' },
];
const numericId = (value) => /^[1-9]\d*$/.test(String(value ?? ''));
const apiId = (value) => encodeURIComponent(String(value));

export async function managementPage(ctx, segments, params = new URLSearchParams()) {
    const permissions = { users: ['users.manage'], classes: ['classes.manage', 'memberships.review', 'classes.invite'], verifications: ['identity.verify'], security: ['security.view'], audit: ['users.manage', 'audit.view'] };
    const required = permissions[segments[0]];
    const allowed = required && (segments[0] === 'classes' ? required.some((permission) => ctx.has(permission)) : required.every((permission) => ctx.has(permission)));
    if (!allowed) {
        ctx.page('Akses tidak tersedia', 'Halaman ini memerlukan kewenangan yang sesuai.', empty('Tidak ada akses untuk akun ini', 'Role tidak otomatis memberi akses ke data privat. Gunakan navigasi sesuai izin Anda.', '<a class="button" href="#/dashboard">Kembali ke ringkasan</a>'));
        return;
    }
    if (segments[0] === 'users') return usersPage(ctx, params);
    if (segments[0] === 'classes') return classesPage(ctx, segments, params);
    if (segments[0] === 'verifications') return verificationsPage(ctx, params);
    return logsPage(ctx, segments[0], params);
}

function listQuery(params, keys) {
    const query = new URLSearchParams();
    for (const key of keys) if (params.get(key)) query.set(key, params.get(key));
    query.set('page', numericId(params.get('page')) ? params.get('page') : '1');
    return query;
}

function bindFilter(ctx, route) {
    const form = ctx.view.querySelector('[data-filter]');
    if (!form) return;
    ctx.form(form, async (element) => {
        if (!element.reportValidity()) return;
        const query = new URLSearchParams();
        for (const [key, value] of new FormData(element)) if (String(value).trim()) query.set(key, String(value).trim());
        ctx.navigate(`${route}${query.size ? `?${query}` : ''}`);
    });
}

async function usersPage(ctx, params) {
    const query = listQuery(params, ['search', 'role', 'identity_status']);
    const { data, meta } = await ctx.api(`/admin/users?${query}`);
    if (!ctx.view.isConnected) return;
    ctx.page('Pengguna & role', 'Kelola akses administratif tanpa membuka isi pengaduan atau dokumen KTM.', `
        <div class="stack"><section class="panel"><form data-filter class="form-grid">${field('search', 'Cari nama, email, atau NIM', { value: query.get('search'), maxLength: 255, placeholder: 'Cari pengguna...' })}${selectField('role', 'Role', [{ value: '', label: 'Semua role' }, ...roles], { value: query.get('role') || '' })}${selectField('identity_status', 'Status identitas', [{ value: '', label: 'Semua status' }, ...identityStatuses], { value: query.get('identity_status') || '' })}<div class="toolbar"><button class="button" type="submit">Terapkan filter</button><a class="button ghost" href="#/users">Reset</a></div></form></section>
        <section class="panel"><div class="panel-header"><h2>Daftar pengguna</h2><span class="muted">${esc(meta?.pagination?.total ?? data.length)} hasil</span></div>${data.length ? `<div class="content-list">${data.map((user) => `<article class="list-item"><div class="stack"><div class="toolbar"><strong>${esc(user.name)}</strong>${badge(user.is_active ? 'active' : 'inactive', user.is_active ? 'Aktif' : 'Nonaktif')}${badge(user.identity_status)}</div><p class="muted">ID ${esc(user.id)} / ${esc(user.email)}<br>NIM: ${esc(user.nim || 'Belum tersedia')} / ${esc(user.role_name || user.role)}${user.membership?.class_id ? `<br>Keanggotaan ${esc(user.membership.status)} / ID kelas ${esc(user.membership.class_id)}` : ''}</p>
            ${String(user.id) === String(ctx.user.id) ? '<p class="muted">Ini akun Anda. Perubahan role dan suspensi diri sendiri tidak diizinkan.</p>' : `<details><summary>Kelola akses ${esc(user.name)}</summary><div class="stack">${ctx.has('roles.manage') ? `<form class="stack" data-role-user="${esc(user.id)}"><div data-errors></div>${selectField('role', 'Role baru (pilih secara eksplisit)', [{ value: '', label: 'Pilih role baru' }, ...roles.filter((role) => role.value !== user.role)], { required: true, value: '' })}${textarea('reason', 'Alasan perubahan role', { required: true, minLength: 10, maxLength: 500 })}<p class="muted">Role baru tidak menggantikan penugasan kelas atau laporan. Super Admin tidak otomatis berhak membaca pengaduan privat atau KTM.</p><button class="button secondary" type="submit">Tinjau perubahan role</button></form>` : ''}${user.is_active ? `<form class="stack" data-suspend-user="${esc(user.id)}"><div data-errors></div>${textarea('reason', 'Alasan menonaktifkan akun', { required: true, minLength: 5, maxLength: 500 })}<button class="button danger" type="submit">Nonaktifkan akun</button></form>` : `<button class="button secondary" type="button" data-reactivate-user="${esc(user.id)}">Aktifkan kembali akun</button>`}</div></details>`}
        </div></article>`).join('')}</div>` : empty('Pengguna tidak ditemukan', 'Coba ubah kata pencarian atau filter role dan identitas.')}${pagination(meta?.pagination, '/users', query)}</section></div>`);
    bindFilter(ctx, '/users');
    const findUser = (id) => data.find((user) => String(user.id) === id);
    ctx.view.querySelectorAll('[data-role-user]').forEach((form) => ctx.form(form, async (element) => {
        if (!element.reportValidity()) return;
        const user = findUser(element.dataset.roleUser);
        const body = formDataObject(element);
        body.reason = body.reason.trim();
        if (body.reason.length < 10) throw new Error('Alasan perubahan role minimal 10 karakter.');
        if (!roles.some((role) => role.value === body.role) || body.role === user.role) throw new Error('Pilih role baru yang berbeda.');
        if (!await ctx.confirm({ title: 'Ubah role pengguna?', message: `${user.name}: ${user.role_name || user.role} menjadi ${roles.find((role) => role.value === body.role).label}. Alasan: ${body.reason}`, confirmLabel: 'Ubah role' })) return;
        if (!ctx.view.isConnected) return;
        await ctx.api(`/admin/users/${apiId(user.id)}/role`, { method: 'POST', body });
        if (!ctx.view.isConnected) return;
        ctx.toast('Perubahan role tercatat.'); ctx.refresh();
    }));
    ctx.view.querySelectorAll('[data-suspend-user]').forEach((form) => ctx.form(form, async (element) => {
        if (!element.reportValidity()) return;
        const user = findUser(element.dataset.suspendUser);
        const reason = String(new FormData(element).get('reason')).trim();
        if (reason.length < 5) throw new Error('Alasan suspensi minimal 5 karakter.');
        if (!await ctx.confirm({ title: 'Nonaktifkan akun?', message: `Akun ${user.name} akan dinonaktifkan dan seluruh token aksesnya dicabut. Alasan: ${reason}`, confirmLabel: 'Nonaktifkan' })) return;
        if (!ctx.view.isConnected) return;
        await ctx.api(`/admin/users/${apiId(user.id)}/suspend`, { method: 'POST', body: { reason } });
        if (!ctx.view.isConnected) return;
        ctx.toast('Akun dinonaktifkan.'); ctx.refresh();
    }));
    ctx.view.querySelectorAll('[data-reactivate-user]').forEach((button) => button.addEventListener('click', () => ctx.action(button, async () => {
        const user = findUser(button.dataset.reactivateUser);
        if (!await ctx.confirm({ title: 'Aktifkan kembali akun?', message: `Akun ${user.name} dapat masuk kembali dengan role yang dimilikinya.`, confirmLabel: 'Aktifkan akun' })) return;
        if (!ctx.view.isConnected) return;
        await ctx.api(`/admin/users/${apiId(user.id)}/reactivate`, { method: 'POST' });
        if (!ctx.view.isConnected) return;
        ctx.toast('Akun diaktifkan kembali.'); ctx.refresh();
    })));
}

async function classesPage(ctx, segments, params) {
    const admin = ctx.has('classes.manage');
    const [catalog, termResult] = await Promise.all([
        ctx.api(admin ? '/classes' : '/dashboard'),
        admin ? ctx.api('/academic-terms') : Promise.resolve({ data: [] }),
    ]);
    if (!ctx.view.isConnected) return;
    const classes = admin ? catalog.data : (catalog.data.classes || []);
    const terms = termResult.data;
    const create = segments[1] === 'new' && admin;
    const requestedId = segments[1] || params.get('class_id');
    const selected = !create && (requestedId ? classes.find((item) => String(item.id) === requestedId) : classes[0]);
    if (requestedId && !create && !selected) {
        ctx.page('Kelas tidak tersedia', 'Kelas tidak termasuk katalog atau cakupan akses Anda saat ini.', empty('Tidak ada data kelas yang dimuat', 'Daftar anggota tidak diminta untuk kelas di luar cakupan.', '<a class="button" href="#/classes">Kembali ke daftar kelas</a>'));
        return;
    }
    const query = listQuery(params, []);
    const pending = selected && ctx.has('memberships.review') ? await ctx.api(`/classes/${apiId(selected.id)}/memberships/pending?${query}`) : { data: [], meta: {} };
    if (!ctx.view.isConnected) return;
    const classForm = (item = {}) => `<form id="class-form" class="stack"><div data-errors></div><div class="form-grid">${selectField('academic_term_id', 'Periode akademik', [{ value: '', label: 'Pilih periode' }, ...terms.map((term) => ({ value: term.id, label: `${term.name} (${term.code})${term.is_active ? '' : ' - nonaktif'}` }))], { value: item.academic_term?.id || '', required: true })}${field('code', 'Kode kelas', { value: item.code, required: true, maxLength: 64 })}${field('name', 'Nama kelas', { value: item.name, required: true, maxLength: 120 })}${field('program_study', 'Program studi', { value: item.program_study, required: true, maxLength: 128 })}${field('semester', 'Semester', { value: item.semester, type: 'number', required: true, min: 1, max: 14 })}</div><button class="button" type="submit">${create ? 'Buat kelas' : 'Simpan perubahan kelas'}</button></form>`;
    ctx.page(admin ? 'Kelola kelas' : 'Kelas dalam penugasan', admin ? 'Katalog, penugasan pengelola, undangan, dan permintaan keanggotaan.' : 'Kelola undangan dan keanggotaan hanya dalam cakupan kelas Anda.', `
        <div class="stack"><section class="panel"><div class="panel-header"><h2>${admin ? 'Katalog kelas aktif' : 'Kelas yang dapat dikelola'}</h2></div>${classes.length ? `<div class="toolbar">${classes.map((item) => `<a class="button ${selected?.id === item.id ? '' : 'secondary'}" href="#/classes/${esc(apiId(item.id))}" ${selected?.id === item.id ? 'aria-current="page"' : ''}>${esc(item.code)} / ${esc(item.name)}</a>`).join('')}</div>` : empty('Belum ada kelas tersedia', admin ? 'Siapkan kelas menggunakan periode akademik yang telah dibuat operator.' : 'Tidak ada kelas dalam respons dashboard. Penugasan aktif diperlukan untuk pengelolaan.')}</section>
        ${create ? `<section class="panel"><div class="panel-header"><h2>Buat kelas</h2></div>${terms.length ? classForm() : alertBox('Katalog periode masih kosong. Operator perlu menyiapkannya; API pembuatan periode belum tersedia.', 'warning')}</section>` : selected ? `<section class="panel"><div class="panel-header"><div><span class="eyebrow">${esc(selected.code)}</span><h2>${esc(selected.name)}</h2></div></div>${admin ? `<p class="muted">${esc(selected.program_study)} / Semester ${esc(selected.semester)} / ${esc(selected.academic_term?.name || 'Periode tidak tersedia')}</p><details><summary>Ubah informasi kelas</summary>${classForm(selected)}</details>` : '<p class="muted">Cakupan berasal dari dashboard akun. Backend tetap memeriksa penugasan aktif pada setiap tindakan.</p>'}</section>
        ${ctx.has('classes.invite') ? `<section class="panel"><div class="panel-header"><h2>Terbitkan kode undangan</h2>${icon('class')}</div><form id="invitation-form" class="stack"><div data-errors></div><div class="form-grid">${field('expires_in_days', 'Berlaku selama (hari)', { type: 'number', min: 1, max: 365, help: 'Opsional, 1 sampai 365 hari. Kosong mengikuti kebijakan server.' })}${field('max_uses', 'Batas penggunaan', { type: 'number', min: 1, max: 1000, help: 'Opsional, 1 sampai 1.000 penggunaan.' })}</div><button class="button secondary" type="submit">Buat kode undangan</button></form><div id="invitation-result" class="stack" aria-live="polite"></div><p class="muted">Kode hanya ditampilkan pada halaman ini dan tidak disimpan di penyimpanan browser. Bagikan secara terbatas. API belum menyediakan daftar atau pencabutan undangan.</p></section>` : ''}
        ${ctx.has('memberships.review') ? `<section class="panel"><div class="panel-header"><h2>Permintaan keanggotaan</h2><span class="muted">${esc(pending.meta?.pagination?.total ?? pending.data.length)} menunggu</span></div>${pending.data.length ? `<div class="content-list">${pending.data.map((membership) => `<article class="list-item"><div class="stack"><strong>${esc(membership.user?.name || 'Pengguna tidak tersedia')}</strong><p class="muted">${esc(membership.user?.email)} / NIM ${esc(membership.user?.nim || 'Belum tersedia')}<br>Diajukan ${date(membership.created_at)}</p>${String(membership.user?.id) === String(ctx.user.id) ? '<p class="muted">Anda tidak dapat memutuskan pengajuan sendiri.</p>' : `<form data-membership="${esc(membership.id)}" class="stack"><div data-errors></div>${selectField('decision', 'Keputusan keanggotaan', [{ value: '', label: 'Pilih keputusan' }, { value: 'approve', label: 'Setujui' }, { value: 'reject', label: 'Tolak' }], { required: true, value: '' })}${textarea('note', 'Catatan keputusan', { maxLength: 500, help: 'Wajib diisi untuk penolakan; alasan penolakan dapat dibaca pemohon.' })}<button class="button secondary" type="submit">Tinjau keputusan</button></form>`}</div></article>`).join('')}</div>` : empty('Tidak ada pengajuan menunggu', 'Pengajuan dengan kode undangan yang valid akan muncul di sini.')}${pagination(pending.meta?.pagination, `/classes/${selected.id}`, query)}</section>` : ''}
        ${admin ? `<section class="panel"><div class="panel-header"><h2>Penugasan pengelola</h2></div>${alertBox('Daftar penugasan aktif belum tersedia melalui API. Gunakan ID pengguna yang telah dikonfirmasi pada halaman Pengguna. Penetapan ketua menggantikan ketua aktif sebelumnya dan dapat mengubah role mahasiswa menjadi ketua.')}${ctx.has('users.manage') ? '<a class="button ghost" href="#/users">Lihat ID pengguna</a>' : ''}<div class="grid-2"><form id="leader-form" class="stack"><h3>Tetapkan ketua kelas</h3><div data-errors></div>${field('user_id', 'ID mahasiswa atau ketua', { type: 'number', required: true, min: 1, help: 'Harus aktif, terverifikasi, dan memiliki keanggotaan aktif di kelas ini.' })}<button class="button secondary" type="submit">Tetapkan ketua</button><div data-assignment-result aria-live="polite"></div></form><form id="lecturer-form" class="stack"><h3>Tugaskan dosen</h3><div data-errors></div>${field('lecturer_id', 'ID dosen koordinator', { type: 'number', required: true, min: 1 })}${selectField('role', 'Peran penugasan dosen', [{ value: 'coordinator', label: 'Koordinator' }, { value: 'assistant', label: 'Asisten' }], { value: 'coordinator', required: true })}<button class="button secondary" type="submit">Tugaskan dosen</button><div data-assignment-result aria-live="polite"></div></form></div><details><summary>Cabut penugasan ketua berdasarkan ID</summary><form id="revoke-leader-form" class="stack"><div data-errors></div>${field('assignment_id', 'ID penugasan ketua (bukan ID pengguna)', { type: 'number', min: 1, required: true, help: 'Gunakan ID hasil penetapan atau referensi audit yang sudah diverifikasi. API tidak menyediakan daftar penugasan.' })}${textarea('reason', 'Alasan pencabutan', { minLength: 5, maxLength: 500, required: true })}<button class="button danger" type="submit">Cabut penugasan ketua</button></form></details></section>` : ''}` : ''}
        ${admin ? alertBox('Batas operasional: API mendukung pembuatan dan pembaruan kelas, bukan hapus/nonaktifkan kelas. Pembuatan periode, pencabutan dosen, anggota, dan undangan, serta perubahan konfigurasi belum memiliki endpoint. Tidak ada tindakan simulasi untuk fitur tersebut.') : ''}</div>`, admin ? '<a class="button" href="#/classes/new">Buat kelas</a>' : '');

    const editForm = ctx.view.querySelector('#class-form');
    if (editForm) ctx.form(editForm, async (form) => {
        if (!form.reportValidity()) return;
        const body = formDataObject(form);
        body.academic_term_id = Number(body.academic_term_id); body.semester = Number(body.semester);
        body.code = body.code.trim(); body.name = body.name.trim(); body.program_study = body.program_study.trim();
        if (!body.code || !body.name || !body.program_study) throw new Error('Kode, nama kelas, dan program studi tidak boleh kosong.');
        const result = await ctx.api(create ? '/admin/classes' : `/admin/classes/${apiId(selected.id)}`, { method: create ? 'POST' : 'PATCH', body });
        if (!ctx.view.isConnected) return;
        ctx.toast(create ? 'Kelas dibuat. Terbitkan kode undangan untuk proses keanggotaan.' : 'Informasi kelas diperbarui.');
        if (create) ctx.navigate(`/classes/${result.data.id}`); else ctx.refresh();
    });
    const invitationForm = ctx.view.querySelector('#invitation-form');
    if (invitationForm) ctx.form(invitationForm, async (form) => {
        if (!form.reportValidity()) return;
        const values = formDataObject(form);
        const body = {};
        for (const name of ['expires_in_days', 'max_uses']) if (values[name]) body[name] = Number(values[name]);
        const { data } = await ctx.api(`/classes/${apiId(selected.id)}/invitations`, { method: 'POST', body });
        if (!ctx.view.isConnected) return;
        const result = ctx.view.querySelector('#invitation-result');
        result.innerHTML = `<div class="callout"><div><strong>Kode undangan untuk ${esc(selected.name)}</strong><p><code>${esc(data.code)}</code></p><p>Berlaku sampai: ${data.expires_at ? date(data.expires_at) : 'Tidak dibatasi server'}<br>Batas penggunaan: ${esc(data.max_uses ?? 'Tidak dibatasi server')}</p><button class="button ghost" type="button" data-hide-code>Sembunyikan kode</button></div></div>`;
        result.querySelector('[data-hide-code]').addEventListener('click', () => { result.innerHTML = ''; });
        ctx.toast('Kode undangan diterbitkan. Kode tidak disimpan di browser.');
    });
    ctx.view.querySelectorAll('[data-membership]').forEach((form) => {
        form.elements.namedItem('decision').addEventListener('change', () => { form.elements.namedItem('note').required = form.elements.namedItem('decision').value === 'reject'; });
        ctx.form(form, async (element) => {
            if (!element.reportValidity()) return;
            const values = formDataObject(element);
            const body = { decision: values.decision, note: values.note.trim() || null };
            if (body.decision === 'reject' && !body.note) throw new Error('Isi alasan penolakan keanggotaan.');
            const membership = pending.data.find((item) => String(item.id) === element.dataset.membership);
            if (!await ctx.confirm({ title: body.decision === 'approve' ? 'Setujui keanggotaan?' : 'Tolak keanggotaan?', message: `${membership.user?.name || 'Pengguna'} pada kelas ${selected.name}.${body.note ? ` Catatan: ${body.note}` : ''}`, confirmLabel: 'Simpan keputusan' })) return;
            if (!ctx.view.isConnected) return;
            await ctx.api(`/memberships/${apiId(membership.id)}/decide`, { method: 'POST', body });
            if (!ctx.view.isConnected) return;
            ctx.toast('Keputusan keanggotaan tercatat.'); ctx.refresh();
        });
    });
    for (const type of ['leader', 'lecturer']) {
        const form = ctx.view.querySelector(`#${type}-form`);
        if (form) ctx.form(form, async (element) => {
            if (!element.reportValidity()) return;
            const values = formDataObject(element);
            const id = values[type === 'leader' ? 'user_id' : 'lecturer_id'];
            if (!numericId(id)) throw new Error('ID pengguna harus berupa bilangan bulat positif.');
            if (!await ctx.confirm({ title: type === 'leader' ? 'Tetapkan ketua kelas?' : 'Tugaskan dosen?', message: `Pengguna ID ${id} akan ditugaskan pada ${selected.name}. ${type === 'leader' ? 'Ketua lama akan digantikan dan role mahasiswa dapat berubah menjadi ketua.' : 'Pastikan pengguna merupakan dosen koordinator aktif dan belum memiliki penugasan yang sama.'}`, confirmLabel: 'Tetapkan penugasan' })) return;
            if (!ctx.view.isConnected) return;
            const body = type === 'leader' ? { user_id: Number(id) } : { lecturer_id: Number(id), role: values.role };
            const result = await ctx.api(`/admin/classes/${apiId(selected.id)}/${type === 'leader' ? 'leader' : 'lecturers'}`, { method: 'POST', body });
            if (!ctx.view.isConnected) return;
            element.querySelector('[data-assignment-result]').innerHTML = alertBox(`Penugasan berhasil. ID penugasan: ${result.data.id}. ID kelas: ${result.data.class_id}.`);
            ctx.toast('Penugasan tercatat.');
        });
    }
    const revoke = ctx.view.querySelector('#revoke-leader-form');
    if (revoke) ctx.form(revoke, async (form) => {
        if (!form.reportValidity()) return;
        const values = formDataObject(form);
        if (!numericId(values.assignment_id)) throw new Error('Isi ID penugasan yang valid, bukan ID pengguna.');
        const reason = values.reason.trim();
        if (reason.length < 5) throw new Error('Alasan pencabutan minimal 5 karakter.');
        if (!await ctx.confirm({ title: 'Cabut penugasan ketua?', message: `Penugasan ID ${values.assignment_id} akan dicabut. ID ini tidak otomatis dibatasi pada kelas yang sedang ditampilkan; pastikan referensi audit benar. Alasan: ${reason}`, confirmLabel: 'Cabut penugasan' })) return;
        if (!ctx.view.isConnected) return;
        await ctx.api(`/admin/class-leaders/${apiId(values.assignment_id)}`, { method: 'DELETE', body: { reason } });
        if (!ctx.view.isConnected) return;
        form.reset(); ctx.toast('Penugasan ketua dicabut.');
    });
}

async function verificationsPage(ctx, params) {
    const query = listQuery(params, ['status']);
    if (!query.has('status')) query.set('status', 'pending_verification');
    const { data, meta } = await ctx.api(`/identity-verifications?${query}`);
    if (!ctx.view.isConnected) return;
    ctx.page('Verifikasi identitas', 'Periksa dokumen secara manual. Keputusan Anda dicatat oleh sistem.', `
        <div class="stack">${alertBox('Verifikasi ini merupakan pemeriksaan manual dalam aplikasi, bukan integrasi atau pernyataan validasi resmi melalui sistem kampus. Unduh KTM hanya untuk keperluan pemeriksaan dan jangan membagikannya.')}
        <section class="panel"><form data-filter class="toolbar">${selectField('status', 'Status pengajuan', identityStatuses.filter((item) => item.value !== 'unverified'), { value: query.get('status') })}<button class="button secondary" type="submit">Tampilkan</button></form></section>
        <section class="panel"><div class="panel-header"><h2>Dokumen pengajuan</h2><span class="muted">${esc(meta?.pagination?.total ?? data.length)} hasil</span></div>${data.length ? `<div class="content-list">${data.map((item) => `<article class="list-item"><div class="stack"><div class="toolbar"><strong>${esc(item.full_name)}</strong>${badge(item.status)}</div><p class="muted">NIM pengajuan: ${esc(item.nim)}<br>Akun: ${esc(item.user?.name || 'Tidak tersedia')} / ${esc(item.user?.email)}<br>NIM akun: ${esc(item.user?.nim || 'Belum tersedia')}<br>Pengajuan #${esc(item.id)} / ${date(item.submitted_at)}</p><button class="button secondary" type="button" data-ktm="${esc(item.id)}">${icon('download')} Unduh KTM untuk diperiksa</button>${item.status === 'pending_verification' && item.user && String(item.user.id) !== String(ctx.user.id) ? `<div class="toolbar"><button class="button" type="button" data-approve-verification="${esc(item.id)}">Setujui identitas</button></div><details><summary>Tolak dengan alasan</summary><form class="stack" data-reject-verification="${esc(item.id)}"><div data-errors></div>${textarea('reason', 'Alasan penolakan untuk pemohon', { required: true, minLength: 10, maxLength: 500, help: 'Berikan alasan yang aman dan dapat ditindaklanjuti. Jangan menyertakan data mahasiswa lain.' })}<button class="button danger" type="submit">Tolak pengajuan</button></form></details>` : `<p class="muted">${String(item.user?.id) === String(ctx.user.id) ? 'Anda tidak dapat memutuskan pengajuan sendiri.' : 'Tidak ada keputusan baru untuk pengajuan ini.'}</p>`}</div></article>`).join('')}</div>` : empty('Tidak ada pengajuan pada status ini', 'Antrean menggunakan data server. Muat ulang untuk melihat pengajuan yang baru masuk.')}${pagination(meta?.pagination, '/verifications', query)}</section></div>`, '<button class="button secondary" type="button" data-refresh-verifications>Muat ulang antrean</button>');
    bindFilter(ctx, '/verifications');
    ctx.view.querySelector('[data-refresh-verifications]')?.addEventListener('click', ctx.refresh);
    ctx.view.querySelectorAll('[data-ktm]').forEach((button) => button.addEventListener('click', () => ctx.action(button, () => ctx.download(`/identity-verifications/${apiId(button.dataset.ktm)}/ktm`, `ktm-${button.dataset.ktm}`))));
    ctx.view.querySelectorAll('[data-approve-verification]').forEach((button) => button.addEventListener('click', () => ctx.action(button, async () => {
        const item = data.find((entry) => String(entry.id) === button.dataset.approveVerification);
        if (!await ctx.confirm({ title: 'Setujui identitas?', message: `Pastikan KTM ${item.full_name}, NIM ${item.nim}, sudah diperiksa sesuai prosedur. Persetujuan identitas tidak otomatis menyetujui keanggotaan kelas.`, confirmLabel: 'Setujui identitas' })) return;
        if (!ctx.view.isConnected) return;
        await ctx.api(`/identity-verifications/${apiId(item.id)}/approve`, { method: 'POST' });
        if (!ctx.view.isConnected) return;
        ctx.toast('Identitas disetujui.'); ctx.refresh();
    })));
    ctx.view.querySelectorAll('[data-reject-verification]').forEach((form) => ctx.form(form, async (element) => {
        if (!element.reportValidity()) return;
        const reason = String(new FormData(element).get('reason')).trim();
        if (reason.length < 10) throw new Error('Alasan penolakan minimal 10 karakter.');
        const item = data.find((entry) => String(entry.id) === element.dataset.rejectVerification);
        if (!await ctx.confirm({ title: 'Tolak pengajuan identitas?', message: `${item.full_name} akan menerima alasan berikut: ${reason}`, confirmLabel: 'Tolak pengajuan' })) return;
        if (!ctx.view.isConnected) return;
        await ctx.api(`/identity-verifications/${apiId(item.id)}/reject`, { method: 'POST', body: { reason } });
        if (!ctx.view.isConnected) return;
        ctx.toast('Penolakan identitas tercatat.'); ctx.refresh();
    }));
}

async function logsPage(ctx, page, params) {
    const audit = page === 'audit';
    const query = listQuery(params, audit ? ['action'] : ['category', 'severity', 'user_id']);
    const { data, meta } = await ctx.api(`${audit ? '/admin/audit-logs' : '/security-events'}?${query}`);
    if (!ctx.view.isConnected) return;
    const categories = [
        ['access_denied', 'Akses ditolak'], ['unauthorized_access_attempt', 'Percobaan tanpa izin'], ['cross_class_access_attempt', 'Akses lintas kelas'], ['privilege_escalation_attempt', 'Percobaan eskalasi hak'], ['repeated_denials', 'Penolakan berulang'], ['rate_limit_exceeded', 'Batas request'], ['suspicious_login', 'Login mencurigakan'], ['identity_verification_bypass_attempt', 'Bypass verifikasi identitas'], ['private_file_access_attempt', 'Akses berkas privat'], ['role_change', 'Perubahan role'], ['conflict_of_interest_violation', 'Konflik kepentingan'],
    ];
    ctx.page(audit ? 'Audit aktivitas' : 'Keamanan', audit ? 'Jejak administratif yang diizinkan, bukan isi pengaduan privat.' : 'Kejadian yang dikategorikan backend, sesuai cakupan akses akun Anda.', `
        <div class="stack">${alertBox(audit ? 'Audit bersifat baca saja. Tidak ada API untuk menghapus log, mengubah konfigurasi, atau membuka isi pengaduan dari halaman ini.' : `${ctx.user.role === 'super_admin' ? 'Super Admin melihat kejadian teknis secara global.' : 'Kejadian dibatasi pada kelas dengan penugasan aktif.'} Kegagalan request tidak otomatis berarti seseorang adalah penyerang; klasifikasi berasal dari backend.`)}
        <section class="panel"><form data-filter class="form-grid">${audit ? field('action', 'Kode tindakan audit', { value: query.get('action'), placeholder: 'Contoh: role_assigned', maxLength: 100, help: 'Filter cocok dengan kode tindakan, bukan pencarian isi log.' }) : `${selectField('category', 'Kategori kejadian', [{ value: '', label: 'Semua kategori' }, ...categories.map(([value, label]) => ({ value, label }))], { value: query.get('category') || '' })}${selectField('severity', 'Tingkat kejadian', [{ value: '', label: 'Semua tingkat' }, { value: 'low', label: 'Rendah' }, { value: 'medium', label: 'Sedang' }, { value: 'high', label: 'Tinggi' }, { value: 'critical', label: 'Kritis' }], { value: query.get('severity') || '' })}${field('user_id', 'ID pengguna (opsional)', { type: 'number', min: 1, value: query.get('user_id') })}`}<div class="toolbar"><button class="button" type="submit">Terapkan filter</button><a class="button ghost" href="#/${page}">Reset</a></div></form></section>
        <section class="panel"><div class="panel-header"><h2>${audit ? 'Jejak tindakan' : 'Daftar kejadian'}</h2><span class="muted">${esc(meta?.pagination?.total ?? data.length)} hasil</span></div>${data.length ? `<div class="content-list">${data.map((item) => audit ? `<article class="list-item"><div class="stack"><strong>${esc(item.action_label || item.action)}</strong><p class="muted">${date(item.created_at)} / ${esc(item.actor?.name || 'Sistem')}<br>Subjek: ${esc(item.subject_type || 'Tidak tersedia')} #${esc(item.subject_id ?? '-')} / ID kelas: ${esc(item.class_id ?? '-')}</p>${item.changes && Object.keys(item.changes).length ? `<details><summary>Metadata perubahan</summary><pre class="prose">${esc(JSON.stringify(item.changes, null, 2))}</pre></details>` : '<p class="muted">Tidak ada metadata perubahan.</p>'}</div></article>` : `<article class="list-item"><div class="stack"><div class="toolbar"><strong>${esc(item.category_label || item.category)}</strong>${badge(item.severity)}</div><p class="muted">${date(item.created_at)} / ${esc(item.class?.name || 'Tidak terkait kelas')}<br>Pengguna: ${esc(item.user?.name || 'Tidak tersedia')} ${item.user?.email ? `(${esc(item.user.email)})` : ''}</p>${item.denial_reason ? `<p class="prose">${esc(item.denial_reason)}</p>` : ''}<details><summary>Metadata teknis yang diizinkan</summary><dl><dt>Referensi kejadian</dt><dd>#${esc(item.id)}</dd><dt>Resource</dt><dd>${esc(item.resource_type || '-')} / ${esc(item.resource_ref || '-')}</dd><dt>Route dan method</dt><dd>${esc(item.route || '-')} / ${esc(item.method || '-')}</dd><dt>Alamat IP</dt><dd>${esc(item.ip_address || 'Tidak tersedia')}</dd></dl></details></div></article>`).join('')}</div>` : empty(audit ? 'Tidak ada audit pada filter ini' : 'Tidak ada kejadian pada filter ini', 'Hasil kosong bukan pengukuran kesehatan seluruh sistem. Sesuaikan filter atau muat ulang data.')}${pagination(meta?.pagination, `/${page}`, query)}</section>
        ${!audit && ctx.has('system.maintain') ? `<section class="panel"><div class="panel-header"><h2>Batas status teknis</h2>${icon('shield')}</div><p class="prose">API ini menyediakan kejadian keamanan, bukan monitor kesehatan database, antrean, backup, atau deployment. Pengaturan layanan dan konfigurasi dilakukan oleh operator di luar antarmuka ini. Tidak ada status layanan yang diasumsikan atau data pemantauan contoh.</p></section>` : ''}</div>`);
    bindFilter(ctx, `/${page}`);
}
