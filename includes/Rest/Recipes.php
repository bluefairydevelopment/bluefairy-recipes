<?php
/**
 * `GET /bfrc/v1/recipes`: the public, read-only, bounded recipe list the
 * Recipe Filters block reads to update a list without a reload.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Rest;

use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\RecipeData;
use BlueFairy\Recipes\Front\RecipeQuery;
use BlueFairy\Recipes\Front\Renderer;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Arguments: `s` (≤ 100 characters), `filter[{code}]` for each enabled
 * filter type (any other key is a 400: `filter[page]` is not a code),
 * `page` (1..1000) and `per_page` (1..100; 101 is a 400). Every argument is
 * validated here as well as by the schema, so the bounds hold on a site
 * whose REST server skipped schema validation.
 *
 * Each item carries `id`, `title`, `url`, `thumb`, `writer`, `times`,
 * `excerpt` and `html` (the card as the archive renders it, from the same
 * Renderer, so the client never builds card markup of its own). A
 * password-protected recipe's item is its locked card, whoever asks: the
 * response does not vary by cookie and is cacheable by anyone (`public,
 * max-age=60` for anonymous callers; logged-in callers get core's no-cache
 * headers). While the plugin is disabled the list is `[]`.
 */
final class Recipes {

	/**
	 * Most recipes the list pages past: page × per_page past it would make
	 * MySQL walk every row before the OFFSET (page 1000 of 100 is 99,900).
	 */
	public const MAX_OFFSET = 10000;

	public const REST_NAMESPACE = 'bfrc/v1';
	public const ROUTE          = '/recipes';
	public const MAX_AGE        = 60;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings   Settings.
	 * @param Taxonomies  $taxonomies Filter types.
	 * @param RecipeQuery $query      The list query.
	 * @param Renderer    $renderer   The card renderer.
	 */
	public function __construct( private Settings $settings, private Taxonomies $taxonomies, private RecipeQuery $query, private Renderer $renderer ) {}

	/**
	 * Hook registration.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * `rest_api_init`.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					// A public read of published recipes, the same set the archive page shows anyone;
					// every argument is bounded, a locked recipe shows its locked card only.
					'permission_callback' => '__return_true',
					'args'                => $this->args(),
				],
				'schema' => [ $this, 'item_schema' ],
			]
		);
	}

	/**
	 * The argument schema, with explicit validators.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function args(): array {
		$codes = [];
		foreach ( array_keys( $this->taxonomies->enabled() ) as $code ) {
			$codes[ $code ] = [
				'type'        => 'string',
				'description' => __( 'A term slug of this filter type.', 'bluefairy-recipes' ),
			];
		}
		return [
			's'        => [
				'type'              => 'string',
				'description'       => __( 'Title keyword, up to 100 characters.', 'bluefairy-recipes' ),
				'default'           => '',
				'maxLength'         => RecipeQuery::SEARCH_MAX,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => [ $this, 'validate_search' ],
			],
			'page'     => [
				'type'              => 'integer',
				'description'       => __( 'Page of results.', 'bluefairy-recipes' ),
				'default'           => 1,
				'minimum'           => 1,
				'maximum'           => RecipeQuery::MAX_PAGE,
				'validate_callback' => [ $this, 'validate_page' ],
			],
			'per_page' => [
				'type'              => 'integer',
				'description'       => __( 'Recipes per page, 1 to 100.', 'bluefairy-recipes' ),
				'default'           => $this->query->normalize( [] )['per_page'],
				'minimum'           => 1,
				'maximum'           => RecipeQuery::MAX_PER_PAGE,
				'validate_callback' => [ $this, 'validate_per_page' ],
			],
			'filter'   => [
				'type'                 => 'object',
				'description'          => __( 'One term slug per filter type code.', 'bluefairy-recipes' ),
				'default'              => [],
				'properties'           => $codes,
				'additionalProperties' => false,
				'validate_callback'    => [ $this, 'validate_filter' ],
			],
		];
	}

	/**
	 * `s`: a string of at most SEARCH_MAX characters.
	 *
	 * @param mixed $value The value.
	 */
	public function validate_search( mixed $value ): bool|\WP_Error {
		if ( ! is_string( $value ) || mb_strlen( $value ) > RecipeQuery::SEARCH_MAX ) {
			/* translators: %d: the maximum length */
			return self::invalid( 's', sprintf( __( 'The keyword must be text of at most %d characters.', 'bluefairy-recipes' ), RecipeQuery::SEARCH_MAX ) );
		}
		return true;
	}

