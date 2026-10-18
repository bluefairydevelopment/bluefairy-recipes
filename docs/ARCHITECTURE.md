# BlueFairy Recipes — architecture and module contracts

Base plugin (`bluefairy-recipes`, namespace `BlueFairy\Recipes`, prefix `bfrc_`).
The Pro add-on (`bluefairy-recipes-pro`, `BlueFairy\RecipesPro`, `bfrcp_`) hooks the
seams listed under **Extension seams** and never ships inside this plugin.

## Fixed conventions

- `declare(strict_types=1);` in every PHP file, directly after the opening tag (in the
  entry file, after the plugin header docblock WordPress requires there). WPCS
  (`phpcs.xml.dist`), PHPStan level 6 (`phpstan.neon`), PHPUnit 10 (`phpunit.xml.dist`).
  Tabs. Yoda conditions. Every function has a docblock with `@param` for every parameter.
- PSR-4 under `includes/`. Instance-based services wired in `Plugin::boot()` and exposed
  through `Plugin::service( $id )`; `Plugin::*`, `Installer::*`, `Capabilities::*` and the
  `Settings::sanitize_*`/`to_bool` helpers are static. `Plugin::reset()` is a test seam
  (`@internal`).
- Option writes whose outcome matters go through the trait
  `Support\WritesOptions::write_option( $option, $value, $autoload ): bool`:
  `update_option()` returns false both for a refused write and for an unchanged value, so on
  false the stored value is read back and compared in its stored form (no row means failed).
- **No custom tables.** Recipes are a post type, browse filters are taxonomies, recipe data
  is registered post meta, writer profiles are user meta. Direct SQL exists only in
  `uninstall.php`, where the post type and taxonomies are not registered.
- Every list query is bounded (`posts_per_page`, `number`, `LIMIT`, keyset paging in
  uninstall).
- Admin: every form handler checks the capability first, then `check_admin_referer(
  'bfrc_<action>' )`. Output escaped at the boundary. Argument-less action callbacks have
  no typed first parameter (WordPress passes `''`); a test checks this by reflection.
- Text domain `bluefairy-recipes`; no `load_plugin_textdomain` (WordPress.org loads
  translations). No `Update URI`, no updater: the free plugin updates from WordPress.org.
  Role display names are stored untranslated, as core stores its own.
- Prefix every global (`bfrc_`), hook (`bfrc_`), option (`bfrc_`), transient (`bfrc_`),
  AJAX action (`bfrc_ajax_`), handle (`bfrc-`), capability (`bfrc_manage_recipes` and the
  `*_bfrc_recipe(s)` set), REST namespace `bfrc/v1`, object-cache group `bfrc`.
- The entry file returns early when `BFRC_VERSION` is already defined (a second copy of
  the plugin must not fatal) and its autoloader validates the class name before it becomes
  a path.

## Boot order

1. `bluefairy-recipes.php` (file scope): constants, autoloader, activation/deactivation
   hooks, `bfrc_plugin()`, `plugins_loaded` → `Plugin::boot()` at priority 10.
2. `Plugin::boot()`: `Installer::maybe_upgrade()`; hooks `Installer::maybe_flush_rewrite`
   on `init` 99; creates `Settings` (service `settings`); **`do_action( 'bfrc_loaded' )`**;
   wires the subsystems (later milestones); `do_action( 'bfrc_booted' )`.
3. Pro hooks `bfrc_loaded` at file scope, so its filters are in place before anything here
   reads them, and sets `bfrc_is_pro` to true from its own boot after `base_ok()`.

Per request, on a provisioned site: two autoloaded option reads in `maybe_upgrade()` and
one autoloaded option read on `init` 99 (the flush flag). Nothing is written.

## Settings

One option, `bfrc_settings` (autoloaded), read through the shared instance
`Plugin::service( 'settings' )`. `Settings::DEFAULTS` is the whole vocabulary (an add-on
keeps its own option; keys outside the vocabulary are dropped). `patch()` merges the
`taxonomies` map one level down too. The settings screen (a later milestone) must save
through `update()`, not through `options.php`, so the side effects below run.
Every read is normalised (typed, bounded, system taxonomies always present), so a value
written around the class cannot reach a consumer as the wrong type. Two writers:
`update()` has form semantics (an absent checkbox is unticked) for the settings screen;
`patch()` has merge semantics for code that changes one key. A changed `archive_slug`
raises the flush flag; a flipped `grant_core_roles` grants or revokes the core roles'
recipe capabilities. Both only when the write reached the database.

