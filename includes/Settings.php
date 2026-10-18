<?php
/**
 * Plugin settings, merged over defaults at read time.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single `bfrc_settings` option, read once per request.
 *
 * Every reader goes through get() so the defaults live in exactly one place.
 * sanitize() is the only way values enter the option: the settings page and
 * the installer both call it, so an out-of-range value cannot be stored no
 * matter which path wrote it.
 */
final class Settings {

	use Support\WritesOptions;

	public const OPTION = 'bfrc_settings';

	/**
	 * Always-present autoloaded flag (0/1): raised when the archive slug
	 * changes or the plugin is activated, consumed by Installer::maybe_flush_rewrite().
	 */
	public const FLUSH_OPTION = 'bfrc_flush_rewrite';

	/**
	 * The four taxonomies this plugin ships. Codes are the part after `bfrc_`.
	 *
	 * @var list<string>
	 */
	public const SYSTEM_TAXONOMIES = [ 'meal_type', 'diet', 'season', 'cuisine' ];

	/**
	 * A filter-type code: 2–27 characters, so `bfrc_{code}` stays inside the
	 * 32-character taxonomy-name limit core enforces.
	 */
	public const TAXONOMY_CODE_PATTERN = '/^[a-z][a-z0-9_]{1,26}$/';

	/**
	 * Defaults. Keys are the whole vocabulary of the option: sanitize() drops
	 * anything not listed here.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = [
		'enabled'                  => true,
		'rich_snippets'            => true,
		'archive_slug'             => 'recipes',
		'archive_title'            => 'Recipes',
		'per_page'                 => 12,
		'share_enabled'            => true,
		'share_facebook'           => true,
		'share_x'                  => true,
		'share_pinterest'          => true,
		'writers_listing'          => true,
		'grant_core_roles'         => true,
		'taxonomies'               => [
			'meal_type' => [
				'enabled'    => true,
				'sort_order' => 10,
			],
			'diet'      => [
				'enabled'    => true,
				'sort_order' => 20,
			],
			'season'    => [
				'enabled'    => true,
				'sort_order' => 30,
			],
			'cuisine'   => [
				'enabled'    => true,
				'sort_order' => 40,
			],
		],
		'delete_data_on_uninstall' => false,
	];

	/**
	 * Bounds for the integer settings. Values are clamped, not rejected, so a
	 * typo cannot leave the option unsaved.
	 *
	 * @var array<string, array{int, int}>
	 */
	private const BOUNDS = [
		'per_page' => [ 1, 100 ],
	];

	private const SORT_ORDER_BOUNDS = [ 0, 999 ];

	private const SLUG_MAX_LENGTH  = 64;
	private const TITLE_MAX_LENGTH = 120;