	/**
	 * `page`: an integer 1..MAX_PAGE.
	 *
	 * @param mixed $value The value.
	 */
	public function validate_page( mixed $value ): bool|\WP_Error {
		if ( ! self::is_int_between( $value, 1, RecipeQuery::MAX_PAGE ) ) {
			/* translators: %d: the deepest page */
			return self::invalid( 'page', sprintf( __( 'page must be a whole number from 1 to %d.', 'bluefairy-recipes' ), RecipeQuery::MAX_PAGE ) );
		}
		return true;
	}

	/**
	 * `per_page`: an integer 1..MAX_PER_PAGE.
	 *
	 * @param mixed $value The value.
	 */
	public function validate_per_page( mixed $value ): bool|\WP_Error {
		if ( ! self::is_int_between( $value, 1, RecipeQuery::MAX_PER_PAGE ) ) {
			/* translators: %d: the largest page size */
			return self::invalid( 'per_page', sprintf( __( 'per_page must be a whole number from 1 to %d.', 'bluefairy-recipes' ), RecipeQuery::MAX_PER_PAGE ) );
		}
		return true;
	}

	/**
	 * `filter`: an object whose keys are enabled filter-type codes and whose
	 * values are strings. `filter[page]`, `filter[s]`, a disabled code or a
	 * nested array is refused.
	 *
	 * @param mixed $value The value.
	 */
	public function validate_filter( mixed $value ): bool|\WP_Error {
		if ( ! is_array( $value ) ) {
			return self::invalid( 'filter', __( 'filter must be an object of filter-type code to term slug.', 'bluefairy-recipes' ) );
		}
		$codes = array_keys( $this->taxonomies->enabled() );
		foreach ( $value as $code => $slug ) {
			if ( ! is_string( $code ) || ! in_array( $code, $codes, true ) ) {
				/* translators: %s: the key sent */
				return self::invalid( 'filter', sprintf( __( '%s is not a filter type.', 'bluefairy-recipes' ), (string) $code ) );
			}
			if ( ! is_string( $slug ) ) {
				/* translators: %s: the filter type code */
				return self::invalid( 'filter', sprintf( __( 'filter[%s] must be one term slug.', 'bluefairy-recipes' ), $code ) );
			}
		}
		return true;
	}

