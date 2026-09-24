/**
 * Real Payrails staging (test cards, nothing charged). About 8 authorizations per run.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ensureServer, order, wpEval, ARTIFACTS } from '../helpers/wp';
import { CARDS, addToCart, fillCheckout, placeOrder, toPayPage, waitState, payCard, do3ds, adminLogin, expectNoHorizontalScroll } from '../helpers/shop';

test.beforeAll(() => ensureServer('live'));

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

function expectPaid(id: number) {
	const o = order(id);
	expect(o.status).toBe('processing');
	expect(o.txn).toMatch(UUID);
	expect(o.txn).toBe(o.exec);
	return o;
}

test.describe('staging (real Payrails)', () => {
	test('card 4242: loading → ready → processing → success → order-received; replay is idempotent; admin shows txn', async ({ page, browser }) => {
		const states: string[] = [];
		await page.exposeFunction('__qaState', (s: string) => states.push(s));
		await page.addInitScript(() => {
			// Record every data-state value, including ones overwritten within the same task.
			const seen = (s: string | null) => {
				if (s && (window as any).__qaLast !== s) {
					(window as any).__qaLast = s;
					(window as any).__qaState(s);
				}
			};
			new MutationObserver(recs => {
				for (const r of recs) {
					const t = r.target as Element;
					if (r.type === 'attributes' && t.id === 'woo-payrails-pay') {
						seen(r.oldValue);
						seen(t.getAttribute('data-state'));
					}
				}
				seen(document.querySelector('#woo-payrails-pay')?.getAttribute('data-state') ?? null);
			}).observe(document, { subtree: true, attributes: true, attributeOldValue: true, attributeFilter: ['data-state'], childList: true });
		});
		const id = await toPayPage(page, 'organic-cotton-tee');
		await waitState(page, ['ready'], 45000);
		await expect(page.locator('iframe[title="Payrails Card Form"]')).toBeVisible();
		// Cards only: wallet rows hidden.
		const wallets = page.locator('#woo-payrails-dropin').getByText(/google pay|apple pay/i);
		for (const w of await wallets.all()) await expect(w).toBeHidden();
		await page.screenshot({ path: `${ARTIFACTS}/staging-pay-ready.png`, fullPage: true });
		const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).exclude('iframe').analyze();
		// The SDK's logo <img>s get alt="" from pay.js, so no allowlist is needed.
		const bad = axe.violations.filter(v => v.impact === 'serious' || v.impact === 'critical');
		test.info().annotations.push({ type: 'axe-staging-pay', description: JSON.stringify(axe.violations.map(v => `${v.impact}:${v.id}`)) });
		expect(bad.map(v => `${v.impact}: ${v.id} ${v.nodes.map(n => n.target.join(' ')).slice(0, 3).join(', ')}`)).toEqual([]);
		const C = await page.evaluate(() => (window as any).PayrailsWooPay);
		await payCard(page, CARDS.ok);
		await page.waitForURL(/order-received/, { timeout: 90000 });
		expect(states).toContain('loading');
		expect(states).toContain('ready');
		test.info().annotations.push({ type: 'observed', description: `state sequence: ${states.join(' → ')}` });
		expect(states.some(s => s === 'processing' || s === 'pending'), 'an in-flight state is shown after Pay').toBe(true);
		expect(states.some(s => s === 'success')).toBe(true);
		const paid = expectPaid(id);
		const r = await page.request.post(new URL(C.confirmUrl, page.url()).toString(), { form: { order_id: String(id), order_key: C.orderKey, nonce: C.nonce, execution_id: C.executionId } });
		expect((await r.json()).state).toBe('authorized');
		expect(order(id).notes).toBe(paid.notes);
		// Reload of the pay URL after paying → order-received.
		await page.goto(`/checkout/order-pay/${id}/?key=${C.orderKey}`);
		await expect(page).toHaveURL(/order-received/);

		const admin = await browser.newPage();
		await adminLogin(admin);
		await admin.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${id}`);
		await expect(admin.locator('.woocommerce-order-data__meta')).toContainText(paid.txn);
		await expect(admin.locator('.order_notes')).toContainText(`Payrails authorized ${paid.txn}`);
		await admin.screenshot({ path: `${ARTIFACTS}/staging-admin-order.png`, fullPage: true });
		await admin.close();
	});

	test('pay page at 375px with the live Drop-in (no payment)', async ({ browser }) => {
		const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
		const page = await ctx.newPage();
		await toPayPage(page, 'cashmere-scarf');
		await waitState(page, ['ready'], 45000);
		await page.waitForTimeout(2500);
		await expectNoHorizontalScroll(page);
		await page.screenshot({ path: `${ARTIFACTS}/375-pay-staging.png`, fullPage: true });
		await ctx.close();
	});

	test('3DS challenge → Complete → processing', async ({ page }) => {
		test.skip(!CARDS.threeDS, 'set E2E_CARD_3DS to a 3DS-challenge test card for your processor');
		const id = await toPayPage(page, 'organic-cotton-tee');
		await payCard(page, CARDS.threeDS);
		const how = await do3ds(page, 'complete');
		test.info().annotations.push({ type: 'observed', description: `3DS path: ${how}` });
		await page.waitForURL(/order-received/, { timeout: 90000 });
		expectPaid(id);
	});

	test('3DS challenge → Fail → declined state, order failed, not paid', async ({ page }) => {
		test.skip(!CARDS.threeDS, 'set E2E_CARD_3DS to a 3DS-challenge test card for your processor');
		const id = await toPayPage(page, 'organic-cotton-tee');
		await payCard(page, CARDS.threeDS);
		const how = await do3ds(page, 'fail');
		test.info().annotations.push({ type: 'observed', description: `3DS path: ${how}` });
		const st = await waitState(page, ['failed', 'error', 'unresolved'], 90000);
		await page.screenshot({ path: `${ARTIFACTS}/staging-3ds-failed.png`, fullPage: true });
		expect(st).toBe('failed');
		await expect(page.locator('#woo-payrails-pay-status')).toContainText('Payment declined');
		const o = order(id);
		expect(o.status).toBe('failed');
		expect(o.paid).toBe(false);
		expect(o.noteTexts.join('\n')).toMatch(/authorizeFailed/);
	});

	test('decline → Payment declined → Try again → 4242 → processing on the same order', async ({ page }) => {
		test.skip(!CARDS.decline, 'set E2E_CARD_DECLINE to a decline test card for your processor');
		const id = await toPayPage(page, 'organic-cotton-tee');
		await payCard(page, CARDS.decline);
		await waitState(page, ['failed'], 60000);
		await expect(page.locator('#woo-payrails-pay-status')).toContainText('Payment declined');
		await expect(page.locator('#woo-payrails-dropin')).toBeHidden();
		await page.screenshot({ path: `${ARTIFACTS}/staging-declined.png`, fullPage: true });
		expect(order(id).status).toBe('failed');
		await page.getByRole('button', { name: 'Try again' }).click();
		await payCard(page, CARDS.ok);
		await page.waitForURL(/order-received/, { timeout: 90000 });
		const o = expectPaid(id);
		expect(o.executions).toBe(2);
	});

	test('logged-in customer + fixed-cart coupon with uneven line split: Payrails accepts and verifies the amount', async ({ page }) => {
		test.setTimeout(240_000);
		const t0 = Date.now();
		const mark = (s: string) => test.info().annotations.push({ type: 'timing', description: `${s} +${((Date.now() - t0) / 1000).toFixed(1)}s` });
		wpEval(`if ( ! wc_get_coupon_id_by_code( 'test7off' ) ) { $c = new WC_Coupon(); $c->set_code( 'test7off' ); $c->set_discount_type( 'fixed_cart' ); $c->set_amount( 7 ); $c->save(); } if ( ! get_user_by( 'login', 'test_customer' ) ) { wc_create_new_customer( 'test.customer@example.com', 'test_customer', 'test-pass-123' ); } $u = get_user_by( 'login', 'test_customer' ); delete_user_meta( $u->ID, '_woocommerce_persistent_cart_' . get_current_blog_id() ); ( new WC_Session_Handler() )->delete_session( (string) $u->ID ); echo 1;`);
		await page.goto('/my-account/');
		await page.fill('#username', 'test_customer');
		await page.fill('#password', 'test-pass-123');
		await page.locator('button[name=login]').click();
		mark('logged in');
		await addToCart(page, 'organic-cotton-tee', 3);
		await addToCart(page, 'cashmere-scarf');
		await page.goto('/cart/');
		await page.getByRole('button', { name: /add coupons/i }).click();
		await page.locator('input[id*="coupon"]').first().fill('test7off');
		await page.getByRole('button', { name: /^apply$/i }).click();
		await expect(page.locator('.wc-block-components-totals-discount')).toBeVisible();
		await fillCheckout(page, 'test.customer@example.com');
		mark('checkout filled');
		const id = await placeOrder(page);
		mark('pay page');
		const st = await waitState(page, ['ready', 'error'], 45000);
		mark('ready');
		expect(st, `pay page state: ${await page.locator('#woo-payrails-pay-status').innerText()}`).toBe('ready');
		expect(order(id).total).toBe('223.00');
		await payCard(page, CARDS.visa);
		mark('paid clicked');
		await page.waitForURL(/order-received/, { timeout: 90000 });
		mark('order-received');
		expectPaid(id);
		expect(Number(wpEval(`echo wc_get_order( ${id} )->get_customer_id();`))).toBeGreaterThan(0);
	});

	test('two tabs on the same order: pay in A, B must not double-charge', async ({ context }) => {
		const a = await context.newPage();
		const id = await toPayPage(a, 'organic-cotton-tee');
		await waitState(a, ['ready'], 45000);
		const b = await context.newPage();
		await b.goto(a.url());
		await waitState(b, ['ready'], 45000);
		await payCard(a, CARDS.ok);
		await a.waitForURL(/order-received/, { timeout: 90000 });
		const paid = expectPaid(id);
		// Tab B still shows the old Drop-in on the same (now authorized) execution. Try to pay again.
		await payCard(b, CARDS.ok).catch(() => {});
		const st = await Promise.race([
			b.waitForURL(/order-received/, { timeout: 60000 }).then(() => 'order-received'),
			waitState(b, ['failed', 'error', 'unresolved'], 60000),
		]).catch(() => 'timeout');
		await b.screenshot({ path: `${ARTIFACTS}/staging-two-tabs-b.png`, fullPage: true }).catch(() => {});
		test.info().annotations.push({ type: 'observed', description: `tab B outcome: ${st}` });
		const after = order(id);
		expect(after.status).toBe('processing');
		expect(after.executions).toBe(paid.executions);
		expect(after.noteTexts.filter(n => /Payrails authorized/.test(n)).length).toBe(1);
	});
});
