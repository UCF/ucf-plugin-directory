# Project plan: UCF Plugin Directory (WordPress plugin)

You are building a WordPress plugin, in this directory, that lets UCF web developers browse, install, and update UCF's WordPress plugins and themes from inside WordPress — the Plugins → Add New screen, Themes → Add New, the Updates screen, and WP-CLI — without publishing anything to wordpress.org.

The plugin's catalog is the `ucf` vendor on the public **packagist.org** registry. Work through the phases in order. Each phase ends with acceptance criteria; do not start the next phase until they pass. Before writing code, read the "Open questions" section and ask the user about anything marked **ask first**.

---

## 1. Context (verified 2026-10-19)

### Where the packages live

UCF is moving its Composer packages from a self-hosted Satis index (`https://ucf.github.io/ucf-packagist/`) to packagist.org, under the vendor name `ucf`. Packagist.org picks up new tags automatically through GitHub webhooks, so this plugin needs no publishing pipeline of its own. **The migration may be partly or entirely incomplete while you build.** `list.json?vendor=ucf` may return only a few packages, or none. Develop and test against fixtures (Phase 0), and do not build a Satis code path — the Satis index is being retired.

For scale, the Satis index being replaced holds 79 packages:

- **58 plugins.** These have type `wordpress-plugin`.
- **20 themes.** These have type `wordpress-theme`.
- **1 WP-CLI package.** This has type `wp-cli-package`.

Together they have about 310 tagged releases. Every package is a public repo in the `UCF` GitHub org, and the `composer.json` names already use the form `ucf/<name>`.

### Packagist.org endpoints to use

Use only the static, cache-friendly endpoints. These are documented at https://packagist.org/apidoc; re-check that page if anything below disagrees with what you observe.

- **Package list:** `GET https://packagist.org/packages/list.json?vendor=ucf&fields[]=type&fields[]=abandoned&fields[]=repository`. With `fields[]`, the response is `{ "packages": { "<name>": { "type": …, "abandoned": …, "repository": … } } }`. Only `type`, `abandoned`, and `repository` are allowed fields. Use this to discover the catalog in one small request.
- **Per-package metadata (tagged releases):** `GET https://repo.packagist.org/p2/ucf/<package>.json`. Dev branches are in a separate `~dev.json` file. **Never fetch `~dev.json`**; v1 ignores dev versions entirely.
- **The p2 format is minified** (`"minified": "composer/2.0"`). Versions are listed newest first. Each version inherits every field from the version before it unless it overrides that field, and the string value `"__unset"` deletes an inherited field. Implement this expansion yourself (it is about 15 lines; cover it with tests) rather than adding a Composer runtime dependency.
- **Conditional requests:** p2 files support `If-Modified-Since` / `Last-Modified`. Store `Last-Modified` per package and send it on refresh; a `304` means keep the cached data.
- **Change feed (optional optimization):** `GET https://packagist.org/metadata/changes.json?since=<timestamp>` returns `update` / `delete` / `resync` actions, kept for 24 hours. It's a stretch goal; conditional requests are enough for v1.
- **Avoid `https://packagist.org/packages/<vendor>/<package>.json`.** It is generated dynamically, and Packagist asks clients to use the static files instead.
- **Etiquette (required):**
  - Send a `User-Agent` that names the plugin, its version, and a contact address in the form `mailto=<address>` (see Open questions).
  - Make no more than 10 concurrent requests.
  - Schedule background refreshes at a randomized minute, never at `:00`.

### What the metadata contains

Each expanded p2 version has the same fields the Satis index had: `name`, `version`, `version_normalized`, `source`, `dist`, `time`, `type`, `description`, `support`, and sometimes `license`, `authors`, `homepage`. It has no WordPress-specific fields (display name, "Requires at least", "Tested up to", "Requires PHP"). `extra` passes through if a package defines it; no UCF package currently does.

### Downloads and folders

- **Download URLs are GitHub API zipballs.** Every `dist.url` looks like `https://api.github.com/repos/UCF/<Repo>/zipball/<sha>`, and `dist.shasum` is empty.
- **They extract to the wrong folder name.** A zipball unzips to something like `UCF-Section-Menus-Shortcode-2c9030e/`, not to the plugin's slug.
- **WP-CLI only partly works around this.** For `wp plugin install <url>`, WP-CLI renames `github.com/<org>/<repo>/archive/…` downloads to the repo name with its case kept (`UCF-FAQ-CPT`, not `ucf-faq-cpt`), and it does nothing for `api.github.com` zipball URLs. Its `--slug=<dir>` option renames the extracted folder explicitly. This was checked against `wp-cli/extension-command` source on 2026-10-19; confirm the minimum WP-CLI version that has `--slug`.
- **GitHub rate-limits these downloads.** Unauthenticated GitHub API requests are limited per IP (60/hour at last check), and many UCF sites likely share egress IPs.

