import { expect, test } from '@playwright/test';

import { CmpSettingsPage } from '../lib/pages/cmp-settings-page';

declare global {
	interface Window {
		AgentforceCMP: {
			loadSDK: () => void;
			unloadSDK: () => void;
		};
		AFConsentGranted?: boolean;
	}
}

test.describe( 'CMP Settings', () => {
	test( 'saves consent settings and toggles provider fields', async ( { page } ) => {
		const cmpSettings = new CmpSettingsPage( page );

		await test.step( 'Visit CMP settings page', async () => {
			await cmpSettings.visit();
			await expect( cmpSettings.heading ).toHaveText( 'Agentforce Settings' );
			await expect( cmpSettings.sdkActivationStatus ).toHaveAttribute( 'data-status', 'active' );
			await expect( cmpSettings.sdkUrl ).toHaveAttribute( 'data-url', 'https://example.local' );
		} );

		await test.step( 'Configure Cookiebot consent and SDK URL', async () => {
			await cmpSettings.setConsentType( 'CookieBot' );

			await expect( cmpSettings.onetrustRow ).toBeHidden();
			await expect( cmpSettings.cookiebotRow ).toBeVisible();

			await cmpSettings.cookiebotCategory.fill( 'marketing' );
		} );

		await test.step( 'Save settings and verify persisted values', async () => {
			await cmpSettings.save();

			await expect( cmpSettings.consentType ).toHaveValue( 'CookieBot' );
			await expect( cmpSettings.cookiebotCategory ).toHaveValue( 'marketing' );
		} );
	} );

	test( 'custom consent exposes SDK control API on the frontend', async ( { page } ) => {
		const cmpSettings = new CmpSettingsPage( page );
		const dataUrl = 'https://example.local';

		await test.step( 'Configure Custom consent with SDK URL', async () => {
			await cmpSettings.visit();
			await expect( cmpSettings.sdkActivationStatus ).toHaveAttribute( 'data-status', 'active' );
			await expect( cmpSettings.sdkUrl ).toHaveAttribute( 'data-url', dataUrl );
			await cmpSettings.setConsentType( 'Custom' );

			await cmpSettings.save();
		} );

		await test.step( 'Load frontend and use the exposed API to load/unload the SDK', async () => {
			await page.goto( '/' );

			const apiExists = await page.evaluate( () => typeof window.AgentforceCMP === 'object' );
			expect( apiExists ).toBe( true );

			const afterLoad = await page.evaluate( () => {
				window.AgentforceCMP.loadSDK();
				return {
					hasScript: Boolean( document.getElementById( 'agentforce-sdk' ) ),
					sdkSrc: document.getElementById( 'agentforce-sdk' )?.getAttribute( 'src' ) || '',
					consent: window.AFConsentGranted === true,
				};
			} );

			expect( afterLoad.hasScript ).toBe( true );
			expect( afterLoad.consent ).toBe( true );
			expect( afterLoad.sdkSrc ).toContain( dataUrl );

			const afterUnload = await page.evaluate( () => {
				window.AgentforceCMP.unloadSDK();
				return {
					hasScript: Boolean( document.getElementById( 'agentforce-sdk' ) ),
					consent: window.AFConsentGranted === true,
				};
			} );

			expect( afterUnload.hasScript ).toBe( false );
			expect( afterUnload.consent ).toBe( false );
		} );
	} );
} );
