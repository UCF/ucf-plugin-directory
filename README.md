# UCF Plugin Directory

Browse, install, and update UCF's WordPress plugins from inside WordPress — a **UCF** tab on Plugins → Add New, update notices on the Plugins and Updates screens, and `wp plugin install <slug>` / `wp plugin update` from WP-CLI — without publishing anything to wordpress.org.

> [!CAUTION]
> **Under development — not for production sites.** This is a proof of concept (v0.1.0) awaiting approval to proceed. It covers plugins only; themes, the `wp ucf` command, and the packagist.org cutover are later phases of `PLAN.md`.

## What problem this solves

The problem is not distributing our plugins. Composer already does that well for sites built with it. It is that most UCF sites aren't managed with Composer. Plugins get there by hand, so nothing tells anyone when one falls behind. This plugin reads the same Composer repository that Composer uses and turns it into the native WordPress install and update experience.

## Where the catalog comes from

The catalog is any Composer v2 repository. It defaults to the current UCF Satis index, `https://ucf.github.io/ucf-packagist/`. That index holds 79 packages: 59 plugins and 20 themes. When the `ucf` vendor is live on packagist.org, point the plugin there instead:

```php
// wp-config.php
define( 'UCF_PLUGIN_DIRECTORY_REPOSITORY', 'https://repo.packagist.org/' );
```

The two sources are discovered differently, and the plugin handles both. For packagist.org it uses `list.json?vendor=ucf`; for Satis it uses `packages.json` → `available-packages`. After discovery, both paths share everything else: per-package `p2/<vendor>/<package>.json` files, `composer/2.0` minified-format expansion, and `If-Modified-Since` conditional requests. The first fetch of all 79 packages takes about 8 seconds. A refresh where nothing changed takes about 4, because every package answers `304 Not Modified`. The cached catalog is about 60 KB.

## Installation

The first install of this plugin happens before its own folder-renaming code exists, so use one of these routes:

- **Composer:** `composer require ucf/ucf-plugin-directory`. This works once the package is published.
- **WP-CLI from a GitHub zip:** `wp plugin install <zip URL> --slug=ucf-plugin-directory`. The `--slug` flag names the folder. Without it, a GitHub zipball extracts to something like `UCF-UCF-Plugin-Directory-abc1234/`.

The `--slug=<package-name>` trick works for any UCF plugin on a site that doesn't have this plugin installed.

On multisite, network-activate it. The catalog and settings are network-wide, and the settings live under **Network Admin → Settings → UCF Plugin Directory**.

## How installed plugins are matched to packages

**A plugin gets updates from this directory only when we can tell which package it is.** There are two ways:

- **`Update URI` header (preferred, always on).** A plugin that declares `Update URI: https://packagist.org/packages/ucf/<package>` is matched exactly. That header also stops WordPress from ever offering a wordpress.org "update" for a plugin with the same slug. Only vendors on the allowlist (default `ucf`) are answered. A plugin with `…/packages/someone-else/foo` is left to core.
- **Fallback matching (opt-in setting).** No UCF plugin declares `Update URI` today. They all carry `GitHub Plugin URI: UCF/<Repo>`, though. With the setting on, a plugin is matched by that header, or by its folder name when the header is missing (against both the package slug and the GitHub repo name, ignoring case). On a test site with 51 UCF plugins installed by hand in `UCF-*` folders, all 51 matched this way.

Updates always install into the folder the plugin already occupies. A plugin in `UCF-FAQ-CPT/` stays in `UCF-FAQ-CPT/` and stays active. Fresh installs go into `<slug>/`, the same folder `composer/installers` would use, so a site can move between Composer and the dashboard without plugin paths changing.

If a plugin is already installed under a different folder name, the UCF tab shows **Installed** in place of **Install Now**, and a direct install attempt is refused. That way we never end up with two copies.

## Security model

GitHub zipballs carry no checksum (`dist.shasum` is empty), so integrity rests on where the code comes from, checked at three points:

- **The package name.** Only UCF maintainers can publish under `ucf/` on packagist.org, through vendor protection. The Satis index is UCF-owned outright.
- **The download URL.** It must start with `https://api.github.com/repos/UCF/`, `https://codeload.github.com/UCF/`, or `https://github.com/UCF/`. Anything else is refused with a readable error.
- **The package's source repository.** It must be under `https://github.com/UCF/`.

All traffic is HTTPS. Package descriptions are treated as plain text and escaped everywhere.

## GitHub rate limits

The concern here is not GitHub's capacity. It is the limit GitHub puts on its API: without a token, 60 requests an hour per IP address. Most sites using this plugin will be on Pantheon, where outbound traffic comes from shared addresses on Google Cloud, so one developer updating a dozen sites in an afternoon could hit that limit.

The package metadata links to `api.github.com/repos/UCF/<Repo>/zipball/<sha>`, which counts against that limit and then just redirects to `codeload.github.com`. **The plugin skips the API and downloads from `codeload.github.com/UCF/<Repo>/zip/<sha>` directly.** It is the same file from the same place, and codeload sends no rate-limit headers. We checked this on 2026-10-19: an install and an update through the plugin left the API budget at 60 of 60. GitHub likely applies some unpublished abuse protection to codeload too, so this removes the documented limit, not every possible one.

If downloads do get throttled, the failure is safe. WordPress reports that the download failed, the installed plugin is left as it was, and the update can be retried. As a safety valve, a token can be defined in `wp-config.php`:

