# WP Deployer

Push content and design changes from one WordPress site to another (local → staging → production) item by item, without replacing the receiving site's database. Form entries, users, comments, SEO data and plugin activation on the receiving site are left alone.

## What it pushes

Sources are detected from the plugins and theme on the site and switched on or off in **WP Deployer › Settings**:

- **Post types**, grouped by the plugin that registered them: pages, posts, LiveCanvas partials / dynamic templates / sections / blocks, Forminator forms, and so on. Pages, LiveCanvas types and Forminator forms are on by default. Posts are off, so blog posts written on the live site are never touched.
- **Menus**: items and theme locations.
- **Additional CSS**, **theme settings** (Customizer theme mods by name pattern, default `SCSSvar_*`, `picostrap_*`, font objects) and the **compiled Picostrap CSS bundle**. Picostrap compiles SCSS in the browser, so the bundle is sent with the tokens.
- **Homepage & posts page** (Settings › Reading).
- **Plugin settings**: only the options you name (default `lc_settings`, which holds the LiveCanvas header, footer and dynamic templates switches). Site URL and name, active plugins, user roles and anything that looks like a key, token, secret or password are never pushed.

Images and upload files referenced by pushed items travel with them, together with every generated size. Posts the selection depends on (a form embedded in a page, an `lc_get_post` section, a parent page) are added automatically when the target doesn't have them.

Nothing is ever deleted on a target.

## How matching works

Each pushed object gets a UUID (`_wpd_uuid` post/term meta). On the first push the target matches by post type + slug, attachment file path or menu slug, and adopts the UUID. After that, matching is by UUID.

IDs and URLs inside content are made portable on the way out and resolved on arrival:

- site URL and uploads URL, including JSON-escaped forms
- `wp-image-N` classes and `id="attachment_N"`
- ID attributes of known shortcodes: Forminator, `lc_get_post`, `gallery`. Extend with the `wpd_id_shortcodes` filter.
- block comment attributes `id`, `ref`, `mediaId` and `featuredImage`
- ID meta keys: `_thumbnail_id`. Extend with the `wpd_id_meta_keys` filter.

## Safety

- **Preview** shows exactly what will be created, updated, left unchanged or skipped.
- The target remembers what it last received. Each item shows *New*, *Changed here*, *In sync*, *Edited on target* or *Changed on both*. Items edited on the target are skipped unless you tick **overwrite** for that item.
- **Protected meta** (default: Yoast, Rank Math, AIOSEO, SEOPress, Genesis, Forminator's submission counter) is never sent and never overwritten. The target only touches meta keys the sender has pushed before, so meta added by the target's own plugins is left alone.
- Every push is recorded on the target with a snapshot of everything it changed. **WP Deployer › History** on the target can roll a push back, which also deletes what it created. A push that fails halfway is rolled back automatically. Upload files a push overwrote are not restored.

## Setup

1. Install and activate the plugin on both sites.
2. On the **target** (staging/production):
   - **Settings › Receive pushes** → tick, save.
   - Create an application password for an administrator under **Users › Profile › Application Passwords**.
3. On the **sending** site (local):
   - **Settings › Push targets** → add the target's URL, the admin username and the application password. The password is stored encrypted with this site's `AUTH_KEY` salt.
4. **WP Deployer › Push** → pick the target → *Compare* → tick items → *Preview push* → *Push*.

The target must use HTTPS, which WordPress requires for application passwords. If the host strips the `Authorization` header (some Apache + FastCGI setups), the plugin also sends credentials as `X-WPD-Auth`, and the receiving side accepts either.

## Updates

Sites update WP Deployer from this repo's GitHub releases, with an **Update** button in Dashboard › Updates and on the Plugins screen, auto-updates included. The repo is private, so each site needs a token:

1. GitHub › Settings › Developer settings › **Fine-grained tokens** › Generate.
   - **Repository access:** only `scbudgetweb/wp-deployer`.
   - **Permissions:** *Contents: Read-only*.
2. Add it to the site's `wp-config.php`:
   ```php
   define( 'WPD_GITHUB_TOKEN', 'github_pat_…' );
   ```
   Or paste it in **WP Deployer › Settings › Plugin updates**, where it's stored encrypted.

WordPress checks every 12 hours, and the plugin caches GitHub's answer for 6. **Dashboard › Updates › Check again** checks immediately.

## Releasing

1. Bump the version in `wp-deployer.php` (both the `Version:` header and `WPD_VERSION`) and commit.
2. Tag and push:
   ```
   git tag -a v0.3.0 -m "WP Deployer 0.3.0" -m "Release notes…"
   git push origin main v0.3.0
   ```

The Release workflow checks the tag matches the plugin version and builds `wp-deployer-0.3.0.zip`. It then publishes the release, using the tag message as the notes. Sites offer the update from there.

## Not covered (yet)

- Deleting items, and pushing in the other direction (pull)
- Theme and plugin **code**: deploy those files separately (rsync/Deployer)
- Users, comments, form entries, WooCommerce data: deliberately never synced
