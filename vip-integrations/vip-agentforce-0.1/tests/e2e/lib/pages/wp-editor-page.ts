import type { Frame, Locator, Page } from '@playwright/test';

const selectors = {
	// Editor
	editorTitle: '.editor-post-title__input',

	// Block inserter
	blockInserterToggle: 'button.edit-post-header-toolbar__inserter-toggle',
	blockInserterPanel: '.block-editor-inserter__content',
	blockSearch: '.block-editor-inserter__search input[type="search"]',
	blockInserterResultItem: '.block-editor-block-types-list__list-item',

	// Within the editor body.
	blockAppender: '.block-editor-default-block-appender',
	blockInserter: '.block-editor-inserter__toggle',
	paragraphBlocks: 'p.wp-block-paragraph',
	block: '.wp-block[id*="block-"][data-empty="false"]',
	blockWarning: '.block-editor-warning',
	imageBlocks: '.editor-block-list-item-image',
	uploadImageButton: '.block-editor-media-placeholder__upload-button',
	firstEmptyBlock: '.wp-block-paragraph[data-empty="true"]',
	spinner: '.components-spinner',

	// Top bar selectors.
	postToolbar: '.edit-post-header',
	settingsToggle: '.edit-post-header__settings .interface-pinned-items button:first-child',
	saveDraftButton: '.editor-post-save-draft',
	previewButton: ':is(button:text("Preview"), a:text("Preview"))',
	publishButton: ( parentSelector: string ) =>
		`${ parentSelector } button:text("Publish")[aria-disabled=false]`,
	updateButton: '.editor-post-publish-button',
	// Settings panel.
	settingsPanel: '.interface-complementary-area',

	// Publish panel (including post-publish)
	publishPanel: '.editor-post-publish-panel',
	viewButton: '.editor-post-publish-panel a:has-text("View")',
	addNewButton: '.editor-post-publish-panel a:text-matches("Add a New P(ost|age)")',
	closePublishPanel: 'button[aria-label="Close panel"]',

	// Welcome tour
	welcomeTourCloseButton: '.edit-post-welcome-guide .components-modal__header button',

	// Block editor sidebar
	desktopEditorSidebarButton: 'button[aria-label="Block editor sidebar"]:visible',
	desktopDashboardLink: 'a[aria-description="Returns to the dashboard"]:visible',
	mobileDashboardLink: 'a[aria-current="page"]:visible',

	// Choose a pattern
	choosePatternCloseButton: '.components-modal__screen-overlay .components-modal__header button',
};

export class EditorPage {
	private page: Page;

	/**
	 * Constructs an instance of the component.
	 *
	 * @param { Page } page The underlying page
	 */
	constructor( page: Page ) {
		this.page = page;
	}

	/**
	 * Dismisses the Welcome Tour (card) if it is present.
	 */
	public dismissWelcomeTour(): Promise<void> {
		return this.clickButtonIfExists( this.page.locator( selectors.welcomeTourCloseButton ) );
	}

	public dismissPatternSelector(): Promise<void> {
		return this.clickButtonIfExists( this.page.locator( selectors.choosePatternCloseButton ) );
	}

	private async clickButtonIfExists( locator: Locator ): Promise<void> {
		try {
			await locator.click( { timeout: 5000, trial: true } );
		} catch {
			return;
		}

		return locator.click( { delay: 20, timeout: 1000 } );
	}

	private async getEditorFrame(): Promise<Frame | null> {
		const existingFrame = this.page.frame( { name: 'editor-canvas' } );
		if ( existingFrame ) {
			return existingFrame;
		}

		try {
			await this.page.waitForSelector( 'iframe[name="editor-canvas"]', {
				state: 'attached',
				timeout: 5000,
			} );
		} catch {
			return null;
		}

		return this.page.frame( { name: 'editor-canvas' } );
	}

	private async getEditorScope(): Promise<Page | Frame> {
		const frame = await this.getEditorFrame();
		return frame ?? this.page;
	}

	/**
	 * Enter Title of page or post
	 *
	 * @param {string} title Page/Post Title
	 */
	public async enterTitle( title: string ): Promise<void> {
		await this.dismissPatternSelector();

		const editor = await this.getEditorScope();
		await editor.click( selectors.editorTitle );
		await editor.fill( selectors.editorTitle, title );
	}

