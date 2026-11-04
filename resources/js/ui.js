export const esc = (value = '') => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const paths = {
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    report: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
    chat: '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 4 11.5a8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
    shield: '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z"/><path d="m8 12 3 3 5-5"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.9"/><circle cx="9" cy="7" r="4"/><path d="M16 3a4 4 0 0 1 0 8"/>',
    class: '<path d="m2 9 10-6 10 6-10 6zM6 12v6l6 3 6-3v-6M22 9v8"/>',
    arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4M12 14v3"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    logout: '<path d="M9 3H4v18h5M9 12h12m-4-4 4 4-4 4"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    volume: '<path d="m11 5-6 4H2v6h3l6 4zM15 8a6 6 0 0 1 0 8M18 5a10 10 0 0 1 0 14"/>',
    download: '<path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4"/>',
    alert: '<path d="m12 3 10 18H2zM12 9v5M12 17v1"/>',
};
export function icon(name = 'report') {
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.report}</svg>`;
}

export const statusLabels = {
    submitted: 'Terkirim', needs_review: 'Perlu peninjauan', under_review: 'Sedang ditinjau', needs_clarification: 'Butuh klarifikasi', in_progress: 'Dalam penanganan', waiting_follow_up: 'Menunggu tindak lanjut', resolution_proposed: 'Usulan penyelesaian', forwarded: 'Dialihkan', rejected: 'Ditolak', resolved: 'Selesai', appeal_pending: 'Menunggu banding', reopened: 'Dibuka kembali',
    unverified: 'Belum diverifikasi', pending_verification: 'Menunggu verifikasi', verified: 'Terverifikasi', pending: 'Menunggu persetujuan', active: 'Aktif', revoked: 'Dicabut', published: 'Terbit', locked: 'Dikunci', hidden: 'Disembunyikan', removed: 'Dihapus dari tampilan', accepted: 'Diterima', open: 'Terbuka', dismissed: 'Ditutup', low: 'Rendah', medium: 'Sedang', high: 'Tinggi', critical: 'Kritis',
};
export function badge(status, label) {
    const safe = String(status ?? '').replace(/[^a-z_]/g, '');
    return `<span class="badge status-${safe}"><span class="badge-dot"></span>${esc(label || statusLabels[status] || status || 'Belum tersedia')}</span>`;
}
export function date(value) {
    if (!value) return 'Belum tersedia';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? 'Tanggal tidak tersedia' : new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(d);
}
export function age(value) {
    const hours = Math.floor((Date.now() - new Date(value).getTime()) / 3600000);
    if (!Number.isFinite(hours)) return 'Usia tidak tersedia';
    if (hours < 1) return 'Kurang dari 1 jam';
    return hours < 24 ? `${hours} jam` : `${Math.floor(hours / 24)} hari`;
}
export function dataTable(columns, rows, caption) {
    return `<div class="table-wrap" tabindex="0" role="region" aria-label="${esc(caption)}"><table class="table"><caption class="sr-only">${esc(caption)}</caption><thead><tr>${columns.map((column) => `<th scope="col">${esc(column.label)}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${columns.map((column) => `<td>${column.render ? column.render(row) : esc(row[column.key])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}
const attributes = (o) => Object.entries(o).filter(([, v]) => v !== undefined && v !== false && v !== null).map(([k, v]) => v === true ? esc(k) : `${esc(k)}="${esc(v)}"`).join(' ');
let fieldSequence = 0;
function fieldFrame(id, label, input, help) {
    return `<div class="field"><label for="${esc(id)}">${esc(label)}</label>${input}${help ? `<small id="${esc(id)}-help">${esc(help)}</small>` : ''}</div>`;
}
export function field(name, label, options = {}) {
    const id = `field-${++fieldSequence}`;
    const { help, value, minLength, maxLength, ...rest } = options;
    return fieldFrame(id, label, `<input ${attributes({ name, type: 'text', ...rest, id, value: rest.type === 'file' ? undefined : value, minlength: minLength, maxlength: maxLength, 'aria-describedby': help ? `${id}-help` : undefined })}>`, help);
}
export function textarea(name, label, options = {}) {
    const id = `field-${++fieldSequence}`;
    const { help, value, minLength, maxLength, ...rest } = options;
    return fieldFrame(id, label, `<textarea ${attributes({ name, rows: 5, ...rest, id, minlength: minLength, maxlength: maxLength, 'aria-describedby': help ? `${id}-help` : undefined })}>${esc(value)}</textarea>`, help);
}
export function selectField(name, label, options, settings = {}) {
    const id = `field-${++fieldSequence}`;
    return fieldFrame(id, label, `<select ${attributes({ id, name, required: settings.required, disabled: settings.disabled, 'aria-describedby': settings.help ? `${id}-help` : undefined })}>${options.map((o) => `<option value="${esc(o.value)}" ${String(o.value) === String(settings.value) ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>`, settings.help);
}
export function empty(title, message, actionHTML = '') {
    return `<div class="empty-state"><span class="empty-icon">${icon('report')}</span><h3>${esc(title)}</h3><p>${esc(message)}</p>${actionHTML}</div>`;
}
export function alertBox(message, tone = 'info') {
    return `<div class="callout ${esc(tone)}" role="${tone === 'error' ? 'alert' : 'status'}">${icon(tone === 'error' ? 'alert' : 'shield')}<span>${esc(message)}</span></div>`;
}
export function pagination(meta, route, params = new URLSearchParams()) {
    if (!meta || Number(meta.last_page) <= 1) return '';
    const link = (page, text) => {
        const q = new URLSearchParams(params);
        q.set('page', page);
        return `<a class="button secondary" href="#${esc(route)}?${esc(q.toString())}">${text}</a>`;
    };
    return `<nav class="pagination" aria-label="Halaman hasil">${meta.current_page > 1 ? link(meta.current_page - 1, 'Sebelumnya') : '<span></span>'}<span>Halaman ${esc(meta.current_page)} dari ${esc(meta.last_page)} <span class="muted">(${esc(meta.total)} data)</span></span>${meta.current_page < meta.last_page ? link(Number(meta.current_page) + 1, 'Berikutnya') : '<span></span>'}</nav>`;
}
export function formDataObject(form) {
    return Object.fromEntries(new FormData(form).entries());
}
export function formErrors(form, error) {
    form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    let area = form.querySelector('[data-errors]');
    if (!area) {
        area = document.createElement('div');
        area.dataset.errors = '';
        form.prepend(area);
    }
    area.innerHTML = '';
    if (!error) return;
    const messages = [];
    for (const [key, values] of Object.entries(error.details || {})) {
        const el = form.elements?.namedItem(key) || form.elements?.namedItem(key.split('.')[0]);
        el?.setAttribute?.('aria-invalid', 'true');
        if (Array.isArray(values)) messages.push(...values.filter((v) => typeof v === 'string'));
    }
    area.innerHTML = `<div class="form-error" role="alert" tabindex="-1"><strong>${esc(error.message)}</strong>${messages.length ? `<ul>${messages.map((m) => `<li>${esc(m)}</li>`).join('')}</ul>` : ''}</div>`;
    area.firstElementChild.focus();
}
export function skeleton() {
    return '<div class="skeleton-group" role="status" aria-label="Memuat data"><div class="skeleton skeleton-title"></div><div class="grid-3"><div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div></div><div class="skeleton skeleton-table"></div><span class="sr-only">Memuat data dari server...</span></div>';
}

export function navigation(user) {
    const has = (p) => user?.permissions?.includes(p);
    const classReady = user?.identity_status === 'verified' && user?.membership?.status === 'active';
    const lecturer = user?.role === 'lecturer_coordinator';
    const links = [{ path: '/dashboard', label: 'Ringkasan', icon: 'grid' }];
    if (has('reports.view.own') || has('reports.view.assigned') || has('reports.view.class')) links.push({ path: '/reports', label: 'Pengaduan', icon: 'report' });
    if ((classReady && has('aspirations.participate')) || lecturer) links.push({ path: '/aspirations', label: 'Ruang aspirasi', icon: 'chat' });
    if (classReady && user?.role === 'class_leader' && has('attendance.manage')) links.push({ path: '/attendance', label: 'Absensi kelas', icon: 'check' });
    if (has('memberships.review') || has('classes.manage')) links.push({ path: '/classes', label: 'Kelola kelas', icon: 'class' });
    if (has('identity.verify')) links.push({ path: '/verifications', label: 'Verifikasi identitas', icon: 'shield' });
    if (has('users.manage')) links.push({ path: '/users', label: 'Pengguna & role', icon: 'users' });
    if (has('security.view')) links.push({ path: '/security', label: 'Keamanan', icon: 'shield' });
    if (has('audit.view')) links.push({ path: '/audit', label: 'Audit aktivitas', icon: 'clock' });
    links.push({ path: '/notifications', label: 'Notifikasi', icon: 'bell' }, { path: '/account', label: 'Akun & verifikasi', icon: 'user' });
    return links;
}
