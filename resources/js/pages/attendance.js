import { esc, icon, badge, field, textarea, selectField, empty, alertBox, pagination, dataTable, formDataObject } from '../ui.js';

export const attendanceStatuses = { present: 'Hadir', excused: 'Izin', sick: 'Sakit', absent: 'Alpa' };
const sessionLabels = { draft: 'Draft', finalized: 'Final' };
const historyLabels = { attendance_created: 'Pertemuan dibuat', attendance_updated: 'Draft diperbarui', attendance_finalized: 'Absensi difinalisasi', attendance_reopened: 'Absensi dibuka kembali' };
const idPath = (id) => encodeURIComponent(String(id));
const positiveId = (value) => /^[1-9]\d*$/.test(String(value ?? ''));
const text = (value) => String(value ?? '').trim();
const validDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value) && Number.isFinite(Date.parse(`${value}T00:00:00Z`)) && new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value;
const attendanceBadge = (status) => badge(status || 'unmarked', attendanceStatuses[status] || sessionLabels[status] || 'Belum diisi');

export function attendanceCounts(records) {
    const counts = { present: 0, excused: 0, sick: 0, absent: 0, unmarked: 0, total: records.length };
    for (const record of records) counts[Object.hasOwn(attendanceStatuses, record.status) ? record.status : 'unmarked']++;
    return counts;
}

export function matchesAttendanceStudent(record, search) {
    const query = text(search).toLocaleLowerCase('id-ID');
    return !query || `${record.name ?? ''} ${record.nim ?? ''}`.toLocaleLowerCase('id-ID').includes(query);
}

export function attendanceFilters(params, summary = false) {
    const query = new URLSearchParams();
    if (positiveId(params.get('course_id'))) query.set('course_id', params.get('course_id'));
    for (const key of ['date_from', 'date_to']) if (validDate(params.get(key) || '')) query.set(key, params.get(key));
    if (!summary) {
        if (['draft', 'finalized'].includes(params.get('status'))) query.set('status', params.get('status'));
        query.set('page', positiveId(params.get('page')) ? params.get('page') : '1');
        const size = Number(params.get('per_page'));
        query.set('per_page', Number.isInteger(size) && size >= 1 && size <= 100 ? String(size) : '20');
    }
    return query;
}

function invalid(message, name) {
    const error = new Error(message);
    error.details = { [name]: [message] };
    throw error;
}

function limited(value, name, label, max, required = false) {
    const result = text(value);
    if (required && !result) invalid(`${label} wajib diisi.`, name);
    if ([...result].length > max) invalid(`${label} maksimal ${max} karakter.`, name);
    return result;
}

export function serializeAttendanceCourse(values, editing = false) {
    const body = {
        code: limited(values.code, 'code', 'Kode mata kuliah', 32, true),
        name: limited(values.name, 'name', 'Nama mata kuliah', 160, true),
        lecturer_name: limited(values.lecturer_name, 'lecturer_name', 'Nama dosen', 160, true),
    };
    if (editing) body.is_active = values.is_active === true || values.is_active === 'true';
    return body;
}

export function serializeAttendanceSession(values, today, session = null) {
    const meeting = Number(values.meeting_number);
    if (!Number.isInteger(meeting) || meeting < 1 || meeting > 100) invalid('Nomor pertemuan harus 1 sampai 100.', 'meeting_number');
    if (!validDate(values.held_on || '') || values.held_on > today) invalid('Tanggal pertemuan harus valid dan tidak melebihi hari ini.', 'held_on');
    const start = text(values.starts_at), end = text(values.ends_at);
    if (Boolean(start) !== Boolean(end)) invalid('Isi jam mulai dan selesai, atau kosongkan keduanya.', start ? 'ends_at' : 'starts_at');
    for (const [name, time] of [['starts_at', start], ['ends_at', end]]) {
        if (time && !/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) invalid('Jam harus menggunakan format HH:mm.', name);
    }
    if (start && end <= start) invalid('Jam selesai harus setelah jam mulai pada hari yang sama.', 'ends_at');
    const body = {
        meeting_number: meeting, held_on: values.held_on, starts_at: start || null, ends_at: end || null,
        topic: limited(values.topic, 'topic', 'Topik', 255) || null,
        notes: limited(values.notes, 'notes', 'Catatan pertemuan', 2000) || null,
    };
    if (!session) {
        if (!positiveId(values.course_id)) invalid('Pilih mata kuliah aktif.', 'course_id');
        body.course_id = Number(values.course_id);
    } else {
        body.version = session.version;
        body.lecturer_name = limited(values.lecturer_name, 'lecturer_name', 'Nama dosen', 160, true);
        // Snapshot IDs and order come from the session, never from filtered rows or editable inputs.
        body.records = session.records.map((record, index) => {
            const name = `records.${index}.status`;
            if (!Object.hasOwn(values, name)) invalid('Data mahasiswa tidak lengkap. Muat ulang setelah menyimpan salinan perubahan.', name);
            const status = values[name] || null;
            if (status !== null && !Object.hasOwn(attendanceStatuses, status)) invalid('Pilih status kehadiran yang valid.', name);
            return { user_id: record.user_id, status, note: limited(values[`records.${index}.note`], `records.${index}.note`, 'Catatan mahasiswa', 500) || null };
        });
    }
    return body;
}

