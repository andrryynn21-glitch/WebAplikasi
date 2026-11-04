import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';

// Fixtures belong only to browser tests. Production pages always request the API.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(join(process.env.PLAYWRIGHT_MODULE, 'index.mjs')).href : 'playwright');
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const base = process.env.FRONTEND_URL || 'http://127.0.0.1:8016';
const screenshots = process.env.E2E_SCREENSHOT_DIR;
const at = '2026-10-21T08:00:00Z';
const roles = {
    student: ['reports.create', 'reports.view.own', 'reports.respond_clarification', 'reports.appeal_submit', 'aspirations.create', 'aspirations.participate', 'aspirations.flag', 'identity.submit'],
    class_leader: ['reports.create', 'reports.view.own', 'reports.view.class', 'reports.view.private', 'reports.update_status', 'reports.request_clarification', 'aspirations.create', 'aspirations.participate', 'aspirations.flag', 'aspirations.moderate', 'identity.submit', 'classes.invite', 'memberships.review', 'security.view', 'reports.statistics'],
    lecturer_coordinator: ['reports.view.assigned', 'reports.view.private', 'reports.update_status', 'reports.assign', 'reports.request_clarification', 'reports.appeal_review', 'aspirations.moderate', 'memberships.review', 'classes.invite', 'security.view', 'reports.statistics'],
    super_admin: ['users.manage', 'roles.manage', 'classes.manage', 'classes.invite', 'memberships.review', 'security.view', 'audit.view'],
    identity_verifier: ['identity.verify'],
};
const room = { id: 1, name: 'Teknik Informatika A', code: 'IF-A', semester: 5, program_study: 'Teknik Informatika', academic_term: { id: 1, code: '2026-1', name: 'Ganjil 2026/2027' } };
const categories = [{ id: 4, code: 'facilities', name: 'Fasilitas kelas', sensitive: false }];
const stats = { class_ids: [1], total_reports: 3, open_reports: 2, resolved_reports: 1, rejected_reports: 0, by_status: { under_review: 1, in_progress: 1, resolved: 1 }, by_category: { facilities: 3 }, average_resolution_hours: 4, open_older_than_7_days: 0 };
let checks = 0;
let navigationCount = 0;
const contexts = [];

