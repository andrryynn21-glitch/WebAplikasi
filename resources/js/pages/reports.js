import { esc, badge, date, age, dataTable, field, textarea, selectField, empty, alertBox, pagination, formDataObject } from '../ui.js';

const statusLabels = {
    submitted: 'Dikirim', needs_review: 'Perlu peninjauan', under_review: 'Sedang ditinjau',
    needs_clarification: 'Butuh klarifikasi', in_progress: 'Sedang ditangani',
    waiting_follow_up: 'Menunggu tindak lanjut', resolution_proposed: 'Usulan penyelesaian',
    forwarded: 'Dialihkan', rejected: 'Ditolak', resolved: 'Selesai',
    appeal_pending: 'Banding diajukan', reopened: 'Dibuka kembali',
};

// Forwarding and appeals have their own endpoints, not ordinary status transitions.
const transitions = {
    submitted: ['needs_review', 'under_review', 'rejected'],
    needs_review: ['under_review', 'rejected'],
    under_review: ['in_progress', 'needs_clarification', 'rejected', 'resolution_proposed'],
    needs_clarification: ['under_review', 'in_progress', 'rejected'],
    in_progress: ['waiting_follow_up', 'resolution_proposed', 'rejected'],
    waiting_follow_up: ['in_progress', 'resolution_proposed', 'rejected'],
    resolution_proposed: ['resolved', 'reopened'], forwarded: ['under_review', 'in_progress', 'rejected'],
    rejected: ['reopened'], resolved: ['reopened'], appeal_pending: [],
    reopened: ['under_review', 'in_progress', 'rejected', 'resolution_proposed'],
};

const back = '<a class="button ghost" href="#/reports">Kembali ke pengaduan</a>';
const options = (labels) => Object.entries(labels).map(([value, label]) => ({ value, label }));
const reportPath = (ref) => `/reports/${encodeURIComponent(ref)}`;

function handoffForm() {
    return `<section class="panel stack"><h2>Handoff banding</h2>
        <p class="muted">Masukkan ID banding yang diberikan melalui handoff resmi. Ringkasan pengaduan tidak menyediakan ID banding yang belum ditugaskan. Isi banding hanya dapat dibaca oleh pelapor atau peninjau yang sudah ditetapkan.</p>
        <form data-appeal-handoff class="form-grid">
            ${field('appeal_id', 'ID banding', { type: 'number', min: 1, required: true })}
            ${selectField('mode', 'Tujuan', [{ value: 'assign', label: 'Tetapkan peninjau yang memenuhi syarat' }, { value: 'view', label: 'Baca banding yang sudah ditugaskan' }])}
            <button class="button secondary" type="submit">Lanjutkan</button>
        </form></section>`;
}

function bindHandoff(ctx) {
    const form = ctx.view.querySelector('[data-appeal-handoff]');
    if (!form) return;
    ctx.form(form, async (element) => {
        const data = formDataObject(element);
        ctx.navigate(`/appeals/${encodeURIComponent(data.appeal_id)}${data.mode === 'assign' ? '?mode=assign' : ''}`);
    });
}

