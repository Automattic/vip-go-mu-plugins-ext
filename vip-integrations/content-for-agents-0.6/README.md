> [!WARNING]
>
> This plugin is currently in beta, and breaking changes could occur with any update. Do not use it in production environments.

# Content for Agents

Content for Agents is a WordPress VIP plugin that publishes supported content at
`/markdown` paths, supports a `?markdown=true` query endpoint, and
provides `/llms.txt` discovery. It requires WordPress 7.0 or newer, PHP 8.2 or
newer, and the WordPress VIP platform runtime.
WordPress checks these requirements during normal activation. The plugin also
skips loading and shows an administrator notice when included through an
application loader on an unsupported runtime.

The plugin is derived from **PRC Markdown for Agents by Pew Research Center**.
See [attribution](docs/NOTICE.md) and the [GPL license](LICENSE).

## Features

- Serves posts and pages through `{permalink}/markdown` URLs, with an optional
  trailing slash and YAML frontmatter.
- Supports `markdown=true` on WordPress singular URLs. Published content is
  available publicly, non-public statuses retain WordPress read permissions,
  and WordPress validates preview state and nonces.
- Converts nested Block Editor blocks, Classic Editor HTML, and posts that mix
  blocks with freeform HTML to readable Markdown.
- Publishes a configurable `/llms.txt` index and advertises Markdown and the
  index through HTML discovery links.
- Provides settings for the site summary, About links, categories, featured
  posts, and additional resources.
- Uses WordPress VIP cache invalidation and keeps authenticated and
  password-authorized responses out of shared caches while enforcing access to
  published, private, and password-protected content.
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

With pretty permalinks, published posts use canonical `{permalink}/markdown`
URLs. With WordPress's plain `?p=123` permalink structure, the plugin uses and
advertises `?p=123&markdown=true` instead. Both forms are included in cache
invalidation.

The static front page does not have an individual Markdown URL. The root
`/markdown` path remains available for WordPress to use as a normal page URL.
If `markdown=true` is added to the static homepage URL, the unsupported
parameter is ignored and WordPress renders the normal HTML homepage.
The `markdown=true` query endpoint also works on pretty-permalink singular
URLs. Private and other non-public content is served only when WordPress resolves
it as singular and the current user has permission to read it. This includes
authenticated draft requests and preview revisions that WordPress has resolved;
WordPress also validates preview nonces. Authenticated and other non-public
responses are kept out of shared caches.

If you are working from a source checkout, follow the build instructions in the
[contributor guide](docs/CONTRIBUTING.md).

Use **Settings → Content for Agents** to edit the site summary, About links,
categories, featured posts, and additional resources. The site name and tagline
provide neutral defaults.

## Documentation

- [Plugin behavior and extension contracts](docs/PLUGIN-GUIDE.md)
- [Development and contributing](docs/CONTRIBUTING.md)
- [Attribution](docs/NOTICE.md)