```php
define( 'UCF_PLUGIN_DIRECTORY_GITHUB_TOKEN', 'github_pat_…' );
```

The token is sent only to the allowlisted UCF GitHub URLs, never to the package repository, and it is never stored in the database. A fine-grained token with read-only access to public repositories is enough. Every site sharing a token also shares its 5,000-an-hour budget, so we should deploy one only if codeload throttling shows up in practice.

GitHub doesn't count zipball or codeload downloads, so we have no download metrics today. The closest proxy is the repository traffic API, which reports git clones by day for the last 14 days. Across all 79 UCF repos it showed 2,055 clones in the two weeks before 2026-10-19, with a busiest day of 306 — mostly automation and development, we believe, not site installs.

## Pantheon and `DISALLOW_FILE_MODS`

When file modifications are disallowed, WordPress itself hides Add New and blocks updates. This plugin then registers no install or update hooks. The diagnostics screen still shows the catalog and says that the site is read-only.

## Repository etiquette

- **Fetches never run on front-end requests.** The catalog refreshes twice a day from WP-Cron, at a random minute (never `:00`), and only on the main site of a network. It also refreshes on demand from **Refresh now**. Admin screens read from the cache, with one exception: a single blocking fetch when the cache is empty.
- **Requests identify us.** The User-Agent is `UCF-Plugin-Directory/<version> (WordPress/<version>; mailto=webcom@ucf.edu)`.
- **Requests are sequential and conditional.** That stays well under Packagist's 10-concurrent-request limit.
- **Only static endpoints are used.** We never call `~dev.json` or the dynamic `packagist.org/packages/<vendor>/<package>.json` endpoint.
- **Failures are soft.** A failed fetch keeps the last good catalog and records the error on the diagnostics screen.

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `ucf_plugin_directory_repository` | `UCF_PLUGIN_DIRECTORY_REPOSITORY` | Composer repository URL |
| `ucf_plugin_directory_vendors` | `['ucf']` | Vendor allowlist |
| `ucf_plugin_directory_excluded_packages` | `['ucf/github-action-testing']` | Packages hidden from the catalog |
| `ucf_plugin_directory_user_agent_contact` | `webcom@ucf.edu` | Contact in the User-Agent |
| `ucf_plugin_directory_allowed_download_hosts` | UCF GitHub URL prefixes | Download allowlist |
| `ucf_plugin_directory_allowed_repositories` | `['https://github.com/UCF/']` | Source repository allowlist |
| `ucf_plugin_directory_download_url` | `dist.url` | Download URL handed to WordPress |

Actions: `ucf_plugin_directory_refreshed` fires after each refresh, and `ucf_plugin_directory_source_selected` fires after a package's folder is renamed.

## Known data issues found during the proof of concept

- **Header versions have drifted from tags in two plugins.** UCF Degree Search's header says 1.0.0, but its newest tag is v0.10.3. UCF Document's header says 0.2.6, but its newest tag is v0.2.5. In both cases the installed copy reports a *newer* version than anything published, so neither will be offered an update until a matching tag exists.
- **Some packages have no stable release.** `ucf/ucf-news-block-theme` has only pre-release tags, so it doesn't appear in the catalog.
- **A package's type can change across releases.** `ucf/main-site-utilities-plugin` was a `wp-cli-package` at 1.0.0 and a `wordpress-plugin` from v3 onward. The type is read from the resolved release, so this works, but it's worth knowing.

## Rollout checklist (work outside this plugin)

1. **Finish the packagist.org migration.** All active UCF packages should be live under `ucf/` with GitHub hooks synced, and obsolete ones marked abandoned. Then set `UCF_PLUGIN_DIRECTORY_REPOSITORY` to `https://repo.packagist.org/` and drop the Satis repository entry from Composer-managed sites.
2. **Add `Update URI: https://packagist.org/packages/ucf/<package>`** to every UCF plugin header, starting with the most widely deployed. This is what turns updates on without the fallback setting.
3. **Fix version drift.** Make sure each plugin's header `Version` matches the tag it ships in (see Known data issues above).
4. **Optionally add `extra.wordpress`** to each package's `composer.json` (`name`, `requires`, `tested`, `requires_php`). The plugin already reads these and shows them on the cards.
5. **Optionally attach a built release zip** with the correct top-level folder name to each GitHub release.
6. **Publish this plugin** as `ucf/ucf-plugin-directory`, so it can be installed once and then keep itself updated through its own `Update URI`.

## Development

```sh
composer install
composer test   # PHPUnit; no database needed (WordPress functions are stubbed)
composer lint   # WordPress Coding Standards + PHP 7.4 compatibility
```

The unit tests run against fixtures in `tests/fixtures/`. These include real Satis `p2` files, a real minified packagist.org `p2` file, and a hand-written minified file that covers inheritance, `__unset`, pre-release, `v`-prefixed, and dev versions. The install and update flows were verified by hand on a local multisite (WordPress 7.1, PHP 8.2, WP-CLI 2.12):

- **Fresh install** lands in the slug folder.
- **The duplicate-install guard** blocks a second copy.
- **Bulk updates** keep both a standard and a hand-named folder, and both plugins stay active.
- **A stale download URL** still updates into the right folder.
- **A non-allowlisted URL** is refused.
- **The fallback setting** gates updates on and off as expected.
