import type { Locator, Page } from '@playwright/test';

const selectors = {
	usersTable: 'table.wp-list-table',
	userRowByUsername: ( username: string ) => `text=${ username }`,
	inactiveUserBadge: '.inactive-user-badge--inactive',
	blockedUserBadge: '.inactive-user-badge.inactive-user-badge--blocked',
	unblockLink: 'a.reset_last_seen_action',
	successNotice: '.notice-success',
	errorNotice: '.notice.notice-error',
	infoNotice: '.notice.notice-info',
	mfaFilterLink: '.notice.notice-error a',
	roleColumn: 'th[data-colname="Role"]',
	twoFactorColumn: 'th#two-factor',
};

export class UsersListPage {
	private readonly page: Page;
	public readonly usersTable: Locator;

	/**
	 * Constructs an instance of the component.
	 *
	 * @param { Page } page The underlying page
	 */
	constructor( page: Page ) {
		this.page = page;
		this.usersTable = page.locator( selectors.usersTable );
	}

	/**
	 * Navigate to Users List page
	 */
	public visit(): Promise<unknown> {
		return this.page.goto( '/wp-admin/users.php' );
	}

	/**
	 * Find a user row by username
	 *
	 * @param {string} username The username to search for
	 */
	public getUserRow( username: string ): Locator {
		// Find the row that contains a link starting with the exact username
		// Use regex to match username followed by optional badge text
		const linkPattern = new RegExp( `^${ username.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }(\\s|$)` );
		return this.usersTable.getByRole( 'row' ).filter( { has: this.page.getByRole( 'link', { name: linkPattern } ) } );
	}

	/**
	 * Get the inactive user badge for a specific user
	 *
	 * @param {string} username The username to check for inactive badge
	 */
	public getInactiveUserBadge( username: string ): Locator {
		return this.getUserRow( username ).locator( selectors.inactiveUserBadge ).first();
	}

	public getBlockedUserBadge( username: string ): Locator {
		return this.getUserRow( username ).locator( selectors.blockedUserBadge ).first();
	}

	/**
	 * Get the "Last Seen" column header
	 */
	public getLastSeenColumnHeader(): Locator {
		return this.usersTable.locator( 'thead th' ).filter( { hasText: 'Last seen' } );
	}

	/**
	 * Get the "Last Seen" value for a specific user
	 *
	 * @param {string} username The username to get the "Last Seen" value for
	 */
	public getLastSeenValue( username: string ): Locator {
		// "Last seen" is the 6th td cell (0-indexed: td[5])
		// Columns: username, name, email, role, posts, last-seen, two-factor, wpcom
		return this.getUserRow( username ).locator( 'td' ).nth( 5 );
	}

	/**
	 * Get the unblock link for a specific user
	 *
	 * @param {string} username The username to get the unblock link for
	 */
	public getUnblockLink( username: string ): Locator {
		return this.getLastSeenValue( username ).locator( selectors.unblockLink );
	}

	/**
	 * Click the unblock link for a specific user
	 *
	 * @param {string} username The username to unblock
	 */
	public async clickUnblockLink( username: string ): Promise<void> {
		// Get the href attribute and navigate directly to avoid viewport issues
		const unblockLink = this.getUnblockLink( username );
		const href = await unblockLink.getAttribute( 'href' );
		if ( href ) {
			await this.page.goto( href );
		}
	}

	/**
	 * Get the success notice element
	 */
	public getSuccessNotice(): Locator {
		return this.page.locator( selectors.successNotice );
	}

	/**
	 * Get the MFA disabled warning notice
	 */
	public getErrorNotice(): Locator {
		return this.page.locator( selectors.errorNotice );
	}

	public getInfoNotice(): Locator {
		return this.page.locator( selectors.infoNotice );
	}

	/**
	 * Get filter link by text (e.g., "MFA Disabled", "All")
	 *
	 * @param {string} filterText The text of the filter link
	 */
	public getFilterLink(): Locator {
		return this.page.locator( selectors.mfaFilterLink ).filter( { hasText: 'Filter list to show these users.' } );
	}

	/**
	 * Click a filter link
	 *
	 * @param {string} filterText The text of the filter link to click
	 */
	public async clickFilterLink( ): Promise<void> {
		await this.getFilterLink( ).click();
	}

	/**
	 * Get the Role column header
	 */
	public getRoleColumnHeader(): Locator {
		return this.usersTable.locator( 'thead th' ).filter( { hasText: 'Role' } );
	}

	/**
	 * Get the Two-Factor column header
	 */
	public getTwoFactorColumnHeader(): Locator {
		return this.usersTable.locator( 'thead' ).locator( selectors.twoFactorColumn );
	}

	/**
	 * Get the role value for a specific user
	 *
	 * @param {string} username The username to get the role value for
	 */
	public getRoleValue( username: string ): Locator {
		// Role is the 4th td cell (0-indexed: td[3])
		// Columns: username, name, email, role, posts, last-seen, two-factor, wpcom
		return this.getUserRow( username ).locator( 'td' ).nth( 3 );
	}

	/**
	 * Click the Role column header to sort
	 */
	public async clickRoleColumnHeader(): Promise<void> {
		await this.getRoleColumnHeader().click();
	}

	/**
	 * Get the "Show all users" link from the MFA notice
	 */
	public getShowAllUsersLink(): Locator {
		return this.getInfoNotice().locator( 'a' ).filter( { hasText: 'Show all users' } );
	}
}