### Directory naming

With `composer/installers` and no `installer-name`, Composer installs `ucf/foo-bar` into `wp-content/plugins/foo-bar/` (or `themes/foo-bar/`). **Dashboard installs must use the same directory name**, so a site can move between Composer and dashboard management without the plugin's path changing.

### Hosting

Some UCF sites run on Pantheon, where the filesystem is read-only outside Dev/SFTP mode and `DISALLOW_FILE_MODS` may be set. The plugin must behave correctly (read-only, no errors) when file mods are disallowed.

## 2. Goals and non-goals

**Goals (v1)**

- **A "UCF" tab on Plugins → Add New** that lists UCF plugins as native plugin cards, with search, a working "More Details" modal, and one-click Install/Activate.
- **Dashboard and WP-CLI updates** for installed UCF plugins and themes, using the WordPress 5.8+ `Update URI` mechanism with packagist.org package URLs.
- **`wp plugin install <slug>` working for UCF slugs** when this plugin is active, plus a small `wp ucf` command for listing and searching.
- **Themes support** with the same behavior on Themes → Add New and theme updates (a separate phase, so it can slip to v1.1 without blocking plugins).
- **Self-updating:** this plugin is itself published to packagist.org as `ucf/ucf-plugin-directory` and carries its own `Update URI`.

**Non-goals**

- Not a replacement for Composer-based deployment. It sits alongside Composer.
- No private-package, Private Packagist, or license-key support.
- No support for the retired Satis index.
- No UI for installing `dev-*` branches in v1.
- No custom admin pages beyond one small settings/diagnostics screen.

## 3. Architecture

Keep the plugin small and dependency-free at runtime. Suggested layout; adapt names to UCF conventions once you've inspected an existing UCF plugin (Phase 0).

```
ucf-plugin-directory/
  ucf-plugin-directory.php      Main file: headers (incl. Update URI), constants, bootstrap
  includes/
    class-packagist-client.php  HTTP to packagist.org: list.json, p2 files, conditional requests
    class-metadata-expander.php composer/2.0 minified-format expansion
    class-catalog.php           Cached, resolved catalog: latest stable release per package
    class-package.php           Value object: one package's resolved release
    class-plugins-api.php       plugins_api / Add New tab integration
    class-themes-api.php        themes_api / theme install integration
    class-updater.php           update_plugins_packagist.org / update_themes_packagist.org
    class-upgrader.php          Folder renaming, download auth, URL allowlist
    class-settings.php          Settings + diagnostics screen
    class-cli.php               `wp ucf` command (loaded only under WP_CLI)
  tests/                        PHPUnit (WP test suite) + fixtures
  README.md
```

### Key design decisions

- **Catalog discovery, then per-package metadata.** Fetch `list.json` (vendor `ucf`, with `type`, `abandoned`, and `repository`) to learn which packages exist and their types. Then fetch each relevant package's p2 file with conditional requests, sequentially or in small batches (≤10 concurrent; WordPress's bundled `WpOrg\Requests\Requests::request_multiple` is fine). Store the *expanded, reduced* result per package (the latest stable release plus the fields we display) along with its `Last-Modified` value.
- **Caching and refresh.** Refresh the catalog at most every 12 hours from a WP-Cron event at a randomized minute, and on demand from the diagnostics screen and `wp ucf refresh`. Never fetch on front-end requests. Admin screens read only from cache, except for a one-time blocking fetch when the cache is empty. Fail soft: if a fetch fails, keep serving the last good data and record the error for diagnostics. Use site transients or a single autoload-off option. Measure the stored size and keep it reasonable for ~80 packages.
- **Version resolution.** Use p2 tagged releases only. Choose the highest `version_normalized` with `version_compare()`. Exclude pre-releases (`-alpha`, `-beta`, `-RC`) by default.
- **Abandoned packages.** Hide packages that `list.json` marks `abandoned` from the Add New listing, but keep offering updates for them to sites that already have them installed. Show the abandoned notice (and any suggested replacement) in "More Details".
- **Slug = package name minus the `ucf/` prefix.** This matches composer/installers and is the directory name used for installs. Answer `plugins_api` / `themes_api` only for slugs in our catalog, so we never interfere with wordpress.org lookups for other slugs.
- **`Update URI` names the package explicitly.** UCF plugins and themes declare `Update URI: https://packagist.org/packages/ucf/<package>`. Hook `update_plugins_packagist.org` and `update_themes_packagist.org`, parse the package name from the URI's path, and look it up in the catalog. No slug guessing is needed, and the header also stops WordPress from ever offering a wordpress.org "update" that would overwrite a same-slug UCF plugin.
  - **Only answer for allowed vendors.** Another plugin on the same site could use a packagist.org `Update URI` too. Answer only for vendors on an allowlist (default `['ucf']`, filterable), and return `false` for everything else so core behaves normally.
