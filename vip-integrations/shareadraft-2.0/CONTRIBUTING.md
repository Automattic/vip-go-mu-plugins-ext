# Contributing to Share a Draft

Thanks for helping. This guide covers the tooling, tests, and local environments behind the plugin; what the plugin does, and how to use it, is in the [README](README.md) and [/docs](/docs).

The repository ships fully configured VIP local and cloud development environments along with unit tests, end-to-end tests, static analysis, and linting. Pull requests target the `develop` branch.

## Running on WordPress VIP

Share a Draft is an ordinary WordPress plugin and works on any host, with nothing to configure. It is also packaged as a WordPress VIP integration: on VIP it reads optional settings from a VIP-provided constant, records Tracks telemetry, and is registered with the VIP Integrations Center through the [handoff manifest](/docs/manifest.md). Each of those is gated behind a platform check (`Automattic\ShareADraft\Platform::is_vip()`) or a `class_exists()` guard, so off VIP they are simply absent — no notices, no fatals, and no VIP branding on the site. See [/docs/vip-integration.md](/docs/vip-integration.md) for the operational details, and check conformance with the [`vip-integration`](https://github.com/Automattic/integration) CLI (`npx @automattic/vip-integration validate`).

## Technology

These are the tools we use on a day-to-day basis to ensure code quality on the WordPress VIP platform.

### Unit and integration tests

We use [PHPUnit 9](https://phpunit.de/index.html) for both suites. The fast unit tests (pure PHP, no WordPress) live in [/tests/unit](/tests/unit), and the WordPress-booting integration tests live in [/tests/integration](/tests/integration).

### End-to-end tests

For end-to-end tests we use [Playwright](https://playwright.dev/). The specs live in [/tests/e2e](/tests/e2e).

### WP-CLI feature tests

Each `wp shareadraft` command is pinned by a [Behat](https://behat.org/) feature file in [/features](/features), run against a real WordPress in [wp-env](https://www.npmjs.com/package/@wordpress/env) via [automattic/behat-wp-env-context](https://packagist.org/packages/automattic/behat-wp-env-context). Start the environment with `composer prepare-behat-tests`, then run `composer behat` (`composer behat-rerun` repeats only the scenarios that failed).

### Static analysis

[PHPStan](https://phpstan.org/) runs at level 10 with the [WordPress](https://github.com/szepeviktor/phpstan-wordpress), strict-rules, PHPUnit and [dead-code](https://github.com/shipmonk-rnd/dead-code-detector) extensions. Run it with `composer phpstan`. For it to work properly you will need to annotate the PHP code; see [/inc](/inc) for examples. Stubs for symbols the shipped stub packages miss (the WP-CLI surface, and a few test-library types) live in [/tests/phpstan](/tests/phpstan).

### Linting and coding standards

Linting and coding standards are powered by [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) (PHPCS) along with the WordPress VIP and WordPress core rulesets. For more information see the [linting doc](/docs/linting.md).

### GitHub Actions

CI runs on every push and pull request:

| Workflow                               | What it does                                                                                                |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `unit-tests.yml`                       | Fast PHPUnit unit suite (pure PHP, no WordPress) across the PHP baseline (8.2–8.5).                         |
| `integration-tests.yml`                | PHPUnit integration suite across the VIP platform baseline (PHP 8.2–8.5 × WordPress 6.9.x/latest, single site and multisite, plus WordPress nightly on PHP 8.5). |
| `e2e.yml`                              | Playwright end-to-end tests against a real `vip dev-env` (WordPress 6.9 and 7.1).                           |
| `behat.yml`                            | Behat feature tests for the `wp shareadraft` WP-CLI commands, against a real wp-env.                      |
| `lint.yml`                             | PHPCS with the WordPress VIP rulesets.                                                                      |
| `static-code-analysis.yml`             | PHPStan static analysis.                                                                                    |
| `codeql.yml` / `dependency-review.yml` | Security scanning of code and dependency changes.                                                           |

Releases run from a pushed tag: `release.yml` publishes the GitHub Release and its WordPress.org ZIP, `deploy.yml` pushes to WordPress.org SVN, and `vip-integration-release.yml` builds and validates the package handed to VIP; dry-run that last one by hand on the release branch before tagging. See [Cutting a release](/docs/vip-integration.md#cutting-a-release).

## Repository structure

| Path | What lives here |
| ---- | --------------- |
| `shareadraft.php` | Plugin entry file: header, constants, autoloader, and start-up. Kept small. |
| `vestigial.php` | Support for links made with Share a Draft 1.x. Self-contained, so it can be removed in 2.1.0 by deleting it and the two lines in `shareadraft.php` that load it. |
| `inc/` | Runtime code, autoloaded by `inc/autoload.php`. |
| `inc/cli/` | The `wp shareadraft` WP-CLI commands, one class per subcommand, loaded only under WP-CLI. |
| `src/` | Block editor JavaScript, compiled into `build/` by `npm run build`. `build/` is not committed. |
| `languages/` | The translation template (POT). Regenerate it with `composer i18n`. |
| `tests/unit/` | Fast PHPUnit tests: pure PHP, no WordPress. |
| `tests/integration/` | PHPUnit tests that boot WordPress. |
| `tests/e2e/` | Playwright end-to-end tests, run against a `vip dev-env`. |
| `features/`, `tests/behat/` | Behat feature files for the WP-CLI commands, and their context class. |
| `fixtures/` | Mock VIP configurations for local development and tests (see [/fixtures/README.md](/fixtures/README.md)). |
| `docs/` | User and developer documentation, indexed in [/docs/README.md](/docs/README.md). |
| `vip-manifest.yaml`, `vip-manifest.schema.json` | The handoff manifest VIP registers the integration from, and the schema it is validated against. |
| `.wordpress-org/` | WordPress.org directory assets: screenshots, banners and icons. |
| `.wpvip/`, `.devcontainer/` | The VIP local development environment and GitHub Codespaces configuration. |
| `.github/workflows/` | CI, and the release build for tagged versions. |
| `.distignore`, `.gitattributes` | Everything that stays out of the release ZIP, and out of GitHub's source archives. Keep the two in step. |
| `AGENTS.md` | Orientation for AI coding agents working in this repository. |

## Local installation and development

You will need the following tools installed: [Composer](https://getcomposer.org/), [Node.js](https://nodejs.org/en) (which includes NPM), [Docker](https://www.docker.com/), and the [VIP-CLI](https://docs.wpvip.com/vip-cli/).

📝 While we usually recommend Docker Desktop, we understand it may not be possible for every organization. This project is compatible with alternative container runtimes like Colima and Rancher Desktop. For details see [our documentation](https://docs.wpvip.com/vip-local-development-environment/requirements/#Alternatives-to-Docker-Desktop).

Once the prerequisites are installed:

1. Clone the repository and change into its directory.
2. Install Composer dependencies:

```sh
composer install
```

3. Install Node.js dependencies:

```sh
npm i
```

4. Build the JavaScript assets (the `build/` directory is not committed; without this step the editor panel and parts of the Preview Links screen are silently absent, though an admin notice will remind you):

```sh
npm run build
```

5. Create and start a WPVIP local development instance:

```sh
vip dev-env create
vip dev-env start
```

6. Write code, write tests. Or the other way around! `composer test` runs both suites (the e2e half needs the dev-env from the previous step running — see [/docs/vip-integration.md](/docs/vip-integration.md)).

📝 For convenience, this repository contains a [vip-dev-env.yml.ejs](/.wpvip/vip-dev-env.yml.ejs) configuration file; tweak it to your needs. For a more in-depth guide to VIP local development environments, see [our documentation site](https://docs.wpvip.com/vip-local-development-environment/create/).

## Cloud-based development

We support GitHub Codespaces. There are no set-up steps: on the first start the codespace takes a few minutes to build, after which you have a working environment. You can use either the web-based editor or local VS Code.
