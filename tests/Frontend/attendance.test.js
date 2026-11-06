import test from 'node:test';
import assert from 'node:assert/strict';
import { attendanceCounts, attendanceDay, attendanceFilters, attendancePage, attendanceRate, matchesAttendanceStudent, parseAttendanceStudents, serializeAttendanceCourse, serializeAttendanceSession, serializeAttendanceStudent } from '../../resources/js/pages/attendance.js';
import { navigation } from '../../resources/js/ui.js';

const today = '2026-11-03';
const leader = { role: 'class_leader', identity_status: 'verified', membership: { status: 'active' }, permissions: ['attendance.manage'] };
const room = { id: 3, code: 'IF-A', name: 'Kelas A', semester: 3, academic_term: { id: 1, code: '2026-1', name: 'Ganjil 2026', starts_on: '2026-09-01', ends_on: '2027-02-01' } };
const course = { id: 4, code: '001A', name: 'Algoritma', lecturer_name: 'Dosen Pengampu', semester: 5, is_active: true, sessions_count: 2, next_meeting_number: 9 };
const records = [{ student_id: 119, user_id: null, name: 'Nadia Putri', nim: '0019', status: null, note: null }, { student_id: 122, user_id: 22, name: 'Bima', nim: '0022', status: 'sick', note: 'Istirahat' }];
const catalog = { class: room, courses: [course], roster: records, timezone: 'Asia/Jakarta', today };
const session = { id: 10, course_id: 4, course, meeting_number: 2, held_on: today, day_name: 'Selasa', starts_at: '08:00', ends_at: '09:00', topic: 'Pengantar', notes: null, status: 'draft', version: 7, records, counts: attendanceCounts(records), history: [], created_at: '2026-11-03T00:00:00Z', updated_at: '2026-11-03T00:00:00Z', finalized_at: null, created_by: { id: 19, name: 'Nadia' }, updated_by: { id: 19, name: 'Nadia' } };
const values = { course_id: '4', meeting_number: '2', held_on: today, starts_at: '08:00', ends_at: '09:00', topic: ' Pengantar ', notes: '', lecturer_name: ' Dosen Pengampu ', 'records.0.status': '', 'records.0.note': '', 'records.1.status': 'sick', 'records.1.note': ' Istirahat ' };

test('attendance navigation requires permission, class readiness and the class leader role together', () => {
    assert.ok(navigation(leader).some((link) => link.path === '/attendance'));
    for (const change of [{ permissions: [] }, { role: 'student' }, { role: 'super_admin' }, { role: 'lecturer_coordinator' }, { role: 'identity_verifier' }, { identity_status: 'pending_verification' }, { membership: { status: 'revoked' } }, { membership: null }]) {
        assert.ok(!navigation({ ...leader, ...change }).some((link) => link.path === '/attendance'), JSON.stringify(change));
    }
});

test('counts never convert an unmarked or unrecognized student to present', () => {
    assert.deepEqual(attendanceCounts([{ status: 'present' }, { status: 'excused' }, { status: 'sick' }, { status: 'absent' }, { status: null }, { status: '' }, { status: 'unknown' }]), { present: 1, excused: 1, sick: 1, absent: 1, unmarked: 3, total: 7 });
    assert.deepEqual(attendanceCounts([]), { present: 0, excused: 0, sick: 0, absent: 0, unmarked: 0, total: 0 });
});

test('student search matches trimmed case-insensitive names and NIM without changing the roster', () => {
    const before = structuredClone(records);
    assert.equal(matchesAttendanceStudent(records[0], '  NADIA '), true);
    assert.equal(matchesAttendanceStudent(records[0], '0019'), true);
    assert.equal(matchesAttendanceStudent(records[0], 'Bima'), false);
    assert.equal(matchesAttendanceStudent({ name: null, nim: null }, ''), true);
    assert.deepEqual(records, before);
});

test('session and summary queries whitelist filters and reset invalid pagination', () => {
    const params = new URLSearchParams('course_id=4&date_from=2026-10-01&date_to=2026-11-03&status=draft&page=3&per_page=50&class_id=99&role=super_admin');
    assert.deepEqual(Object.fromEntries(attendanceFilters(params)), { course_id: '4', date_from: '2026-10-01', date_to: today, status: 'draft', page: '3', per_page: '50' });
    assert.deepEqual(Object.fromEntries(attendanceFilters(params, true)), { course_id: '4', date_from: '2026-10-01', date_to: today });
    assert.equal(attendanceFilters(new URLSearchParams(), true).toString(), '');
    assert.deepEqual(Object.fromEntries(attendanceFilters(new URLSearchParams('course_id=0&date_from=2026-02-30&date_to=bad&status=all&page=-1&per_page=1000'))), { page: '1', per_page: '20' });
});

test('calendar dates and attendance rates preserve date-only and no-data semantics', () => {
    assert.match(attendanceDay(today), /Selasa.*3 November 2026/);
    assert.equal(attendanceDay('2026-02-30'), 'Tanggal belum dipilih');
    assert.equal(attendanceRate(null), 'Belum ada data');
    assert.equal(attendanceRate(0), '0%');
    assert.equal(attendanceRate(83.3333), '83,33%');
});

