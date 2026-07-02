/**
 * Playwright global teardown: fail the run if wp-content/debug.log is non-empty.
 *
 * A PHP notice/warning/error emitted anywhere during the suite is a real defect, so surface it as a
 * test-run failure. A small allowlist covers benign WordPress-core chatter (e.g. automatic-update logs).
 */
import type { FullConfig } from '@playwright/test';

const DEV_REST = '/wp-json/bh-wp-mailboxes-dev/v1';

/** Lines matching any of these are benign WordPress-core noise, not defects under test. */
const ALLOWLIST: RegExp[] = [
	/Automatic updates starting/,
	/Automatic updates complete/,
];

function baseUrl(): string {
	return process.env.WP_BASE_URL || process.env.BASEURL || 'http://localhost:8888';
}

async function globalTeardown( _config: FullConfig ): Promise< void > {
	let contents = '';
	try {
		const res = await fetch( `${ baseUrl() }${ DEV_REST }/debug-log` );
		if ( ! res.ok ) {
			return; // Dev plugin unavailable — nothing to assert.
		}
		( { contents } = ( await res.json() ) as { contents: string } );
	} catch {
		return; // wp-env not reachable — skip.
	}

	const offending = String( contents )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.filter( ( line ) => ! ALLOWLIST.some( ( re ) => re.test( line ) ) );

	if ( offending.length > 0 ) {
		const preview = offending.slice( 0, 40 ).join( '\n' );
		throw new Error(
			`wp-content/debug.log has ${ offending.length } unexpected line(s) after the e2e run:\n${ preview }`
		);
	}
}

export default globalTeardown;
