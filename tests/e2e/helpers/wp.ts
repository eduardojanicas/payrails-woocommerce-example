/**
 * Server + WP-CLI helpers for the e2e suite.
 *
 * SECRETS RULE: nothing here prints secret values. Only file PATHS are handled,
 * except secretValuesForLeakCheck(), which keeps values in memory for "is this
 * string absent from the HTML" assertions and never logs them.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
export const PORT = process.env.PORT || '8080';
export const BASE = process.env.SITE_URL || `http://localhost:${PORT}`;
export const ARTIFACTS = path.join(ROOT, 'tests/e2e/artifacts');
const MODE_MARKER = path.join(ROOT, '.run/e2e-mode');

export type ServerMode = 'live' | 'authfail-net';

/** Environment for each server mode. authfail-net points the API URL at a never-resolving host (→ PR-NET-*). */
const MODE_ENV: Record<ServerMode, Record<string, string>> = {
	live: {},
	// A *.payrails.invalid host never resolves (RFC 6761), so the token call fails with a
	// PR-NET-* code without any traffic. The plugin accepts that host only when the test
	// constant PAYRAILS_WOO_TESTING is defined (scripts/router.php defines it from this env).
	'authfail-net': { PAYRAILS_API_URL: 'https://unreachable.payrails.invalid:9', PAYRAILS_WOO_TESTING: '1' },
};

function sh(script: string, env: Record<string, string> = {}): string {
	return execFileSync('bash', ['-c', script], {
		cwd: ROOT,
		encoding: 'utf8',
		env: { ...process.env, PAYRAILS_API_URL: '', PAYRAILS_WOO_TESTING: '', PORT, ...env },
		stdio: ['ignore', 'pipe', 'pipe'],
	});
}

function currentMarker(): string | null {
	try {
		return fs.readFileSync(MODE_MARKER, 'utf8').trim();
	} catch {
		return null;
	}
}

function serverUp(): boolean {
	try {
		sh(`curl -fsS -o /dev/null ${BASE}/wp-login.php`);
		return true;
	} catch {
		return false;
	}
}

/** Restarts the PHP server in the requested mode, unless it already runs in it. */
export function ensureServer(mode: ServerMode): void {
	if (currentMarker() === mode && serverUp()) return;
	sh('bash scripts/stop.sh >/dev/null 2>&1 || true');
	for (let i = 0; i < 20; i++) {
		try {
			sh(`lsof -nP -iTCP:${PORT} -sTCP:LISTEN >/dev/null 2>&1 && exit 1 || exit 0`);
			break;
		} catch {
			sh('sleep 0.5');
		}
	}
	sh('bash scripts/start.sh >/dev/null', MODE_ENV[mode]);
	if (!serverUp()) throw new Error(`server did not come up in ${mode} mode`);
	fs.writeFileSync(MODE_MARKER, mode);
}

/** Runs PHP inside WordPress via wp eval. Returns the last stdout line. */
export function wpEval(php: string): string {
	const out = execFileSync('bash', ['-c', `source "${ROOT}/scripts/_env.sh" && wp eval "$1"`, '_', php], {
		cwd: ROOT,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	});
	return out.trim().split('\n').pop() ?? '';
}

export function wpJson<T = any>(php: string): T {
	return JSON.parse(wpEval(php));
}

/** wp-cli passthrough (args array, no shell interpolation of values). */
export function wp(...args: string[]): string {
	return execFileSync('bash', ['-c', `source "${ROOT}/scripts/_env.sh" && wp "$@"`, '_', ...args], {
		cwd: ROOT,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	}).trim();
}

export interface OrderInfo {
	status: string;
	txn: string;
	exec: string;
	executions: number;
	notes: number;
	noteTexts: string[];
	total: string;
	paid: boolean;
}

export function order(id: number): OrderInfo {
	return wpJson<OrderInfo>(`$o = wc_get_order( ${Number(id)} ); $n = wc_get_order_notes( array( 'order_id' => $o->get_id() ) ); echo wp_json_encode( array( 'status' => $o->get_status(), 'txn' => $o->get_transaction_id(), 'exec' => $o->get_meta( '_payrails_execution_id' ), 'executions' => count( (array) json_decode( (string) $o->get_meta( '_payrails_execution_ids' ), true ) ), 'notes' => count( $n ), 'noteTexts' => array_map( fn( $x ) => $x->content, $n ), 'total' => $o->get_total(), 'paid' => $o->is_paid() ) );`);
}

/** Creates a guest pending order paid by Payrails; returns id + pay URL. No client-init happens until the URL is opened. */
export function createOrder(items: Array<{ sku: string; qty: number }> = [{ sku: '', qty: 1 }], opts: { customerId?: number; coupon?: string } = {}): { id: number; payUrl: string; key: string } {
	const itemsPhp = items
		.map(i => (i.sku ? `$p = wc_get_product( wc_get_product_id_by_sku( '${i.sku.replace(/'/g, '')}' ) );` : `$p = wc_get_product( wc_get_products( array( 'limit' => 1, 'orderby' => 'menu_order', 'order' => 'ASC', 'return' => 'ids' ) )[0] );`) + ` $o->add_product( $p, ${Number(i.qty)} );`)
		.join(' ');
	const php = `$o = wc_create_order( array( 'customer_id' => ${Number(opts.customerId ?? 0)} ) ); ${itemsPhp} $a = array( 'first_name' => 'Jane', 'last_name' => 'Cli', 'email' => 'jane.cli@example.com', 'address_1' => '350 Fifth Avenue', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10118', 'country' => 'US' ); $o->set_address( $a, 'billing' ); $o->set_address( $a, 'shipping' ); $o->set_payment_method( 'payrails' ); $o->set_payment_method_title( 'Card' ); ${opts.coupon ? `$o->apply_coupon( '${opts.coupon}' );` : ''} $o->calculate_totals(); $o->set_status( 'pending' ); $o->save(); echo wp_json_encode( array( 'id' => $o->get_id(), 'url' => $o->get_checkout_payment_url( true ), 'key' => $o->get_order_key() ) );`;
	const r = wpJson<{ id: number; url: string; key: string }>(php);
	return { id: r.id, payUrl: r.url, key: r.key };
}

export function secretsFileConstant(): string {
	return wp('config', 'get', 'PAYRAILS_SECRETS_FILE');
}

export function setSecretsFileConstant(p: string): void {
	wp('config', 'set', 'PAYRAILS_SECRETS_FILE', p);
}

/**
 * Secret values held in memory ONLY for absence assertions. Never log the return value.
 * Returns the client secret and the private-key body lines (long enough to be unique).
 */
export function secretValuesForLeakCheck(): string[] {
	const file = path.join(ROOT, '.secrets/payrails.env');
	const out: string[] = [];
	try {
		const ini = fs.readFileSync(file, 'utf8');
		for (const line of ini.split('\n')) {
			const m = line.match(/^\s*PAYRAILS_CLIENT_SECRET\s*=\s*(.*)$/);
			if (m) out.push(m[1].trim().replace(/^["']|["']$/g, ''));
		}
		const key = fs.readFileSync(path.join(ROOT, '.secrets/client.key'), 'utf8');
		const body = key.split('\n').filter(l => l && !l.startsWith('-----'));
		if (body[1]) out.push(body[1]);
	} catch {
		/* no secrets: nothing to leak */
	}
	return out.filter(s => s.length >= 12);
}

export function debugLogLines(): string[] {
	try {
		return fs.readFileSync(path.join(ROOT, '.run/wp-debug.log'), 'utf8').split('\n').filter(Boolean);
	} catch {
		return [];
	}
}
