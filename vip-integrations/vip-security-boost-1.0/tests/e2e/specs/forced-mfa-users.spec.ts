import { expect, test } from '@playwright/test';

import { LoginPage } from '../lib/pages/login-page';
import { getSecurityBoostConfigHeaders } from '../lib/security-boost-config-helper';

const ADMIN_USERNAME = 'sbadmin';
const ADMIN_PASSWORD = 'password';
const EDITOR_USERNAME = 'sbeditor';
const EDITOR_PASSWORD = 'password';
const CONTRIBUTOR_USERNAME = 'sbcontributor';
const CONTRIBUTOR_PASSWORD = 'password';

test.describe( 'Forced MFA Users', () => {
	// Use a dedicated storage state so logging out does not invalidate the shared admin session.
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.describe( 'Module disabled', () => {
		test.beforeEach( async ( { context } ) => {
			// Disable forced-mfa module
			await context.setExtraHTTPHeaders( {
				...getSecurityBoostConfigHeaders( {
					enabled_modules: [],
				} ),
			} );
		} );
		test( 'Editor user is NOT prompted to setup MFA on login', async ( { page } ) => {
			const loginPage = new LoginPage( page );

			// Logout any existing session
			await loginPage.logout();

			// Navigate to login page
			await loginPage.visit();

			// Login as editor
			await loginPage.login( EDITOR_USERNAME, EDITOR_PASSWORD );

			// Verify Two-Factor setup prompt does NOT appear
			const twoFactorPrompt = loginPage.getTwoFactorSetupPrompt();
			await expect( twoFactorPrompt ).toBeHidden();

			// Verify we're redirected to wp-admin (successful login without MFA prompt)
			expect( page.url() ).toContain( '/wp-admin' );
		} );
	} );

	test( 'Admin user is prompted to setup MFA on login', async ( { page } ) => {
		const loginPage = new LoginPage( page );

		// Logout any existing session
		await loginPage.logout();

		// Navigate to login page
		await loginPage.visit();

		// Login as admin
		await loginPage.login( ADMIN_USERNAME, ADMIN_PASSWORD );

		// Verify Two-Factor setup prompt appears
		const twoFactorPrompt = loginPage.getTwoFactorSetupPrompt();
		await expect( twoFactorPrompt ).toBeVisible();

		// Verify the prompt contains Two-Factor setup text
		await expect( twoFactorPrompt ).toContainText( 'Your account requires two-factor authentication to be enabled.' );
	} );
	test( 'Editor user is prompted to setup MFA on login', async ( { page } ) => {
		const loginPage = new LoginPage( page );

		// Logout any existing session
		await loginPage.logout();

		// Navigate to login page
		await loginPage.visit();

		// Login as editor
		await loginPage.login( EDITOR_USERNAME, EDITOR_PASSWORD );

		// Verify Two-Factor setup prompt appears
		const twoFactorPrompt = loginPage.getTwoFactorSetupPrompt();
		await expect( twoFactorPrompt ).toBeVisible();

		// Verify the prompt contains Two-Factor setup text
		await expect( twoFactorPrompt ).toContainText( 'Your account requires two-factor authentication to be enabled.' );
	} );

	test( 'Contributor user is NOT prompted to setup MFA on login', async ( { page } ) => {
		const loginPage = new LoginPage( page );

		// Logout any existing session
		await loginPage.logout();

		// Navigate to login page
		await loginPage.visit();

		// Login as contributor
		await loginPage.login( CONTRIBUTOR_USERNAME, CONTRIBUTOR_PASSWORD );

		// Verify Two-Factor setup prompt does NOT appear
		const twoFactorPrompt = loginPage.getTwoFactorSetupPrompt();
		await expect( twoFactorPrompt ).toBeHidden();

		// Verify we're redirected to wp-admin (successful login without MFA prompt)
		expect( page.url() ).toContain( '/wp-admin' );
	} );
} );
