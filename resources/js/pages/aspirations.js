import { esc, badge, date, field, textarea, selectField, empty, alertBox, pagination, formDataObject } from '../ui.js';

const back = '<a class="button ghost" href="#/aspirations">Kembali ke aspirasi</a>';
const flagReasons = [
    { value: 'spam', label: 'Spam' }, { value: 'harassment', label: 'Pelecehan atau perundungan' },
    { value: 'offensive', label: 'Konten tidak pantas' }, { value: 'private_information', label: 'Informasi pribadi' },
    { value: 'other', label: 'Lainnya' },
];
const actionLabels = { hide: 'Sembunyikan konten', restore: 'Pulihkan / buka konten', remove: 'Hapus dari tampilan', lock: 'Kunci komentar', dismiss_flag: 'Tolak laporan konten', resolve_flag: 'Tandai laporan ditangani' };
const pathFor = (id) => `/aspirations/${encodeURIComponent(id)}`;

function flagForm(key) {
    return `<details><summary>Laporkan konten</summary><form data-flag="${esc(key)}" class="stack">
        ${selectField('reason', 'Alasan laporan konten', [{ value: '', label: 'Pilih alasan' }, ...flagReasons], { required: true })}
        ${textarea('note', 'Keterangan tambahan (opsional)', { maxLength: 500 })}
        <button class="button secondary" type="submit">Kirim laporan konten</button>
    </form></details>`;
}

function moderationForm(key, status, isComment = false, openFlag = false) {
    const actions = [];
    if (['published', 'locked'].includes(status)) actions.push('hide');
    if (['hidden', 'removed', 'locked'].includes(status)) actions.push('restore');
    if (status !== 'removed') actions.push('remove');
    if (!isComment && status === 'published') actions.push('lock');
    if (openFlag) actions.push('dismiss_flag', 'resolve_flag');
    return `<details><summary>Moderasi konten</summary><form data-moderate="${esc(key)}" class="stack">
        ${selectField('action', 'Tindakan moderasi', [{ value: '', label: 'Pilih tindakan' }, ...actions.map((value) => ({ value, label: actionLabels[value] }))], { required: true })}
        ${textarea('reason', 'Alasan tindakan', { required: true, minLength: 5, maxLength: 500 })}
        <button class="button secondary" type="submit">Simpan tindakan moderasi</button>
    </form></details>`;
}

