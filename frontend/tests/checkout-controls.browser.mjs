// Run after npm run build; PLAYWRIGHT_MODULE points to an existing local Playwright install.
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {resolve, extname} from 'node:path';
import {createRequire} from 'node:module';
import {EventEmitter} from 'node:events';
const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = resolve('dist/client');
const {render} = await import('../dist/server/entry.server.js');
const product = {id: 9, title: 'Synthetic Standard', product_type: 'TICKET', prices: [{id: 9, price: 30}], is_hidden: false};
const event = {id: 7, title: 'Synthetic checkout QC', slug: 'synthetic', currency: 'USD', timezone: 'UTC', start_date: '2030-10-17T12:00:00Z', end_date: '2030-10-18T12:00:00Z', images: [], settings: {attendee_details_collection_method: 'PER_ATTENDEE', require_billing_address: false, show_marketing_opt_in: true, location_details: {}, continue_button_text: 'Continue'}, organizer: {id: 2, name: 'Synthetic organizer'}, product_categories: [{id: 1, products: [product]}]};
let quantity = 1;
let mutationAttempts = 0;
const order = () => ({id: 1, short_id: 'synthetic', event_id: 7, event, status: 'RESERVED', is_expired: false, reserved_until: '2030-10-17T12:00:00Z', is_payment_required: true, currency: 'USD', total_gross: 36 * quantity, total_before_additions: 30 * quantity, total_refunded: 0, attendees: [], order_items: [{id: 1, product_id: 9, product_price_id: 9, product, item_name: 'Synthetic Standard', quantity, price: 30, total_gross: 36 * quantity}], taxes_and_fees_rollup: {fees: [], taxes: []}});
const questions = [{id: 1, title: 'Synthetic choice', type: 'RADIO', options: ['Option A', 'Option B'], required: true, belongs_to: 'PRODUCT', product_ids: [9], description: ''}];
let origin;
const server = createServer(async (req, res) => {
    try {
        const path = new URL(req.url, origin).pathname;
        if (path.startsWith('/api/')) {
            if (req.method !== 'GET') mutationAttempts++;
            assert.equal(req.method, 'GET', 'No mutation is allowed in this synthetic journey');
            const data = path.endsWith('/questions') ? questions : path.includes('/order/') ? order() : event;
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({data})); return;
        }
        if (path.startsWith('/checkout/')) {
            const result = await render({req: {cookies: {}, headers: {}, protocol: 'http', get: () => new URL(origin).host, originalUrl: req.url, method: 'GET'}, res: new EventEmitter()});
            let html = await readFile(resolve(root, 'index.html'), 'utf8');
            html = html.replace('<!--app-html-->', result.appHtml).replace('<!--environment-variables-->', `<script>window.hievents=${JSON.stringify({VITE_API_URL_CLIENT: origin + '/api', VITE_FRONTEND_URL: origin})}</script>`).replace('<!--dehydrated-state-->', `<script>window.__REHYDRATED_STATE__=${JSON.stringify(result.dehydratedState)}</script>`);
            res.setHeader('Content-Type', 'text/html'); res.end(html); return;
        }
        const file = resolve(root, '.' + path);
        assert.ok(file.startsWith(root + '/'));
        res.setHeader('Content-Type', ({'.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml'})[extname(file)] || 'application/octet-stream');
        res.end(await readFile(file));
    } catch (e) {res.statusCode = 500; res.end('Synthetic server error'); console.error(e.message);}
});
let browser;
try {
    await new Promise(r => server.listen(0, '127.0.0.1', r));
    origin = `http://127.0.0.1:${server.address().port}`;
    process.env.VITE_API_URL_SERVER = origin + '/api';
    browser = await chromium.launch({headless: true});
    for (const width of [390, 1280]) {
        for (quantity of [1, 2]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            const errors = [];
            page.on('pageerror', e => errors.push(e.message));
            await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
            await page.goto(origin + '/checkout/7/synthetic/details');
            const marketing = page.getByRole('checkbox', {name: /Keep me updated/});
            await marketing.waitFor();
            assert.equal(await marketing.isChecked(), false);
            const hint = page.getByText('Fill in your details above first', {exact: true});
            await hint.waitFor();
            const copy = quantity === 1 ? page.getByRole('checkbox', {name: 'Copy details to first attendee'}) : page.getByRole('radiogroup').first();
            if (quantity === 1) assert.equal(await copy.isDisabled(), true);
            const describedBy = await copy.getAttribute('aria-describedby');
            assert.ok(describedBy);
            assert.equal(await page.locator(`[id="${describedBy}"]`).innerText(), 'Fill in your details above first');
            await page.getByRole('radio', {name: 'Option A', exact: true}).first().waitFor();
            const geometry = await page.locator('.mantine-Checkbox-input, .mantine-Radio-radio').evaluateAll(inputs => inputs.filter(e => !e.closest('.mantine-SegmentedControl-root')).map(e => {const r = e.getBoundingClientRect(); const label = document.querySelector(`label[for="${e.id}"]`);return {width: r.width, height: r.height, labelHeight: label?.getBoundingClientRect().height};}));
            const outsideHeights = await page.locator('.mantine-Checkbox-root, .mantine-Radio-root').evaluateAll(roots => roots.slice(0, 3).map(root => {
                const clone = root.cloneNode(true);
                clone.querySelectorAll('[id]').forEach(e => e.removeAttribute('id'));
                document.body.appendChild(clone);
                const height = clone.querySelector('input').getBoundingClientRect().height;
                clone.remove();
                return height;
            }));
            assert.ok(outsideHeights.every(height => height >= 44), 'Outside checkout retains the baseline hitbox');
            assert.ok(geometry.length >= 3);
            for (const g of geometry) {assert.ok(Math.abs(g.width - 20) < 1); assert.ok(Math.abs(g.height - 20) < 1); assert.ok(g.labelHeight >= 43.9);}
            await page.getByRole('textbox', {name: 'First Name', exact: true}).first().fill('Synthetic');
            await page.getByRole('textbox', {name: 'Last Name', exact: true}).first().fill('Buyer');
            await page.getByRole('textbox', {name: 'Email Address', exact: true}).first().fill('synthetic@example.invalid');
            await hint.waitFor({state: 'hidden'});
            if (quantity === 1) {await copy.check();} else {await page.getByText('All attendees', {exact: true}).click();}
            assert.equal(await page.getByRole('textbox', {name: 'First Name', exact: true}).nth(quantity).inputValue(), 'Synthetic');
            assert.equal(await marketing.isChecked(), false);
            await marketing.focus(); await page.keyboard.press('Space'); assert.equal(await marketing.isChecked(), true);
            await page.keyboard.press('Space'); assert.equal(await marketing.isChecked(), false);
            const radio = page.getByRole('radio', {name: 'Option A', exact: true}).first();
            await radio.focus(); await page.keyboard.press('Space'); await page.keyboard.press('ArrowRight');
            assert.equal(await page.getByRole('radio', {name: 'Option B', exact: true}).first().isChecked(), true);
            const label = page.locator('label').filter({hasText: /^Option A$/}).first();
            await label.scrollIntoViewIfNeeded(); const box = await label.boundingBox(); await page.mouse.click(box.x + box.width / 2, box.y + 2);
            assert.equal(await radio.isChecked(), true);
            await page.getByRole('textbox', {name: 'Email Address', exact: true}).first().fill('invalid');
            await hint.waitFor();
            if (quantity === 1) {assert.equal(await copy.isDisabled(), true); assert.equal(await copy.isChecked(), false);}
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.getByRole('button', {name: 'Continue to Payment', exact: true}).click();
            assert.equal(await page.locator('input:invalid').count() > 0, true);
            assert.equal(mutationAttempts, 0, 'Invalid form must not submit even to the synthetic API');
            assert.deepEqual(errors, []);
            console.log(JSON.stringify({width, quantity, hydratedBuiltApp: true, compactIndicators: true, labelTargets44: true, copyEligibilityAndReset: true, marketingDefaultFalse: true, keyboardAndLabelClick: true, externalNetworkBlocked: true}));
            await page.close();
        }
    }
} finally {if (browser) await browser.close(); await new Promise(r => server.close(r));}
