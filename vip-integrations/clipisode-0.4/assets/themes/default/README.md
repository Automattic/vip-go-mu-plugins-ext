# Default theme — Clipisode invitation flow screens

Starter block markup for every screen in the public invitation flow. Each `.html` file is a self-contained block document; the seeder reads it from disk, substitutes a small set of tokens, and stores the result as the `post_content` of a `clipisode_screen` post. The public renderer (`plugin/clipisode/assets/templates/clipisode-flow.php`) loads that post, runs `do_blocks()`, and injects dynamic data into named slots before sending HTML to the guest.

This README covers the conventions that span all screens. Per-screen quirks live in a sibling `.md` file with the same basename (e.g. `intro_desktop.html` → `intro_desktop.md`). Most screens don't need one.

## Files

| File | Purpose |
|---|---|
| `intro.html` | Mobile intro screen — the first thing a guest sees on a phone. |
| `intro_desktop.html` | Desktop intro screen — same role for desktop browsers. ([details](intro_desktop.md)) |
| `email.html` | Asks the guest for their email address before recording. |
| `name.html` | Asks the guest for their display name. |
| `success.html` | Confirmation after a successful upload. Rooted in `core/cover` so authors can pick a stretched-to-cover background image / colour / gradient via the standard sidebar. |
| `closed.html` | Shown when a topic has stopped accepting replies. |
| `warning_camera.html` | Camera permission denied. |
| `warning_silent.html` | Phone is on silent (mic blocked on iOS). |
| `warning_network.html` | Network is slow or offline. |
| `warning_wide.html` | Phone is in landscape — flow needs portrait. |
| `theme.css` | Public-side stylesheet shared by every screen. |
| `icon.png` | Default logo image, imported into the Media Library on activation. |
| `preview-values.json` | Sample values for placeholder tokens. Used by the editor's preview simulator. |

## Lock model

Every block in a starter file falls into one of four categories. The category controls what authors can do once the screen is in the WP block editor.

### Author-controlled (content-locked)

Real core blocks with no `templateLock`. Author can edit content and styling, but can't reorder or delete.

- `lock: {move: true, remove: true}`.
- Headings, paragraphs, images, buttons that are essential to the screen's job.
- Used on screens where the layout is non-negotiable — every block is required for the screen to do what it's supposed to do.

### Author-controlled (permissive)

Real core blocks the author can edit, reorder restrictions vary, **and can also delete entirely**.

