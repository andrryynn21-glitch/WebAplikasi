import test from 'node:test';
import assert from 'node:assert/strict';
import { createApi, ApiError, createVoiceManager } from '../../resources/js/api.js';
import { esc, navigation, badge, field, textarea, selectField, pagination, dataTable, age } from '../../resources/js/ui.js';

const response = (data, status = 200, headers = {}) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json', ...headers } });
test('API uses current bearer token, JSON envelope, no cookies and no cache', async () => {
    let request;
    const api = createApi({ token: () => 'test-token', fetcher: async (...args) => { request = args; return response({ data: { status: 'submitted' } }); } });
    const result = await api('/reports', { method: 'POST', body: { title: 'Example' } });
    assert.equal(result.data.status, 'submitted');
    assert.equal(request[0], '/api/v1/reports');
    assert.equal(request[1].headers.Authorization, 'Bearer test-token');
    assert.equal(request[1].headers['Content-Type'], 'application/json');
    assert.equal(request[1].credentials, 'omit');
    assert.equal(request[1].cache, 'no-store');
    assert.equal(request[1].redirect, 'error');
    assert.deepEqual(JSON.parse(request[1].body), { title: 'Example' });
});
test('explicit credentials mode applies to JSON and private blobs without changing bearer or transport guards', async () => {
    for (const credentials of ['omit', 'same-origin']) {
        let calls = 0;
        const api = createApi({ credentials, token: () => 'test-token', fetcher: async (url, options) => {
            calls++;
            assert.equal(options.credentials, credentials);
            assert.equal(options.headers.Authorization, 'Bearer test-token');
            assert.equal(options.cache, 'no-store');
            assert.equal(options.redirect, 'error');
            assert.ok(!url.includes('test-token'));
            return url.endsWith('/attachments/1') ? new Response('private content') : response({ data: {} });
        } });
        await api('/reports');
        assert.equal(await (await api('/attachments/1', { binary: true })).text(), 'private content');
        assert.equal(calls, 2);
    }
});
test('same-origin public requests do not invent an authorization header', async () => {
    const api = createApi({ credentials: 'same-origin', fetcher: async (url, options) => {
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers.Authorization, undefined);
        return response({ data: { enabled: false } });
    } });
    assert.equal((await api('/security/voice-warnings')).data.enabled, false);
});
test('include and invalid credentials modes are refused before token access or fetch', () => {
    let calls = 0;
    for (const credentials of ['include', '', 'Same-Origin', 'same-origin ', null, false, 0, {}, ['same-origin']]) {
        assert.throws(() => createApi({ credentials, token: () => { calls++; }, fetcher: () => { calls++; } }), /Invalid API credentials/);
    }
    assert.equal(calls, 0);
});
test('multipart preserves FormData without forcing Content-Type', async () => {
    const body = new FormData(); body.append('title', 'Report');
    const api = createApi({ fetcher: async (url, options) => { assert.equal(options.body, body); assert.equal(options.headers['Content-Type'], undefined); return response({ data: {} }); } });
    await api('/reports', { method: 'POST', body });
});
test('422 validation errors retain safe field messages', async () => {
    const api = createApi({ fetcher: async () => response({ message: 'Data tidak valid', error: { code: 'VALIDATION_FAILED', details: { title: ['Judul wajib diisi'] } } }, 422) });
    await assert.rejects(api('/reports'), (error) => error.status === 422 && error.details.title[0] === 'Judul wajib diisi');
});
test('401 is propagated to centralized session handler', async () => {
    let received;
    const api = createApi({ onError: (error, path) => { received = [error.status, path]; }, fetcher: async () => response({ error: { code: 'UNAUTHENTICATED' } }, 401) });
    await assert.rejects(api('/auth/me'), ApiError);
    assert.deepEqual(received, [401, '/auth/me']);
});
test('500 and HTML errors never reveal server internals', async () => {
    const api = createApi({ fetcher: async () => response({ message: 'SQL query /secret stacktrace' }, 500) });
    await assert.rejects(api('/reports'), (e) => !e.message.includes('SQL') && e.status === 500);
    const html = createApi({ fetcher: async () => new Response('<h1>Fatal trace</h1>', { status: 502 }) });
    await assert.rejects(html('/reports'), (e) => !e.message.includes('trace'));
});
test('429 exposes retry-after and does not pretend success', async () => {
    const api = createApi({ fetcher: async () => response({ error: { code: 'RATE_LIMITED' } }, 429, { 'Retry-After': '30' }) });
    await assert.rejects(api('/reports'), (e) => e.retryAfter === 30 && e.message.includes('30 detik'));
});
test('network failures and cancelled navigation remain distinct', async () => {
    const api = createApi({ fetcher: async () => { throw new TypeError('secret internal network value'); } });
    await assert.rejects(api('/reports'), (e) => e.code === 'NETWORK_ERROR' && !e.message.includes('secret'));
    const abort = createApi({ fetcher: async () => { throw new DOMException('Aborted', 'AbortError'); } });
    await assert.rejects(abort('/reports'), (e) => e.name === 'AbortError');
});
test('private downloads require successful response and return a Blob', async () => {
    const api = createApi({ fetcher: async (url, options) => { assert.equal(options.credentials, 'omit'); return new Response('private content', { status: 200 }); } });
    assert.equal(await (await api('/attachments/1', { binary: true })).text(), 'private content');
    const denied = createApi({ fetcher: async () => response({ error: { code: 'FORBIDDEN' } }, 403) });
    await assert.rejects(denied('/attachments/1', { binary: true }), (e) => e.status === 403);
});
test('client rejects external or traversing API destinations', async () => {
    for (const credentials of ['omit', 'same-origin']) {
        let calls = 0;
        const api = createApi({ credentials, token: () => { calls++; }, fetcher: () => { calls++; } });
        for (const path of ['https://evil.test', '//evil.test', '/../secret']) await assert.rejects(api(path), /Invalid API path/);
        assert.equal(calls, 0);
    }
});
test('an aborted old response cannot invalidate a new session', async () => {
    const controller = new AbortController(); let denied = false;
    const api = createApi({ onError: () => { denied = true; }, fetcher: async () => { controller.abort(); return response({}, 401); } });
    await assert.rejects(api('/auth/me', { signal: controller.signal }), (e) => e.name === 'AbortError');
    assert.equal(denied, false);
});
test('escaping and badges never interpolate executable user HTML', () => {
    assert.equal(esc('<img onerror="x">&'), '&lt;img onerror=&quot;x&quot;&gt;&amp;');
    assert.ok(!badge('bad" onclick="x', '<script>').includes('<script>'));
    assert.ok(!field('title', '<img>', { value: '"><script>' }).includes('<script>'));
});
test('repeated fields have distinct label IDs', () => {
    const html = field('reason', 'Reason') + textarea('reason', 'Other') + selectField('reason', 'Third', []);
    const ids = [...html.matchAll(/id="([^"]+)"/g)].map((m) => m[1]);
    assert.equal(new Set(ids).size, 3);
    for (const id of ids) assert.ok(html.includes(`for="${id}"`));
});
test('role navigation does not give superadmin private report or KTM views', () => {
    const admin = navigation({ role: 'super_admin', permissions: ['users.manage', 'roles.manage', 'classes.manage', 'audit.view', 'security.view'] });
    assert.ok(admin.some((l) => l.path === '/users'));
    assert.ok(!admin.some((l) => ['/reports', '/aspirations', '/verifications'].includes(l.path)));
    const pending = navigation({ role: 'student', identity_status: 'pending_verification', membership: { status: 'active' }, permissions: ['reports.view.own', 'aspirations.participate'] });
    assert.ok(!pending.some((l) => l.path === '/aspirations'));
    assert.ok(pending.some((l) => l.path === '/reports'));
});
test('pagination retains filters and does not fabricate pages', () => {
    const html = pagination({ current_page: 2, last_page: 3, total: 45 }, '/reports', new URLSearchParams('status=resolved'));
    assert.ok(html.includes('status=resolved&amp;page=1'));
    assert.ok(html.includes('status=resolved&amp;page=3'));
    assert.equal(pagination({ last_page: 1 }, '/reports'), '');
});
test('data tables escape plain values and label their headers', () => {
    const html = dataTable([{ key: 'name', label: 'Nama' }], [{ name: '<script>unsafe</script>' }], 'Daftar');
    assert.ok(html.includes('<th scope="col">Nama</th>'));
    assert.ok(!html.includes('<script>'));
    assert.equal(age('invalid'), 'Usia tidak tersedia');
    assert.equal(age(new Date().toISOString()), 'Kurang dari 1 jam');
});
test('voice is opt-in and never plays for ordinary success events', async () => {
    let calls = 0;
    const manager = createVoiceManager({ audioFactory: () => { calls++; return { play: async () => {} }; } });
    manager.setCatalogue({ enabled: true, sounds: [{ key: 'access_denied', available: true, file: '/audio/denied.mp3' }] });
    const denial = { status: 403, voice: { play: true, category: 'access_denied', sound: '/audio/denied.mp3' } };
    await manager.handle(denial); assert.equal(calls, 0);
    manager.setEnabled(true);
    await manager.handle({ ...denial, status: 200 }); assert.equal(calls, 0);
    assert.equal(await manager.handle(denial), true); assert.equal(calls, 1);
    await manager.handle(denial); assert.equal(calls, 1);
});
test('missing or rejected audio is nonfatal and never invented', async () => {
    let calls = 0; const notes = [];
    const manager = createVoiceManager({ notify: (m) => notes.push(m), audioFactory: () => { calls++; return { play: async () => { throw new Error('autoplay blocked'); } }; } });
    manager.setEnabled(true); manager.setCatalogue({ enabled: true, sounds: [] });
    assert.equal(await manager.handle({ status: 403, voice: { play: true, category: 'access_denied', placeholder: true } }), false);
    assert.equal(calls, 0); assert.equal(notes.length, 1);
    const fail = createVoiceManager({ audioFactory: () => ({ play: async () => { throw new Error('blocked'); } }) });
    fail.setEnabled(true); fail.setCatalogue({ enabled: true, sounds: [{ key: 'access_denied', available: true, file: '/audio/denied.mp3' }] });
    assert.equal(await fail.handle({ status: 403, voice: { play: true, category: 'access_denied', sound: '/audio/denied.mp3' } }), false);
});
