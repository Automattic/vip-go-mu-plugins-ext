=== Gist Answers ===
Contributors:       prorataai
Tags:               ai, answers, widgets, content ingestion, multisite
Requires at least:  6.8
Tested up to:       7.0
Requires PHP:       8.0
Stable tag:         1.0.16
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Connects WordPress to Gist.ai for AI answer widgets, content ingestion, reporting, website settings, and multisite management.

== Description ==

Gist Answers connects WordPress sites with Gist.ai, providing a WordPress integration layer for Gist widgets, content ingestion, reporting, website settings, and publisher management tools.

The plugin allows publishers to connect one or more WordPress sites to Gist.ai, keep eligible content synchronized with Gist, and place Gist Question and Answer Widgets throughout their sites using Gutenberg blocks, shortcodes, sidebar widgets, or PHP template functions.

Gist Answers also provides convenient access to Gist CMS features directly from the WordPress admin, including reporting, website settings, widget management, Sandbox tools, and Suggested Questions.

= Key Features =

* Connect WordPress to a Gist.ai partner account using a Gist API key.
* Support API keys supplied through the WordPress VIP integration config, a server environment variable, a PHP constant, or the plugin dashboard.
* Match or activate WordPress sites as Gist.ai publishers.
* Support WordPress Multisite and multi-publication environments.
* Initiate, pause, and resume Gist content ingestion.
* Notify Gist.ai when eligible WordPress content is published or updated.
* Allow developers to customize ingested post types, post statuses, taxonomy requirements, and per-post eligibility.
* Display Gist Question and Answer Widgets.
* Provide Gutenberg blocks for Question and Answer Widgets.
* Provide a classic sidebar widget for Gist Question Widgets.
* Support shortcode-based widget placement.
* Provide a PHP rendering function for themes and custom templates.
* Detect Answer Widgets placed in WordPress content.
* Support creation and updating of WordPress Answer Pages from the Gist widget-management experience.
* Provide access to Gist Answers, Content Usage, and Revenue reports.
* Provide access to Gist Website Settings, Sandbox, Suggested Questions, and widget-management tools.
* Provide a searchable, sortable, and paginated Network Hub for Multisite installations.
* Provide developer hooks and optional diagnostic tools.

= Typical Use Cases =

* Add Gist Question Widgets to posts, pages, sidebars, or templates.
* Create Answer Pages containing Gist Answer Widgets.
* Connect a WordPress publication to an existing Gist.ai publisher.
* Activate a new WordPress site within Gist.ai.
* Start or pause content ingestion from WordPress.
* Keep eligible published content synchronized with Gist.ai.
* Limit ingestion to specific post types, statuses, categories, tags, or other taxonomies.
* Review answer engagement and content usage reports.
* Manage Gist.ai website settings from the WordPress admin.
* Search, sort, and manage sites from the Gist Answers Multisite Network Hub.

== Installation ==

1. Upload the `gist-answers` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress admin.
2. Activate Gist Answers from the `Plugins` screen.
3. Open the Gist Answers dashboard.
4. Provide a valid Gist.ai API key if one has not already been configured through the WordPress VIP integration config, the server environment, or a PHP constant.
5. Allow the plugin to validate the connection and retrieve the associated Gist.ai publisher information.
6. If necessary, activate the WordPress site as a new Gist.ai publisher.
7. Configure content ingestion and Gist.ai website settings as needed.
8. Add Question or Answer Widgets using the available Gutenberg blocks, shortcode, sidebar widget, or PHP function.

== Frequently Asked Questions ==

= Does Gist Answers replace the Gist.ai CMS? =

No. Gist Answers connects WordPress with the Gist.ai CMS and API. Reporting, website configuration, widget management, Sandbox tools, Suggested Questions, and other Gist features remain managed by Gist.ai and may be displayed within WordPress through the plugin.

= How is the Gist.ai API key configured? =

Gist Answers supports four API-key sources, checked in the following order:

1. On WordPress VIP, the `api_token` in the `VIP_GIST_ANSWERS_CONFIG` integration config
2. Server environment variable: `GIST_ANSWERS_API_KEY`
3. PHP constant: `GIST_ANSWERS_API_KEY`
4. API key entered through the Gist Answers dashboard

When the VIP integration config is present, it is always used, even if it is incomplete, and the other sources are ignored.

When entered through the plugin dashboard, the API key is encrypted before being stored in the WordPress database.

Until an API key is configured, the plugin does not contact Gist.ai.

= Does Gist Answers support WordPress Multisite? =

Yes. On Multisite installations, the Network Hub provides a central location for reviewing and managing Gist Answers across the sites in the network that you are a member of.

