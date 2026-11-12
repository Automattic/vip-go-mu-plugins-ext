import { expect, test } from '@playwright/test';

import { UsersListPage } from '../lib/pages/users-list-page';
import { DEFAULT_CONFIG, getSecurityBoostConfigHeaders } from '../lib/security-boost-config-helper';

test.describe( 'Inactive Users', () => {
	const INACTIVE_ADMIN_USERNAME = 'sbinactiveadmin';
	const BLOCKED_BADGE_CLASS = /inactive-user-badge--blocked/;
	const INACTIVE_BADGE_CLASS = /inactive-user-badge--inactive/;
	const INACTIVE_CONTRIBUTOR_USERNAME = 'sbinactivecontributor';
	const EXPECTED_LAST_SEEN = 'November 8, 2023 at 4:00 pm';
	const ACTIVE_USERNAMES = [ 'vipgo', 'sbadmin', 'sbcontributor', 'sbeditor' ];

	test.describe( 'Blocked Users', () => {
		test( 'Display inactive user badge for inactive blocked user', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();

			// Verify "Last Seen" column exists
			await expect( usersListPage.getLastSeenColumnHeader() ).toBeVisible();

			// Verify inactive users have badges and correct last seen date
			await hasBlockedBadge( usersListPage, INACTIVE_ADMIN_USERNAME );
			await hasBlockedBadge( usersListPage, INACTIVE_CONTRIBUTOR_USERNAME );
			await expect( usersListPage.getLastSeenValue( INACTIVE_ADMIN_USERNAME ) ).toContainText( EXPECTED_LAST_SEEN );
			await expect( usersListPage.getLastSeenValue( INACTIVE_CONTRIBUTOR_USERNAME ) ).toContainText( EXPECTED_LAST_SEEN );
		} );

		test( 'Ensure users do not have the blocked badge', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();
			// Verify active users don't have inactive badges
			await Promise.all(
				ACTIVE_USERNAMES.map( ( username ) => verifyNoInactiveBadge( usersListPage, username ) ),
			);
		} );

		test( 'Unblock link appears for blocked users', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();

			// Verify unblock link is present for blocked users
			await expect( usersListPage.getUnblockLink( INACTIVE_ADMIN_USERNAME ) ).toBeVisible();
			await expect( usersListPage.getUnblockLink( INACTIVE_CONTRIBUTOR_USERNAME ) ).toBeVisible();

			// Verify unblock link has correct text
			await expect( usersListPage.getUnblockLink( INACTIVE_ADMIN_USERNAME ) ).toContainText( 'Unblock' );
		} );

		// Note: Unblock functionality test removed to avoid database state modification
		// The unblock link is tested for visibility in "Unblock link appears for blocked users"
		// Actual unblock action would affect subsequent tests by changing user state
	} );
	test.describe( 'Inactive Users', () => {
		test.beforeEach( async ( { context } ) => {
			await context.setExtraHTTPHeaders(
				getSecurityBoostConfigHeaders( {
					module_configs: {
						...DEFAULT_CONFIG.module_configs,
						'inactive-users': {
							...DEFAULT_CONFIG.module_configs[ 'inactive-users' ],
							mode: 'REPORT',
						},
					},
				} ),
			);
		} );

		test( 'Display inactive user badge for inactive user', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();

			// Verify "Last Seen" column exists
			await expect( usersListPage.getLastSeenColumnHeader() ).toBeVisible();

			// Verify inactive users have badges and correct last seen date
			await hasInactiveBadge( usersListPage, INACTIVE_ADMIN_USERNAME );
			await hasInactiveBadge( usersListPage, INACTIVE_CONTRIBUTOR_USERNAME );
			await expect( usersListPage.getLastSeenValue( INACTIVE_ADMIN_USERNAME ) ).toContainText( EXPECTED_LAST_SEEN );
			await expect( usersListPage.getLastSeenValue( INACTIVE_CONTRIBUTOR_USERNAME ) ).toContainText( EXPECTED_LAST_SEEN );

			// Verify active users don't have inactive badges
			await Promise.all(
				ACTIVE_USERNAMES.map( ( username ) => verifyNoInactiveBadge( usersListPage, username ) ),
			);
		} );

		test( 'Ensure users do not have the inactive badge', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();
			// Verify active users don't have inactive badges
			await Promise.all(
				ACTIVE_USERNAMES.map( ( username ) => verifyNoInactiveBadge( usersListPage, username ) ),
			);
		} );

		test( 'Unblock link does not appear in REPORT mode', async ( { page } ) => {
			const usersListPage = new UsersListPage( page );
			await usersListPage.visit();

			// Verify unblock link is NOT present for inactive users in REPORT mode
			await expect( usersListPage.getUnblockLink( INACTIVE_ADMIN_USERNAME ) ).toBeHidden();
			await expect( usersListPage.getUnblockLink( INACTIVE_CONTRIBUTOR_USERNAME ) ).toBeHidden();
		} );
	} );

	async function hasInactiveBadge( usersListPage: UsersListPage, username: string ) {
		const inactiveUserBadge = usersListPage.getInactiveUserBadge( username );
		await expect( usersListPage.getUserRow( username ) ).toBeVisible();

		// Check that the inactive user badge is visible
		await expect( inactiveUserBadge ).toBeVisible();

		// Verify the badge has both required classes
		await expect( inactiveUserBadge ).toHaveClass( INACTIVE_BADGE_CLASS );

		// Verify the badge text content
		await expect( inactiveUserBadge ).toContainText( 'Inactive User' );
	}

	async function hasBlockedBadge( usersListPage: UsersListPage, username: string ) {
		const blockedUserBadge = usersListPage.getBlockedUserBadge( username );
		await expect( usersListPage.getUserRow( username ) ).toBeVisible();

		// Check that the blocked user badge is visible
		await expect( blockedUserBadge ).toBeVisible();

		// Verify the badge has both required classes
		await expect( blockedUserBadge ).toHaveClass( BLOCKED_BADGE_CLASS );

		// Verify the badge text content
		await expect( blockedUserBadge ).toContainText( 'Blocked: Inactivity' );
	}

	async function verifyNoInactiveBadge( usersListPage: UsersListPage, username: string ) {
		const userRow = usersListPage.getUserRow( username );
		await expect( userRow ).toBeVisible();

		// Verify that neither inactive nor blocked badges are present
		const inactiveBadge = userRow.locator( '.inactive-user-badge--inactive' );
		const blockedBadge = userRow.locator( '.inactive-user-badge--blocked' );

		await expect( inactiveBadge ).toBeHidden();
		await expect( blockedBadge ).toBeHidden();
	}
} );