export function attendanceDay(value) {
    return validDate(value || '') ? new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)) : 'Tanggal belum dipilih';
}

export function attendanceRate(value) {
    return typeof value === 'number' && Number.isFinite(value) ? `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value)}%` : 'Belum ada data';
}

function historyTime(value) {
    const time = new Date(value);
    return value && Number.isFinite(time.getTime()) ? `${new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(time)} WIB` : 'Belum tersedia';
}

function pageTop(catalog, active) {
    return `<section class="panel attendance-class"><div><span class="eyebrow">KELAS ANDA / ${esc(catalog.class.code)}</span><h2>${esc(catalog.class.name)}</h2><p class="muted">${esc(catalog.class.academic_term?.name)} / ${esc(catalog.class.academic_term?.code)}<br>Waktu ${esc(catalog.timezone)} / Hari ini ${esc(attendanceDay(catalog.today))}</p></div><nav class="toolbar" aria-label="Navigasi absensi">${[['sessions', '/attendance', 'Pertemuan'], ['courses', '/attendance/courses', 'Mata kuliah'], ['summary', '/attendance/summary', 'Rekap & CSV']].map(([key, path, label]) => `<a class="button ${key === active ? '' : 'secondary'}" href="#${path}" ${key === active ? 'aria-current="page"' : ''}>${label}</a>`).join('')}</nav></section>`;
}

function counters(counts) {
    return `<div class="attendance-counts">${[...Object.entries(attendanceStatuses), ['unmarked', 'Belum diisi'], ['total', 'Mahasiswa']].map(([key, label]) => `<div class="attendance-count attendance-${key}"><strong>${esc(counts[key] ?? 0)}</strong><span>${label}</span></div>`).join('')}</div>`;
}

function filtersMarkup(catalog, query, summary = false) {
    return `<section class="panel"><form data-filter class="stack"><div data-errors></div><div class="form-grid">${selectField('course_id', 'Mata kuliah', [{ value: '', label: 'Semua mata kuliah' }, ...catalog.courses.map((course) => ({ value: course.id, label: `${course.code} / ${course.name}${course.is_active ? '' : ' (arsip)'}` }))], { value: query.get('course_id') || '' })}${summary ? '' : selectField('status', 'Status pertemuan', [{ value: '', label: 'Semua status' }, { value: 'draft', label: 'Draft' }, { value: 'finalized', label: 'Final' }], { value: query.get('status') || '' })}${field('date_from', 'Dari tanggal', { type: 'date', value: query.get('date_from') })}${field('date_to', 'Sampai tanggal', { type: 'date', value: query.get('date_to') })}${summary ? '' : selectField('per_page', 'Hasil per halaman', [...new Set([20, 50, 100, Number(query.get('per_page'))])].map((value) => ({ value, label: `${value} pertemuan` })), { value: query.get('per_page') })}</div><div class="toolbar"><button class="button secondary" type="submit">Terapkan filter</button><a class="button ghost" href="#/attendance${summary ? '/summary' : ''}">Reset filter</a></div></form></section>`;
}

function bindFilters(ctx, summary = false) {
    ctx.form(ctx.view.querySelector('[data-filter]'), async (form) => {
        const values = formDataObject(form);
        if (values.date_from && values.date_to && values.date_from > values.date_to) invalid('Tanggal akhir tidak boleh sebelum tanggal awal.', 'date_to');
        const query = attendanceFilters(new URLSearchParams(values), summary);
        ctx.navigate(`/attendance${summary ? '/summary' : ''}${query.size ? `?${query}` : ''}`);
    });
}

