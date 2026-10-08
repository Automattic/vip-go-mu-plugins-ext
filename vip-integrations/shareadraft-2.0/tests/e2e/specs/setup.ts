import { open, writeFile, constants } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { LoginPage } from '../lib/login.pom';
import { AdminPage } from '../lib/adminpage';

const storagePath = '.playwright/state.json';

test.describe('Set up', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: storagePath });

    test.beforeAll(async () => {
        try {
            const fd = await open(storagePath, constants.O_CREAT | constants.O_EXCL | constants.O_WRONLY, 0o644);
            await writeFile(fd, '{}');
            await fd.close();
        } catch {
            // Do nothing, file exists
        }
    });

    test('Codespaces', async ({ page, baseURL }) => {
        const url = new URL(baseURL ?? '');
        if (url.hostname.endsWith('.app.github.dev')) {
            await page.goto('.');
            const title = await page.title();
            if (title.trim() === 'Codespaces Access Port') {
                await page.getByRole('button', { name: 'Continue', exact: true }).click();
                await expect(page).toHaveTitle(/WordPress/u);
            }
        }

        await page.context().storageState({ path: storagePath });
    });

    test('Log in as vipgo', async ({ page }) => {
        const loginPage = new LoginPage(page);
        await loginPage.visit();
        await loginPage.login('vipgo', 'password', true);
        await page.context().storageState({ path: storagePath });
    });

    test('Gather info', async ({ page }) => {
        const adminPage = new AdminPage(page);
        await adminPage.visit();
        await page.waitForLoadState('domcontentloaded');
        process.env.WP_REST_NONCE = await adminPage.getRestNonce();
        process.env.WP_VERSION = await adminPage.getVersion();
    });
});
