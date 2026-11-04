import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';

// API fixtures are isolated to this browser test; no real attendance data is written.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(join(process.env.PLAYWRIGHT_MODULE, 'index.mjs')).href : 'playwright');
const base = process.env.FRONTEND_URL || 'http://127.0.0.1:8016';
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', acceptDownloads: true });
const user = { id: 1, name: 'Nadia Ketua', role: 'class_leader', role_name: 'Ketua Kelas', permissions: ['attendance.manage'], identity_status: 'verified', membership: { status: 'active', class_id: 1 } };
const room = { id: 1, name: 'Teknik Informatika A', code: 'IF-A', academic_term: { id: 1, code: '2026-1', name: 'Ganjil 2026/2027', starts_on: '2026-09-01', ends_on: '2027-01-31' } };
const roster = [{ user_id: 2, name: 'Ardi Mahasiswa', nim: '001234' }, { user_id: 1, name: 'Nadia Ketua', nim: '001235' }];
const courses = [];
const sessions = [];
const errors = [];
let conflict = false;
let saves = 0;
let checks = 0;
const at = '2026-11-03T08:00:00Z';
const counts = (records) => records.reduce((out, record) => { out[record.status || 'unmarked']++; return out; }, { present: 0, excused: 0, sick: 0, absent: 0, unmarked: 0, total: records.length });
function log(session, action, reason = null) {
    session.history.push({ action: `attendance_${action}`, actor: { id: 1, name: user.name }, created_at: at, reason });
    session.counts = counts(session.records);
}
await context.addInitScript(() => sessionStorage.setItem('rd.token', 'attendance-browser-fixture'));
await context.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace('/api/v1', '');
    const method = request.method();
    const json = (data, status = 200, meta) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ message: 'Respons pengujian', data, ...(meta ? { meta } : {}) }) });
    if (path === '/auth/me') return json(user);
    if (path === '/security/voice-warnings') return json({ enabled: false, sounds: [] });
    if (path === '/attendance') return json({ class: room, courses, roster, timezone: 'Asia/Jakarta', today: '2026-11-03' });
    if (path === '/attendance/courses' && method === 'POST') {
        const body = request.postDataJSON();
        const course = { ...body, id: 1, is_active: true, sessions_count: 0 };
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
        assert.equal(body.course_id, 1);
        assert.equal(body.meeting_number, 1);
        const session = { ...body, id: 1, course: { ...courses[0] }, status: 'draft', version: 1, day_name: 'Selasa', created_at: at, updated_at: at, created_by: user, updated_by: user, finalized_at: null, records: roster.map((student) => ({ ...student, status: null, note: null })), history: [] };
        log(session, 'created'); sessions.push(session); courses[0].sessions_count++;
        return json(session, 201);
    }
    if (path === '/attendance/sessions/1' && method === 'GET') return json(sessions[0]);
    if (path === '/attendance/sessions/1' && method === 'PATCH') {
        if (conflict) return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ message: 'Versi telah berubah di tab lain.', error: { code: 'ATTENDANCE_CONFLICT' } }) });
        const body = request.postDataJSON();
        assert.equal(body.version, sessions[0].version);
        assert.deepEqual(body.records.map((record) => record.user_id), [2, 1], 'hidden rows remain in payload');
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
    if (path === '/attendance/summary') {
        const records = sessions[0]?.status === 'finalized' ? sessions[0].records : [];
        return json({ class: room, sessions_count: records.length ? 1 : 0, totals: { ...counts(records), attendance_rate: records.length ? 50 : null }, students: records.map((record) => ({ ...record, present: Number(record.status === 'present'), sick: Number(record.status === 'sick'), excused: 0, absent: 0, total: 1, attendance_rate: record.status === 'present' ? 100 : 0 })) });
    }
    if (path === '/attendance/export') return route.fulfill({ status: 200, contentType: 'text/csv', body: 'Nama,Hadir\nArdi,1\n' });
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
    await go('/attendance/courses');
    const courseForm = page.locator('[data-course=""]');
    await courseForm.getByLabel('Kode mata kuliah').fill('0001');
    await courseForm.getByLabel('Nama mata kuliah').fill('Algoritma & Pemrograman');
    await courseForm.getByLabel('Nama dosen pengampu').fill('Dosen <img src=x onerror=alert(1)>');
    await courseForm.getByRole('button', { name: 'Tambah mata kuliah', exact: true }).click();
    await page.locator('[data-course="1"]').waitFor({ state: 'attached' });
    assert.equal(await page.locator('img[onerror]').count(), 0); checks++;
    await go('/attendance/new?course_id=1');
    await page.getByLabel('Pertemuan ke').fill('1');
    await page.getByLabel('Jam mulai (WIB)').fill('08:00');
    await page.getByLabel('Jam selesai (WIB)').fill('10:00');
    await page.getByRole('button', { name: 'Buat draft & isi absensi' }).click();
    await page.getByRole('heading', { name: 'Pertemuan 1', exact: true }).waitFor();
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
    await go('/attendance/summary?course_id=1');
    await page.getByRole('heading', { name: 'Rekap kehadiran', exact: true }).waitFor();
    const download = page.waitForEvent('download');
    await page.locator('[data-export]').click();
    assert.match((await download).suggestedFilename(), /^rekap-absensi-.*\.csv$/); checks++;
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
    await go('/attendance/sessions/1');
    assert.equal(await page.getByLabel('Catatan Ardi Mahasiswa', { exact: true }).inputValue(), ''); checks++;
    await go('/attendance/courses');
    await page.getByText('Edit / arsipkan 0001', { exact: true }).click();
    await page.locator('[data-course="1"]').getByLabel('Status mata kuliah').selectOption('false');
    await page.getByRole('button', { name: 'Simpan mata kuliah', exact: true }).click();
    await confirm('Arsipkan');
    await page.locator('.attendance-course .badge.status-hidden').waitFor();
    await go('/attendance/new');
    await page.getByRole('heading', { name: 'Tambahkan mata kuliah aktif terlebih dahulu' }).waitFor(); checks++;
    user.role = 'student';
    // Reload authentication after changing the fixture's role.
    await page.reload();
    await go('/attendance');
    await page.getByRole('heading', { name: 'Halaman tidak tersedia', exact: true }).waitFor();
    assert.equal(await page.locator('.nav-link[href="#/attendance"]').count(), 0); checks++;
    assert.deepEqual(errors, []);
    console.log(`Attendance browser passed: ${checks} checks, desktop/mobile, save/finalize/reopen/conflict/export.`);
} finally {
    await browser.close();
}
