# WordPress VIP - Answers Agent

Welcome to WordPress VIP! This repository is a starting point for building your WordPress VIP application, including all the base directories.

## For Automatticians

:wave: Just a quick reminder that this is a public repo. Please don't include any internal links or sensitive data (like PII, private code, customer names, site URLs, etc. Any fixes related to security should be discussed with Platform before opening a PR. If you're not sure if something is safe to share, please just ask!

## Guidebooks

We recommend starting with one of the following WordPress VIP guidebooks:

- [Get Started](https://docs.wpvip.com/guidebooks/get-started/)
- [Development on WordPress VIP](<[https://docs.wpvip.com/technical-references/development-workflow/](https://docs.wpvip.com/guidebooks/develop-on-wpvip/)>)
- [Prepare for a Site Launch](https://docs.wpvip.com/guidebooks/prepare-for-launch/)

## Directories

- `modules/`: Contains modular components of the Answers Agent integration.
- `utils/`: Contains utility functions and helper classes.
- `vip-config/`: For [custom configurations](https://docs.wpvip.com/technical-references/vip-codebase/vip-config-directory/) and additional [`sunrise.php` changes](https://docs.wpvip.com/technical-references/multisites/sunrise-php/). This folder's `vip-config.php` can be used to supply things usually found in `wp-config.php`.

Key files in the root directory:

- `vip-agentforce.php`: Main plugin file

These directories and files are essential for the Answers Agent integration to function properly. Any additional directories created in your GitHub repository that are not included in the above list will not be mounted onto your site, and so will not be web-accessible.

For more information on how our codebase is structured, see <https://docs.wpvip.com/technical-references/vip-codebase/>.

The `docs/` directory is a special directory that contains your documentation for your application. It is not mounted onto your site, but is available for you to use. See [docs/index.php](docs/index.php) for more information.

## Testing

### Unit Tests

We utilize [PHPUnit 9](https://phpunit.de/index.html) for unit tests. For an example of a test suite please refer to the [/tests/phpunit](tests/phpunit/) folder.

To run the unit tests, execute the following command from the project root:

```bash
composer test
```

This script uses Docker to run the tests in an isolated environment.

To test for a specific test module you can run

```bash
composer test -- --filter Test_Forced_MFA_Users
```

or, in case you want to test for a specific function you can run

```bash
composer test -- --filter test_honor_vip_should_force_two_factor
```

If you are using Claude in VSCode/Cursor and find trouble with running the tests, it's because you need to pass the CI environment variable to the test script.
Run ```composer test-noninteractive``` instead of ```composer test```. All the rest applies.

#### Testing Modules

When testing classes defined within the `modules/` directory, ensure they are correctly autoloaded for the test environment. Add the necessary file paths to the `autoload-dev` section in `composer.json`:

```json
 "autoload-dev": {
  "files": [
   "modules/inactive-users/inactive-users.php"
  ]
 },
```

After modifying `composer.json`, regenerate the autoload files:

```bash
composer dump-autoload
```

**Note:** If a module class file includes a global initialization call (e.g., `My_Class::init();` at the end of the file), it might run before the test's `setUp` method configures necessary constants or settings. If you encounter test failures related to incorrect configuration, you may need to explicitly re-run the class's initialization method at the beginning of the affected test method(s).

### End-to-end tests

For end-to-end tests we use [Playwright](https://playwright.dev/). Examples can be found in [/tests/e2e](/tests/e2e).

## Static analysis

[Psalm](https://psalm.dev/) is a free & open-source static analysis tool that helps you identify problems in your code.

Please note, for Psalm to work properly you will need to annotate your PHP code. For examples please refer to [/plugins/auth-monitoring](/plugins/auth-monitoring).

## Linting and coding standards

Linting and coding standards are powered by [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) (commonly known as PHPCS) along with WordPress VIP and WordPress core rulesets.

For more information please refer to the [linting documentation](/docs/linting.md).

To check the codebase for coding standards violations, run:

```bash
composer lint
```

To automatically fix many of the reported violations, run:

```bash
composer format
```

## Releasing

This project uses GitHub Actions to automate the release process. The workflow follows the pattern used by other Automattic projects like [vip-cli](https://github.com/Automattic/vip-cli) and [vip-design-system](https://github.com/Automattic/vip-design-system).

### Releasing a new version

1. **Initiate the release process** by going to [Actions > Prepare Release](../../actions/workflows/prepare-release.yml).

2. Click **"Run workflow"** on the right-hand side.

3. **Select your version bump type:**
   - `patch`: Bug fixes and small updates (e.g., 1.0.0 → 1.0.1)
   - `minor`: New features and non-breaking changes (e.g., 1.0.0 → 1.1.0)
   - `major`: Breaking changes (e.g., 1.0.0 → 2.0.0)

4. Click **"Run workflow"** to start the process.

5. A **pull request** will be created that:
   - Updates the version in `package.json`, `package-lock.json`, and `vip-agentforce.php`
   - Is assigned to you for review
   - Has the `release` label applied

6. **Review and merge** the pull request when ready.

7. **Merging automatically triggers** the package workflow, which will:
   - Build the production assets
   - Create a git tag for the version
   - Package the plugin into a distributable zip
   - Create a GitHub Release with the zip attached

### What's included in the release zip

The release package includes only the files needed to run the plugin:

- `vip-agentforce.php` (main plugin file)
- `modules/` (PHP modules)
- `utils/` (utility classes)
- `vip-config/` (VIP configuration)
- `assets/build/` (compiled JS/CSS)
- `assets/images/` (static assets)
- `languages/` (translations)
- `README.md` and `LICENSE`

Development files, tests, and build tools are excluded via `.distignore`.

## Support

If you need help with anything, VIP's support team is [just a ticket away](https://wpvip.com/accessing-vip-support/).

## Your documentation here

Feel free to add to or replace this README.md content with content unique to your project, for example:

- Project-specific notes; like a list of VIP environments and branches,
- Workflow documentation; so everyone working in this repo can follow a defined process, or
- Instructions for testing new features.

This can be detailed in the `docs/` directory.