async function reportsList(ctx, params) {
    const classScope = ctx.has('reports.view.class') || ctx.has('reports.view.assigned');
    const query = new URLSearchParams();
    for (const key of ['status', 'category_id', 'class_id', 'sort', 'direction', 'page', 'per_page']) {
        if (params.get(key)) query.set(key, params.get(key));
    }
    query.set('scope', classScope ? (params.get('scope') || 'class') : 'mine');
    const [response, categories] = await Promise.all([
        ctx.api(`/reports?${query}`), ctx.api('/report-categories'),
    ]);
    if (!ctx.view.isConnected) return;
    const create = ctx.has('reports.create') && ctx.canClass()
        ? '<a class="button" href="#/reports/new">Buat pengaduan</a>' : '';
    ctx.page('Pengaduan privat', 'Pantau penanganan tanpa membuka informasi di luar kewenangan Anda.', `
        <div class="stack"><section class="panel"><form data-report-filters class="form-grid">
            ${classScope ? selectField('scope', 'Cakupan', [{ value: 'mine', label: 'Pengaduan saya' }, { value: 'class', label: 'Kelas dalam kewenangan' }], { value: query.get('scope') }) : ''}
            ${selectField('status', 'Status', [{ value: '', label: 'Semua status' }, ...options(statusLabels)], { value: query.get('status') || '' })}
            ${selectField('category_id', 'Kategori', [{ value: '', label: 'Semua kategori' }, ...categories.data.map((item) => ({ value: item.id, label: item.name }))], { value: query.get('category_id') || '' })}
            ${classScope ? field('class_id', 'ID kelas (opsional)', { type: 'number', min: 1, value: query.get('class_id') || '', help: 'Hanya kelas yang berada dalam kewenangan Anda.' }) : ''}
            ${selectField('sort', 'Urutkan', [{ value: 'last_activity_at', label: 'Aktivitas terakhir' }, { value: 'created_at', label: 'Tanggal dibuat' }, { value: 'status', label: 'Status' }], { value: query.get('sort') || 'last_activity_at' })}
            ${selectField('direction', 'Arah urutan', [{ value: 'desc', label: 'Menurun' }, { value: 'asc', label: 'Menaik' }], { value: query.get('direction') || 'desc' })}
            <div class="toolbar"><button class="button secondary" type="submit">Terapkan filter</button><a class="button ghost" href="#/reports">Reset</a></div>
        </form></section>
        ${response.data.length ? `<section class="panel"><div class="panel-header"><h2>Daftar pengaduan</h2><span class="muted">${esc(response.meta?.pagination?.total ?? response.data.length)} pengaduan</span></div>
            ${dataTable([
                { label: 'Pengaduan', render: (item) => `<a href="#${esc(reportPath(item.public_ref))}">${esc(item.public_ref)}</a><p>${esc(item.title ?? 'Isi pengaduan dilindungi')}</p>${item.is_mine ? '<small>Milik saya</small>' : ''}` },
                { label: 'Kategori / kelas', render: (item) => `${esc(item.category?.name ?? 'Tidak tersedia')}<small>${esc(item.class?.name ?? 'Tidak tersedia')}</small>` },
                { label: 'Status', render: (item) => badge(item.status, item.status_label) },
                { label: 'Usia / aktivitas', render: (item) => `${age(item.created_at)}<small>${date(item.last_activity_at)}</small>` },
            ], response.data, 'Daftar pengaduan sesuai kewenangan')}
            ${pagination(response.meta?.pagination, '/reports', query)}</section>`
        : empty('Belum ada pengaduan', 'Tidak ada pengaduan yang cocok dengan cakupan dan filter ini.', create)}
        ${ctx.has('reports.appeal_review') ? handoffForm() : ''}</div>`, create);
    ctx.form(ctx.view.querySelector('[data-report-filters]'), async (form) => {
        const next = new URLSearchParams();
        for (const [key, value] of Object.entries(formDataObject(form))) if (value !== '') next.set(key, value);
        ctx.navigate(`/reports?${next}`);
    });
    bindHandoff(ctx);
}

