# BlueFairy Recipes

Recipes for WordPress. **In development: 1.0.0 is not released.** This file is completed
milestone by milestone (plan: WPMAP-181); every statement in it is checked against the code
before the release tag.

## Requirements

- WordPress 6.7 or newer
- PHP 8.1 or newer

## What is in the tree today (milestone A1)

- Plugin bootstrap, settings model, roles and capabilities, activation, deactivation and
  uninstall. No recipe content model yet.

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
