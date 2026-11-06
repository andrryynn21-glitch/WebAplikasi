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
        name: limited(values.name, 'name', 'Nama mata kuliah', 160, true),
        lecturer_name: limited(values.lecturer_name, 'lecturer_name', 'Nama dosen', 160, true),
    };
    const code = limited(values.code, 'code', 'Kode mata kuliah', 32);
    if (code) body.code = code;
    if (text(values.semester)) {
        const semester = Number(values.semester);
        if (!Number.isInteger(semester) || semester < 1 || semester > 14) invalid('Semester mata kuliah harus 1 sampai 14.', 'semester');
        body.semester = semester;
    }
    if (editing) body.is_active = values.is_active === true || values.is_active === 'true';
    return body;
}

export function serializeAttendanceStudent(values, editing = false) {
    const body = {
        name: limited(values.name, 'name', 'Nama mahasiswa', 255, true),
        nim: limited(values.nim, 'nim', 'NIM', 32),
    };
    if (editing) body.is_active = values.is_active === true || values.is_active === 'true';
    return body;
}

export function parseAttendanceStudents(value) {
    const lines = String(value ?? '').split(/\r\n|\n|\r/).filter((line) => text(line));
    if (!lines.length || lines.length > 100) invalid('Tempel 1 sampai 100 mahasiswa per penyimpanan.', 'students');
    const nims = new Set();
    return lines.map((line, index) => {
        const parts = line.split(/[;\t]/);
        if (parts.length > 2) invalid(`Baris ${index + 1}: gunakan Nama;NIM atau dua kolom dipisahkan Tab.`, 'students');
        let student;
        try { student = serializeAttendanceStudent({ name: parts[0], nim: parts[1] }); }
        catch (error) { invalid(`Baris ${index + 1}: ${error.message}`, 'students'); }
        const nimKey = student.nim.toLocaleLowerCase('id-ID');
        if (student.nim && nims.has(nimKey)) invalid(`Baris ${index + 1}: NIM ${student.nim} berulang dalam daftar.`, 'students');
        if (student.nim) nims.add(nimKey);
        return student;
    });
}