async function createReport(ctx) {
    if (!ctx.has('reports.create') || !ctx.canClass()) {
        ctx.page('Buat pengaduan', 'Fitur kelas belum tersedia.', alertBox('Identitas terverifikasi, keanggotaan aktif, dan izin membuat pengaduan diperlukan.'), back);
        return;
    }
    const { data: categories, meta: { uploads } } = await ctx.api('/report-categories');
    if (!ctx.view.isConnected) return;
    const attachmentLimits = `Maksimal ${uploads.max_files} file, masing-masing ${uploads.max_file_bytes / (1024 * 1024)} MiB, total ${uploads.max_total_bytes / (1024 * 1024)} MiB.`;
    ctx.page('Ceritakan dengan aman', 'Pengaduan bersifat privat dan tidak dipublikasikan sebagai aspirasi kelas.', `
        <div class="stack">${alertBox('Kelas dan penangan ditentukan oleh sistem. Tuliskan fakta yang relevan; jangan menyertakan kata sandi atau informasi pribadi yang tidak diperlukan.')}
        ${categories.length ? `<form data-create-report class="panel stack" enctype="multipart/form-data">
            ${selectField('category_id', 'Kategori', [{ value: '', label: 'Pilih kategori' }, ...categories.map((item) => ({ value: item.id, label: `${item.name}${item.sensitive ? ' (sensitif)' : ''}` }))], { required: true })}
            ${field('title', 'Judul pengaduan', { required: true, minLength: 5, maxLength: 200, placeholder: 'Ringkasan singkat permasalahan' })}
            ${textarea('description', 'Uraian kejadian', { required: true, minLength: 30, maxLength: 20000, help: 'Jelaskan apa yang terjadi, waktu kejadian, dan dampaknya. Minimal 30 karakter.' })}
            ${textarea('desired_outcome', 'Hasil yang diharapkan (opsional)', { maxLength: 5000 })}
            ${textarea('involved_parties', 'Pihak yang terlibat (opsional)', { help: 'Satu nama per baris, maksimal 20 nama dan 200 karakter per nama. Digunakan untuk pemeriksaan konflik kepentingan.' })}
            ${field('attachments', 'Lampiran (opsional)', { type: 'file', multiple: true, accept: '.jpg,.jpeg,.png,.pdf,.txt', help: `${attachmentLimits} JPG, PNG, PDF, atau TXT. Lampiran hanya dapat ditambahkan saat membuat pengaduan.` })}
            <div class="toolbar"><button class="button" type="submit">Kirim pengaduan privat</button>${back}</div>
        </form>` : empty('Kategori belum tersedia', 'Pengaduan belum dapat dibuat karena katalog kategori belum tersedia.')}</div>`, back);
    const form = ctx.view.querySelector('[data-create-report]');
    if (!form) return;
    ctx.form(form, async (element) => {
        const partiesInput = element.elements.namedItem('involved_parties');
        const parties = partiesInput.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
        partiesInput.setCustomValidity(parties.length > 20 || parties.some((value) => value.length > 200) ? 'Gunakan maksimal 20 nama dengan maksimal 200 karakter per nama.' : '');
        const fileInput = element.elements.namedItem('attachments');
        const files = [...fileInput.files];
        const invalidFiles = files.length > uploads.max_files
            || files.some((file) => file.size > uploads.max_file_bytes)
            || files.reduce((total, file) => total + file.size, 0) > uploads.max_total_bytes;
        fileInput.setCustomValidity(invalidFiles ? attachmentLimits : '');
        if (!element.reportValidity()) return;
        const body = new FormData();
        for (const key of ['category_id', 'title', 'description', 'desired_outcome']) {
            const value = element.elements.namedItem(key).value.trim();
            if (value) body.append(key, value);
        }
        parties.forEach((party) => body.append('involved_parties[]', party));
        files.forEach((file) => body.append('attachments[]', file));
        const result = await ctx.api('/reports', { method: 'POST', body });
        if (!ctx.view.isConnected) return;
        ctx.toast('Pengaduan berhasil dikirim.');
        ctx.navigate(reportPath(result.data.public_ref));
    });
    for (const name of ['involved_parties', 'attachments']) {
        form.elements.namedItem(name).addEventListener('input', (event) => event.target.setCustomValidity(''));
        form.elements.namedItem(name).addEventListener('change', (event) => event.target.setCustomValidity(''));
    }
}

function assignmentForm(ctx, report) {
    const forwardable = ['submitted', 'needs_review', 'under_review', 'in_progress'].includes(report.status);
    return `<section class="panel stack"><h2>Penugasan penangan</h2><p class="muted">Gunakan ID pengguna yang sudah diketahui dan berwenang. Sistem memeriksa kelas, izin, dan konflik kepentingan. Penugasan diri diperlukan sebelum membaca laporan manual.</p>
        <form data-assignment class="stack">
            ${selectField('operation', 'Tindakan', [{ value: 'assignments', label: 'Tambah penugasan' }, ...(forwardable ? [{ value: 'forward', label: 'Alihkan penangan utama' }] : [])])}
            ${field('assignee_id', 'ID penangan', { type: 'number', min: 1, required: true, value: ctx.user.id })}
            <button class="button ghost" type="button" data-assign-self>Gunakan ID saya</button>
            ${selectField('assignment_type', 'Jenis penugasan', [{ value: 'primary', label: 'Utama (primary)' }, { value: 'secondary', label: 'Pendamping (secondary)' }])}
            ${textarea('reason', 'Alasan penugasan', { required: true, minLength: 10, maxLength: 500 })}
            <button class="button secondary" type="submit">Simpan penugasan</button>
        </form></section>`;
}

