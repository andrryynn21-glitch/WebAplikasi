import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';

const base = process.env.LIVE_AUDIT_URL || 'http://127.0.0.1:8017';
if (!['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) throw new Error('Live audit only permits a loopback server.');
const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'mysql', DB_DATABASE: 'pengaduan_unpam_audit_e2e_test' };
const helper = (mode) => JSON.parse(execFileSync('php', ['tests/Support/live-audit.php', mode], { env, encoding: 'utf8', timeout: 120000 }));
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(join(process.env.PLAYWRIGHT_MODULE, 'index.mjs')).href : 'playwright');
let browser;
let prepared = false;
let stage = 'prepare isolated database';
const completed = [];
const pageErrors = [];
let navigation = 0;
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVZkAAAAASUVORK5CYII=', 'base64');
const capture = (page, path, method, click) => Promise.all([
    page.waitForResponse((r) => new URL(r.url()).pathname === `/api/v1${path}` && r.request().method() === method), click(),
]).then(([response]) => response);
async function expectStatus(response, status, label = stage) {
    assert.equal(response.status(), status, `${label}: unexpected HTTP status (response body intentionally omitted)`);
    return response.json();
}
async function go(page, path) {
    await page.goto(`${base}/?live-audit=${++navigation}#${path}`);
    await page.locator('.skeleton-group').waitFor({ state: 'hidden' });
    await page.locator('h1').waitFor();
}
async function call(page, path, method = 'GET', data) {
    const token = await page.evaluate(() => sessionStorage.getItem('rd.token'));
    return page.request.fetch(`${base}/api/v1${path}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, ...(data === undefined ? {} : { data }) });
}
async function confirm(page, name) {
    await page.getByRole('dialog').getByRole('button', { name, exact: true }).click();
}

try {
    const seed = helper('prepare'); prepared = true;
    browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
    const sessions = {};
    async function login(key) {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        page.on('pageerror', (error) => pageErrors.push(error.name));
        await go(page, '/login');
        await page.getByLabel('Alamat email', { exact: true }).fill(seed.accounts[key].email);
        await page.getByLabel('Kata sandi', { exact: true }).fill(seed.password);
        const response = await capture(page, '/auth/login', 'POST', () => page.getByRole('button', { name: 'Masuk', exact: true }).click());
        const result = await expectStatus(response, 200);
        assert.equal(result.data.user.id, seed.accounts[key].id, 'Server must use the seeded isolated database');
        await page.getByRole('heading', { name: 'Halo, Akun.' }).waitFor();
        sessions[key] = page;
        return page;
    }

    // Verify the server is attached to the disposable database BEFORE registering.
    stage = 'verify isolated backend and privileged login';
    const admin = await login('admin'); completed.push(stage);
    const verifier = await login('verifier');
    const lecturer = await login('lecturer');
    const leader = await login('leader');
    const reviewer = await login('reviewer');

    stage = 'register through the real frontend';
    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const student = await studentContext.newPage();
    student.on('pageerror', (error) => pageErrors.push(error.name));
    await go(student, '/register');
    await student.getByLabel('Nama lengkap', { exact: true }).fill('Mahasiswa Fiktif Audit');
    await student.getByLabel('Alamat email', { exact: true }).fill('student@audit.example.test');
    await student.getByLabel('Nomor Induk Mahasiswa').fill('AUDIT-STUDENT');
    await student.getByLabel('Kata sandi', { exact: true }).fill(seed.password);
    await student.getByLabel('Konfirmasi kata sandi').fill(seed.password);
    await expectStatus(await capture(student, '/auth/register', 'POST', () => student.getByRole('button', { name: 'Buat akun mahasiswa' }).click()), 201);
    await student.getByRole('heading', { name: 'Akun & verifikasi', exact: true }).waitFor();
    await expectStatus(await call(student, '/reports', 'POST', { category_id: 1, title: 'Cannot bypass identity', description: 'This should be blocked before student verification.' }), 403);
    completed.push(stage);

    stage = 'upload private KTM and independent manual approval';
    await student.getByLabel('Berkas KTM').setInputFiles({ name: 'audit-ktm.png', mimeType: 'image/png', buffer: png });
    const identity = await expectStatus(await capture(student, '/identity/submissions', 'POST', () => student.getByRole('button', { name: 'Kirim pengajuan identitas' }).click()), 201);
    const identityId = identity.data.id;
    await go(verifier, '/verifications');
    const download = verifier.waitForEvent('download');
    await verifier.getByRole('button', { name: 'Unduh KTM untuk diperiksa' }).click();
    await download;
    await verifier.locator(`[data-approve-verification="${identityId}"]`).click();
    await expectStatus(await capture(verifier, `/identity-verifications/${identityId}/approve`, 'POST', () => confirm(verifier, 'Setujui identitas')), 200);
    await go(student, '/account');
    assert.equal((await expectStatus(await call(student, '/identity/status'), 200)).data.identity_status, 'verified');
    completed.push(stage);

    stage = 'class invitation and independent membership approval';
    await go(lecturer, '/classes');
    const invitation = await expectStatus(await capture(lecturer, `/classes/${seed.class_id}/invitations`, 'POST', () => lecturer.getByRole('button', { name: 'Buat kode undangan', exact: true }).click()), 201);
    await student.getByLabel('Kode undangan dari pengelola kelas').fill(invitation.data.code);
    const membership = await expectStatus(await capture(student, '/memberships/join', 'POST', () => student.getByRole('button', { name: 'Ajukan keanggotaan' }).click()), 202);
    await go(lecturer, '/classes');
    const memberForm = lecturer.locator(`[data-membership="${membership.data.id}"]`);
    await memberForm.getByLabel('Keputusan keanggotaan').selectOption('approve');
    await memberForm.getByRole('button', { name: 'Tinjau keputusan' }).click();
    await expectStatus(await capture(lecturer, `/memberships/${membership.data.id}/decide`, 'POST', () => confirm(lecturer, 'Simpan keputusan')), 200);
    completed.push(stage);

    stage = 'submit private complaint with real attachment';
    await go(student, '/reports/new');
    const categories = (await expectStatus(await call(student, '/report-categories'), 200)).data;
    await student.getByLabel('Kategori', { exact: true }).selectOption(String(categories.find((c) => c.code === 'facilities').id));
    await student.getByLabel('Judul pengaduan').fill('Pengaduan fiktif proyektor kelas');
    await student.getByLabel('Uraian kejadian').fill('PRIVATE-AUDIT-CANARY: data fiktif kerusakan proyektor untuk audit integrasi, bukan data mahasiswa nyata.');
    await student.locator('input[type=file]').setInputFiles({ name: 'audit-bukti.txt', mimeType: 'text/plain', buffer: Buffer.from('PRIVATE-AUDIT-FILE: fictitious evidence') });
    const created = await expectStatus(await capture(student, '/reports', 'POST', () => student.getByRole('button', { name: 'Kirim pengaduan privat' }).click()), 201);
    const ref = created.data.public_ref;
    const detail = (await expectStatus(await call(student, `/reports/${ref}`), 200)).data;
    const attachmentId = detail.attachments[0].id;
    assert.equal(detail.current_handler.id, seed.accounts.leader.id);
    assert.equal(detail.status, 'under_review'); completed.push(stage);

    stage = 'request and answer clarification through the frontend';
    await go(leader, `/reports/${ref}`);
    const clarification = leader.locator('[data-clarification-request]');
    await clarification.locator('textarea[name=body]').fill('Mohon konfirmasi waktu kejadian untuk catatan penanganan.');
    await expectStatus(await capture(leader, `/reports/${ref}/clarification-requests`, 'POST', () => clarification.locator('button[type=submit]').click()), 201);
    await go(student, `/reports/${ref}`);
    const update = student.locator('[data-clarification-response]');
    await update.locator('[name=body]').fill('Kejadian fiktif berlangsung pukul sepuluh pagi.');
    await expectStatus(await capture(student, `/reports/${ref}/updates`, 'POST', () => update.locator('button[type=submit]').click()), 201);
    completed.push(stage);

    stage = 'rejection and independent appeal workflow';
    await go(leader, `/reports/${ref}`);
    const statusForm = leader.locator('[data-status]');
    await statusForm.locator('[name=status]').selectOption('rejected');
    await statusForm.locator('[name=reason]').fill('Bukti fiktif belum mencukupi dan perlu peninjauan tambahan.');
    await statusForm.locator('button[type=submit]').click();
    await expectStatus(await capture(leader, `/reports/${ref}/status`, 'PATCH', () => confirm(leader, 'Perbarui status')), 200);
    await go(student, `/reports/${ref}`);
    const appealForm = student.locator('[data-submit-appeal]');
    await appealForm.locator('[name=reason]').fill('Ada bukti fiktif tambahan yang perlu ditinjau peninjau independen.');
    await appealForm.locator('button[type=submit]').click();
    const appeal = await expectStatus(await capture(student, `/reports/${ref}/appeals`, 'POST', () => confirm(student, 'Ajukan banding')), 201);
    await go(reviewer, `/appeals/${appeal.data.id}?mode=assign`);
    const reviewerForm = reviewer.locator('[data-reviewer]');
    await reviewerForm.locator('[name=reviewer_id]').selectOption(String(seed.accounts.reviewer.id));
    await reviewerForm.locator('button[type=submit]').click();
    await expectStatus(await capture(reviewer, `/appeals/${appeal.data.id}/reviewer`, 'POST', () => confirm(reviewer, 'Tetapkan peninjau')), 200);
    await go(reviewer, `/appeals/${appeal.data.id}`);
    const decision = reviewer.locator('[data-appeal-decision]');
    await decision.locator('[name=status]').selectOption('accepted');
    await decision.locator('[name=decision_note]').fill('Banding fiktif diterima, pemeriksaan harus dibuka kembali.');
    await decision.locator('button[type=submit]').click();
    await expectStatus(await capture(reviewer, `/appeals/${appeal.data.id}/decision`, 'POST', () => confirm(reviewer, 'Catat keputusan')), 200);
    assert.equal((await expectStatus(await call(student, `/reports/${ref}`), 200)).data.status, 'reopened');
    completed.push(stage);

    stage = 'assignment forwarding and final resolution on real API';
    await expectStatus(await call(lecturer, `/reports/${ref}/assignments`, 'POST', { assignee_id: seed.accounts.lecturer.id, reason: 'Penanganan ulang oleh dosen fiktif.' }), 201);
    await expectStatus(await call(lecturer, `/reports/${ref}/status`, 'PATCH', { status: 'in_progress' }), 200);
    await expectStatus(await call(lecturer, `/reports/${ref}/forward`, 'POST', { assignee_id: seed.accounts.leader.id, reason: 'Pengalihan fiktif untuk penyelesaian fasilitas kelas.' }), 200);
    await expectStatus(await call(leader, `/reports/${ref}/status`, 'PATCH', { status: 'in_progress' }), 200);
    await expectStatus(await call(leader, `/reports/${ref}/status`, 'PATCH', { status: 'resolution_proposed' }), 200);
    await expectStatus(await call(leader, `/reports/${ref}/status`, 'PATCH', { status: 'resolved', resolution_summary: 'Proyektor fiktif selesai diperbaiki dalam skenario pengujian.' }), 200);
    const resolved = (await expectStatus(await call(student, `/reports/${ref}`), 200)).data;
    assert.equal(resolved.status, 'resolved'); assert.ok(resolved.timeline.length >= 8); completed.push(stage);

    stage = 'aspiration creation, concurrent duplicate support and moderation';
    await go(student, '/aspirations/new');
    await student.getByLabel('Judul aspirasi').fill('Jadwal belajar fiktif sebelum ujian');
    await student.getByLabel('Isi aspirasi').fill('Usulan fiktif mengadakan belajar bersama di kelas untuk pengujian integrasi.');
    const aspiration = await expectStatus(await capture(student, '/aspirations', 'POST', () => student.locator('[data-create-aspiration] button[type=submit]').click()), 201);
    const aspirationId = aspiration.data.id;
    const votes = await Promise.all([call(leader, `/aspirations/${aspirationId}/support`, 'POST'), call(leader, `/aspirations/${aspirationId}/support`, 'POST')]);
    for (const vote of votes) await expectStatus(vote, 200);
    assert.equal((await expectStatus(await call(student, `/aspirations/${aspirationId}`), 200)).data.supports_count, 1);
    const comment = await expectStatus(await call(leader, `/aspirations/${aspirationId}/comments`, 'POST', { body: 'Komentar fiktif yang akan dimoderasi.' }), 201);
    await expectStatus(await call(student, `/aspirations/${aspirationId}/comments/${comment.data.id}/flag`, 'POST', { reason: 'offensive', note: 'Catatan laporan fiktif.' }), 201);
    await expectStatus(await call(lecturer, `/aspirations/${aspirationId}/comments/${comment.data.id}/moderate`, 'POST', { action: 'hide', reason: 'Alasan moderasi fiktif untuk audit.' }), 200);
    assert.equal((await expectStatus(await call(student, `/aspirations/${aspirationId}/comments`), 200)).data.length, 0);
    completed.push(stage);

    stage = 'cross-class and administrator private-resource denial';
    const outsider = await login('outsider');
    for (const path of [`/reports/${ref}`, `/attachments/${attachmentId}`, `/aspirations/${aspirationId}`]) {
        const denied = await call(outsider, path); await expectStatus(denied, 403);
        assert.ok(!(await denied.text()).includes('PRIVATE-AUDIT'));
    }
    await expectStatus(await call(admin, `/reports/${ref}`), 403);
    await expectStatus(await call(admin, `/identity-verifications/${identityId}/ktm`), 403);
    const audit = await call(admin, '/admin/audit-logs'); await expectStatus(audit, 200);
    assert.ok(!(await audit.text()).includes('PRIVATE-AUDIT'));
    completed.push(stage);

    stage = 'repeated denial cooldown without audio dependency';
    await expectStatus(await call(outsider, `/reports/${ref}`), 403);
    await expectStatus(await call(outsider, `/reports/${ref}`), 403);
    const cooldown = await expectStatus(await call(outsider, '/dashboard'), 429);
    assert.equal(cooldown.error.code, 'SECURITY_COOLDOWN');
    await expectStatus(await call(outsider, '/auth/logout', 'POST'), 200);
    completed.push(stage);

    stage = 'notification read and server-side logout';
    await go(student, '/notifications');
    await expectStatus(await capture(student, '/notifications/read-all', 'POST', () => student.getByRole('button', { name: 'Tandai semua dibaca' }).click()), 200);
    assert.equal((await expectStatus(await call(student, '/notifications'), 200)).meta.unread_count, 0);
    const oldToken = await student.evaluate(() => sessionStorage.getItem('rd.token'));
    await student.getByRole('button', { name: 'Buka navigasi' }).click();
    await student.getByRole('button', { name: 'Keluar dari akun' }).click();
    await expectStatus(await capture(student, '/auth/logout', 'POST', () => confirm(student, 'Keluar')), 200);
    await student.getByRole('button', { name: 'Masuk', exact: true }).waitFor();
    const revoked = await student.request.get(`${base}/api/v1/auth/me`, { headers: { Authorization: `Bearer ${oldToken}`, Accept: 'application/json' } });
    await expectStatus(revoked, 401); completed.push(stage);

    assert.deepEqual(pageErrors, [], 'No browser JavaScript exceptions');
    console.log(JSON.stringify({ result: 'passed', stages: completed, database: env.DB_DATABASE, apiInterception: false, pageErrors }));
} catch (error) {
    console.error(`Live audit failed at: ${stage}. ${error.name}: ${error.message}`);
    process.exitCode = 1;
} finally {
    await browser?.close();
    if (prepared) {
        try { assert.equal(helper('cleanup').cleaned, true); console.log('Isolated audit database and referenced uploads cleaned.'); }
        catch { console.error('Audit cleanup failed; inspect the dedicated audit database before repeating.'); process.exitCode = 1; }
    }
}
