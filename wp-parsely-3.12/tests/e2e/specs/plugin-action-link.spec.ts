/**
 * WordPress dependencies
 */
import { visitAdminPage } from '@wordpress/e2e-test-utils';

/**
 * Internal dependencies
 */
import {
	waitForWpAdmin,
} from '../utils';

describe( 'Plugin action link', () => {
	it( 'Should link to plugin settings page', async () => {
		await visitAdminPage( '/plugins.php', '' );

		await page.click( '[data-slug=wp-parsely] .settings>a' );
		await waitForWpAdmin();

		const versionText = await page.$eval( '#wp-parsely_version', ( el: Element ) => el.textContent );
		expect( versionText ).toMatch( /^Version \d+.\d+/ );
	} );
} );