export async function attendancePage(ctx, segments, params = new URLSearchParams()) {
    if (ctx.user?.role !== 'class_leader' || !ctx.canClass() || !ctx.has('attendance.manage')) {
        ctx.page('Absensi tidak tersedia', 'Absensi hanya tersedia untuk ketua kelas aktif dan terverifikasi.', empty('Tidak ada akses absensi', 'Server tetap memeriksa penugasan ketua pada setiap permintaan.', '<a class="button" href="#/dashboard">Kembali ke ringkasan</a>'));
        return;
    }
    const route = segments[1];
    const detail = route === 'sessions' && positiveId(segments[2]) && segments.length === 3;
    if (!detail && (segments.length > 2 || ![undefined, 'courses', 'new', 'summary'].includes(route))) {
        ctx.page('Halaman tidak tersedia', 'Alamat absensi tidak dikenali.', empty('Kembali ke absensi', 'Pilih pertemuan dari daftar.', '<a class="button" href="#/attendance">Daftar pertemuan</a>'));
        return;
    }
    const { data: catalog } = await ctx.api('/attendance');
    if (!ctx.view.isConnected) return;
    if (route === 'courses') return coursesPage(ctx, catalog);
    if (route === 'new') return newSessionPage(ctx, catalog, params);
    if (route === 'summary') return summaryPage(ctx, catalog, params);
    if (detail) {
        const { data } = await ctx.api(`/attendance/sessions/${idPath(segments[2])}`);
        if (ctx.view.isConnected) sessionPage(ctx, catalog, data);
        return;
    }
    return sessionsPage(ctx, catalog, params);
}

async function sessionsPage(ctx, catalog, params) {
    const query = attendanceFilters(params);
    const { data, meta } = await ctx.api(`/attendance/sessions?${query}`);
    if (!ctx.view.isConnected) return;
    ctx.page('Absensi kelas', 'Catat kehadiran dengan jelas, dari draft sampai rekap final.', `${pageTop(catalog, 'sessions')}${alertBox('Daftar mahasiswa dan informasi mata kuliah disimpan sebagai snapshot saat pertemuan dibuat. Hanya pertemuan final yang masuk ke rekap.')}${filtersMarkup(catalog, query)}<section class="panel"><div class="panel-header"><h2>Riwayat pertemuan</h2><span class="muted">${esc(meta?.pagination?.total ?? data.length)} pertemuan</span></div>${data.length ? `<div class="content-list">${data.map((session) => `<a class="list-item attendance-session" href="#/attendance/sessions/${esc(idPath(session.id))}"><span class="row-icon">${icon('check')}</span><span class="list-main"><strong>${esc(session.course.code)} / ${esc(session.course.name)}</strong><small>Pertemuan ${esc(session.meeting_number)} / ${esc(session.day_name)} / ${esc(attendanceDay(session.held_on))}${session.starts_at ? ` / ${esc(session.starts_at.slice(0, 5))} - ${esc(session.ends_at?.slice(0, 5))} WIB` : ''}</small><small>Dosen: ${esc(session.course.lecturer_name)}</small>${session.topic ? `<small>${esc(session.topic)}</small>` : ''}<small>Hadir ${esc(session.counts.present)} / Izin ${esc(session.counts.excused)} / Sakit ${esc(session.counts.sick)} / Alpa ${esc(session.counts.absent)} / Belum diisi ${esc(session.counts.unmarked)} / Total ${esc(session.counts.total)}</small></span>${attendanceBadge(session.status)}<span class="row-arrow">${icon('arrow')}</span></a>`).join('')}</div>` : empty('Belum ada pertemuan pada filter ini', 'Buat pertemuan baru atau ubah filter untuk melihat riwayat absensi.')}${pagination(meta?.pagination, '/attendance', query)}</section>`, '<a class="button" href="#/attendance/new">' + icon('plus') + ' Buat pertemuan</a>');
    bindFilters(ctx);
}

function courseForm(course = {}) {
    const editing = Boolean(course.id);
    return `<form data-course="${esc(course.id || '')}" class="stack"><div data-errors></div><fieldset class="attendance-fieldset"><div class="form-grid">${field('code', 'Kode mata kuliah', { value: course.code, required: true, maxLength: 32, help: 'Teks, bukan angka. Nol di depan tetap disimpan.' })}${field('name', 'Nama mata kuliah', { value: course.name, required: true, maxLength: 160 })}${field('lecturer_name', 'Nama dosen pengampu', { value: course.lecturer_name, required: true, maxLength: 160, help: 'Isi nama dosen secara langsung. Tidak harus memiliki akun koordinator.' })}${editing ? selectField('is_active', 'Status mata kuliah', [{ value: 'true', label: 'Aktif' }, { value: 'false', label: 'Arsip' }], { value: String(course.is_active) }) : ''}</div></fieldset><button class="button ${editing ? 'secondary' : ''}" type="submit">${editing ? 'Simpan mata kuliah' : 'Tambah mata kuliah'}</button></form>`;
}

