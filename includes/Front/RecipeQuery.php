<?php
/**
 * The bounded recipe list query shared by the REST endpoint, the Recipe List
 * block and the `[bfrc_recipes]` shortcode.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Front;

use BlueFairy\Recipes\Content\Cache;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One normalised argument shape (`normalize()`), one `WP_Query` argument
 * builder (`args()`), one runner (`run()`), and the cached featured/recent
 * set the Featured Recipes block and shortcode read (`featured()`).
 *
 * The archive page itself does not come through here: its list is the main
 * query, shaped in `Templates::shape_query()` with the same bounds.
 */
final class RecipeQuery {

	/**
	 * The page query argument of a list on a page (`?bfrc_page=2`): the
	 * block's pagination cannot use core's `paged`, which a page's own
	 * canonical redirect strips, and must not use `page`, which core reads
	 * as a `<!--nextpage-->` page of the post.
	 */
	public const PAGE_VAR = 'bfrc_page';

	/**
	 * Deepest page any list serves: `OFFSET` grows with the page number, so
	 * an unbounded page is an unbounded query.
	 */
	public const MAX_PAGE = 1000;

	/**
	 * Most recipes one list request returns (the per-page setting's bound).
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Keyword length bound, as the archive's.
	 */
	public const SEARCH_MAX = Templates::SEARCH_MAX;

	/**
	 * Featured block: most cards, and the cache lifetime of its id set.
	 */
	public const FEATURED_MAX = 12;
	public const FEATURED_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Taxonomies $taxonomies Filter types.
	 */
	public function __construct( private Settings $settings, private Taxonomies $taxonomies ) {}

	/**
	 * Normalise raw list arguments: the keyword (≤ SEARCH_MAX characters),
	 * the filters (enabled codes only, one sanitised slug each; `''`, `0`
	 * and non-strings are no choice), the page (1..MAX_PAGE) and the
	 * page size (1..MAX_PER_PAGE, the setting when absent).
	 *
	 * @param array<string, mixed> $raw `s`, `filters` (code => slug), `page`, `per_page`.
	 * @return array{s: string, filters: array<string, string>, page: int, per_page: int}
	 */
	public function normalize( array $raw ): array {
		$s       = $raw['s'] ?? '';
		$s       = is_string( $s ) ? mb_substr( sanitize_text_field( $s ), 0, self::SEARCH_MAX ) : '';
		$filters = [];
		$chosen  = $raw['filters'] ?? [];
		if ( is_array( $chosen ) ) {
			foreach ( array_keys( $this->taxonomies->enabled() ) as $code ) {
				$value = $chosen[ $code ] ?? '';
				if ( ! is_string( $value ) || '' === $value || '0' === $value ) {
					continue;
				}
				$slug = sanitize_title( $value );
				if ( '' !== $slug ) {
					$filters[ $code ] = $slug;
				}
			}
		}
		$per_page = $raw['per_page'] ?? 0;
		$per_page = is_numeric( $per_page ) ? (int) $per_page : 0;
		if ( $per_page < 1 ) {
			$per_page = (int) $this->settings->get( 'per_page' );
		}
		$page = $raw['page'] ?? 1;
		$page = is_numeric( $page ) ? (int) $page : 1;
		return [
			's'        => $s,
			'filters'  => $filters,
			'page'     => max( 1, min( self::MAX_PAGE, $page ) ),
			'per_page' => max( 1, min( self::MAX_PER_PAGE, $per_page ) ),
		];
	}

