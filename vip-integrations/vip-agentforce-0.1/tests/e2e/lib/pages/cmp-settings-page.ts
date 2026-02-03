import type { Locator, Page } from '@playwright/test';

const selectors = {
	heading: '.agentforce-wrap h1',
	sdkActivationStatus: '#agentforce-sdk-activation-status',
	sdkUrl: '#agentforce-sdk-url',
	consentType: 'select[name="vip_agentforce_consent_type"]',
	onetrustRow: '#row_onetrust',
	onetrustGroupId: 'input[name="vip_agentforce_onetrust_group_id"]',
	cookiebotRow: '#row_cookiebot',
	cookiebotCategory: 'input[name="vip_agentforce_cookiebot_category"]',
	iubendaRow: '#row_iubenda',
	iubendaPurpose: 'input[name="vip_agentforce_iubenda_category"]',
	saveButton: 'button[type="submit"]',
	successNotice: '#setting-error-vip_agentforce_message.notice-success',
};

export class CmpSettingsPage {
	private readonly page: Page;
	public readonly heading: Locator;
	public readonly sdkActivationStatus: Locator;
	public readonly sdkUrl: Locator;
	public readonly consentType: Locator;
	public readonly onetrustRow: Locator;
	public readonly onetrustGroupId: Locator;
	public readonly cookiebotRow: Locator;
	public readonly cookiebotCategory: Locator;
	public readonly iubendaRow: Locator;
	public readonly iubendaPurpose: Locator;
	public readonly saveButton: Locator;
	public readonly successNotice: Locator;

	constructor( page: Page ) {
		this.page = page;
		this.heading = page.locator( selectors.heading );
		this.sdkActivationStatus = page.locator( selectors.sdkActivationStatus );
		this.sdkUrl = page.locator( selectors.sdkUrl );
		this.consentType = page.locator( selectors.consentType );
		this.onetrustRow = page.locator( selectors.onetrustRow );
		this.onetrustGroupId = page.locator( selectors.onetrustGroupId );
		this.cookiebotRow = page.locator( selectors.cookiebotRow );
		this.cookiebotCategory = page.locator( selectors.cookiebotCategory );
		this.iubendaRow = page.locator( selectors.iubendaRow );
		this.iubendaPurpose = page.locator( selectors.iubendaPurpose );
		this.saveButton = page.locator( selectors.saveButton );
		this.successNotice = page.locator( selectors.successNotice );
	}

	public async visit(): Promise<void> {
		await this.page.goto( '/wp-admin/admin.php?page=vip-agentforce-settings' );
		await this.heading.waitFor();
	}

	public async setConsentType( value: 'CookieYes' | 'CookieBot' | 'OneTrust' | 'iubenda' | 'Custom' ): Promise<void> {
		await this.consentType.selectOption( value );
	}

	public async save(): Promise<void> {
		await this.saveButton.click();
		await this.successNotice.waitFor();
	}
}
