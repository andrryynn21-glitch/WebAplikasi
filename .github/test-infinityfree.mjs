import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtemp, mkdir, readFile, realpath, rm, writeFile } from 'node:fs/promises';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

// No PHP handler, Laravel bootstrap, database, real payloads, or system config.
const httpd = process.env.HTTPD_BINARY ?? (process.platform === 'darwin' ? '/usr/sbin/httpd' : '/usr/sbin/apache2');
const modules = process.env.APACHE_MODULE_DIR ?? (process.platform === 'darwin' ? '/usr/libexec/apache2' : '/usr/lib/apache2/modules');
const tempParent = process.env.INFINITYFREE_TEST_TMPDIR ?? tmpdir();
const frontController = 'SYNTHETIC_FRONT_CONTROLLER';
const privatePayload = 'SYNTHETIC_PRIVATE_PAYLOAD';
const privateDirectories = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'storage', 'vendor'];
const rootFiles = [
    '.env', '.env.production', '.git/config', '.github/config', '.htaccess.bak',
    'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lockb',
    'backup.sql', 'backup.sql.gz', 'backup.sqlite', 'backup.db', 'backup.dump',
    'backup.zip', 'backup.tar.gz', 'backup.tgz', 'backup.7z', 'backup.rar',
    'settings.ini', 'settings.conf', 'settings.config', 'settings.yaml', 'settings.toml',
    'README.md', 'unknown-root-file', 'docs/nested/payload.txt',
];
const assets = { 'build/assets/app.css': 'body { color: #123456; }', 'build/assets/app.js': 'console.log("synthetic");' };

function get(port, path) {
    // Pass the raw request target: fetch/URL would normalize traversal before Apache.
    return new Promise((resolve, reject) => {
        const req = request({ host: '127.0.0.1', port, path, agent: false }, (res) => {
            let body = '';
            res.setEncoding('utf8');
            res.on('data', (chunk) => { body += chunk; });
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
            res.on('error', reject);
        });
        req.on('error', reject);
        req.setTimeout(2000, () => req.destroy(new Error('Synthetic Apache request timed out')));
        req.end();
    });
}

async function ephemeralPort() {
    const socket = createServer();
    await new Promise((resolve, reject) => {
        socket.once('error', reject);
        socket.listen(0, '127.0.0.1', resolve);
    });
    const { port } = socket.address();
    await new Promise((resolve, reject) => socket.close((error) => error ? reject(error) : resolve()));
    return port;
}

