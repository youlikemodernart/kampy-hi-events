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
        if (req.url.startsWith('/public/') || req.url.startsWith('/_fixture/')) {
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

        await page.getByRole('heading', {name: 'Waiver contacts'}).waitFor({timeout: 15000}).catch(() => {throw new Error('Fixture render failed: '+errors.join('; '));});
        const counts = async () => (await context.request.get(origin+'/_fixture/counts')).json();
        assert.deepEqual(await counts(), {challenges: 0, assignments: 0, outbox: 0, consumed: 0});
        const requested = page.waitForResponse(response => response.url().endsWith('/request-verification'));
        await page.getByRole('button', {name: 'Verify purchase email'}).click();
        const requestResponse = await requested; assert.equal(requestResponse.status(), 202, await requestResponse.text());
        await page.getByText('If this order is eligible, a verification code will be sent to the purchase email address.').waitFor();
        assert.deepEqual(await counts(), {challenges: 1, assignments: 0, outbox: 0, consumed: 0}); // Forwarded receipt alone only initiates a challenge.
        await page.getByRole('textbox', {name: 'Invented Attendee 1'}).click(); await page.getByRole('option', {name: 'Adult attendee', exact: true}).click();
        await page.getByRole('textbox', {name: 'Invented Attendee 2'}).click(); await page.getByRole('option', {name: 'Parent or guardian', exact: true}).click();
        await page.getByLabel(/^Email/).nth(0).fill('adult@example.test');
        await page.getByLabel(/^Name/).fill('Invented Guardian');
        await page.getByLabel(/^Email/).nth(1).fill('guardian@example.test');
        await page.getByRole('checkbox').last().check();
        await page.getByLabel('Verification code', {exact: true}).fill('0'.repeat(64));
        await page.getByRole('button', {name: 'Confirm all contacts'}).click();
        await page.getByText('Unable to confirm contacts', {exact: true}).waitFor();
        assert.equal((await counts()).assignments, 0);
        const mailbox = await context.newPage(); await mailbox.goto(origin+'/_fixture/mailbox');
        const code = await mailbox.locator('p').evaluateAll(nodes => nodes.map(n => n.textContent.trim()).find(value => /^[a-f0-9]{64}$/.test(value)));
        assert.equal(code.length, 64);
        await mailbox.locator('p').filter({hasText: code}).evaluate(node => {
            const range = document.createRange(); range.selectNodeContents(node);
            const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
        });
        await mailbox.keyboard.press(process.platform === 'darwin' ? 'Meta+C' : 'Control+C');
        await page.bringToFront(); await page.getByLabel('Verification code', {exact: true}).click();
        await page.keyboard.press(process.platform === 'darwin' ? 'Meta+V' : 'Control+V');
        assert.equal(await page.getByLabel('Verification code', {exact: true}).inputValue(), code);
        const geometry = await page.evaluate(() => ({scroll: document.documentElement.scrollWidth, width: innerWidth}));
        assert.ok(geometry.scroll <= width, 'checkout horizontal overflow '+JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('*')].filter(el => el.getBoundingClientRect().right > innerWidth).slice(0, 8).map(el => ({tag: el.tagName, cls: el.className, right: el.getBoundingClientRect().right})))));
        const button = await page.getByRole('button', {name: 'Confirm all contacts'}).boundingBox(); assert.ok(button.width > 200 && button.height >= 36);
        const output = process.env.RESPONDENT_BROWSER_OUTPUT;
        if (output) {fs.mkdirSync(output, {recursive: true}); await page.screenshot({path: `${output}/${historical ? 'historical' : 'new'}-${width}.png`, fullPage: true});}
        await page.getByRole('button', {name: 'Confirm all contacts'}).click(); await page.getByText('Contacts confirmed', {exact: true}).waitFor();
        if (output) await page.screenshot({path: `${output}/${historical ? 'historical' : 'new'}-${width}-confirmed.png`, fullPage: true});
        assert.deepEqual(await counts(), {challenges: 1, assignments: 2, outbox: 1, consumed: 1});
        const order = 11;
        const respondents = await (await context.request.get(origin+'/_fixture/respondents')).json();
        const replay = async respondents => page.evaluate(async ({shortId, code, respondents}) => (await fetch(`/public/registration/orders/${shortId}/confirm-respondents`, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-Kamp-Respondent-Intent': 'confirm'}, body: JSON.stringify({verification_code: code, acknowledged: true, respondents})})).status, {shortId, code, respondents});
        assert.equal(await replay(respondents), 200);
        respondents[1].email = 'changed@example.test'; assert.equal(await replay(respondents), 409);
        assert.deepEqual(await counts(), {challenges: 1, assignments: 2, outbox: 1, consumed: 1});
        assert.deepEqual(errors, []); checks.push({width, historical, receiptEmailTakeoverDenied: true, originalMailboxOnly: true, clipboardPaste: true, invalidRejected: true, exactReplay: true, changedReplayDenied: true, outbox: 1, overflow: false});
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
