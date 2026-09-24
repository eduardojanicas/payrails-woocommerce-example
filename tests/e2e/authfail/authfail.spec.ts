/**
 * Auth / config failure paths. Nothing here reaches Payrails:
 *  - "net": the server runs with PAYRAILS_API_URL=https://unreachable.payrails.invalid:9 (env
 *    overrides the file's API_URL), so the token call cannot resolve → PR-NET-6.
 *  - "config": wp-config's PAYRAILS_SECRETS_FILE points at a nonexistent file → PR-CONFIG,
 *    the gateway hides itself. The original path is restored in afterAll (and again by
 *    global-teardown as a safety net).
 */
import { test, expect, type Page } from '@playwright/test';
import { ensureServer, order, createOrder, secretsFileConstant, setSecretsFileConstant, secretValuesForLeakCheck, ARTIFACTS, ROOT } from '../helpers/wp';
import { addToCart, fillCheckout, placeOrder, waitState, adminLogin } from '../helpers/shop';

async function expectSafeError(page: Page, code: RegExp) {
	await waitState(page, ['error'], 20000);
	const st = page.locator('#woo-payrails-pay-status');
	await expect(st).toHaveAttribute('role', 'alert');
	await expect(st).toContainText('Payment is unavailable right now');
	await expect(st).toContainText("you haven't been charged");
	await expect(st.locator('code')).toHaveText(code);
	await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
	await expect(page.getByRole('link', { name: 'Return to cart' })).toBeVisible();
	await expect(page.locator('#woo-payrails-dropin iframe')).toHaveCount(0);
	await expect(page.locator('#woo-payrails-client-init')).toHaveCount(0);
	const html = await page.content();
	expect(secretValuesForLeakCheck().filter(s => html.includes(s)).length, 'secret values in page HTML').toBe(0);
	expect(html).not.toContain('.secrets/');
	expect(html).not.toMatch(/Bearer |x-api-key|access_token/i);
}

test.describe.serial('authfail: API unreachable (PR-NET)', () => {
	test.beforeAll(() => ensureServer('authfail-net'));

	test('checkout → pay page shows the safe error, order stays pending, confirm cannot mark it paid', async ({ page }) => {
		await addToCart(page, 'organic-cotton-tee');
		await fillCheckout(page);
		const id = await placeOrder(page);
		await expectSafeError(page, /^PR-NET-\d+$/);
		await page.screenshot({ path: `${ARTIFACTS}/state-error-net.png`, fullPage: true });
		const o = order(id);
		expect(o.status).toBe('pending');
		expect(o.paid).toBe(false);
		expect(o.txn).toBe('');
		expect(o.noteTexts.filter(n => /Payrails: .*failed \(PR-NET-\d+\)/.test(n)).length).toBe(1);
		// The confirm endpoint has no execution to read for this order: 400, never paid.
		const C = await page.evaluate(() => (window as any).PayrailsWooPay);
		const r = await page.request.post(new URL(C.confirmUrl, page.url()).toString(), { form: { order_id: String(id), order_key: C.orderKey, nonce: C.nonce } });
		expect(r.status()).toBe(400);
		expect((await r.json()).code).toBe('unknown_execution');
		// Reload: still safe, and the error note is not duplicated.
		await page.reload();
		await expectSafeError(page, /^PR-NET-\d+$/);
		expect(order(id).notes).toBe(o.notes);
		// Try again reloads and keeps the safe error.
		await page.getByRole('button', { name: 'Try again' }).click();
		await expectSafeError(page, /^PR-NET-\d+$/);
		expect(order(id).status).toBe('pending');
	});
});

test.describe.serial('authfail: secrets file missing (PR-CONFIG)', () => {
	let original = '';
	test.beforeAll(() => {
		ensureServer('live');
		original = secretsFileConstant();
		expect(original).toMatch(/payrails\.env$/);
	});
	test.afterAll(async () => {
		if (original) setSecretsFileConstant(original);
		expect(secretsFileConstant()).toBe(original);
		await new Promise(r => setTimeout(r, 2500)); // opcache revalidation window
	});

	test('gateway hidden at checkout; existing order pay page shows PR-CONFIG; admin names the missing file', async ({ page, browser }) => {
		const o = createOrder([{ sku: '', qty: 1 }]); // created before the break, so no client-init has happened
		setSecretsFileConstant(`${ROOT}/.secrets/nonexistent-test.env`);
		// PHP's opcache revalidates wp-config.php every 2 s: wait until the Store API stops offering the gateway.
		await expect
			.poll(async () => ((await (await page.request.get('/wp-json/wc/store/v1/cart')).json()).payment_methods ?? []).includes('payrails'), { timeout: 15000 })
			.toBe(false);

		await addToCart(page, 'organic-cotton-tee');
		await page.goto('/checkout/');
		await page.waitForSelector('#email');
		await page.waitForTimeout(2500);
		await expect(page.locator('.payrails-block-desc')).toHaveCount(0);
		const methods = await page.locator('.wc-block-checkout__payment-method').innerText().catch(() => '');
		expect(methods).not.toMatch(/Pay securely by card/);
		await expect(page.locator('.wc-block-components-notice-banner__content').filter({ hasText: 'There are no payment methods available' })).toBeVisible();
		await page.screenshot({ path: `${ARTIFACTS}/authfail-checkout-no-card.png`, fullPage: true });

		const shopper = await browser.newPage();
		await shopper.goto(o.payUrl);
		await expectSafeError(shopper, /^PR-CONFIG$/);
		await shopper.screenshot({ path: `${ARTIFACTS}/state-error-config.png`, fullPage: true });
		await shopper.close();
		const after = order(o.id);
		expect(after.status).toBe('pending');
		expect(after.paid).toBe(false);
		expect(after.exec).toBe('');

		const admin = await browser.newPage();
		await adminLogin(admin);
		await admin.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payrails');
		await expect(admin.locator('body')).toContainText('PAYRAILS_SECRETS_FILE (not readable)');
		await expect(admin.getByText('Payrails is not configured:').first()).toBeVisible();
		await admin.close();
	});
});