function coursesPage(ctx, catalog) {
    ctx.page('Mata kuliah kelas', 'Kelola kode, nama, dan dosen pengampu tanpa mengubah riwayat pertemuan.', `${pageTop(catalog, 'courses')}<section class="panel"><div class="panel-header"><h2>Tambah mata kuliah</h2>${icon('plus')}</div>${courseForm()}</section><section class="panel"><div class="panel-header"><h2>Katalog mata kuliah</h2><span class="muted">${esc(catalog.courses.length)} mata kuliah</span></div>${alertBox('Arsip menutup pembuatan pertemuan baru, bukan menghapus riwayat. Perubahan katalog tidak mengganti snapshot pertemuan yang sudah ada; nama dosen dapat dikoreksi pada draft.')}${catalog.courses.length ? `<div class="stack">${catalog.courses.map((course) => `<article class="attendance-course"><div class="panel-header"><div><h3>${esc(course.code)} / ${esc(course.name)}</h3><p class="muted">${esc(course.lecturer_name)} / ${esc(course.sessions_count)} pertemuan</p></div>${badge(course.is_active ? 'active' : 'hidden', course.is_active ? 'Aktif' : 'Arsip')}</div><div class="toolbar"><a class="text-link" href="#/attendance?course_id=${esc(idPath(course.id))}">Lihat riwayat ${icon('arrow')}</a>${course.is_active ? `<a class="button secondary" href="#/attendance/new?course_id=${esc(idPath(course.id))}">Buat pertemuan</a>` : ''}</div><details><summary>Edit / arsipkan ${esc(course.code)}</summary>${courseForm(course)}</details></article>`).join('')}</div>` : empty('Katalog masih kosong', 'Tambahkan mata kuliah pertama untuk mulai mencatat pertemuan.')}</section>`);
    ctx.view.querySelectorAll('[data-course]').forEach((form) => ctx.form(form, async () => {
        const course = catalog.courses.find((item) => String(item.id) === form.dataset.course);
        const body = serializeAttendanceCourse(formDataObject(form), Boolean(course));
        const fields = form.querySelector('fieldset');
        fields.disabled = true;
        try {
            if (course?.is_active && !body.is_active && !await ctx.confirm({ title: 'Arsipkan mata kuliah?', message: `${course.name} tidak dapat dipilih untuk pertemuan baru. Seluruh pertemuan dan rekap yang sudah ada tetap tersimpan.`, confirmLabel: 'Arsipkan' })) return;
            if (!ctx.view.isConnected) return;
            await ctx.api(course ? `/attendance/courses/${idPath(course.id)}` : '/attendance/courses', { method: course ? 'PATCH' : 'POST', body });
            if (!ctx.view.isConnected) return;
            ctx.toast(course ? 'Mata kuliah diperbarui.' : 'Mata kuliah ditambahkan.');
            ctx.refresh();
        } finally { fields.disabled = false; }
    }));
}

function meetingFields(catalog, session = {}) {
    return `<div class="form-grid">${field('meeting_number', 'Pertemuan ke', { type: 'number', min: 1, max: 100, step: 1, required: true, value: session.meeting_number, placeholder: '1 sampai 100' })}${field('held_on', 'Tanggal pertemuan', { type: 'date', required: true, max: catalog.today, value: session.held_on || catalog.today, help: 'Tidak boleh setelah hari ini (Asia/Jakarta).' })}${field('starts_at', 'Jam mulai (WIB)', { type: 'time', value: session.starts_at?.slice(0, 5), help: 'Opsional. Isi bersama jam selesai.' })}${field('ends_at', 'Jam selesai (WIB)', { type: 'time', value: session.ends_at?.slice(0, 5), help: 'Harus setelah jam mulai pada hari yang sama.' })}</div><p class="muted" data-day aria-live="polite">${esc(attendanceDay(session.held_on || catalog.today))}</p>${field('topic', 'Topik / materi (opsional)', { value: session.topic, maxLength: 255 })}${textarea('notes', 'Catatan pertemuan (opsional)', { value: session.notes, maxLength: 2000, rows: 3 })}`;
}

function bindDay(form) {
    form.elements.namedItem('held_on').addEventListener('input', (event) => { form.querySelector('[data-day]').textContent = attendanceDay(event.target.value); });
}

