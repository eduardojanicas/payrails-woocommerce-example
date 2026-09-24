import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { ROOT, ARTIFACTS } from './helpers/wp';

export default async function globalSetup(): Promise<void> {
	fs.mkdirSync(ARTIFACTS, { recursive: true });
	if (process.env.E2E_RESET === '1') {
		execFileSync('bash', ['scripts/reset.sh'], { cwd: ROOT, stdio: 'inherit' });
		fs.rmSync(path.join(ROOT, '.run/e2e-mode'), { force: true });
	}
	// Remember the wp-config secrets path so teardown can restore it even if a spec crashes.
	const saved = path.join(ROOT, '.run/e2e-secrets-file.orig');
	if (!fs.existsSync(saved)) {
		const v = execFileSync('bash', ['-c', `source scripts/_env.sh && wp config get PAYRAILS_SECRETS_FILE`], { cwd: ROOT, encoding: 'utf8' }).trim();
		if (!v.includes('nonexistent')) fs.writeFileSync(saved, v);
	}
}
