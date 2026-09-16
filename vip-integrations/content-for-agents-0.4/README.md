> [!WARNING]
>
> This plugin is currently in beta, and breaking changes could occur with any update. Do not use it in production environments.

# Content for Agents

Content for Agents is a WordPress VIP plugin that publishes posts and pages as
Markdown and provides `/llms.txt` discovery. It requires WordPress 6.8 or newer,
PHP 8.2 or newer, and the WordPress VIP platform runtime.

The plugin is derived from **PRC Markdown for Agents by Pew Research Center**.
See [attribution](docs/NOTICE.md) and the [GPL license](LICENSE).

## Features

- Serves posts and pages through `.md`, `/markdown`, and `?markdown=true`
  URLs, with YAML frontmatter.
- Converts Block Editor content, nested blocks, and Classic Editor HTML to
  readable Markdown.
- Publishes a configurable `/llms.txt` index and advertises Markdown and the
  index through HTML discovery links.
- Provides settings for the site summary, About links, categories, featured
  posts, and additional resources.
- Uses WordPress VIP cache invalidation and keeps authenticated, preview, and
  password-authorized responses out of shared caches while enforcing access to
  published, private, preview, and password-protected content.
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

If you are working from a source checkout, follow the build instructions in the
[contributor guide](https://github.com/Automattic/content-for-agents/blob/trunk/docs/CONTRIBUTING.md).

Use **Settings → Content for Agents** to edit the site summary, About links,
categories, featured posts, and additional resources. The site name and tagline
provide neutral defaults.

## Documentation

- [Plugin behavior and extension contracts](docs/PLUGIN-GUIDE.md)
- [Development and contributing](https://github.com/Automattic/content-for-agents/blob/trunk/docs/CONTRIBUTING.md)
- [Attribution](docs/NOTICE.md)
