> [!WARNING]
>
> This plugin is currently in beta, and breaking changes could occur with any update. Do not use it in production environments.

# Content for Agents

Content for Agents publishes WordPress posts and pages as Markdown. It serves
`{permalink}/markdown` and `?markdown=true` URLs, or responds to an explicit
`Accept: text/markdown` request at the post's normal URL. It advertises the
Markdown version on each supported HTML page. It requires WordPress 7.0 or newer, PHP
8.2 or newer, and the WordPress VIP platform. On an unsupported runtime, the
plugin does not load and shows an administrator notice.

The plugin is derived from **PRC Markdown for Agents by Pew Research Center**.
See [attribution](docs/NOTICE.md) and the [GPL license](LICENSE).

## Features

- Serves posts and pages as Markdown with YAML frontmatter through
  `{permalink}/markdown`, `?markdown=true`, or `Accept: text/markdown` at the
  normal URL.
- Recognizes the lowercase `text/markdown` text in Accept values. See the
  [plugin guide](docs/PLUGIN-GUIDE.md) for matching details.
- Converts nested Block Editor blocks, Classic Editor HTML, and posts that mix
  blocks with freeform HTML to readable Markdown.
- Advertises Markdown alternatives on supported HTML pages.
- Applies WordPress permissions to private, draft, preview, and
  password-protected content. Authenticated and password-authorized responses
  send private, no-store headers.
- Purges affected Markdown URLs when content changes.
- Records sampled Markdown usage and block callback usage through MC Stats on production VIP sites.
- Publishes Content-Signal values in Markdown headers and `robots.txt`.
- Exposes a public block callback registry and provider-neutral filters for
  integrations.

## Analytics on WordPress VIP

When enabled on a production WordPress VIP site, the plugin sends two sampled
counters through VIP's MC Stats. Analytics are disabled on non-VIP sites and
VIP sandbox environments. The plugin has no analytics setting.

| Counter                                  | When it is sent                                                                                                         |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `content-for-agents-markdown-served`     | A sampled Markdown response reaches WordPress through a Markdown URL or the Accept route.                               |
| `content-for-agents-block-callback-used` | The same sampled response invoked one or more registered block Markdown callbacks. Multiple callbacks still count once. |

Each counter's value is the VIP customer site ID (`FILES_CLIENT_SITE_ID`). The
plugin sends no post IDs, URLs, content, block names, or user IDs. Each origin
response is sampled independently at one in ten; responses served entirely by
VIP's edge cache do not reach the plugin and are not counted. Authorized
private and preview responses can increment these site-level counters. See the
[analytics implementation](includes/class-markdown-stats.php) and
[WordPress VIP privacy policy](https://wpvip.com/privacy/).

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
available for a normal page. If a child page occupies a post's `/markdown`
path, that post uses `?markdown=true` instead. See the
[plugin guide](docs/PLUGIN-GUIDE.md) for access and preview behavior, and the
[site setup guide](docs/SITE-SETUP.md) for extension points.

If you are working from a source checkout, follow the development setup in the
[contributor guide](docs/CONTRIBUTING.md).

## Documentation

For site owners and customers:

- [Using Content for Agents](docs/PLUGIN-GUIDE.md)
- [Adapt Content for Agents to your site](docs/SITE-SETUP.md)

For implementation and maintenance:

- [Conversion architecture](docs/ARCHITECTURE.md)
- [Development and contributing](docs/CONTRIBUTING.md)
- [Attribution](docs/NOTICE.md)
