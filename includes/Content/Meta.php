<?php
/**
 * Registered recipe meta: the recipe data model.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Content;

use BlueFairy\Recipes\Support\VideoUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every recipe field is registered post meta: single, typed for REST with a
 * schema, revisioned (restoring a revision restores the ingredients), writable
 * only by someone who can edit the recipe, and sanitised by one callback per
 * key whichever path writes it (meta box, REST, WP-CLI).
 *
 * The one rule that spans two keys, servings max ≥ min, is owned here too:
 * whichever of the two is written last wins and the other follows, on
 * `added_post_meta` / `updated_post_meta`, so every write path obeys it.
 *
 * Ingredient rows carry a `type`. The base knows `plain`; the Pro add-on adds
 * `internal` and `affiliate` through `bfrc_ingredient_types`, and the extra
 * fields those rows carry (`product_id`, `affiliate_url`) through
 * `bfrc_ingredient_row_fields`, each with its own sanitiser. When the add-on
 * is absent its types read as `plain` and their fields are dropped on the
 * next save.
 */
final class Meta {

	public const MEDIA_TYPE   = '_bfrc_media_type';
	public const VIDEO_URL    = '_bfrc_video_url';
	public const PREP_MINUTES = '_bfrc_prep_minutes';
	public const COOK_MINUTES = '_bfrc_cook_minutes';
	public const SERVINGS_MIN = '_bfrc_servings_min';
	public const SERVINGS_MAX = '_bfrc_servings_max';
	public const FEATURED     = '_bfrc_featured';
	/** The capability that may set Featured. */
	public const CURATE_CAP  = 'edit_others_bfrc_recipes';
	public const INGREDIENTS = '_bfrc_ingredients';
	public const STEPS       = '_bfrc_steps';
	public const NUTRITION   = '_bfrc_nutrition';

	public const MAX_MINUTES   = 6000;
	public const MAX_SERVINGS  = 999;
	public const MAX_ROWS      = 200;
	public const MEASURE_MAX   = 64;
	public const LABEL_MAX     = 255;
	public const MAX_NUTRITION = 100000.0;

	/**
	 * The nutrition fields, in display order, with their unit.
	 *
	 * @var array<string, string>
	 */
	public const NUTRITION_FIELDS = [
		'calories'      => 'kcal',
		'fat'           => 'g',
		'saturated_fat' => 'g',
		'carbohydrates' => 'g',
		'sugar'         => 'g',
		'fiber'         => 'g',
		'protein'       => 'g',
		'sodium'        => 'mg',
	];

	/**
	 * Re-entrancy guard for the servings rule.
	 *
	 * @var bool
	 */
	private static bool $reconciling = false;

	/**
	 * Every key this class registers.
	 *
	 * @return list<string>
	 */
	public static function keys(): array {
		return [ self::MEDIA_TYPE, self::VIDEO_URL, self::PREP_MINUTES, self::COOK_MINUTES, self::SERVINGS_MIN, self::SERVINGS_MAX, self::FEATURED, self::INGREDIENTS, self::STEPS, self::NUTRITION ];
	}

	/**
	 * Register on init, after the post type; keep servings consistent on every write path.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 7 );
		add_action( 'added_post_meta', [ self::class, 'reconcile_servings' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'reconcile_servings' ], 10, 4 );
		add_action( 'save_post_' . PostType::NAME, [ self::class, 'ensure_featured_key' ], 5, 2 );
	}

	/**
	 * Every recipe carries a `_bfrc_featured` row from its first save on any
	 * path (editor, REST, WP-CLI, an importer calling `wp_insert_post`), so the
	 * list's featured sort and filter have a value to look at. Auto-drafts are
	 * skipped (every "Add New" visit creates one).
	 *
	 * @param int      $post_id The recipe.
	 * @param \WP_Post $post    The recipe.
	 */
	public static function ensure_featured_key( int $post_id, \WP_Post $post ): void {
		if ( PostType::NAME !== $post->post_type || 'auto-draft' === $post->post_status || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! metadata_exists( 'post', $post_id, self::FEATURED ) ) {
			add_post_meta( $post_id, self::FEATURED, false, true );
		}
	}