async function withApache({ rewrite, mount }, run) {
    // macOS /var is an alias of /private/var: use one canonical server path.
    const root = await realpath(await mkdtemp(join(tempParent, 'infinityfree-httpd-')));
    let child;
    let closed;
    let stopped = false;
    let diagnostics = '';
    try {
        assert.notEqual(process.getuid(), 0, 'Run this isolated Apache test as a non-root user');
        const documentRoot = join(root, 'htdocs');
        const applicationRoot = join(documentRoot, mount);
        const fixture = async (path, content) => {
            const file = join(applicationRoot, path);
            await mkdir(dirname(file), { recursive: true });
            await writeFile(file, content);
        };
        await fixture('.htaccess', await readFile(new URL('./infinityfree.htaccess', import.meta.url)));
        // Copy only routing rules, never the real public files or index.php.
        await fixture('public/.htaccess', await readFile(new URL('../public/.htaccess', import.meta.url)));
        await fixture('public/index.php', frontController);
        for (const [path, body] of Object.entries(assets)) await fixture(`public/${path}`, body);
        for (const path of rootFiles) await fixture(path, privatePayload);
        for (const path of privateDirectories) {
            await fixture(`${path}/nested/payload.txt`, privatePayload);
            // Also prove accidental public copies do not bypass the explicit deny.
            await fixture(`public/${path}/nested/payload.txt`, privatePayload);
        }
        await fixture('public/.env', privatePayload);
        await fixture('public/build/.hidden/payload.txt', privatePayload);
        await fixture('public/build/assets/.env', privatePayload);
        await fixture('public/composer.json', privatePayload);
        await fixture('public/backup.zip', privatePayload);
        await fixture('public/build/manifest.json', '{"synthetic":true}');
        await fixture('public/build/assets/negotiation.css', '/* Synthetic MultiViews probe */');
        const builtins = execFileSync(httpd, ['-l'], { encoding: 'utf8' });
        const required = ['mpm_event', 'unixd', 'authz_core', 'dir', 'mime', 'negotiation', 'autoindex'];
        if (rewrite) required.push('rewrite');
        const loadModules = required.filter((name) => !builtins.includes(`mod_${name}.c`))
            .map((name) => `LoadModule ${name}_module "${join(modules, `mod_${name}.so`)}"`).join('\n');
        if (!rewrite) assert.ok(!builtins.includes('mod_rewrite.c'), 'Deny-all test needs a dynamically loaded mod_rewrite');
        const port = await ephemeralPort();
        const config = join(root, 'httpd.conf');
        await writeFile(config, `
ServerRoot "${root}"
${loadModules}
DefaultRuntimeDir "${root}"
PidFile "${join(root, 'httpd.pid')}"
ErrorLog "${join(root, 'error.log')}"
LogLevel warn
Listen 127.0.0.1:${port}
ServerName 127.0.0.1
ServerTokens Prod
ServerSignature Off
User #${process.getuid()}
Group #${process.getgid()}
DocumentRoot "${documentRoot}"
DirectoryIndex index.php
TypesConfig /dev/null
AddType text/css .css
AddType text/javascript .js
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "${documentRoot}">
    Options FollowSymLinks Indexes MultiViews
    AllowOverride All
    Require all granted
</Directory>
`);
        // -X keeps this a single foreground process; no detached worker tree to leak.
        child = spawn(httpd, ['-f', config, '-X', '-DFOREGROUND'], { detached: false, stdio: ['ignore', 'ignore', 'pipe'] });
        child.stderr.setEncoding('utf8');
        child.stderr.on('data', (chunk) => { diagnostics = (diagnostics + chunk).slice(-16000); });
        closed = new Promise((resolve) => {
            child.once('error', (error) => { diagnostics += error.message; stopped = true; resolve(); });
            child.once('close', () => { stopped = true; resolve(); });
        });
        const deadline = Date.now() + 5000;
        while (true) {
            assert.ok(!stopped, `Synthetic Apache failed to start: ${diagnostics}`);
            try {
                await get(port, `${mount}/`);
                break;
            } catch (error) {
                if (Date.now() >= deadline) throw error;
                await delay(25);
            }
        }
        await run((path) => get(port, `${mount}${path}`));
    } catch (error) {
        // The only log that may be read belongs to this synthetic fixture.
        const log = await readFile(join(root, 'error.log'), 'utf8').catch(() => '');
        error.message += `\nSynthetic Apache diagnostics: ${diagnostics}\n${log.slice(-16000)}`;
        throw error;
    } finally {
        if (child && !stopped) {
            child.kill('SIGTERM');
            await Promise.race([closed, delay(3000, undefined, { ref: false })]);
            if (!stopped) {
                child.kill('SIGKILL');
                await closed;
            }
        }
        await rm(root, { recursive: true, force: true });
    }
}

