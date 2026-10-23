import { type Response, expect, test } from '@playwright/test';

test.describe( 'Front-page smoke', () => {
	// A cheap guard that activating this plugin does not fatal the front end.
	test( 'Home page renders without a fatal or wp_die() message', async ( { page } ) => {
		const response = await page.goto( '.' ) as Response;
		expect.soft( response.status() ).toBeLessThan( 500 );
		await expect( page.locator( '.wp-die-message' ) ).toHaveCount( 0 );
		const html = await page.content();
		expect( html ).toContain( '</html>' );
		await expect( page ).toHaveTitle( /WordPress/u );
	} );
} );
