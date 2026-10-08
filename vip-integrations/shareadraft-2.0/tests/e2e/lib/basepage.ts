import type { Page, Request } from '@playwright/test';

export class BasePage {
    protected readonly _page: Page
    protected _lastNavigationRequest: Request | null = null;

    public constructor(page: Page) {
        this._page = page;

        page.on('requestfinished', (request) => {
            if (request.isNavigationRequest()) {
                this._lastNavigationRequest = request;
            }
        });
    }

    public get page(): Page {
        return this._page;
    }

    public get url(): string {
        return this._page.url();
    }

    public get lastNavigationRequest(): Request | null {
        return this._lastNavigationRequest;
    }
}