function newSessionPage(ctx, catalog, params) {
    const courses = catalog.courses.filter((course) => course.is_active);
    const selected = courses.find((course) => String(course.id) === params.get('course_id'));
    const content = !courses.length
        ? `<section class="panel">${empty('Tambahkan mata kuliah aktif terlebih dahulu', 'Mata kuliah arsip tidak bisa digunakan untuk membuat pertemuan baru.', '<a class="button" href="#/attendance/courses">Kelola mata kuliah</a>')}</section>`
        : !catalog.roster.length
            ? `<section class="panel">${empty('Belum ada mahasiswa aktif', 'Pastikan keanggotaan kelas sudah disetujui sebelum membuat snapshot absensi.', '<a class="button secondary" href="#/classes">Kelola keanggotaan</a>')}</section>`
            : `<section class="panel"><form data-new-session class="stack"><div data-errors></div><fieldset class="attendance-fieldset stack">${selectField('course_id', 'Mata kuliah', [{ value: '', label: 'Pilih mata kuliah' }, ...courses.map((course) => ({ value: course.id, label: `${course.code} / ${course.name}` }))], { required: true, value: selected?.id || '' })}<p class="muted" data-lecturer>Dosen: ${esc(selected?.lecturer_name || 'Pilih mata kuliah terlebih dahulu')}</p>${meetingFields(catalog)}${alertBox(`Snapshot berisi ${catalog.roster.length} mahasiswa dari keanggotaan aktif saat pembuatan. Perubahan anggota sesudahnya tidak mengubah pertemuan ini. Semua status awal kosong, bukan otomatis hadir.`)}<details><summary>Pratinjau ${esc(catalog.roster.length)} mahasiswa saat ini</summary>${dataTable([{ key: 'name', label: 'Nama' }, { key: 'nim', label: 'NIM' }], catalog.roster, 'Pratinjau mahasiswa aktif')}</details></fieldset><div class="toolbar"><button class="button" type="submit">Buat draft & isi absensi</button><a class="button ghost" href="#/attendance">Batal</a></div></form></section>`;
    ctx.page('Buat pertemuan', 'Satu pertemuan untuk satu mata kuliah, tanggal, dan daftar mahasiswa.', `${pageTop(catalog, 'sessions')}${content}`);
    const form = ctx.view.querySelector('[data-new-session]');
    if (!form) return;
    bindDay(form);
    form.elements.namedItem('course_id').addEventListener('change', (event) => { form.querySelector('[data-lecturer]').textContent = `Dosen: ${courses.find((course) => String(course.id) === event.target.value)?.lecturer_name || 'Pilih mata kuliah terlebih dahulu'}`; });
    ctx.form(form, async () => {
        const body = serializeAttendanceSession(formDataObject(form), catalog.today);
        const fields = form.querySelector('fieldset');
        fields.disabled = true;
        try {
            const { data } = await ctx.api('/attendance/sessions', { method: 'POST', body });
            if (!ctx.view.isConnected) return;
            ctx.toast('Draft dibuat. Isi status mahasiswa sebelum finalisasi.');
            ctx.navigate(`/attendance/sessions/${idPath(data.id)}`);
        } finally { fields.disabled = false; }
    });
}

function rosterMarkup(session, draft) {
    return `<div class="attendance-roster">${session.records.map((record, index) => `<article class="attendance-student" data-student="${index}"><div class="attendance-student-name"><span class="avatar avatar-small">${esc(record.name?.slice(0, 1).toUpperCase())}</span><div><h3>${esc(record.name)}</h3><p class="muted">NIM ${esc(record.nim || 'Belum tersedia')}</p></div></div>${draft ? `${selectField(`records.${index}.status`, `Status ${record.name}`, [{ value: '', label: 'Belum diisi' }, ...Object.entries(attendanceStatuses).map(([value, label]) => ({ value, label }))], { value: record.status || '' })}${field(`records.${index}.note`, `Catatan ${record.name}`, { value: record.note, maxLength: 500, placeholder: 'Catatan opsional' })}` : `<div>${attendanceBadge(record.status)}</div><p class="prose">${esc(record.note || 'Tidak ada catatan')}</p>`}</article>`).join('')}</div><p class="muted" data-roster-empty hidden>Tidak ada mahasiswa yang cocok dengan pencarian.</p>`;
}

