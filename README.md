# BlueFairy Recipes

Recipes for WordPress: ingredients, numbered steps, timings, servings, nutrition facts, a
photo or a video, writer profiles, four browse filter types, a filterable recipe archive,
Recipe JSON-LD for Google, share buttons, print, and blocks for lists, filters, featured
recipes and single cards. Everything is set up in wp-admin; block themes get editable
templates and classic themes get bundled ones you can override.

BlueFairy Recipes Pro adds visitor reviews with star ratings, recipe submissions with an
approval workflow, WooCommerce shoppable ingredients and related recipes, affiliate
ingredient links and your own filter types.

The feature-by-feature guide is [FEATURES.md](FEATURES.md); what each version changed is in
[CHANGELOG.md](CHANGELOG.md).

## Requirements

- WordPress 6.7 or newer (pretty permalinks recommended; see Plain permalinks below)
- PHP 8.1 or newer

## Installation

1. Upload the plugin folder to `wp-content/plugins/`, or install the zip from
   Plugins → Add New Plugin, and activate it.
2. Open Recipes → Settings. The Data tab can install three sample recipes to look at and
   remove them again.
3. Add a recipe under Recipes → Add New; it appears at `/recipes/` (the archive slug is a
   setting) and on its filter pages. Its writer gets a public page once they are listed
   (Recipe Writers are, by default) and have a published recipe.

## Notes for site owners

- **Multisite:** network-activate the plugin or activate it on each site that uses it.
  Every site, including one created after network activation, is set up on its own (its
  own settings, roles and recipes); uninstalling cleans every site, each by its own Data
  tab setting.
- **Uninstall keeps your recipes** unless "Delete all data on uninstall" is on. With it on,
  a site with thousands of recipes may not finish inside one web request; run
  `wp plugin uninstall bluefairy-recipes` instead, or simply repeat the uninstall (profiles
  are removed first and each site's settings are removed last, so a repeated run makes the
  same choices and picks up where it stopped).
- After an uninstall and reinstall, recipes and writer profiles are intact, but users must
  be given the Recipe Writer role again (uninstall removes the plugin's roles).
- **WooCommerce sites:** WooCommerce keeps users without blog-editing rights out of the
  dashboard; the plugin lets Recipe Writers through so they can write recipes and fill their
  profile. They still see nothing of the shop's admin.
- **Plain permalinks** (no permalink structure) are not recommended: the writer pages then
  live at query-string addresses such as `/?post_type=bfrc_recipe&bfrc_writers=1`.
- **Very long recipes** post many form fields. PHP's `max_input_vars` (usually 1000) allows a
  recipe of 200 ingredients and 200 steps; the editor warns before a recipe outgrows the limit,
  and a panel that was cut off keeps its previous values rather than being emptied.
- On a multisite network, writer profiles are shared across sites (user meta) while the
  chosen profile images are attachments of one site; pick them on the site that hosts them.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpstan analyse --memory-limit=1G
php bin/method-coverage.php
npm install        # once: @wordpress/scripts and jsdom (dev only, not shipped)
npm run build      # src/ → build/ (the blocks' editor scripts, the view module); build/ is committed
npm test           # node --test tests/js/*.test.js
```

`build/` is committed so a checkout works without Node; rebuild and commit it with any change
under `src/`. The release zip ships `build/`, `src/`, `package.json`, `package-lock.json` and
`webpack.config.js` (WordPress.org requires the source of built JavaScript), never
`node_modules/`, `tests/`, `FEATURES.md` or this file; of `docs/` only the features guide
(`docs/BlueFairyRecipesFeaturesGuide.pdf`) ships; `readme.txt` is the plugin directory's readme.

`tests/Unit/DocsTest.php` checks the names, defaults, limits, roles and hooks quoted in
FEATURES.md, README.md and readme.txt against the code, so the documentation cannot drift
from a release unnoticed.

Architecture and extension seams: `docs/ARCHITECTURE.md`.

## Licence

GPL-2.0-or-later. See `LICENSE`.

## Support the free plugin

This plugin is free and always will be. If it saves you time, you can [buy us a coffee on Ko-fi](https://ko-fi.com/bluefairydevelopment) ☕ — it helps keep the free plugins tested and maintained.

Found a bug or have an idea? [Open an issue](../../issues/new/choose). Security problems: see [SECURITY.md](SECURITY.md).

## Releasing: the changelog date

Between releases the newest `CHANGELOG.md` heading reads `## X.Y.Z — RELEASE-DATE`. The date
is set on the day the release is tagged, never guessed in advance:

1. On the tag day, before building the zip: `php bin/release-date.php --set` (today's date;
   `--set=YYYY-MM-DD` for an explicit one). Commit the change.
2. `php bin/release-date.php --check` exits 1 while the placeholder remains; run it before the
   zip is built. The unit suite accepts the placeholder, so this check is the gate.
