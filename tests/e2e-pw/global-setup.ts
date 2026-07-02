/**
 * Playwright global setup: truncate wp-content/debug.log before the run.
 *
 * Paired with global-teardown.ts, which fails the run if the suite left anything (unexpected) in the log.
 * Starting from empty means the teardown only sees what this run produced.
 */
import type { FullConfig } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

function baseUrl(): string {
	return process.env.WP_BASE_URL || process.env.BASEURL || 'http://localhost:8888';
}

async function globalSetup( _config: FullConfig ): Promise< void > {
	try {
		await fetch( `${ baseUrl() }${ DEV_REST }/debug-log`, { method: 'DELETE' } );
	} catch {
		// wp-env / dev plugin not reachable yet — the teardown will no-op too.
	}
}

export default globalSetup;
