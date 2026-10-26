# Contributing to Content for Agents

This guide covers the quickest path from a new checkout to a validated change.
The public behavior and supported extension contracts are documented in the
[plugin guide](PLUGIN-GUIDE.md). See the [conversion architecture](ARCHITECTURE.md)
for callback precedence and the HTML fallback.

## Table of contents

- [Development requirements](#development-requirements)
- [Set up a checkout](#set-up-a-checkout)
- [Architecture and request lifecycle](#architecture-and-request-lifecycle)
- [Choose the right test layer](#choose-the-right-test-layer)
- [Run the complete checks](#run-the-complete-checks)
- [Common change recipes](#common-change-recipes)
- [Release boundaries](#release-boundaries)

## Development requirements

- PHP 8.2 or newer
- Composer
- Node.js 24, selected through `.nvmrc`
- npm 10 or newer
- Docker, OrbStack, or another Docker-compatible runtime for `wp-env`

The local `wp-env` configuration uses the minimum supported versions:
WordPress 7.0 and PHP 8.2. CI checks PHP 8.2 through 8.5 and runs integration
tests against the WordPress 7.0 and 7.1 branches and master.

For branch protection, require `PHP / Result` and `Integration / Result`.
Each passes only when its full matrix succeeds.

Composer and npm dependencies are development-only. The deployed plugin has
no JavaScript runtime and does not load Composer's autoloader.

## Set up a checkout

```sh
composer install
nvm use
npm ci
npx wp-env start
```

The development site uses <http://localhost:8910>. The integration suite uses a
separate site on port `8911`; start it with
`npx wp-env start --config=.wp-env.test.json`. If a port is busy, add
`--auto-port` to the corresponding start command. Sign in at `/wp-admin/` with
the default `wp-env` credentials (`admin` / `password`). Stop each site with
`npx wp-env stop`, passing `--config=.wp-env.test.json` for the test site.

## Architecture and request lifecycle

The plugin deliberately uses a small, direct bootstrap:

```text
content-for-agents.php
  -> plugins_loaded: Bootstrap creates and registers the modules
  -> init: supported post types and block callbacks are registered
  -> parse_request: /markdown requests are handled
  -> wp_headers: eligible HTML pages add Vary: Accept
  -> template_redirect: markdown=true and Accept requests are handled
  -> Markdown_Response: access and response behavior are applied
  -> Markdown_Converter: blocks are resolved and converted
  -> Frontmatter: document metadata is assembled
  -> Markdown_Response: final body filter and headers
```

On content and metadata changes, `Markdown_Cache_Invalidator` purges affected
VIP edge URLs separately from the request flow.

The main responsibilities are divided as follows:

| Area                            | Primary classes                                                                  |
| ------------------------------- | -------------------------------------------------------------------------------- |
| Plugin initialization and hooks | `Bootstrap`, `Loader`                                                            |
| Dedicated Markdown URLs         | `Markdown_Endpoint`, `Markdown_Response`                                         |
| Block conversion                | `Markdown_Converter`, `Block_Markdown_Registry`                                  |
| Conversion diagnostics          | `Markdown_Conversion_Logger`                                                     |
| HTML conversion                 | `HTML_To_Markdown_Converter`, `Markdown_Inline_Buffer`, `Markdown_Output_Writer` |
| Document metadata               | `Frontmatter`                                                                    |
| Discovery                       | `Discovery`, `Robots_Txt`                                                        |
| Cache invalidation              | `Markdown_Cache_Invalidator`                                                     |
| Analytics                       | `Markdown_Stats`                                                                 |

Public PHP classes are direct members of the `Content_For_Agents` namespace.
Integration plugins use their own namespaces and autoloaders. Preserve the
extension contracts in the plugin guide when changing hooks, identifiers,
callback precedence, options, metadata, or cache behavior.

The `/markdown` endpoint uses WordPress's normalized path during
`parse_request`. It does not use stored rewrite rules or activation hooks;
VIP application loaders may not run activation hooks. The `markdown=true`
endpoint and `Accept: text/markdown` negotiation run during
`template_redirect`, after WordPress resolves the post. They use the original
main query rather than the mutable global post. Neither looks up
unresolved post IDs. The plugin selects Markdown when the Accept value
contains the lowercase `text/markdown` string, including in `q=0` or quoted
parameters. Keep this behavior consistent with shared cache variants on the
supported hosting platform.

## Choose the right test layer

| Layer                 | What it verifies                                                               | Command or method                    |
| --------------------- | ------------------------------------------------------------------------------ | ------------------------------------ |
| PHP unit              | Isolated behavior that does not require WordPress                              | `composer test:unit`                 |
| WordPress integration | WordPress hooks, conversion, Markdown structure, and access behavior           | `npm run test:integration`           |
| Manual UI             | Public Markdown output in a real browser                                       | Exercise public URLs in a browser    |
| VIP deployment        | Edge caching, purge propagation, provider services, and application load order | Verify in the target VIP application |

Start the test site with `npx wp-env start --config=.wp-env.test.json` before
running the integration suite. Its bootstrap loads the base plugin. The suite
uses a development-only Markdown parser to check how a reader interprets the
exact-output fixtures. Deployment checks cannot be fully reproduced by
`wp-env`.

Tests should be added at the lowest layer that proves the behavior. Changes to
WordPress hooks, permissions, conversion integration, or cache invalidation
normally require an integration test even when the underlying helper also has a
unit test.

## Run the complete checks

Run the checks relevant to the change. Before proposing a completed change, run
the complete set:

```sh
composer validate --strict
composer check-platform-reqs
composer phpcs
composer test:unit
npm run format:check
npx wp-env start --config=.wp-env.test.json
npm run test:integration
git diff --check
```

Use `composer phpcs-fix` to apply PHP style fixes and `npm run format` to format
Markdown, JSON, and YAML files. The exact-output conversion fixtures are kept in
their original form so the tests can compare them with generated Markdown.

## Common change recipes

- To support a custom block, use the callback registry described in
  [Implementation reference](SITE-SETUP.md#implementation-reference-for-your-site-team).
- To expose another post type, add the `content-for-agents` support flag.
- When related data changes, identify the affected post IDs and use the public
  cache invalidators described in [Access and cache rules](SITE-SETUP.md#access-and-cache-rules).
- Provider-specific compatibility belongs in a separately loaded plugin, not
  in the provider-neutral base.

When changing access or invalidation behavior, include private, draft, preview,
password-protected, moved, and deleted content in the design. A conversion hook
that changes output does not automatically invalidate an already cached result.

## Release boundaries

The base plugin and integrations are separate release units.

The base version appears in `package.json`, its lockfile, the plugin header, and
`CONTENT_FOR_AGENTS_VERSION`. The **Create release PR** workflow updates these
values. After that version change reaches `trunk`, the release workflow builds
`content-for-agents.zip`, creates the matching `v{version}` tag, and publishes
the ZIP. `npm run plugin-zip` archives the committed plugin code and docs from
`HEAD`; uncommitted changes are not included. Development files, `package.json`,
and provider-specific integrations are excluded.

Integrations keep their dependencies, versioning, release process, and
deployment separate from the base plugin.