function sessionPage(ctx, catalog, initialSession) {
    ctx.guardUnsavedChanges(null);
    let session = initialSession;
    const draft = session.status === 'draft';
    ctx.page(`Pertemuan ${session.meeting_number}`, `${session.course.code} / ${session.course.name}`, `${pageTop(catalog, 'sessions')}<section class="panel"><div class="panel-header"><div><h2>${esc(session.course.name)}</h2><p class="muted">${esc(session.day_name)} / ${esc(attendanceDay(session.held_on))} / Versi ${esc(session.version)}</p></div>${attendanceBadge(session.status)}</div>${alertBox(draft ? 'Draft belum masuk rekap. Daftar mahasiswa merupakan snapshot tetap, termasuk anggota yang kemudian berpindah atau nonaktif. Tidak ada status yang dipilih otomatis.' : 'Pertemuan final bersifat hanya baca dan sudah masuk rekap. Untuk koreksi, buka kembali dengan alasan; pertemuan akan keluar dari rekap sampai difinalisasi lagi.')}<div data-conflict></div><div data-counts aria-live="polite">${counters(attendanceCounts(session.records))}</div></section>
        <form data-session-form class="stack"><div data-errors></div><fieldset class="attendance-fieldset stack" data-editor ${draft ? '' : 'disabled'}><section class="panel stack"><div class="panel-header"><h2>Informasi pertemuan</h2></div>${draft ? `${field('lecturer_name', 'Dosen pada pertemuan ini', { value: session.course.lecturer_name, required: true, maxLength: 160, help: 'Koreksi hanya untuk snapshot draft ini, bukan katalog mata kuliah.' })}${meetingFields(catalog, session)}` : `<dl><dt>Dosen</dt><dd>${esc(session.course.lecturer_name)}</dd><dt>Tanggal / hari</dt><dd>${esc(attendanceDay(session.held_on))}</dd><dt>Jam (WIB)</dt><dd>${session.starts_at ? `${esc(session.starts_at.slice(0, 5))} - ${esc(session.ends_at?.slice(0, 5))}` : 'Tidak dicatat'}</dd><dt>Topik</dt><dd class="prose">${esc(session.topic || 'Tidak dicatat')}</dd><dt>Catatan</dt><dd class="prose">${esc(session.notes || 'Tidak ada catatan')}</dd></dl>`}</section></fieldset><section class="panel stack"><div class="panel-header"><h2>Daftar kehadiran</h2>${draft ? '<button class="button secondary" type="button" data-all-present>Tandai semua hadir</button>' : ''}</div>${field('roster_search', 'Cari nama atau NIM', { type: 'search', placeholder: 'Cari mahasiswa...', autocomplete: 'off' })}<p class="muted" data-visible aria-live="polite"></p><fieldset class="attendance-fieldset" data-records>${rosterMarkup(session, draft)}</fieldset>${draft ? '<p class="muted">Pencarian hanya menyembunyikan baris. Semua mahasiswa tetap disimpan, termasuk yang tidak terlihat.</p>' : ''}</section>${draft ? '<section class="panel attendance-save"><div><strong data-save-state>Draft tersimpan</strong><p class="muted">Simpan draft kapan saja. Finalisasi hanya setelah seluruh mahasiswa ditandai.</p></div><div class="toolbar"><button class="button secondary" type="submit">Simpan draft</button><button class="button" type="button" data-finalize>Simpan & finalisasi</button></div></section>' : ''}</form>
        ${draft ? '' : `<section class="panel"><details><summary>Buka kembali untuk koreksi</summary><form data-reopen class="stack"><div data-errors></div>${textarea('reason', 'Alasan membuka kembali', { required: true, minLength: 10, maxLength: 1000, help: '10 sampai 1.000 karakter. Alasan dan pelaku dicatat pada riwayat.' })}<button class="button danger" type="submit">Buka kembali menjadi draft</button></form></details></section>`}
        <section class="panel"><div class="panel-header"><h2>Riwayat perubahan</h2>${icon('clock')}</div><p class="muted">Dibuat ${esc(historyTime(session.created_at))} oleh ${esc(session.created_by?.name || 'Tidak tersedia')}. Diperbarui ${esc(historyTime(session.updated_at))} oleh ${esc(session.updated_by?.name || 'Tidak tersedia')}.${session.finalized_at ? ` Finalisasi terakhir ${esc(historyTime(session.finalized_at))}.` : ''}</p>${session.history.length ? `<ol class="timeline">${session.history.map((entry) => `<li class="timeline-item"><strong>${esc(historyLabels[entry.action] || entry.action)}</strong><p>${esc(entry.actor?.name || 'Tidak tersedia')}</p><small>${esc(historyTime(entry.created_at))}</small>${entry.reason ? `<p class="prose">${esc(entry.reason)}</p>` : ''}</li>`).join('')}</ol>` : empty('Belum ada riwayat perubahan', 'Aktivitas pertemuan yang dicatat server akan muncul di sini.')}</section>`, '<a class="button secondary" href="#/attendance">Kembali ke pertemuan</a>');

    const form = ctx.view.querySelector('[data-session-form]');
    const search = form.elements.namedItem('roster_search');
    const rows = [...form.querySelectorAll('[data-student]')];
    const updateSearch = () => {
        let visible = 0;
        rows.forEach((row, index) => { row.hidden = !matchesAttendanceStudent(session.records[index], search.value); if (!row.hidden) visible++; });
        form.querySelector('[data-visible]').textContent = `${visible} dari ${session.records.length} mahasiswa ditampilkan`;
        form.querySelector('[data-roster-empty]').hidden = visible !== 0;
    };
    search.addEventListener('input', updateSearch);
    updateSearch();
    const showConflict = (error) => {
        if (error.status !== 409 || !ctx.view.isConnected) return;
        const area = ctx.view.querySelector('[data-conflict]');
        area.innerHTML = `${alertBox(`${error.message} Data mungkin berubah di tab lain. Isian Anda tetap ada dan tidak ditimpa. Tinjau atau salin perubahan sebelum memilih muat ulang.`, 'warning')}<button class="button secondary" type="button" data-reload-conflict>Muat versi terbaru</button>`;
        area.querySelector('button').addEventListener('click', (event) => ctx.action(event.currentTarget, async () => {
            if (!await ctx.confirm({ title: 'Ganti isian dengan versi server?', message: 'Perubahan yang belum tersimpan pada halaman ini akan dibuang. Pilih Batal untuk tetap meninjau atau menyalin isian Anda.', confirmLabel: 'Muat versi terbaru' })) return;
            if (ctx.view.isConnected) ctx.refresh();
        }));
    };
    if (draft) {
        bindDay(form);
        let dirty = false;
        ctx.guardUnsavedChanges(() => dirty);
        const updateCounts = () => {
            const counts = attendanceCounts(session.records.map((record, index) => ({ status: form.elements.namedItem(`records.${index}.status`).value })));
            ctx.view.querySelector('[data-counts]').innerHTML = counters(counts);
            form.querySelector('[data-finalize]').disabled = counts.total === 0 || counts.unmarked > 0;
        };
        const markDirty = () => { dirty = true; form.querySelector('[data-save-state]').textContent = 'Ada perubahan belum disimpan'; updateCounts(); };
        form.addEventListener('input', (event) => { if (event.target !== search) markDirty(); });
        form.addEventListener('change', (event) => { if (event.target !== search) markDirty(); });
        updateCounts();
        const lockFields = (locked) => { form.querySelector('[data-editor]').disabled = locked; form.querySelector('[data-records]').disabled = locked; form.querySelector('[data-all-present]').disabled = locked; form.querySelector('[data-finalize]').disabled = locked; };
        form.querySelector('[data-all-present]').addEventListener('click', () => ctx.action(form, async () => {
            lockFields(true);
            try {
                if (!await ctx.confirm({ title: 'Tandai seluruh mahasiswa hadir?', message: `Semua ${session.records.length} mahasiswa, termasuk yang tersembunyi oleh pencarian, akan diberi status Hadir. Status Izin, Sakit, dan Alpa yang sudah diisi juga akan diganti. Catatan tetap dipertahankan.`, confirmLabel: 'Tandai semua hadir' })) return;
                if (!ctx.view.isConnected) return;
                session.records.forEach((record, index) => { form.elements.namedItem(`records.${index}.status`).value = 'present'; });
                markDirty();
            } finally { lockFields(false); if (form.isConnected) updateCounts(); }
        }));
        const save = async (finalize) => {
            const body = serializeAttendanceSession(formDataObject(form), catalog.today, session);
            if (finalize && (body.records.length === 0 || attendanceCounts(body.records).unmarked)) invalid('Isi status seluruh mahasiswa sebelum finalisasi.', 'records');
            lockFields(true);
            let savedDraft = false;
            try {
                if (finalize && !await ctx.confirm({ title: 'Finalisasi absensi?', message: 'Semua isian akan disimpan, pertemuan menjadi hanya baca dan masuk ke rekap. Koreksi berikutnya memerlukan alasan membuka kembali.', confirmLabel: 'Simpan & finalisasi' })) return;
                if (!ctx.view.isConnected) return;
                if (dirty || !finalize) {
                    const result = await ctx.api(`/attendance/sessions/${idPath(session.id)}`, { method: 'PATCH', body });
                    if (!ctx.view.isConnected) return;
                    session = result.data;
                    savedDraft = true;
                    dirty = false;
                    form.querySelector('[data-save-state]').textContent = 'Draft tersimpan';
                }
                if (finalize) {
                    const result = await ctx.api(`/attendance/sessions/${idPath(session.id)}/finalize`, { method: 'POST', body: { version: session.version } });
                    if (!ctx.view.isConnected) return;
                    session = result.data;
                }
                if (!ctx.view.isConnected) return;
                ctx.toast(finalize ? 'Absensi final dan sudah masuk rekap.' : 'Draft absensi tersimpan.');
                sessionPage(ctx, catalog, session);
                ctx.view.querySelector('h1')?.focus({ preventScroll: true });
            } catch (error) {
                if (ctx.view.isConnected && error.name !== 'AbortError') {
                    if (savedDraft) error.message = `Draft sudah tersimpan. Finalisasi belum dapat dipastikan. ${error.message}`;
                    if (Object.keys(error.details || {}).some((key) => key.startsWith('records.'))) { search.value = ''; updateSearch(); }
                    showConflict(error);
                }
                throw error;
            }
            finally { lockFields(false); if (form.isConnected) updateCounts(); }
        };
        ctx.form(form, () => save(false));
        form.querySelector('[data-finalize]').addEventListener('click', () => { if (form.reportValidity()) return ctx.action(form, () => save(true)); });
    } else {
        form.addEventListener('submit', (event) => event.preventDefault());
        const reopen = ctx.view.querySelector('[data-reopen]');
        ctx.form(reopen, async () => {
            const reason = limited(formDataObject(reopen).reason, 'reason', 'Alasan', 1000, true);
            if ([...reason].length < 10) invalid('Alasan minimal 10 karakter.', 'reason');
            const control = reopen.elements.namedItem('reason');
            control.readOnly = true;
            try {
                if (!await ctx.confirm({ title: 'Buka kembali absensi?', message: 'Pertemuan akan menjadi draft dan tidak dihitung dalam rekap sampai difinalisasi lagi. Alasan pembukaan kembali disimpan pada riwayat.', confirmLabel: 'Buka kembali' })) return;
                if (!ctx.view.isConnected) return;
                const { data } = await ctx.api(`/attendance/sessions/${idPath(session.id)}/reopen`, { method: 'POST', body: { version: session.version, reason } });
                if (!ctx.view.isConnected) return;
                ctx.toast('Absensi dibuka kembali. Koreksi draft lalu finalisasi ulang.');
                sessionPage(ctx, catalog, data);
                ctx.view.querySelector('h1')?.focus({ preventScroll: true });
            } catch (error) { showConflict(error); throw error; }
            finally { control.readOnly = false; }
        });
    }
}

