// Browser end-to-end tests for Chandan Digital AI for NVIDIA (test-only, not shipped).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8890';
const ADMIN = BASE + '/wp-admin/admin.php?page=chandan-digital-ai-nvidia';
const SHOTS = process.env.SHOTS_DIR;
const KEY = 'nvapi-E2eSecretKey_ABC123xyz';
let pass = 0;
const fails = [];
function check(name, cond, info) {
	if (cond) { pass++; console.log('PASS  ' + name); }
	else { fails.push(name); console.log('FAIL  ' + name + (info ? '  [' + info + ']' : '')); }
}

async function login(page, user, pass) {
	await page.goto(BASE + '/wp-login.php');
	await page.fill('#user_login', user);
	await page.fill('#user_pass', pass);
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
}

async function shot(page, name, fullPage = true) {
	if (SHOTS) { await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage }); }
}

(async () => {
	const exe = '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell';
	const browser = await chromium.launch({ executablePath: exe });
	const context = await browser.newContext({ viewport: { width: 1360, height: 900 } });
	await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
	const page = await context.newPage();
	const consoleErrors = [];
	// Network failures are reported separately; deliberate 400/409 test calls and WordPress core's
	// Gravatar requests (blocked in this sandbox) are not JavaScript errors.
	page.on('console', (m) => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) consoleErrors.push(m.text()); });
	page.on('pageerror', (e) => consoleErrors.push(e.message));
	const externalRequests = [];
	// WordPress core loads admin-bar avatars from Gravatar; everything else must stay on this site.
	page.on('request', (r) => { const u = new URL(r.url()); if (u.protocol !== 'blob:' && u.hostname !== '127.0.0.1' && u.hostname !== 'secure.gravatar.com') externalRequests.push(r.url()); });

	await login(page, 'admin', 'admin-pass-123');

	// Overview
	await page.goto(ADMIN);
	check('overview heading', (await page.textContent('.cdnv-brand__title')).includes('Chandan Digital AI for NVIDIA'));
	check('developer + website shown', (await page.textContent('.cdnv-brand__meta')).includes('Chandan Digital') && (await page.textContent('.cdnv-brand__meta')).includes('chandandigital.com'));
	check('seven tabs', (await page.$$('.cdnv-tabs .nav-tab')).length === 7);
	await shot(page, '01-overview-fresh');

	// API settings: save key
	await page.goto(ADMIN + '&tab=api');
	await page.fill('#cdnv-api-key', KEY);
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][name=submit]')]);
	check('key saved notice', (await page.textContent('.cdnv-notice')).includes('API key was saved'));
	const apiHtml = await page.content();
	check('full key never rendered in HTML', !apiHtml.includes(KEY) && !apiHtml.includes('E2eSecretKey'));
	check('masked key shown', apiHtml.includes('nvapi-••••••••3xyz'));
	check('key field left empty after save', (await page.inputValue('#cdnv-api-key')) === '');
	// Invalid base URL is rejected and the old one kept.
	await page.fill('#cdnv-base-url', 'http://evil.example.com/v1');
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][name=submit]')]);
	check('http base URL rejected', (await page.textContent('.cdnv-notice')).includes('https://') && (await page.inputValue('#cdnv-base-url')) === 'https://integrate.api.nvidia.com/v1');
	// Connection test (uses the mock through the test-only base URL filter).
	await page.click('[data-target="#cdnv-api-test"]');
	await page.waitForSelector('#cdnv-api-test .cdnv-summary', { timeout: 30000 });
	const testText = await page.textContent('#cdnv-api-test');
	check('connection test: endpoint, key, model all OK', (await page.$$('#cdnv-api-test .cdnv-pill--ok')).length === 3 && testText.includes('Connection works'), testText);
	await shot(page, '02-api-settings-tested');
	const html2 = await page.content();
	check('key not exposed in JS config', !html2.includes('E2eSecretKey'));

	// Models
	await page.goto(ADMIN + '&tab=models');
	const rows = await page.$$('[data-cdnv-model-row]');
	check('model manager lists 49 models', rows.length === 49, String(rows.length));
	await page.fill('#cdnv-model-search', 'kimi');
	const visible = await page.$$eval('[data-cdnv-model-row]', (rs) => rs.filter((r) => !r.hidden).length);
	check('search filters to Kimi models', visible === 2, String(visible));
	await page.fill('#cdnv-model-search', 'reasoning vision');
	const visible2 = await page.$$eval('[data-cdnv-model-row]', (rs) => rs.filter((r) => !r.hidden).map((r) => r.querySelector('code').textContent));
	check('capability search (reasoning + vision) -> Kimi K3', visible2.length === 1 && visible2[0] === 'moonshotai/kimi-k3', visible2.join(','));
	await page.click('[data-cdnv-action="refresh-models"]');
	await page.waitForSelector('#cdnv-refresh-result .cdnv-summary', { timeout: 30000 });
	const refreshText = await page.textContent('#cdnv-refresh-result');
	check('refresh models shows count', refreshText.includes('8 models'), refreshText.slice(0, 120));
	await page.click('#cdnv-refresh-result summary');
	const addButtons = await page.$$('#cdnv-refresh-result .cdnv-catalog-list button');
	check('unregistered catalogue models listed', addButtons.length === 2);
	await addButtons[0].click();
	check('add-as-custom fills form', (await page.inputValue('#cdnv-custom-id')) === 'acme/new-model-1');
	await page.fill('#cdnv-custom-name', 'New Model One');
	await Promise.all([page.waitForNavigation(), page.click('#cdnv-add-custom input[type=submit]')]);
	check('custom model added', (await page.textContent('.cdnv-notice')).includes('Custom model added'));
	check('custom row present', (await page.$$('[data-cdnv-model-row]')).length === 50);
	// Check Kimi access from the table.
	await page.fill('#cdnv-model-search', 'kimi-k3');
	await page.click('[data-cdnv-action="verify-model"][data-model="moonshotai/kimi-k3"]');
	await page.waitForFunction(() => document.querySelector('[data-cdnv-access="moonshotai/kimi-k3"]').textContent.includes('Access confirmed'), null, { timeout: 30000 });
	check('Kimi K3 access confirmed in table', true);
	await page.fill('#cdnv-model-search', '');
	await shot(page, '03-models');
	// Disable a model and change the default, then confirm persistence.
	await page.uncheck('input[name="enabled[meta/llama-3.2-1b-instruct]"]');
	await page.check('input[name="default_model"][value="meta/llama-3.3-70b-instruct"]');
	await Promise.all([page.waitForNavigation(), page.click('form input[type=submit][value="Save model choices"]')]);
	check('model choices saved', (await page.textContent('.cdnv-notice')).includes('Model choices saved'));
	check('disabled model persisted', !(await page.isChecked('input[name="enabled[meta/llama-3.2-1b-instruct]"]')));
	check('default model persisted', await page.isChecked('input[name="default_model"][value="meta/llama-3.3-70b-instruct"]'));
	// Set Kimi back as default.
	await page.check('input[name="default_model"][value="moonshotai/kimi-k3"]');
	await Promise.all([page.waitForNavigation(), page.click('form input[type=submit][value="Save model choices"]')]);
	// Remove the custom model (confirm dialog).
	page.once('dialog', (d) => d.accept());
	await Promise.all([page.waitForNavigation(), page.click('button[form^="cdnv-remove-"]')]);
	check('custom model removed', (await page.textContent('.cdnv-notice')).includes('Custom model removed'));

	// Model-specific settings for a non-Kimi model.
	await page.goto(ADMIN + '&tab=models&configure=meta/llama-3.3-70b-instruct');
	check('model settings page', (await page.textContent('h2')).includes('Llama 3.3 70B Instruct'));
	const reasoningDisabled = await page.$$eval('#cdnv-reasoning option', (o) => o.filter((x) => x.disabled).length);
	check('reasoning options disabled for model without control', reasoningDisabled === 4);

	// Kimi K3 settings
	await page.goto(ADMIN + '&tab=kimi');
	check('kimi tab shows exact model id', (await page.content()).includes('moonshotai/kimi-k3'));
	check('kimi temperature default 1', (await page.inputValue('#cdnv-temperature')) === '1');
	check('kimi max tokens default 16384', (await page.inputValue('#cdnv-max-tokens')) === '16384');
	check('kimi reasoning default max', (await page.inputValue('#cdnv-reasoning')) === 'max');
	check('kimi seed default 0', (await page.inputValue('#cdnv-seed')) === '0');
	check('kimi medium disabled', await page.$eval('#cdnv-reasoning option[value=medium]', (o) => o.disabled));
	check('kimi streaming + images on', (await page.isChecked('input[name=stream]')) && (await page.isChecked('input[name=images]')));
	await shot(page, '04-kimi-settings');
	await page.selectOption('#cdnv-reasoning', 'high');
	await page.fill('#cdnv-max-tokens', '8192');
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][value="Save model settings"]')]);
	check('kimi settings saved', (await page.textContent('.cdnv-notice')).includes('Model settings saved') && (await page.inputValue('#cdnv-reasoning')) === 'high' && (await page.inputValue('#cdnv-max-tokens')) === '8192');
	// Invalid value: server-side validation keeps previous value.
	await page.$eval('#cdnv-max-tokens', (e) => { e.removeAttribute('max'); e.value = '2000000'; });
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][value="Save model settings"]')]);
	check('out-of-range max tokens rejected, value kept', (await page.textContent('.cdnv-notice')).includes('not saved') && (await page.inputValue('#cdnv-max-tokens')) === '8192');

	// Playground: streaming text, multi-turn with reasoning passback.
	await page.goto(ADMIN + '&tab=playground');
	check('playground default model is Kimi K3', (await page.inputValue('#cdnv-pg-model')) === 'moonshotai/kimi-k3');
	check('stream checkbox follows model setting', await page.isChecked('#cdnv-pg-stream'));
	check('image controls visible for Kimi', await page.isVisible('#cdnv-pg-images'));
	await page.fill('#cdnv-pg-prompt', 'Write one line about Kolkata.');
	const t0 = Date.now();
	await page.click('#cdnv-pg-send');
	// Text must start appearing before the response is finished (incremental rendering).
	await page.waitForFunction(() => { const b = document.querySelector('.cdnv-msg--assistant .cdnv-msg__body'); return b && !b.querySelector('.cdnv-msg__thinking') && b.textContent.length > 0; }, null, { timeout: 30000 });
	const partialLen = await page.$eval('.cdnv-msg--assistant .cdnv-msg__body', (b) => b.textContent.length);
	await page.waitForSelector('.cdnv-msg--assistant .cdnv-msg__meta button', { timeout: 60000 });
	const fullText = await page.$eval('.cdnv-msg--assistant .cdnv-msg__body', (b) => b.textContent);
	check('streamed text rendered incrementally', partialLen < fullText.length, partialLen + ' < ' + fullText.length);
	check('streamed reply complete', fullText.startsWith('Hello from moonshotai/kimi-k3. You said: Write one line about Kolkata.'), fullText);
	check('reasoning shown separately', (await page.textContent('.cdnv-msg__reasoning-body')).includes('step by step'));
	check('usage + time shown', (await page.textContent('.cdnv-msg__meta')).includes('42 prompt + 17 output tokens'));
	await page.click('.cdnv-msg--assistant .cdnv-msg__meta button');
	await page.waitForTimeout(300);
	const clip = await page.evaluate(() => navigator.clipboard.readText());
	check('copy response copies answer text', clip === fullText);
	await page.fill('#cdnv-pg-prompt', 'And one more?');
	await page.keyboard.press('Control+Enter');
	await page.waitForFunction(() => document.querySelectorAll('.cdnv-msg--assistant .cdnv-msg__meta button').length === 2, null, { timeout: 60000 });
	const second = await page.$$eval('.cdnv-msg--assistant .cdnv-msg__body', (b) => b[1].textContent);
	check('multi-turn: turn 2 with reasoning passed back', second.includes('Turn 2, reasoning passed back: 1'), second);
	await shot(page, '05-playground-stream');

	// Non-streaming mode
	await page.click('#cdnv-pg-clear');
	await page.uncheck('#cdnv-pg-stream');
	await page.fill('#cdnv-pg-prompt', 'Non streaming please');
	await page.click('#cdnv-pg-send');
	await page.waitForSelector('.cdnv-msg--assistant .cdnv-msg__meta button', { timeout: 60000 });
	check('non-streaming reply', (await page.textContent('.cdnv-msg--assistant .cdnv-msg__body')).includes('Non streaming please'));

	// Image upload (PNG) + image URL
	await page.click('#cdnv-pg-clear');
	await page.check('#cdnv-pg-stream');
	const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
	await page.setInputFiles('#cdnv-pg-file', { name: 'dot.png', mimeType: 'image/png', buffer: png });
	await page.waitForSelector('.cdnv-chat__pending-item');
	await page.fill('#cdnv-pg-url', 'https://example.com/photo.jpg');
	await page.click('#cdnv-pg-url-add');
	check('two pending images', (await page.$$('.cdnv-chat__pending-item')).length === 2);
	await page.fill('#cdnv-pg-prompt', 'What is in these images?');
	await page.click('#cdnv-pg-send');
	await page.waitForSelector('.cdnv-msg--assistant .cdnv-msg__meta button', { timeout: 60000 });
	const imgReply = await page.textContent('.cdnv-msg--assistant .cdnv-msg__body');
	check('image upload + URL analysed', imgReply.includes('I can see 2 image(s)'), imgReply);
	check('multibyte text intact through stream', imgReply.includes('नमस्ते'));
	await shot(page, '06-playground-images');
	// Unsupported format client-side and server-side
	await page.setInputFiles('#cdnv-pg-file', { name: 'x.gif', mimeType: 'image/gif', buffer: Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64') });
	check('GIF rejected in browser', (await page.textContent('#cdnv-pg-status')).includes('not enabled'));
	await page.fill('#cdnv-pg-url', 'http://example.com/a.jpg');
	await page.click('#cdnv-pg-url-add');
	check('http image URL rejected in browser', (await page.textContent('#cdnv-pg-status')).includes('https://'));
	// Server-side SSRF guard (bypassing the browser check)
	const ssrf = await page.evaluate(async () => {
		const r = await fetch(window.cdnvConfig.restUrl + 'chat', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.cdnvConfig.nonce }, body: JSON.stringify({ model: 'moonshotai/kimi-k3', request_id: crypto.randomUUID(), messages: [{ role: 'user', text: 'x', images: [{ type: 'url', value: 'https://10.0.0.5/secret.png' }] }] }) });
		return { status: r.status, body: await r.json() };
	});
	check('server rejects private-network image URL', ssrf.status === 400 && ssrf.body.error.code === 'invalid_image', JSON.stringify(ssrf));
	const spoof = await page.evaluate(async () => {
		const fake = 'data:image/png;base64,' + btoa('<?php phpinfo(); ?>');
		const r = await fetch(window.cdnvConfig.restUrl + 'chat', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.cdnvConfig.nonce }, body: JSON.stringify({ model: 'moonshotai/kimi-k3', request_id: crypto.randomUUID(), messages: [{ role: 'user', text: 'x', images: [{ type: 'data', value: fake }] }] }) });
		return { status: r.status, body: await r.json() };
	});
	check('server rejects disguised non-image upload', spoof.status === 400, JSON.stringify(spoof));
	// Duplicate request ID rejected
	const dup = await page.evaluate(async () => {
		const id = crypto.randomUUID();
		const send = () => fetch(window.cdnvConfig.restUrl + 'chat', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.cdnvConfig.nonce }, body: JSON.stringify({ model: 'moonshotai/kimi-k3', request_id: id, messages: [{ role: 'user', text: 'dup' }] }) }).then((r) => r.status);
		const a = await send();
		const b = await send();
		return [a, b];
	});
	check('duplicate request id rejected (409)', dup[0] === 200 && dup[1] === 409, dup.join(','));

	// Stop button cancels a stream
	await page.click('#cdnv-pg-clear');
	await page.fill('#cdnv-pg-prompt', 'This will be stopped');
	await page.click('#cdnv-pg-send');
	await page.waitForFunction(() => { const b = document.querySelector('.cdnv-msg--assistant .cdnv-msg__reasoning-body'); return b && b.textContent.length > 0; }, null, { timeout: 30000 });
	await page.click('#cdnv-pg-stop');
	await page.waitForSelector('.cdnv-msg__note--warn', { timeout: 10000 });
	check('stop cancels and marks reply', (await page.textContent('.cdnv-msg__note--warn')).includes('Stopped'));
	check('buttons re-enabled after stop', !(await page.isDisabled('#cdnv-pg-send')));

	// Error display: model without access
	await page.click('#cdnv-pg-clear');
	await page.goto(ADMIN + '&tab=models');
	await page.fill('#cdnv-custom-id', 'acme/no-access');
	await Promise.all([page.waitForNavigation(), page.click('#cdnv-add-custom input[type=submit]')]);
	await page.goto(ADMIN + '&tab=playground');
	await page.selectOption('#cdnv-pg-model', 'acme/no-access');
	await page.fill('#cdnv-pg-prompt', 'hello');
	await page.click('#cdnv-pg-send');
	await page.waitForSelector('.cdnv-error', { timeout: 30000 });
	const errText = await page.textContent('.cdnv-error');
	check('model-access error is distinct from key error', errText.includes('not available to your NVIDIA account') && errText.includes('Your API key may be fine') && errText.includes('404'), errText);
	await shot(page, '07-playground-error');

	// Diagnostics: streaming test + connection test with model choice
	await page.goto(ADMIN + '&tab=diagnostics');
	await page.click('[data-cdnv-action="stream-test"]');
	await page.waitForSelector('#cdnv-diag-stream .cdnv-summary', { timeout: 30000 });
	const streamResult = await page.textContent('#cdnv-diag-stream');
	check('streaming self-test reports a verdict', /Streaming works|buffered/.test(streamResult), streamResult);
	console.log('      stream test said: ' + streamResult.trim().slice(0, 140));
	await shot(page, '08-diagnostics');

	// Privacy: logging on, request, entries visible without secrets, clear logs.
	await page.goto(ADMIN + '&tab=privacy');
	await page.check('input[name=logging]');
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][value="Save privacy and security settings"]')]);
	await page.goto(ADMIN + '&tab=playground');
	await page.fill('#cdnv-pg-prompt', 'log me privately');
	await page.click('#cdnv-pg-send');
	await page.waitForSelector('.cdnv-msg--assistant .cdnv-msg__meta button', { timeout: 60000 });
	await page.goto(ADMIN + '&tab=diagnostics');
	const logHtml = await page.content();
	check('log entry recorded', logHtml.includes('playground_stream'));
	check('log has no prompt by default', !logHtml.includes('log me privately'));
	check('log has no key', !logHtml.includes('E2eSecretKey'));
	await page.goto(ADMIN + '&tab=privacy');
	await shot(page, '09-privacy');
	page.once('dialog', (d) => d.accept());
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][value="Clear logs"]')]);
	check('logs cleared', (await page.textContent('.cdnv-notice')).includes('Local logs cleared'));

	// Plugins screen: manual updates only.
	await page.goto(BASE + '/wp-admin/plugins.php');
	const row = await page.textContent('tr[data-slug="chandan-digital-ai-for-nvidia"]');
	check('plugins screen says manual updates only', row.includes('Manual updates only'));
	check('settings link on plugins screen', row.includes('Settings'));

	// Mobile layout screenshot
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto(ADMIN + '&tab=playground');
	await shot(page, '10-mobile-playground');
	for (const tab of ['overview', 'api', 'models', 'kimi', 'playground', 'diagnostics', 'privacy']) {
		await page.goto(ADMIN + '&tab=' + tab);
		const fits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
		check('no horizontal page overflow at 390px: ' + tab, fits);
	}
	await page.goto(ADMIN + '&tab=models');
	await shot(page, '11-mobile-models');
	await page.setViewportSize({ width: 1360, height: 900 });

	check('no JavaScript console errors', consoleErrors.length === 0, consoleErrors.join(' | '));
	check('no external requests from plugin screens (WordPress core avatars excluded)', externalRequests.length === 0, externalRequests.join(', '));

	// Security: unauthenticated and nonce-less REST calls.
	const anon = await browser.newContext();
	const ap = await anon.newPage();
	const anonRes = await ap.request.post(BASE + '/?rest_route=/chandan-digital-ai/v1/chat', { data: { model: 'moonshotai/kimi-k3', request_id: 'abcdefgh-1234', messages: [{ role: 'user', text: 'x' }] } });
	check('anonymous REST chat refused', anonRes.status() === 401, String(anonRes.status()));
	const anonTest = await ap.request.post(BASE + '/?rest_route=/chandan-digital-ai/v1/connection-test');
	check('anonymous connection test refused', anonTest.status() === 401, String(anonTest.status()));
	const noNonce = await page.evaluate(async () => (await fetch(window.cdnvConfig.restUrl + 'models/refresh', { method: 'POST', credentials: 'same-origin' })).status);
	check('logged-in request without nonce refused (CSRF)', noNonce === 401 || noNonce === 403, String(noNonce));
	await anon.close();

	// Editor access
	const ed = await browser.newContext({ viewport: { width: 1360, height: 900 } });
	const ep = await ed.newPage();
	await login(ep, 'editor1', 'editor-pass-123');
	await ep.goto(ADMIN);
	check('editor blocked when Playground is admin-only', (await ep.content()).includes('not allowed') || (await ep.content()).includes('permission'));
	await page.goto(ADMIN + '&tab=privacy');
	await page.check('input[name=playground_access][value=editor]');
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][value="Save privacy and security settings"]')]);
	await ep.goto(ADMIN + '&tab=api');
	check('editor sees only Playground tab', (await ep.$$('.cdnv-tabs .nav-tab')).length === 1 && (await ep.inputValue('#cdnv-pg-model')) === 'moonshotai/kimi-k3');
	const edAdminApi = await ep.evaluate(async () => (await fetch(window.cdnvConfig.restUrl + 'connection-test', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': window.cdnvConfig.nonce } })).status);
	check('editor cannot call admin REST routes', edAdminApi === 403, String(edAdminApi));
	const edPost = await ep.request.post(BASE + '/wp-admin/admin-post.php', { form: { action: 'cdnv_save_api', api_key: 'nvapi-hijack-123' } });
	check('editor cannot save API settings', edPost.status() === 403 || (await edPost.text()).includes('permission'), String(edPost.status()));
	await ep.fill('#cdnv-pg-prompt', 'Editor question');
	await ep.click('#cdnv-pg-send');
	await ep.waitForSelector('.cdnv-msg--assistant .cdnv-msg__meta button', { timeout: 60000 });
	check('editor can use Playground when allowed', (await ep.textContent('.cdnv-msg--assistant .cdnv-msg__body')).includes('Editor question'));
	await page.check('input[name=playground_access][value=administrator]');
	await Promise.all([page.waitForNavigation(), page.click('input[type=submit][value="Save privacy and security settings"]')]);
	await ed.close();

	await browser.close();
	console.log('\nRESULT: ' + pass + ' passed, ' + fails.length + ' failed');
	fails.forEach((f) => console.log('  - ' + f));
	process.exit(fails.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