The Gist API connection is shared across the network (the API key is stored network-wide), while individual WordPress sites maintain their own Gist publisher associations.

The Network Hub also provides site-title search, sorting, pagination, activation controls, ingestion status, and site-specific Gist Answers actions.

= What kinds of widgets are supported? =

Gist Answers supports two primary widget types:

* Question Widgets
* Answer Widgets

Question Widgets allow visitors to submit questions and can direct visitors to an associated Answer Page.

Answer Widgets provide the Gist answer/chat experience.

= How can widgets be added to WordPress? =

Widgets can be added using:

* Gutenberg blocks
* A classic sidebar widget for Question Widgets
* The `[gist_answers_render_widget]` shortcode
* The `gist_answers_render_widget()` PHP function in theme templates or custom integrations

= Can multiple Gist widgets be used on the same site? =

Yes. The plugin retrieves the widgets available to the current Gist.ai publisher and allows the appropriate widget ID to be selected or supplied when rendering a widget.

= How does content ingestion work? =

Gist Answers configures the WordPress site as a content source for Gist.ai and can start or pause the associated content crawl.

The plugin also monitors eligible WordPress content changes and queues matching content for ingestion by Gist.ai. Content is queued when it is saved by a user who can edit it, or published by WordPress itself (for example, when a scheduled post goes live). Queued content is sent to Gist.ai shortly afterwards by WP-Cron, and also during activity in the WordPress admin, in batches of up to 10 posts by default. If WP-Cron is disabled on the site, queued content is sent during admin activity only.

By default, ongoing ingestion applies to published WordPress posts. Pages and other post types are included only if a filter adds them.

Developers can customize ingestion using WordPress filters for:

* Post types
* Post statuses
* Taxonomy queries
* Individual post eligibility
* Processing limits
* Delay before ingestion

= Can ingestion be limited by category, tag, or another taxonomy? =

Yes. Developers can use the `gist_answers_ingest_tax_query` filter to provide a standard WordPress taxonomy query.

This makes it possible to include or exclude content according to categories, tags, or supported custom taxonomies.

= Why does a newly created Gist widget not appear immediately in WordPress? =

There may be a short delay before newly created or updated Gist widgets become available in WordPress. Allow a few minutes and then refresh the relevant plugin screen.

= Does the plugin automatically detect Answer Pages? =

Gist Answers can detect Answer Widgets placed in published WordPress posts and pages using the Gutenberg block, shortcode, or pasted Gist widget HTML. Detection happens when the post or page is saved, so existing content is detected the next time it is updated.

Answer Widget blocks placed inside other blocks (such as Group or Columns blocks) are not currently detected.

Widgets rendered only through PHP templates are not present in WordPress post content and therefore cannot be automatically detected through normal content scanning.

= Can Gist create or update a WordPress Answer Page? =

Yes. The embedded Gist widget-management workflow can request creation or updating of a WordPress Answer Page.

These requests are protected by WordPress capability checks and validation associated with the current Gist embed session.

The plugin also prevents Answer Page operations from unintentionally overwriting existing WordPress content.

== Shortcodes ==

Gist Answers provides the following shortcode:

`[gist_answers_render_widget]`

= Question Widget =

Specify the widget type and Gist widget ID. The type may be `question` (or `search` or `questions`) or `answer` (or `chat` or `answers`).

`[gist_answers_render_widget type="question" id="search-widget-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"]`

= Answer Widget =

An Answer Widget may be rendered with a specific widget ID:

`[gist_answers_render_widget type="answer" id="chat-widget-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"]`

If no valid widget ID is supplied, the plugin determines the Answer Widget from the Answer Page it belongs to: the page path given in `target`, or otherwise the current page's slug.

`[gist_answers_render_widget type="answer" target="answers"]`

== Blocks ==

Gist Answers provides two Gutenberg blocks in the Gist Answers block category.

= Gist Question Widget =

Block name:

`gist-answers/question`

Displays a Gist Question Widget selected from the widgets available to the current site.

= Gist Answer Widget =

Block name:

`gist-answers/answer`

Displays a Gist Answer Widget. The plugin can use a selected widget ID or determine the appropriate Answer Widget for the current Answer Page where configured.

== PHP Integration ==

Widgets can also be rendered directly from WordPress theme templates or custom PHP code using:

`gist_answers_render_widget()`

Example:

`<?php
if ( function_exists( 'gist_answers_render_widget' ) ) {
	gist_answers_render_widget(
		array(
			'type' => 'question',
			'id'   => 'search-widget-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
		)
	);
}
?>`

To capture the rendered widget HTML instead of outputting it directly:

`<?php
if ( function_exists( 'gist_answers_render_widget' ) ) {
	$widget_html = gist_answers_render_widget(
		array(
			'type' => 'question',
			'id'   => 'search-widget-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
			'echo' => false,
		)
	);
}
?>`

== Configuration ==

Depending on the site and Gist.ai account configuration, Gist Answers manages or provides access to:

* Gist.ai API connection.
* Publisher-group association.
* Publisher/website association.
* Website activation.
* Gist.ai website name and category.
* Homepage and development-site URLs.
* Content sources.
* Content-ingestion status.
* Question and Answer Widgets.
* Answer Page associations.
* Answer engagement reports.
* Content Usage reports.
* Revenue reports.
* Sandbox.
* Suggested Questions.

== Multisite ==

On WordPress Multisite installations, Gist Answers provides a Network Hub for reviewing and managing the sites in the network that you are a member of.

The Network Hub provides:

* Site-title search.
* Ascending and descending site-title sorting.
* Pagination for larger networks.
* Site activation status.
* Content-ingestion status and controls.
* Links to site-specific Gist Answers administration.
* Access to widget setup and widget-management tools.

By default, the Network Hub displays 20 sites per page. Developers can customize the page size (up to 100) using the `gist_answers_network_sites_per_page` filter.

== Developer Features ==

Gist Answers provides WordPress filters and actions for developers who need to customize widget rendering, content ingestion, Answer Page detection, Network Hub behavior, or respond to Gist API activity.

Content-ingestion filters include:

* `gist_answers_should_enqueue_post_for_ingestion`
* `gist_answers_ingest_post_types`
* `gist_answers_ingest_post_statuses`
* `gist_answers_ingest_tax_query`
* `gist_answers_max_posts_for_ingestion`
* `gist_answers_min_delay_for_ingestion`

Answer Page detection filters:

* `gist_answers_answer_page_types`
* `gist_answers_answer_page_statuses`
* `gist_answers_max_flagged_answer_widget_posts`

Widget rendering filters:

* `gist_answers_render_widget`
* `gist_answers_widget_html`

Other filters:

* `gist_answers_network_sites_per_page`
* `gist_answers_api_timeout`
* `gist_answers_error_log`

An optional developer menu containing logging, diagnostics, API-error simulation, Answer Page inspection, and destructive cleanup tools can be enabled with:

`define( 'GIST_ANSWERS_SHOW_DEVELOPER_MENU', true );`

Developer tools should be enabled only in appropriate development, QA, or troubleshooting environments.

== External Services ==

This plugin connects to Gist.ai, a third-party service operated by ProRata.ai, to provide its features. It can't work without that connection, which is only made once a Gist Answers API token is configured (entered by an administrator, or supplied through the WordPress VIP integration config, a server environment variable, or a PHP constant).

= Data sent from the WordPress server =

Requests go to the Gist Answers API at `https://cms.gist.ai/api/cms/v1`, authenticated with the site's API token and identified by the site's home URL (sent in the `X-CMS-Origin` header).

* When the API token is checked: a request that confirms the token is valid.
* When a site is activated: the site's name, tagline (or its name, if it has none), domain and home URL, a Gist.ai category, a placeholder location, and the URL of its WordPress REST API posts endpoint.
* When eligible content is published or updated: the permalinks of that content, so Gist.ai can ingest them.
* When an Answer Page is detected in content that Gist.ai doesn't already associate with an Answer Widget: its permalink.
* When content ingestion is paused or resumed from the dashboard: the request to start or stop it.
* When the plugin admin screens load: requests for the account's Gist Answers websites (used to match them to this site), their sites, widgets, ingestion status and messages, the list of Gist.ai categories, and a short-lived ticket for each embedded Gist.ai page.
* When a page containing a Gist widget is rendered: a request for the widget's code, which is cached for five minutes.

No post content, comments or user data are sent from the WordPress server. Gist.ai reads published content through the site's public REST API.

= Content loaded from Gist.ai =

* The plugin's admin screens embed pages from `https://cms.gist.ai/pages` (website settings, widgets, questions, answers, and reports).
* Pages that contain a Gist Question or Answer Widget load the widget's script and markup, as supplied by the Gist Answers API, in visitors' browsers. Those widgets then communicate with Gist.ai directly. The script is only loaded from Gist.ai's CDN (`https://cdn.gist.ai`), and the markup is filtered to the widget's own elements, without scripts.

Gist.ai's terms of service and privacy policy are available at https://gist.ai/.

== Changelog ==

= 1.0.16 =
Fix four high-severity and five medium severity issues

== Upgrade Notice ==

= 1.0.16 =
Fix four high-severity and five medium severity issues