- `lock: {move: true, remove: false}`.
- Used when the screen has a "stripped down" use case where the author legitimately wants to remove some or all of the inner blocks. Today's example is the success screen — a host running a sponsor ad might want to delete the heading, message, and share button to leave just a Cover background.
- The structural wrappers (the screen's outer Group, any layout containers) stay content-locked even when their children are permissive. Authors get freedom inside the frame but can't break the frame itself.

### Structural wrappers

Group blocks that frame the layout. Almost always carry `lock: {move: true, remove: true}` to keep the frame anchored, but **no** `templateLock` — authors can still drop their own blocks (a caption, a spacer, a divider) into the wrapper.

### PHP-injected slots

Empty group blocks with `lock: {move: true, remove: true, templateLock: "all"}`. The author sees an empty container in the editor; the public renderer fills the slot at request time with dynamic content (live URL, QR code, video element, etc.). `templateLock: "all"` prevents authors from inserting blocks that the renderer would just overwrite or ignore.

### Empty slots and Gutenberg's layout picker

An empty `core/group` triggers Gutenberg's "pick a layout" UI in the editor, which is visually noisy and confusing for slots that authors aren't supposed to fill. The workaround used across these starter files is to drop a 0-height locked `core/spacer` inside the slot. The spacer satisfies "this group has children" so the picker doesn't show; the public renderer strips the spacer divs before slot injection so they never reach guests.

The class name convention is `clipisode-<screen>-slot-filler` (e.g. `clipisode-introd-slot-filler` on the desktop intro, `clipisode-success-slot-filler` on the success screen). The renderer's strip rule matches any class ending in `slot-filler`, so a new screen using the same trick gets covered automatically.

```html
<!-- wp:group {"className":"clipisode-something-slot","lock":{"move":true,"remove":true},"templateLock":"all","layout":{"type":"default"}} -->
<div class="wp-block-group clipisode-something-slot"><!-- wp:spacer {"height":"0px","lock":{"move":true,"remove":true},"className":"clipisode-something-slot-filler"} -->
<div style="height:0px" aria-hidden="true" class="wp-block-spacer clipisode-something-slot-filler"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
```

## Rich backgrounds: when to use core/cover vs core/group

Most screens are rooted in a `core/group` block. Group's color sidebar lets authors set a solid colour or a gradient as the background, which covers the gradient + solid colour use cases and keeps the saved markup minimal. **Stretched-to-cover background images are not part of Group's standard sidebar** — Group can carry a background colour or gradient via inline `style.color`, but not a media-library image.

For screens where authors should be able to pick a stretched-to-cover background image (today: `success.html`, for the sponsor-ad use case), the root is a `core/cover` block instead. Cover's standard sidebar gives:

- A media-library image picker (with a focal-point control) — the image is rendered as `<img class="wp-block-cover__image-background">` with `object-fit: cover; object-position: center`.
- A media-library video picker — same shape, with `<video autoplay loop muted>`.
- A solid colour overlay (with opacity).
- A gradient overlay (with opacity).

Authors can mix these (image + dim colour, image + gradient tint, etc.) without any custom code from us.

### Trade-offs

Cover is heavier than Group. It wraps children in `.wp-block-cover__inner-container`, which means CSS selectors and editor canvas rules have to account for that extra wrapper:

- Direct-child combinators (`.clipisode-foo-root > .child`) break — Cover puts `.wp-block-cover__inner-container` between root and children. Use descendant combinators (`.clipisode-foo-root .child`) instead.
- Layout rules (flex, grid) that need to apply to the actual content row should target `.clipisode-foo-root .wp-block-cover__inner-container`, not the root.
- Cover ships with a default `1.5em` padding on `__inner-container`. Zero it explicitly if your screen has its own gutter system.

Don't blanket-convert every screen to Cover — only the ones that have a real product reason to support image backgrounds. For warning / closed / form screens, the Group root is the right shape.

### Why `intro.html` is NOT Cover

`intro.html` already uses runtime video injection via `topic.intro_media_id` — when a topic has an intro video, the public renderer mounts a full-bleed `<video>` inside `.clipisode-intro-root`. That mechanism predates and replaces Cover-style media for the intro screen.

A previous attempt at `core/cover` for the intro screen was retired because the editor flagged the resulting markup as invalid content (the failure mode involved `core/cover` paired with `core/html` blocks for video and play-button injection). The current architecture moved all media plumbing into the runtime template + plugin CSS, leaving plain Group blocks in the database. `Clipisode_Post_Types::migrate_legacy_intro_post()` still auto-heals any post that surfaces with the legacy Cover markup.

For the "no video, use a photo as the intro background" use case, the right path is per-topic media on the `clipisode_topics` table (let authors pick a video OR a photo OR neither on the topic edit form), not a Cover block on the screen template. That's a separate planned piece of work; the screen template stays Group-rooted.

## Placeholder tokens

The seeder calls `Clipisode_Post_Types::substitute_seed_tokens()` against the raw HTML before saving the post. Tokens are replaced once, at seed time. After that the post stores the resolved markup verbatim — author edits in the block editor don't re-trigger substitution.

| Token | Replaced with | Source |
|---|---|---|
| `{logo_id}` | Media Library attachment id of the imported `icon.png`. | `clipisode_default_logo_attachment_id` site option. |
| `{logo_url}` | Public URL of the imported `icon.png`. | `wp_get_attachment_url()` on the same attachment. |
| `{topic_title}` | Left as a literal placeholder in the editor; the public renderer substitutes the live topic title at request time. | Topic post title. |
| `{host_name}` | Same — literal in the editor, substituted at request time. | Host display name. |

`{logo_id}` / `{logo_url}` are special: they have to resolve to a real Media Library attachment before the post is saved, otherwise Gutenberg's `core/image` validator rejects the markup with a "recover this content" prompt. The seeder is self-healing — if the option is missing it imports `icon.png` on demand. See `intro_desktop.md` for the full story.

## Editor preview simulator

The block editor swaps `{token}` placeholders for realistic sample values so authors can lay out screens against the kind of long-form copy guests will actually see — `{topic_title}` (12 characters) becomes "Cooking with Mom: Sunday Sauce Stories" (44 characters) and the layout shows whether the wrapping looks right.

Sample values live in `preview-values.json` as a flat `{ token: sampleValue }` map. Edit that file to change what authors see. The editor reads it via `wp_localize_script`, so any change requires a hard reload of the post-edit page (Cmd+Shift+R) to pick up.

Behaviour rules:

- Swap is **per-block, by closest ancestor**. The leaf block the author has clicked into shows raw `{tokens}` for editing; every other block on the canvas keeps showing samples. Clicking a parent Group block does NOT un-preview every paragraph inside it — only the Group's own (usually empty) text content reveals raw tokens.
- Tokens are only swapped in **visible text content**. Tokens inside attributes (image `src`, button `href`) are left alone.
- Preview spans are typographically transparent — they inherit `font-family`, `font-size`, `font-weight`, `font-style`, `color`, and `text-decoration` from their surrounding context. A swap inside `<strong>` stays bold; a swap inside an `<h1>` keeps the heading's weight. No visible marker, by design — earlier attempts at a dashed underline broke heading bolding and looked like typos when they landed inside `<strong>`.
- A toggle in the document sidebar (**Preview sample values**) flips the simulator off entirely. Defaults on. Persisted per-browser via `localStorage` (`clipisode_preview_values_enabled`), not per-post — the same author gets the same default across every screen.
- Nothing the simulator does is ever written to the database. The post stores raw `{tokens}` regardless of what the editor displays.

### Which tokens get sample values

The runtime renderer (`clipisode-flow.php`) substitutes six tokens server-side: `{host_name}`, `{topic_title}`, `{invitation_slug}`, `{invitation_url}`, `{network}`, `{theme_asset_url}`. The first four appear in visible text and live in `preview-values.json` (or are computed from it — see below). `{network}` lives in the JSON and is also surfaced in visible text on the Name screen. `{theme_asset_url}` only appears in attributes (image `src` URLs), so it's intentionally not in the JSON.

`{pct}` is **not** a runtime-renderer token — it's a runtime-JS placeholder that the upload-progress code on the Name screen substitutes client-side. But because it appears in visible text (`<h2>Uploading {pct}%</h2>`), it's included in `preview-values.json` so authors see realistic copy in the editor.

### `preview-values.json` syntax

Three value shapes are supported:

**Plain string** — used as-is.

```json
"host_name": "Sarah Chen"
```

**Template** — a string containing one or more `{variable}` references. Variables resolve against (a) other entries in the same JSON, and (b) a small set of plugin-derived built-ins. The entire template resolves to empty string if any variable is missing or empty, which causes the editor to show the raw token (so the misconfiguration is visible).

```json
"invitation_url": "{site_url}/{invitation_prefix}/{invitation_slug}/"
```

**Template with fallback** — a `{ template, fallback }` object. The template resolves first; if it's empty, the fallback resolves. Either side can be a plain string or another template. Lets `{invitation_short_url}` degrade to `{invitation_url}` when the short-URL feature isn't configured.

```json
"invitation_short_url": {
    "template": "{short_url_base}/{invitation_slug}/",
    "fallback": "{invitation_url}"
}
```

### Built-in interpolation variables

The resolver provides three plugin-derived variables that templates can reference but the JSON can't know about:

| Variable | Source | Purpose |
|---|---|---|
| `{site_url}` | `home_url()` | Tracks the site's canonical URL. No need to update preview-values when a customer moves domains. |
| `{invitation_prefix}` | `Clipisode_Invitation::get_prefix()` | Tracks the admin-configured invitation route prefix. Localized prefixes (`/invitasjon/`, `/邀請/`) flow through automatically. |
| `{short_url_base}` | `Clipisode_Invitation::get_short_url_base()` | The configured short-URL base if any (e.g. `https://rs.video/i`). Empty until the short-URL feature is configured — see [`docs/specs/planned/short-invitation-urls.md`](../../../../../docs/specs/planned/short-invitation-urls.md). |

Built-ins are stripped from the final preview map before it reaches the editor JS, so authors don't see `{site_url}` etc. as previewable tokens — they're implementation details of how URL-shaped previews are constructed.

### Future: short URLs

`preview-values.json` already declares `{invitation_short_url}` as a template-with-fallback. Today, because `get_short_url_base()` returns empty, the entry resolves to the long invitation URL — the same value `{invitation_url}` produces. When the short-URL feature ships ([spec](../../../../../docs/specs/planned/short-invitation-urls.md)), `get_short_url_base()` will start returning the configured short base, the template will start resolving on its own, and the editor preview + public render will both show the short form. No theme changes required.

If `preview-values.json` is missing or malformed the simulator silently does nothing and the editor shows raw tokens, which is the same behaviour as toggling it off. Implementation lives in `plugin/clipisode/assets/editor/preview-values.js`.

## Slot injection at render time

The public renderer (`clipisode-flow.php`) runs `do_blocks()` on the screen post's content, then walks the result and replaces named slots:

- **URL slot** — `<div class="...clipisode-introd-url-slot...">` → live invitation URL as a styled link.
- **QR placeholder image** — `<figure class="...clipisode-introd-qr-image...">` → the renderer replaces the entire figure with a `<div class="clipisode-introd-qr">` mount that `qrcode.js` hydrates into a `<canvas>` showing the current invitation URL. The figure exists so authors can resize / pad / border / move the QR via standard core/image controls; the lock prevents removal so the public flow always finds a target. (Earlier versions used an empty `clipisode-introd-qr-slot` Group with `templateLock: "all"`; that approach gave authors no way to style the QR and was replaced.)
- **Video slot** — for screens that play the topic intro video, the renderer **appends** the video markup just before the slot wrapper's closing `</div>`. Author-added blocks inside the slot render above the injected video, not in place of it.

The renderer strips the 0-height `clipisode-introd-slot-filler` spacers before slot injection, so empty slots in the editor become truly empty in the rendered HTML.

## Editor canvas styling

`Clipisode_Post_Types::enqueue_screen_editor_canvas_styles()` ships a chunk of CSS into the block editor iframe so screens look roughly like the public flow while authors edit them. Two things to know:

- The CSS targets the editor canvas only. The public flow's appearance is owned by `theme.css`. If you change one, check the other.
- The desktop intro screen uses an aspect-ratio-driven layout (`height: min(90vh, 800px)` on the card, both columns share `aspect-ratio: 9/16`). See `intro_desktop.md`.

## No HTML comments in starter files

Gutenberg parses anything between block markers (`<!-- wp:foo -->` … `<!-- /wp:foo -->`) that isn't itself a block marker as freeform/Classic content, which trips the block validator. **Don't put doc comments inside `.html` files in this folder.** All commentary goes in the matching `.md` file (or this README).

The seeder runs `strip_doc_comments()` on the raw HTML as a safety net — it removes any non-block HTML comment before saving — but relying on the strip is fragile. Treat the rule as: only `wp:*` comments allowed.

## Reseeding after edits

The seeder won't touch existing screen posts (it preserves author edits), so re-seeding only takes effect after the post is fully deleted. The plugin intercepts "Move to Trash" for `clipisode_screen` posts and converts it to immediate permanent deletion, so the trash can never confuse the seeder into thinking a screen already exists.

### With debug mode on

In WP Admin → Clipisode → Settings, enable **Debug mode**. The Screens list page (Clipisode → Screens) gains a **"Clipisode debug tools"** notice with three buttons:

- **Reseed missing screens** — recreates any screen post that's missing. Existing posts are left alone.
- **Re-import default logo** — deletes the cached default-logo attachment and re-imports `icon.png` into the Media Library.
- **Re-import QR placeholder** — same workflow for `assets/editor/sample/sample-qr.png` (the QR placeholder image authors see in the editor; the public renderer always replaces it with the live QR canvas).

Existing screen posts that already reference the old attachment id are not updated; reseed them after re-importing if you want the new image in their markup.

Iteration loop:

1. Edit the `.html` file on disk.
2. (Optional) Replace `icon.png` or `sample-qr.png` on disk.
3. WP Admin → Clipisode → Screens → find the screen → **Move to Trash**.
4. (Optional, if you replaced an image) Click the matching **Re-import** button.
5. Click **Reseed missing screens**.
6. Open the recreated post in the block editor.

### Without debug mode

The lazy-import logic in `substitute_seed_tokens()` self-heals on first seed, so the manual flow still works:

1. WP Admin → Clipisode → Screens → trash the screen.
2. Visit any `/clipisode-flow/<slug>` URL on the public side. The flow template's auto-upgrade loop calls `ensure_screen()` for missing screens, which triggers the lazy logo import on its first run.
3. Reload the WP Admin Screens list; the post is back.

## Default logo and QR placeholder

Two images ship in this folder / `assets/editor/sample/` and get imported into the Media Library on activation:

- **`icon.png`** (500×500 PNG, in this folder) → `clipisode_default_logo_attachment_id` option, surfaced via `{logo_id}` and `{logo_url}` tokens. Used by the desktop intro logo.
- **`assets/editor/sample/sample-qr.png`** (360×360 PNG) → `clipisode_default_qr_attachment_id` option, surfaced via `{qr_id}` and `{qr_url}` tokens. Used as the desktop intro QR placeholder image; the public renderer swaps the figure for a live QR canvas.

`ensure_default_logo_attachment()` and `ensure_default_qr_attachment()` are idempotent — already-imported attachments are reused. If activation didn't fire (e.g. plugin updated in place without deactivate/reactivate), `substitute_seed_tokens()` calls them on demand the first time it sees a token. The plugin-folder source files are the source of truth for re-imports; the debug-mode buttons exist to refresh after edits to those files.

## Editor typography (font-size pickers and heading levels)

The clipisode_screen editor canvas registers a five-step font-size scale (S=12 / M=16 / L=22 / XL=32 / XXL=48) via `merge_clipisode_screen_editor_font_sizes()`. Authors picking a size store the choice as `style.typography.fontSize` on the block, which renders as inline `style="font-size:NNpx"` and beats class-level CSS thanks to higher specificity.

This works only because the editor canvas CSS in `enqueue_screen_editor_canvas_styles()` does NOT use `!important` on `font-size` or `font-weight` in per-class rules. **If you add a new screen, follow the same convention:**

- Pin margin / padding / color / text-align with `!important` (these don't conflict with picker controls).
- Pin `font-size` and `font-weight` WITHOUT `!important` so the size picker, the heading-level dropdown, and any inline block style attribute can override.
- A heading block's level (H1 / H2 / H3 …) flows through the same way: the rendered HTML element changes (`<h1>` → `<h2>`) and the canvas inherits whatever sizing you've defined for that tag, instead of being pinned by class.

Starter HTML for headings and paragraphs that should match the canvas defaults out of the box should also carry inline `style.typography.fontSize` matching the design size. That way the visual default doesn't change when the picker isn't engaged.

## Related code

- `plugin/clipisode/includes/class-post-types.php` — seeder (`ensure_screen`, `seed_default_screens`, `substitute_seed_tokens`, `ensure_default_logo_attachment`, `ensure_default_qr_attachment`), trash interceptor, debug-mode admin tools, editor canvas CSS.
- `plugin/clipisode/assets/templates/clipisode-flow.php` — public renderer, slot injection, `do_blocks()` pipeline.
- `plugin/clipisode/assets/themes/default/theme.css` — public stylesheet.
