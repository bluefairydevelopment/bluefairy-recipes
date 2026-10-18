# BlueFairy Recipes

Recipes for WordPress. **In development: 1.0.0 is not released.** This file is completed
milestone by milestone; every statement in it is checked against the code before the
release tag.

## Requirements

- WordPress 6.7 or newer
- PHP 8.1 or newer

## What is in the tree today (milestone A1)

- Plugin bootstrap, settings model, roles and capabilities, activation, deactivation and
  uninstall. No recipe content model yet.

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

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpstan analyse --memory-limit=1G
php bin/method-coverage.php
```

Architecture and extension seams: `docs/ARCHITECTURE.md`.

## Licence

GPL-2.0-or-later. See `LICENSE`.
