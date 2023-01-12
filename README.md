# VIP MU plugins external dependencies

This repository contains external dependencies for MU plugins. Currently this is limited to Jetpack and WP-Parsely.

The idea behind this repo is to automate external dependency management while still maintaining back-compat.

## Jetpack

Jetpack is a hard dependency on VIP. Unfortunately, Jetpack release cadence (every month) creates burden/toil for us, so we're rebundling Jetpack here in the repo.

# WP-Parsely

WP-Parsely is another first-party versioned dependency.

# Automation

...profit?

# Configuration

Hopefully the only upkeep we need to do is to change [config](./config.json). And only to remove or skip a version. **Additions** and **updates** should happen on its own.

```json

```


## `lowestVersion`

The version to start scanning dependency tags from. Updater will delete versions lower than `lowestVersion`.

## `skip`

List of versions to be excluded from the updater. This is used for higher versions that `lowestVersion` that we don't need. Updater will delete this version if present.

## `ignore`

List of versions that should be fully ignored by upgrader. That means not update, add or remove them. This is useful if we for some reason want to diverge from the upstream (a hotfix, VIP-specific patch, etc.

# Ignored versions

## Jetpack

* 10.7 - We are using `10.7+vip.1` tag in order to avoid breaking changes to filter `jetpack_relatedposts_returned_results`
* 10.9
