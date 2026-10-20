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
 * schema, revisioned (restoring a revision restores the ingredients) except
 * Featured, writable only by someone who can edit the recipe (Featured: by a
 * curator, see can_curate()), and sanitised by one callback per key whichever
 * path writes it (meta box, REST, WP-CLI).
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

	/**
	 * Did this request prepare a password-protected recipe's REST item? (see no_store_when_protected())
	 *
	 * @var bool
	 */
	private static bool $saw_protected = false;
	/**
	 * How many REST dispatches are in flight (a route may call rest_do_request()).
	 *
	 * @var int
	 */
	private static int $dispatch_depth = 0;

	/**
	 * Every recipe field key starts with this.
	 */
	public const PREFIX = '_bfrc_';

	public const MEDIA_TYPE   = '_bfrc_media_type';
	public const VIDEO_URL    = '_bfrc_video_url';
	public const PREP_MINUTES = '_bfrc_prep_minutes';
	public const COOK_MINUTES = '_bfrc_cook_minutes';
	public const SERVINGS_MIN = '_bfrc_servings_min';
	public const SERVINGS_MAX = '_bfrc_servings_max';
	public const FEATURED     = '_bfrc_featured';
	/** The capability that may set Featured. */
	public const CURATE_CAP = 'edit_others_bfrc_recipes';
	/** Object-cache key of the featured id set. */
	public const FEATURED_IDS_CACHE = 'featured_ids';
	public const INGREDIENTS        = '_bfrc_ingredients';
	public const STEPS              = '_bfrc_steps';
	public const NUTRITION          = '_bfrc_nutrition';

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
	 * The registered default of every key (what REST returns for a recipe
	 * that never had the key, and what a locked recipe's REST item shows).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			self::MEDIA_TYPE   => 'image',
			self::VIDEO_URL    => '',
			self::PREP_MINUTES => 0,
			self::COOK_MINUTES => 0,
			self::SERVINGS_MIN => 0,
			self::SERVINGS_MAX => 0,
			self::FEATURED     => false,
			self::INGREDIENTS  => [],
			self::STEPS        => [],
			self::NUTRITION    => self::empty_nutrition(),
		];
	}

	/**
	 * Register on init, after the post type; keep servings consistent on every write path;
	 * keep a password-protected recipe's fields out of its REST item.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 7 );
		add_filter( 'rest_prepare_' . PostType::NAME, [ self::class, 'hide_protected_meta' ], 10, 3 );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'forget_protected' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'no_store_when_protected' ], 10, 3 );
		add_filter( 'bfag_cache_bypass', [ self::class, 'bypass_rest_cache' ], 10, 2 );
		add_action( 'added_post_meta', [ self::class, 'reconcile_servings' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'reconcile_servings' ], 10, 4 );
		add_action( 'added_post_meta', [ self::class, 'flush_featured_ids' ], 10, 3 );
		add_action( 'updated_post_meta', [ self::class, 'flush_featured_ids' ], 10, 3 );
		add_action( 'deleted_post_meta', [ self::class, 'flush_featured_ids' ], 10, 3 );
	}

	/**
	 * May the current user curate (set Featured)? Recipe Managers,
	 * Administrators, and Editors while the core-role grant is on, hold
	 * `edit_others_bfrc_recipes`; a Writer does not.
	 */
	public static function can_curate(): bool {
		return current_user_can( self::CURATE_CAP );
	}

	/**
	 * Ids of every recipe marked featured, any status: one indexed postmeta
	 * lookup, cached for the request (and in a persistent object cache until
	 * a featured value changes). The list's featured filter and sort, and the
	 * front end's featured block, work from this set, so no query ever joins
	 * postmeta for the key.
	 *
	 * @return array<int, int>
	 */
	public static function featured_ids(): array {
		$cached = wp_cache_get( self::FEATURED_IDS_CACHE, 'bfrc' );
		if ( is_array( $cached ) ) {
			return array_values( array_map( 'intval', $cached ) );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the one indexed lookup every featured list query works from; cached just below and flushed on any featured write.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = '1' AND p.post_type = %s",
				self::FEATURED,
				PostType::NAME
			)
		);
		$ids = array_values( array_map( 'intval', is_array( $ids ) ? $ids : [] ) );
		wp_cache_set( self::FEATURED_IDS_CACHE, $ids, 'bfrc' );
		return $ids;
	}

	/**
	 * A featured value changed: forget the cached id set.
	 *
	 * @param int|array<int> $meta_ids  Ignored.
	 * @param int            $object_id Ignored.
	 * @param string         $meta_key  The key written.
	 */
	public static function flush_featured_ids( int|array $meta_ids, int $object_id, string $meta_key ): void {
		unset( $meta_ids, $object_id );
		if ( self::FEATURED === $meta_key ) {
			wp_cache_delete( self::FEATURED_IDS_CACHE, 'bfrc' );
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
		// Not revisioned: core's revision restore copies meta without the auth callback, so a writer could re-apply or wipe a curator's flag.
		$this->register_key( self::FEATURED, 'boolean', [ 'type' => 'boolean' ], [ self::class, 'sanitize_bool' ], false, $auth, false );
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
	 * @param bool                 $revisions Whether revisions keep and restore the key.
	 */
	private function register_key( string $key, string $type, array $schema, callable $sanitize, mixed $fallback, callable $auth, bool $revisions = true ): void {
		register_post_meta(
			PostType::NAME,
			$key,
			[
				'type'              => $type,
				'single'            => true,
				'default'           => $fallback,
				'show_in_rest'      => [ 'schema' => $schema ],
				'revisions_enabled' => $revisions,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => $auth,
			]
		);
	}

	/**
	 * `rest_request_after_callbacks`: a REST response that must not be
	 * served to anyone else carries `Cache-Control: no-store`: one that
	 * prepared a password-protected recipe (a collection keeps only an
	 * embedded item's data, not its headers), one whose search results name
	 * one, and **any response to a caller holding an unlock cookie**, since
	 * what they see of a locked recipe can appear in another post's body
	 * (a Query Loop with a Post Content block) with no protected item in the
	 * response at all. This hook runs inside dispatch, before any
	 * `rest_post_dispatch` consumer (the BlueFairy API Gateway stores there).
	 *
	 * @param mixed            $response The response the route produced.
	 * @param mixed            $handler  The route handler (core passes an array).
	 * @param \WP_REST_Request $request  The request.
	 */
	public static function no_store_when_protected( mixed $response, mixed $handler, \WP_REST_Request $request ): mixed {
		unset( $handler, $request );
		self::$dispatch_depth = max( 0, self::$dispatch_depth - 1 ); // Every before_callbacks has its after_callbacks, error responses included: balance first.
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		$data = $response instanceof \WP_HTTP_Response ? $response->get_data() : $response; // WP_REST_Response or a bare WP_HTTP_Response (core wraps that one too).
		if ( self::lists_protected_recipe( $data ) ) {
			self::$saw_protected = true; // A search naming a locked recipe: the request-level bypass must say so, not only the header.
		}
		if ( self::$saw_protected || self::caller_has_unlock_cookie() ) {
			$response = rest_ensure_response( $response ); // A route may return a plain array; core wraps it two lines later, so this is the same object core serves.
			if ( $response instanceof \WP_REST_Response ) {
				$response->header( 'Cache-Control', 'no-store' );
			}
		}
		return $response; // The flag is kept until the next outermost dispatch starts (forget_protected()): a REST cache deciding at rest_post_dispatch must still see it.
	}

	/**
	 * `rest_request_before_callbacks`: a fresh dispatch, a fresh answer to
	 * "did this request prepare a locked recipe?". Reset here rather than
	 * after the callbacks so that a cache consulting the flag at
	 * `rest_post_dispatch` (see bypass_rest_cache()) still sees it, and so
	 * that a nested dispatch inside a route cannot wipe the outer one's.
	 *
	 * @param mixed            $response Core's pre-callback value (null, or a short-circuit).
	 * @param mixed            $handler  The route handler.
	 * @param \WP_REST_Request $request  The request.
	 */
	public static function forget_protected( mixed $response, mixed $handler, \WP_REST_Request $request ): mixed {
		unset( $handler, $request );
		if ( 0 === self::$dispatch_depth ) {
			self::$saw_protected = false; // Only the outermost dispatch starts clean: a route composing items with rest_do_request() keeps what its inner dispatches prepared.
		}
		++self::$dispatch_depth;
		return $response;
	}

	/**
	 * `bfag_cache_bypass` (the BlueFairy API Gateway's REST cache): never
	 * store or serve a stored response for a request that prepared a locked
	 * recipe or comes from a caller holding an unlock cookie. The header
	 * says the same, but a gateway cache rule may set its own
	 * `Cache-Control` over it before the gateway reads it; a request-level
	 * answer cannot be overwritten by a header.
	 *
	 * @param mixed $bypass The decision so far.
	 * @param mixed $request The request.
	 */
	public static function bypass_rest_cache( mixed $bypass, mixed $request ): bool {
		unset( $request );
		return (bool) $bypass || self::$saw_protected || self::caller_has_unlock_cookie();
	}

	/**
	 * Does the caller hold a `wp-postpass_*` cookie? Then any recipe they
	 * read may be unlocked for them alone.
	 */
	public static function caller_has_unlock_cookie(): bool {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( is_string( $name ) && str_starts_with( $name, 'wp-postpass_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does a search result list a password-protected recipe? `/wp/v2/search`
	 * embeds the items only while serving, after the headers went out, so
	 * the decision is taken from the ids it returns.
	 *
	 * @param mixed $data The response data.
	 */
	private static function lists_protected_recipe( mixed $data ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( $data as $row ) {
			if ( is_array( $row ) && 'post' === ( $row['type'] ?? '' ) && PostType::NAME === ( $row['subtype'] ?? '' ) ) {
				$post = get_post( (int) ( $row['id'] ?? 0 ) );
				if ( $post instanceof \WP_Post && '' !== (string) $post->post_password ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * `rest_prepare_bfrc_recipe`: a password-protected recipe's REST item
	 * shows every field at its default until the caller may read the post:
	 * the recipe is unlocked for them (`post_password_required()`, which
	 * honours the unlock cookie; core's controller has removed its own
	 * password filter by the time this runs), they may edit it, or they sent
	 * the right `password` parameter, as core does for `content`. The page
	 * shows the password form and nothing else; the API must not hand out
	 * what the page withholds.
	 *
	 * @param \WP_REST_Response $response The item.
	 * @param \WP_Post          $post     The recipe.
	 * @param \WP_REST_Request  $request  The request.
	 */
	public static function hide_protected_meta( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ): \WP_REST_Response {
		if ( '' !== (string) $post->post_password ) {
			// Whether this item comes out locked or unlocked depends on the caller's cookie or password:
			// no shared cache may store it for the next caller. Core drops an item's headers when it
			// embeds the item in a collection, so the flag carries the header to the final response.
			$response->header( 'Cache-Control', 'no-store' );
			self::$saw_protected = true;
		}
		if ( ! post_password_required( $post ) || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$password = $request['password'] ?? null;
		if ( is_string( $password ) && '' !== $password && hash_equals( (string) $post->post_password, $password ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}
		foreach ( self::defaults() as $key => $fallback ) {
			if ( array_key_exists( $key, $data['meta'] ) ) {
				$data['meta'][ $key ] = $fallback;
			}
		}
		$response->set_data( $data );
		return $response;
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
			// Featured is curation (see can_curate()), not the recipe's own writer's call.
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