The archive slug is `sanitize_title()` of the input, at most 64 bytes cut on a whole
percent-encoded character, never empty, never one of `Settings::RESERVED_SLUGS` (routes
WordPress owns) or a four-digit year. Custom filter-type codes match
`Settings::TAXONOMY_CODE_PATTERN` (`^[a-z][a-z0-9_]{1,26}\z`, so `bfrc_{code}` fits
core's 32-character taxonomy-name limit); at most `MAX_CUSTOM_TAXONOMIES` (50) are kept.

## Data (milestone A1; A2 adds the content model)

| What | Where | Notes |
|---|---|---|
| Settings | option `bfrc_settings` (autoload) | see above |
| Versions | options `bfrc_version`, `bfrc_db_version` (autoload) | `Installer::maybe_upgrade()` compares on every boot |
| Rewrite flag | option `bfrc_flush_rewrite` (autoload, always present, `0`/`1`) | raised by activation and by a changed `archive_slug` (only when the version write succeeded); consumed once on `init` 99. An option rather than a transient: free to read, cannot be evicted or expire |
| Roles | `bfrc_writer`, `bfrc_recipe_manager` | `Capabilities::WRITER_CAPS`, `MANAGER_EXTRA_CAPS`; core roles granted per `core_grants()` while `grant_core_roles` is on (turning it off takes the capabilities back from Authors and Editors; administrators keep everything) |

`Installer::OPTIONS` must equal the option list in `uninstall.php` in both directions, as
must the capability list, the role list and the core-role list (one test enforces all four).

## Activation, deactivation, uninstall

- **Activation** (`Installer::provision_site()`): roles, `sanitize( DEFAULTS )` when no
  settings exist, core-role grants per the setting, versions, flush flag. Idempotent.
  Network activation walks sites in pages of 200; it is handled but not a tested
  configuration in 1.0 (README says so).
- **Deactivation**: lowers the flag and deletes the stored `rewrite_rules`, so core rebuilds
  them on the next request without this plugin's (a flush during deactivation would write
  them back, the post type being still registered). Roles, settings, versions and content
  stay.
- **Uninstall** (`uninstall.php`, standalone): reads every site's choice first, removes the
  writer profiles next (when every site opted in), then cleans each site with its options
  last, so an interrupted run can be repeated. Always removes the listed options, every
  other `bfrc_…` option (core's taxonomy `_children` caches, dynamic transients), the stored
  rewrite rules, the two roles (users keep a role: `subscriber` when it was their only one;
  a pass that makes no progress ends the loop), the recipe capabilities on core roles, and
  the `bfrc` cache group. Only a stored boolean `true` in `delete_data_on_uninstall` opts a
  site in to losing content: recipes (keyset batches of 100, force-deleted through core's
  own routine with cache invalidation suspended and one flush at the end), the terms of
  every `bfrc_*` taxonomy with their meta (only those terms, and only where no other
  taxonomy still uses them), and the writer profile user meta. User meta is shared by a
  whole network, so profiles are removed only when every site opted in (a site that never
  ran the plugin has no settings and counts as "no"). The term loop stops when a batch could
  not be deleted; the recipe loop pages by id. After a reinstall recipes and profiles are
  intact; writers must be given the Recipe Writer role again.
- **Known limit:** a database that refuses the `bfrc_db_version` write makes every request
  re-run `provision_site()` (role caps re-asserted, three option writes attempted); the
  rewrite flush is not repeated. Such a site is broken in ways this plugin cannot fix.

## Extension seams (this list is mirrored in FEATURES.md "Extending")

| Seam | Kind | Since | Purpose |
|---|---|---|---|
| `bfrc_loaded` | action | 1.0.0 | fired before the base binds anything |
| `bfrc_booted` | action | 1.0.0 | fired after every subsystem is wired |
| `bfrc_is_pro` | filter (bool) | 1.0.0 | Pro answers true after `base_ok()`; hides the upsell |

Seams added by later milestones (`bfrc_filter_taxonomies`, `bfrc_ingredient_types`,
`bfrc_ingredient_row_fields`, `bfrc_render_ingredient_actions`, `bfrc_recipe_meta_boxes`,
`bfrc_schema_data`, `bfrc_single_after_steps`, `bfrc_single_after_nutrition`,
`bfrc_single_footer`, `bfrc_settings_tabs`, `bfrc_settings_page_footer`,
`bfrc_settings_saved`, `bfrc_admin_menu_items`, `bfrc_archive_query_args`,
`bfrc_rest_recipe_item`, `bfrc_reserved_slugs`) are appended to this table as they land.

## Tests

`tests/bootstrap.php` is a WordPress stub harness (no database, no WordPress): hooks really
dispatch, options and transients live in arrays, roles and users resolve capabilities as
core does, `$wpdb` is `tests/FakeWpdb.php` with a scripted answer queue. Module stubs go in
`tests/stubs/<module>.php`. Every public and protected method is named in a test under
`tests/Unit/` (`php bin/method-coverage.php`, self-checked with `--self-check`).
`uninstall.php` is executed for real by `UninstallTest`; the entry file by `EntryFileTest`
in a separate PHP process.