async function setup(role = 'student', authenticated = true) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }); contexts.push(context);
    const user = { id: role === 'student' ? 10 : 20, name: role === 'student' ? 'Nadia Putri' : 'Petugas Kelas', email: `${role}@example.test`, nim: 'TEST-NIM', role, role_name: ({ student: 'Mahasiswa', class_leader: 'Ketua Kelas', lecturer_coordinator: 'Dosen Koordinator', super_admin: 'Super Admin', identity_verifier: 'Petugas Verifikasi' })[role], permissions: roles[role], identity_status: 'verified', identity_message: 'Identitas terverifikasi.', is_active: true, membership: ['student', 'class_leader'].includes(role) ? { status: 'active', class_id: 1 } : { status: null, class_id: null }, created_at: at };
    if (authenticated) await context.addInitScript(() => sessionStorage.setItem('rd.token', 'test-session-token'));
    const state = { user, fail: null, requests: [], pageErrors: [], consoleErrors: [], invalid: false, slowReports: false, emptyReports: false, metadataOnly: false, createCount: 0, supportCount: 0, supported: false, lastBody: null };
    const report = { public_ref: 'PGD-TEST001', title: 'Proyektor ruang kelas perlu diperbaiki', status: 'under_review', status_label: 'Sedang ditinjau', sensitivity: 'routine', routing_mode: 'auto', category: categories[0], class: room, is_mine: role === 'student', created_at: at, last_activity_at: at, resolved_at: null };
    const aspiration = { id: 7, title: 'Jadwal belajar bersama sebelum ujian', body: 'Mari menyusun jadwal belajar bersama yang sesuai dengan waktu kelas.', status: 'published', is_pinned: false, supports_count: 4, comments_count: 1, author: { id: 11, name: 'Ardi Mahendra' }, is_mine: false, created_at: at, last_activity_at: at };
    await context.route('**/api/v1/**', async (route) => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname.split('/api/v1')[1]; const method = request.method();
        state.requests.push(`${method} ${path}`);
        const json = (data, status = 200, meta) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ message: 'Respons pengujian', data, ...(meta ? { meta } : {}) }) });
        const list = (data) => json(data, 200, { pagination: { current_page: 1, per_page: 20, total: data.length, last_page: 1 } });
        if (path === '/security/voice-warnings') return json({ enabled: true, cooldown_seconds: 30, sounds: [{ key: 'access_denied', available: false, file: null, placeholder: 'akses-ditolak' }] });
        if (state.fail && path === state.fail.path) {
            if (state.fail.network) return route.abort('failed');
            return route.fulfill({ status: state.fail.status, contentType: 'application/json', body: JSON.stringify({ message: state.fail.message || 'Akses ditolak oleh server.', error: { code: state.fail.code || 'FORBIDDEN' }, meta: { voice_warning: { play: true, category: 'access_denied', placeholder: true, sound: null, cooldown_seconds: 30 } } }) });
        }
        if (path === '/auth/me') return json(user);
        if (path === '/auth/login' || path === '/auth/register') return json({ token: 'test-session-token', token_type: 'Bearer', user, expires_in_minutes: 480 }, path.endsWith('register') ? 201 : 200);
        if (path === '/auth/logout' || path === '/auth/logout-all') return json(null);
        if (path === '/dashboard') return json(role === 'super_admin' ? { role, system: { active_users: 45, inactive_users: 2, classes: 3, pending_identity_verifications: 4 }, security: { events_last_24h: 3, high_severity_last_7d: 0 }, note: 'Admin tidak dapat membaca pengaduan privat.' } : role === 'identity_verifier' ? { role, identity_verifications: { pending: 1 } } : role === 'student' ? { role, reports: { total: 3, open: 2, resolved: 1, needs_clarification: 0 }, recent_reports: [report], aspirations: [aspiration], membership: { status: 'active', class: room } } : { role, classes: [room], reports: role === 'class_leader' ? { assigned_open: 2, waiting_clarification: 0, resolved_by_me: 1 } : { needs_review: 1, under_review: 1, waiting_follow_up: 0, resolution_proposed: 1 }, needs_attention: [report], statistics: stats });
        if (path === '/report-categories') return json(categories);
        if (path === '/reports/statistics') return json(stats);
        if (path === '/reports' && method === 'GET') { if (state.slowReports) await new Promise((r) => setTimeout(r, 400)); return list(state.emptyReports ? [] : [report]); }
        if (path === '/reports' && method === 'POST') {
            state.createCount++; state.lastBody = request.postData();
            if (state.invalid) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Data yang dikirim tidak valid.', error: { code: 'VALIDATION_FAILED', details: { title: ['Judul perlu diperiksa kembali.'] } } }) });
            await new Promise((r) => setTimeout(r, 150)); return json(report, 201);
        }
        if (path === '/reports/PGD-TEST001') return json(state.metadataOnly ? { ...report, title: null, private_details: null, reporter: null, assignments: [], timeline: [], updates: [], attachments: [], summary_only: true } : { ...report, private_details: { description: 'Bukti privat hanya boleh terlihat oleh pemilik dan penangan yang berwenang.', involved_parties: [], desired_outcome: 'Perbaikan proyektor' }, reporter: role === 'student' ? null : { id: 10, name: 'Nadia Putri', nim: 'TEST-NIM' }, current_handler: { id: 20, name: 'Petugas Kelas' }, timeline: [{ id: 1, to_status: 'submitted', to_status_label: 'Terkirim', actor: null, reason: 'Pengaduan dikirim', created_at: at }], updates: [], assignments: [{ id: 1, type: 'primary', assignee: { id: 20, name: 'Petugas Kelas' }, assigned_at: at, revoked_at: null }], attachments: [{ id: 1, original_name: 'bukti.txt', mime: 'text/plain', size: 14 }], open_appeal: null });
        if (/^\/reports\/PGD-TEST001\/(updates|status|assignments|forward|clarification-requests)$/.test(path)) return json({ id: 1, ...report });
        if (path === '/attachments/1' || /^\/identity-verifications\/\d+\/ktm$/.test(path)) return route.fulfill({ status: 200, contentType: 'text/plain', body: 'test document' });
        if (path === '/aspirations') return method === 'GET' ? list([aspiration]) : json(aspiration, 201);
        if (path === '/aspirations/7') return json({ ...aspiration, supported_by_me: state.supported, supports_count: 4 + Number(state.supported) });
        if (path === '/aspirations/7/comments') return method === 'GET' ? json([{ id: 2, body: '<img src=x onerror=alert(1)> Teks ini harus aman.', author: { id: 11, name: 'Ardi' }, parent_id: null, created_at: at }]) : json({ id: 3, body: 'Komentar pengujian', created_at: at }, 201);
        if (path === '/aspirations/7/support') { state.supportCount++; state.supported = method !== 'DELETE'; await new Promise((r) => setTimeout(r, 150)); return json({ supported: state.supported, created: true, supports_count: 4 + Number(state.supported) }); }
        if (path === '/aspirations/7/flags') return list([{ id: 1, content_type: 'comment', content_id: 2, content_status: 'published', body: 'Konten dilaporkan', can_moderate: true, reason: 'offensive', note: null, status: 'open', created_at: at }]);
        if (/^\/aspirations\/7(\/comments\/2)?\/(moderate|flag)$/.test(path)) return json({ id: 1, action: 'hide' });
        if (path === '/identity/status') return json({ identity_status: user.identity_status, message: 'Identitas diproses manual.', latest_submission: null });
        if (path === '/identity/submissions') return json({ id: 4, status: 'pending_verification', submitted_at: at }, 201);
        if (path === '/memberships') return json([{ id: 1, status: 'active', class: room, created_at: at }]);
        if (path === '/classes') return json([room]);
        if (path === '/academic-terms') return json([{ id: 1, code: '2026-1', name: 'Ganjil 2026', starts_on: '2026-09-01', ends_on: '2027-01-31', is_active: true }]);
        if (path === '/profile') return json(user);
        if (path === '/memberships/join') return json({ id: 2, status: 'pending', class: room }, 202);
        if (path === '/notifications') return json([{ id: 1, type: 'report_status_changed', title: 'Status pengaduan diperbarui', message: 'Periksa perkembangan pengaduan Anda.', data: { report_ref: report.public_ref }, severity: 'info', read_at: null, created_at: at, action_url: 'https://evil.invalid' }], 200, { unread_count: 1, pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } });
        if (/^\/notifications\/(read-all|\d+\/read)$/.test(path)) return json(null);
        if (path === '/admin/users') return list([{ ...user, id: 55, name: 'Anggota Kelas', role: 'student', role_name: 'Mahasiswa', permissions: roles.student }]);
        if (path === '/admin/audit-logs') return list([{ id: 1, action: 'class_created', action_label: 'Kelas dibuat', actor: { id: 20, name: 'Admin', email: 'admin@example.test' }, actor_role: 'super_admin', subject_type: 'ClassRoom', subject_id: 1, changes: { code: 'IF-A' }, ip_address: '127.0.0.1', created_at: at }]);
        if (path === '/security-events') return list([{ id: 1, category: 'access_denied', category_label: 'Akses ditolak', severity: 'low', user: { id: 55, name: 'Anggota Kelas', email: 'member@example.test' }, class: room, denial_reason: 'Akses tidak diizinkan.', route: 'reports.show', method: 'GET', resource_type: 'Report', resource_ref: 'PGD-TEST001', ip_address: '127.0.0.1', created_at: at }]);
        if (path === '/identity-verifications') return list([{ id: 4, user: { id: 55, name: 'Anggota Kelas', email: 'member@example.test' }, nim: 'TEST-NIM', full_name: 'Anggota Kelas', status: 'pending_verification', submitted_at: at, ktm_download_url: '/api/v1/identity-verifications/4/ktm' }]);
        if (/^\/classes\/\d+\/memberships\/pending$/.test(path)) return list([{ id: 2, user: { id: 55, name: 'Anggota Kelas', nim: 'TEST-NIM', email: 'member@example.test' }, status: 'pending', created_at: at }]);
        if (/^\/admin\/users\/55\/(role|suspend|reactivate)$/.test(path) || /^\/memberships\/2\/decide$/.test(path) || /^\/identity-verifications\/4\/(approve|reject)$/.test(path)) return json(null);
        if (path === '/admin/classes' || /^\/admin\/classes\/\d+(\/(leader|lecturers))?$/.test(path) || /^\/classes\/\d+\/invitations$/.test(path)) return json({ id: 1, code: 'TEST-INVITE', ...room }, 201);
        throw new Error(`Unmatched API fixture: ${method} ${path}`);
    });
    const page = await context.newPage();
    page.on('pageerror', (e) => state.pageErrors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource:')) state.consoleErrors.push(m.text()); });
    return { context, page, state };
}
async function go(page, path) {
    await page.goto(`${base}/?browser-test=${++navigationCount}#${path}`);
    await page.locator('.skeleton-group').waitFor({ state: 'hidden' });
    await page.locator('h1').first().waitFor();
}
async function layout(page, label) {
    const result = await page.evaluate(() => {
        const ids = [...document.querySelectorAll('[id]')].map((el) => el.id);
        return { overflow: document.documentElement.scrollWidth > innerWidth + 1, duplicateIds: ids.filter((id, index) => ids.indexOf(id) !== index), unlabeled: [...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter((el) => !el.labels?.length && !el.getAttribute('aria-label')).map((el) => el.name) };
    });
    assert.equal(result.overflow, false, `${label}: horizontal overflow`);
    assert.deepEqual(result.duplicateIds, [], `${label}: duplicate ids`);
    assert.deepEqual(result.unlabeled, [], `${label}: unlabeled controls`);
    checks++;
}
try {
    const publicSession = await setup('student', false);
    for (const width of [320, 390, 768, 1440]) {
        await publicSession.page.setViewportSize({ width, height: 1000 });
        await go(publicSession.page, '/login'); await layout(publicSession.page, `login ${width}`);
    }
    if (screenshots) await publicSession.page.screenshot({ path: join(screenshots, 'ruang-dengar-login.png'), fullPage: true, animations: 'disabled' });
    await publicSession.page.getByLabel('Alamat email', { exact: true }).fill('student@example.test');
    await publicSession.page.getByLabel('Kata sandi', { exact: true }).fill('PasswordTest123!');
    await publicSession.page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await publicSession.page.getByRole('heading', { name: 'Halo, Nadia.' }).waitFor(); checks++;
    await publicSession.page.getByRole('button', { name: 'Keluar dari akun' }).click();
    await publicSession.page.getByRole('dialog').getByRole('button', { name: 'Keluar', exact: true }).click();
    await publicSession.page.getByRole('button', { name: 'Masuk', exact: true }).waitFor();
    assert.ok(publicSession.state.requests.includes('POST /auth/logout'));
    assert.equal(await publicSession.page.evaluate(() => sessionStorage.getItem('rd.token')), null); checks++;

    const dashboardLogin = await setup('student', false);
    let documentLoads = 0;
    dashboardLogin.page.on('request', (request) => { if (request.resourceType() === 'document') documentLoads++; });
    await go(dashboardLogin.page, '/dashboard');
    await dashboardLogin.page.getByLabel('Alamat email', { exact: true }).fill('student@example.test');
    await dashboardLogin.page.getByLabel('Kata sandi', { exact: true }).fill('PasswordTest123!');
    await dashboardLogin.page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await dashboardLogin.page.getByRole('heading', { name: 'Halo, Nadia.' }).waitFor();
    assert.equal(await dashboardLogin.page.evaluate(() => location.hash), '#/dashboard');
    assert.equal(dashboardLogin.state.requests.filter((request) => request === 'POST /auth/login').length, 1);
    assert.equal(documentLoads, 1, 'login renders the dashboard without reloading the document');
    assert.deepEqual(dashboardLogin.state.pageErrors, []);
    assert.deepEqual(dashboardLogin.state.consoleErrors, []); checks++;

    const student = await setup(); const page = student.page;
    await go(page, '/dashboard');
    if (screenshots) await page.screenshot({ path: join(screenshots, 'ruang-dengar-dashboard.png'), fullPage: true, animations: 'disabled' });
    await layout(page, 'student dashboard');
    student.state.emptyReports = true;
    await go(page, '/reports'); await page.getByRole('heading', { name: 'Belum ada pengaduan', exact: true }).waitFor(); checks++;
    student.state.emptyReports = false; student.state.slowReports = true;
    await page.evaluate(() => { location.hash = '/reports?status=in_progress'; });
    await page.getByRole('status', { name: 'Memuat data', exact: true }).waitFor(); checks++;
    await page.evaluate(() => { location.hash = '/dashboard'; });
    await page.getByRole('heading', { name: 'Halo, Nadia.' }).waitFor();
    await page.waitForLoadState('networkidle');
    assert.equal(await page.getByRole('heading', { name: 'Pengaduan privat', exact: true }).count(), 0); checks++;
    student.state.slowReports = false;
    await go(page, '/reports/new');
    await page.getByLabel('Kategori', { exact: true }).selectOption('4');
    await page.getByLabel('Judul pengaduan').fill('Proyektor kelas tidak dapat digunakan');
    await page.getByLabel('Uraian kejadian').fill('Proyektor pada ruang kelas tidak menampilkan gambar selama dua pertemuan terakhir.');
    student.state.invalid = true;
    await page.getByRole('button', { name: 'Kirim pengaduan privat' }).click();
    await page.getByText('Judul perlu diperiksa kembali.').waitFor(); checks++;
    student.state.invalid = false;
    const before = student.state.createCount;
    await page.getByRole('button', { name: 'Kirim pengaduan privat' }).dblclick();
    await page.getByRole('heading', { name: 'Uraian pengaduan', exact: true }).waitFor();
    assert.equal(student.state.createCount, before + 1, 'duplicate report submit blocked');
    assert.ok(student.state.lastBody.includes('name="title"')); checks++;
    assert.equal(await page.locator('[data-status]').count(), 0, 'owner has no status action');
    assert.equal(await page.locator('select[name=internal]').count(), 0, 'owner cannot set internal');
    await layout(page, 'report detail with fields');
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Unduh', exact: true }).click();
    assert.equal((await download).suggestedFilename(), 'bukti.txt'); checks++;
    await go(page, '/aspirations/7');
    assert.equal(await page.locator('img[onerror]').count(), 0, 'comment text escaped');
    await page.getByRole('button', { name: 'Dukung aspirasi' }).dblclick();
    await page.getByRole('button', { name: 'Batalkan dukungan' }).waitFor();
    assert.equal(student.state.supportCount, 1); checks++;
    await go(page, '/notifications');
    assert.equal(await page.locator('a[href^="https://evil"]').count(), 0, 'notification action URL not trusted'); checks++;
    for (const route of ['/dashboard', '/reports', '/reports/PGD-TEST001', '/aspirations/7', '/account', '/notifications']) {
        await page.setViewportSize({ width: 390, height: 844 }); await go(page, route); await layout(page, `mobile ${route}`);
    }
    await page.getByRole('button', { name: 'Buka navigasi' }).click();
    assert.equal(await page.locator('.workspace').evaluate((el) => el.inert), true);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.sidebar').evaluate((el) => el.inert), true);
    assert.equal(await page.locator('.workspace').evaluate((el) => el.inert), false); checks++;
    if (screenshots) await page.screenshot({ path: join(screenshots, 'ruang-dengar-mobile.png'), fullPage: true, animations: 'disabled' });
    await page.emulateMedia({ reducedMotion: 'reduce' }); await go(page, '/dashboard');
    assert.equal(await page.locator('.page-body').evaluate((el) => getComputedStyle(el).animationName), 'none'); checks++;
    student.state.fail = { path: '/reports', status: 403 };
    await go(page, '/reports');
    await page.getByRole('dialog').waitFor();
    assert.equal(await page.evaluate(() => document.querySelector('dialog').contains(document.activeElement)), true);
    await page.keyboard.press('Escape');
    assert.equal(await page.getByRole('dialog').count(), 0); checks++;
    student.state.fail = { path: '/reports', network: true };
    await go(page, '/reports'); await page.getByText('Tidak dapat terhubung ke server.', { exact: false }).waitFor(); checks++;
    student.state.fail = { path: '/reports', status: 429, code: 'RATE_LIMITED' };
    await go(page, '/reports'); await page.getByText('Terlalu banyak permintaan.', { exact: false }).waitFor(); checks++;
    student.state.fail = { path: '/notifications', status: 401, code: 'UNAUTHENTICATED' };
    await go(page, '/notifications');
    await page.getByRole('button', { name: 'Masuk', exact: true }).waitFor();
    assert.equal(await page.evaluate(() => sessionStorage.getItem('rd.token')), null);
    assert.equal(await page.locator('.sidebar').count(), 0); checks++;

    for (const role of ['class_leader', 'lecturer_coordinator', 'super_admin', 'identity_verifier']) {
        const session = await setup(role); await go(session.page, '/dashboard'); await layout(session.page, `${role} dashboard`);
        const paths = role === 'super_admin' ? ['/users', '/classes', '/security', '/audit'] : role === 'identity_verifier' ? ['/verifications'] : ['/reports/PGD-TEST001', '/classes', '/security', '/aspirations/7?tab=flags&status=open'];
        for (const path of paths) {
            await go(session.page, path); await layout(session.page, `${role} ${path}`);
            await session.page.setViewportSize({ width: 320, height: 844 }); await layout(session.page, `${role} mobile ${path}`);
            await session.page.setViewportSize({ width: 1440, height: 1000 });
        }
        if (role === 'super_admin') {
            await go(session.page, '/reports');
            assert.equal(session.state.requests.some((r) => r === 'GET /reports'), false, 'admin UI never requests private reports');
            assert.equal(await session.page.getByRole('heading', { name: 'Uraian pengaduan' }).count(), 0); checks++;
        }
        if (role === 'lecturer_coordinator') {
            session.state.metadataOnly = true; await go(session.page, '/reports/PGD-TEST001');
            assert.equal(await session.page.getByRole('heading', { name: 'Uraian pengaduan' }).count(), 0);
            assert.equal(await session.page.getByText('Nadia Putri', { exact: true }).count(), 0);
            assert.equal(await session.page.locator('[data-status]').count(), 0); checks++;
        }
        assert.deepEqual(session.state.pageErrors, [], `${role}: browser exceptions`);
        assert.deepEqual(session.state.consoleErrors, [], `${role}: console errors`);
    }
    assert.deepEqual(student.state.pageErrors, []);
    assert.deepEqual(publicSession.state.pageErrors, []);
    assert.deepEqual(student.state.consoleErrors, []);
    const pending = await setup();
    pending.state.user.identity_status = 'pending_verification';
    await go(pending.page, '/account');
    assert.equal(await pending.page.locator('#identity-form').count(), 0);
    assert.equal(await pending.page.locator('input[name=invitation_code]').count(), 0);
    assert.equal(await pending.page.locator('.sidebar a[href="#/aspirations"]').count(), 0);
    await go(pending.page, '/reports/new');
    assert.equal(await pending.page.locator('[data-create-report]').count(), 0); checks++;
    const live = await browser.newContext(); contexts.push(live);
    const livePage = await live.newPage(); const liveErrors = [];
    livePage.on('pageerror', (error) => liveErrors.push(error.message));
    const liveResponse = await livePage.goto(`${base}/#/login`);
    assert.equal(liveResponse.status(), 200);
    await livePage.getByRole('button', { name: 'Masuk', exact: true }).waitFor();
    const unauthorized = await live.request.get(`${base}/api/v1/auth/me`);
    assert.equal(unauthorized.status(), 401);
    assert.equal((await unauthorized.json()).error.code, 'UNAUTHENTICATED');
    assert.deepEqual(liveErrors, []); checks++;
    console.log(JSON.stringify({ result: 'passed', checks, browser: 'Chromium', api: 'contract fixtures (not live data)', viewports: [320, 390, 768, 1440], pageErrors: [] }));
} finally {
    for (const context of contexts) await context.close();
    await browser.close();
}
