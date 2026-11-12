/**
 * External dependencies
 */
import { PlaywrightTestConfig } from '@playwright/test';

import { getSecurityBoostConfigHeaders } from './lib/security-boost-config-helper';

const config: PlaywrightTestConfig = {
	retries: 1,
	globalSetup: require.resolve( './lib/global-setup' ),
	timeout: 120000,
	reporter: process.env.CI ? [ [ 'github' ] ] : 'line',
	reportSlowTests: null,
	workers: process.env.CI ? 1 : undefined,
	use: {
		headless: process.env.DEBUG_TESTS !== 'true',
		viewport: { width: 1280, height: 1000 },
		ignoreHTTPSErrors: true,
		video: 'retain-on-failure',
		trace: 'retain-on-failure',
		storageState: 'e2eStorageState.json',
		baseURL: process.env.E2E_BASE_URL
			? process.env.E2E_BASE_URL
			: 'http://e2e-sb-test-site.vipdev.lndo.site',
		extraHTTPHeaders: getSecurityBoostConfigHeaders(),
	},
};

export default config;
