# BlueFairy Recipes

Recipes for WordPress. **In development: 1.0.0 is not released.** This file is completed
milestone by milestone; every statement in it is checked against the code before the
release tag.

## Requirements

- WordPress 6.7 or newer, with pretty permalinks
- PHP 8.1 or newer

## What is in the tree today (milestones A1–A6)

- Plugin bootstrap, settings model, roles and capabilities, activation, deactivation and
  uninstall.
- The content model: the `bfrc_recipe` post type, the four filter taxonomies with starter
  terms, the registered recipe fields, reserved recipe slugs.
- Admin editing: the recipe edit screen (five panels, repeatable rows, block and classic
  editors), the Recipes list (columns, filters, bulk featured) and the writer profile fields.
- The public pages: the recipe archive with filters, title search and pagination, the
  filter-type pages, recipe pages (photo or video, ingredients, method, nutrition, share and
  print), the writers listing and writer pages; block and classic theme templates.
- Blocks (Recipe List, Recipe Filters, Featured Recipes, Recipe Card) and the shortcodes
  that mirror them (`[bfrc_recipes]`, `[bfrc_featured]`, `[bfrc_writers]`); the public
  `GET /bfrc/v1/recipes` endpoint; filtering a Recipe List without a reload (Interactivity
  API).
- Recipe JSON-LD for Google (validated against `tests/fixtures/recipe-jsonld.schema.json`),
  the Recipes → Settings screen, the opt-in sample recipes, the Pro upsell, the readme's
  External services section, the POT file. A7 completes the documentation.

## Notes for site owners

- **Network activation is not a tested configuration in 1.0.** On a multisite network,
  activate the plugin on each site. Network activation is handled, but nothing in the
  release process exercises it.
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
under `src/`. The release zip ships `build/`, `src/`, `package.json`, `package-lock.json` and `webpack.config.js`
(WordPress.org requires the source of built JavaScript), never `node_modules/`.

Architecture and extension seams: `docs/ARCHITECTURE.md`.

## Licence

GPL-2.0-or-later. See `LICENSE`.