test('course serialization retains textual codes, trims lecturer, validates semester and uses a boolean archive flag', () => {
    const input = { code: ' 001A ', name: ' Algoritma ', lecturer_name: ' Dosen luar akun ', semester: ' 5 ', is_active: 'false', class_id: '99' };
    assert.deepEqual(serializeAttendanceCourse(input), { code: '001A', name: 'Algoritma', lecturer_name: 'Dosen luar akun', semester: 5 });
    assert.equal(serializeAttendanceCourse(input, true).is_active, false);
    assert.equal(serializeAttendanceCourse({ ...input, is_active: 'true' }, true).is_active, true);
    for (const [name, length] of [['code', 32], ['name', 160], ['lecturer_name', 160]]) {
        if (name !== 'code') assert.throws(() => serializeAttendanceCourse({ ...input, [name]: ' ' }), (error) => Object.hasOwn(error.details, name));
        assert.throws(() => serializeAttendanceCourse({ ...input, [name]: 'a'.repeat(length + 1) }), /maksimal/);
    }
    for (const semester of ['0', '15', '1.5', 'NaN']) assert.throws(() => serializeAttendanceCourse({ ...input, semester }), /Semester mata kuliah/);
    for (const editing of [false, true]) {
        const body = serializeAttendanceCourse({ ...input, code: ' ', semester: '' }, editing);
        assert.equal(Object.hasOwn(body, 'code'), false, 'blank code omitted for server autogeneration/preservation');
        assert.equal(Object.hasOwn(body, 'semester'), false, 'blank semester uses server default');
    }
});

test('manual students whitelist name and textual optional NIM without creating account identifiers', () => {
    const input = { name: ' Nadia Putri ', nim: ' 0019 ', user_id: 19, student_id: 99, class_id: 3, role: 'super_admin', is_active: 'false' };
    assert.deepEqual(serializeAttendanceStudent(input), { name: 'Nadia Putri', nim: '0019' });
    assert.deepEqual(serializeAttendanceStudent({ name: 'Tanpa akun' }), { name: 'Tanpa akun', nim: '' });
    assert.deepEqual(serializeAttendanceStudent(input, true), { name: 'Nadia Putri', nim: '0019', is_active: false });
    assert.equal(serializeAttendanceStudent({ ...input, is_active: 'true' }, true).is_active, true);
    for (const [name, value] of [['name', ' '], ['name', 'a'.repeat(256)], ['nim', '0'.repeat(33)]]) assert.throws(() => serializeAttendanceStudent({ ...input, [name]: value }), (error) => Object.hasOwn(error.details, name));
});

test('bulk paste supports semicolon or Tab with optional NIM, CRLF, blanks and leading zeros', () => {
    assert.deepEqual(parseAttendanceStudents(' Nadia Putri ; 0019 \r\n Bima\t0022\n\nTanpa NIM\nNIM kosong;\n'), [
        { name: 'Nadia Putri', nim: '0019' }, { name: 'Bima', nim: '0022' }, { name: 'Tanpa NIM', nim: '' }, { name: 'NIM kosong', nim: '' },
    ]);
    assert.equal(parseAttendanceStudents(Array.from({ length: 100 }, (_, i) => `Mahasiswa ${i};00${i}`).join('\n')).length, 100);
});

test('bulk paste rejects the entire batch for invalid rows, duplicate nonempty NIM or batch limits', () => {
    for (const value of ['', ' \n\t ', 'Valid;001\n;002', 'Valid;001\nInvalid;002;extra', 'A;001\nB\t001', 'A;abc001\nB;ABC001', `Valid;001\n${'a'.repeat(256)}`, `Nama;${'0'.repeat(33)}`, Array(101).fill('Mahasiswa').join('\n')]) {
        assert.throws(() => parseAttendanceStudents(value), (error) => Object.hasOwn(error.details, 'students'), value);
    }
    assert.throws(() => parseAttendanceStudents('Valid;001\n;002'), /Baris 2/);
});

test('creation includes only metadata and a numeric course ID, never roster or forged version', () => {
    assert.deepEqual(serializeAttendanceSession({ ...values, version: 999, records: [{ user_id: 999 }], class_id: 88 }, today), { course_id: 4, meeting_number: 2, held_on: today, starts_at: '08:00', ends_at: '09:00', topic: 'Pengantar', notes: null });
    assert.equal(serializeAttendanceSession({ ...values, starts_at: '', ends_at: '' }, today).starts_at, null);
    assert.equal(serializeAttendanceSession({ ...values, starts_at: '', ends_at: '' }, today).ends_at, null);
});

test('draft serialization includes every fixed snapshot ID regardless of search and preserves null statuses', () => {
    const body = serializeAttendanceSession({ ...values, roster_search: 'Nadia', course_id: '999', version: 123, 'records.0.student_id': '900', 'records.0.user_id': '900', 'records.2.status': 'present' }, today, session);
    assert.deepEqual(body, { version: 7, lecturer_name: 'Dosen Pengampu', meeting_number: 2, held_on: today, starts_at: '08:00', ends_at: '09:00', topic: 'Pengantar', notes: null, records: [{ student_id: 119, status: null, note: null }, { student_id: 122, status: 'sick', note: 'Istirahat' }] });
    assert.throws(() => serializeAttendanceSession(values, today, { ...session, records: [{ ...records[0], student_id: null }] }), /ID snapshot mahasiswa/);
});