	/**
	 * The list.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! (bool) $this->settings->get( 'enabled' ) ) {
			return $this->respond( [], 0, 0 );
		}
		$filter = $request->get_param( 'filter' );
		$args   = $this->query->normalize(
			[
				's'        => $request->get_param( 's' ),
				'filters'  => is_array( $filter ) ? $filter : [],
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			]
		);
		if ( ( $args['page'] - 1 ) * $args['per_page'] >= self::MAX_OFFSET ) {
			/* translators: %d: the most recipes the list pages through */
			return self::invalid( 'page', sprintf( __( 'The list pages through the first %d recipes only: ask for a smaller page or fewer per page, or narrow the list with a search or a filter.', 'bluefairy-recipes' ), self::MAX_OFFSET ) );
		}
		$query = $this->query->run( $args, 'rest' );
		$posts = array_values( array_filter( is_array( $query->posts ) ? $query->posts : [], static fn( mixed $p ): bool => $p instanceof \WP_Post ) );
		$this->renderer->prime_thumbnails( $posts ); // As the grid does: one query for every card's photo, not two per card.
		$links = $this->renderer->writer_links( $posts );
		$items = [];
		foreach ( $posts as $post ) {
			$items[] = $this->item( $post, $links[ (int) $post->post_author ] ?? null );
		}
		return $this->respond( $items, (int) $query->found_posts, (int) $query->max_num_pages );
	}

	/**
	 * One item.
	 *
	 * @param \WP_Post    $post       The recipe.
	 * @param string|null $writer_url The writer's page, or null.
	 * @return array<string, mixed>
	 */
	public function item( \WP_Post $post, ?string $writer_url ): array {
		$locked = '' !== (string) $post->post_password; // Whoever asks: the response never varies by cookie.
		$data   = RecipeData::for_post( $post );
		$user   = get_userdata( (int) $post->post_author );
		$thumb  = get_the_post_thumbnail_url( $post, Renderer::GRID_IMAGE );
		$text   = ! $locked && has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		return [
			'id'      => (int) $post->ID,
			'title'   => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), // Plain text: the_title filters texturise.
			'url'     => (string) get_permalink( $post ),
			'thumb'   => is_string( $thumb ) ? $thumb : '',
			'writer'  => [
				'name' => $user instanceof \WP_User ? (string) $user->display_name : '',
				'url'  => (string) $writer_url,
			],
			'times'   => $locked ? [
				'prep'  => 0,
				'cook'  => 0,
				'total' => 0,
			] : [
				'prep'  => $data->prep_minutes,
				'cook'  => $data->cook_minutes,
				'total' => $data->total_minutes(),
			],
			'excerpt' => '' === $text ? '' : html_entity_decode( wp_trim_words( wp_strip_all_tags( $text ), 24, '…' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), // Plain text, as the title.
			'html'    => $this->renderer->card( $post, $writer_url, 2, $locked ),
		];
	}

	/**
	 * The item schema (for `OPTIONS` and the index).
	 *
	 * @return array<string, mixed>
	 */
	public function item_schema(): array {
		$int = static fn( string $description ): array => [
			'type'        => 'integer',
			'description' => $description,
		];
		$str = static fn( string $description ): array => [
			'type'        => 'string',
			'description' => $description,
		];
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bfrc_recipe_card',
			'type'       => 'object',
			'properties' => [
				'id'      => $int( __( 'Recipe id.', 'bluefairy-recipes' ) ),
				'title'   => $str( __( 'Title: unescaped plain text; escape it before inserting into HTML.', 'bluefairy-recipes' ) ),
				'url'     => $str( __( 'Recipe page.', 'bluefairy-recipes' ) ),
				'thumb'   => $str( __( 'Photo URL, or empty.', 'bluefairy-recipes' ) ),
				'writer'  => [
					'type'        => 'object',
					'description' => __( 'The writer: name, and their page when they have one.', 'bluefairy-recipes' ),
					'properties'  => [
						'name' => $str( __( 'Display name.', 'bluefairy-recipes' ) ),
						'url'  => $str( __( 'Writer page, or empty.', 'bluefairy-recipes' ) ),
					],
				],
				'times'   => [
					'type'        => 'object',
					'description' => __( 'Minutes; zeros for a locked recipe.', 'bluefairy-recipes' ),
					'properties'  => [
						'prep'  => $int( __( 'Prep minutes.', 'bluefairy-recipes' ) ),
						'cook'  => $int( __( 'Cook minutes.', 'bluefairy-recipes' ) ),
						'total' => $int( __( 'Prep plus cook.', 'bluefairy-recipes' ) ),
					],
				],
				'excerpt' => $str( __( 'Short description: unescaped plain text, at most 24 words; empty for a locked recipe; escape it before inserting into HTML.', 'bluefairy-recipes' ) ),
				'html'    => $str( __( 'The recipe card as the archive renders it.', 'bluefairy-recipes' ) ),
			],
		];
	}

	/**
	 * The response: items, totals in `X-WP-Total` / `X-WP-TotalPages` as
	 * core's collections, and the public cache header for anonymous callers.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 * @param int                              $total Matching recipes.
	 * @param int                              $pages Pages.
	 */
	private function respond( array $items, int $total, int $pages ): \WP_REST_Response {
		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );
		if ( ! is_user_logged_in() ) {
			$response->header( 'Cache-Control', 'public, max-age=' . self::MAX_AGE );
		}
		return $response;
	}

	/**
	 * A 400 for one argument.
	 *
	 * @param string $param   The argument.
	 * @param string $message Why.
	 */
	private static function invalid( string $param, string $message ): \WP_Error {
		return new \WP_Error(
			'rest_invalid_param',
			$message,
			[
				'status' => 400,
				'params' => [ $param => $message ],
			]
		);
	}

	/**
	 * Is the value an integer (or an integer string) within the bounds?
	 *
	 * @param mixed $value The value.
	 * @param int   $min   Lowest allowed.
	 * @param int   $max   Highest allowed.
	 */
	private static function is_int_between( mixed $value, int $min, int $max ): bool {
		if ( is_bool( $value ) || ! is_numeric( $value ) || (string) (int) $value !== (string) $value ) {
			return false;
		}
		$int = (int) $value;
		return $int >= $min && $int <= $max;
	}
}
