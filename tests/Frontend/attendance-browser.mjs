import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';

// API fixtures are isolated to this browser test; no real attendance data is written.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(join(process.env.PLAYWRIGHT_MODULE, 'index.mjs')).href : 'playwright');
const base = process.env.FRONTEND_URL || 'http://127.0.0.1:8016';
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', acceptDownloads: true, serviceWorkers: 'block' });
const user = { id: 1, name: 'Nadia Ketua', role: 'class_leader', role_name: 'Ketua Kelas', permissions: ['attendance.manage'], identity_status: 'verified', membership: { status: 'active', class_id: 1 } };
const room = { id: 1, name: 'Teknik Informatika A', code: 'IF-A', semester: 3, academic_term: { id: 1, code: '2026-1', name: 'Ganjil 2026/2027', starts_on: '2026-09-01', ends_on: '2027-01-31' } };
const students = [];
const courses = [];
const sessions = [];
const errors = [];
let conflict = false;
let saves = 0;
let checks = 0;
const at = '2026-11-03T08:00:00Z';
const today = '2026-11-03';
const nextMeeting = (id) => Math.max(0, ...sessions.filter((session) => session.course_id === id).map((session) => session.meeting_number)) + 1;
const courseCatalog = () => courses.map((course) => ({ ...course, sessions_count: sessions.filter((session) => session.course_id === course.id).length, next_meeting_number: nextMeeting(course.id) }));
const roster = () => students.filter((student) => student.is_active).map((student) => ({ student_id: student.id, user_id: null, name: student.name, nim: student.nim }));
const counts = (records) => records.reduce((out, record) => { out[record.status || 'unmarked']++; return out; }, { present: 0, excused: 0, sick: 0, absent: 0, unmarked: 0, total: records.length });
function log(session, action, reason = null) {
    session.history.push({ action: `attendance_${action}`, actor: { id: 1, name: user.name }, created_at: at, reason });
    session.counts = counts(session.records);
}
await context.addInitScript(() => sessionStorage.setItem('rd.token', 'attendance-browser-fixture'));
await context.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace('/api/v1', '');
    const method = request.method();
    const json = (data, status = 200, meta) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ message: 'Respons pengujian', data, ...(meta ? { meta } : {}) }) });
    const invalid = (message, details) => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message, error: { code: 'VALIDATION_ERROR', details } }) });
    if (path === '/auth/me') return json(user);
    if (path === '/security/voice-warnings') return json({ enabled: false, sounds: [] });
    if (path === '/attendance') return json({ class: room, courses: courseCatalog(), roster: roster(), timezone: 'Asia/Jakarta', today });
    if (path === '/attendance/students' && method === 'GET') return json(students);
    if (path === '/attendance/students' && method === 'POST') {
        const body = request.postDataJSON();
        assert.deepEqual(Object.keys(body), ['students']);
        const batch = body.students;
        if (!Array.isArray(batch) || batch.length < 1 || batch.length > 100) return invalid('Batch harus 1 sampai 100 mahasiswa.', { students: ['Batch tidak valid.'] });
        const nims = new Set(students.map((student) => student.nim).filter(Boolean));
        for (const [index, student] of batch.entries()) {
            assert.deepEqual(Object.keys(student).sort(), ['name', 'nim']);
            assert.equal(typeof student.nim, 'string');
            if (!student.name?.trim() || student.name.length > 255 || student.nim.length > 32) return invalid('Periksa baris mahasiswa.', { [`students.${index}.name`]: ['Nama atau NIM tidak valid.'] });
            if (student.nim && nims.has(student.nim)) return invalid('NIM sudah dipakai pada daftar kelas.', { [`students.${index}.nim`]: ['NIM harus unik termasuk pada mahasiswa arsip.'] });
            if (student.nim) nims.add(student.nim);
        }
        const created = batch.map((student, index) => ({ ...student, nim: student.nim || null, user_id: null, id: students.length + 101 + index, is_active: true }));
        students.push(...created);
        return json(created, 201);
    }
    if (/^\/attendance\/students\/\d+$/.test(path) && method === 'PATCH') {
        const student = students.find((item) => item.id === Number(path.split('/').at(-1)));
        const body = request.postDataJSON();
        assert.deepEqual(Object.keys(body).sort(), ['is_active', 'name', 'nim']);
        if (body.nim && students.some((item) => item.id !== student.id && item.nim === body.nim)) return invalid('NIM sudah dipakai pada daftar kelas.', { nim: ['NIM harus unik.'] });
        Object.assign(student, body);
        return json(student);
    }
    if (path === '/attendance/courses' && method === 'POST') {
        const body = request.postDataJSON();
        const course = { semester: room.semester, code: `MK-${courses.length + 1}`, ...body, id: courses.length + 1, is_active: true };
        courses.push(course);
        return json(course, 201);
    }
    if (path === '/attendance/courses/1' && method === 'PATCH') {
        Object.assign(courses[0], request.postDataJSON());
        return json(courses[0]);
    }
    if (path === '/attendance/sessions' && method === 'GET') return json(sessions, 200, { pagination: { current_page: 1, last_page: 1, per_page: 20, total: sessions.length } });
    if (path === '/attendance/sessions' && method === 'POST') {
        const body = request.postDataJSON();
        assert.notEqual(Boolean(body.course_id), Boolean(body.course));
        assert.equal(body.held_on, today);
        assert.equal(Object.hasOwn(body, 'records'), false);
        let course = courses.find((item) => item.id === body.course_id);
        if (body.course) {
            course = { semester: room.semester, code: `MK-${courses.length + 1}`, ...body.course, id: courses.length + 1, is_active: true };
            courses.push(course);
        }
        assert.ok(course.is_active);
        const meeting_number = body.meeting_number ?? nextMeeting(course.id);
        assert.ok(meeting_number >= 1 && meeting_number <= 100);
        const session = { ...body, id: sessions.length + 1, course_id: course.id, course: { ...course }, semester: course.semester, meeting_number, status: 'draft', version: 1, day_name: 'Selasa', created_at: at, updated_at: at, created_by: user, updated_by: user, finalized_at: null, records: roster().map((student) => ({ ...student, status: null, note: null })), history: [] };
        log(session, 'created'); sessions.push(session);
        return json(session, 201);
    }
    if (/^\/attendance\/sessions\/\d+$/.test(path) && method === 'GET') return json(sessions.find((session) => session.id === Number(path.split('/').at(-1))));
    if (path === '/attendance/sessions/1' && method === 'PATCH') {
        if (conflict) return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ message: 'Versi telah berubah di tab lain.', error: { code: 'ATTENDANCE_CONFLICT' } }) });
        const body = request.postDataJSON();
        assert.equal(body.version, sessions[0].version);
        assert.deepEqual(body.records.map((record) => record.student_id), [101, 102], 'manual snapshot IDs including hidden rows remain in payload');
        assert.ok(body.records.every((record) => !Object.hasOwn(record, 'user_id')));
        const records = sessions[0].records.map((record, index) => ({ ...record, ...body.records[index] }));
        Object.assign(sessions[0], body, { records, version: body.version + 1 });
        sessions[0].course.lecturer_name = body.lecturer_name;
        log(sessions[0], 'updated'); saves++;
        await new Promise((resolve) => setTimeout(resolve, 120));
        return json(sessions[0]);
    }
    if (path === '/attendance/sessions/1/finalize') {
        assert.equal(request.postDataJSON().version, sessions[0].version);
        assert.equal(counts(sessions[0].records).unmarked, 0);
        Object.assign(sessions[0], { status: 'finalized', finalized_at: at, version: sessions[0].version + 1 });
        log(sessions[0], 'finalized'); return json(sessions[0]);
    }
    if (path === '/attendance/sessions/1/reopen') {
        const body = request.postDataJSON();
        assert.equal(body.version, sessions[0].version);
        assert.ok(body.reason.length >= 10);
        Object.assign(sessions[0], { status: 'draft', finalized_at: null, version: sessions[0].version + 1 });
        log(sessions[0], 'reopened', body.reason); return json(sessions[0]);
    }
    if (path === '/attendance/summary' || path === '/attendance/export') {
        assert.ok([...url.searchParams.keys()].every((key) => ['course_id', 'date_from', 'date_to'].includes(key)));
        const finalized = sessions.filter((session) => session.status === 'finalized' && (!url.searchParams.has('course_id') || String(session.course_id) === url.searchParams.get('course_id')) && (!url.searchParams.has('date_from') || session.held_on >= url.searchParams.get('date_from')) && (!url.searchParams.has('date_to') || session.held_on <= url.searchParams.get('date_to')));
        const records = finalized.flatMap((session) => session.records);
        if (path === '/attendance/export') {
            assert.equal(url.searchParams.get('course_id'), '1');
            assert.equal(url.searchParams.get('date_from'), today);
            const cell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
            const headers = ['Kelas', 'Periode akademik', 'Mata kuliah (katalog)', 'Dosen (katalog)', 'Dari tanggal', 'Sampai tanggal', 'NIM', 'Nama', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total pertemuan', 'Persentase hadir'];
            const course = courses.find((item) => String(item.id) === url.searchParams.get('course_id'));
            const rows = records.map((record) => [room.name, room.academic_term.name, course.name, course.lecturer_name,
                url.searchParams.get('date_from'), url.searchParams.get('date_to'), record.nim ? `'${record.nim}` : '', record.name,
                Number(record.status === 'present'), Number(record.status === 'excused'), Number(record.status === 'sick'), Number(record.status === 'absent'), 1, record.status === 'present' ? 100 : 0]);
            const body = '\uFEFF' + [headers, ...rows].map((row) => row.map(cell).join(',')).join('\r\n') + '\r\n';
            return route.fulfill({ status: 200, contentType: 'text/csv', body });
        }
        return json({ class: room, sessions_count: finalized.length, totals: { ...counts(records), attendance_rate: records.length ? 100 * records.filter((record) => record.status === 'present').length / records.length : null }, students: records.map((record) => ({ student_id: record.student_id, name: record.name, nim: record.nim, present: Number(record.status === 'present'), sick: Number(record.status === 'sick'), excused: 0, absent: 0, total: 1, attendance_rate: record.status === 'present' ? 100 : 0 })) });
    }
    errors.push(`Unmatched fixture: ${method} ${path}`);
    return json(null, 500);
});
const page = await context.newPage();
page.on('pageerror', (error) => errors.push(error.message));
const go = async (path) => { await page.goto(`${base}/#${path}`); await page.locator('.skeleton-group').waitFor({ state: 'hidden' }); await page.locator('h1').waitFor(); };
const confirm = (label) => page.getByRole('dialog').getByRole('button', { name: label, exact: true }).click();
async function layout(label) {
    const result = await page.evaluate(() => {
        const ids = [...document.querySelectorAll('[id]')].map((element) => element.id);
        return { overflow: document.documentElement.scrollWidth > innerWidth + 1, duplicateIds: ids.filter((id, index) => ids.indexOf(id) !== index), unlabeled: [...document.querySelectorAll('input,select,textarea')].filter((element) => !element.labels?.length && !element.getAttribute('aria-label')).map((element) => element.name) };
    });
    assert.deepEqual(result, { overflow: false, duplicateIds: [], unlabeled: [] }, label); checks++;
}
try {
    await go('/attendance');
    await page.getByRole('heading', { name: 'Absensi kelas', exact: true }).waitFor();
    await go('/attendance/new');
    await page.getByRole('heading', { name: 'Tambahkan mahasiswa terlebih dahulu', exact: true }).waitFor();
    assert.equal(await page.locator('[data-new-session]').count(), 0, 'registered leader does not populate a manual roster'); checks++;
    await go('/attendance/students');
    const studentForm = page.locator('[data-student-editor]');
    await studentForm.getByLabel('Nama mahasiswa', { exact: true }).fill('Ardi Mahasiswa');
    await studentForm.getByLabel('NIM (opsional)', { exact: true }).fill('001234');
    await studentForm.getByRole('button', { name: 'Tambah mahasiswa', exact: true }).click();
    await page.locator('[data-edit-student="101"]').waitFor();
    assert.equal(students[0].nim, '001234');
    assert.equal(students[0].user_id, null); checks++;
    await studentForm.getByLabel('Cara menambah').selectOption('bulk');
    await studentForm.getByLabel('Daftar nama dan NIM').fill('Nadia Ketua;001235\n;001236');
    await studentForm.getByRole('button', { name: 'Tambah semua mahasiswa', exact: true }).click();
    await studentForm.locator('.form-error').waitFor();
    assert.equal(students.length, 1, 'invalid paste writes no partial batch'); checks++;
    await studentForm.getByLabel('Daftar nama dan NIM').fill('Nadia Ketua;001235\nDuplikat;001234');
    await studentForm.getByRole('button', { name: 'Tambah semua mahasiswa', exact: true }).click();
    await studentForm.getByText('NIM sudah dipakai pada daftar kelas.', { exact: true }).waitFor();
    assert.equal(students.length, 1, 'server duplicate rejects entire batch');
    assert.equal(await studentForm.getByLabel('Daftar nama dan NIM').inputValue(), 'Nadia Ketua;001235\nDuplikat;001234'); checks++;
    await studentForm.getByLabel('Daftar nama dan NIM').fill('Nadia Ketua\t001235\nArsip <img src=x onerror=alert(1)>;001236');
    await studentForm.getByRole('button', { name: 'Tambah semua mahasiswa', exact: true }).click();
    await page.locator('[data-edit-student="103"]').waitFor();
    assert.equal(await page.locator('img[onerror]').count(), 0); checks++;
    for (const width of [320, 768, 1440]) { await page.setViewportSize({ width, height: 1000 }); await layout(`manual roster ${width}`); }
    await page.locator('[data-edit-student="103"]').click();
    await studentForm.getByLabel('Nama mahasiswa', { exact: true }).fill('Mahasiswa arsip');
    await studentForm.getByLabel('Status mahasiswa').selectOption('false');
    await studentForm.getByRole('button', { name: 'Simpan mahasiswa', exact: true }).click();
    await confirm('Arsipkan');
    await page.getByText('2 aktif / 1 arsip', { exact: true }).waitFor();
    assert.equal(students[2].is_active, false); checks++;
    await go('/attendance/new');
    assert.equal(await page.getByLabel('Mata kuliah', { exact: true }).inputValue(), 'new');
    assert.equal(await page.getByLabel('Semester mata kuliah', { exact: true }).inputValue(), '3');
    assert.equal(await page.getByLabel('Tanggal pertemuan', { exact: true }).inputValue(), today);
    assert.equal(await page.getByLabel('Ubah nomor pertemuan (opsional)', { exact: true }).inputValue(), '');
    assert.match(await page.locator('[data-next-meeting]').innerText(), /Pertemuan 1/);
    assert.equal(await page.getByText('Mahasiswa arsip', { exact: true }).count(), 0); checks++;
    await page.getByLabel('Nama mata kuliah baru', { exact: true }).fill('Algoritma & Pemrograman');
    await page.getByLabel('Nama dosen pengampu', { exact: true }).fill('Dosen <img src=x onerror=alert(1)>');
    for (const width of [320, 768, 1440]) { await page.setViewportSize({ width, height: 1000 }); await layout(`quick course ${width}`); }
    await page.locator('[data-meeting-advanced] > summary').click();
    await page.getByLabel('Jam mulai (WIB)').fill('08:00');
    await page.getByLabel('Jam selesai (WIB)').fill('10:00');
    await page.getByRole('button', { name: 'Buat draft & isi absensi' }).click();
    await page.getByRole('heading', { name: 'Pertemuan 1', exact: true }).waitFor();
    assert.equal(courses.length, 1, 'course created inline with the session');
    assert.equal(courses[0].code, 'MK-1');
    assert.equal(sessions[0].semester, 3);
    assert.deepEqual(sessions[0].records.map((record) => [record.student_id, record.user_id, record.nim]), [[101, null, '001234'], [102, null, '001235']]);
    assert.equal(await page.locator('img[onerror]').count(), 0); checks++;
    assert.equal(await page.getByRole('button', { name: 'Simpan & finalisasi', exact: true }).isDisabled(), true); checks++;
    for (const width of [320, 390, 768, 1440]) { await page.setViewportSize({ width, height: 1000 }); await layout(`draft ${width}`); }
    await page.getByLabel('Cari nama atau NIM').fill('Ardi');
    await page.getByRole('button', { name: 'Tandai semua hadir', exact: true }).click();
    await confirm('Tandai semua hadir');
    assert.equal(await page.locator('[name="records.1.status"]').inputValue(), 'present', 'bulk applies to hidden student'); checks++;
    await page.getByLabel('Cari nama atau NIM').fill('Nadia');
    await page.getByLabel('Status Nadia Ketua', { exact: true }).selectOption('sick');
    await page.getByLabel('Catatan Nadia Ketua', { exact: true }).fill('Izin sakit');
    await page.evaluate(() => history.back());
    await page.getByRole('dialog').waitFor();
    await confirm('Batal');
    assert.equal(new URL(page.url()).hash, '#/attendance/sessions/1');
    assert.equal(await page.getByLabel('Status Nadia Ketua', { exact: true }).inputValue(), 'sick'); checks++;
    await page.getByRole('link', { name: 'Rekap & CSV', exact: true }).click();
    await confirm('Batal');
    assert.equal(new URL(page.url()).hash, '#/attendance/sessions/1');
    assert.equal(await page.getByLabel('Catatan Nadia Ketua', { exact: true }).inputValue(), 'Izin sakit'); checks++;
    const unload = page.waitForEvent('dialog');
    const reloading = page.reload({ timeout: 10000 }).catch((error) => {
        assert.match(error.message, /net::ERR_ABORTED|Timeout/);
    });
    const warning = await unload;
    assert.equal(warning.type(), 'beforeunload');
    await warning.dismiss();
    await reloading;
    assert.equal(await page.getByLabel('Catatan Nadia Ketua', { exact: true }).inputValue(), 'Izin sakit'); checks++;
    await page.getByRole('button', { name: 'Simpan draft', exact: true }).dblclick();
    await page.getByText('Draft tersimpan', { exact: true }).waitFor();
    assert.equal(saves, 1, 'duplicate save prevented');
    assert.equal(sessions[0].records[1].status, 'sick'); checks++;
    conflict = true;
    await page.getByLabel('Catatan Ardi Mahasiswa', { exact: true }).fill('Isian lokal tetap ada');
    await page.getByRole('button', { name: 'Simpan draft', exact: true }).click();
    await page.getByRole('button', { name: 'Muat versi terbaru', exact: true }).waitFor();
    assert.equal(await page.getByLabel('Catatan Ardi Mahasiswa', { exact: true }).inputValue(), 'Isian lokal tetap ada'); checks++;
    conflict = false;
    await page.getByRole('button', { name: 'Muat versi terbaru', exact: true }).click();
    await confirm('Muat versi terbaru');
    await page.getByText('Draft tersimpan', { exact: true }).waitFor();
    await page.getByRole('button', { name: 'Simpan & finalisasi', exact: true }).click();
    await confirm('Simpan & finalisasi');
    await page.getByText('Buka kembali untuk koreksi', { exact: true }).waitFor();
    assert.equal(await page.locator('select[name^="records."]').count(), 0);
    assert.equal(sessions[0].status, 'finalized'); checks++;
    await layout('finalized desktop');
    await go('/attendance/students');
    await page.locator('[data-edit-student="101"]').click();
    await studentForm.getByLabel('Nama mahasiswa', { exact: true }).fill('Nama katalog dikoreksi');
    await studentForm.getByLabel('Status mahasiswa').selectOption('false');
    await studentForm.getByRole('button', { name: 'Simpan mahasiswa', exact: true }).click();
    await confirm('Arsipkan');
    await page.getByText('1 aktif / 2 arsip', { exact: true }).waitFor();
    await go('/attendance/new?course_id=1');
    assert.equal(await page.locator('[data-inline-course]').isVisible(), false);
    assert.match(await page.locator('[data-course-info]').innerText(), /Dosen: Dosen <img.*Semester mata kuliah: 3/);
    assert.match(await page.locator('[data-next-meeting]').innerText(), /Pertemuan 2/);
    await page.getByRole('button', { name: 'Buat draft & isi absensi' }).click();
    await page.getByRole('heading', { name: 'Pertemuan 2', exact: true }).waitFor();
    assert.deepEqual(sessions[1].records.map((record) => record.student_id), [102]);
    assert.equal(sessions[1].records[0].status, null);
    assert.equal(sessions[0].records[0].name, 'Ardi Mahasiswa', 'archive and rename do not rewrite snapshots'); checks++;
    await go(`/attendance/summary?course_id=1&date_from=${today}&status=draft&page=2`);
    await page.getByRole('heading', { name: 'Rekap kehadiran', exact: true }).waitFor();
    await page.getByText('Ardi Mahasiswa', { exact: true }).waitFor();
    const download = page.waitForEvent('download');
    await page.locator('[data-export]').click();
    const exported = await download;
    assert.match(exported.suggestedFilename(), /^rekap-absensi-.*\.csv$/);
    let csv = '';
    for await (const chunk of await exported.createReadStream()) csv += chunk.toString();
    assert.equal(csv.trim().split('\r\n').length, 3);
    assert.match(csv, /"'001234","Ardi Mahasiswa","1","0","0","0","1","100"/);
    assert.match(csv, /"'001235","Nadia Ketua","0","0","1","0","1","0"/);
    assert.equal(csv.includes('Nama katalog dikoreksi'), false); checks++;
    await page.setViewportSize({ width: 320, height: 900 }); await layout('summary mobile');
    await go('/attendance/sessions/1');
    await page.getByText('Buka kembali untuk koreksi', { exact: true }).click();
    await page.getByLabel('Alasan membuka kembali').fill('Koreksi status sesuai konfirmasi dosen.');
    await page.getByRole('button', { name: 'Buka kembali menjadi draft', exact: true }).click();
    await confirm('Buka kembali');
    await page.getByRole('button', { name: 'Simpan draft', exact: true }).waitFor();
    assert.equal(sessions[0].status, 'draft');
    await page.getByText('Koreksi status sesuai konfirmasi dosen.', { exact: true }).waitFor(); checks++;
    await page.getByLabel('Catatan Ardi Mahasiswa', { exact: true }).fill('Perubahan dibuang dengan konfirmasi');
    await page.getByRole('link', { name: 'Rekap & CSV', exact: true }).click();
    await confirm('Tinggalkan halaman');
    await page.getByRole('heading', { name: 'Rekap kehadiran', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Belum ada rekap final', exact: true }).waitFor();
    await go('/attendance/sessions/1');
    assert.equal(await page.getByLabel('Catatan Ardi Mahasiswa', { exact: true }).inputValue(), ''); checks++;
    await go('/attendance/courses');
    await page.getByText('Edit / arsipkan MK-1', { exact: true }).click();
    await page.locator('[data-course="1"]').getByLabel('Kode mata kuliah (opsional)', { exact: true }).fill('');
    await page.locator('[data-course="1"]').getByLabel('Semester mata kuliah', { exact: true }).fill('7');
    await page.locator('[data-course="1"]').getByLabel('Status mata kuliah').selectOption('false');
    await page.getByRole('button', { name: 'Simpan mata kuliah', exact: true }).click();
    await confirm('Arsipkan');
    await page.locator('.attendance-course .badge.status-hidden').waitFor();
    assert.equal(courses[0].code, 'MK-1');
    assert.equal(courses[0].semester, 7);
    assert.equal(sessions[0].semester, 3); checks++;
    await go('/attendance/new');
    await page.getByLabel('Nama mata kuliah baru', { exact: true }).waitFor();
    assert.equal(await page.getByLabel('Mata kuliah', { exact: true }).locator('option[value="1"]').count(), 0);
    assert.equal(await page.getByLabel('Semester mata kuliah', { exact: true }).inputValue(), '3'); checks++;
    user.role = 'student';
    // Reload authentication after changing the fixture's role.
    await page.reload();
    await go('/attendance');
    await page.getByRole('heading', { name: 'Halaman tidak tersedia', exact: true }).waitFor();
    assert.equal(await page.locator('.nav-link[href="#/attendance"]').count(), 0); checks++;
    assert.deepEqual(errors, []);
    console.log(`Attendance browser passed: ${checks} checks, manual roster/bulk/archive, inline course/semester/auto meeting, desktop/mobile, save/finalize/reopen/conflict/filtered export.`);
} finally {
    await browser.close();
}
