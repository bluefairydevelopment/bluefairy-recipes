<?php
/**
 * The browse taxonomies (filter types).
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Content;

use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Four system taxonomies ship (meal type, diet, season, cuisine). The Pro
 * add-on adds custom filter types through the `bfrc_filter_taxonomies`
 * filter; every definition passes the same validation, is switched and
 * ordered by the `taxonomies` setting, and registers as `bfrc_{code}` against
 * the recipe post type with the plugin's own term capabilities.
 *
 * A taxonomy switched off in settings stays registered for the admin (its
 * terms are data) but is not public, so it has no archive and no filter.
 * The plugin's master `enabled` switch does the same to every taxonomy.
 */
final class Taxonomies {

	public const POST_TYPE = 'bfrc_recipe';
	public const PREFIX    = 'bfrc_';

	/**
	 * The four system filter types and their starter terms.
	 *
	 * @var array<string, array{label: string, singular: string, sort_order: int, terms: list<string>}>
	 */
	public const SYSTEM = [
		'meal_type' => [
			'label'      => 'Meal Types',
			'singular'   => 'Meal Type',
			'sort_order' => 10,
			'terms'      => [ 'Breakfast', 'Lunch', 'Dinner', 'Dessert', 'Snack' ],
		],
		'diet'      => [
			'label'      => 'Diets',
			'singular'   => 'Diet',
			'sort_order' => 20,
			'terms'      => [ 'Vegetarian', 'Vegan', 'Gluten-Free', 'Dairy-Free' ],
		],
		'season'    => [
			'label'      => 'Seasons',
			'singular'   => 'Season',
			'sort_order' => 30,
			'terms'      => [ 'Spring', 'Summer', 'Autumn', 'Winter' ],
		],
		'cuisine'   => [
			'label'      => 'Cuisines',
			'singular'   => 'Cuisine',
			'sort_order' => 40,
			'terms'      => [ 'Italian', 'Mexican', 'Asian', 'American', 'Mediterranean' ],
		],
	];

	/**
	 * The term capabilities every recipe taxonomy uses (Capabilities grants them).
	 *
	 * @var array<string, string>
	 */
	public const CAPABILITIES = [
		'manage_terms' => 'manage_bfrc_terms',
		'edit_terms'   => 'edit_bfrc_terms',
		'delete_terms' => 'delete_bfrc_terms',
		'assign_terms' => 'assign_bfrc_terms',
	];

