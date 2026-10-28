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
 * The single `bfrc_settings` option, read once per request through the shared
 * instance `Plugin::service( 'settings' )` (a second instance has its own
 * per-request cache and does not see another's write until it re-reads).
 *
 * Every reader goes through get() so the defaults live in exactly one place,
 * and every read is normalised (typed, bounded), so a value written around
 * this class (`wp option update`, an import) cannot reach a consumer as the
 * wrong type. Two writers:
 *
 * - update( $form ) has FORM semantics: a missing checkbox is an unticked
 *   checkbox. It is for a caller that posts every field (an importer, a
 *   WP-CLI command).
 * - patch( $values ) has MERGE semantics: keys not given keep their stored
 *   value. It is for code that changes some settings without knowing the
 *   others: the settings screen (one tab at a time, building that tab's
 *   booleans itself), the Pro add-on, REST.
 *
 * Both normalise through the same rules, so an out-of-range value cannot be
 * stored whichever path wrote it.
 *
 * @api
 * @since 1.0.0
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
	 * 32-character taxonomy-name limit core enforces. `\z` (not `$`): a
	 * trailing newline must not slip through into a taxonomy name.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public const TAXONOMY_CODE_PATTERN = '/^[a-z][a-z0-9_]{1,26}\z/';

	/**
	 * Custom filter types the map keeps on top of the system four. The map
	 * lives in an autoloaded option, so it is bounded.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public const MAX_CUSTOM_TAXONOMIES = 50;

	/**
	 * Archive slugs that would collide with WordPress's own routes or with
	 * core's reserved query names; a four-digit year is refused too (it is a
	 * date archive). Any of these falls back to the default. File names
	 * (`wp-login.php`, `robots.txt`) need no entry: `sanitize_title()` turns
	 * the dot into a dash, so no slug can ever equal one.
	 *
	 * @var list<string>
	 */
	public const RESERVED_SLUGS = [
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login',
		'feed',
		'rss',
		'rss2',
		'rdf',
		'atom',
		'trackback',
		'page',
		'author',
		'search',
		'embed',
		'comments',
		'attachment',
		'category',
		'tag',
		'type',
		'wp',
	];

	/**
	 * Defaults. Keys are the whole vocabulary of the option: normalisation
	 * drops anything not listed here.
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
	 * typo cannot leave the option unsaved; a non-number takes the default.
	 *
	 * @var array<string, array{int, int}>
	 */
	private const BOUNDS = [
		'per_page' => [ 1, 100 ],
	];

	/**
	 * Highest sort order a filter type's switch keeps (0 is first).
	 *
	 * @api
	 * @since 1.0.0
	 */
	public const SORT_ORDER_MAX = 999;

	private const SORT_ORDER_BOUNDS = [ 0, self::SORT_ORDER_MAX ];

	private const SLUG_MAX_LENGTH  = 64;
	private const TITLE_MAX_LENGTH = 120;

	/**
	 * Per-request cache of the merged, normalised option.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Whether the last write reached the database.
	 *
	 * @var bool
	 */
	private bool $last_update_ok = true;

	/**
	 * All settings, merged over the defaults and normalised.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = $this->normalise( array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : [] ), false );
		}
		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public function get( string $key ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	/**
	 * Replace the stored option with the sanitised form post (form
	 * semantics: an absent checkbox is unticked; an absent field keeps its
	 * default). For code that changes one setting, use patch().
	 *
	 * @param array<string, mixed> $values Raw values from the settings form.
	 * @return array<string, mixed> What was meant to be stored (the sanitised values).
	 */
	public function update( array $values ): array {
		return $this->store( $this->normalise( $values, true ) );
	}

	/**
	 * Change the given settings and keep every other one as stored.
	 *
	 * @param array<string, mixed> $values The keys to change.
	 * @return array<string, mixed> What was meant to be stored.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public function patch( array $values ): array {
		$current = $this->all();
		$merged  = array_merge( $current, $values );
		if ( isset( $values['taxonomies'] ) && is_array( $values['taxonomies'] ) ) {
			// One level down too: patching one taxonomy's switch keeps every other code (the add-on's included) and its other field.
			$merged['taxonomies'] = array_replace_recursive( $current['taxonomies'], $values['taxonomies'] );
		}
		return $this->store( $this->normalise( $merged, false ) );
	}

	/**
	 * Remove one add-on filter type's switch from the taxonomies map, keeping
	 * every other setting and switch as stored. A system code is never
	 * removed (false); a code with no switch is already gone (true).
	 *
	 * @param string $code The filter type's code.
	 * @return bool The switch is gone.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public function remove_taxonomy_switch( string $code ): bool {
		if ( in_array( $code, self::SYSTEM_TAXONOMIES, true ) ) {
			return false;
		}
		$current = $this->all();
		if ( ! array_key_exists( $code, $current['taxonomies'] ) ) {
			return true;
		}
		unset( $current['taxonomies'][ $code ] );
		$this->store( $this->normalise( $current, false ) );
		return $this->last_update_ok;
	}

	/**
	 * Did the last update() or patch() store its values? True before any write.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public function last_update_succeeded(): bool {
		return $this->last_update_ok;
	}

	/**
	 * Coerce a form post into a complete, typed, bounded settings array
	 * (form semantics): what update() stores. Public so an importer or a
	 * WP-CLI command can normalise before it writes.
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		return $this->normalise( $raw, true );
	}

	/**
	 * Write $clean, then apply what a changed value implies: a changed
	 * archive slug asks for a rewrite flush; a flipped `grant_core_roles`
	 * grants or revokes the core roles' recipe capabilities. Both only when
	 * the write reached the database (`update_option()` alone cannot tell a
	 * refused write from an unchanged value).
	 *
	 * @param array<string, mixed> $clean Normalised values.
	 * @return array<string, mixed> $clean
	 */
	private function store( array $clean ): array {
		$previous             = $this->all();
		$this->last_update_ok = $this->write_option( self::OPTION, $clean, true ); // Autoloaded: read on every request.
		$this->cache          = null;
		if ( ! $this->last_update_ok ) {
			return $clean;
		}
		if ( $clean['archive_slug'] !== $previous['archive_slug'] || $clean['enabled'] !== $previous['enabled'] || $clean['taxonomies'] !== $previous['taxonomies'] ) {
			Installer::request_rewrite_flush(); // rules change with the slug and the taxonomy switches, and go entirely when the plugin is disabled.
		}
		$taxonomies = Plugin::service( 'content.taxonomies' );
		if ( $taxonomies instanceof Content\Taxonomies ) {
			$taxonomies->flush(); // definitions read these settings.
		}
		if ( $clean['grant_core_roles'] !== $previous['grant_core_roles'] ) {
			if ( $clean['grant_core_roles'] ) {
				Capabilities::grant_core_roles();
			} else {
				Capabilities::revoke_core_roles();
			}
		}
		return $clean;
	}

	/**
	 * The one set of rules every value passes through.
	 *
	 * Unknown keys are dropped. Booleans accept checkbox-style values ('1',
	 * 'on', 'true', 'yes'). The archive slug is a non-empty, non-reserved
	 * `sanitize_title()` result or the default; the title a non-empty text or
	 * the default. The taxonomies map always carries the four system codes
	 * and keeps up to MAX_CUSTOM_TAXONOMIES other codes that match
	 * TAXONOMY_CODE_PATTERN.
	 *
	 * @param array<string, mixed> $raw                   Raw input.
	 * @param bool                 $missing_bool_is_false Form semantics (an absent checkbox is unticked) when true; otherwise an absent key takes its default.
	 * @return array<string, mixed>
	 */
	private function normalise( array $raw, bool $missing_bool_is_false ): array {
		$clean = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				$clean[ $key ] = is_bool( $default ) && $missing_bool_is_false ? false : $default;
				continue;
			}
			$value = $raw[ $key ];

			if ( is_bool( $default ) ) {
				$clean[ $key ] = self::to_bool( $value );
			} elseif ( is_int( $default ) ) {
				[ $min, $max ] = self::BOUNDS[ $key ] ?? [ 0, PHP_INT_MAX ];
				$clean[ $key ] = is_numeric( $value ) && is_finite( (float) $value ) ? max( $min, min( $max, (int) $value ) ) : $default;
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
	 * SLUG_MAX_LENGTH bytes, never empty, never a reserved route or a
	 * four-digit year (the default stands in).
	 *
	 * Core percent-encodes non-ASCII, and a plugin filtering `sanitize_title`
	 * may keep raw UTF-8 instead, so the length is enforced on the INPUT: raw
	 * characters are dropped from the end until the encoded form fits. That
	 * is a loop over at most SLUG_MAX_LENGTH characters, so it always ends,
	 * and the cut never lands inside `%xx` or inside a multibyte character.
	 * A result that still does not decode to valid UTF-8 (a literal `%ff`
	 * typed by the administrator) is refused rather than stored.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_slug( mixed $value ): string {
		$fallback = (string) self::DEFAULTS['archive_slug'];
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$chars  = mb_str_split( mb_substr( trim( $value ), 0, self::SLUG_MAX_LENGTH ) );
		$slug   = sanitize_title( implode( '', $chars ) );
		$length = strlen( $slug );
		while ( $length > self::SLUG_MAX_LENGTH && [] !== $chars ) {
			array_pop( $chars );
			$slug   = sanitize_title( implode( '', $chars ) );
			$length = strlen( $slug );
		}
		$slug = trim( $slug, '-' );
		if ( '' === $slug || strlen( $slug ) > self::SLUG_MAX_LENGTH ) {
			return $fallback;
		}
		// Every `%` must open a full `%xx` octet, and the octets must decode to UTF-8 (a typed `%ff` or a lone `%` is refused).
		if ( 1 === preg_match( '/%(?![0-9a-f]{2})/i', $slug ) ) {
			return $fallback;
		}
		$decoded = rawurldecode( $slug );
		if ( ! mb_check_encoding( $decoded, 'UTF-8' ) || ! mb_check_encoding( $slug, 'UTF-8' ) ) {
			return $fallback;
		}
		// Octets may spell what sanitize_title() would never let through (a slash, a dot, a space, `<`, a control
		// character, an upper-case letter): decoded, only non-ASCII, digits, lower-case letters, dashes and
		// underscores (core keeps them) may remain.
		if ( 1 === preg_match( '/[^\x80-\xff0-9a-z_\-]/', $decoded ) ) {
			return $fallback;
		}
		foreach ( [ $slug, mb_strtolower( $decoded ) ] as $form ) {
			if ( in_array( $form, self::RESERVED_SLUGS, true ) || 1 === preg_match( '/^\d{4}\z/', $form ) ) {
				return $fallback;
			}
		}
		return $slug;
	}

	/**
	 * The Page that already answers at the archive slug's address, or null.
	 *
	 * A top-level Page (published, draft, private or pending) at the same
	 * path as the archive competes with it for one address, so one of the
	 * two cannot be reached; so does a blog post when the permalink
	 * structure is `/%postname%/`. A post in the trash, or a child Page (its
	 * path has a parent segment), does not count. Used by the settings form (a clashing slug is refused) and
	 * the admin notice (activation on a site where the address is taken).
	 *
	 * @param string $slug A sanitised archive slug.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public static function slug_clash( string $slug ): ?\WP_Post {
		if ( '' === $slug ) {
			return null;
		}
		$types = '/%postname%/' === (string) get_option( 'permalink_structure' ) ? [ 'page', 'post' ] : [ 'page' ];
		foreach ( $types as $type ) {
			$found = get_page_by_path( $slug, OBJECT, $type );
			if ( $found instanceof \WP_Post && 'trash' !== $found->post_status && 0 === (int) $found->post_parent ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * The taxonomies map. The four system codes are always present (a
	 * missing one takes its default); other keys are kept only when they are
	 * valid codes, in input order, up to MAX_CUSTOM_TAXONOMIES. Each entry is
	 * `{ enabled: bool, sort_order: 0..999 }`.
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
		$custom   = 0;
		$reserved = Content\Taxonomies::reserved_codes();
		foreach ( $raw as $code => $entry ) {
			if ( ! is_string( $code ) || isset( $clean[ $code ] ) || in_array( $code, $reserved, true ) || 1 !== preg_match( self::TAXONOMY_CODE_PATTERN, $code ) ) {
				continue; // The same rule Content\Taxonomies applies: a route name never becomes a switch.
			}
			if ( ++$custom > self::MAX_CUSTOM_TAXONOMIES ) {
				break;
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
		$sort          = $entry['sort_order'] ?? null;
		return [
			'enabled'    => array_key_exists( 'enabled', $entry ) ? self::to_bool( $entry['enabled'] ) : $fallback['enabled'],
			'sort_order' => is_numeric( $sort ) ? max( $min, min( $max, (int) $sort ) ) : $fallback['sort_order'],
		];
	}
}