	/**
	 * The ingredient types in force: `plain` plus what the add-on adds.
	 *
	 * @return list<string>
	 */
	public static function ingredient_types(): array {
		/**
		 * The kinds of ingredient row. The base knows `plain`; Pro adds
		 * `internal` (a WooCommerce product) and `affiliate` (an external link).
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $types Type names.
		 */
		$types = apply_filters( 'bfrc_ingredient_types', [ 'plain' ] );
		$out   = [ 'plain' ];
		foreach ( is_array( $types ) ? $types : [] as $type ) {
			if ( is_string( $type ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,31}\z/', $type ) ) {
				$out[] = $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The extra fields a row of $type carries, each with a sanitiser
	 * `callable( mixed ): mixed`; a result that is not a scalar, null or an
	 * array of scalars is dropped.
	 *
	 * @param string $type An ingredient type.
	 * @return array<string, callable>
	 */
	public static function ingredient_row_fields( string $type ): array {
		/**
		 * Extra fields for an ingredient row of this type, keyed by field
		 * name, each a sanitiser callable.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, callable> $fields Field => sanitiser.
		 * @param string                  $type   The row type.
		 */
		$fields = apply_filters( 'bfrc_ingredient_row_fields', [], $type );
		$out    = [];
		foreach ( is_array( $fields ) ? $fields : [] as $name => $sanitiser ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,31}\z/', $name ) && is_callable( $sanitiser ) && ! in_array( $name, [ 'measure', 'label', 'type' ], true ) ) {
				$out[ $name ] = $sanitiser;
			}
		}
		return $out;
	}

	/**
	 * Register every key.
	 */
	public function register(): void {
		$auth = [ self::class, 'can_edit' ];
		$this->register_key(
			self::MEDIA_TYPE,
			'string',
			[
				'type' => 'string',
				'enum' => [ 'image', 'video' ],
			],
			[ self::class, 'sanitize_media_type' ],
			'image',
			$auth
		);
		// No `format: uri`: the sanitiser owns validity, and '' (clear the video) must stay writable.
		$this->register_key( self::VIDEO_URL, 'string', [ 'type' => 'string' ], [ VideoUrl::class, 'normalise' ], '', $auth );
		$this->register_key(
			self::PREP_MINUTES,
			'integer',
			[
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => self::MAX_MINUTES,
			],
			[ self::class, 'sanitize_minutes' ],
			0,
			$auth
		);
		$this->register_key(
			self::COOK_MINUTES,
			'integer',
			[
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => self::MAX_MINUTES,
			],
			[ self::class, 'sanitize_minutes' ],
			0,
			$auth
		);
		$this->register_key(
			self::SERVINGS_MIN,
			'integer',
			[
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => self::MAX_SERVINGS,
			],
			[ self::class, 'sanitize_servings' ],
			0,
			$auth
		);
		$this->register_key(
			self::SERVINGS_MAX,
			'integer',
			[
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => self::MAX_SERVINGS,
			],
			[ self::class, 'sanitize_servings' ],
			0,
			$auth
		);
		$this->register_key( self::FEATURED, 'boolean', [ 'type' => 'boolean' ], [ self::class, 'sanitize_bool' ], false, $auth );
		$this->register_key(
			self::INGREDIENTS,
			'array',
			[
				'type'     => 'array',
				'maxItems' => self::MAX_ROWS,
				'items'    => [
					'type'                 => 'object',
					'properties'           => [
						'measure' => [
							'type'      => 'string',
							'maxLength' => self::MEASURE_MAX,
						],
						'label'   => [
							'type'      => 'string',
							'maxLength' => self::LABEL_MAX,
						],
						'type'    => [ 'type' => 'string' ],
					],
					'additionalProperties' => true,
				],
			],
			[ self::class, 'sanitize_ingredients' ],
			[],
			$auth
		);
		$this->register_key(
			self::STEPS,
			'array',
			[
				'type'     => 'array',
				'maxItems' => self::MAX_ROWS,
				'items'    => [
					'type'       => 'object',
					'properties' => [ 'text' => [ 'type' => 'string' ] ],
				],
			],
			[ self::class, 'sanitize_steps' ],
			[],
			$auth
		);
		$nutrition_schema = [
			'type'       => 'object',
			'properties' => array_map(
				static fn(): array => [
					'type'    => [ 'number', 'null' ],
					'minimum' => 0,
					'maximum' => self::MAX_NUTRITION,
				],
				self::NUTRITION_FIELDS
			),
		];
		$this->register_key( self::NUTRITION, 'object', $nutrition_schema, [ self::class, 'sanitize_nutrition' ], self::empty_nutrition(), $auth );
	}

	/**
	 * One register_post_meta() call.
	 *
	 * @param string               $key      Meta key.
	 * @param string               $type     Meta type for register_post_meta().
	 * @param array<string, mixed> $schema   REST schema.
	 * @param callable             $sanitize Sanitiser.
	 * @param mixed                $fallback Default value.
	 * @param callable             $auth     Auth callback.
	 */
	private function register_key( string $key, string $type, array $schema, callable $sanitize, mixed $fallback, callable $auth ): void {
		register_post_meta(
			PostType::NAME,
			$key,
			[
				'type'              => $type,
				'single'            => true,
				'default'           => $fallback,
				'show_in_rest'      => [ 'schema' => $schema ],
				'revisions_enabled' => true,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => $auth,
			]
		);
	}

	/**
	 * Auth callback: may $user_id (core passes it as the fourth argument;
	 * 0 means the current user) edit this recipe?
	 *
	 * @param bool   $allowed   Core's default.
	 * @param string $meta_key  The key.
	 * @param int    $object_id The recipe.
	 * @param int    $user_id   The user being checked.
	 */
	public static function can_edit( bool $allowed, string $meta_key, int $object_id, int $user_id = 0 ): bool {
		unset( $allowed );
		$can = $user_id > 0 ? user_can( $user_id, 'edit_post', $object_id ) : current_user_can( 'edit_post', $object_id );
		if ( $can && self::FEATURED === $meta_key ) {
			// Featured is curation: Recipe Managers, Editors and Administrators, not the recipe's own writer.
			$can = $user_id > 0 ? user_can( $user_id, self::CURATE_CAP ) : current_user_can( self::CURATE_CAP );
		}
		return $can;
	}

	/**
	 * Servings max ≥ min, on whichever key was just written: a min above the
	 * stored max raises max to it; a max below the stored min lowers min to it.
	 * A max of 0 means "not set" and is left alone.
	 *
	 * @param int    $meta_id   Ignored.
	 * @param int    $object_id The post.
	 * @param string $meta_key  The key written.
	 * @param mixed  $value     The value written.
	 */
	public static function reconcile_servings( int $meta_id, int $object_id, string $meta_key, mixed $value ): void {
		unset( $meta_id );
		if ( self::$reconciling || ! in_array( $meta_key, [ self::SERVINGS_MIN, self::SERVINGS_MAX ], true ) || PostType::NAME !== get_post_type( $object_id ) ) {
			return;
		}
		$written = self::sanitize_servings( $value );
		$other   = self::SERVINGS_MIN === $meta_key ? self::SERVINGS_MAX : self::SERVINGS_MIN;
		$current = self::sanitize_servings( get_post_meta( $object_id, $other, true ) );
		$fix     = null;
		if ( self::SERVINGS_MIN === $meta_key && $current > 0 && $written > $current ) {
			$fix = $written; // max follows min up.
		} elseif ( self::SERVINGS_MAX === $meta_key && $written > 0 && $current > $written ) {
			$fix = $written; // min follows max down.
		}
		if ( null === $fix ) {
			return;
		}
		self::$reconciling = true;
		try {
			update_post_meta( $object_id, $other, $fix );
		} finally {
			self::$reconciling = false;
		}
	}

	/**
	 * `image` or `video`.
	 *
	 * @param mixed $value Raw.
	 */
	public static function sanitize_media_type( mixed $value ): string {
		return 'video' === $value ? 'video' : 'image';
	}

	/**
	 * Whole minutes, 0..MAX_MINUTES; anything else is 0 (unset).
	 *
	 * @param mixed $value Raw.
	 */
	public static function sanitize_minutes( mixed $value ): int {
		return self::bounded_int( $value, self::MAX_MINUTES );
	}

	/**
	 * Servings, 0..MAX_SERVINGS; anything else is 0 (unset).
	 *
	 * @param mixed $value Raw.
	 */
	public static function sanitize_servings( mixed $value ): int {
		return self::bounded_int( $value, self::MAX_SERVINGS );
	}

	/**
	 * Checkbox-tolerant boolean.
	 *
	 * @param mixed $value Raw.
	 */
	public static function sanitize_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return is_string( $value ) && in_array( strtolower( trim( $value ) ), [ '1', 'on', 'true', 'yes' ], true );
	}

