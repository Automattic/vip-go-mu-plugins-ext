import type { Locator, Page } from '@playwright/test';

const selectors = {
	usernameInput: '#user_login',
	passwordInput: '#user_pass',
	rememberMeCheckbox: '#rememberme',
	submitButton: '#wp-submit',
	twoFactorSetupNotice: '.two-factor-prompt',
	twoFactorInterstitialPage: '#vip-2fa-error',
};

export class LoginPage {
	private readonly page: Page;

	/**
	 * Constructs an instance of the LoginPage
	 *
	 * @param { Page } page The underlying page
	 */
	constructor( page: Page ) {
		this.page = page;
	}

	/**
	 * Navigate to Login page
	 */
	public visit(): Promise<unknown> {
		return this.page.goto( '/wp-login.php' );
	}

	/**
	 * Get the username input field
	 */
	public getUsernameInput(): Locator {
		return this.page.locator( selectors.usernameInput );
	}

	/**
	 * Get the password input field
	 */
	public getPasswordInput(): Locator {
		return this.page.locator( selectors.passwordInput );
	}

	/**
	 * Get the Remember Me checkbox
	 */
	public getRememberMeCheckbox(): Locator {
		return this.page.locator( selectors.rememberMeCheckbox );
	}

	/**
	 * Get the submit button
	 */
	public getSubmitButton(): Locator {
		return this.page.locator( selectors.submitButton );
	}

	/**
	 * Get the Two-Factor setup notice/prompt
	 */
	public getTwoFactorSetupPrompt(): Locator {
		return this.page.locator( selectors.twoFactorInterstitialPage );
	}

	/**
	 * Login with username and password
	 *
	 * @param {string} username The username
	 * @param {string} password The password
	 * @param {boolean} rememberMe Whether to check the Remember Me checkbox
	 */
	public async login( username: string, password: string, rememberMe: boolean = false ): Promise<void> {
		await this.getUsernameInput().fill( username );
		await this.getPasswordInput().fill( password );

		const rememberMeCheckbox = this.getRememberMeCheckbox();
		if ( rememberMe ) {
			await rememberMeCheckbox.check();
		} else {
			await rememberMeCheckbox.uncheck();
		}

		await this.getSubmitButton().click();
		await this.page.waitForLoadState( 'load' );
	}

	/**
	 * Logout the current user
	 */
	public async logout(): Promise<void> {
		await this.page.goto( '/wp-login.php?action=logout' );
		// Confirm logout if confirmation page appears
		const confirmButton = this.page.locator( 'a:has-text("log out")' );
		if ( await confirmButton.isVisible() ) {
			await confirmButton.click();
			await this.page.waitForLoadState( 'load' );
		}
	}
}
