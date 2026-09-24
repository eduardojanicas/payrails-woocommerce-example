import fs from 'node:fs';
import path from 'node:path';
import { ROOT, ensureServer, secretsFileConstant, setSecretsFileConstant } from './helpers/wp';

/** Leaves the store as a demo expects it: real secrets file, live (staging) mode. */
export default async function globalTeardown(): Promise<void> {
	const saved = path.join(ROOT, '.run/e2e-secrets-file.orig');
	if (fs.existsSync(saved)) {
		const orig = fs.readFileSync(saved, 'utf8').trim();
		if (orig && secretsFileConstant() !== orig) setSecretsFileConstant(orig);
		fs.rmSync(saved, { force: true });
	}
	if (process.env.E2E_KEEP_MODE !== '1') ensureServer('live');
}
