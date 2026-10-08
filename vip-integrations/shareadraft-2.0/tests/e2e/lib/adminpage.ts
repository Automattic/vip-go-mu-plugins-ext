import type { Locator, Page, Response } from '@playwright/test';
import { BasePage } from './basepage';

export class AdminPage extends BasePage {
    public readonly adminBar: Locator;
    public readonly versionText: Locator;
    public readonly adminMenu: Locator;
    public readonly screenOptionsButton: Locator;
    public readonly screenHelpButton: Locator;
    public readonly pageBody: Locator;

    public readonly menuToggle: Locator;
    public readonly menuDashboard: Locator;
    public readonly menuPosts: Locator;
    public readonly menuMedia: Locator;
    public readonly menuPages: Locator;
    public readonly menuComments: Locator;
    public readonly menuAppearance: Locator;
    public readonly menuPlugins: Locator;
    public readonly menuUsers: Locator;
    public readonly menuTools: Locator;
    public readonly menuSettings: Locator;

    public readonly secondaryMenu: Locator;
    public readonly adminBarUserInfo: Locator;
    public readonly adminBarLogOut: Locator;

    public constructor(page: Page) {
        super(page);

        this.adminBar = page.locator('#wpadminbar');
        this.versionText = page.locator('#footer-upgrade');
        this.adminMenu = page.locator('#adminmenu');
        this.screenOptionsButton = page.locator('#show-settings-link');
        this.screenHelpButton = page.locator('#contextual-help-link');
        this.pageBody = page.locator('#wpbody-content > .wrap');

        this.menuToggle = page.locator('#wp-admin-bar-menu-toggle > a');
        this.menuDashboard = page.locator('#menu-dashboard > a');
        this.menuPosts = page.locator('#menu-posts > a');
        this.menuMedia = page.locator('#menu-media > a');
        this.menuPages = page.locator('#menu-pages > a');
        this.menuComments = page.locator('#menu-comments > a');
        this.menuAppearance = page.locator('#menu-appearance > a');
        this.menuPlugins = page.locator('#menu-plugins > a');
        this.menuUsers = page.locator('#menu-users > a');
        this.menuTools = page.locator('#menu-tools > a');
        this.menuSettings = page.locator('#menu-settings > a');

        this.secondaryMenu = page.locator('#wp-admin-bar-my-account > a');
        this.adminBarUserInfo = page.locator('#wp-admin-bar-user-info > a');
        this.adminBarLogOut = page.locator('#wp-admin-bar-logout > a');
    }

    public visit(): Promise<Response> {
        return this.page.goto('./wp-admin/') as Promise<Response>;
    }

    public getRestNonce(): Promise<string> {
        return this.page.evaluate<string>('wpApiSettings.nonce');
    }

    public async getVersion(): Promise<string> {
        const version = await this.versionText.innerText();
        const matches = /(\d+\.\d+(?:\.\d+)?)/u.exec(version);
        return matches ? matches[1] : '';
    }

    public async showMenu(): Promise<void> {
        const isMenuVisible = await this.menuToggle.isVisible();
        if (isMenuVisible && 'false' === await this.menuToggle.getAttribute('aria-expanded')) {
            await this.menuToggle.click();
        }
    }

    public async hideMenu(): Promise<void> {
        const isMenuVisible = await this.menuToggle.isVisible();
        if (isMenuVisible && 'true' === await this.menuToggle.getAttribute('aria-expanded')) {
            await this.menuToggle.click();
        }
    }

    public async logOut(): Promise<BasePage> {
        await this.secondaryMenu.hover();
        await this.adminBarLogOut.click();
        await this.page.waitForLoadState('domcontentloaded');

        return new BasePage(this.page);
    }
}
