// Manual ZIP update through the real WordPress upload screen ("Replace current with uploaded").
const { chromium } = require('playwright');
(async () => {
	const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' });
	const page = await browser.newPage();
	await page.goto('http://127.0.0.1:8890/wp-login.php');
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'admin-pass-123');
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
	await page.goto('http://127.0.0.1:8890/wp-admin/plugin-install.php?tab=upload');
	await page.setInputFiles('#pluginzip', process.argv[2]);
	await Promise.all([page.waitForNavigation(), page.click('#install-plugin-submit')]);
	const text = await page.textContent('.wrap');
	console.log('UPLOAD_SCREEN: ' + (text.includes('already installed') ? 'replace offered' : 'no replace prompt'));
	const replace = await page.$('a.update-from-upload-overwrite');
	if (replace) {
		await Promise.all([page.waitForNavigation(), replace.click()]);
		const done = await page.textContent('.wrap');
		console.log('REPLACE_RESULT: ' + (done.includes('Plugin updated successfully') ? 'updated' : done.replace(/\s+/g, ' ').slice(0, 300)));
	}
	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
