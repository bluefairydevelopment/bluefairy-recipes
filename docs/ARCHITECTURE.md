# BlueFairy Recipes — architecture and module contracts

Base plugin (`bluefairy-recipes`, namespace `BlueFairy\Recipes`, prefix `bfrc_`).
The Pro add-on (`bluefairy-recipes-pro`, `BlueFairy\RecipesPro`, `bfrcp_`) hooks the
seams listed under **Extension seams** and never ships inside this plugin.

Plan: `~/Sites/blueSites/plans/bluefairy-recipes.plan.md` (approved 2026-10-18; Jira epic WPMAP-181).

## Fixed conventions

- `declare(strict_types=1);` on line 2 of every PHP file. WPCS (`phpcs.xml.dist`), PHPStan
  level 6 (`phpstan.neon`), PHPUnit 10 (`phpunit.xml.dist`). Tabs. Yoda conditions.
  Every function has a docblock with `@param` for every parameter.
- PSR-4 under `includes/`. Instance-based services wired in `Plugin::boot()` and exposed
  through `Plugin::service( $id )`; `Plugin::*`, `Installer::*`, `Capabilities::*` and the
  `Settings::sanitize_*`/`to_bool` helpers are static.
- Option writes whose outcome matters go through the trait
  `Support\WritesOptions::write_option( $option, $value, $autoload ): bool`:
  `update_option()` returns false both for a refused write and for an unchanged value, so on
  false the stored value is read back and compared in its stored form.
- **No custom tables.** Recipes are a post type, browse filters are taxonomies, recipe data
  is registered post meta, writer profiles are user meta. Direct SQL exists only in
  `uninstall.php`, where the post type and taxonomies are not registered.
- Every list query is bounded (`posts_per_page`, `number`, `LIMIT`).
- Admin: every form handler checks the capability first, then `check_admin_referer(
  'bfrc_<action>' )`. Output escaped at the boundary. Argument-less action callbacks have
  no typed first parameter (WordPress passes `''`).
- Text domain `bluefairy-recipes`; no `load_plugin_textdomain` (WordPress.org loads
  translations). No `Update URI`, no updater: the free plugin updates from WordPress.org.
- Prefix every global (`bfrc_`), hook (`bfrc_`), option (`bfrc_`), transient (`bfrc_`),
  AJAX action (`bfrc_ajax_`), handle (`bfrc-`), capability (`bfrc_manage_recipes` and the
  `*_bfrc_recipe(s)` set), REST namespace `bfrc/v1`, object-cache group `bfrc`.

## Boot order

1. `bluefairy-recipes.php` (file scope): constants, autoloader, activation/deactivation
   hooks, `bfrc_plugin()`, `plugins_loaded` → `Plugin::boot()` at priority 10.
2. `Plugin::boot()`: `Installer::maybe_upgrade()`; hooks `Installer::maybe_flush_rewrite`
   on `init` 99; creates `Settings` (service `settings`); **`do_action( 'bfrc_loaded' )`**;
   wires the subsystems (milestones A2+); `do_action( 'bfrc_booted' )`.
3. Pro hooks `bfrc_loaded` at file scope, so its filters are in place before anything here
   reads them, and sets `bfrc_is_pro` to true from its own boot after `base_ok()`.

## Data (milestone A1; A2 adds the content model)

| What | Where | Notes |
|---|---|---|
| Settings | option `bfrc_settings` (autoload) | `Settings::DEFAULTS` is the whole vocabulary; `Settings::sanitize()` the only writer |
| Versions | options `bfrc_version`, `bfrc_db_version` (autoload) | `Installer::maybe_upgrade()` compares on every boot |
| Rewrite flag | transient `bfrc_flush_rewrite` | set by activation and by a changed `archive_slug`; consumed once on `init` 99 |
| Roles | `bfrc_writer`, `bfrc_recipe_manager` | `Capabilities::WRITER_CAPS`, `MANAGER_EXTRA_CAPS`; core roles granted per `core_grants()` while `grant_core_roles` is on; administrators always hold `bfrc_manage_recipes` |

`Installer::OPTIONS` and `Installer::TRANSIENTS` must equal the lists in `uninstall.php`
in both directions (a test enforces it).

## Uninstall

Delete-data OFF (default): options, transients, the two roles (their users keep a role;
`subscriber` when it was their only one), the recipe capabilities on core roles, the
`bfrc` cache group. Content, meta, terms and writer profiles stay, so a reinstall restores
everything. Delete-data ON: additionally recipes (batches of 100, force-deleted), every
`bfrc_*` taxonomy's terms, and the writer profile user meta.

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
dispatch, options and transients live in arrays, `$wpdb` is `tests/FakeWpdb.php` with a
scripted answer queue. Module stubs go in `tests/stubs/<module>.php`. Every public and
protected method is named in a test (`php bin/method-coverage.php`, self-checked with
`--self-check`). `uninstall.php` is executed for real by `UninstallTest`.