async function summaryPage(ctx, catalog, params) {
    const query = attendanceFilters(params, true);
    const { data } = await ctx.api(`/attendance/summary${query.size ? `?${query}` : ''}`);
    if (!ctx.view.isConnected) return;
    ctx.page('Rekap kehadiran', 'Rekap hanya dari pertemuan final pada mata kuliah dan rentang tanggal terpilih.', `${pageTop(catalog, 'summary')}${filtersMarkup(catalog, query, true)}${alertBox('Tanpa filter, seluruh pertemuan final dihitung. Persentase hadir setiap mahasiswa adalah Hadir dibagi jumlah snapshot pertemuan final yang menyertakan mahasiswa tersebut, bukan jumlah pertemuan seluruh kelas. Draft dan pertemuan yang dibuka kembali tidak dihitung.')}<div class="metrics-grid">${[['Pertemuan final', data.sessions_count], ['Total catatan mahasiswa', data.totals.total], ['Kehadiran', attendanceRate(data.totals.attendance_rate)], ['Mata kuliah', catalog.courses.find((course) => String(course.id) === query.get('course_id'))?.code || 'Semua']].map(([label, value], index) => `<article class="metric metric-${index}"><span class="metric-heading">${label}</span><strong class="metric-number attendance-metric-value">${esc(value)}</strong><small>Sesuai filter yang diterapkan</small></article>`).join('')}</div><section class="panel stack"><div class="toolbar">${Object.entries(attendanceStatuses).map(([key, label]) => `<span class="badge status-${key}">${label}: ${esc(data.totals[key])}</span>`).join('')}</div><div class="panel-header"><h2>Rekap per mahasiswa</h2><span class="muted">${esc(data.students.length)} mahasiswa</span></div>${data.students.length ? dataTable([{ label: 'Mahasiswa', render: (student) => `<strong>${esc(student.name)}</strong><small>NIM ${esc(student.nim || 'Belum tersedia')}</small>` }, ...Object.entries(attendanceStatuses).map(([key, label]) => ({ key, label })), { key: 'total', label: 'Total pertemuan' }, { label: '% Hadir', render: (student) => esc(attendanceRate(student.attendance_rate)) }], data.students, 'Rekap absensi mahasiswa dari pertemuan final') : empty('Belum ada rekap final', 'Finalisasi pertemuan atau ubah filter. Nilai kosong tidak berarti mahasiswa alpa.')}</section>`, `<button class="button secondary" type="button" data-export>${icon('download')} Unduh CSV</button>`);
    bindFilters(ctx, true);
    ctx.view.querySelector('[data-export]').addEventListener('click', (event) => ctx.action(event.currentTarget, async () => {
        const blob = await ctx.api(`/attendance/export${query.size ? `?${query}` : ''}`, { binary: true });
        if (!ctx.view.isConnected) return;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `rekap-absensi-${catalog.today}.csv`;
        try { document.body.append(link); link.click(); }
        finally { link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
        ctx.toast('CSV diunduh sesuai filter yang diterapkan.');
    }));
}
