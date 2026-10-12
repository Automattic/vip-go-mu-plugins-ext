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

The development site uses <http://localhost:8910> and the test site uses port
`8911` by default. If those ports are busy, run `npx wp-env start --auto-port`
and use the printed URLs. Sign in at `/wp-admin/` with the default `wp-env`
credentials (`admin` / `password`). Run `npx wp-env stop` when finished.

## Architecture and request lifecycle

The plugin deliberately uses a small, direct bootstrap:

```text
content-for-agents.php
  -> plugins_loaded: Bootstrap creates and registers the modules
  -> init: supported post types and block callbacks are registered
  -> parse_request: /markdown requests are handled
  -> template_redirect: the markdown=true query endpoint is handled
  -> Markdown_Response: access and response behavior are applied
  -> Markdown_Converter: blocks are resolved and converted
  -> Frontmatter: document metadata is assembled
  -> Markdown_Response: final body filter, headers, and caching
```

On content and metadata changes, `Markdown_Cache_Invalidator` clears cached
documents and purges affected URLs separately from the request flow.

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

Public PHP classes are direct members of the `Content_For_Agents` namespace.
Integration plugins use their own namespaces and autoloaders. Preserve the
extension contracts in the plugin guide when changing hooks, identifiers,
callback precedence, options, metadata, or cache behavior.

The `/markdown` endpoint uses WordPress's normalized path during
`parse_request`. It does not use stored rewrite rules or activation hooks;
VIP application loaders may not run activation hooks. The `markdown=true`
endpoint runs during `template_redirect`, after WordPress resolves the post.
It does not look up unresolved post IDs.

## Choose the right test layer

| Layer                 | What it verifies                                                               | Command or method                    |
| --------------------- | ------------------------------------------------------------------------------ | ------------------------------------ |
| PHP unit              | Isolated behavior that does not require WordPress                              | `composer test:unit`                 |
| WordPress integration | WordPress hooks, conversion, Markdown structure, and access behavior           | `npm run test:integration`           |
| Manual UI             | Public Markdown output in a real browser                                       | Exercise public URLs in a browser    |
| VIP deployment        | Edge caching, purge propagation, provider services, and application load order | Verify in the target VIP application |

Start `wp-env` before running the integration suite. Its bootstrap loads the
base plugin. The suite uses a development-only Markdown parser to check how a
reader interprets the exact-output fixtures. Deployment checks cannot be fully
reproduced by `wp-env`.

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
npx wp-env start
npm run test:integration
git diff --check
```

Use `composer phpcs-fix` to apply PHP style fixes and `npm run format` to format
Markdown, JSON, and YAML files. The exact-output conversion fixtures are kept in
their original form so the tests can compare them with generated Markdown.

## Common change recipes

- To support a custom block, use the callback registry described in
  [Extend the base](PLUGIN-GUIDE.md#extend-the-base).
- To expose another post type, add the `content-for-agents` support flag.
- When related data changes, identify the affected post IDs and use the public
  cache invalidators described in [Cache invalidation](PLUGIN-GUIDE.md#cache-invalidation).
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
the ZIP. The `package.json` file list includes only runtime files and
documentation; development files and provider-specific integrations are excluded.

Integrations keep their dependencies, versioning, release process, and
deployment separate from the base plugin.
