import { expect, test } from '@playwright/test';

/**
 * The site-wide Preview Links screen, exercising the parts that only run in a
 * browser: the Gmail-style "select all across pages" script and the
 * enable/disable slider. The server-side halves — nonces, capabilities, the
 * revoke sweep, the toggle option — are covered by the PHP integration suite,
 * so they are deliberately not re-tested here.
 *
 * Both journeys act site-wide (one revokes every link, the other pauses the
 * feature), so this file runs in its own Playwright project after the editor
 * journey has finished with its link — see playwright.config.ts.
 */

test.describe( 'Preview Links admin screen', () => {
	// Both tests mutate site-wide state; keep them off each other's toes.
	test.describe.configure( { mode: 'serial' } );
	test.use( { storageState: '.playwright/state.json' } );

	test( 'select-all across pages revokes every link', async ( { page } ) => {
		test.setTimeout( 90000 );

		// --- Mint three links over REST -------------------------------------
		// The editor UI path to a link is covered by preview-links.spec.ts;
		// here links are just fixtures, so take the fast road.
		await page.goto( './wp-admin/' );
		const nonce = await page.evaluate<string>( 'wpApiSettings.nonce' );
		const headers = { 'X-WP-Nonce': nonce };

		for ( let i = 1; i <= 3; i++ ) {
			const created = await page.request.post( './wp-json/wp/v2/posts', {
				headers,
				data: { title: `Admin screen E2E draft ${ i }`, status: 'draft' },
			} );
			expect( created.ok() ).toBeTruthy();
			const { id } = ( await created.json() ) as { id: number };

			const minted = await page.request.post( './wp-json/shareadraft/v1/preview-links', {
				headers,
				data: { post_id: id, expiration: 3600 },
			} );
			expect( minted.ok() ).toBeTruthy();
		}

		// --- Two rows per page, so selecting across pages has a job to do ---
		await page.goto( './wp-admin/admin.php?page=shareadraft' );
		await page.locator( '#show-settings-link' ).click();
		await page.locator( '#shareadraft_links_per_page' ).fill( '2' );
		await page.locator( '#screen-options-apply' ).click();

		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );

		// Ticking the header checkbox surfaces the cross-page offer.
		const banner = page.locator( '#shareadraft-select-all' );
		await expect( banner ).toBeHidden();
		await page.locator( '#cb-select-all-1' ).check();
		await expect( banner.getByText( 'All links on this page are selected.' ) ).toBeVisible();

		// Unticking any row narrows the selection and withdraws the offer.
		await page.locator( '#the-list input[name="links[]"]' ).first().uncheck();
		await expect( banner ).toBeHidden();

		// Take the upgrade this time: the selection now covers every page.
		await page.locator( '#cb-select-all-1' ).check();
		await banner.getByRole( 'button', { name: /^Select all \d+ links across the whole site$/u } ).click();
		await expect( banner.getByText( /^All \d+ links across the whole site are selected\./u ) ).toBeVisible();

		// The ordinary bulk Revoke, upgraded by the hidden shareadraft_all field.
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'revoke' );
		await page.locator( '#doaction' ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( /\d+ preview links? revoked\./u );

		// Revoked links stay listed until the sweep prunes them; what must be
		// gone is any link still marked Active.
		await expect( page.locator( '#the-list' ).getByText( 'Active', { exact: true } ) ).toHaveCount( 0 );
	} );

	test( 'the slider pauses preview links site-wide, reversibly', async ( { page } ) => {
		await page.goto( './wp-admin/admin.php?page=shareadraft' );

		const slider = page.locator( '.shareadraft-switch' );
		const state = page.locator( 'input[name="shareadraft_enabled"]' );
		const warning = page.getByText( 'Preview links are disabled site-wide.' );

		if ( ! ( await state.isChecked() ) ) {
			// A failed earlier run may have left the switch off; recover first.
			await slider.click();
			await expect( state ).toBeChecked();
		}

		await expect( warning ).toBeHidden();

		// Flipping the slider submits its form and reloads the screen.
		await slider.click();
		await expect( warning ).toBeVisible();
		await expect( page.getByText( 'Preview links are disabled', { exact: true } ) ).toBeVisible();
		await expect( state ).not.toBeChecked();

		await slider.click();
		await expect( warning ).toBeHidden();
		await expect( page.getByText( 'Preview links are enabled', { exact: true } ) ).toBeVisible();
		await expect( state ).toBeChecked();
	} );
} );