- **Fallback matching for plugins without the header.** Most existing UCF plugins probably don't have an `Update URI` yet (**unverified — check a few repos in Phase 0**). Add an opt-in setting, off by default, that matches installed plugins whose directory equals a catalog slug and offers updates through `pre_set_site_transient_update_plugins`. It is off by default because a directory-name match is weaker evidence than an explicit header.
- **Folder renaming.** On `upgrader_source_selection`, if the package came from our catalog (track it via `upgrader_pre_download` or `hook_extra`), rename the extracted `UCF-Foo-abc123/` directory. The target name depends on the operation:
  - **Fresh install:** rename to `<slug>/`, the same directory Composer would use.
  - **Update:** rename to **the directory the plugin or theme already occupies**, taken from `hook_extra['plugin']` (or `hook_extra['theme']`). Never "correct" an existing install to `<slug>/`: WordPress tracks active plugins by path, so a rename during update would deactivate the plugin. A site that has `UCF-FAQ-CPT/` from an old manual install keeps `UCF-FAQ-CPT/`.
  - **Bulk updates:** handle `hook_extra` for these as well, where each item carries its own plugin or theme.
  - **Already-correct names:** if the extracted directory already has the target name (e.g. a release zip built with the right folder), leave it alone.
- **Find the main plugin file after extraction.** The main plugin file isn't known until install, so locate it with `get_plugins()` on the new directory. The Activate link and update matching depend on it.
- **Download security.** Allowlist download URLs to `api.github.com/repos/UCF/`, `codeload.github.com/UCF/`, and `github.com/UCF/`, and refuse anything else. Also require the package's `repository` (from `list.json`) to be under `github.com/UCF/`. Packagist's vendor protection means only UCF maintainers can publish `ucf/*`; together with HTTPS and the allowlist, that is the integrity model, since `shasum` is empty. Document this in the README.
- **GitHub rate limits.** Support an optional constant `UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN`, defined in `wp-config.php` and never stored in the database. When it is set, add an `Authorization` header via `http_request_args`, but only for the allowlisted GitHub hosts and never for packagist.org. Show the remaining rate limit on the diagnostics screen when known.
- **Missing WordPress metadata.**
  - **Derive the display name.** Build it from the package name (`ucf-faq-cpt` → "UCF FAQ CPT"), with an override map via filter. Leave requires/tested blank.
  - **Prefer `extra.wordpress` when present.** If a package defines `extra.wordpress` (`name`, `requires`, `tested`, `requires_php`), use those values instead. Repos can adopt this gradually (Phase 6).
- **Capabilities and context.** Show the UI only to users with `install_plugins` / `update_plugins` (or the theme equivalents). On multisite, show it in Network Admin only. When `DISALLOW_FILE_MODS` is true, show the catalog read-only with a note, and don't hook install or update actions.
- **Extensibility filters**, all prefixed `ucf_plugin_directory_`:
  - **`vendors`** sets the vendor allowlist (default `['ucf']`).
  - **`excluded_packages`** lists packages to hide from the catalog.
  - **`display_names`** maps packages to display-name overrides.
  - **`cache_ttl`** sets how long the catalog is cached.
  - **`allowed_download_hosts`** sets the download URL allowlist.
  - **`user_agent_contact`** sets the contact address sent in the User-Agent.

## 4. Phases

### Phase 0 — Orient and scaffold

1. Ask the user the **ask first** questions in Section 5.
2. Inspect one or two existing UCF plugins on GitHub (e.g. `UCF/UCF-FAQ-CPT`, `UCF/UCF-Section-Plugin`). Note the file layout, naming and prefixing, header format, minimum PHP, phpcs config, and release workflow, and match them. While you're there, report whether any UCF plugin already declares `Update URI`.
3. Check `https://packagist.org/packages/list.json?vendor=ucf` and report how many UCF packages are live on packagist.org so far.
4. Scaffold the plugin:
   - **The main file** has headers including `Update URI: https://packagist.org/packages/ucf/ucf-plugin-directory`.
   - **`composer.json`** uses type `wordpress-plugin`, name `ucf/ucf-plugin-directory`, and requires `composer/installers`, matching the other UCF packages.
   - **Supporting files:** a README, a phpcs config, and a PHPUnit setup against the WordPress test suite (or `wp-env`, whichever UCF already uses).
