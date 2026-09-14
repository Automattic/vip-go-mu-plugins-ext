> [!WARNING]
>
> This plugin is currently in beta, and breaking changes could occur with any update. Do not use it in production environments.

# Agent Ready Content

Agent Ready Content is a WordPress VIP plugin that publishes posts and pages as
Markdown and provides `/llms.txt` discovery. It requires WordPress 6.8 or newer,
PHP 8.2 or newer, and the WordPress VIP platform runtime.

The plugin is derived from **PRC Markdown for Agents by Pew Research Center**.
See [attribution](docs/NOTICE.md) and the [GPL license](LICENSE).

## Installation

Install the packaged plugin ZIP in your WordPress plugins directory, then
activate **Agent Ready Content**. On WordPress VIP, follow the guidance for
[activating plugins through code](https://docs.wpvip.com/how-tos/activate-plugins-through-code/).
If you are working from a source checkout, follow the build instructions in the
[contributor guide](https://github.com/Automattic/agent-ready-content/blob/trunk/docs/CONTRIBUTING.md).

Use **Settings → Agent Ready Content** to edit the site summary, About links,
categories, featured posts, and additional resources. The site name and tagline
provide neutral defaults.

## Documentation

- [Plugin behavior and extension contracts](docs/PLUGIN-GUIDE.md)
- [Development and contributing](https://github.com/Automattic/agent-ready-content/blob/trunk/docs/CONTRIBUTING.md)
- [Attribution](docs/NOTICE.md)
