import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { createApi, createVoiceManager } from '../../resources/js/api.js';
import * as ui from '../../resources/js/ui.js';
import { accountPage } from '../../resources/js/pages/account.js';
import { reportsPage } from '../../resources/js/pages/reports.js';

const response = (data, status = 200, headers = {}) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json', ...headers } });
const aborted = (error) => error.name === 'AbortError';

test('cancel during JSON parsing suppresses old session errors and success data', async () => {
    for (const status of [200, 401, 403, 429]) {
        const controller = new AbortController();
        let errors = 0;
        const api = createApi({ onError: () => errors++, fetcher: async () => ({
            ok: status === 200, status, headers: new Headers({ 'Content-Type': 'application/json' }),
            json: async () => { controller.abort(); return { data: {}, error: { code: 'UNAUTHENTICATED' } }; },
        }) });
        await assert.rejects(api('/auth/me', { signal: controller.signal }), aborted);
        assert.equal(errors, 0);
    }
});

test('AbortError while reading JSON is not rewritten as HTTP or envelope failure', async () => {
    for (const status of [200, 401]) {
        let errors = 0;
        const api = createApi({ onError: () => errors++, fetcher: async () => ({
            ok: status === 200, status, headers: new Headers({ 'Content-Type': 'application/json' }),
            json: async () => { throw new DOMException('Aborted', 'AbortError'); },
        }) });
        await assert.rejects(api('/auth/me'), aborted);
        assert.equal(errors, 0);
    }
});

test('a replaced bearer session cannot receive old errors or private response data', async () => {
    for (const status of [200, 401, 403]) {
        let token = 'old-session';
        let errors = 0;
        const api = createApi({ token: () => token, onError: () => errors++, fetcher: async () => ({
            ok: status === 200, status, headers: new Headers({ 'Content-Type': 'application/json' }),
            json: async () => { token = 'new-session'; return { data: { private: 'old session' } }; },
        }) });
        await assert.rejects(api('/reports/PGD-TEST', {}), aborted);
        assert.equal(errors, 0);
    }
});

test('cancelled binary body is not returned for download', async () => {
    const controller = new AbortController();
    const api = createApi({ fetcher: async () => ({
        ok: true, headers: new Headers({ 'Content-Type': 'text/plain' }),
        blob: async () => { controller.abort(); return new Blob(['private']); },
    }) });
    await assert.rejects(api('/attachments/1', { signal: controller.signal, binary: true }), aborted);
});

test('API rejects encoded traversal, encoded separators, backslashes and control characters before fetch', async () => {
    let requests = 0;
    const api = createApi({ fetcher: async () => { requests++; return response({ data: null }); } });
    for (const path of ['/reports/%2e%2e/auth/me', '/%2e%2e/%2e%2e/outside', '/reports/%2fadmin', '/reports/%252e%252e', '/reports\\..\\auth/me', '/reports/./1', '/reports/1#other', '/reports/\n1']) {
        await assert.rejects(api(path), /Invalid API path/, path);
    }
    assert.equal(requests, 0);
});

test('legitimate query text containing dots, percent signs and URLs stays query data', async () => {
    const path = `/admin/users?${new URLSearchParams({ search: 'a..b 100% https://example.test/name' })}`;
    const api = createApi({ fetcher: async (url) => { assert.equal(url, `/api/v1${path}`); return response({ data: [] }); } });
    await api(path);
});

test('API disallows redirects for credentials and private payloads', async () => {
    for (const credentials of ['omit', 'same-origin']) {
        let request;
        const api = createApi({ credentials, fetcher: async (url, options) => {
            request = options;
            throw new TypeError('redirect blocked: sensitive destination');
        } });
        await assert.rejects(api('/auth/login', { method: 'POST', body: { password: 'synthetic-password' } }),
            (error) => error.code === 'NETWORK_ERROR' && !error.message.includes('sensitive'));
        assert.equal(request.redirect, 'error');
    }
});

