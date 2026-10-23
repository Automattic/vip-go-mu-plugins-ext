# Share a Draft

**Contributors:** nbachiyski, automattic, lessbloat, garyj  
**Tags:** draft, preview, share, sharing, review  
**Requires at least:** 6.9  
**Tested up to:** 7.1  
**Requires PHP:** 8.2  
**Stable tag:** 2.0.1  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Create a secure, time-limited link to a draft, so a reviewer without a WordPress account can read it before you publish.

## Description

Drafts in WordPress can only be seen by people who can edit them. When a client, a legal reviewer, or a colleague without an account needs to read a draft before it goes live, Share a Draft gives you a link for exactly that, and keeps you in control of it.

Open a draft, choose **Generate preview link** in the Share a Draft panel, and send the link to your reviewer. They see the draft exactly as it will look when published, without logging in.

Every link is:

- **Private.** Only people with the link can open the draft. Links are kept out of search engines and page caches, and an unknown link is a plain "not found", so nobody can go looking for drafts.
- **Time-limited.** Choose how long the link lasts, from an hour to a week. Developers can [offer other lifetimes](https://github.com/Automattic/shareadraft/blob/main/docs/customizing.md#offer-different-link-lifetimes).
- **Use-limited, if you want.** Cap how many people can open it, down to a one-time link.
- **Bound to named reviewers, if you want.** List reviewers' email addresses, and each one confirms theirs with a one-time code before the draft opens, so a forwarded link is useless to anyone else.
- **Restricted to trusted networks, if you want.** Allow only certain IP addresses or ranges, such as your office network.
- **Revocable.** Switch off any link at any moment. A reviewer who returns to an expired or revoked link is told why it stopped working.

Links also tidy themselves up: they are discarded when a draft is published, made private, or trashed, and revoked when the person who created them is deleted.

Editors and administrators get a **Preview Links** screen listing every link on the site, with who created it, how often it has been used, and when it expires. From there they can revoke links in bulk (for example, everything one person made, when they leave), and administrators can pause every link at once while investigating a suspected leak, then switch them back on. See [managing links](https://github.com/Automattic/shareadraft/blob/main/docs/managing-links.md) for the details, including hooks for your own offboarding process.

Developers can do all of this from the shell with [WP-CLI](https://github.com/Automattic/shareadraft/blob/main/docs/wp-cli.md) (`wp shareadraft create`, `list`, `revoke`, `prune`, `disable` and `enable`), or let AI assistants and MCP clients do it through the WordPress [Abilities API](https://github.com/Automattic/shareadraft/blob/main/docs/abilities.md), under the same rules and permissions as the editor. They can also [customize](https://github.com/Automattic/shareadraft/blob/main/docs/customizing.md) how links behave, from the lifetimes on offer to what reviewers are told.

Share a Draft works on any host, with nothing to configure. On [WordPress VIP](https://wpvip.com/) it is also available as an integration, with [hosting requirements already met and optional settings in the VIP Dashboard](https://github.com/Automattic/shareadraft/blob/main/docs/wordpress-vip.md).

### Upgrading from Share a Draft 1.x

Share a Draft 2.0 is a rewrite. Links made with 1.x keep working until they expire, and their owners can review and delete them under **Posts → Share a Draft (Old)**, which only appears while they have one. 1.x links cannot be extended; new links are made from the post editor. Support for 1.x links will be removed in 2.1.0.

None of 2.0's controls apply to 1.x links. Pausing every link, trusted IP ranges, viewer limits, named reviewers, the Preview Links screen, bulk revoking, WP-CLI and the Abilities API all cover 2.0 links only, and a 1.x link keeps working even if its post is made private or trashed. Only the person who made a 1.x link can delete it, so if one leaks, ask them to delete it under **Posts → Share a Draft (Old)**.

## Installation

1. Install and activate Share a Draft from **Plugins → Add New Plugin**.
2. Open a draft, and find the **Share a Draft** panel in the post sidebar. It appears in the block editor and on the classic editing screen.
3. Manage every link on the site under **Preview Links** in the admin menu.

There are no settings to configure. If your site sits behind a page cache, a reverse proxy, or a host that cannot send email, check the [hosting requirements](https://github.com/Automattic/shareadraft/blob/main/docs/hosting.md) first.

## Frequently Asked Questions

### Does it work with the Classic Editor?

Yes. Where a post is edited on the classic screen, whether through the Classic Editor plugin or because its post type does not use the block editor, Share a Draft adds a **Share a Draft** box to the sidebar with the same **Generate preview link** and **Manage preview links** buttons.

### Does my reviewer need a WordPress account?

No. Anyone with the link can open the draft, unless you have bound the link to named reviewers, in which case they confirm their email address with a one-time code first.

### What does the reviewer see?

The draft, rendered by your theme exactly as it will look when published. If the link has expired, been revoked, or been used up, they see a short notice saying so instead.

### Why can I still see a draft through a link that has expired or been used up?

Because you can edit the post. Anyone who can edit it, such as its author, editors, and administrators, sees the draft through WordPress's own preview, so a link's expiry, viewing limit, and other restrictions don't apply to them, and their visits don't use up any of its views. Reviewers who cannot edit the post are held to every limit.

### Can I change a link after sharing it?

No. A link's lifetime and limits are fixed when it is created. To change them, revoke the link and generate a new one.

### I think a link has leaked. What should I do?

Revoke it from the editor or the Preview Links screen. If you are not sure which link leaked, an administrator can pause every link on the site at once from the Preview Links screen, and switch them back on when the dust settles. Pausing doesn't revoke or change any link: once links are switched back on, each one works again until its own expiry. Pausing does not cover links made with 1.x: only the person who made one can delete it, under **Posts → Share a Draft (Old)**.

### Does it work with page caching?

Yes, as long as your page cache does not serve cached copies of preview requests. Almost every cache already skips them. The [hosting requirements](https://github.com/Automattic/shareadraft/blob/main/docs/hosting.md) explain what to check.

### Why do IP-restricted links fail behind my proxy or CDN?

Behind a reverse proxy, every visitor appears to come from the proxy's address. A few lines of code [tell Share a Draft the visitor's real address](https://github.com/Automattic/shareadraft/blob/main/docs/customizing.md#behind-a-reverse-proxy-tell-share-a-draft-the-visitors-real-ip-address).

### Can I turn off the reviewer email and IP restriction options?

Yes, with [a line of code each](https://github.com/Automattic/shareadraft/blob/main/docs/customizing.md#turn-off-named-reviewer-or-ip-restriction-features). The options disappear from the editor and the Preview Links screen, and links that already use them keep enforcing them.

### Does it work with the Export and Erase Personal Data tools?

Yes. A named reviewer's email address shows up in Tools → Export Personal Data as a list of the preview links it is bound to. Erasing it removes the address from each link, and a link left with no reviewers is revoked rather than opened to anyone.

### Where can I report a bug or contribute?

On [GitHub](https://github.com/Automattic/shareadraft). See the [contributing guide](https://github.com/Automattic/shareadraft/blob/main/CONTRIBUTING.md) to get started.

## Screenshots

1. The Share a Draft panel in the block editor's post sidebar.
2. Generating a link: how long it lasts, how many people can open it, and optionally who can open it and from where.
3. Managing a draft's links from the editor.
4. The Preview Links screen, listing every link on the site, with bulk revoking and a switch to pause every link.
5. What a reviewer sees when a link has been revoked.

![The Share a Draft panel in the block editor's post sidebar.](.wordpress-org/screenshot-1.png)
![Generating a link.](.wordpress-org/screenshot-2.png)
![Managing a draft's links from the editor.](.wordpress-org/screenshot-3.png)
![The Preview Links screen.](.wordpress-org/screenshot-4.png)
![What a reviewer sees when a link has been revoked.](.wordpress-org/screenshot-5.png)

## Changelog

The highlights of recent releases are below. Every change, including older releases, is listed in [CHANGELOG.md](https://github.com/Automattic/shareadraft/blob/main/CHANGELOG.md).

### 2.0.1

- **Changed:** The editor and the Preview Links help explain that people who can edit a post skip a link's limits.
- **Fixed:** Create and manage preview links from the classic editing screen, for sites using the Classic Editor plugin and for post types without the block editor.
- **Fixed:** Deleting a user from a multisite network revokes their preview links on every site they belong to.
- **Fixed:** A named reviewer is told straight away when a link has reached its viewing limit, rather than being emailed a code first.
- **Fixed:** Revoking a link on the Preview Links screen keeps the creator filter and page you were on.

### 2.0.0

A rewrite. Requires WordPress 6.9 and PHP 8.2 or later.

- **New:** Generate preview links from the Share a Draft panel in the block editor or the classic editing screen, and choose how long each one lasts and how many people can open it.
- **New:** Bind a link to named reviewers, who confirm their email address with a one-time code, or restrict it to trusted IP ranges.
- **New:** A Preview Links screen listing every link on the site, with bulk revoking (for example, everything one person made) and an administrator switch to pause every link at once.
- **New:** Reviewers who open an expired, revoked, or used-up link are told why it stopped working. Unknown links stay a plain "not found".
- **New:** Manage links with WP-CLI (`wp shareadraft`) and the Abilities API, under the same rules and permissions as the editor.
- **New:** Links are discarded when a draft is published, made private, or trashed, and revoked when the person who created them is deleted.
- **New:** Support for the Export and Erase Personal Data tools, and a Site Health check for the cleanup schedule.
- **Changed:** Links are created in the editor rather than under Posts → Share a Draft, which remains only for reviewing and deleting 1.x links, as Share a Draft (Old).
- **Removed:** Extending a link's lifetime. Generate a new link instead.
- **Security:** Only a hash of each link's token is stored, and every limit is enforced on the server.

### 1.7

- **New:** A button to copy a shared draft's link.
- **Changed:** A redesigned Share a Draft screen.
- **Fixed:** Previews breaking the header and other template parts on block themes.

### 1.6

- **Fixed:** PHP 8 deprecation notices, warnings from shares whose post was deleted, and a comparison that could make a valid link 404.

## Upgrade Notice

### 2.0.1

Brings preview links to the classic editing screen, revokes a deleted user's links across a multisite network, and fixes several smaller issues with the Preview Links screen and link cleanup.

### 2.0.0

A rewrite. Create links from the block editor and manage them under Preview Links. Links made with 1.x keep working until they expire, and can be reviewed or deleted under Posts → Share a Draft (Old), until 2.1.0. 2.0's controls, such as pausing links and trusted IP ranges, do not apply to them.