	/**
	 * Ingredient rows: `{ measure, label, type, …extra }`, in order, at most
	 * MAX_ROWS; a row without a label is dropped; an unknown type becomes
	 * `plain`; extra fields come from the type's row-field sanitisers and
	 * nothing else survives.
	 *
	 * @param mixed $value Raw.
	 * @return list<array<string, mixed>>
	 */
	public static function sanitize_ingredients( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$types  = self::ingredient_types();
		$fields = []; // row-field sanitisers per type, asked for once per save.
		$out    = [];
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = mb_substr( sanitize_text_field( is_scalar( $row['label'] ?? null ) ? (string) $row['label'] : '' ), 0, self::LABEL_MAX );
			if ( '' === $label ) {
				continue;
			}
			$type              = is_string( $row['type'] ?? null ) && in_array( $row['type'], $types, true ) ? $row['type'] : 'plain';
			$clean             = [
				'measure' => mb_substr( sanitize_text_field( is_scalar( $row['measure'] ?? null ) ? (string) $row['measure'] : '' ), 0, self::MEASURE_MAX ),
				'label'   => $label,
				'type'    => $type,
			];
			$fields[ $type ] ??= self::ingredient_row_fields( $type );
			foreach ( $fields[ $type ] as $field => $sanitiser ) {
				$result = $sanitiser( $row[ $field ] ?? null );
				if ( null === $result || is_scalar( $result ) || ( is_array( $result ) && [] === array_filter( $result, static fn( mixed $v ): bool => ! is_scalar( $v ) && null !== $v ) ) ) {
					$clean[ $field ] = $result;
				}
			}
			$out[] = $clean;
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Steps: `{ text }` with the post-safe HTML a step may carry, in order,
	 * at most MAX_ROWS; an empty step is dropped.
	 *
	 * @param mixed $value Raw.
	 * @return list<array{text: string}>
	 */
	public static function sanitize_steps( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $row ) {
			$raw = is_array( $row ) ? ( $row['text'] ?? '' ) : $row;
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$text = trim( wp_kses_post( (string) $raw ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = [ 'text' => $text ];
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Nutrition: every NUTRITION_FIELDS key, each a number in 0..MAX_NUTRITION
	 * with two decimals, or null when empty, not a number or ambiguous
	 * (hidden on display). A single comma with no dot is a decimal comma
	 * (`12,5`), except a comma followed by exactly three digits (`1,200`),
	 * which could be a thousands separator and is refused rather than guessed.
	 *
	 * @param mixed $value Raw.
	 * @return array<string, float|null>
	 */
	public static function sanitize_nutrition( mixed $value ): array {
		$raw = is_array( $value ) ? $value : [];
		$out = self::empty_nutrition();
		foreach ( array_keys( self::NUTRITION_FIELDS ) as $field ) {
			$v = $raw[ $field ] ?? null;
			if ( is_string( $v ) ) {
				$v = trim( $v );
				if ( 1 === substr_count( $v, ',' ) && ! str_contains( $v, '.' ) ) {
					if ( 1 === preg_match( '/^(?!0,)\d*,\d{3}$/', $v ) ) {
						continue; // Ambiguous: 1,200 could be twelve hundred or one point two (0,500 can only be a half).
					}
					$v = str_replace( ',', '.', $v );
				}
			}
			if ( null === $v || '' === $v || is_bool( $v ) || ! is_numeric( $v ) || ! is_finite( (float) $v ) ) {
				continue;
			}
			$number = round( (float) $v, 2 );
			if ( $number < 0 || $number > self::MAX_NUTRITION ) {
				continue;
			}
			$out[ $field ] = 0.0 === $number ? 0.0 : $number; // never -0.0.
		}
		return $out;
	}

	/**
	 * Every nutrition field set to null.
	 *
	 * @return array<string, null>
	 */
	public static function empty_nutrition(): array {
		return array_fill_keys( array_keys( self::NUTRITION_FIELDS ), null );
	}

	/**
	 * A whole number in 0..$max, or 0 for anything that is not one. The float
	 * is clamped before the cast, so a huge value cannot raise a warning.
	 *
	 * @param mixed $value Raw.
	 * @param int   $max   Upper bound.
	 */
	private static function bounded_int( mixed $value, int $max ): int {
		if ( is_bool( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			return 0;
		}
		return (int) max( 0.0, min( (float) $max, floor( (float) $value ) ) );
	}
}
