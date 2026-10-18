<?php
/**
 * A planted file OUTSIDE includes/. If the entry file's autoloader ever
 * resolves a class name carrying `..` to this path, loading it sets a marker
 * the probe reports, and EntryFileTest goes red. Must never run in WordPress.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile -- test fixture, not shipped.

$GLOBALS['bfrc_planted_loaded'] = ( $GLOBALS['bfrc_planted_loaded'] ?? 0 ) + 1;