async function reportDetail(ctx, ref) {
    const path = reportPath(ref);
    const { data: report } = await ctx.api(path);
    if (!ctx.view.isConnected) return;
    const privateAccess = report.private_details !== null && typeof report.private_details === 'object';
    // Detail deliberately omits is_mine; null reporter identifies its requesting owner.
    const owner = privateAccess && report.reporter === null;
    const assignments = privateAccess ? report.assignments : [];
    const assigned = assignments.some((item) => item.assignee?.id === ctx.user.id && !item.revoked_at && ['primary', 'secondary'].includes(item.type));
    const reviewer = assignments.some((item) => item.assignee?.id === ctx.user.id && !item.revoked_at && item.type === 'appeal_reviewer');
    const canHandle = privateAccess && !owner && assigned && ctx.has('reports.view.private') && ctx.has('reports.update_status');
    const canAssign = !owner && ctx.has('reports.assign') && report.status !== 'appeal_pending';
    const nextStatuses = canHandle ? (transitions[report.status] || []) : [];
    const updates = privateAccess ? report.updates.filter((item) => !owner || !item.is_internal) : [];
    const answered = new Set(updates.filter((item) => item.type === 'clarification_response' && item.reply_to_id != null).map((item) => item.reply_to_id));
    const questions = updates.filter((item) => item.type === 'clarification_request' && !item.is_internal && !answered.has(item.id));
    const canAnswer = owner && ctx.canClass() && report.status === 'needs_clarification' && ctx.has('reports.respond_clarification');
    const canUpdate = privateAccess && (ctx.canClass() || (!owner && ctx.has('reports.view.private')));
    const canAsk = canHandle && ctx.has('reports.request_clarification') && ['under_review', 'needs_clarification'].includes(report.status);
    const canAppeal = owner && ctx.canClass() && ctx.has('reports.appeal_submit') && ['resolved', 'rejected'].includes(report.status) && !report.open_appeal;
    const canReview = ctx.has('reports.appeal_review') && !owner && !assignments.some((item) => item.assignee?.id === ctx.user.id && ['primary', 'secondary'].includes(item.type));
    ctx.page(report.title ?? 'Metadata pengaduan', report.public_ref, `<div class="stack">
        <section class="panel stack"><div class="toolbar">${badge(report.status, report.status_label)}${badge(report.sensitivity, report.sensitivity === 'sensitive' ? 'Sensitif' : 'Rutin')}<span class="badge">Privat</span></div>
            <dl class="grid-3"><div><dt class="muted">Kategori</dt><dd>${esc(report.category?.name ?? '-')}</dd></div><div><dt class="muted">Kelas</dt><dd>${esc(report.class?.name ?? '-')}</dd></div><div><dt class="muted">Perutean</dt><dd>${esc(report.routing_mode === 'auto' ? 'Otomatis' : report.routing_mode === 'manual' ? 'Manual' : '-')}</dd></div>
            <div><dt class="muted">Dibuat</dt><dd>${date(report.created_at)}</dd></div><div><dt class="muted">Aktivitas terakhir</dt><dd>${date(report.last_activity_at)}</dd></div>
            ${privateAccess ? `<div><dt class="muted">Penangan utama</dt><dd>${esc(report.current_handler?.name ?? 'Belum ditetapkan')}</dd></div>` : ''}</dl>
        </section>
        ${!privateAccess ? alertBox('Anda hanya memiliki akses metadata. Judul, identitas pelapor, uraian, lampiran, dan riwayat privat tidak ditampilkan. Penugasan tidak boleh digunakan bila terdapat konflik kepentingan.') : `
            <section class="panel stack"><h2>Uraian pengaduan</h2><p class="prose">${esc(report.private_details.description ?? 'Uraian tidak tersedia.')}</p>
                ${report.private_details.desired_outcome ? `<h3>Hasil yang diharapkan</h3><p class="prose">${esc(report.private_details.desired_outcome)}</p>` : ''}
                ${report.private_details.involved_parties?.length ? `<h3>Pihak yang disebutkan</h3><ul>${report.private_details.involved_parties.map((party) => `<li>${esc(party)}</li>`).join('')}</ul>` : ''}
                ${!owner && ctx.has('reports.view.private') && report.reporter ? `<h3>Pelapor (akses terbatas)</h3><p>${esc(report.reporter.name ?? '-')} &middot; NIM ${esc(report.reporter.nim ?? '-')}</p>` : ''}
            </section>
            ${report.resolution_summary ? `<section class="panel"><h2>Ringkasan hasil</h2><p class="prose">${esc(report.resolution_summary)}</p></section>` : ''}
            ${report.rejection_reason ? `<section class="panel"><h2>Alasan penolakan</h2><p class="prose">${esc(report.rejection_reason)}</p></section>` : ''}
            <section class="panel stack"><h2>Lampiran privat</h2>${report.attachments.length ? `<ul class="content-list">${report.attachments.map((file, index) => `<li class="list-item"><div class="toolbar"><span>${esc(file.original_name)} <span class="muted">(${esc(Math.ceil(file.size / 1024))} KB)</span></span><button class="button secondary" type="button" data-download="${index}">Unduh</button></div></li>`).join('')}</ul>` : '<p class="muted">Tidak ada lampiran.</p>'}</section>
            <section class="panel stack"><h2>Timeline penanganan</h2><p class="muted">Maksimal 100 peristiwa terbaru, ditampilkan dari yang terbaru. Status berasal dari sistem.</p>
                ${report.timeline.length ? `<ol class="timeline">${report.timeline.map((item, index) => `<li class="timeline-item" style="--step:${Math.min(index, 10) * 40}ms"><details class="timeline-event" ${index === 0 ? 'open' : ''}><summary>${badge(item.to_status, item.to_status_label)} <span>${date(item.created_at)}</span></summary><p class="muted">${esc(item.actor?.name ?? 'Sistem')}</p><p class="prose">${esc(item.reason || 'Tidak ada catatan tambahan pada peristiwa ini.')}</p></details></li>`).join('')}</ol>` : '<p class="muted">Belum ada peristiwa.</p>'}
            </section>
            <section class="panel stack"><h2>Pembaruan dan klarifikasi</h2><p class="muted">Maksimal 100 pembaruan terbaru. Catatan internal tidak ditampilkan kepada pelapor.</p>
                ${updates.length ? `<div class="content-list">${updates.map((item) => `<article class="list-item stack"><div class="toolbar"><strong>${esc(item.author?.name ?? 'Pengguna')}</strong>${badge(item.type, ({ clarification_request: 'Permintaan klarifikasi', clarification_response: 'Jawaban klarifikasi', internal_note: 'Catatan internal', update: 'Pembaruan' })[item.type] ?? item.type)}${item.is_internal ? '<span class="badge">Internal</span>' : ''}</div><p class="muted">${date(item.created_at)} &middot; Pembaruan #${esc(item.id)}${item.reply_to_id ? ` &middot; Menjawab #${esc(item.reply_to_id)}` : ''}</p><p class="prose">${esc(item.body)}</p></article>`).join('')}</div>` : '<p class="muted">Belum ada pembaruan.</p>'}
                ${canUpdate ? `<form data-update class="stack"><h3>Kirim pembaruan</h3>${textarea('body', 'Pesan pembaruan', { required: true, minLength: 3, maxLength: 5000 })}${!owner && ctx.has('reports.view.private') ? selectField('internal', 'Visibilitas', [{ value: 'false', label: 'Dapat dibaca pelapor' }, { value: 'true', label: 'Internal: penangan berwenang saja' }]) : ''}<button class="button" type="submit">Kirim pembaruan</button></form>` : ''}
                ${canAnswer ? `<form data-clarification-response class="stack"><h3>Jawab permintaan klarifikasi</h3>${questions.length ? selectField('reply_to_id', 'Permintaan yang dijawab', questions.map((item) => ({ value: item.id, label: `#${item.id}: ${item.body.slice(0, 100)}` })), { required: true }) : '<p class="muted">Sistem akan memilih permintaan terbuka terbaru dan memeriksa apakah masih dapat dijawab.</p>'}${textarea('body', 'Jawaban klarifikasi', { required: true, minLength: 3, maxLength: 5000 })}<button class="button" type="submit">Kirim jawaban klarifikasi</button></form>` : ''}
            </section>
            ${assignments.length ? `<details class="panel"><summary>Riwayat penugasan</summary><ul class="content-list">${assignments.map((item) => `<li class="list-item stack"><p><strong>${esc(item.assignee?.name ?? 'Penangan')}</strong> &middot; ${esc(({ primary: 'Utama', secondary: 'Pendamping', appeal_reviewer: 'Peninjau banding' })[item.type] ?? item.type)}</p><p class="muted">${date(item.assigned_at)} &middot; ${item.revoked_at ? `Berakhir ${date(item.revoked_at)}` : 'Aktif'}</p>${item.reason ? `<p class="prose">${esc(item.reason)}</p>` : ''}</li>`).join('')}</ul></details>` : ''}
        `}
        ${nextStatuses.length ? `<section class="panel stack"><h2>Tindak lanjut</h2><form data-status class="stack">${selectField('status', 'Status berikutnya', nextStatuses.map((value) => ({ value, label: statusLabels[value] })), { required: true })}${textarea('reason', 'Alasan (wajib untuk penolakan)', { maxLength: 1000 })}${textarea('resolution_summary', 'Ringkasan hasil (wajib untuk selesai)', { maxLength: 5000 })}<p class="muted">Untuk meminta jawaban pelapor, gunakan formulir permintaan klarifikasi. Status dan hak penangan diperiksa kembali oleh server.</p><button class="button" type="submit">Perbarui status</button></form></section>` : ''}
        ${canAsk ? `<section class="panel stack"><h2>Minta klarifikasi</h2><form data-clarification-request class="stack">${textarea('body', 'Hal yang perlu diklarifikasi', { required: true, minLength: 5, maxLength: 2000 })}<button class="button secondary" type="submit">Kirim permintaan klarifikasi</button></form></section>` : ''}
        ${canAssign ? assignmentForm(ctx, report) : ''}
        ${report.status === 'appeal_pending' && ctx.has('reports.assign') ? alertBox('Laporan sedang dalam banding. Gunakan penetapan peninjau banding, bukan penugasan penangan biasa. Penugasan utama atau pendamping dapat membuat calon peninjau tidak lagi memenuhi syarat.') : ''}
        ${report.open_appeal ? `<section class="panel stack"><h2>Banding aktif #${esc(report.open_appeal.id)}</h2>${badge(report.open_appeal.status)}<div class="toolbar">${owner || reviewer ? `<a class="button secondary" href="#/appeals/${esc(encodeURIComponent(report.open_appeal.id))}">Baca banding</a>` : ''}${canReview && !reviewer ? `<a class="button secondary" href="#/appeals/${esc(encodeURIComponent(report.open_appeal.id))}?mode=assign">Tetapkan peninjau</a>` : ''}</div></section>` : ''}
        ${canAppeal ? `<section class="panel stack"><h2>Ajukan banding</h2><p class="muted">Banding ditinjau oleh pihak yang berbeda dan bebas konflik. Jelaskan alasan permintaan peninjauan ulang.</p><form data-submit-appeal class="stack">${textarea('reason', 'Alasan banding', { required: true, minLength: 20, maxLength: 5000 })}<button class="button secondary" type="submit">Ajukan banding</button></form></section>` : ''}
        ${!privateAccess && report.status === 'appeal_pending' && ctx.has('reports.appeal_review') ? handoffForm() : ''}
    </div>`, back);

    const bind = (selector, callback) => {
        const form = ctx.view.querySelector(selector);
        if (form) ctx.form(form, callback);
    };
    const save = async (suffix, body, method = 'POST') => {
        await ctx.api(`${path}/${suffix}`, { method, body });
        if (!ctx.view.isConnected) return;
        ctx.toast('Perubahan berhasil disimpan.');
        ctx.refresh();
    };
    bind('[data-update]', async (form) => {
        const data = formDataObject(form);
        await save('updates', { body: data.body, internal: !owner && data.internal === 'true', type: 'update' });
    });
    bind('[data-clarification-response]', async (form) => {
        const data = formDataObject(form);
        await save('updates', { body: data.body, type: 'clarification_response', ...(data.reply_to_id ? { reply_to_id: Number(data.reply_to_id) } : {}) });
    });
    bind('[data-clarification-request]', async (form) => save('clarification-requests', formDataObject(form)));
    const statusForm = ctx.view.querySelector('[data-status]');
    if (statusForm) {
        const requireFields = () => {
            statusForm.elements.namedItem('reason').required = statusForm.elements.namedItem('status').value === 'rejected';
            statusForm.elements.namedItem('resolution_summary').required = statusForm.elements.namedItem('status').value === 'resolved';
        };
        statusForm.elements.namedItem('status').addEventListener('change', requireFields);
        requireFields();
        ctx.form(statusForm, async (form) => {
            const data = formDataObject(form);
            if (!nextStatuses.includes(data.status)) return;
            if (!await ctx.confirm({ title: 'Perbarui status pengaduan?', message: `Status akan menjadi ${statusLabels[data.status]}. Tindakan ini dicatat dalam timeline.`, confirmLabel: 'Perbarui status' }) || !ctx.view.isConnected) return;
            await save('status', data, 'PATCH');
        });
    }
    const assignment = ctx.view.querySelector('[data-assignment]');
    if (assignment) {
        const operation = assignment.elements.namedItem('operation');
        operation.addEventListener('change', () => { assignment.elements.namedItem('assignment_type').disabled = operation.value === 'forward'; });
        assignment.querySelector('[data-assign-self]').addEventListener('click', () => { assignment.elements.namedItem('assignee_id').value = ctx.user.id; });
        ctx.form(assignment, async (form) => {
            const data = formDataObject(form);
            if (!await ctx.confirm({ title: data.operation === 'forward' ? 'Alihkan penangan utama?' : 'Tetapkan penangan?', message: 'Penugasan memberikan akses privat sesuai izin. Pastikan penerima berwenang dan bebas konflik kepentingan.', confirmLabel: 'Simpan penugasan' }) || !ctx.view.isConnected) return;
            await save(data.operation === 'forward' ? 'forward' : 'assignments', { assignee_id: Number(data.assignee_id), reason: data.reason, ...(data.operation === 'forward' ? {} : { assignment_type: data.assignment_type }) });
        });
    }
    bind('[data-submit-appeal]', async (form) => {
        if (!await ctx.confirm({ title: 'Ajukan banding?', message: 'Laporan akan masuk alur peninjauan banding oleh peninjau yang memenuhi syarat.', confirmLabel: 'Ajukan banding' }) || !ctx.view.isConnected) return;
        const result = await ctx.api(`${path}/appeals`, { method: 'POST', body: formDataObject(form) });
        if (!ctx.view.isConnected) return;
        ctx.toast(`Banding #${result.data.id} berhasil diajukan.`);
        ctx.navigate(`/appeals/${encodeURIComponent(result.data.id)}`);
    });
    ctx.view.querySelectorAll('[data-download]').forEach((button) => {
        const file = report.attachments[Number(button.dataset.download)];
        button.addEventListener('click', () => ctx.action(button, async () => ctx.download(`/attachments/${encodeURIComponent(file.id)}`, file.original_name)));
    });
    bindHandoff(ctx);
}

async function appealPage(ctx, id, params) {
    const path = `/appeals/${encodeURIComponent(id)}`;
    if (params.get('mode') === 'assign') {
        if (!ctx.has('reports.appeal_review')) {
            ctx.page('Peninjauan banding', 'Akses terbatas.', alertBox('Anda tidak memiliki izin menetapkan peninjau banding.'), back);
            return;
        }
        // Do not request private appeal detail before the requester is assigned.
        const { data: reviewers } = await ctx.api(`${path}/eligible-reviewers`);
        if (!ctx.view.isConnected) return;
        ctx.page(`Peninjau banding #${id}`, 'Hanya kandidat yang dinyatakan memenuhi syarat oleh server ditampilkan.', `<div class="stack">
            ${alertBox('Peninjau harus berbeda dari penangan atau pemutus sebelumnya dan bebas konflik. Penetapan tidak memberikan akses kepada pengguna lain yang melakukan handoff.')}
            ${reviewers.length ? `<form data-reviewer class="panel stack">${selectField('reviewer_id', 'Peninjau yang memenuhi syarat', [{ value: '', label: 'Pilih peninjau' }, ...reviewers.map((item) => ({ value: item.id, label: `${item.name}${item.id === ctx.user.id ? ' (saya)' : ''}` }))], { required: true })}<button class="button" type="submit">Tetapkan peninjau</button></form>` : empty('Belum ada peninjau yang memenuhi syarat', 'Koordinasikan penugasan dosen yang sesuai dan bebas konflik. Tidak ada pengganti otomatis dari Super Admin.')}
        </div>`, back);
        const form = ctx.view.querySelector('[data-reviewer]');
        if (form) ctx.form(form, async (element) => {
            const reviewerId = Number(formDataObject(element).reviewer_id);
            if (!reviewers.some((item) => item.id === reviewerId)) return;
            if (!await ctx.confirm({ title: 'Tetapkan peninjau banding?', message: 'Peninjau yang ditetapkan mendapat akses untuk membaca dan memutuskan banding sesuai kewenangannya.', confirmLabel: 'Tetapkan peninjau' }) || !ctx.view.isConnected) return;
            const result = await ctx.api(`${path}/reviewer`, { method: 'POST', body: { reviewer_id: reviewerId } });
            if (!ctx.view.isConnected) return;
            ctx.toast('Peninjau banding berhasil ditetapkan.');
            if (result.data.reviewer.id === ctx.user.id) ctx.navigate(path);
            else ctx.page(`Banding #${id}`, 'Penetapan peninjau tercatat.', `<section class="panel stack"><h2>Peninjau telah ditetapkan</h2><p>${esc(result.data.reviewer.name)} akan meninjau banding ini. Isi banding tetap hanya dapat dibaca oleh pelapor dan peninjau tersebut.</p>${back}</section>`, back);
        });
        return;
    }
    const { data: appeal } = await ctx.api(path);
    if (!ctx.view.isConnected) return;
    const canDecide = ctx.has('reports.appeal_review') && appeal.reviewer?.id === ctx.user.id && appeal.status === 'under_review';
    ctx.page(`Banding #${appeal.id}`, 'Peninjauan ulang dengan pemisahan kewenangan.', `<div class="stack"><section class="panel stack">
        <div class="toolbar">${badge(appeal.status)}<span class="badge">Privat</span></div>
        <p class="muted">Diajukan ${date(appeal.created_at)} &middot; Peninjau: ${esc(appeal.reviewer?.name ?? 'Belum ditetapkan')}</p>
        <h2>Alasan banding</h2><p class="prose">${esc(appeal.reason ?? 'Isi banding tidak tersedia untuk kewenangan Anda.')}</p>
        ${appeal.decision_note ? `<h2>Catatan keputusan</h2><p class="prose">${esc(appeal.decision_note)}</p><p class="muted">Diputuskan ${date(appeal.decided_at)}</p>` : ''}
        </section>${canDecide ? `<section class="panel stack"><h2>Keputusan peninjauan</h2><form data-appeal-decision class="stack">${selectField('status', 'Keputusan', [{ value: '', label: 'Pilih keputusan' }, { value: 'accepted', label: 'Terima banding dan buka kembali laporan' }, { value: 'rejected', label: 'Tolak banding dan pertahankan hasil sebelumnya' }], { required: true })}${textarea('decision_note', 'Dasar keputusan', { required: true, minLength: 10, maxLength: 5000 })}<button class="button" type="submit">Catat keputusan banding</button></form></section>` : ''}</div>`, back);
    const form = ctx.view.querySelector('[data-appeal-decision]');
    if (form) ctx.form(form, async (element) => {
        if (!await ctx.confirm({ title: 'Catat keputusan banding?', message: 'Keputusan dicatat dalam riwayat. Banding yang diterima membuka kembali laporan; penolakan mengembalikan status hasil sebelumnya.', confirmLabel: 'Catat keputusan' }) || !ctx.view.isConnected) return;
        await ctx.api(`${path}/decision`, { method: 'POST', body: formDataObject(element) });
        if (!ctx.view.isConnected) return;
        ctx.toast('Keputusan banding berhasil dicatat.');
        ctx.refresh();
    });
}

export async function reportsPage(ctx, segments, params) {
    if (segments[0] === 'appeals' && segments[1] && segments.length === 2) return appealPage(ctx, segments[1], params);
    if (segments[0] === 'reports' && segments.length === 1) return reportsList(ctx, params);
    if (segments[0] === 'reports' && segments[1] === 'new' && segments.length === 2) return createReport(ctx);
    if (segments[0] === 'reports' && segments[1] && segments.length === 2) return reportDetail(ctx, segments[1]);
    ctx.page('Halaman tidak ditemukan', 'Periksa kembali alamat pengaduan atau banding.', empty('Alamat tidak tersedia', 'Gunakan daftar pengaduan untuk melanjutkan.', back));
}
