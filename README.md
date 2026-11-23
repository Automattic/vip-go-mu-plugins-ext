# VIP MU plugins external dependencies

This repository contains external dependencies for MU plugins, including Jetpack, WP-Parsely, and VIP integrations.

The idea behind this repo is to automate external dependency management while still maintaining back-compat.

## Jetpack

Jetpack is a hard dependency on VIP. Unfortunately, Jetpack release cadence (every month) creates burden/toil for us, so we're rebundling Jetpack here in the repo.

## WP-Parsely

WP-Parsely is another first-party versioned dependency.

## Integrations

VIP-created plugins bundled for easier customer usage.

### VIP Block Data API

The [VIP Block Data API](https://github.com/Automattic/vip-block-data-api/) is a REST API for retrieving block editor posts structured as JSON data.

### VIP Governance

[VIP Governance](https://github.com/Automattic/vip-governance-plugin) is a plugin that adds additional governance capabilities to the block editor.

### MCP Adapter

[MCP Adapter](https://github.com/WordPress/mcp-adapter) bridges WordPress abilities to the Model Context Protocol (MCP).

### Clipisode

[Clipisode](https://github.com/Automattic/clipisode) collects, curates, and publishes user-generated video content.

# Automation

...profit?

## Running and Debugging the Updater Script

### Setup

Before running the script for the first time, you need to install its npm dependencies:

```bash
cd ci/
npm install
```

### Running the script

The update-deps script can be run in two modes:

1. **Normal mode**: Updates and commits changes to the repository. It would be run in this mode in the [update-deps](.github/workflows/update-deps.yml) github action.

   ```bash
   node ci/update-deps.js
   ```

2. **Dry run mode**: Shows what would happen without making any changes - useful during development
   ```bash
   node ci/update-deps.js --dry-run
   ```

When running in dry run mode, the script will:
- Log all git commands instead of executing them
- Log Slack notifications instead of sending them
- Log WordPress post creations instead of publishing them
- Still check for version information and report what would be updated

This is useful for testing changes to the update process or previewing updates before applying them.

# Configuration

Hopefully the only upkeep we need to do is to change [config](./config.json). And only to remove or skip a version. **Additions** and **updates** should happen on its own.

Automatic dependency updates select **stable version tags by default**. Each
dependency can explicitly opt into alpha or beta tags with `releaseChannels` (see
below). Disallowed tags are excluded before selecting the latest version in each
major/minor series, so they cannot add a directory or replace an existing stable
patch. Existing bundled prereleases are not removed or downgraded when their
channel is disabled. Discovery uses Git tags, not GitHub release metadata.

Each entry in config.json should follow the following format:

```json
{
  "plugin": {
    "repo": "https://github.com/Automattic/awesome-plugin",
    "folderPrefix": "awesome-plugin-",
    "lowestVersion": "3.1",
    "versionPrefix": "",
    "releaseChannels": {
      "alpha": false,
      "beta": false
    },
    "releaseZipFileName": "awesome-plugin",
    "releaseZipRootFolder": "",
    "skip": [
      "3.4"
    ],
    "ignore": [],
    "current": {
      "3.1": "3.1.3",
      "3.2": "3.2.1",
      "3.5": "3.5.2",
    }
  }
}
```

## `lowestVersion`

The version to start scanning dependency tags from. Updater will delete versions lower than `lowestVersion`.

## `skip`

List of versions to be excluded from the updater. This is used for higher versions than `lowestVersion` that we don't need. Updater will delete this version if present.

## `ignore`

List of versions that should be fully ignored by upgrader. That means not update, add or remove them. This is useful if we for some reason want to diverge from the upstream (a hotfix, VIP-specific patch, etc.

## `versionPrefix`

Optional string prefix used in version numbers. For example, if a plugin uses "v1.2.3" rather than "1.2.3", set this to "v". The updater will handle stripping and adding this prefix when comparing versions.

## `releaseChannels`

Optional per-dependency object with independent `alpha` and `beta` booleans.
Only literal `true` enables a channel; omitting the object or a channel defaults
to `false`. For example, to allow beta tags but not alpha tags:

```json
"releaseChannels": { "alpha": false, "beta": true }
```

Stable versions are always eligible. The updater selects the highest allowed
version in each major/minor series using the normal version ordering; enabling
a channel can therefore replace a stable patch with a newer prerelease patch
or add a prerelease-only series. An enabled channel does not imply another:
`beta: true` does not enable alpha, and `alpha: true` does not enable beta.
The beta setting also covers legacy tags such as `13.0-beta1`. Other prerelease
formats, such as release candidates, remain unsupported and are skipped.

## `releaseZipFileName`

Optional GitHub release asset name, without the `.zip` extension. When set, the updater downloads this release zip instead of using `git subtree`.

## `releaseZipRootFolder`

Optional top-level folder inside the release zip. When set, the updater moves that folder's contents into the configured destination folder after extracting.

# Ignored versions

## Jetpack

* 11.9 - Fix Publicize bug + backported https://github.com/Automattic/jetpack/pull/31072
* 11.3, 11.6, 11.9, 12.0, 12.3 - To prevent undoing of https://github.com/Automattic/vip-go-mu-plugins-ext/commit/82b8a5e608825ba7dd2395f7210e3a010c18e2c8
* 14.6 - Fix XML sitemap bug https://github.com/Automattic/vip-go-mu-plugins-ext/commit/865b56dabb8ab99d466b68f50ec024e3585c5104
* 14.8 - Revert using deprecated `wpcom_is_vip()` call https://github.com/Automattic/jetpack/commit/3287aa706df4b5934960ab12553f979f0836ba2d