test('API validates configured origin but supports same-origin deployment prefixes', async (t) => {
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'location');
    Object.defineProperty(globalThis, 'location', { configurable: true, value: { origin: 'https://app.example.test' } });
    t.after(() => { if (previous) Object.defineProperty(globalThis, 'location', previous); else delete globalThis.location; });
    for (const credentials of ['omit', 'same-origin']) {
        let requests = 0; let tokens = 0;
        for (const base of ['https://other.example.test/api/v1', '//other.example.test/api/v1', '/\\other.example.test/api/v1', 'https://user:password@app.example.test/api/v1', '/api/v1?next=', '/api/../v1']) {
            const api = createApi({ base, credentials, token: () => { tokens++; return 'test-token'; }, fetcher: async () => { requests++; return response({ data: null }); } });
            await assert.rejects(async () => api('/auth/me'), /Invalid API base/, base);
        }
        assert.equal(requests, 0);
        assert.equal(tokens, 0);
        for (const base of ['/api/v1', '/WebAplikasi/public/api/v1', 'https://app.example.test/WebAplikasi/public/api/v1']) {
            await createApi({ base, credentials, fetcher: async (url) => { assert.equal(url, `${base}/auth/me`); return response({ data: {} }); } })('/auth/me');
        }
    }
});

test('success JSON requires JSON content type and a non-array envelope', async () => {
    for (const contentType of ['text/html', 'text/plain', '']) {
        const api = createApi({ fetcher: async () => response({ data: { token: 'untrusted' } }, 200, { 'Content-Type': contentType }) });
        await assert.rejects(api('/auth/login'), (error) => error.code === 'INVALID_RESPONSE');
    }
    const api = createApi({ fetcher: async () => response({ data: null }, 200, { 'Content-Type': 'application/json; charset=UTF-8' }) });
    assert.equal((await api('/auth/logout', { method: 'POST' })).data, null);
});

test('HTML-typed errors cannot supply trusted messages or voice instructions', async () => {
    const api = createApi({ fetcher: async () => response({ message: 'sensitive proxy diagnostic', meta: { voice_warning: { play: true } } }, 403, { 'Content-Type': 'text/html' }) });
    await assert.rejects(api('/reports'), (error) => !error.message.includes('sensitive') && !error.voice);
});

test('binary download rejects JSON envelopes and HTML fallback pages', async () => {
    for (const type of ['application/json', 'text/html; charset=utf-8']) {
        const api = createApi({ fetcher: async () => new Response('not a private file', { headers: { 'Content-Type': type } }) });
        await assert.rejects(api('/attachments/1', { binary: true }), (error) => error.code === 'INVALID_RESPONSE');
    }
});

test('invalid retry-after headers fall back to valid cooldown metadata', async () => {
    for (const retry of ['NaN', '-10', 'Infinity']) {
        const api = createApi({ fetcher: async () => response({ error: { code: 'SECURITY_COOLDOWN' }, meta: { retry_after: 45 } }, 429, { 'Retry-After': retry }) });
        await assert.rejects(api('/auth/me'), (error) => error.retryAfter === 45 && error.message.includes('45 detik'));
    }
});

test('security audio accepts only actual boolean authorization flags', async () => {
    for (const flag of ['enabled', 'available', 'play']) {
        let calls = 0;
        const manager = createVoiceManager({ audioFactory: () => { calls++; return { play: async () => {} }; } });
        manager.setEnabled(true);
        manager.setCatalogue({ enabled: flag === 'enabled' ? 'false' : true, sounds: [{ key: 'access_denied', available: flag === 'available' ? 'false' : true, file: '/audio/denied.mp3' }] });
        await manager.handle({ status: 403, voice: { play: flag === 'play' ? 'false' : true, sound: '/audio/denied.mp3', category: 'access_denied' } });
        assert.equal(calls, 0, flag);
    }
});

