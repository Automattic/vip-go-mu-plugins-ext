import { expect, test } from '@playwright/test';

import { LoginPage } from '../lib/pages/login-page';
import { DEFAULT_CONFIG, getSecurityBoostConfigHeaders } from '../lib/security-boost-config-helper';

import type { Cookie } from '@playwright/test';

const DAY_IN_SECONDS = 86400;
test.describe( 'Session Control', () => {
	// we're using logout here so we want a dedicated storage state
	test.use( { storageState: { cookies: [], origins: [] } } );
	const TEST_USERNAME = 'sbadmin';
	const TEST_PASSWORD = 'password';

	test( 'Cookie expiration with Remember Me and custom expiration (7 days)', async ( { page, context } ) => {
		// Enable session-control with 7 days expiration
		await context.setExtraHTTPHeaders(
			getSecurityBoostConfigHeaders( {
				module_configs: {
					...DEFAULT_CONFIG.module_configs,
					'session-control': {
						expiration_days: 7,
					},
				},
			} ),
		);

		const loginPage = new LoginPage( page );

		// Logout any existing session
		await loginPage.logout();

		// Navigate to login page
		await loginPage.visit();

		// Login with Remember Me checked
		await loginPage.login( TEST_USERNAME, TEST_PASSWORD, true );

		// Wait for login to complete
		await page.waitForURL( /wp-admin/, { timeout: 10000 } );

		// Get cookies
		const cookies = await context.cookies();

		expectAuthCookieLifetime( cookies, 7 );
	} );

	test( 'Cookie expiration with default expiration (14 days)', async ( { page, context } ) => {
		// Default WordPress expiration is 14 days
		await context.setExtraHTTPHeaders(
			getSecurityBoostConfigHeaders( {
				module_configs: {
					...DEFAULT_CONFIG.module_configs,
					'session-control': {
						expiration_days: 'default',
					},
				},
			} ),
		);

		const loginPage = new LoginPage( page );

		// Logout any existing session
		await loginPage.logout();

		// Navigate to login page
		await loginPage.visit();

		// Login with Remember Me checked
		await loginPage.login( TEST_USERNAME, TEST_PASSWORD, true );

		// Wait for login to complete
		await page.waitForURL( /wp-admin/, { timeout: 10000 } );

		// Get cookies
		const cookies = await context.cookies();

		expectAuthCookieLifetime( cookies, 14 );
	} );

	test( 'Cookie expiration with default expiration when module is disabled', async ( { page, context } ) => {
		// When session-control is disabled, WordPress default expiration is 14 days
		await context.setExtraHTTPHeaders(
			getSecurityBoostConfigHeaders( {
				enabled_modules: [],
			} ),
		);

		const loginPage = new LoginPage( page );

		// Logout any existing session
		await loginPage.logout();

		// Navigate to login page
		await loginPage.visit();

		// Login with Remember Me checked
		await loginPage.login( TEST_USERNAME, TEST_PASSWORD, true );

		// Wait for login to complete
		await page.waitForURL( /wp-admin/, { timeout: 10000 } );

		// Get cookies
		const cookies = await context.cookies();

		expectAuthCookieLifetime( cookies, 14 );
	} );
} );

function expectAuthCookieLifetime( cookies: Cookie[], expectedDays: number ): void {
	const authCookie = cookies.find( ( cookie ) => cookie.name.includes( 'wordpress_logged_in' ) );

	expect( authCookie ).toBeDefined();

	if ( ! authCookie ) {
		return;
	}

	const currentTimestamp = Math.floor( Date.now() / 1000 );
	const expectedLifetime = expectedDays * DAY_IN_SECONDS;
	const actualLifetime = authCookie.expires - currentTimestamp;
	expect( actualLifetime ).toBeGreaterThanOrEqual( expectedLifetime );
}