test('quick course creation sends nested whitelisted fields and omits automatic meeting number', () => {
    const body = serializeAttendanceSession({ course_id: 'new', held_on: today, meeting_number: '', 'course.name': ' Aljabar ', 'course.lecturer_name': ' Dosen tamu ', 'course.semester': '3', 'course.code': ' ', 'course.is_active': false, 'course.class_id': 99 }, today);
    assert.deepEqual(body, { course: { name: 'Aljabar', lecturer_name: 'Dosen tamu', semester: 3 }, held_on: today, starts_at: null, ends_at: null, topic: null, notes: null });
    assert.equal(Object.hasOwn(serializeAttendanceSession({ ...values, meeting_number: undefined }, today), 'meeting_number'), false);
    assert.throws(() => serializeAttendanceSession({ ...values, course_id: 'new', 'course.name': 'Aljabar' }, today), (error) => Object.hasOwn(error.details, 'course.lecturer_name'));
    assert.throws(() => serializeAttendanceSession({ ...values, meeting_number: '' }, today, session), /1 sampai 100/);
});

test('all four attendance statuses round-trip while missing or invalid dotted statuses are rejected', () => {
    for (const status of ['present', 'excused', 'sick', 'absent']) assert.equal(serializeAttendanceSession({ ...values, 'records.0.status': status }, today, session).records[0].status, status);
    const missing = { ...values }; delete missing['records.1.status'];
    for (const input of [missing, { ...values, 'records.1.status': 'attended' }]) assert.throws(() => serializeAttendanceSession(input, today, session), (error) => Object.hasOwn(error.details, 'records.1.status'));
    assert.throws(() => serializeAttendanceSession({ ...values, 'records.0.note': 'a'.repeat(501) }, today, session), (error) => Object.hasOwn(error.details, 'records.0.note'));
});

test('session validation rejects future/impossible dates, invalid meetings, and unpaired or reversed times', () => {
    for (const [name, value] of [['held_on', '2026-11-04'], ['held_on', '2026-02-30'], ['held_on', '03/11/2026'], ['meeting_number', '0'], ['meeting_number', '101'], ['meeting_number', '1.5'], ['course_id', ''], ['starts_at', '25:00'], ['ends_at', '08:00'], ['ends_at', '07:00'], ['ends_at', ''], ['starts_at', '']]) {
        assert.throws(() => serializeAttendanceSession({ ...values, [name]: value }, today), Error, `${name}=${value}`);
    }
    for (const [name, length] of [['topic', 255], ['notes', 2000], ['lecturer_name', 160]]) assert.throws(() => serializeAttendanceSession({ ...values, [name]: 'a'.repeat(length + 1) }, today, session), (error) => Object.hasOwn(error.details, name));
});

// Exercise page callbacks with minimal DOM controls; no backend, browser package or network is needed.
class Control {
    constructor(value = '') { this.value = value; this.nodes = new Map(); this.events = {}; this.disabled = false; this.isConnected = true; this.innerHTML = ''; this.textContent = ''; this.hidden = false; this.dataset = {}; }
    querySelector(selector) { if (!this.nodes.has(selector)) this.nodes.set(selector, new Control()); return this.nodes.get(selector); }
    querySelectorAll(selector) { return this.collections?.[selector] || (selector === '[data-student]' ? this.rows || [] : []); }
    addEventListener(name, handler) { this.events[name] = handler; }
    reportValidity() { return true; }
    focus() {}
    reset() { for (const control of Object.values(this.controls)) control.value = control.initialValue ?? ''; }
}

function harness({ user = leader, data = session, root = catalog, request } = {}) {
    const view = new Control();
    const requests = [], pages = [], notices = [];
    const ctx = {
        user, view, requests, pages, notices, confirmations: [], refreshes: 0,
        has: (permission) => user.permissions.includes(permission), canClass: () => user.identity_status === 'verified' && user.membership?.status === 'active',
        api: async (path, options) => { requests.push([path, options]); if (request) { const result = await request(path, options, ctx); if (result !== undefined) return result; } return { data: path === '/attendance' ? root : path.startsWith('/attendance/sessions?') ? [] : data }; },
        page: (title, description, html, actions = '') => { pages.push({ title, description, html: html + actions }); },
        form: (form, handler) => { form.submit = () => handler(form); },
        action: (element, handler) => handler(),
        confirm: async (options) => { ctx.confirmations.push(options); return ctx.confirmed ?? true; },
        navigate: (path) => { ctx.destination = path; }, refresh: () => { ctx.refreshes++; }, toast: (notice) => notices.push(notice),
        guardUnsavedChanges: (check) => { ctx.hasUnsavedChanges = check; },
    };
    const form = view.querySelector('[data-session-form]');
    form.controls = Object.fromEntries(Object.entries({ ...values, roster_search: '' }).map(([name, value]) => [name, new Control(value)]));
    form.elements = { namedItem: (name) => form.controls[name] };
    form.rows = data.records?.map(() => new Control()) || [];
    const reopen = view.querySelector('[data-reopen]');
    reopen.controls = { reason: new Control('Koreksi berdasarkan bukti kehadiran') };
    reopen.elements = { namedItem: (name) => reopen.controls[name] };
    for (const [selector, defaults] of [
        ['[data-new-session]', { course_id: String(root.courses.find((item) => item.is_active)?.id || 'new'), meeting_number: '', held_on: root.today, starts_at: '', ends_at: '', topic: '', notes: '', 'course.name': '', 'course.lecturer_name': '', 'course.semester': String(root.class.semester), 'course.code': '' }],
        ['[data-student-editor]', { mode: 'single', name: '', nim: '', is_active: 'true', students: '' }],
    ]) {
        const editor = view.querySelector(selector);
        editor.controls = Object.fromEntries(Object.entries(defaults).map(([name, value]) => [name, Object.assign(new Control(value), { initialValue: value })]));
        editor.elements = { namedItem: (name) => editor.controls[name] };
    }
    return ctx;
}

