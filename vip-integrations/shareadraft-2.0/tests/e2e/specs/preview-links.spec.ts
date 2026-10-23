import { type Browser, type Page, expect, test } from '@playwright/test';

/**
 * Full-stack journey for the one thing only a browser can prove: an editor
 * mints a preview link from the block-editor sidebar (or the classic screen's
 * meta box), an anonymous visitor loads that link and sees the draft, and once
 * the editor revokes it the same visitor is turned away with a friendly notice.
 *
 * The narrow rules behind each step (token hashing, distinct-viewer caps,
 * revocation-beats-expiry, bot handling) are already covered by the PHP unit
 * and integration suites, so they are deliberately not re-tested here.
 */

// A distinctive marker we can assert on in the anonymously rendered draft.
const BODY_MARKER = 'Live preview body marker 4f9a2c';

// A plain desktop UA so the gate's bot heuristic treats the anonymous visitor
// as a human rather than serving the contentless stub.
const HUMAN_USER_AGENT =
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

/** Expand a collapsible control (settings toggle, panel) if present and not already open. */
async function ensureExpanded( control: ReturnType<Page['getByRole']>, attribute: string ): Promise<void> {
	if ( ( await control.count() ) === 0 ) {
		return;
	}
	if ( ( await control.first().getAttribute( attribute ) ) === 'false' ) {
		await control.first().click();
	}
}

/**
 * Turn off the "Welcome to the editor" guide, which renders a focus-trapping
 * overlay a beat after the editor mounts. Flipping the preference unmounts it
 * even if it is already open; the Close button is a fallback for older cores.
 */
async function dismissWelcomeGuide( page: Page ): Promise<void> {
	await page.evaluate( () => {
		const preferences = ( window as unknown as { wp?: { data?: { dispatch?: ( store: string ) => { set?: ( scope: string, name: string, value: boolean ) => void } } } } ).wp?.data?.dispatch?.( 'core/preferences' );
		preferences?.set?.( 'core/edit-post', 'welcomeGuide', false );
		preferences?.set?.( 'core', 'welcomeGuide', false );
	} );

	const close = page.getByRole( 'dialog', { name: /Welcome to/i } ).getByRole( 'button', { name: 'Close' } );
	if ( await close.isVisible().catch( () => false ) ) {
		await close.click();
	}
}

/** Show the Share a Draft panel under the document (Post) tab of the editor sidebar. */
async function openSharePanel( page: Page ): Promise<void> {
	// Match on the tab's visible text rather than its computed accessible name,
	// which WP leaves empty.
	await page.locator( '[role="tab"]' ).filter( { hasText: 'Post' } ).click();
	await ensureExpanded( page.getByRole( 'button', { name: 'Settings', exact: true } ), 'aria-pressed' );
	await ensureExpanded( page.getByRole( 'button', { name: 'Share a Draft' } ), 'aria-expanded' );
}

/** Open a fresh, logged-out browser context and read the given preview URL as a visitor would. */
async function visitAsAnonymous( browser: Browser, url: string ): Promise<Page> {
	const context = await browser.newContext( { storageState: undefined, userAgent: HUMAN_USER_AGENT } );
	const page = await context.newPage();
	await page.goto( url );
	return page;
}

