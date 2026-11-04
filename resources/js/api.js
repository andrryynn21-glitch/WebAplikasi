export class ApiError extends Error {
    constructor(message, { status = 0, code = 'NETWORK_ERROR', details = {}, voice = null, retryAfter = 0 } = {}) {
        super(message);
        this.name = 'ApiError';
        Object.assign(this, { status, code, details, voice, retryAfter });
    }
}

// Endpoint IDs and configured local paths must not change meaning after URL normalization.
const localPath = (value) => typeof value === 'string' && /^\/(?!\/)[A-Za-z0-9._/-]*$/.test(value)
    && !value.includes('//') && !value.split('/').some((part) => part === '.' || part === '..');

export function createApi({ base = '/api/v1', credentials = 'omit', token = () => null, fetcher = (...args) => fetch(...args), onError = () => {} } = {}) {
    if (!['omit', 'same-origin'].includes(credentials)) throw new Error('Invalid API credentials');
    return async function api(path, { method = 'GET', body, signal, binary = false } = {}) {
        if (typeof path !== 'string' || !localPath(path.split('?')[0]) || /[\\#\u0000-\u0020\u007f]/.test(path)) throw new Error('Invalid API path');
        const origin = globalThis.location?.origin;
        let destination;
        try { destination = new URL(base, origin || 'http://localhost'); } catch { throw new Error('Invalid API base'); }
        if (typeof base !== 'string' || !localPath(base.startsWith('/') ? base : base.replace(/^https?:\/\/[^/]+/i, ''))
            || destination.username || destination.password || destination.search || destination.hash
            || (!base.startsWith('/') && (!origin || destination.origin !== origin))) throw new Error('Invalid API base');
        const headers = { Accept: 'application/json' };
        const bearer = token();
        const ensureCurrent = () => { if (signal?.aborted || token() !== bearer) throw new DOMException('Aborted', 'AbortError'); };
        ensureCurrent();
        if (bearer) headers.Authorization = `Bearer ${bearer}`;
        const multipart = typeof FormData !== 'undefined' && body instanceof FormData;
        if (body !== undefined && !multipart) headers['Content-Type'] = 'application/json';
        let response;
        try {
            response = await fetcher(`${base}${path}`, { method, headers, signal, credentials, cache: 'no-store', redirect: 'error', body: body === undefined ? undefined : multipart ? body : JSON.stringify(body) });
        } catch (error) {
            ensureCurrent();
            if (error.name === 'AbortError') throw error;
            throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.');
        }
        ensureCurrent();
        const type = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
        const json = /^application\/(?:[a-z0-9.+-]+\+)?json$/.test(type);
        const invalidResponse = () => new ApiError('Respons server tidak sesuai. Silakan muat ulang halaman.', { code: 'INVALID_RESPONSE' });
        if (binary && response.ok) {
            if (json || type === 'text/html') throw invalidResponse();
            let blob;
            try { blob = await response.blob(); }
            catch (error) {
                ensureCurrent();
                if (error.name === 'AbortError') throw error;
                throw new ApiError('Dokumen belum dapat diunduh. Periksa koneksi lalu coba lagi.');
            }
            ensureCurrent();
            return blob;
        }
        let payload;
        try { payload = json ? await response.json() : null; }
        catch (error) { if (error.name === 'AbortError') throw error; payload = null; }
        ensureCurrent();
        if (!response.ok) {
            const retryAfter = [Number(response.headers.get('Retry-After')), Number(payload?.meta?.retry_after)].find((value) => Number.isFinite(value) && value > 0) || 0;
            const message = response.status >= 500 ? 'Layanan sedang mengalami gangguan. Coba beberapa saat lagi.'
                : response.status === 429 ? `Terlalu banyak permintaan. Coba lagi ${retryAfter > 0 ? `dalam ${Math.ceil(retryAfter)} detik` : 'beberapa saat lagi'}.`
                    : payload?.message || 'Permintaan tidak dapat diproses.';
            const error = new ApiError(message, { status: response.status, code: payload?.error?.code || 'HTTP_ERROR', details: response.status === 422 ? payload?.error?.details || {} : {}, voice: payload?.meta?.voice_warning, retryAfter });
            onError(error, path);
            throw error;
        }
        if (!payload || typeof payload !== 'object' || Array.isArray(payload) || !Object.hasOwn(payload, 'data')) throw invalidResponse();
        return payload;
    };
}

export function createVoiceManager({ audioFactory = (url) => new Audio(url), now = () => Date.now(), notify = () => {} } = {}) {
    let enabled = false;
    let catalogue = null;
    let lastPlayed = -Infinity;
    let current = null;
    return {
        setCatalogue(value) { catalogue = value; },
        setEnabled(value) { enabled = Boolean(value); if (!enabled) { current?.pause?.(); current = null; } },
        async handle(error) {
            const voice = error.voice;
            if (!enabled || catalogue?.enabled !== true || voice?.play !== true || ![403, 429].includes(error.status)) return false;
            if (!['access_denied', 'repeated_denials'].includes(voice.category)) return false;
            const cooldown = Math.max(30, Number(voice.cooldown_seconds) || 0) * 1000;
            if (now() - lastPlayed < cooldown) return false;
            lastPlayed = now();
            const sound = catalogue.sounds?.find((s) => s.key === voice.category && s.available === true && s.file === voice.sound);
            if (!sound?.file || voice.placeholder) { notify('Rekaman peringatan belum tersedia. Peringatan visual tetap aktif.'); return false; }
            // Only an explicitly configured same-origin audio path can be played.
            if (!localPath(sound.file) || !/\.(mp3|wav|ogg)$/i.test(sound.file)) { notify('Lokasi rekaman belum dikonfigurasi untuk browser.'); return false; }
            try { current = audioFactory(sound.file); await current.play(); return true; }
            catch { notify('Audio tidak dapat diputar. Keputusan akses tidak berubah.'); return false; }
        },
    };
}