	/**
	 * Per-request cache of the merged option.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Whether the last update() reached the database.
	 *
	 * @var bool
	 */
	private bool $last_update_ok = true;

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 */
	public function get( string $key ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	/**
	 * Replace the stored option with a sanitised copy of $values.
	 *
	 * A changed archive slug flags a rewrite flush (FLUSH_OPTION), which
	 * Installer::maybe_flush_rewrite() performs once the post type and
	 * taxonomies are registered. Whether the write reached the database is
	 * kept for last_update_succeeded(): `update_option()` alone cannot tell
	 * a refused write from an unchanged value.
	 *
	 * @param array<string, mixed> $values Raw values, typically from a form.
	 * @return array<string, mixed> What was meant to be stored (the sanitised values).
	 */
	public function update( array $values ): array {
		$previous_slug        = (string) $this->get( 'archive_slug' );
		$clean                = $this->sanitize( $values );
		$this->last_update_ok = $this->write_option( self::OPTION, $clean, true ); // Autoloaded: read on every request.
		$this->cache          = null;
		if ( $this->last_update_ok && $clean['archive_slug'] !== $previous_slug ) {
			Installer::request_rewrite_flush();
		}
		return $clean;
	}

	/**
	 * Did the last update() store its values? True before any update().
	 */
	public function last_update_succeeded(): bool {
		return $this->last_update_ok;
	}

	/**
	 * Coerce raw input into a complete, typed, bounded settings array.
	 *
	 * Unknown keys are dropped. Booleans accept checkbox-style values
	 * ('1', 'on', 'true', true). The archive slug is a non-empty
	 * `sanitize_title()` result or the default; the title a non-empty text
	 * or the default. The taxonomies map always carries the four system
	 * codes, and keeps any other code that matches TAXONOMY_CODE_PATTERN
	 * (the Pro add-on's custom filter types record their switch here).
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		$clean = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				// A missing checkbox is an unticked checkbox; a missing field keeps its default.
				$clean[ $key ] = is_bool( $default ) ? false : $default;
				continue;
			}
			$value = $raw[ $key ];

			if ( is_bool( $default ) ) {
				$clean[ $key ] = self::to_bool( $value );
			} elseif ( is_int( $default ) ) {
				[ $min, $max ] = self::BOUNDS[ $key ] ?? [ 0, PHP_INT_MAX ];
				$clean[ $key ] = max( $min, min( $max, (int) $value ) );
			} elseif ( 'archive_slug' === $key ) {
				$clean[ $key ] = self::sanitize_slug( $value );
			} elseif ( 'taxonomies' === $key ) {
				$clean[ $key ] = self::sanitize_taxonomies( $value );
			} else {
				$text          = sanitize_text_field( is_string( $value ) ? $value : '' );
				$text          = mb_substr( $text, 0, self::TITLE_MAX_LENGTH );
				$clean[ $key ] = '' === $text ? (string) $default : $text;
			}
		}
		return $clean;
	}

	/**
	 * Checkbox-tolerant boolean coercion.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'on', 'true', 'yes' ], true );
		}
		return false;
	}

	/**
	 * The archive slug: `sanitize_title()` of the input, at most
	 * SLUG_MAX_LENGTH characters, never empty (the default stands in).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_slug( mixed $value ): string {
		$slug = is_string( $value ) ? sanitize_title( $value ) : '';
		$slug = substr( $slug, 0, self::SLUG_MAX_LENGTH );
		$slug = trim( $slug, '-' );
		return '' === $slug ? (string) self::DEFAULTS['archive_slug'] : $slug;
	}

	/**
	 * The taxonomies map. The four system codes are always present (a
	 * missing one takes its default); any other key is kept only when it is
	 * a valid code. Each entry is `{ enabled: bool, sort_order: 0..999 }`.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, array{enabled: bool, sort_order: int}>
	 */
	public static function sanitize_taxonomies( mixed $value ): array {
		$raw   = is_array( $value ) ? $value : [];
		$clean = [];
		foreach ( self::DEFAULTS['taxonomies'] as $code => $default ) {
			$clean[ $code ] = self::sanitize_taxonomy_entry( $raw[ $code ] ?? [], $default );
		}
		foreach ( $raw as $code => $entry ) {
			if ( ! is_string( $code ) || isset( $clean[ $code ] ) || 1 !== preg_match( self::TAXONOMY_CODE_PATTERN, $code ) ) {
				continue;
			}
			$clean[ $code ] = self::sanitize_taxonomy_entry(
				$entry,
				[
					'enabled'    => true,
					'sort_order' => 100,
				]
			);
		}
		return $clean;
	}

	/**
	 * One taxonomy switch.
	 *
	 * @param mixed                                 $entry    Raw entry.
	 * @param array{enabled: bool, sort_order: int} $fallback What a missing field takes.
	 * @return array{enabled: bool, sort_order: int}
	 */
	private static function sanitize_taxonomy_entry( mixed $entry, array $fallback ): array {
		$entry         = is_array( $entry ) ? $entry : [];
		[ $min, $max ] = self::SORT_ORDER_BOUNDS;
		return [
			'enabled'    => array_key_exists( 'enabled', $entry ) ? self::to_bool( $entry['enabled'] ) : $fallback['enabled'],
			'sort_order' => array_key_exists( 'sort_order', $entry ) ? max( $min, min( $max, (int) $entry['sort_order'] ) ) : $fallback['sort_order'],
		];
	}
}
