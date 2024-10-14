# VIP MU plugins external dependencies

This repository contains external dependencies for MU plugins. Currently this is limited to Jetpack and WP-Parsely.

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

# Automation

...profit?

# Configuration

Hopefully the only upkeep we need to do is to change [config](./config.json). And only to remove or skip a version. **Additions** and **updates** should happen on its own.

Each entry in config.json should follow the following format:

```json
{
  "plugin": {
    "repo": "https://github.com/Automattic/awesome-plugin",
    "folderPrefix": "awesome-plugin-",
    "lowestVersion": "3.1",
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

# Ignored versions

## Jetpack

* 11.9 - Fix Publicize bug + backported https://github.com/Automattic/jetpack/pull/31072
* 11.3, 11.6, 11.9, 12.0, 12.3 - To prevent undoing of https://github.com/Automattic/vip-go-mu-plugins-ext/commit/82b8a5e608825ba7dd2395f7210e3a010c18e2c8
