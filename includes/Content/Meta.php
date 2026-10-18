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
 * Ingredient rows carry a `type`. The base knows `plain`; the Pro add-on adds
 * `internal` and `affiliate` through `bfrc_ingredient_types`, and the extra
 * fields those rows carry (`product_id`, `affiliate_url`) through
 * `bfrc_ingredient_row_fields`, each with its own sanitiser.
 */
final class Meta {

	public const MEDIA_TYPE   = '_bfrc_media_type';
	public const VIDEO_URL    = '_bfrc_video_url';
	public const PREP_MINUTES = '_bfrc_prep_minutes';
	public const COOK_MINUTES = '_bfrc_cook_minutes';
	public const SERVINGS_MIN = '_bfrc_servings_min';
	public const SERVINGS_MAX = '_bfrc_servings_max';
	public const FEATURED     = '_bfrc_featured';
	public const INGREDIENTS  = '_bfrc_ingredients';
	public const STEPS        = '_bfrc_steps';
	public const NUTRITION    = '_bfrc_nutrition';

	public const MAX_MINUTES  = 6000;
	public const MAX_SERVINGS = 999;
	public const MAX_ROWS     = 200;
	public const MEASURE_MAX  = 64;
	public const LABEL_MAX    = 255;

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
	 * Every key this class registers.
	 *
	 * @return list<string>
	 */
	public static function keys(): array {
		return [ self::MEDIA_TYPE, self::VIDEO_URL, self::PREP_MINUTES, self::COOK_MINUTES, self::SERVINGS_MIN, self::SERVINGS_MAX, self::FEATURED, self::INGREDIENTS, self::STEPS, self::NUTRITION ];
	}

	/**
	 * Register on init, after the post type.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 7 );
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
	 * `callable( mixed ): mixed` whose result is stored as given.
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
		$this->register_key(
			self::VIDEO_URL,
			'string',
			[
				'type'   => 'string',
				'format' => 'uri',
			],
			[ VideoUrl::class, 'normalise' ],
			'',
			$auth
		);
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
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'properties'           => [
						'measure' => [ 'type' => 'string' ],
						'label'   => [ 'type' => 'string' ],
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
				'type'  => 'array',
				'items' => [
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
			'properties' => array_map( static fn(): array => [ 'type' => [ 'number', 'null' ] ], self::NUTRITION_FIELDS ),
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
	 * Auth callback: may the current user edit this recipe?
	 *
	 * @param bool   $allowed   Core's default.
	 * @param string $meta_key  The key.
	 * @param int    $object_id The recipe.
	 */
	public static function can_edit( bool $allowed, string $meta_key, int $object_id ): bool {
		unset( $allowed, $meta_key );
		return current_user_can( 'edit_post', $object_id );
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
		$types = self::ingredient_types();
		$out   = [];
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = mb_substr( sanitize_text_field( is_scalar( $row['label'] ?? null ) ? (string) $row['label'] : '' ), 0, self::LABEL_MAX );
			if ( '' === $label ) {
				continue;
			}
			$type  = is_string( $row['type'] ?? null ) && in_array( $row['type'], $types, true ) ? $row['type'] : 'plain';
			$clean = [
				'measure' => mb_substr( sanitize_text_field( is_scalar( $row['measure'] ?? null ) ? (string) $row['measure'] : '' ), 0, self::MEASURE_MAX ),
				'label'   => $label,
				'type'    => $type,
			];
			foreach ( self::ingredient_row_fields( $type ) as $field => $sanitiser ) {
				$clean[ $field ] = $sanitiser( $row[ $field ] ?? null );
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
	 * Nutrition: every NUTRITION_FIELDS key, each a non-negative number with
	 * two decimals, or null when empty or not a number (hidden on display).
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
				$v = trim( str_replace( ',', '.', $v ) );
			}
			if ( null === $v || '' === $v || ! is_numeric( $v ) || ! is_finite( (float) $v ) || (float) $v < 0 ) {
				continue;
			}
			$out[ $field ] = round( (float) $v, 2 );
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
	 * A whole number in 0..$max, or 0 for anything that is not one.
	 *
	 * @param mixed $value Raw.
	 * @param int   $max   Upper bound.
	 */
	private static function bounded_int( mixed $value, int $max ): int {
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			return 0;
		}
		return max( 0, min( $max, (int) $value ) );
	}
}
