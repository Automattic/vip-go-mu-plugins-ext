import { expect, test } from '@playwright/test';

import { UsersListPage } from '../lib/pages/users-list-page';
import { getSecurityBoostConfigHeaders } from '../lib/security-boost-config-helper';

test.describe( 'Highlight MFA Users', () => {
	// All test users don't have MFA setup according to the requirements
	const USERS_WITHOUT_MFA = [ 'vipgo', 'sbadmin', 'sbinactiveadmin', 'sbeditor' ];
	const USERS_WITH_NO_MFA_REQUIREMENTS = [ 'sbcontributor', 'sbinactivecontributor' ];
	const ALL_USERNAMES = [ ...USERS_WITHOUT_MFA, ...USERS_WITH_NO_MFA_REQUIREMENTS ];

	test.describe( 'With module disabled', () => {
		test.beforeEach( async ( { context } ) => {
			// Disable the MFA module
			await context.setExtraHTTPHeaders( {
				...getSecurityBoostConfigHeaders( {
					enabled_modules: [] },
				),
			} );
		} );
		test( 'No MFA disabled notice appears when module is disabled', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();

			// Verify MFA disabled warning notice is not visible
			const mfaNotice = usersListPage.getErrorNotice();
			await expect( mfaNotice ).toHaveCount( 0 );
		} );
		test( 'Role column is not sortable when module is disabled', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();

			// Verify Role column header is present
			const roleColumnHeader = usersListPage.getRoleColumnHeader();
			await expect( roleColumnHeader ).toBeVisible();
			// Verify Role column header is not clickable (not sortable)
			await expect( roleColumnHeader ).not.toHaveAttribute( 'aria-sort', /ascending|descending/ );
		} );
	} );
	test( 'MFA disabled notice appears for users without MFA', async ( { page } ) => {
		const usersListPage = new UsersListPage( page );
		await usersListPage.visit();

		// Verify MFA disabled warning notice is visible
		const mfaNotice = usersListPage.getErrorNotice();
		await expect( mfaNotice ).toBeVisible();

		// Verify notice contains expected text
		await expect( mfaNotice ).toContainText( 'with Two-Factor Authentication disabled' );
	} );

	test( 'Filtering by MFA status', async ( { page } ) => {
		const usersListPage = new UsersListPage( page );
		await usersListPage.visit();

		// Verify "MFA Disabled" filter link exists
		const mfaDisabledFilter = usersListPage.getFilterLink();
		await expect( mfaDisabledFilter ).toBeVisible();

		// Click the MFA Disabled filter
		await usersListPage.clickFilterLink();
		await page.waitForLoadState( 'load' );

		// Verify URL contains the mfa filter parameter
		expect( page.url() ).toContain( 'filter_mfa_disabled=1' );

		// Verify only users without MFA are shown (all our test users don't have MFA)
		for ( const username of USERS_WITHOUT_MFA ) {
			/* eslint-disable no-await-in-loop */
			await expect( usersListPage.getUserRow( username ) ).toBeVisible();
		}
	} );

	test( 'Role column sorting', async ( { page } ) => {
		const usersListPage = new UsersListPage( page );
		await usersListPage.visit();

		// Click Role column header to sort
		await usersListPage.clickRoleColumnHeader();
		await page.waitForLoadState( 'load' );

		// Verify URL contains orderby=role parameter
		expect( page.url() ).toContain( 'orderby=role' );

		// Verify the table is still visible after sorting
		await expect( usersListPage.usersTable ).toBeVisible();
	} );

	test( 'Show all users link works', async ( { page } ) => {
		const usersListPage = new UsersListPage( page );
		await usersListPage.visit();

		// First filter by MFA Disabled

		await usersListPage.clickFilterLink( );
		await page.waitForLoadState( 'load' );

		// Verify we're on filtered view
		expect( page.url() ).toContain( 'filter_mfa_disabled=1' );

		// Click "Show all users" link in the notice
		const showAllUsersLink = usersListPage.getShowAllUsersLink();
		await expect( showAllUsersLink ).toBeVisible();
		await showAllUsersLink.click();
		await page.waitForLoadState( 'load' );

		// Verify we're back to unfiltered view
		expect( page.url() ).not.toContain( 'filter_mfa_disabled=1' );

		// Verify all users are visible
		for ( const username of ALL_USERNAMES ) {
			/* eslint-disable no-await-in-loop */
			await expect( usersListPage.getUserRow( username ) ).toBeVisible();
		}
	} );
} );