	/**
	 * Enter text in to page or post
	 *
	 * @param {string} text Text to enter
	 */
	public async enterText( text: string ): Promise<void> {
		const lines = text.split( '\n' );
		const editor = await this.getEditorScope();

		if ( await editor.isVisible( selectors.blockAppender ) ) {
			await editor.click( selectors.blockAppender );
		} else {
			await editor.click( selectors.paragraphBlocks );
		}

		// Playwright does not break up newlines in Gutenberg. This causes issues when we expect
		// text to be broken into new lines/blocks. This presents an unexpected issue when entering
		// text such as 'First sentence\nSecond sentence', as it is all put in one line.
		// frame.type() will respect newlines like a human would, but it is slow.
		// This approach will run faster than using frame.type() while respecting the newline chars.
		await Promise.all(
			lines.map( async ( line, index ) => {
				await editor.fill( `${ selectors.paragraphBlocks }:nth-of-type(${ index + 1 })`, line );
				await this.page.keyboard.press( 'Enter' );
			} ),
		);
	}

	/**
	 * Clear Title of page or post
	 */
	public async clearTitle(): Promise<void> {
		const editor = await this.getEditorScope();
		await editor.click( selectors.editorTitle );
		await this.page.keyboard.down( 'Shift' );
		await this.page.keyboard.press( 'Home' );
		await this.page.keyboard.up( 'Shift' );
		await this.page.keyboard.press( 'Backspace' );
	}

	/**
	 * Clear text of page or post
	 */
	public async clearText(): Promise<void> {
		const editor = await this.getEditorScope();

		/* eslint-disable no-await-in-loop */
		while ( await editor.isVisible( selectors.block ) ) {
			await editor.click( selectors.block );
			await this.page.keyboard.down( 'Shift' );
			await this.page.keyboard.press( 'Home' );
			await this.page.keyboard.up( 'Shift' );
			await this.page.keyboard.press( 'Backspace' );
			await this.page.keyboard.press( 'Backspace' );
		}
		/* eslint-enable no-await-in-loop */
	}

	/**
	 * Add Image to Post or Page
	 *
	 * @param {string} fileName Name of image file to add
	 */
	public async addImage( fileName: string ): Promise<void> {
		await this.dismissPatternSelector();
		const editor = await this.getEditorScope();

		if ( await editor.isVisible( selectors.blockAppender ) ) {
			await editor.click( selectors.blockAppender );
		} else {
			const lastBlock = editor.locator( selectors.paragraphBlocks ).last();
			if ( await lastBlock.count() ) {
				await lastBlock.click();
			}
		}

		let blockInserted = false;
		const inserterToggle = this.page.locator( selectors.blockInserterToggle );
		if ( await inserterToggle.isVisible() ) {
			try {
				await inserterToggle.click();
				const inserterPanel = this.page.locator( selectors.blockInserterPanel );
				const searchInput = this.page.locator( selectors.blockSearch );
				await inserterPanel.waitFor( { state: 'visible', timeout: 5000 } );
				await searchInput.fill( 'Image' );
				const imageBlockResult = this.page
					.locator( selectors.blockInserterResultItem )
					.filter( { hasText: /Image/i } )
					.first();
				await imageBlockResult.click( { timeout: 5000 } );
				await inserterPanel.waitFor( { state: 'hidden', timeout: 5000 } );
				blockInserted = true;
			} catch {
				blockInserted = false;
			}
		}

		if ( ! blockInserted ) {
			const inlineInserter = editor.locator( selectors.blockInserter ).first();
			await inlineInserter.click();
			await editor.locator( selectors.imageBlocks ).first().click();
		}

		const [ fileChooser ] = await Promise.all( [
			// It is important to call waitForEvent before click to set up waiting.
			this.page.waitForEvent( 'filechooser' ),
			// This has to click twice, the first focuses in the block, the second opens the upload
			editor.click( selectors.uploadImageButton ),
			editor.click( selectors.uploadImageButton ),
		] );
		await fileChooser.setFiles( fileName );
		await editor.locator( selectors.spinner ).waitFor( { state: 'detached' } );
	}

	/**
	 * Publishes the post or page.
	 *
	 * @param {boolean} visit Whether to then visit the page.
	 * @return {string} Url of published post or page
	 */
	public async publish( { visit = false }: { visit?: boolean } = {} ): Promise<string> {
		await this.page.click( selectors.publishButton( selectors.postToolbar ) );
		await this.page.click( selectors.publishButton( selectors.publishPanel ) );
		const publishedURL = ( await this.page.locator( selectors.viewButton ).getAttribute( 'href' ) )!;

		if ( visit ) {
			await this.visitPublishedPost( publishedURL );
		}
		return publishedURL;
	}

	/**
	 * Updates the post or page.
	 */
	public update(): Promise<void> {
		return this.page.click( selectors.updateButton );
	}

	/**
	 * Visits the published entry from the post-publish sidebar.
	 *
	 * @param {string} url Url to visit
	 */
	private visitPublishedPost( url: string ): Promise<unknown> {
		return Promise.all( [
			this.page.waitForURL( url, { waitUntil: 'load' } ),
			this.page.click( selectors.viewButton ),
		] );
	}
}