	/**
	 * `WP_Query` arguments for a normalised request: published recipes,
	 * newest first, title-only keyword search, one `tax_query` clause per
	 * filter (AND), paged and bounded. The term cache is not primed (cards
	 * read no terms); the meta cache is (one `postmeta IN` per list keeps
	 * `RecipeData::for_post()` at zero queries per card). `$count` false
	 * skips `SQL_CALC_FOUND_ROWS` for a list that shows no pagination.
	 *
	 * @param array{s: string, filters: array<string, string>, page: int, per_page: int} $args    From normalize().
	 * @param string                                                                     $context `rest`, `block` or `shortcode`.
	 * @param bool                                                                       $count   Count the matches (pagination needs it).
	 * @return array<string, mixed>
	 */
	public function args( array $args, string $context, bool $count = true ): array {
		$query = [
			'post_type'              => PostType::NAME,
			'post_status'            => 'publish',
			'posts_per_page'         => $args['per_page'],
			'paged'                  => $args['page'],
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'no_found_rows'          => ! $count,
		];
		if ( '' !== $args['s'] ) {
			$query['s']              = $args['s'];
			$query['search_columns'] = [ 'post_title' ];
		}
		$tax_query = [];
		foreach ( $args['filters'] as $code => $slug ) {
			$tax_query[] = [
				'taxonomy' => Taxonomies::taxonomy_name( $code ),
				'field'    => 'slug',
				'terms'    => $slug,
			];
		}
		if ( [] !== $tax_query ) {
			$query['tax_query'] = array_merge( [ 'relation' => 'AND' ], $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the filter form's choices; one indexed term_relationships join per filter.
		}
		/**
		 * The arguments of a recipe list query that is not the archive's main
		 * query: the REST endpoint, the Recipe List block and the
		 * `[bfrc_recipes]` shortcode. The archive page fires
		 * `bfrc_archive_query` instead.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $query   WP_Query arguments.
		 * @param array<string, mixed> $args    The normalised request (s, filters, page, per_page).
		 * @param string               $context `rest`, `block` or `shortcode`.
		 */
		$filtered = apply_filters( 'bfrc_list_query_args', $query, $args, $context );
		return is_array( $filtered ) ? $filtered : $query;
	}

	/**
	 * Run a normalised request.
	 *
	 * @param array{s: string, filters: array<string, string>, page: int, per_page: int} $args    From normalize().
	 * @param string                                                                     $context `rest`, `block` or `shortcode`.
	 * @param bool                                                                       $count   Count the matches (pagination needs it).
	 */
	public function run( array $args, string $context, bool $count = true ): \WP_Query {
		return new \WP_Query( $this->args( $args, $context, $count ) );
	}

	/**
	 * The featured (or most recent) published recipes, newest first, at most
	 * FEATURED_MAX. The id set is cached for FEATURED_TTL seconds under a key
	 * that carries the recipe incrementor (Content\Cache), so a featured
	 * toggle, a new recipe or a renamed term starts a new key at once and the
	 * lifetime only bounds what the incrementor cannot see.
	 *
	 * @param string $mode  `featured` or `recent`.
	 * @param int    $count 1..FEATURED_MAX.
	 * @return array<int, \WP_Post>
	 */
	public function featured( string $mode, int $count ): array {
		$mode  = 'recent' === $mode ? 'recent' : 'featured';
		$count = max( 1, min( self::FEATURED_MAX, $count ) );
		$key   = Cache::key(
			'featured',
			[
				'mode'  => $mode,
				'count' => $count,
			]
		);
		$ids   = wp_cache_get( $key, Cache::GROUP );
		if ( ! is_array( $ids ) ) {
			$args = [
				'post_type'           => PostType::NAME,
				'post_status'         => 'publish',
				'posts_per_page'      => $count,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
			];
			if ( 'featured' === $mode ) {
				$featured = Meta::newest_featured_ids( $count ); // At most $count ids: a site may feature thousands.
				if ( [] === $featured ) {
					wp_cache_set( $key, [], Cache::GROUP, self::FEATURED_TTL );
					return [];
				}
				$args['post__in'] = $featured; // Already the newest $count; the query re-checks status and password.
			}
			$query = new \WP_Query( $args );
			$ids   = array_values( array_map( 'intval', is_array( $query->posts ) ? $query->posts : [] ) );
			wp_cache_set( $key, $ids, Cache::GROUP, self::FEATURED_TTL );
		}
		if ( [] === $ids ) {
			return [];
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true ); // Posts and meta; cards read no terms.
		}
		$posts = [];
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status && PostType::NAME === $post->post_type ) {
				$posts[] = $post; // A recipe unpublished since the set was cached is left out (its clean_post_cache bumped the incrementor, so this is belt and braces).
			}
		}
		return $posts;
	}

	/**
	 * The page asked for in the request (`?bfrc_page=N`), 1 when absent.
	 */
	public static function requested_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public read-only list; the value is cast and bounded.
		$raw = isset( $_GET[ self::PAGE_VAR ] ) && is_scalar( $_GET[ self::PAGE_VAR ] ) ? (int) sanitize_text_field( wp_unslash( (string) $_GET[ self::PAGE_VAR ] ) ) : 1;
		return max( 1, min( self::MAX_PAGE, $raw ) );
	}
}
