# Safe Publish

> [!WARNING]
> This plugin is currently in Beta, and breaking changes could occur with any update. DO NOT USE IT ON PRODUCTION ENVIRONMENTS.

**Safe Publish** is a WordPress plugin that allows editors to securely promote content from non-production environments (staging, development) to production. It provides a user-friendly interface for browsing, previewing, and importing posts, pages, and custom post types while preserving all formatting, media, and metadata.

## Features

- **Secure Authentication**: Support for shared secret tokens and basic authentication
- **Content Preview**: View and compare content before importing with side-by-side diff view
- **Bulk Import**: Import multiple posts at once with progress tracking
- **Media Handling**: Automatically imports featured images and inline images
- **Block Preservation**: Maintains Gutenberg block formatting and structure
- **Imports page**: Manage imported posts, review failures, and roll back batches
- **Post Type Support**: Works with posts, pages, and custom post types
- **VIP-Safe**: Built with WordPress VIP best practices and coding standards

## Use Cases

Safe Publish is ideal for:

- **Content Promotion Workflows**: Move approved content from staging to production
- **Editorial Review**: Create and review content in a safe environment before going live
- **Multi-Environment Publishing**: Separate content creation from publication
- **Compliance & Auditing**: Track all content imports with detailed history
- **Media-Rich Content**: Seamlessly import posts with multiple images

## Requirements

- **PHP**: 8.2 or higher
- **WordPress**: 6.8 or higher
- **cURL**: PHP cURL extension with SSL support
- **HTTPS**: Required for secure communication between sites
- Administrator privileges on both source and destination sites

## Installation

See the [Quickstart Guide](docs/quickstart.md) for detailed instructions.

## Documentation

- **[Quickstart](docs/quickstart.md)** - Get started in minutes
- **[Core Concepts](docs/concepts/index.md)** - Understand how the plugin works
  - [Authentication](docs/concepts/authentication.md) - Setting up secure connections
  - [Content Validation](docs/concepts/validation.md) - Understanding validation checks
  - [Import Process](docs/concepts/import-process.md) - How imports work step-by-step
  - [Imports](docs/concepts/imports.md) - Managing imported content and post-import problems
  - [Audit Log](docs/concepts/audit-log.md) - Reviewing logged events, including exports
- **[Extending](docs/extending/index.md)** - Customize the plugin
  - [Hooks and Filters](docs/extending/hooks.md) - Available WordPress hooks
  - [Custom Post Types](docs/extending/post-types.md) - Supporting custom post types
  - [REST API Extension](docs/extending/api.md) - Extending the API
- **[Local Development](docs/local-development.md)** - Setting up a development environment
- **[Troubleshooting](docs/troubleshooting.md)** - Common issues and solutions

## Contributing

Issues, pull requests, and discussions are welcome. Please see our [contribution guide](CONTRIBUTING.md) for more information.

## Support

- Report bugs and request features via GitHub Issues
- Check the [troubleshooting guide](docs/troubleshooting.md) for common issues
- Review [documentation](docs/index.md) for detailed information

## Security

If you discover a security vulnerability, please email security@wpvip.com instead of using the issue tracker.

## License

Safe Publish is licensed under the [GPLv2 (or later)](LICENSE).
