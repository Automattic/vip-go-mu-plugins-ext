import type { Locator, Page, Response } from '@playwright/test';
import { BasePage } from './basepage';

export class LoginPage extends BasePage {
    public readonly loginField: Locator;
    public readonly passwordField: Locator;
    public readonly showPasswordButton: Locator;
    public readonly rememberMeField: Locator;
    public readonly loginButton: Locator;
    public readonly redirectToField: Locator;
    public readonly registerLink: Locator
    public readonly loginErrorBlock: Locator;
    public readonly backToBlogLink: Locator;

    constructor(page: Page) {
        super(page);
        this.loginField = page.locator('input#user_login');
        this.passwordField = page.locator('input#user_pass');
        this.showPasswordButton = page.locator('button.wp-hide-pw');
        this.rememberMeField = page.locator('input#rememberme');
        this.loginButton = page.locator('input#wp-submit');
        this.redirectToField = page.locator('input[name="redirect_to"]');
        this.registerLink = page.locator('#nav a[href*="wp-login.php?action=register"]');
        this.loginErrorBlock = page.locator('div#login_error');
        this.backToBlogLink = page.locator('#backtoblog a');
    }

    /**
     * Returns the main resource response. In case of multiple redirects, the navigation will resolve with the first
     * non-redirect response.
     *
     * The method will throw an error if:
     * - there's an SSL error (e.g. in case of self-signed certificates).
     * - target URL is invalid.
     * - the `timeout` is exceeded during navigation.
     * - the remote server does not respond or is unreachable.
     * - the main resource failed to load.
     *
     * The method will not throw an error when any valid HTTP status code is returned by the remote server, including 404
     * "Not Found" and 500 "Internal Server Error".  The status code for such responses can be retrieved by calling
     * [response.status()](https://playwright.dev/docs/api/class-response#response-status).
     */
    public visit(): Promise<Response> {
        return this.page.goto('./wp-login.php') as Promise<Response>;
    }

    public async login(login: string, password: string, rememberMe: boolean): Promise<unknown> {
        await this.loginField.fill(login);
        await this.passwordField.fill(password);
        if (rememberMe) {
            await this.rememberMeField.check();
        } else {
            await this.rememberMeField.uncheck();
        }

        await this.loginButton.click();
        return this.page.waitForLoadState('load');
    }
}