	/**
	 * Per-request cache of definitions().
	 *
	 * @var array<string, array{code: string, label: string, singular: string, sort_order: int, enabled: bool, system: bool, terms: list<string>}>|null
	 */
	private ?array $definitions = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings The plugin settings (taxonomy switches, archive slug, enabled).
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register on init, early enough for the post type and the rewrite rules.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 5 );
	}

	/**
	 * Every filter type, validated, switched and sorted.
	 *
	 * Starts from SYSTEM, passes through `bfrc_filter_taxonomies`, then applies
	 * the `taxonomies` setting (enabled, sort order). A definition whose code
	 * fails Settings::TAXONOMY_CODE_PATTERN, or that tries to redefine a
	 * system code, is dropped. System codes can be disabled but never removed.
	 *
	 * @return array<string, array{code: string, label: string, singular: string, sort_order: int, enabled: bool, system: bool, terms: list<string>}>
	 */
	public function definitions(): array {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}
		$base = [];
		foreach ( self::SYSTEM as $code => $def ) {
			$base[ $code ] = [
				'code'       => $code,
				'label'      => $def['label'],
				'singular'   => $def['singular'],
				'sort_order' => $def['sort_order'],
				'enabled'    => true,
				'system'     => true,
				'terms'      => $def['terms'],
			];
		}
		/**
		 * The filter types (taxonomies) recipes can be browsed by.
		 *
		 * Add an entry keyed by its code (2–27 characters, `[a-z][a-z0-9_]*`)
		 * with `label`, `singular` and optionally `sort_order`; it registers
		 * as the taxonomy `bfrc_{code}`. The four system codes cannot be
		 * replaced or removed here.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $definitions Keyed by code.
		 */
		$filtered = apply_filters( 'bfrc_filter_taxonomies', $base );
		$all      = $base;
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $code => $def ) {
				if ( ! is_string( $code ) || isset( self::SYSTEM[ $code ] ) || 1 !== preg_match( Settings::TAXONOMY_CODE_PATTERN, $code ) || ! is_array( $def ) ) {
					continue;
				}
				$label = sanitize_text_field( (string) ( $def['label'] ?? '' ) );
				if ( '' === $label ) {
					continue;
				}
				$all[ $code ] = [
					'code'       => $code,
					'label'      => $label,
					'singular'   => sanitize_text_field( (string) ( $def['singular'] ?? $label ) ),
					'sort_order' => (int) ( $def['sort_order'] ?? 100 ),
					'enabled'    => true,
					'system'     => false,
					'terms'      => [],
				];
			}
		}
		$switches = (array) $this->settings->get( 'taxonomies' );
		foreach ( $all as $code => &$def ) {
			if ( isset( $switches[ $code ] ) && is_array( $switches[ $code ] ) ) {
				$def['enabled']    = (bool) ( $switches[ $code ]['enabled'] ?? true );
				$def['sort_order'] = (int) ( $switches[ $code ]['sort_order'] ?? $def['sort_order'] );
			}
		}
		unset( $def );
		uasort( $all, static fn( array $a, array $b ): int => [ $a['sort_order'], $a['code'] ] <=> [ $b['sort_order'], $b['code'] ] );
		$this->definitions = $all;
		return $all;
	}

	/**
	 * The enabled definitions only, in order.
	 *
	 * @return array<string, array{code: string, label: string, singular: string, sort_order: int, enabled: bool, system: bool, terms: list<string>}>
	 */
	public function enabled(): array {
		return array_filter( $this->definitions(), static fn( array $d ): bool => $d['enabled'] );
	}

	/**
	 * The taxonomy name of a code.
	 *
	 * @param string $code A filter-type code.
	 */
	public static function taxonomy_name( string $code ): string {
		return self::PREFIX . $code;
	}

	/**
	 * Register every definition. Public only when both the plugin and the
	 * taxonomy are enabled.
	 */
	public function register(): void {
		$plugin_on = (bool) $this->settings->get( 'enabled' );
		$archive   = (string) $this->settings->get( 'archive_slug' );
		foreach ( $this->definitions() as $code => $def ) {
			$public = $plugin_on && $def['enabled'];
			register_taxonomy(
				self::taxonomy_name( $code ),
				self::POST_TYPE,
				[
					'labels'             => [
						'name'          => $def['label'],
						'singular_name' => $def['singular'],
						'menu_name'     => $def['label'],
					],
					'description'        => '',
					'public'             => $public,
					'publicly_queryable' => $public,
					'show_ui'            => true,
					'show_in_menu'       => true,
					'show_in_nav_menus'  => $public,
					'show_in_rest'       => true,
					'show_admin_column'  => true,
					'show_tagcloud'      => false,
					'hierarchical'       => false,
					'query_var'          => $public ? self::taxonomy_name( $code ) : false,
					'rewrite'            => $public ? [
						'slug'       => $archive . '/' . str_replace( '_', '-', $code ),
						'with_front' => false,
					] : false,
					'capabilities'       => self::CAPABILITIES,
				]
			);
		}
	}

	/**
	 * Seed each system taxonomy's starter terms, once: only a taxonomy with
	 * no terms at all gets them, so a site that deleted or renamed them is
	 * left alone. Idempotent; safe on every activation.
	 *
	 * @return array<string, int> Terms inserted per code (for the caller's log or test).
	 */
	public function ensure_default_terms(): array {
		$inserted = [];
		foreach ( $this->definitions() as $code => $def ) {
			if ( ! $def['system'] || [] === $def['terms'] ) {
				continue;
			}
			$taxonomy = self::taxonomy_name( $code );
			$count    = wp_count_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				]
			);
			if ( is_wp_error( $count ) || (int) $count > 0 ) {
				continue;
			}
			$inserted[ $code ] = 0;
			foreach ( $def['terms'] as $name ) {
				if ( ! is_wp_error( wp_insert_term( $name, $taxonomy ) ) ) {
					++$inserted[ $code ];
				}
			}
		}
		return $inserted;
	}
}
