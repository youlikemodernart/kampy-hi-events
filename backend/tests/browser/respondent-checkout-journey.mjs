import {completeInvitationFixture} from './completion-invitation-fixture-journey.mjs';
import {pathToFileURL, fileURLToPath} from 'node:url';
import {resolve} from 'node:path';
import fs from 'node:fs';
import os from 'node:os';
import https from 'node:https';
import http from 'node:http';
import net from 'node:net';
import {spawn, execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';

if (process.env.KAMP_RESPONDENT_DISPOSABLE !== '1') throw new Error('Disposable runner required');
const backend = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const frontend = resolve(backend, '../frontend');
process.chdir(frontend);
const {createServer} = await import(pathToFileURL(resolve(frontend, 'node_modules/vite/dist/node/index.js')));
const {chromium} = await import(pathToFileURL(process.env.RESPONDENT_PLAYWRIGHT_MODULE));
const dir = fs.mkdtempSync(resolve(os.tmpdir(), 'respondent-browser-'));
let vite, php, browser, server;
const checks = [];
try {
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', dir+'/key.pem', '-out', dir+'/cert.pem', '-days', '1', '-subj', '/CN=127.0.0.1'], {stdio: 'ignore'});
    vite = await createServer({root: frontend, cacheDir: dir+'/vite-cache', configFile: resolve(frontend, 'vite.config.ts'), define: {'process.env': '{}'}, server: {middlewareMode: true, hmr: false}, appType: 'custom', logLevel: 'error'});
    const reservation = net.createServer(); await new Promise(r => reservation.listen(0, '127.0.0.1', r));
    const phpPort = reservation.address().port; await new Promise(r => reservation.close(r));
    server = https.createServer({key: fs.readFileSync(dir+'/key.pem'), cert: fs.readFileSync(dir+'/cert.pem')}, async (req, res) => {
        if (req.url.startsWith('/api/registration/invitation') || req.url.startsWith('/public/') || req.url.startsWith('/_fixture/')) {
            const proxy = http.request({host: '127.0.0.1', port: phpPort, method: req.method, path: req.url, headers: req.headers}, upstream => {res.writeHead(upstream.statusCode, upstream.headers); upstream.pipe(res);});
            proxy.on('error', () => {res.statusCode = 502; res.end();}); req.pipe(proxy); return;
        }
        if (req.url.startsWith('/checkout/')) {
            res.setHeader('Content-Type', 'text/html');
            res.end(await vite.transformIndexHtml(req.url, '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0"><div id="root"></div><script type="module" src="/tests/respondent-checkout-entry.tsx"></script></body></html>')); return;
        }
        vite.middlewares(req, res, () => {res.statusCode = 404; res.end();});
    });
    await new Promise(r => server.listen(0, '127.0.0.1', r));
    const origin = `https://127.0.0.1:${server.address().port}`;
    const env = {PATH: process.env.PATH, HOME: process.env.HOME, TMPDIR: os.tmpdir(), APP_ENV: 'testing', APP_DEBUG: 'false', MAIL_MAILER: 'array', CACHE_DRIVER: 'array', CACHE_STORE: 'array', SESSION_DRIVER: 'array', KAMP_RESPONDENT_DISPOSABLE: '1', RESPONDENT_BROWSER_ORIGIN: origin, RESPONDENT_FULL_CHECKOUT: '1', RESPONDENT_BROWSER_MAILBOX: dir+'/mail.html'};
    for (const key of ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']) env[key] = process.env[key];
    php = spawn('php', ['-d', 'display_errors=0', '-d', 'log_errors=0', '-S', `127.0.0.1:${phpPort}`, 'tests/browser/respondent-router.php'], {cwd: backend, env, stdio: 'ignore'});
    for (let n = 0; n < 100; n++) { try {await new Promise((yes, no) => {const socket = net.connect(phpPort, '127.0.0.1', () => {socket.destroy(); yes();}); socket.on('error', no);}); break;} catch {await new Promise(r => setTimeout(r, 30));} }
    browser = await chromium.launch({headless: true});
    for (const width of [320, 390, 1200]) for (const historical of [false]) {
        const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 1000}, permissions: ['clipboard-read', 'clipboard-write']});
        await context.route('**/*', route => route.request().url().startsWith(origin+'/') ? route.continue() : route.abort());
        const reset = await context.request.post(origin+'/_fixture/reset');
        assert.equal(reset.status(), 200, await reset.text());
        await context.addCookies([{name: 'session_identifier', value: 'synthetic-session-11', url: origin}]);
        const page = await context.newPage(); const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin+'/checkout/7/order_11/details');
        await page.getByRole('button', {name: 'Continue to Payment'}).waitFor({timeout: 20000}).catch(async () => {throw new Error((await page.locator('body').innerText()) + errors.join(';'));});
        for (let i = 0; i < 3; i++) {
            await page.getByRole('textbox', {name: /^First Name/}).nth(i).fill(i === 0 ? 'Synthetic' : 'Invented');
            await page.getByRole('textbox', {name: /^Last Name/}).nth(i).fill(i === 0 ? 'Buyer' : `Attendee ${i}`);
            const email = i === 0 ? 'buyer@example.test' : `attendee${i}@example.test`;
            await page.getByRole('textbox', {name: /^Email Address/}).nth(i).fill(email);
            await page.getByRole('textbox', {name: /^Confirm Email Address/}).nth(i).fill(email);
        }
        const submitted = page.waitForResponse(r => r.request().method() === 'PUT');
        await page.getByRole('button', {name: 'Continue to Payment'}).click();
        const completion = await submitted; assert.equal(completion.status(), 200, await completion.text());
        await page.getByText('Synthetic payment boundary', {exact: false}).waitFor();
        assert.equal((await context.request.post(origin+'/_fixture/paid')).status(), 200);
        await page.goto(origin+'/checkout/7/order_11/summary');
        // Receipt-only public edit rotates the short ID but must never redirect verification mail.
        const edited = await context.request.patch(origin+'/public/events/7/order/order_11', {data: {email: 'attacker@example.test'}});
        assert.equal(edited.status(), 200, await edited.text());
        const shortId = (await edited.json()).new_short_id;
        assert.ok(shortId && shortId !== 'order_11');
        await context.clearCookies();
        await page.goto(origin+`/checkout/7/${shortId}/summary`);

        checks.push(await completeInvitationFixture({page,context,origin,width,output:process.env.RESPONDENT_BROWSER_OUTPUT,prefix:'checkout'}));
        assert.deepEqual(errors, []);
        await context.close();
    }
    if (process.env.RESPONDENT_BROWSER_OUTPUT) fs.writeFileSync(process.env.RESPONDENT_BROWSER_OUTPUT+'/checks.json', JSON.stringify(checks, null, 2));
    console.log(JSON.stringify({journeys: checks.length, passed: true, transport: 'Laravel array; no provider configuration', shell: 'native Checkout + CollectInformation + OrderSummaryAndProducts', payment: 'explicit synthetic state transition; NOT Stripe acceptance', widths: [320, 390, 1200]}));
} finally {
    await browser?.close();
    if (php && php.exitCode === null) {php.kill('SIGTERM'); await new Promise(resolve => php.once('exit', resolve));}
    if (server) await new Promise(resolve => server.close(resolve));
    await vite?.close(); fs.rmSync(dir, {recursive: true, force: true});
}