5. Build fixtures in `tests/fixtures/`:
   - **A sample `list.json`** that includes a plugin, a theme, a `wp-cli-package`, and an abandoned package.
   - **Real minified p2 files** captured from packagist.org. Use UCF packages if any are live; otherwise use a small public package such as `composer/installers`, with a copy renamed to look like a UCF package.
   - **A hand-written p2 file** that exercises inheritance, `__unset`, pre-release tags, and `v`-prefixed tags.

**Done when:** the plugin activates cleanly on a local site, phpcs passes, and an empty test suite runs.

### Phase 1 — Packagist client and catalog

Build `Packagist_Client`, `Metadata_Expander`, `Catalog`, and `Package`:

- **Fetching:** list discovery, p2 fetches with conditional requests, a User-Agent with contact info, a concurrency cap, and timeouts.
- **Catalog building:** minified-format expansion, version resolution, type filtering, abandoned handling, exclusions, and the vendor allowlist.
- **Caching and errors:** cron refresh at a randomized minute, and last-error recording.

**Done when** unit tests against the fixtures prove all of the following:

- **Expansion is correct.** Expansion matches hand-expanded output, including `__unset`.
- **Version resolution is correct.** The highest stable tag wins (including `v`-prefixed tags), and pre-releases are excluded.
- **Filtering is correct.** `wp-cli-package` and excluded packages are filtered out, and abandoned packages are flagged.
- **Conditional requests work.** A `304` response keeps the cached package without re-parsing.
- **Failures are soft.** A failed or malformed response leaves the last good catalog in place.
- **Front-end requests never fetch.** No HTTP request happens on a front-end page load.

### Phase 2 — Plugin install UI

1. Add the "UCF" tab with `install_plugins_tabs`. Render it with core's `WP_Plugin_Install_List_Table`: hook `install_plugins_table_api_args_ucf` to pass a marker arg, then answer `plugins_api` `query_plugins` requests that carry the marker. Support search over name, slug, and description.
2. Answer `plugins_api` `plugin_information` for our slugs. Return the name, slug, and version; the description as its section; the homepage or GitHub link; last updated (`time`); the abandoned notice if any; and `download_link` set to the resolved `dist.url`. This also powers the "More Details" modal.
3. Implement `Upgrader`: the URL and repository allowlist, the optional token header, and folder renaming in `upgrader_source_selection`.
4. Make sure the native card's Install → Activate flow works, including activation of the main file found after extraction.

**Done when,** on a clean local site:

- **The tab works.** The UCF tab lists non-abandoned plugins, and search works.
- **More Details opens.**
- **Installs land in the right folder.** Install puts the plugin at `wp-content/plugins/<slug>/`, not the GitHub folder name, and Activate works.
- **Bad URLs are refused.** A non-allowlisted `download_link`, injected via a test filter, is refused with a readable error.
- **wordpress.org is untouched.** Search and installs on the other tabs behave normally.

### Phase 3 — Updates

1. Implement `update_plugins_packagist.org`. Return `slug`, `version`, `url` (the packagist.org package page), and `package`, plus `tested`/`requires_php` when known. Return `false` for vendors outside the allowlist.
2. Implement the opt-in directory-match fallback described in Section 3.
3. Confirm the plugin updates itself by the same path.

**Done when:**

- **Updates appear.** Installing an older tag of a UCF plugin that has the `Update URI` header produces an update notice on Dashboard → Updates and on the Plugins screen.
- **Updates preserve state.** Updating keeps the directory name and the active state.
- **Non-standard folders survive updates.** A UCF plugin installed by hand in a non-standard directory (e.g. `UCF-FAQ-CPT/`) updates in place, stays in that directory, and stays active. Test this for both single and bulk updates.
- **WP-CLI sees updates.** `wp plugin update --all` and `wp plugin list --update=available` both show the update.
- **Other vendors are left alone.** A test plugin with `Update URI: https://packagist.org/packages/someone-else/foo` gets no update from us.
- **The fallback toggle works.** With the fallback off, a UCF plugin without the header gets no update offer; with it on, it does.

### Phase 4 — Themes

