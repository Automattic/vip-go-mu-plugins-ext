> [!WARNING]
>
> This plugin is currently in beta, and breaking changes could occur with any update. Do not use it in production environments.

# Content for Agents

Content for Agents publishes WordPress posts and pages as Markdown. It serves
`{permalink}/markdown` and `?markdown=true` URLs and advertises the Markdown
version on each supported HTML page. It requires WordPress 7.0 or newer, PHP
8.2 or newer, and the WordPress VIP platform. On an unsupported runtime, the
plugin does not load and shows an administrator notice.

The plugin is derived from **PRC Markdown for Agents by Pew Research Center**.
See [attribution](docs/NOTICE.md) and the [GPL license](LICENSE).

## Features

- Serves posts and pages as Markdown with YAML frontmatter through
  `{permalink}/markdown` or `?markdown=true`.
- Converts nested Block Editor blocks, Classic Editor HTML, and posts that mix
  blocks with freeform HTML to readable Markdown.
- Advertises Markdown alternatives on supported HTML pages.
- Applies WordPress permissions to private, draft, preview, and
  password-protected content. Authenticated and password-authorized responses
  are never stored in the shared cache.
- Purges affected Markdown URLs when content changes.
- Publishes Content-Signal values in Markdown headers and `robots.txt`.
- Exposes a public block callback registry and provider-neutral filters for
  integrations.

## Installation

Install the packaged plugin ZIP in your WordPress plugins directory, then
activate **Content for Agents**. On WordPress VIP, follow the guidance for
[activating plugins through code](https://docs.wpvip.com/how-tos/activate-plugins-through-code/):

```php
wpcom_vip_load_plugin( 'content-for-agents' );
```

Load integration plugins after Content for Agents. Each integration owns and
loads its individual dependencies; the base plugin does not require an
integration framework or provider-specific packages.

For a post with plain permalinks, the Markdown URL is
`?p=123&markdown=true`. The query endpoint also works with pretty
permalinks. A static front page uses `/?markdown=true`, leaving `/markdown`
available for a normal page. See the [plugin guide](docs/PLUGIN-GUIDE.md) for
access rules, preview behavior, and extension points.

If you are working from a source checkout, follow the development setup in the
[contributor guide](docs/CONTRIBUTING.md).

## Documentation

- [Plugin behavior and extension contracts](docs/PLUGIN-GUIDE.md)
- [Development and contributing](docs/CONTRIBUTING.md)
- [Attribution](docs/NOTICE.md)
