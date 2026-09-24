/**
 * Confirm-endpoint tampering against real Payrails staging. Needs two real orders
 * with real executions (client-init only): no test-card payment is made here.
 * Replay on a paid order is covered by the card test in staging.spec.ts.
 */
import { test, expect, type Page, type APIRequestContext } from '@playwright/test';
import { ensureServer, order, createOrder, debugLogLines } from '../helpers/wp';
import { waitState } from '../helpers/shop';

test.beforeAll(() => ensureServer('live'));

type Cfg = { confirmUrl: string; nonce: string; orderId: number; orderKey: string; executionId: string };

const cfg = (page: Page): Promise<Cfg> => page.evaluate(() => (window as any).PayrailsWooPay);

async function confirm(req: APIRequestContext, url: string, form: Record<string, string>) {
	const r = await req.post(url, { form });
	let body: any = null;
	try {
		body = await r.json();
	} catch {
		body = { raw: (await r.text()).slice(0, 200) };
	}
	return { status: r.status(), body, headers: r.headers() };
}

test.describe('confirm endpoint tampering (staging, no payment)', () => {
	test('wrong key, other order, foreign/garbage execution, bad nonce, client-sent outcome, GET', async ({ page }) => {
		const logBefore = debugLogLines().length;
		const a = createOrder([{ sku: '', qty: 1 }]);
		const b = createOrder([{ sku: '', qty: 1 }]);
		// Order B gets a real execution too: a "foreign" execution id for A.
		await page.goto(b.payUrl);
		await waitState(page, ['ready']);
		const cb = await cfg(page);
		await page.goto(a.payUrl);
		await waitState(page, ['ready']);
		const c = await cfg(page);
		const url = new URL(c.confirmUrl, page.url()).toString();
		const req = page.request; // same cookies as the page
		const base = { order_id: String(c.orderId), order_key: c.orderKey, nonce: c.nonce, execution_id: c.executionId };

		// Unpaid execution: a normal confirm reads Payrails, answers pending, changes nothing.
		let r = await confirm(req, url, base);
		expect(r.status).toBe(200);
		expect(r.body.state).toBe('pending');
		expect(r.headers['cache-control']).toMatch(/no-store/);

		r = await confirm(req, url, { ...base, order_key: 'wc_order_WRONG' });
		expect(r.status).toBe(404);
		expect(r.body.code).toBe('not_found');

		r = await confirm(req, url, { ...base, order_id: String(b.id), order_key: b.key });
		expect(r.status, 'the nonce is bound to the order id').toBe(403);

		r = await confirm(req, url, { ...base, execution_id: cb.executionId });
		expect(r.status, "another order's execution is never read").toBe(400);
		expect(r.body.code).toBe('unknown_execution');

		r = await confirm(req, url, { ...base, execution_id: '00000000-0000-4000-8000-000000000000' });
		expect(r.status).toBe(400);
		expect(r.body.code).toBe('unknown_execution');

		r = await confirm(req, url, { ...base, execution_id: '../../etc/passwd' });
		expect(r.status, 'a malformed execution id falls back to the stored one').toBe(200);
		expect(r.body.state).toBe('pending');

		r = await confirm(req, url, { ...base, nonce: 'deadbeef00' });
		expect(r.status).toBe(403);
		expect(r.body.code).toBe('stale_session');

		r = await confirm(req, url, { ...base, state: 'authorized', status: 'authorizeSuccessful' });
		expect(r.body.state, 'a client-sent outcome is ignored').toBe('pending');

		const get = await req.get(url + '&order_id=' + c.orderId);
		expect(get.status()).toBe(405);

		for (const id of [a.id, b.id]) {
			const o = order(id);
			expect(o.status).toBe('pending');
			expect(o.paid).toBe(false);
			expect(o.txn).toBe('');
		}
		expect(debugLogLines().slice(logBefore).filter(l => /payrails/i.test(l)), 'no plugin notices in wp-debug.log').toEqual([]);
	});

	test('pay URL with a wrong key does not render the pay panel or create an execution', async ({ page }) => {
		const a = createOrder([{ sku: '', qty: 1 }]);
		await page.goto(a.payUrl.replace(/key=[^&]+/, 'key=wc_order_nope'));
		await expect(page.locator('#woo-payrails-pay')).toHaveCount(0);
		expect(order(a.id).exec).toBe('');
	});
});
