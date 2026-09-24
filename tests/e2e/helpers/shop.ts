import { expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { BASE, ROOT } from './wp';

export const PRODUCTS = [
	{ name: 'Merino Wool Sweater', price: '$185.00', category: 'Knitwear', slug: 'merino-wool-sweater' },
	{ name: 'Organic Cotton Tee', price: '$45.00', category: 'Basics', slug: 'organic-cotton-tee' },
	{ name: 'Linen Button Shirt', price: '$125.00', category: 'Shirts', slug: 'linen-button-shirt' },
	{ name: 'Cashmere Scarf', price: '$95.00', category: 'Accessories', slug: 'cashmere-scarf' },
	{ name: 'Wool Coat', price: '$320.00', category: 'Outerwear', slug: 'wool-coat' },
	{ name: 'Cotton Dress', price: '$165.00', category: 'Dresses', slug: 'cotton-dress' },
];

/**
 * Test cards; they depend on the processor behind your staging workflow. The 3DS-challenge and
 * decline cards are processor-specific, so they have no default: set E2E_CARD_3DS and
 * E2E_CARD_DECLINE, or the tests that need them are skipped.
 */
export const CARDS = {
	ok: process.env.E2E_CARD_OK ?? '4242424242424242',
	visa: process.env.E2E_CARD_VISA ?? '4111111111111111',
	threeDS: process.env.E2E_CARD_3DS ?? '',
	decline: process.env.E2E_CARD_DECLINE ?? '',
};

export async function addToCart(page: Page, slug = 'organic-cotton-tee', qty = 1): Promise<void> {
	await page.goto(`${BASE}/product/${slug}/`);
	if (qty !== 1) await page.locator('input.qty').first().fill(String(qty));
	await page.getByRole('button', { name: /add to (cart|bag)/i }).first().click();
	await page.waitForLoadState('networkidle');
}

export async function fillCheckout(page: Page, email = 'jane.demo@example.com'): Promise<void> {
	await page.goto(`${BASE}/checkout/`);
	await page.waitForSelector('#email', { timeout: 30000 });
	await page.fill('#email', email);
	// Logged-in customers may have a saved address summary; open the form if needed.
	const first = page.locator('#shipping-first_name');
	if (!(await first.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false))) {
		await page.locator('.wc-block-components-address-card__edit').first().click({ timeout: 5000 }).catch(() => {});
	}
	if (await first.isVisible().catch(() => false)) {
		await page.fill('#shipping-first_name', 'Jane');
		await page.fill('#shipping-last_name', 'Demo');
		await page.fill('#shipping-address_1', '350 Fifth Avenue');
		await page.fill('#shipping-city', 'New York');
		await page.selectOption('#shipping-state', 'NY');
		await page.fill('#shipping-postcode', '10118');
	}
	await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
	await page.waitForTimeout(2000); // Let the Checkout block finish validating the address.
}

export async function placeOrder(page: Page): Promise<number> {
	await page.locator('.wc-block-components-checkout-place-order-button').click();
	await page.waitForURL(/\/checkout\/order-pay\/\d+\/\?key=wc_order_/, { timeout: 60000, waitUntil: 'commit' });
	return Number(page.url().match(/order-pay\/(\d+)/)![1]);
}

/** Browse → add → checkout block → Continue to payment → pay page. Returns the order id. */
export async function toPayPage(page: Page, slug = 'organic-cotton-tee', qty = 1): Promise<number> {
	await addToCart(page, slug, qty);
	await fillCheckout(page);
	return placeOrder(page);
}

export const payState = (page: Page) => page.locator('#woo-payrails-pay').getAttribute('data-state');

export async function waitState(page: Page, states: string[], timeout = 45000): Promise<string> {
	await page.waitForFunction(s => s.includes(document.querySelector('#woo-payrails-pay')?.getAttribute('data-state') ?? ''), states, { timeout });
	return (await payState(page)) ?? '';
}

export async function payCard(page: Page, pan: string): Promise<void> {
	await waitState(page, ['ready']);
	await page.locator('#payrails-dropin-item-payrails-credit-card-wrapper').click({ force: true }).catch(() => {});
	const card = page.frameLocator('iframe[title="Payrails Card Form"]');
	await card.locator('input[name="CARD_NUMBER"]').fill(pan);
	await card.locator('input[name="EXPIRATION_MONTH"]').fill('12');
	await card.locator('input[name="EXPIRATION_YEAR"]').fill('30');
	await card.locator('input[name="CVV"]').fill('123');
	await page.waitForFunction(() => !(document.querySelector('#payrails-card-payment-button') as HTMLButtonElement | null)?.disabled, null, { timeout: 20000 });
	await page.locator('#payrails-card-payment-button').click();
}

/**
 * Clicks Complete/Fail in the test 3DS challenge (ACS) page, wherever it is
 * (an in-page iframe or the top-level page after the redirect fallback): the frame that is
 * neither this store nor Payrails and shows those buttons. Returns false if the page already left.
 */
export async function answerAcs(page: Page, which: 'complete' | 'fail', timeoutMs = 45000): Promise<boolean> {
	const end = Date.now() + timeoutMs;
	const re = which === 'complete' ? /complete/i : /fail/i;
	while (Date.now() < end) {
		for (const f of page.frames()) {
			const host = (() => { try { return new URL(f.url()).host; } catch { return ''; } })();
			if (!host || host === new URL(BASE).host || /(^|\.)payrails\.io$/.test(host)) continue;
			const b = f.locator('button').filter({ hasText: re }).first();
			if (await b.count().catch(() => 0)) {
				await b.click();
				return true;
			}
		}
		if (/order-received/.test(page.url())) return false;
		await page.waitForTimeout(500);
	}
	throw new Error('3DS challenge (test ACS) never appeared');
}

/** 3DS: the in-page challenge, or the page's "Continue to bank verification" redirect when the SDK reports pending first. */
export async function do3ds(page: Page, which: 'complete' | 'fail'): Promise<string> {
	try {
		await answerAcs(page, which, 30000);
		return 'in-page';
	} catch {
		await page.getByRole('button', { name: /continue to bank verification/i }).click({ timeout: 30000 });
		await answerAcs(page, which);
		return 'redirect-fallback';
	}
}

/** Admin credentials from .secrets/admin.txt (written by setup.sh). Kept in memory; never logged. */
export function adminCredentials(): { user: string; pass: string } {
	const txt = fs.readFileSync(path.join(ROOT, '.secrets/admin.txt'), 'utf8');
	const get = (k: string) => (txt.match(new RegExp(`^${k}=(.*)$`, 'm'))?.[1] ?? '').trim();
	return { user: get('user'), pass: get('password') };
}

export async function adminLogin(page: Page): Promise<void> {
	const { user, pass } = adminCredentials();
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', user);
	await page.fill('#user_pass', pass);
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/);
}

export async function miniCartCount(page: Page): Promise<string> {
	return (await page.locator('.wc-block-mini-cart__badge').first().textContent())?.trim() ?? '';
}

export async function expectNoHorizontalScroll(page: Page): Promise<void> {
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow, 'horizontal overflow in px').toBeLessThanOrEqual(1);
}