for (const mount of ['', '/campus']) {
    test(`Apache wrapper routes and protects ${mount || '/'} without changing public rules`, async (t) => {
        await withApache({ rewrite: true, mount }, async (get) => {
            await t.test('root, public entry and backend/SPA URLs reach the front controller without loops', async () => {
                for (const path of ['/', '/public/', '/index.php', '/public/index.php', '/attendance/42', '/api/v1/reports?status=open', '/api/v1/reports?next=%2e%2e%2fstorage', '/public/api/v1/reports']) {
                    const response = await get(path);
                    assert.equal(response.status, 200, path);
                    assert.equal(response.body, frontController, path);
                    assert.equal(response.headers.location, undefined, path);
                }
                const redirect = await get('/public');
                assert.equal(redirect.status, 301, 'Apache adds the directory slash');
                assert.equal(new URL(redirect.headers.location).pathname, `${mount}/public/`);
            });
            await t.test('CSS/JS are available directly and through relative rewrites', async () => {
                for (const [path, body] of Object.entries(assets)) {
                    for (const prefix of ['/', '/public/']) {
                        const response = await get(`${prefix}${path}?v=synthetic`);
                        assert.equal(response.status, 200, `${prefix}${path}`);
                        assert.equal(response.body, body, `${prefix}${path}`);
                        assert.match(response.headers['content-type'], path.endsWith('.css') ? /^text\/css/ : /^text\/javascript/);
                    }
                }
                assert.equal((await get('/build/manifest.json')).body, '{"synthetic":true}');
                assert.equal((await get('/public/build/assets/')).status, 403, 'No directory listing');
                assert.equal((await get('/build/assets/negotiation')).body, frontController, 'MultiViews must not serve negotiation.css');
            });
            await t.test('private directories, dotfiles, manifests and root artifacts are forbidden', async () => {
                const paths = rootFiles.map((path) => `/${path}`);
                for (const dir of privateDirectories) {
                    for (const prefix of ['/', '/public/']) {
                        paths.push(`${prefix}${dir}`, `${prefix}${dir}/`, `${prefix}${dir}/nested/payload.txt`);
                        paths.push(`${prefix}${dir.toUpperCase()}/nested/payload.txt`);
                    }
                }
                paths.push('/.htaccess', '/public/.htaccess', '/public/.env', '/build/.hidden/payload.txt', '/public/build/.hidden/payload.txt', '/build/assets/.env', '/public/composer.json', '/public/backup.zip', '/COMPOSER.JSON', '/artisan/extra');
                for (const path of paths) {
                    const response = await get(path);
                    assert.equal(response.status, 403, path);
                    assert.ok(!response.body.includes(privatePayload), path);
                }
            });
            await t.test('raw traversal, encoded delimiters and path variants never expose payloads', async () => {
                for (const path of [
                    '/%2eenv', '/.%65nv', '/public/%2eenv', '/public/../.env', '/public/%2e%2e/.env',
                    '/public/%252e%252e/%252eenv', '/public/%2e%2e%2fvendor/nested/payload.txt',
                    '/public/..%5cstorage/nested/payload.txt', '/public/%255c../.env',
                    '/public/..\\storage/nested/payload.txt', '/public/\\..\\.env',
                    '/%76endor/nested/payload.txt', '/%73torage/nested/payload.txt',
                    '//vendor//nested/payload.txt', '/public//storage//nested/payload.txt',
                    '/public/./storage/nested/payload.txt', '/public/../composer.json',
                    '/backup.zip/extra', '/backup.sql.gz/extra', '/public/.env/extra',
                    '/PUBLIC/STORAGE/nested/payload.txt', '/public/vendor/nested/payload.txt;extra',
                    '/public/.env.', '/public/.env%20',
                    '/public/%00.env', '/public/%3f.env', '/public/%23.env',
                ]) {
                    const response = await get(path);
                    assert.ok([400, 403, 404].includes(response.status), `${path}: ${response.status}`);
                    assert.ok(!response.body.includes(privatePayload), path);
                    assert.ok(!response.body.includes(frontController), path);
                    assert.equal(response.headers.location, undefined, path);
                }
            });
        });
    });

    test(`Apache without mod_rewrite denies all at ${mount || '/'}`, async () => {
        await withApache({ rewrite: false, mount }, async (get) => {
            for (const path of ['/', '/public/', '/index.php', '/public/index.php', '/attendance/42', '/build/assets/app.css', '/public/build/assets/app.js', '/.env', '/vendor/nested/payload.txt', '/storage/nested/payload.txt']) {
                const response = await get(path);
                assert.equal(response.status, 403, path);
                assert.ok(!response.body.includes(privatePayload), path);
                assert.ok(!response.body.includes(frontController), path);
            }
        });
    });
}