async function aspirationsList(ctx, params) {
    const query = new URLSearchParams();
    for (const key of ['class_id', 'sort', 'page', 'per_page']) if (params.get(key)) query.set(key, params.get(key));
    if (ctx.has('aspirations.moderate') && params.get('include_hidden') === '1') query.set('include_hidden', '1');
    const { data: items, meta } = await ctx.api(`/aspirations?${query}`);
    if (!ctx.view.isConnected) return;
    const create = ctx.has('aspirations.create') && ctx.canClass() ? '<a class="button" href="#/aspirations/new">Tulis aspirasi</a>' : '';
    ctx.page('Suara kelas', 'Usulan terbuka untuk kelas Anda. Gunakan pengaduan privat untuk masalah yang sensitif.', `<div class="stack">
        <section class="panel"><form data-aspiration-filters class="form-grid">
            ${selectField('sort', 'Urutkan aspirasi', [{ value: 'last_activity_at', label: 'Aktivitas terbaru' }, { value: 'supports_count', label: 'Dukungan terbanyak' }, { value: 'comments_count', label: 'Komentar terbanyak' }, { value: 'created_at', label: 'Baru dibuat' }], { value: query.get('sort') || 'last_activity_at' })}
            ${ctx.has('aspirations.moderate') ? field('class_id', 'ID kelas (opsional)', { type: 'number', min: 1, value: query.get('class_id') || '', help: 'Hanya kelas dalam kewenangan Anda. Kosongkan untuk cakupan bawaan.' }) : ''}
            ${ctx.has('aspirations.moderate') ? selectField('include_hidden', 'Visibilitas', [{ value: '', label: 'Konten terlihat' }, { value: '1', label: 'Sertakan konten tersembunyi / dihapus' }], { value: query.get('include_hidden') || '' }) : ''}
            <div class="toolbar"><button class="button secondary" type="submit">Terapkan filter</button><a class="button ghost" href="#/aspirations">Reset</a></div>
        </form></section>
        ${items.length ? `<section class="panel"><div class="panel-header"><h2>Aspirasi kelas</h2><span class="muted">${esc(meta?.pagination?.total ?? items.length)} aspirasi</span></div><div class="content-list">${items.map((item) => `<article class="list-item stack">
            <div class="toolbar">${badge(item.status)}${item.is_pinned ? '<span class="badge">Disematkan</span>' : ''}${item.is_mine ? '<span class="badge">Milik saya</span>' : ''}</div>
            <h3><a href="#${esc(pathFor(item.id))}">${esc(item.title ?? 'Konten dibatasi')}</a></h3>
            ${item.body !== null ? `<p class="prose">${esc(item.body.length > 240 ? `${item.body.slice(0, 240)}...` : item.body)}</p>` : '<p class="muted">Isi konten tidak tersedia untuk kewenangan Anda.</p>'}
            <p class="muted">${esc(item.author?.name ?? 'Anggota kelas')} &middot; ${date(item.last_activity_at || item.created_at)}</p>
            <div class="toolbar"><span>${esc(item.supports_count)} dukungan</span><span>${esc(item.comments_count)} komentar</span></div>
        </article>`).join('')}</div>${pagination(meta?.pagination, '/aspirations', query)}</section>` : empty('Belum ada aspirasi', 'Tidak ada aspirasi yang cocok dengan filter ini. Usulan yang Anda kirim akan terlihat oleh kelas.', create)}
    </div>`, create);
    ctx.form(ctx.view.querySelector('[data-aspiration-filters]'), async (form) => {
        const next = new URLSearchParams();
        for (const [key, value] of Object.entries(formDataObject(form))) if (value !== '') next.set(key, value);
        ctx.navigate(`/aspirations?${next}`);
    });
}

function createAspiration(ctx) {
    if (!ctx.has('aspirations.create') || !ctx.canClass()) {
        ctx.page('Tulis aspirasi', 'Fitur kelas belum tersedia.', alertBox('Identitas terverifikasi, keanggotaan aktif, dan izin membuat aspirasi diperlukan.'), back);
        return;
    }
    ctx.page('Satu usulan, untuk kelas', 'Aspirasi dapat dibaca oleh anggota kelas dan pengelola yang berwenang.', `<div class="stack">
        ${alertBox('Jangan menulis NIM, identitas pelapor, atau isi pengaduan rahasia di sini. Untuk kasus sensitif, gunakan pengaduan privat.')}
        <form data-create-aspiration class="panel stack">
            ${field('title', 'Judul aspirasi', { required: true, minLength: 5, maxLength: 200, placeholder: 'Perubahan apa yang Anda usulkan?' })}
            ${textarea('body', 'Isi aspirasi', { required: true, minLength: 10, maxLength: 10000, help: 'Jelaskan usulan dan manfaatnya. Gunakan teks biasa, bukan HTML.' })}
            <div class="toolbar"><button class="button" type="submit">Publikasikan ke kelas</button>${back}</div>
        </form></div>`, back);
    ctx.form(ctx.view.querySelector('[data-create-aspiration]'), async (form) => {
        const data = formDataObject(form);
        const result = await ctx.api('/aspirations', { method: 'POST', body: { title: data.title.trim(), body: data.body.trim() } });
        if (!ctx.view.isConnected) return;
        ctx.toast('Aspirasi berhasil dipublikasikan.');
        ctx.navigate(pathFor(result.data.id));
    });
}