function mockFormData(t) {
    const original = globalThis.FormData;
    globalThis.FormData = class { constructor(form) { this.form = form; } entries() { return Object.entries(this.form.controls).map(([name, control]) => [name, control.value])[Symbol.iterator](); } };
    t.after(() => { globalThis.FormData = original; });
}

test('direct unauthorized and malformed attendance routes make no API requests', async () => {
    for (const user of [{ ...leader, role: 'student' }, { ...leader, permissions: [] }, { ...leader, membership: null }]) {
        const ctx = harness({ user });
        await attendancePage(ctx, ['attendance']);
        assert.equal(ctx.requests.length, 0);
        assert.match(ctx.pages[0].title, /tidak tersedia/);
    }
    for (const parts of [['attendance', 'sessions', '..'], ['attendance', 'sessions', '1', 'delete'], ['attendance', 'unknown']]) {
        const ctx = harness(); await attendancePage(ctx, parts);
        assert.equal(ctx.requests.length, 0);
    }
});

test('attendance stops rendering and fetching detail when the root request outlives its view', async () => {
    const ctx = harness({ request: async (path, options, context) => { context.view.isConnected = false; return { data: catalog }; } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    assert.deepEqual(ctx.requests.map(([path]) => path), ['/attendance']);
    assert.equal(ctx.pages.length, 0);
});

test('empty manual roster is roster-first even for a verified registered class leader', async () => {
    for (const route of [['attendance'], ['attendance', 'new']]) {
        const ctx = harness({ root: { ...catalog, roster: [] } });
        await attendancePage(ctx, route);
        assert.match(ctx.pages[0].html, /Tambahkan mahasiswa terlebih dahulu/);
        assert.match(ctx.pages[0].html, /href="#\/attendance\/students"/);
        assert.ok(!ctx.pages[0].html.includes('href="#/classes"'));
        assert.ok(!ctx.pages[0].html.includes('data-new-session'));
        assert.equal(ctx.requests.some(([, options]) => options?.method), false);
    }
});

test('manual add posts a name and leading-zero NIM without account or membership calls', async (t) => {
    mockFormData(t);
    const ctx = harness({ request: async (path) => path === '/attendance/students' ? { data: [] } : undefined });
    await attendancePage(ctx, ['attendance', 'students']);
    const form = ctx.view.querySelector('[data-student-editor]');
    form.controls.name.value = ' Nadia tanpa akun ';
    form.controls.nim.value = ' 001900 ';
    form.events.input();
    assert.equal(ctx.hasUnsavedChanges(), true);
    await form.submit();
    assert.deepEqual(ctx.requests.at(-1), ['/attendance/students', { method: 'POST', body: { students: [{ name: 'Nadia tanpa akun', nim: '001900' }] } }]);
    assert.equal(ctx.hasUnsavedChanges(), false);
    assert.equal(ctx.refreshes, 1);
    assert.ok(ctx.requests.every(([path]) => path.startsWith('/attendance')));
    assert.match(ctx.pages[0].html, /tidak membuat akun, keanggotaan kelas, atau verifikasi identitas/);
});

test('bulk form blocks invalid batches and retains all pasted rows after a server duplicate rejection', async (t) => {
    mockFormData(t);
    let fail = true;
    const rejection = Object.assign(new Error('NIM sudah dipakai termasuk pada mahasiswa arsip.'), { status: 422, details: { 'students.1.nim': ['NIM sudah dipakai.'] } });
    const ctx = harness({ request: async (path, options) => {
        if (path !== '/attendance/students') return;
        if (options?.method && fail) throw rejection;
        return { data: [] };
    } });
    await attendancePage(ctx, ['attendance', 'students']);
    const form = ctx.view.querySelector('[data-student-editor]');
    form.controls.mode.value = 'bulk'; form.controls.mode.events.change(); form.events.change();
    assert.equal(form.querySelector('[data-single-student]').disabled, true);
    assert.equal(form.querySelector('[data-bulk-students]').disabled, false);
    form.controls.students.value = 'Valid;001\n;002';
    await assert.rejects(form.submit(), /Baris 2/);
    assert.equal(ctx.requests.filter(([, options]) => options?.method).length, 0);
    form.controls.students.value = 'Valid;001\nMahasiswa kedua\t002';
    await assert.rejects(form.submit(), (error) => error === rejection);
    assert.equal(form.controls.students.value, 'Valid;001\nMahasiswa kedua\t002');
    assert.equal(form.querySelector('[data-student-fields]').disabled, false);
    assert.equal(ctx.hasUnsavedChanges(), true);
    assert.equal(ctx.refreshes, 0);
    fail = false;
    await form.submit();
    assert.deepEqual(ctx.requests.at(-1)[1].body, { students: [{ name: 'Valid', nim: '001' }, { name: 'Mahasiswa kedua', nim: '002' }] });
});

test('student edit and archive use listed IDs, confirm archive, escape text and preserve pending input on cancel', async (t) => {
    mockFormData(t);
    const unsafe = '<img src=x onerror=alert(1)>';
    const students = [{ id: 119, name: unsafe, nim: '0019', is_active: true }, { id: 122, name: 'Arsip', nim: '0022', is_active: false }];
    const ctx = harness({ request: async (path) => path === '/attendance/students' ? { data: students } : undefined });
    const button = new Control(); button.dataset.editStudent = '119';
    ctx.view.collections = { '[data-edit-student]': [button] };
    await attendancePage(ctx, ['attendance', 'students']);
    assert.ok(!ctx.pages[0].html.includes(unsafe));
    assert.match(ctx.pages[0].html, /1 aktif \/ 1 arsip/);
    const form = ctx.view.querySelector('[data-student-editor]');
    form.controls.name.value = 'Belum disimpan'; form.events.input();
    ctx.confirmed = false;
    await button.events.click();
    assert.equal(form.controls.name.value, 'Belum disimpan');
    ctx.confirmed = true;
    await button.events.click();
    assert.equal(form.controls.name.value, unsafe);
    assert.equal(form.controls.mode.disabled, true);
    form.controls.name.value = 'Nama dikoreksi'; form.controls.is_active.value = 'false'; form.events.change();
    ctx.confirmed = false;
    await form.submit();
    assert.equal(ctx.requests.filter(([, options]) => options?.method).length, 0);
    assert.equal(ctx.hasUnsavedChanges(), true);
    ctx.confirmed = true;
    await form.submit();
    assert.deepEqual(ctx.requests.at(-1), ['/attendance/students/119', { method: 'PATCH', body: { name: 'Nama dikoreksi', nim: '0019', is_active: false } }]);
    assert.equal(ctx.requests.some(([, options]) => options?.method === 'DELETE'), false);
});

test('new meeting defaults to today, selected lecturer and course semester with server max-plus-one numbering', async (t) => {
    mockFormData(t);
    const ctx = harness({ root: { ...catalog, courses: [course, { ...course, id: 8, name: 'Arsip rahasia', is_active: false }] } });
    await attendancePage(ctx, ['attendance', 'new']);
    const html = ctx.pages[0].html;
    const form = ctx.view.querySelector('[data-new-session]');
    assert.ok(!html.includes('Arsip rahasia'));
    assert.match(html, /name="held_on"[^>]*value="2026-11-03"/);
    assert.match(html, /name="course.semester"[^>]*value="3"/);
    assert.match(html, /<details data-meeting-advanced>/);
    assert.equal(form.querySelector('[data-course-info]').textContent, 'Dosen: Dosen Pengampu / Semester mata kuliah: 5');
    assert.match(form.querySelector('[data-next-meeting]').textContent, /Pertemuan 9/);
    assert.equal(form.querySelector('[data-inline-course]').disabled, true);
    const folded = { open: false };
    form.events.invalid({ target: { closest: () => folded } });
    assert.equal(folded.open, true, 'native validation reveals a folded optional field');
    await form.submit();
    assert.deepEqual(ctx.requests.at(-1), ['/attendance/sessions', { method: 'POST', body: { course_id: 4, held_on: today, starts_at: null, ends_at: null, topic: null, notes: null } }]);
    assert.equal(ctx.destination, '/attendance/sessions/10');
});

test('course catalog creation defaults semester and editing preserves blank code while confirming archive', async (t) => {
    mockFormData(t);
    const ctx = harness();
    const create = new Control(); create.dataset.course = '';
    const edit = new Control(); edit.dataset.course = '4';
    for (const form of [create, edit]) form.controls = Object.fromEntries(Object.entries({ name: 'Algoritma', lecturer_name: 'Dosen Pengampu', semester: '3', code: '', is_active: 'false' }).map(([name, value]) => [name, new Control(value)]));
    ctx.view.collections = { '[data-course]': [create, edit] };
    await attendancePage(ctx, ['attendance', 'courses']);
    assert.match(ctx.pages[0].html, /name="semester"[^>]*value="3"/);
    await create.submit();
    assert.deepEqual(ctx.requests.at(-1), ['/attendance/courses', { method: 'POST', body: { name: 'Algoritma', lecturer_name: 'Dosen Pengampu', semester: 3 } }]);
    ctx.confirmed = false;
    await edit.submit();
    assert.equal(ctx.requests.filter(([, options]) => options?.method).length, 1);
    ctx.confirmed = true;
    await edit.submit();
    assert.deepEqual(ctx.requests.at(-1), ['/attendance/courses/4', { method: 'PATCH', body: { name: 'Algoritma', lecturer_name: 'Dosen Pengampu', semester: 3, is_active: false } }]);
});

test('quick course works with an empty catalog, retains errors and clears guard only after successful creation', async (t) => {
    mockFormData(t);
    let fail = true;
    const ctx = harness({ root: { ...catalog, courses: [] }, request: async (path, options) => {
        if (options?.method && fail) throw Object.assign(new Error('Nama mata kuliah sudah dipakai.'), { status: 422 });
    } });
    await attendancePage(ctx, ['attendance', 'new']);
    const form = ctx.view.querySelector('[data-new-session]');
    assert.equal(form.querySelector('[data-inline-course]').disabled, false);
    assert.match(form.querySelector('[data-next-meeting]').textContent, /Pertemuan 1/);
    form.controls['course.name'].value = 'Aljabar'; form.controls['course.lecturer_name'].value = 'Dosen tamu'; form.events.input();
    await assert.rejects(form.submit(), /sudah dipakai/);
    assert.equal(form.controls['course.name'].value, 'Aljabar');
    assert.equal(form.querySelector('fieldset').disabled, false);
    assert.equal(ctx.hasUnsavedChanges(), true);
    fail = false;
    await form.submit();
    assert.deepEqual(ctx.requests.at(-1)[1].body.course, { name: 'Aljabar', lecturer_name: 'Dosen tamu', semester: 3 });
    assert.equal(ctx.requests.at(-1)[1].body.course_id, undefined);
    assert.equal(ctx.hasUnsavedChanges(), false);
    assert.equal(ctx.requests.some(([path]) => path === '/attendance/courses'), false);
});

test('course switching reuses stored metadata without submitting stale inline fields and blocks auto meeting 101', async (t) => {
    mockFormData(t);
    const ctx = harness({ root: { ...catalog, courses: [{ ...course, next_meeting_number: 101 }] } });
    await attendancePage(ctx, ['attendance', 'new']);
    const form = ctx.view.querySelector('[data-new-session]');
    assert.match(form.querySelector('[data-next-meeting]').textContent, /batas 100/);
    await assert.rejects(form.submit(), /melebihi 100/);
    form.controls.course_id.value = 'new'; form.controls.course_id.events.change();
    assert.equal(form.querySelector('[data-inline-course]').disabled, false);
    assert.match(form.querySelector('[data-next-meeting]').textContent, /Pertemuan 1/);
    form.controls['course.name'].value = 'Unsubmitted course';
    form.controls.course_id.value = '4'; form.controls.course_id.events.change();
    form.controls.meeting_number.value = '3';
    await form.submit();
    assert.equal(ctx.requests.at(-1)[1].body.course, undefined);
    assert.equal(ctx.requests.at(-1)[1].body.meeting_number, 3);
});

test('draft renders escaped snapshot content, exact dotted fields, and unmarked first options', async () => {
    const unsafe = '<img src=x onerror=alert(1)>';
    const ctx = harness({ data: { ...session, course: { ...course, lecturer_name: unsafe }, records: [{ ...records[0], name: unsafe, note: unsafe }, records[1]], history: [{ action: 'attendance_reopened', actor: { name: unsafe }, created_at: session.created_at, reason: unsafe }] } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const html = ctx.pages[0].html;
    assert.ok(!html.includes(unsafe));
    assert.match(html, /&lt;img/);
    assert.match(html, /Absensi dibuka kembali/);
    assert.match(html, /name="records\.0\.status"/);
    assert.match(html, /name="records\.1\.note"/);
    assert.match(html, /<option value="" selected>Belum diisi/);
    assert.ok(!html.includes('name="class_id"'));
    assert.ok(!html.includes('name="course_id"'));
    assert.deepEqual(ctx.requests.map(([path]) => path), ['/attendance', '/attendance/sessions/10']);
    assert.equal(ctx.view.querySelector('[data-session-form]').querySelector('[data-finalize]').disabled, true);
});

test('detail uses saved roster and semester snapshots, including students no longer active in the catalog', async () => {
    const ctx = harness({ root: { ...catalog, roster: [], courses: [{ ...course, semester: 14 }] }, data: { ...session, semester: 5 } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    assert.match(ctx.pages[0].html, /Semester mata kuliah: 5/);
    assert.match(ctx.pages[0].html, /Nadia Putri/);
    assert.match(ctx.pages[0].html, /NIM 0019/);
    assert.match(ctx.pages[0].html, /name="records\.1\.status"/);
    assert.equal(ctx.requests.some(([path]) => path === '/attendance/students'), false);
});

test('roster search hides rather than removes controls, and bulk presence requires confirmation', async () => {
    const ctx = harness(); await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const form = ctx.view.querySelector('[data-session-form]');
    form.controls.roster_search.value = 'Nadia'; form.controls.roster_search.events.input();
    assert.deepEqual(form.rows.map((row) => row.hidden), [false, true]);
    assert.equal(form.controls['records.1.status'].value, 'sick');
    ctx.confirmed = false;
    await form.querySelector('[data-all-present]').events.click();
    assert.equal(form.controls['records.0.status'].value, '');
    ctx.confirmed = true;
    await form.querySelector('[data-all-present]').events.click();
    assert.equal(ctx.confirmations.length, 2);
    assert.equal(form.controls['records.0.status'].value, 'present');
    assert.equal(form.controls['records.1.status'].value, 'present');
    assert.equal(form.controls['records.1.note'].value, ' Istirahat ');
    assert.equal(form.querySelector('[data-finalize]').disabled, false);
});

test('save then finalize uses the returned version and submits all hidden snapshot records', async (t) => {
    mockFormData(t);
    const ctx = harness({ request: async (path, options) => {
        if (options?.method === 'PATCH') return { data: { ...session, ...options.body, version: 8 } };
        if (path.endsWith('/finalize')) return { data: { ...session, version: 9, status: 'finalized' } };
    } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const form = ctx.view.querySelector('[data-session-form]');
    await form.querySelector('[data-all-present]').events.click();
    form.controls.roster_search.value = 'Nadia'; form.controls.roster_search.events.input();
    await form.querySelector('[data-finalize]').events.click();
    const writes = ctx.requests.filter(([, options]) => options?.method);
    assert.equal(writes.length, 2);
    assert.equal(writes[0][0], '/attendance/sessions/10');
    assert.equal(writes[0][1].body.version, 7);
    assert.deepEqual(writes[0][1].body.records.map((record) => [record.student_id, record.status]), [[119, 'present'], [122, 'present']]);
    assert.deepEqual(writes[1], ['/attendance/sessions/10/finalize', { method: 'POST', body: { version: 8 } }]);
    assert.match(ctx.pages.at(-1).html, /hanya baca/);
    assert.equal(ctx.hasUnsavedChanges, null);
});

test('unsaved attendance guard ignores search, survives failed saves, and clears after saving', async (t) => {
    mockFormData(t);
    let fail = true;
    const ctx = harness({ request: async (path, options) => {
        if (options?.method === 'PATCH') {
            if (fail) throw new Error('Gagal menyimpan');
            return { data: { ...session, ...options.body, version: 8 } };
        }
    } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const form = ctx.view.querySelector('[data-session-form]');
    assert.equal(ctx.hasUnsavedChanges(), false);
    form.events.input({ target: form.controls.roster_search });
    assert.equal(ctx.hasUnsavedChanges(), false);
    form.controls.topic.value = 'Topik baru';
    form.events.input({ target: form.controls.topic });
    assert.equal(ctx.hasUnsavedChanges(), true);
    await assert.rejects(form.submit(), /Gagal menyimpan/);
    assert.equal(ctx.hasUnsavedChanges(), true);
    fail = false;
    await form.submit();
    assert.equal(ctx.hasUnsavedChanges(), false);
});

test('version conflict preserves unsaved fields and requires explicit consent before reload', async (t) => {
    mockFormData(t);
    const conflict = Object.assign(new Error('Versi telah berubah'), { status: 409 });
    const ctx = harness({ request: async (path, options) => { if (options?.method === 'PATCH') throw conflict; } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const form = ctx.view.querySelector('[data-session-form]');
    form.controls.topic.value = 'Catatan yang belum disimpan';
    await assert.rejects(form.submit(), (error) => error === conflict);
    assert.equal(form.controls.topic.value, 'Catatan yang belum disimpan');
    assert.equal(ctx.pages.length, 1);
    assert.equal(ctx.refreshes, 0);
    const area = ctx.view.querySelector('[data-conflict]');
    assert.match(area.innerHTML, /Isian Anda tetap ada/);
    const button = area.querySelector('button');
    ctx.confirmed = false; await button.events.click({ currentTarget: button });
    assert.equal(ctx.refreshes, 0);
    ctx.confirmed = true; await button.events.click({ currentTarget: button });
    assert.equal(ctx.refreshes, 1);
});

test('finalized detail is readonly and reopening sends a trimmed reason plus version', async (t) => {
    mockFormData(t);
    const ctx = harness({ data: { ...session, status: 'finalized' }, request: async (path, options) => { if (path.endsWith('/reopen')) return { data: { ...session, version: 8 } }; } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    assert.ok(!ctx.pages[0].html.includes('name="records.0.status"'));
    assert.ok(!ctx.pages[0].html.includes('data-finalize'));
    const form = ctx.view.querySelector('[data-reopen]');
    form.controls.reason.value = ' singkat ';
    await assert.rejects(form.submit(), /minimal 10/);
    form.controls.reason.value = '  Bukti kehadiran perlu dikoreksi  ';
    await form.submit();
    assert.deepEqual(ctx.requests.find(([path]) => path.endsWith('/reopen')), ['/attendance/sessions/10/reopen', { method: 'POST', body: { version: 7, reason: 'Bukti kehadiran perlu dikoreksi' } }]);
});

test('summary requests all finalized data by default without inheriting draft/page filters', async () => {
    const ctx = harness({ request: async (path) => path.startsWith('/attendance/summary') ? { data: { class: room, filters: {}, sessions_count: 0, totals: { present: 0, excused: 0, sick: 0, absent: 0, total: 0, attendance_rate: null }, students: [] } } : undefined });
    await attendancePage(ctx, ['attendance', 'summary'], new URLSearchParams('status=draft&page=7'));
    assert.deepEqual(ctx.requests.map(([path]) => path), ['/attendance', '/attendance/summary']);
    assert.match(ctx.pages[0].html, /Belum ada rekap final/);
    assert.match(ctx.pages[0].html, /Belum ada data/);
    assert.ok(!ctx.requests.some(([path]) => path.includes('/dashboard') || path.includes('/admin/users')));
});

test('finalization cannot submit an unmarked snapshot even when invoked programmatically', async (t) => {
    mockFormData(t);
    const ctx = harness(); await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const form = ctx.view.querySelector('[data-session-form]');
    await assert.rejects(form.querySelector('[data-finalize]').events.click(), /Isi status seluruh mahasiswa/);
    assert.equal(ctx.requests.filter(([, options]) => options?.method).length, 0);
});

test('a failed finalization retains the successfully saved version for retry without resaving', async (t) => {
    mockFormData(t);
    let attempts = 0;
    const ctx = harness({ request: async (path, options) => {
        if (options?.method === 'PATCH') return { data: { ...session, ...options.body, version: 8 } };
        if (path.endsWith('/finalize')) {
            if (++attempts === 1) throw new Error('Koneksi terputus.');
            return { data: { ...session, version: 9, status: 'finalized' } };
        }
    } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const form = ctx.view.querySelector('[data-session-form]');
    await form.querySelector('[data-all-present]').events.click();
    await assert.rejects(form.querySelector('[data-finalize]').events.click(), /Draft sudah tersimpan/);
    assert.equal(form.querySelector('[data-save-state]').textContent, 'Draft tersimpan');
    assert.equal(form.querySelector('[data-editor]').disabled, false);
    assert.equal(ctx.pages.length, 1);
    await form.querySelector('[data-finalize]').events.click();
    assert.equal(ctx.requests.filter(([, options]) => options?.method === 'PATCH').length, 1);
    assert.deepEqual(ctx.requests.filter(([path]) => path.endsWith('/finalize')).map(([, options]) => options.body.version), [8, 8]);
});

test('record validation errors reveal hidden students without discarding their entered notes', async (t) => {
    mockFormData(t);
    const error = Object.assign(new Error('Periksa catatan mahasiswa.'), { status: 422, details: { 'records.1.note': ['Catatan perlu diperbaiki.'] } });
    const ctx = harness({ request: async (path, options) => { if (options?.method === 'PATCH') throw error; } });
    await attendancePage(ctx, ['attendance', 'sessions', '10']);
    const form = ctx.view.querySelector('[data-session-form]');
    form.controls.roster_search.value = 'Nadia'; form.controls.roster_search.events.input();
    assert.equal(form.rows[1].hidden, true);
    await assert.rejects(form.submit(), (caught) => caught === error);
    assert.equal(form.rows[1].hidden, false);
    assert.equal(form.controls['records.1.note'].value, ' Istirahat ');
});

test('CSV export uses the applied summary filters and a revocable local object URL', async (t) => {
    const original = { document: globalThis.document, timeout: globalThis.setTimeout, create: URL.createObjectURL, revoke: URL.revokeObjectURL };
    t.after(() => { if (original.document === undefined) delete globalThis.document; else globalThis.document = original.document; globalThis.setTimeout = original.timeout; URL.createObjectURL = original.create; URL.revokeObjectURL = original.revoke; });
    const blob = new Blob(['Nama,NIM,Semester\r\nNadia,0019,5\r\n'], { type: 'text/csv' });
    const link = { clicked: false, removed: false, click() { this.clicked = true; }, remove() { this.removed = true; } };
    let revoked, appended;
    globalThis.document = { createElement: () => link, body: { append: (element) => { appended = element; } } };
    globalThis.setTimeout = (callback) => callback();
    URL.createObjectURL = (value) => { assert.equal(value, blob); return 'blob:attendance-test'; };
    URL.revokeObjectURL = (value) => { revoked = value; };
    const ctx = harness({ request: async (path) => {
        if (path.startsWith('/attendance/summary')) return { data: { class: room, filters: {}, sessions_count: 0, totals: { present: 0, excused: 0, sick: 0, absent: 0, total: 0, attendance_rate: null }, students: [] } };
        if (path.startsWith('/attendance/export')) return blob;
    } });
    ctx.download = () => { throw new Error('Restricted download helper must not be used.'); };
    await attendancePage(ctx, ['attendance', 'summary'], new URLSearchParams('course_id=4&date_from=2026-10-01&status=draft&page=2'));
    const button = ctx.view.querySelector('[data-export]');
    await button.events.click({ currentTarget: button });
    assert.deepEqual(ctx.requests.at(-1), ['/attendance/export?course_id=4&date_from=2026-10-01', { binary: true }]);
    assert.equal(link.download, 'rekap-absensi-2026-11-03.csv');
    assert.equal(link.href, 'blob:attendance-test');
    assert.equal(link.clicked, true);
    assert.equal(appended, link);
    assert.equal(link.removed, true);
    assert.equal(revoked, 'blob:attendance-test');
});
