# Clipisode WordPress Plugin

Enables creators and brands to collect video replies from visitors, moderate submissions, and render finished clipisode videos.

## Dev Setup

```bash
npm install
npm run build    # production build
npm run start    # watch mode
```

## Structure

- `assets/themes/` — Static theme assets (images) served at runtime
- `assets/templates/` — PHP templates for invitation and terms pages
- `includes/` — PHP: REST API, database, media handling, admin, post types
- `src/` — TypeScript/React admin UI + SCSS
- `src/flow/` — Invitation page frontend (vanilla TS, block renders)
- `build/` — Compiled output (gitignored)

## Release archive

Run `npm run plugin` from this directory to build the production assets and create `clipisode.zip`. The ZIP contains the installable WordPress plugin. Verify the plugin version in `clipisode.php` and `package.json`, run the checks in the repository's `docs/remotion.md`, and inspect the archive before attaching it to a GitHub release with the matching `v` tag.

To prepare a release, run the **Create release PR** GitHub Actions workflow and select a major, minor, or patch version bump. Merging the generated PR creates the matching GitHub release and attaches `clipisode.zip`.

## Invitation URLs

The invitation URL path defaults to `invitation`, producing `/invitation/{code}`. In **Clipisode → Settings → General**, set **Invitation URL Path** to a path such as `clipisode/invitation` to produce `/clipisode/invitation/{code}`. The setting accepts multiple path segments, with or without surrounding slashes. Saving rebuilds WordPress rewrite rules. Existing shared invitation URLs using the previous path stop resolving after a change.