export function serializeAttendanceSession(values, today, session = null) {
    const meeting = text(values.meeting_number) ? Number(values.meeting_number) : null;
    if ((session || meeting !== null) && (!Number.isInteger(meeting) || meeting < 1 || meeting > 100)) invalid('Nomor pertemuan harus 1 sampai 100.', 'meeting_number');
    if (!validDate(values.held_on || '') || values.held_on > today) invalid('Tanggal pertemuan harus valid dan tidak melebihi hari ini.', 'held_on');
    const start = text(values.starts_at), end = text(values.ends_at);
    if (Boolean(start) !== Boolean(end)) invalid('Isi jam mulai dan selesai, atau kosongkan keduanya.', start ? 'ends_at' : 'starts_at');
    for (const [name, time] of [['starts_at', start], ['ends_at', end]]) {
        if (time && !/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) invalid('Jam harus menggunakan format HH:mm.', name);
    }
    if (start && end <= start) invalid('Jam selesai harus setelah jam mulai pada hari yang sama.', 'ends_at');
    const body = {
        held_on: values.held_on, starts_at: start || null, ends_at: end || null,
        topic: limited(values.topic, 'topic', 'Topik', 255) || null,
        notes: limited(values.notes, 'notes', 'Catatan pertemuan', 2000) || null,
    };
    if (meeting !== null) body.meeting_number = meeting;
    if (!session) {
        if (values.course_id === 'new') {
            try {
                body.course = serializeAttendanceCourse(Object.fromEntries(['name', 'lecturer_name', 'semester', 'code'].map((key) => [key, values[`course.${key}`]])));
            } catch (error) {
                error.details = Object.fromEntries(Object.entries(error.details).map(([key, messages]) => [`course.${key}`, messages]));
                throw error;
            }
        } else {
            if (!positiveId(values.course_id)) invalid('Pilih mata kuliah aktif atau buat mata kuliah baru.', 'course_id');
            body.course_id = Number(values.course_id);
        }
    } else {
        body.version = session.version;
        body.lecturer_name = limited(values.lecturer_name, 'lecturer_name', 'Nama dosen', 160, true);
        // Snapshot IDs and order come from the session, never from filtered rows or editable inputs.
        body.records = session.records.map((record, index) => {
            const name = `records.${index}.status`;
            if (!positiveId(record.student_id)) invalid('ID snapshot mahasiswa tidak tersedia. Muat ulang setelah menyimpan salinan perubahan.', 'records');
            if (!Object.hasOwn(values, name)) invalid('Data mahasiswa tidak lengkap. Muat ulang setelah menyimpan salinan perubahan.', name);
            const status = values[name] || null;
            if (status !== null && !Object.hasOwn(attendanceStatuses, status)) invalid('Pilih status kehadiran yang valid.', name);
            return { student_id: record.student_id, status, note: limited(values[`records.${index}.note`], `records.${index}.note`, 'Catatan mahasiswa', 500) || null };
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
    return `<section class="panel attendance-class"><div><span class="eyebrow">KELAS ANDA / ${esc(catalog.class.code)}</span><h2>${esc(catalog.class.name)}</h2><p class="muted">Periode akademik: ${esc(catalog.class.academic_term?.name)} / ${esc(catalog.class.academic_term?.code)}<br>Semester kelas: ${esc(catalog.class.semester)} / Waktu ${esc(catalog.timezone)}<br>Hari ini ${esc(attendanceDay(catalog.today))}</p></div><nav class="toolbar" aria-label="Navigasi absensi">${[['students', '/attendance/students', 'Mahasiswa'], ['sessions', '/attendance', 'Pertemuan'], ['courses', '/attendance/courses', 'Mata kuliah'], ['summary', '/attendance/summary', 'Rekap & CSV']].map(([key, path, label]) => `<a class="button ${key === active ? '' : 'secondary'}" href="#${path}" ${key === active ? 'aria-current="page"' : ''}>${label}</a>`).join('')}</nav></section>`;
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
    if (!detail && (segments.length > 2 || ![undefined, 'students', 'courses', 'new', 'summary'].includes(route))) {
        ctx.page('Halaman tidak tersedia', 'Alamat absensi tidak dikenali.', empty('Kembali ke absensi', 'Pilih pertemuan dari daftar.', '<a class="button" href="#/attendance">Daftar pertemuan</a>'));
        return;
    }
    const { data: catalog } = await ctx.api('/attendance');
    if (!ctx.view.isConnected) return;
    if (route === 'students') return studentsPage(ctx, catalog);
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
    ctx.page('Absensi kelas', 'Siapkan mahasiswa, pilih mata kuliah, lalu isi kehadiran.', `${pageTop(catalog, 'sessions')}${rosterStep(catalog)}${filtersMarkup(catalog, query)}<section class="panel"><div class="panel-header"><h2>Riwayat pertemuan</h2><span class="muted">${esc(meta?.pagination?.total ?? data.length)} pertemuan</span></div>${data.length ? `<div class="content-list">${data.map((session) => `<a class="list-item attendance-session" href="#/attendance/sessions/${esc(idPath(session.id))}"><span class="row-icon">${icon('check')}</span><span class="list-main"><strong>${esc(session.course.code)} / ${esc(session.course.name)}</strong><small>Pertemuan ${esc(session.meeting_number)} / ${esc(session.day_name)} / ${esc(attendanceDay(session.held_on))}${session.starts_at ? ` / ${esc(session.starts_at.slice(0, 5))} - ${esc(session.ends_at?.slice(0, 5))} WIB` : ''}</small><small>Dosen: ${esc(session.course.lecturer_name)} / Semester ${esc(session.semester ?? session.course.semester)}</small>${session.topic ? `<small>${esc(session.topic)}</small>` : ''}<small>Hadir ${esc(session.counts.present)} / Izin ${esc(session.counts.excused)} / Sakit ${esc(session.counts.sick)} / Alpa ${esc(session.counts.absent)} / Belum diisi ${esc(session.counts.unmarked)} / Total ${esc(session.counts.total)}</small></span>${attendanceBadge(session.status)}<span class="row-arrow">${icon('arrow')}</span></a>`).join('')}</div>` : empty('Belum ada pertemuan pada filter ini', 'Buat pertemuan baru atau ubah filter untuk melihat riwayat absensi.')}${pagination(meta?.pagination, '/attendance', query)}</section>`, `<a class="button" href="#/attendance/${catalog.roster.length ? 'new' : 'students'}">${icon('plus')} ${catalog.roster.length ? 'Buat pertemuan' : 'Tambah mahasiswa'}</a>`);
    bindFilters(ctx);
}

function rosterStep(catalog) {
    return `<section class="panel stack"><div><span class="eyebrow">1. DAFTAR MAHASISWA</span><h2>${catalog.roster.length ? `${esc(catalog.roster.length)} mahasiswa siap diabsen` : 'Tambahkan mahasiswa terlebih dahulu'}</h2></div><p class="muted">Isi nama dan NIM sendiri, satu per satu atau tempel sekaligus. Mahasiswa tidak perlu memiliki akun. Daftar ini tidak membuat akun, keanggotaan kelas, atau verifikasi identitas.</p><div class="toolbar"><a class="button ${catalog.roster.length ? 'secondary' : ''}" href="#/attendance/students">${catalog.roster.length ? 'Kelola mahasiswa' : 'Tambah mahasiswa'}</a>${catalog.roster.length ? '<a class="button" href="#/attendance/new">2. Buat pertemuan</a>' : ''}</div></section>`;
}

async function studentsPage(ctx, catalog) {
    const { data: students } = await ctx.api('/attendance/students');
    if (!ctx.view.isConnected) return;
    ctx.page('Mahasiswa absensi', 'Daftar mandiri ketua kelas, tanpa menunggu mahasiswa mendaftar akun.', `${pageTop(catalog, 'students')}${alertBox('Daftar ini hanya untuk absensi. Menambah mahasiswa tidak membuat akun, keanggotaan kelas, atau verifikasi identitas. Arsip tidak menghapus riwayat; perubahan nama dan NIM hanya berlaku pada pertemuan baru.')}<section class="panel stack"><div class="panel-header"><h2 data-student-title>Tambah mahasiswa</h2></div><form data-student-editor class="stack"><div data-errors></div><fieldset class="attendance-fieldset stack" data-student-fields>${selectField('mode', 'Cara menambah', [{ value: 'single', label: 'Satu mahasiswa' }, { value: 'bulk', label: 'Tempel banyak mahasiswa' }], { value: 'single' })}<fieldset class="attendance-fieldset form-grid" data-single-student>${field('name', 'Nama mahasiswa', { required: true, maxLength: 255 })}${field('nim', 'NIM (opsional)', { maxLength: 32, help: 'Teks, bukan angka. Nol di depan tetap disimpan.' })}<div data-student-status hidden>${selectField('is_active', 'Status mahasiswa', [{ value: 'true', label: 'Aktif' }, { value: 'false', label: 'Arsip' }], { value: 'true' })}</div></fieldset><fieldset class="attendance-fieldset" data-bulk-students hidden disabled>${textarea('students', 'Daftar nama dan NIM', { required: true, rows: 7, placeholder: 'Nama Mahasiswa;001234\nNama Tanpa NIM', help: 'Satu mahasiswa per baris: Nama;NIM atau dua kolom dipisahkan Tab. NIM boleh kosong. Maksimal 100 baris sekali simpan; seluruh baris harus valid.' })}</fieldset></fieldset><p class="muted">Maksimal 1.000 mahasiswa aktif. NIM yang terisi harus unik, termasuk pada mahasiswa arsip. Jika penyimpanan gagal, isian tetap tersedia untuk diperbaiki.</p><div class="toolbar"><button class="button" type="submit" data-student-save>Tambah mahasiswa</button><button class="button ghost" type="button" data-cancel-student hidden>Batal edit</button></div></form></section><section class="panel stack"><div class="panel-header"><h2>Daftar mahasiswa</h2><span class="muted">${esc(students.filter((student) => student.is_active).length)} aktif / ${esc(students.filter((student) => !student.is_active).length)} arsip</span></div>${students.length ? dataTable([{ key: 'name', label: 'Nama' }, { key: 'nim', label: 'NIM' }, { label: 'Status', render: (student) => badge(student.is_active ? 'active' : 'hidden', student.is_active ? 'Aktif' : 'Arsip') }, { label: 'Tindakan', render: (student) => `<button class="button secondary" type="button" data-edit-student="${esc(student.id)}" aria-label="Edit / arsipkan ${esc(student.name)}">Edit / arsip</button>` }], students, 'Daftar mahasiswa absensi aktif dan arsip') : empty('Daftar absensi masih kosong', 'Tambahkan nama sendiri atau tempel daftar kelas di atas. Akun terdaftar tidak otomatis menjadi mahasiswa absensi.')}</section>`, '<a class="button secondary" href="#/attendance/new">Lanjut ke pertemuan</a>');
    const form = ctx.view.querySelector('[data-student-editor]');
    const control = (name) => form.elements.namedItem(name);
    let editing = null, dirty = false;
    ctx.guardUnsavedChanges(() => dirty);
    const changeMode = () => {
        const bulk = !editing && control('mode').value === 'bulk';
        form.querySelector('[data-single-student]').hidden = bulk;
        form.querySelector('[data-single-student]').disabled = bulk;
        form.querySelector('[data-bulk-students]').hidden = !bulk;
        form.querySelector('[data-bulk-students]').disabled = !bulk;
        form.querySelector('[data-student-save]').textContent = editing ? 'Simpan mahasiswa' : bulk ? 'Tambah semua mahasiswa' : 'Tambah mahasiswa';
    };
    control('mode').addEventListener('change', changeMode);
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    const edit = async (student) => {
        if (dirty && !await ctx.confirm({ title: 'Ganti isian mahasiswa?', message: 'Isian yang belum disimpan akan dibuang. Pilih Batal untuk tetap memperbaikinya.', confirmLabel: 'Ganti isian' })) return;
        if (!ctx.view.isConnected) return;
        editing = student;
        form.reset();
        control('mode').disabled = Boolean(student);
        control('name').value = student?.name || '';
        control('nim').value = student?.nim || '';
        control('is_active').value = String(student?.is_active ?? true);
        ctx.view.querySelector('[data-student-title]').textContent = student ? 'Edit mahasiswa' : 'Tambah mahasiswa';
        form.querySelector('[data-student-status]').hidden = !student;
        form.querySelector('[data-cancel-student]').hidden = !student;
        form.querySelector('[data-errors]').innerHTML = '';
        dirty = false;
        changeMode();
        control('name').focus();
    };
    ctx.view.querySelectorAll('[data-edit-student]').forEach((button) => button.addEventListener('click', () => ctx.action(form, () => edit(students.find((student) => String(student.id) === button.dataset.editStudent)))));
    form.querySelector('[data-cancel-student]').addEventListener('click', () => ctx.action(form, () => edit(null)));
    ctx.form(form, async () => {
        const values = formDataObject(form);
        const body = editing ? serializeAttendanceStudent(values, true) : { students: values.mode === 'bulk' ? parseAttendanceStudents(values.students) : [serializeAttendanceStudent(values)] };
        const fields = form.querySelector('[data-student-fields]');
        fields.disabled = true;
        try {
            if (editing?.is_active && !body.is_active && !await ctx.confirm({ title: 'Arsipkan mahasiswa?', message: `${editing.name} tidak akan dimasukkan ke pertemuan baru. Seluruh riwayat kehadiran tetap tersimpan.`, confirmLabel: 'Arsipkan' })) return;
            if (!ctx.view.isConnected) return;
            await ctx.api(editing ? `/attendance/students/${idPath(editing.id)}` : '/attendance/students', { method: editing ? 'PATCH' : 'POST', body });
            if (!ctx.view.isConnected) return;
            dirty = false;
            ctx.toast(editing ? 'Mahasiswa diperbarui.' : `${body.students.length} mahasiswa ditambahkan.`);
            ctx.refresh();
        } finally { fields.disabled = false; }
    });
}

function courseForm(course = {}, semester) {
    const editing = Boolean(course.id);
    return `<form data-course="${esc(course.id || '')}" class="stack"><div data-errors></div><fieldset class="attendance-fieldset"><div class="form-grid">${field('name', 'Nama mata kuliah', { value: course.name, required: true, maxLength: 160 })}${field('lecturer_name', 'Nama dosen pengampu', { value: course.lecturer_name, required: true, maxLength: 160, help: 'Isi nama dosen secara langsung, tanpa akun dosen.' })}${field('semester', 'Semester mata kuliah', { type: 'number', value: course.semester ?? semester, min: 1, max: 14, step: 1, help: 'Nomor semester 1 sampai 14, bukan periode akademik ganjil/genap.' })}${field('code', 'Kode mata kuliah (opsional)', { value: course.code, maxLength: 32, help: editing ? 'Kosongkan untuk mempertahankan kode saat ini.' : 'Kosongkan agar kode dibuat otomatis.' })}${editing ? selectField('is_active', 'Status mata kuliah', [{ value: 'true', label: 'Aktif' }, { value: 'false', label: 'Arsip' }], { value: String(course.is_active) }) : ''}</div></fieldset><button class="button ${editing ? 'secondary' : ''}" type="submit">${editing ? 'Simpan mata kuliah' : 'Tambah mata kuliah'}</button></form>`;
}

function coursesPage(ctx, catalog) {
    ctx.page('Mata kuliah kelas', 'Kelola mata kuliah, dosen, dan semester tanpa mengubah riwayat pertemuan.', `${pageTop(catalog, 'courses')}<section class="panel"><div class="panel-header"><h2>Tambah mata kuliah</h2>${icon('plus')}</div>${courseForm({}, catalog.class.semester)}</section><section class="panel"><div class="panel-header"><h2>Katalog mata kuliah</h2><span class="muted">${esc(catalog.courses.length)} mata kuliah</span></div>${alertBox('Arsip menutup pembuatan pertemuan baru, bukan menghapus riwayat. Perubahan katalog tidak mengganti snapshot pertemuan yang sudah ada; nama dosen dapat dikoreksi pada draft.')}${catalog.courses.length ? `<div class="stack">${catalog.courses.map((course) => `<article class="attendance-course"><div class="panel-header"><div><h3>${esc(course.code)} / ${esc(course.name)}</h3><p class="muted">${esc(course.lecturer_name)} / Semester ${esc(course.semester)} / ${esc(course.sessions_count)} pertemuan</p></div>${badge(course.is_active ? 'active' : 'hidden', course.is_active ? 'Aktif' : 'Arsip')}</div><div class="toolbar"><a class="text-link" href="#/attendance?course_id=${esc(idPath(course.id))}">Lihat riwayat ${icon('arrow')}</a>${course.is_active ? `<a class="button secondary" href="#/attendance/new?course_id=${esc(idPath(course.id))}">Buat pertemuan</a>` : ''}</div><details><summary>Edit / arsipkan ${esc(course.code)}</summary>${courseForm(course, catalog.class.semester)}</details></article>`).join('')}</div>` : empty('Katalog masih kosong', 'Mata kuliah juga dapat dibuat langsung saat membuat pertemuan.')}</section>`);
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

function meetingFields(catalog, session = null) {
    const meeting = field('meeting_number', session ? 'Pertemuan ke' : 'Ubah nomor pertemuan (opsional)', { type: 'number', min: 1, max: 100, step: 1, required: Boolean(session), value: session?.meeting_number, placeholder: 'Otomatis', help: session ? 'Nomor pertemuan 1 sampai 100.' : 'Kosongkan untuk nomor berikutnya otomatis dari server, maksimal 100.' });
    return `<div class="form-grid">${session ? meeting : ''}${field('held_on', 'Tanggal pertemuan', { type: 'date', required: true, max: catalog.today, value: session?.held_on || catalog.today, help: 'Tidak boleh setelah hari ini (Asia/Jakarta).' })}</div><p class="muted" data-day aria-live="polite">${esc(attendanceDay(session?.held_on || catalog.today))}</p><details data-meeting-advanced><summary>Pengaturan tambahan (opsional)</summary><div class="stack">${session ? '' : meeting}<div class="form-grid">${field('starts_at', 'Jam mulai (WIB)', { type: 'time', value: session?.starts_at?.slice(0, 5), help: 'Opsional. Isi bersama jam selesai.' })}${field('ends_at', 'Jam selesai (WIB)', { type: 'time', value: session?.ends_at?.slice(0, 5), help: 'Harus setelah jam mulai pada hari yang sama.' })}</div>${field('topic', 'Topik / materi (opsional)', { value: session?.topic, maxLength: 255 })}${textarea('notes', 'Catatan pertemuan (opsional)', { value: session?.notes, maxLength: 2000, rows: 3 })}</div></details>`;
}

function bindMeetingFields(form) {
    form.elements.namedItem('held_on').addEventListener('input', (event) => { form.querySelector('[data-day]').textContent = attendanceDay(event.target.value); });
    form.addEventListener('invalid', (event) => {
        const details = event.target.closest('details');
        if (details) details.open = true;
    }, true);
}

function newSessionPage(ctx, catalog, params) {
    const courses = catalog.courses.filter((course) => course.is_active);
    const selected = courses.find((course) => String(course.id) === params.get('course_id')) || courses[0];
    const content = !catalog.roster.length ? rosterStep(catalog)
        : `<section class="panel stack"><div><span class="eyebrow">2. MATA KULIAH & PERTEMUAN</span><h2>Siapkan pertemuan hari ini</h2></div><form data-new-session class="stack"><div data-errors></div><fieldset class="attendance-fieldset stack">${selectField('course_id', 'Mata kuliah', [...courses.map((course) => ({ value: course.id, label: `${course.name} / Semester ${course.semester}` })), { value: 'new', label: '+ Mata kuliah baru' }], { required: true, value: selected?.id || 'new' })}<p class="muted" data-course-info></p><fieldset class="attendance-fieldset stack" data-inline-course ${selected ? 'hidden disabled' : ''}><div class="form-grid">${field('course.name', 'Nama mata kuliah baru', { required: true, maxLength: 160 })}${field('course.lecturer_name', 'Nama dosen pengampu', { required: true, maxLength: 160 })}${field('course.semester', 'Semester mata kuliah', { type: 'number', min: 1, max: 14, step: 1, value: catalog.class.semester, help: 'Nomor semester 1 sampai 14, bukan periode akademik ganjil/genap.' })}</div><details><summary>Kode mata kuliah (opsional)</summary>${field('course.code', 'Kode mata kuliah', { maxLength: 32, help: 'Kosongkan agar kode dibuat otomatis.' })}</details></fieldset><p data-next-meeting aria-live="polite"></p>${meetingFields(catalog)}${alertBox(`${catalog.roster.length} mahasiswa aktif dari daftar absensi mandiri akan disalin saat pembuatan. Perubahan atau arsip mahasiswa sesudahnya tidak mengubah pertemuan ini. Semua status awal kosong, bukan otomatis hadir.`)}<details><summary>Pratinjau ${esc(catalog.roster.length)} mahasiswa saat ini</summary>${dataTable([{ key: 'name', label: 'Nama' }, { key: 'nim', label: 'NIM' }], catalog.roster, 'Pratinjau mahasiswa aktif')}<a class="text-link" href="#/attendance/students">Kelola mahasiswa</a></details></fieldset><div class="toolbar"><button class="button" type="submit">Buat draft & isi absensi</button><a class="button ghost" href="#/attendance">Batal</a></div></form></section>`;
    ctx.page('Buat pertemuan', 'Satu pertemuan untuk satu mata kuliah, tanggal, dan daftar mahasiswa.', `${pageTop(catalog, 'sessions')}${content}`);
    const form = ctx.view.querySelector('[data-new-session]');
    if (!catalog.roster.length || !form) return;
    bindMeetingFields(form);
    let dirty = false;
    ctx.guardUnsavedChanges(() => dirty);
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    const chosenCourse = () => courses.find((course) => String(course.id) === form.elements.namedItem('course_id').value);
    const changeCourse = () => {
        const course = chosenCourse();
        const inline = form.querySelector('[data-inline-course]');
        inline.hidden = Boolean(course);
        inline.disabled = Boolean(course);
        const info = form.querySelector('[data-course-info]');
        info.hidden = !course;
        info.textContent = course ? `Dosen: ${course.lecturer_name} / Semester mata kuliah: ${course.semester}` : '';
        const next = course?.next_meeting_number ?? 1;
        form.querySelector('[data-next-meeting]').textContent = next > 100 ? 'Nomor berikutnya melebihi batas 100 pertemuan. Pilih mata kuliah lain atau isi nomor yang masih tersedia pada pengaturan tambahan.' : `Pertemuan ${next} (otomatis jika nomor tidak diubah)`;
        form.elements.namedItem('meeting_number').placeholder = String(next);
    };
    form.elements.namedItem('course_id').addEventListener('change', changeCourse);
    changeCourse();
    ctx.form(form, async () => {
        const body = serializeAttendanceSession(formDataObject(form), catalog.today);
        if (!body.meeting_number && chosenCourse()?.next_meeting_number > 100) invalid('Nomor pertemuan otomatis melebihi 100. Pilih mata kuliah lain atau isi nomor 1 sampai 100 yang belum digunakan.', 'meeting_number');
        const fields = form.querySelector('fieldset');
        fields.disabled = true;
        try {
            const { data } = await ctx.api('/attendance/sessions', { method: 'POST', body });
            if (!ctx.view.isConnected) return;
            dirty = false;
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
    ctx.page(`Pertemuan ${session.meeting_number}`, `${session.course.code} / ${session.course.name}`, `${pageTop(catalog, 'sessions')}<section class="panel"><div class="panel-header"><div><h2>${esc(session.course.name)}</h2><p class="muted">Semester mata kuliah: ${esc(session.semester ?? session.course.semester)} / ${esc(session.day_name)} / ${esc(attendanceDay(session.held_on))} / Versi ${esc(session.version)}</p></div>${attendanceBadge(session.status)}</div>${alertBox(draft ? 'Draft belum masuk rekap. Daftar mahasiswa merupakan snapshot tetap, termasuk mahasiswa yang kemudian diarsipkan. Tidak ada status yang dipilih otomatis.' : 'Pertemuan final bersifat hanya baca dan sudah masuk rekap. Untuk koreksi, buka kembali dengan alasan; pertemuan akan keluar dari rekap sampai difinalisasi lagi.')}<div data-conflict></div><div data-counts aria-live="polite">${counters(attendanceCounts(session.records))}</div></section>
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
        bindMeetingFields(form);
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