test.describe( 'Preview links', () => {
	// Uses the editor session captured by setup.ts.
	test.use( { storageState: '.playwright/state.json' } );

	test( 'an editor mints, shares, and revokes a preview link', async ( { page, browser } ) => {
		// The block editor loads at 30s under a cold container; give it headroom.
		test.setTimeout( 90000 );

		// --- Draft a post in the block editor -------------------------------
		await page.goto( './wp-admin/post-new.php' );

		// The post title and body live inside the editor-canvas iframe; the
		// sidebar, panels, and modals stay in the main document.
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await canvas.getByRole( 'textbox', { name: 'Add title' } ).fill( 'Live preview E2E draft' );

		// With the editor now interactive, suppress the welcome guide before it
		// can overlay the sidebar we are about to drive.
		await dismissWelcomeGuide( page );

		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( BODY_MARKER );

		// Saving the draft gives the post an ID (enabling the panel buttons).
		// Use the keyboard shortcut rather than the toolbar "Save draft" button,
		// whose enabled state races with Gutenberg's own autosave.
		await page.keyboard.press( 'ControlOrMeta+s' );
		await page.waitForURL( /[?&]post=\d+/u );

		// --- The Share a Draft sidebar panel --------------------------------
		// Editing the paragraph switched the sidebar to the Block tab; the plugin
		// panel lives under the document (Post) tab.
		await openSharePanel( page );

		const generateButton = page.getByRole( 'button', { name: 'Generate preview link' } );
		const manageButton = page.getByRole( 'button', { name: 'Manage preview links' } );
		await expect( generateButton ).toBeVisible();
		await expect( manageButton ).toBeVisible();

		// --- Generate a link ------------------------------------------------
		await generateButton.click();
		const generateModal = page.getByRole( 'dialog', { name: 'Generate preview link' } );
		await expect( generateModal ).toBeVisible();

		// Hold the mint request (a POST to the collection, not to a link's
		// id-bearing path) so the dialog can be checked while it is in flight.
		let mintRequests = 0;
		let releaseMint = (): void => {};
		const mintHeld = new Promise<void>( ( resolve ) => {
			releaseMint = resolve;
		} );
		const isMint = ( url: URL ): boolean =>
			/shareadraft\/v1\/preview-links(?!\/)/u.test( decodeURIComponent( url.href ) );
		await page.route( isMint, async ( route ) => {
			if ( route.request().method() !== 'POST' ) {
				return route.fallback();
			}
			mintRequests++;
			await mintHeld;
			return route.fallback();
		} );

		await generateModal.getByRole( 'button', { name: 'Generate link' } ).click();

		// Closing now would lose the only copy of a link the server still
		// creates, and editing a field would make the form disagree with it.
		// Focus has to stay in the dialog for Escape to reach it at all. Reduced
		// motion skips the modal's exit animation, so a wrongful close shows
		// up at once rather than after the dialog has passed as visible.
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await expect( generateModal.getByRole( 'button', { name: 'Close' } ) ).toHaveCount( 0 );
		await expect( generateModal.getByRole( 'button', { name: 'Generate link' } ) ).toBeFocused();
		await page.keyboard.press( 'Escape' );
		await expect( generateModal ).toBeVisible();
		await expect( generateModal.getByLabel( 'Link expiration' ) ).toBeDisabled();
		await expect( generateModal.getByLabel( 'Maximum uses' ) ).toBeDisabled();

		releaseMint();

		// The minted URL is surfaced in a read-only field once the request lands.
		const linkField = generateModal.getByRole( 'textbox', { name: 'Preview link' } );
		await expect( linkField ).toBeVisible();
		await page.unroute( isMint );
		expect( mintRequests ).toBe( 1 );
		const previewUrl = await linkField.inputValue();
		expect( previewUrl ).toContain( 'shareadraft-token=' );

		// Copying again must reuse that link, not mint a second one.
		await generateModal.getByRole( 'button', { name: 'Copy link' } ).click();
		await expect( linkField ).toHaveValue( previewUrl );

		await generateModal.getByRole( 'button', { name: 'Close' } ).click();
		await expect( generateModal ).toBeHidden();

		// --- An anonymous visitor sees the draft ----------------------------
		const visitor = await visitAsAnonymous( browser, previewUrl );
		await expect( visitor.getByText( BODY_MARKER ) ).toBeVisible();
		await expect( visitor.getByText( 'This preview link has been revoked.' ) ).toHaveCount( 0 );

		// --- Manage and revoke the link -------------------------------------
		await manageButton.click();
		const manageModal = page.getByRole( 'dialog', { name: 'Manage preview links' } );
		await expect( manageModal ).toBeVisible();

		const revokeButton = manageModal.getByRole( 'button', { name: 'Revoke' } );
		await expect( revokeButton ).toBeVisible();

		// The modal drops the link from the list optimistically, before the
		// revoke request lands. Wait for that request to complete so the
		// revocation has taken effect server-side before the visitor tries the
		// link again. apiFetch sends the DELETE over the wire as a POST to the
		// id-bearing path, which is what distinguishes it from the earlier mint.
		const revoked = page.waitForResponse(
			( response ) =>
				/\/shareadraft\/v1\/preview-links\/[a-f0-9]{64}/u.test( response.url() ) &&
				[ 'DELETE', 'POST' ].includes( response.request().method() ) &&
				response.ok()
		);
		await revokeButton.click();
		await revoked;
		await expect( manageModal.getByText( 'No active preview links.' ) ).toBeVisible();

		// --- The same link now turns the visitor away -----------------------
		await visitor.goto( previewUrl );
		await expect( visitor.getByText( 'This preview link has been revoked.' ) ).toBeVisible();
		await expect( visitor.getByText( BODY_MARKER ) ).toHaveCount( 0 );

		await visitor.context().close();
	} );

	test( 'the classic edit screen mints and revokes a link from its meta box', async ( { page, browser } ) => {
		test.setTimeout( 90000 );

		// --- Draft a post on the classic screen -----------------------------
		// CI installs the Classic Editor plugin set to "block editor by default,
		// users may switch" (see .github/workflows/e2e.yml). These query args
		// are its "switch to classic" link for a new post, so only this journey
		// gets the classic screen.
		await page.goto( './wp-admin/post-new.php?classic-editor&classic-editor__forget' );
		await expect(
			page.locator( '#title' ),
			'The classic edit screen did not load: is the Classic Editor plugin active and set to let users switch?'
		).toBeVisible();

		await page.locator( '#title' ).fill( 'Classic preview E2E draft' );
		await page.locator( '#content-html' ).click();
		await page.locator( 'textarea#content' ).fill( BODY_MARKER );
		await page.locator( '#save-post' ).click();
		await page.waitForURL( /post\.php\?post=\d+/u );

		// --- Generate a link from the meta box -------------------------------
		const metaBox = page.locator( '#shareadraft' );
		await metaBox.getByRole( 'button', { name: 'Generate preview link' } ).click();

		const generateModal = page.getByRole( 'dialog', { name: 'Generate preview link' } );
		await expect( generateModal ).toBeVisible();
		// The block editor's styles are absent here; an overlay that is not
		// fixed means the components stylesheet never loaded.
		await expect( page.locator( '.components-modal__screen-overlay' ) ).toHaveCSS( 'position', 'fixed' );

		await generateModal.getByRole( 'button', { name: 'Generate link' } ).click();
		const linkField = generateModal.getByRole( 'textbox', { name: 'Preview link' } );
		await expect( linkField ).toBeVisible();
		const previewUrl = await linkField.inputValue();
		await generateModal.getByRole( 'button', { name: 'Close' } ).click();

		const visitor = await visitAsAnonymous( browser, previewUrl );
		await expect( visitor.getByText( BODY_MARKER ) ).toBeVisible();

		// --- Revoke it from the meta box -------------------------------------
		await metaBox.getByRole( 'button', { name: 'Manage preview links' } ).click();
		const manageModal = page.getByRole( 'dialog', { name: 'Manage preview links' } );
		const revoked = page.waitForResponse(
			( response ) =>
				/\/shareadraft\/v1\/preview-links\/[a-f0-9]{64}/u.test( response.url() ) &&
				[ 'DELETE', 'POST' ].includes( response.request().method() ) &&
				response.ok()
		);
		await manageModal.getByRole( 'button', { name: 'Revoke' } ).click();
		await revoked;

		await visitor.goto( previewUrl );
		await expect( visitor.getByText( 'This preview link has been revoked.' ) ).toBeVisible();

		await visitor.context().close();
	} );

	test( 'a failed link load leaves Manage usable', async ( { page } ) => {
		test.setTimeout( 90000 );

		// --- A draft with a live link, minted over REST ----------------------
		await page.goto( './wp-admin/' );
		const nonce = await page.evaluate<string>( 'wpApiSettings.nonce' );
		const headers = { 'X-WP-Nonce': nonce };

		const created = await page.request.post( './wp-json/wp/v2/posts', {
			headers,
			// A block in the content keeps it in the block editor: the Classic
			// Editor plugin CI installs opens block-less posts on the classic screen.
			data: {
				title: 'Failed load E2E draft',
				status: 'draft',
				content: '<!-- wp:paragraph --><p>Failed load body</p><!-- /wp:paragraph -->',
			},
		} );
		expect( created.ok() ).toBeTruthy();
		const { id } = ( await created.json() ) as { id: number };

		const minted = await page.request.post( './wp-json/shareadraft/v1/preview-links', {
			headers,
			data: { post_id: id, expiration: 3600 },
		} );
		expect( minted.ok() ).toBeTruthy();

		// --- The panel's own check succeeds, enabling Manage -----------------
		await page.goto( `./wp-admin/post.php?post=${ id }&action=edit` );
		await expect(
			page.frameLocator( 'iframe[name="editor-canvas"]' ).getByRole( 'textbox', { name: 'Add title' } )
		).toBeVisible();
		await dismissWelcomeGuide( page );
		await openSharePanel( page );

		const manageButton = page.getByRole( 'button', { name: 'Manage preview links' } );
		await expect( manageButton ).toBeEnabled();

		// --- Then the modal's load fails --------------------------------------
		// Only the list request (no link id in the path), so nothing else breaks.
		await page.route(
			( url ) => /shareadraft\/v1\/preview-links(?!\/)/u.test( decodeURIComponent( url.href ) ),
			( route ) =>
				'GET' === route.request().method()
					? route.fulfill( {
							status: 500,
							json: { code: 'e2e_failure', message: 'Simulated load failure.' },
						} )
					: route.fallback()
		);

		await manageButton.click();
		const manageModal = page.getByRole( 'dialog', { name: 'Manage preview links' } );
		const notice = manageModal.locator( '.components-notice.is-error' );
		await expect( notice ).toContainText( 'Simulated load failure.' );

		// Unknown is not empty: no "no links" claim, and no endless spinner
		// left behind by dismissing the only thing on screen.
		await expect( manageModal.getByText( 'No active preview links.' ) ).toHaveCount( 0 );
		await expect( notice.locator( '.components-notice__dismiss' ) ).toHaveCount( 0 );
		await expect( manageModal.locator( '.components-spinner' ) ).toHaveCount( 0 );

		// Closing and reopening is the retry, so Manage must stay usable.
		await manageModal.getByRole( 'button', { name: 'Close' } ).click();
		await expect( manageModal ).toBeHidden();
		await expect( manageButton ).toBeEnabled();
	} );
} );
