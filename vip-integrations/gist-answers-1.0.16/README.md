# Gist Answers

Connects WordPress to [Gist.ai](https://gist.ai/products/gist-answers) for AI
answer widgets, content ingestion, reporting, website settings, and multisite
management.

- **Requires:** WordPress 6.8+, PHP 8.0+
- **Tested with:** WordPress 6.9 and 7.0, PHP 8.2–8.5
- **Support:** support@gist.ai

End-user documentation lives in `readme.txt`. This file covers configuration
on WordPress VIP and how to build and test the plugin.

## Runtime config on WordPress VIP

Config constant: `VIP_GIST_ANSWERS_CONFIG`

On WordPress VIP, the platform defines this constant (a plain PHP associative
array) before the plugin loads. All reads go through
`GistAnswers\Plugin\Config`.

Required values:

| Key         | Type   | Description                                                                  |
| ----------- | ------ | ---------------------------------------------------------------------------- |
| `api_token` | secret | The API token for your Gist.ai partner account. Request one from support@gist.ai. |

Example valid config (mock values only; never commit real credentials):

```php
define( 'VIP_GIST_ANSWERS_CONFIG', array(
	'api_token' => 'mock-token',
) );
```

Example incomplete config (setup in progress; the required `api_token` is missing):

```php
define( 'VIP_GIST_ANSWERS_CONFIG', array() );
```

How the plugin behaves:

- **Valid config:** the token from `VIP_GIST_ANSWERS_CONFIG` is used, and the
  plugin's admin screens show a notice that the token is managed by WordPress
  VIP. The token entry form is hidden (as it is whenever the token comes from
  outside the dashboard).
- **Incomplete config:** the plugin does not fatal. It stays disconnected, does
  not fall back to any other token source, and the Gist Answers dashboard names
  the missing fields.
- **Invalid config** (the constant is defined but is not an array): treated as
  if no config were set.
- **No config:** the plugin reads its token from the `GIST_ANSWERS_API_KEY`
  environment variable, then a `GIST_ANSWERS_API_KEY` PHP constant, then the
  token an administrator saved in the plugin's dashboard.

Until a token is available from one of these sources, the plugin makes no
requests to the Gist API.

## Build

The release zip ships with its compiled assets in `public/` and its runtime
autoloader in `vendor/`, so an installed copy needs no build step.

To make a development build from the source repository (the plugin lives at
`wp-content/plugins/gist-answers`), run from the repository root:

```bash
yarn install
yarn build:production
composer install --working-dir=wp-content/plugins/gist-answers --no-dev --optimize-autoloader
```

(The root `composer install` is only needed for the coding-standards tools.)

A development build still contains the release placeholders (the version, the
per-environment API URLs, and the changelog) and no generated translations.
Release zips are made by `./release-build.sh` or the Build Plugin GitHub
workflow, which fill those in, generate translations, and validate the result.

## Test

The test suites need PHP 8.2 or later (the plugin's `composer.json` pins its
development dependencies to PHP 8.2), even though the plugin itself runs on
PHP 8.0. Install the plugin's test dependencies from this folder:

```bash
composer install
npm ci
npx playwright install chromium
```

Then run both suites:

```bash
composer test
```

`composer test` runs the PHPUnit suite (`composer test:unit`) and then the
Playwright end-to-end suite (`composer test:e2e`, which runs `npm test`). Each
needs a WordPress environment:

- **PHPUnit** needs the WordPress test library. Point `WP_TESTS_DIR` at a
  checkout of it (for example, one installed with
  `bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]`,
  which also creates the test database); it defaults to `wordpress-tests-lib`
  in the system temporary directory. Set `WP_MULTISITE=1` to run the suite as multisite.
  The suite answers the plugin's Gist API requests with a mock
  (`tests/phpunit/includes/MockGistApi.php`) and blocks all other outbound
  HTTP, so it runs offline. Each test runs in a database transaction that is
  rolled back afterwards (`tests/phpunit/includes/TestCase.php`). Only
  `EndpointsTest` calls the live Gist API, and only when `GIST_API_TOKEN` is
  set; otherwise its live-API tests are reported as skipped (its encryption
  tests still run).
- **Playwright** runs against a WordPress site with the plugin active, at
  `E2E_URL`, logging in as `E2E_USER` / `E2E_PASSWORD`. The login
  defaults (`vipgo` / `password`) match a stock `vip dev-env` site; set
  `E2E_URL` to the site's URL (the default is `http://localhost/`).
  The specs for a connected site run against a mock Gist API that ships as
  an mu-plugin in `tests/e2e/mu-plugins`. They cover connecting, activating,
  pausing ingestion, the widgets and Answers screens, the block editor, and
  front-end rendering. To enable them, copy that folder's files into the test site's
  `wp-content/mu-plugins` and add
  `define( 'GIST_ANSWERS_E2E_MOCK_API', true );` to its `wp-config.php`. On a
  site without the mock, those specs are skipped. The mock resets the site's
  Gist Answers settings (including its stored API token), so only use it on a
  throwaway site.

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:unit
E2E_URL=https://example.test/ E2E_USER=admin E2E_PASSWORD=secret composer test:e2e
```

The coding standards checks run from this folder too, once `composer install`
has installed the dev dependencies:

```bash
bin/phpcs.sh              # PHPCS: WordPress, WordPress-VIP-Go, and Slevomat's unused-uses sniff
bin/php-compatibility.sh  # PHPCompatibility, for PHP 8.0 and later
bin/phpcbf.sh             # fix what PHPCS can
```

CI runs the PHPUnit suite on PHP 8.2–8.5 against WordPress 6.9 and 7.0, single
site and multisite, and the Playwright suite against WordPress 6.9 and 7.0. It
also syntax-checks the plugin on PHP 8.0 and 8.1, and runs the checks above and
the VIP conformance checker. The workflow is included in this zip at
`.github/workflows/test-plugin.yml`, with the scripts it calls (`bin/`) and the
tool versions it pins (`versions.env`), so it runs from this zip as well as from
the source repository: push this folder's contents to the root of a GitHub
repository. Each job finds the plugin wherever it is, and since this zip is
already built, the steps that build and package the plugin from source are
skipped.

## Validate for the VIP Integration Center

```bash
npx @automattic/vip-integration@0.2.0 validate <path-to-release-copy>
```

Run it against a built release copy of the plugin, not the source tree: the
version and changelog in `vip-manifest.yaml` are filled in at build time. The
checker version above is stamped at build time from the one pinned in CI and
`release-build.sh`.