test('security audio cannot escape same-origin using a browser-normalized backslash path', async () => {
    const sound = '/\\outside.example.test/denied.mp3';
    assert.equal(new URL(sound, 'https://app.example.test').origin, 'https://outside.example.test');
    let calls = 0;
    const manager = createVoiceManager({ audioFactory: () => { calls++; return { play: async () => {} }; } });
    manager.setEnabled(true);
    manager.setCatalogue({ enabled: true, sounds: [{ key: 'access_denied', available: true, file: sound }] });
    await manager.handle({ status: 403, voice: { play: true, sound, category: 'access_denied' } });
    assert.equal(calls, 0);
});

// Exercise the actual app callbacks without a browser dependency or a live backend.
class Element {
    constructor() {
        this.nodes = new Map(); this.events = {}; this.attributes = {}; this.isConnected = true;
        this.innerHTML = ''; this.disabled = false; this.open = false;
        this.classList = { toggle() {}, contains: () => false };
        this.dataset = { apiBase: '/api/v1' };
    }
    querySelector(selector) { if (!this.nodes.has(selector)) this.nodes.set(selector, new Element()); return this.nodes.get(selector); }
    querySelectorAll() { return []; }
    addEventListener(name, handler) { this.events[name] = handler; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    matches() { return false; }
    close() { this.open = false; }
    showModal() { this.open = true; }
    append() {}
}

async function appHarness(logout = async () => ({ data: null }), credentials) {
    const root = new Element(); const dialog = new Element(); const storage = new Map([['rd.token', 'old-session']]);
    if (credentials !== undefined) root.dataset.apiCredentials = credentials;
    const events = {}; const requests = []; let onError; let apiOptions;
    const never = new Promise(() => {});
    const location = { hash: '#/dashboard' };
    const scope = vm.createContext({
        ...ui, createVoiceManager, AbortController, URL, URLSearchParams, Intl, Date, location,
        createApi: (options) => { apiOptions = options; onError = options.onError; return async (path) => {
            requests.push(path);
            if (path === '/auth/logout') return logout();
            if (path === '/security/voice-warnings') return { data: { enabled: false, sounds: [] } };
            return never;
        }; },
        document: { querySelector: (selector) => selector === '#app' ? root : dialog, createElement: () => new Element() },
        sessionStorage: { getItem: (key) => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: (key) => storage.delete(key) },
        window: { addEventListener: (name, handler) => { events[name] = handler; }, matchMedia: (query) => ({ matches: query.includes('prefers-reduced-motion'), addEventListener() {} }) },
        setTimeout() {},
    });
    const source = await readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    vm.runInContext(source.replace(/^import .*;\n/gm, ''), scope);
    vm.runInContext("user = { id: 1, name: 'Synthetic User', role: 'student', permissions: [] }; shell();", scope);
    return {
        root, dialog, storage, events, requests, location, apiOptions,
        state: () => vm.runInContext('({ token, user, sessionNotice })', scope),
        setToken: (value) => { scope.newToken = value; vm.runInContext('token = newToken; store.set(token);', scope); },
        clearUser: () => vm.runInContext('user = null', scope),
        onError: (...args) => onError(...args),
        action: vm.runInContext('action', scope),
        startLogout: () => {
            const button = root.querySelector('[data-logout]');
            const pending = button.events.click({ currentTarget: button });
            dialog.querySelector('[data-confirm]').events.click();
            return pending;
        },
    };
}

test('app bootstrap forwards credentials configuration and leaves a missing attribute at the client default', async () => {
    for (const credentials of [undefined, 'omit', 'same-origin']) {
        const app = await appHarness(undefined, credentials);
        assert.equal(app.apiOptions.credentials, credentials);
        assert.equal(app.apiOptions.base, '/api/v1');
        assert.equal(app.apiOptions.token(), 'old-session');
    }
});

test('failed logout still clears private local state and warns that server revocation is unconfirmed', async () => {
    const app = await appHarness(async () => { throw new Error('synthetic offline failure'); });
    await app.startLogout();
    assert.equal(app.state().token, null);
    assert.equal(app.state().user, null);
    assert.equal(app.storage.has('rd.token'), false);
    assert.equal(app.root.innerHTML, '');
    assert.equal(app.location.hash, '/login');
    assert.match(app.state().sessionNotice, /server.*(belum|masih)/);
});

test('late logout cannot erase a replacement session', async () => {
    let finish;
    const app = await appHarness(() => new Promise((resolve) => { finish = resolve; }));
    const pending = app.startLogout();
    await Promise.resolve();
    assert.equal(typeof finish, 'function');
    app.setToken('replacement-session');
    finish({ data: null });
    await pending;
    assert.equal(app.state().token, 'replacement-session');
    assert.equal(app.storage.get('rd.token'), 'replacement-session');
    assert.notEqual(app.location.hash, '/login');
});

test('back-forward cache restoration revalidates the server session before private rendering', async () => {
    const app = await appHarness();
    const before = app.requests.filter((path) => path === '/auth/me').length;
    app.storage.set('rd.token', 'new-document-session');
    app.events.pageshow({ persisted: true });
    assert.equal(app.requests.filter((path) => path === '/auth/me').length, before + 1);
    assert.equal(app.state().user, null);
    assert.equal(app.state().token, 'new-document-session');
});

test('a disabled account clears its restored token even before the user profile is loaded', async () => {
    const app = await appHarness();
    app.clearUser();
    app.onError({ status: 403, code: 'ACCOUNT_DISABLED', message: 'Disabled account' }, '/auth/me');
    assert.equal(app.state().token, null);
    assert.equal(app.storage.has('rd.token'), false);
    assert.equal(app.location.hash, '/login');
});

test('the same in-flight action cannot be submitted twice and controls recover after failure', async () => {
    const app = await appHarness();
    const button = new Element();
    let reject; let calls = 0;
    const callback = () => { calls++; return new Promise((resolve, fail) => { reject = fail; }); };
    const first = app.action(button, callback);
    await app.action(button, callback);
    assert.equal(calls, 1);
    assert.equal(button.disabled, true);
    reject(new Error('Synthetic failure'));
    await first;
    assert.equal(button.disabled, false);
    assert.equal(button.attributes['aria-busy'], undefined);
});

async function accountHarness(role, identityStatus, submission = {}) {
    let html; let submit; const requests = [];
    const profile = { reportValidity: () => true };
    const ctx = {
        user: { id: 1, name: 'Reviewed Name', role, email: 'synthetic@example.test' },
        has: (permission) => permission === 'identity.submit',
        view: { isConnected: true, querySelector: (selector) => selector === '#profile-form' ? profile : null },
        api: async (path, options) => {
            requests.push([path, options]);
            return { data: path === '/identity/status' ? { identity_status: identityStatus, ...submission } : [] };
        },
        page: (title, description, content) => { html = content; },
        form: (form, handler) => { if (form === profile) submit = handler; },
        refreshUser: async () => {}, toast() {}, refresh() {},
    };
    await accountPage(ctx, ['account']);
    return { html, submit: () => submit(profile), requests };
}

test('reviewed or privileged names are readonly, while unreviewed student names stay editable', async () => {
    for (const [role, status, locked] of [
        ['student', 'verified', true], ['student', 'pending_verification', true],
        ['class_leader', 'unverified', true], ['lecturer_coordinator', 'unverified', true],
        ['student', 'unverified', false], ['student', 'rejected', false],
    ]) {
        const { html } = await accountHarness(role, status);
        const input = html.match(/<input [^>]*name="name"[^>]*>/)?.[0];
        assert.ok(input);
        assert.equal(/\breadonly\b/.test(input), locked, `${role}/${status}`);
    }
});

test('phone-only profile edits omit the locked name instead of resubmitting stale identity data', async (t) => {
    const original = globalThis.FormData;
    globalThis.FormData = class { entries() { return [['name', 'Stale Name'], ['phone', ' 081234567890 ']][Symbol.iterator](); } };
    t.after(() => { globalThis.FormData = original; });
    for (const [status, submission] of [
        ['verified', {}],
        ['pending_verification', {}],
        ['pending_verification', { pending_submission_id: 1, latest_submission: { id: 1, status: 'pending_verification' } }],
        ['verified', { pending_submission_id: 2, latest_submission: { id: 2, status: 'pending_verification' } }],
        ['verified', { latest_submission: { id: 2, status: 'rejected' } }],
    ]) {
        const account = await accountHarness('student', status, submission);
        const name = account.html.match(/<input [^>]*name="name"[^>]*>/)?.[0];
        assert.match(name, /\breadonly\b/);
        await account.submit();
        const update = account.requests.find(([path]) => path === '/profile');
        assert.deepEqual(update[1], { method: 'PATCH', body: { phone: '081234567890' } }, status);
    }
});

test('pending identity states hide duplicate submissions while rejected corrections remain resubmittable', async () => {
    for (const [status, submission, canSubmit] of [
        ['pending_verification', {}, false],
        ['pending_verification', { pending_submission_id: 1, latest_submission: { id: 1, status: 'pending_verification' } }, false],
        ['verified', { pending_submission_id: 2 }, false],
        ['verified', { latest_submission: { id: 2, status: 'pending_verification' } }, false],
        ['verified', { latest_submission: { id: 2, status: 'rejected' } }, true],
        ['unverified', {}, true],
    ]) {
        const { html } = await accountHarness('student', status, submission);
        assert.equal(html.includes('id="identity-form"'), canSubmit, `${status}/${JSON.stringify(submission)}`);
    }
});

async function reportHarness(report, updateForm = null) {
    let html; let submit; const requests = [];
    const ctx = {
        user: { id: 20 }, has: () => true, canClass: () => true,
        view: { isConnected: true, querySelector: (selector) => selector === '[data-update]' ? updateForm : null, querySelectorAll: () => [] },
        api: async (path, options) => { requests.push([path, options]); return { data: report }; },
        page: (title, description, content) => { html = content; },
        form: (form, handler) => { if (form === updateForm) submit = handler; },
        toast() {}, refresh() {},
    };
    await reportsPage(ctx, ['reports', 'PGD-TEST'], new URLSearchParams());
    return { html, submit: () => submit(updateForm), requests };
}

async function createReportHarness(sizes, maxTotalBytes = 26214400) {
    let html; let submit; const requests = [];
    const inputs = Object.fromEntries(Object.entries({
        category_id: '1', title: 'Synthetic report', description: 'Synthetic report description for upload limit testing.',
        desired_outcome: '', involved_parties: '', attachments: '',
    }).map(([name, value]) => [name, {
        value, validityMessage: '', events: {},
        setCustomValidity(message) { this.validityMessage = message; },
        addEventListener(event, handler) { this.events[event] = handler; },
    }]));
    inputs.attachments.files = sizes.map((size) => {
        const file = new File(['synthetic'], 'synthetic.png', { type: 'image/png' });
        Object.defineProperty(file, 'size', { value: size });
        return file;
    });
    const form = {
        elements: { namedItem: (name) => inputs[name] },
        reportValidity: () => Object.values(inputs).every((input) => input.validityMessage === ''),
    };
    const ctx = {
        has: () => true, canClass: () => true,
        view: { isConnected: true, querySelector: () => form },
        api: async (path, options) => {
            requests.push([path, options]);
            return path === '/report-categories'
                ? { data: [{ id: 1, name: 'Facilities' }], meta: { uploads: { max_files: 5, max_file_bytes: 8388608, max_total_bytes: maxTotalBytes } } }
                : { data: { public_ref: 'PGD-SYNTHETIC' } };
        },
        page: (title, description, content) => { html = content; },
        form: (element, handler) => { submit = handler; },
        toast() {}, navigate() {},
    };
    await reportsPage(ctx, ['reports', 'new'], new URLSearchParams());
    return { html, inputs, submit: () => submit(form), requests };
}

test('report creation displays server-provided total upload limit', async () => {
    for (const [limit, label] of [[26214400, '25'], [16777216, '16']]) {
        const report = await createReportHarness([], limit);
        assert.ok(report.html.includes(`Maksimal 5 file, masing-masing 8 MiB, total ${label} MiB.`));
    }
});

test('report upload preflight blocks aggregate overflow before POST and preserves per-file and count limits', async () => {
    for (const [sizes, limit] of [
        [[8388608, 8388608, 8388608, 1048577], 26214400],
        [[7340032, 7340032, 7340032, 7340032], 26214400],
        [[6291456, 6291456, 6291456], 16777216],
        [[8388609], 26214400],
        [[1024, 1024, 1024, 1024, 1024, 1024], 26214400],
    ]) {
        const report = await createReportHarness(sizes, limit);
        await report.submit();
        assert.equal(report.requests.filter(([, options]) => options?.method === 'POST').length, 0);
        assert.match(report.inputs.attachments.validityMessage, /Maksimal 5 file.*8 MiB, total/);
    }
});

test('report upload preflight allows exact boundary, normal small files and no attachments', async () => {
    for (const sizes of [[8388608, 8388608, 8388608, 1048576], [8388608, 8388608, 8388608], [1024, 1024, 1024, 1024, 1024], []]) {
        const report = await createReportHarness(sizes);
        await report.submit();
        const posts = report.requests.filter(([, options]) => options?.method === 'POST');
        assert.equal(posts.length, 1);
        assert.equal(posts[0][0], '/reports');
        assert.equal(posts[0][1].body.getAll('attachments[]').length, sizes.length);
        assert.equal(report.inputs.attachments.validityMessage, '');
    }
});

test('correcting report file selection clears its validation error and allows resubmission', async () => {
    const report = await createReportHarness([7340032, 7340032, 7340032, 7340032]);
    await report.submit();
    const input = report.inputs.attachments;
    assert.notEqual(input.validityMessage, '');
    input.files = input.files.slice(0, 3);
    input.events.change({ target: input });
    assert.equal(input.validityMessage, '');
    await report.submit();
    assert.equal(report.requests.filter(([, options]) => options?.method === 'POST').length, 1);
});

test('host HTML 413 response preserves HTTP status without leaking upstream diagnostics', async () => {
    const api = createApi({ fetcher: async () => new Response('<html>private proxy diagnostic</html>', { status: 413, headers: { 'Content-Type': 'text/html' } }) });
    await assert.rejects(api('/reports', { method: 'POST', body: new FormData() }), (error) =>
        error.status === 413 && error.code === 'HTTP_ERROR' && error.message === 'Permintaan tidak dapat diproses.');
});

const privateReport = {
    public_ref: 'PGD-TEST', title: 'Synthetic private report', status: 'under_review',
    private_details: { description: 'Private body' }, reporter: null,
    assignments: [{ type: 'primary', assignee: { id: 20 }, revoked_at: null }],
    updates: [{ id: 1, type: 'update', body: 'Public update', is_internal: false }, { id: 2, type: 'internal_note', body: 'INTERNAL-CANARY', is_internal: true }],
    timeline: [], attachments: [],
};

test('report summary, requesting owner and appeal-only reviewer preserve private action boundaries', async () => {
    const summary = await reportHarness({ public_ref: 'PGD-TEST', private_details: null, can_read_private: false, title: null });
    assert.ok(!summary.html.includes('Uraian pengaduan'));
    const owner = await reportHarness(privateReport);
    assert.ok(owner.html.includes('Private body'));
    assert.ok(!owner.html.includes('INTERNAL-CANARY'));
    assert.ok(!owner.html.includes('data-status'));
    assert.ok(!owner.html.includes('name="internal"'));
    const reviewer = await reportHarness({ ...privateReport, reporter: { id: 10 }, assignments: [{ type: 'appeal_reviewer', assignee: { id: 20 }, revoked_at: null }] });
    assert.ok(!reviewer.html.includes('data-status'));
});

test('report update visibility is sent as a JSON boolean, never the truthy string false', async (t) => {
    const original = globalThis.FormData;
    t.after(() => { globalThis.FormData = original; });
    for (const [owner, selected, expected] of [[true, 'true', false], [false, 'false', false], [false, 'true', true]]) {
        globalThis.FormData = class { entries() { return [['body', 'Synthetic update'], ['internal', selected]][Symbol.iterator](); } };
        const report = await reportHarness({ ...privateReport, reporter: owner ? null : { id: 10 } }, {});
        await report.submit();
        const update = report.requests.find(([, options]) => options?.method === 'POST');
        assert.equal(update[0], '/reports/PGD-TEST/updates');
        assert.equal(update[1].body.internal, expected);
        assert.equal(typeof update[1].body.internal, 'boolean');
    }
});

test('clarification requests are offered only in the two backend-authorized states', async () => {
    for (const status of ['submitted', 'needs_review', 'under_review', 'needs_clarification', 'in_progress', 'waiting_follow_up', 'resolution_proposed', 'forwarded', 'resolved', 'rejected', 'appeal_pending', 'reopened']) {
        const { html } = await reportHarness({ ...privateReport, reporter: { id: 10 }, status });
        assert.equal(html.includes('data-clarification-request'), ['under_review', 'needs_clarification'].includes(status), status);
    }
});

test('ordinary update replies do not hide unanswered clarification requests', async () => {
    const question = { id: 10, type: 'clarification_request', body: 'Provide additional information', is_internal: false };
    for (const type of ['update', 'clarification_response']) {
        const { html } = await reportHarness({ ...privateReport, status: 'needs_clarification', updates: [question, { id: 11, type, body: 'Synthetic reply', is_internal: false, reply_to_id: 10 }] });
        assert.equal(html.includes('name="reply_to_id"'), type === 'update');
    }
});

test('credential UI submits current password only to the authenticated self-service endpoint', async () => {
    const source = await readFile(new URL('../../resources/js/pages/account.js', import.meta.url), 'utf8');
    assert.match(source, /field\(['"]current_password['"]/);
    assert.match(source, /\/auth\/password\/change/);
    assert.doesNotMatch(source, /password[^\n]+\/admin\/users/);
});

test('public recovery UI sends reset token in request body and uses the generic forgot endpoint', async () => {
    const source = await readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    assert.match(source, /\/auth\/password\/forgot/);
    assert.match(source, /\/auth\/password\/reset/);
    assert.match(source, /token: params\.get\('token'\)/);
    assert.doesNotMatch(source, /api\([^\n]+\?token=/);
});

test('official institutional logo replaces the old RD monogram while keeping the wordmark', async () => {
    const source = await readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const blade = await readFile(new URL('../../resources/views/app.blade.php', import.meta.url), 'utf8');
    assert.match(source, /src=["']\/images\/logo-unpam\.png["']/);
    assert.match(source, /alt=["']Logo Universitas Pamulang["']/);
    assert.match(source, /brand-copy["']>ruang dengar/);
    assert.doesNotMatch(source, /brand-symbol|>rd\.</);
    assert.match(blade, /images\/logo-unpam\.png/);
    assert.doesNotMatch(blade, /brand-symbol|>rd\.</);
    assert.doesNotMatch(source, /src=["']https?:\/\//);
});
