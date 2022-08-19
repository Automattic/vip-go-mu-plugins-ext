# VIP MU plugins external dependencies

This repository contains external dependencies for MU plugins. For example, all the versioned Jetpack.

The idea behind this repo is to automate external dependency management while still maintaining back-compat.

## Jetpack

Jetpack is a hard dependency on VIP. Unfortunately, Jetpack release cadence (every month) creates burden/toil for us, so we're rebundling Jetpack here in the repo.

There is a github action that runs once an hour. It goes through minor versions (`10.9`, `11.0`, ...) and finds the latest patch version for each.

# Automation

...profit?

# Configuration

Hopefully the only upkeep we need to do is to change [config](./config.json). And only to remove or skip a version. **Additions** and **updates** should happen on its own.

## `lowestVersion`

The version to start scanning Jetpack tags from. Updater will delete versions lower than `lowestVersion`.

## `skip`

List of versions to be excluded from the updater. This is used for higher versions that `lowestVersion` that we don't need. Updater will delete this version if present.

## `ignore`

List of versions that should be fully ignored by upgrader. That means not update,add or remove them. This is usefull if we for some reason want to diverge from the JP semver.

# Ignored versions

* 10.7 - We are using `10.7+vip.1` tag in order to avoid breaking changes to filter `jetpack_relatedposts_returned_results`