async function aspirationDetail(ctx, id, params) {
    const path = pathFor(id);
    const { data: aspiration } = await ctx.api(path);
    if (!ctx.view.isConnected) return;
    const moderator = ctx.has('aspirations.moderate');
    const own = aspiration.is_mine || aspiration.author?.id === ctx.user.id;
    const visible = ['published', 'locked'].includes(aspiration.status);
    const participate = ctx.canClass() && ctx.has('aspirations.participate');
    const canFlag = visible && ctx.canClass() && ctx.has('aspirations.flag');
    if (params.get('tab') === 'flags') {
        if (!moderator) {
            ctx.page('Laporan konten', 'Akses terbatas.', alertBox('Laporan konten hanya tersedia bagi moderator kelas yang berwenang.'), `<a class="button ghost" href="#${esc(path)}">Kembali ke aspirasi</a>`);
            return;
        }
        return aspirationFlags(ctx, aspiration, path, params);
    }
    const { data: comments } = await ctx.api(`${path}/comments`);
    if (!ctx.view.isConnected) return;
    const actions = `${back}${moderator ? `<a class="button secondary" href="#${esc(path)}?tab=flags&status=open">Laporan konten</a>` : ''}`;
    ctx.page(aspiration.title ?? 'Konten dibatasi', 'Ruang diskusi kelas yang saling menghargai.', `<div class="stack">
        <article class="panel stack"><div class="toolbar">${badge(aspiration.status)}${aspiration.is_pinned ? '<span class="badge">Disematkan</span>' : ''}${own ? '<span class="badge">Milik saya</span>' : ''}</div>
            <p class="muted">${esc(aspiration.author?.name ?? 'Anggota kelas')} &middot; ${date(aspiration.created_at)}</p>
            <p class="prose">${esc(aspiration.body ?? 'Isi konten tidak tersedia untuk kewenangan Anda.')}</p>
            <div class="toolbar"><span>${esc(aspiration.supports_count)} dukungan</span><span>${esc(aspiration.comments_count)} komentar</span>
                ${participate && visible ? `<button class="button ${aspiration.supported_by_me ? 'secondary' : ''}" type="button" data-support aria-pressed="${aspiration.supported_by_me ? 'true' : 'false'}">${aspiration.supported_by_me ? 'Batalkan dukungan' : 'Dukung aspirasi'}</button>` : ''}</div>
            ${canFlag && !own ? flagForm('aspiration') : ''}
            ${moderator && !own ? moderationForm('aspiration', aspiration.status) : ''}
            ${moderator && own ? '<p class="muted">Anda tidak dapat memoderasi aspirasi sendiri. Moderator lain yang berwenang harus menanganinya.</p>' : ''}
        </article>
        ${aspiration.status === 'locked' ? alertBox('Diskusi dikunci. Komentar baru tidak tersedia, tetapi dukungan masih dapat diberikan.') : ''}
        ${!visible ? alertBox('Aspirasi ini tidak ditampilkan kepada kelas. Komentar baru, dukungan, dan laporan konten tidak tersedia.') : ''}
        <section class="panel stack"><h2>Diskusi kelas</h2>
            ${comments.length ? `<div class="content-list">${comments.map((comment, index) => {
                const ownComment = comment.author?.id === ctx.user.id;
                return `<article class="list-item stack" id="comment-${esc(comment.id)}"><div class="toolbar"><strong>${esc(comment.author?.name ?? 'Anggota kelas')}</strong>${ownComment ? '<span class="badge">Saya</span>' : ''}</div>
                    <p class="muted">Komentar #${esc(comment.id)} &middot; ${date(comment.created_at)}${comment.parent_id ? ` &middot; Membalas komentar #${esc(comment.parent_id)}` : ''}</p>
                    <p class="prose">${esc(comment.body)}</p>
                    ${participate && aspiration.status === 'published' ? `<button class="button ghost" type="button" data-reply="${index}">Balas komentar #${esc(comment.id)}</button>` : ''}
                    ${canFlag && comment.author && !ownComment ? flagForm(`comment-${index}`) : ''}
                    ${moderator && comment.author && !ownComment ? moderationForm(`comment-${index}`, 'published', true) : ''}
                </article>`;
            }).join('')}</div>` : '<p class="muted">Belum ada komentar yang terlihat.</p>'}
            ${comments.length >= 200 ? alertBox('API menampilkan maksimal 200 komentar paling awal. Jumlah komentar pada ringkasan dapat lebih besar.') : ''}
            ${participate && aspiration.status === 'published' ? `<form data-comment class="stack"><h3>Tambahkan komentar</h3>
                ${selectField('parent_id', 'Balasan untuk', [{ value: '', label: 'Komentar baru' }, ...comments.map((comment) => ({ value: comment.id, label: `#${comment.id}: ${comment.body.slice(0, 80)}` }))])}
                ${textarea('body', 'Komentar', { required: true, minLength: 1, maxLength: 3000, help: 'Hindari data pribadi dan gunakan bahasa yang menghargai anggota kelas.' })}
                <button class="button" type="submit">Kirim komentar</button></form>` : ''}
        </section></div>`, actions);
    const support = ctx.view.querySelector('[data-support]');
    if (support) support.addEventListener('click', () => ctx.action(support, async () => {
        const result = await ctx.api(`${path}/support`, { method: aspiration.supported_by_me ? 'DELETE' : 'POST' });
        if (!ctx.view.isConnected) return;
        ctx.toast(result.data.supported ? 'Dukungan tercatat.' : 'Dukungan dibatalkan.');
        ctx.refresh();
    }));
    const commentForm = ctx.view.querySelector('[data-comment]');
    if (commentForm) {
        ctx.form(commentForm, async (form) => {
            const data = formDataObject(form);
            await ctx.api(`${path}/comments`, { method: 'POST', body: { body: data.body.trim(), ...(data.parent_id ? { parent_id: Number(data.parent_id) } : {}) } });
            if (!ctx.view.isConnected) return;
            ctx.toast('Komentar berhasil dikirim.');
            ctx.refresh();
        });
        ctx.view.querySelectorAll('[data-reply]').forEach((button) => button.addEventListener('click', () => {
            commentForm.elements.namedItem('parent_id').value = comments[Number(button.dataset.reply)].id;
            commentForm.elements.namedItem('body').focus();
        }));
    }
    const target = (key) => {
        if (key === 'aspiration') return { path, own };
        const comment = comments[Number(key.slice('comment-'.length))];
        return { path: `${path}/comments/${encodeURIComponent(comment.id)}`, own: !comment.author || comment.author.id === ctx.user.id };
    };
    ctx.view.querySelectorAll('[data-flag]').forEach((form) => ctx.form(form, async (element) => {
        const content = target(element.dataset.flag);
        if (content.own) return;
        await ctx.api(`${content.path}/flag`, { method: 'POST', body: formDataObject(element) });
        if (!ctx.view.isConnected) return;
        ctx.toast('Laporan konten diterima. Laporan berulang tidak membuat duplikat.');
        element.reset();
        element.closest('details').open = false;
    }));
    ctx.view.querySelectorAll('[data-moderate]').forEach((form) => ctx.form(form, async (element) => {
        const content = target(element.dataset.moderate);
        if (content.own) return;
        await moderate(ctx, content.path, formDataObject(element));
    }));
}

async function moderate(ctx, path, data) {
    if (!ctx.has('aspirations.moderate')) return;
    if (!await ctx.confirm({ title: 'Simpan tindakan moderasi?', message: `${actionLabels[data.action] || 'Tindakan moderasi'} akan dicatat beserta alasan. Hak kelas dan larangan moderasi konten sendiri diperiksa oleh server.`, confirmLabel: 'Simpan tindakan' }) || !ctx.view.isConnected) return;
    await ctx.api(`${path}/moderate`, { method: 'POST', body: data });
    if (!ctx.view.isConnected) return;
    ctx.toast('Tindakan moderasi berhasil dicatat.');
    ctx.refresh();
}

async function aspirationFlags(ctx, aspiration, path, params) {
    const query = new URLSearchParams();
    for (const key of ['status', 'page', 'per_page']) if (params.get(key)) query.set(key, params.get(key));
    const { data: flags, meta } = await ctx.api(`${path}/flags?${query}`);
    if (!ctx.view.isConnected) return;
    const pageParams = new URLSearchParams(query);
    pageParams.set('tab', 'flags');
    const own = aspiration.is_mine || aspiration.author?.id === ctx.user.id;
    ctx.page('Laporan konten', aspiration.title ?? 'Moderasi aspirasi kelas', `<div class="stack">
        ${alertBox('Daftar mencakup aspirasi ini dan komentarnya, tanpa identitas pelapor. Hak melihat laporan tidak memberi hak memoderasi konten sendiri.')}
        <section class="panel"><form data-flag-filters class="form-grid">${selectField('status', 'Status laporan konten', [{ value: '', label: 'Semua status' }, { value: 'open', label: 'Terbuka' }, { value: 'resolved', label: 'Ditangani' }, { value: 'dismissed', label: 'Ditolak' }], { value: query.get('status') || '' })}<button class="button secondary" type="submit">Terapkan filter</button></form></section>
        ${flags.length ? `<section class="panel stack"><h2>Antrean moderasi</h2><div class="content-list">${flags.map((flag, index) => {
            const canModerate = flag.can_moderate === true && !(flag.content_type === 'aspiration' && own);
            return `<article class="list-item stack"><div class="toolbar"><strong>Laporan #${esc(flag.id)}</strong>${badge(flag.status)}<span class="badge">${flag.content_type === 'comment' ? 'Komentar' : 'Aspirasi'} #${esc(flag.content_id)}</span>${badge(flag.content_status)}</div>
                <p class="muted">Dikirim ${date(flag.created_at)}${flag.reviewed_at ? ` &middot; Ditinjau ${date(flag.reviewed_at)}` : ''}</p>
                <h3>Konten yang dilaporkan</h3><p class="prose">${esc(flag.body)}</p>
                <p><strong>Alasan:</strong> ${esc(flagReasons.find((reason) => reason.value === flag.reason)?.label ?? flag.reason)}</p>
                ${flag.note ? `<p class="prose">${esc(flag.note)}</p>` : ''}
                ${canModerate ? moderationForm(`flag-${index}`, flag.content_status, flag.content_type === 'comment', flag.status === 'open') : '<p class="muted">Anda tidak berwenang memoderasi konten ini, termasuk konten milik sendiri. Minta moderator lain yang berwenang untuk menanganinya.</p>'}
            </article>`;
        }).join('')}</div>${pagination(meta?.pagination, path, pageParams)}</section>` : empty('Tidak ada laporan konten', 'Tidak ada laporan konten yang cocok dengan filter ini.')}
    </div>`, `<a class="button ghost" href="#${esc(path)}">Kembali ke diskusi</a>`);
    ctx.form(ctx.view.querySelector('[data-flag-filters]'), async (form) => {
        const next = new URLSearchParams({ tab: 'flags' });
        const status = formDataObject(form).status;
        if (status) next.set('status', status);
        ctx.navigate(`${path}?${next}`);
    });
    ctx.view.querySelectorAll('[data-moderate]').forEach((form) => ctx.form(form, async (element) => {
        const flag = flags[Number(element.dataset.moderate.slice('flag-'.length))];
        if (flag.can_moderate !== true || (flag.content_type === 'aspiration' && own)) return;
        const target = flag.content_type === 'comment' ? `${path}/comments/${encodeURIComponent(flag.content_id)}` : path;
        const data = formDataObject(element);
        // Closed flags are history; content actions must not resubmit their flag_id.
        if (flag.status === 'open') data.flag_id = flag.id;
        await moderate(ctx, target, data);
    }));
}

export async function aspirationsPage(ctx, segments, params) {
    if (segments[0] === 'aspirations' && segments.length === 1) return aspirationsList(ctx, params);
    if (segments[0] === 'aspirations' && segments[1] === 'new' && segments.length === 2) return createAspiration(ctx);
    if (segments[0] === 'aspirations' && segments[1] && segments.length === 2) return aspirationDetail(ctx, segments[1], params);
    ctx.page('Halaman tidak ditemukan', 'Periksa kembali alamat aspirasi.', empty('Alamat tidak tersedia', 'Gunakan daftar aspirasi untuk melanjutkan.', back));
}