Mirror Phases 2–3 for themes:

- **Install and browse:** `themes_api` (`query_themes`, `theme_information`) for a UCF view on Themes → Add New.
- **Updates:** `update_themes_packagist.org` (confirm the minimum WordPress version for the theme `Update URI` hook against core).
- **Folders:** the same folder renaming as plugins.

If a child theme's parent is a UCF theme that isn't installed, show it as a requirement, and offer to install it if core's flow allows. Otherwise, document the limitation.

**Done when:** the Phase 2–3 acceptance checks pass for a parent theme and a child theme.

### Phase 5 — WP-CLI and settings

1. Confirm whether `wp plugin install <ucf-slug>` and `wp theme install <ucf-slug>` work automatically through the `plugins_api` / `themes_api` filters (expected, but **verify**). Confirm the folder rename runs under WP-CLI. Fix gaps if not.
2. Add `wp ucf list [--type=plugin|theme] [--format=…]`, `wp ucf search <term>`, and `wp ucf refresh`.
3. Add a small settings/diagnostics screen (Settings → UCF Plugin Directory, or Network Settings on multisite). It should show:
   - **Fetch status:** the last refresh time and the last error.
   - **Catalog counts:** the number of packages by type, and how many are abandoned.
   - **Download headroom:** the GitHub rate-limit status.
   - **Controls:** a "Refresh now" button and the fallback-matching toggle.

**Done when:** each CLI command works and has at least a smoke test, and the diagnostics screen reflects a forced fetch failure.

### Phase 6 — Release and rollout (work outside this plugin)

These are tasks for the UCF repos and the packagist.org migration, not for this plugin. Write them up in the README as a rollout checklist; don't do them yourself.

- **Finish the packagist.org migration.** All active UCF packages should be live under `ucf/` with GitHub hooks synced, and obsolete ones marked abandoned. Sites that use Composer drop the `ucf.github.io/ucf-packagist` repository entry.
- **Add `Update URI: https://packagist.org/packages/ucf/<package>`** to the header of every UCF plugin and theme, starting with the most widely deployed. This is the step that turns updates on.
- **Optionally add `extra.wordpress`** to each package's `composer.json` (`name`, `requires`, `tested`, `requires_php`). Packagist passes `extra` through to p2 metadata, and the plugin already reads it.
- **Optionally attach a built release zip** with the correct top-level folder name to each GitHub release. Folder renaming already handles zipballs, so this is a nice-to-have.
- **Publish this plugin** as `ucf/ucf-plugin-directory` on packagist.org, so it can be installed by Composer or once by hand and then keep itself updated.

**Done when:** the README documents the following:

- **Installation.** This includes the first install of this plugin itself, which happens before its own rename hook exists. Document two routes: Composer, or `wp plugin install <zip URL> --slug=ucf-plugin-directory`. Also note that `--slug=<package-name>` is the way to install any UCF plugin from a URL on a site without this plugin.
- **The token constant.**
- **Filters.**
- **Pantheon/`DISALLOW_FILE_MODS` behavior.**
- **The security model.**
- **Packagist etiquette settings.**
- **The rollout checklist.**

## 5. Open questions

- **Ask first: plugin name and slug.** The default proposal is "UCF Plugin Directory" / `ucf-plugin-directory`.
- **Ask first: minimum PHP and WordPress versions.** The plugin `Update URI` hook needs WordPress 5.8+, and the theme hook needs a later version (verify which).
- **Ask first: the contact address for the Packagist User-Agent** (`mailto=…`). Use a team address, not an individual's.
- **Ask first: themes in v1, or plugins only?** The default is plugins first, with themes as Phase 4 in the same release if time allows.
- **Ask first: which packages to hide** beyond abandoned ones and the `wp-cli-package` type. Note that `ucf/github-action-testing` may not be migrated at all.
- **Do existing UCF plugins already use `Update URI`?** Report in Phase 0. The answer decides how much the fallback matters.
- **Is a GitHub token needed?** It depends on how many sites share an egress IP. Build the support either way, and let the user decide whether to deploy a token.

## 6. Working agreements

- Match existing UCF code style. Prefix all functions, classes, options, transients, and hooks.
- Escape all output and sanitize all input. Never trust metadata fields as HTML; render descriptions as plain text.
- Don't call packagist.org or GitHub on front-end requests. Fetch only from cron, CLI, and admin contexts, with admin limited to an empty cache or an explicit refresh.
- Keep each phase in its own commit (or commits) with tests. Stop at the end of each phase and summarize for the user what works, what doesn't, and anything you had to decide.
