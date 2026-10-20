<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Front;

use BlueFairy\Recipes\Content\Cache;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\RecipeQuery;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class RecipeQueryTest extends TestCase {

	private Settings $settings;
	private RecipeQuery $query;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$this->settings = new Settings();
		$this->query    = new RecipeQuery( $this->settings, new Taxonomies( $this->settings ) );
	}

	public function test_normalize_bounds_everything_and_keeps_only_enabled_codes(): void {
		$n = $this->query->normalize( [] );
		$this->assertSame( [ 's' => '', 'filters' => [], 'page' => 1, 'per_page' => 12 ], $n, 'the setting is the page size' );
		$n = $this->query->normalize(
			[
				's'        => "  <b>soup</b>\t" . str_repeat( 'x', 200 ),
				'filters'  => [ 'diet' => 'Vegan Food', 'cuisine' => '0', 'season' => [ 'x' ], 'meal_type' => '', 'page' => '2', 'nope' => 'x' ],
				'page'     => '5000',
				'per_page' => '101',
			]
		);
		$this->assertSame( 100, mb_strlen( $n['s'] ), 'the keyword is cut at 100' );
		$this->assertStringStartsWith( 'soup x', $n['s'], 'tags stripped, whitespace collapsed' );
		$this->assertSame( [ 'diet' => 'vegan-food' ], $n['filters'], 'a slug per enabled code; 0, arrays, empties and unknown codes dropped' );
		$this->assertSame( RecipeQuery::MAX_PAGE, $n['page'] );
		$this->assertSame( 100, $n['per_page'] );
		$this->assertSame( [ 'page' => 1, 'per_page' => 12 ], array_intersect_key( $this->query->normalize( [ 'page' => -3, 'per_page' => '0' ] ), [ 'page' => 1, 'per_page' => 1 ] ), 'a page below 1 is page 1; per_page 0 is the setting' );
		$this->assertSame( 1, $this->query->normalize( [ 'per_page' => 1 ] )['per_page'] );
		$this->assertSame( 12, $this->query->normalize( [ 'per_page' => 'abc' ] )['per_page'], 'a non-number is the setting' );
		$this->assertSame( 12, $this->query->normalize( [ 'per_page' => -1 ] )['per_page'], 'a negative is the setting (0 is "use the setting" for blocks)' );
		$this->settings->update( [ 'taxonomies' => [ 'diet' => [ 'enabled' => false ] ] ] + $this->settings->all() );
		$query = new RecipeQuery( $this->settings, new Taxonomies( $this->settings ) );
		$this->assertSame( [], $query->normalize( [ 'filters' => [ 'diet' => 'vegan' ] ] )['filters'], 'a disabled filter type is not a filter' );
	}

	public function test_args_builds_a_bounded_published_recipe_query_and_offers_the_filter(): void {
		$args = $this->query->args( $this->query->normalize( [ 's' => 'soup', 'filters' => [ 'diet' => 'vegan', 'cuisine' => 'thai' ], 'page' => 2, 'per_page' => 6 ] ), 'rest' );
		$this->assertSame( 'bfrc_recipe', $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertSame( [ 6, 2 ], [ $args['posts_per_page'], $args['paged'] ] );
		$this->assertTrue( $args['ignore_sticky_posts'] );
		$this->assertFalse( $args['update_post_term_cache'], 'cards read no terms' );
		$this->assertFalse( $args['no_found_rows'], 'counted by default' );
		$this->assertTrue( $this->query->args( $this->query->normalize( [] ), 'block', false )['no_found_rows'], 'a list without pagination skips SQL_CALC_FOUND_ROWS' );
		$this->assertTrue( $this->query->run( $this->query->normalize( [] ), 'block', false )->get( 'no_found_rows' ) );
		$this->assertSame( 'soup', $args['s'] );
		$this->assertSame( [ 'post_title' ], $args['search_columns'], 'title only, as the archive' );
		$this->assertSame( 'AND', $args['tax_query']['relation'] );
		$this->assertSame( [ [ 'taxonomy' => 'bfrc_diet', 'field' => 'slug', 'terms' => 'vegan' ], [ 'taxonomy' => 'bfrc_cuisine', 'field' => 'slug', 'terms' => 'thai' ] ], [ $args['tax_query'][0], $args['tax_query'][1] ] );
		$plain = $this->query->args( $this->query->normalize( [] ), 'block' );
		$this->assertArrayNotHasKey( 's', $plain );
		$this->assertArrayNotHasKey( 'tax_query', $plain );
		$seen = [];
		add_filter( 'bfrc_list_query_args', static function ( array $q, array $n, string $context ) use ( &$seen ): array {
			$seen = [ $n['per_page'], $context ];
			$q['author'] = 5;
			return $q;
		}, 10, 3 );
		$this->assertSame( 5, $this->query->args( $this->query->normalize( [] ), 'shortcode' )['author'] );
		$this->assertSame( [ 12, 'shortcode' ], $seen );
		add_filter( 'bfrc_list_query_args', '__return_false', 20 );
		$this->assertSame( 'bfrc_recipe', $this->query->args( $this->query->normalize( [] ), 'shortcode' )['post_type'], 'a filter returning a non-array is ignored' );
	}

	public function test_run_queries_with_the_built_args(): void {
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish' ] );
		bfrc_test_query_resolver( static fn( array $vars ): array => 'bfrc_recipe' === $vars['post_type'] ? [ [ get_post( 41 ) ], 7, 2 ] : [ [], 0, 0 ] );
		$q = $this->query->run( $this->query->normalize( [ 'per_page' => 5 ] ), 'rest' );
		$this->assertSame( [ 7, 2, 1 ], [ $q->found_posts, $q->max_num_pages, $q->post_count ] );
		$this->assertSame( 5, $GLOBALS['bfrc_test_queries'][0]['posts_per_page'] );
		$this->assertFalse( $q->is_main_query() );
	}

	public function test_featured_reads_the_cached_id_set_and_starts_a_new_key_when_a_recipe_changes(): void {
		foreach ( [ 41, 42, 43 ] as $id ) {
			bfrc_test_add_post( [ 'ID' => $id, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish' ] );
		}
		update_post_meta( 41, Meta::FEATURED, true );
		update_post_meta( 43, Meta::FEATURED, true );
		$this->wpdb->queue( 'get_col', [ 43, 41 ] ); // Meta::featured_ids(): the indexed id lookup.
		$queries = 0;
		bfrc_test_query_resolver( static function ( array $vars ) use ( &$queries ): array {
			++$queries;
			$ids = isset( $vars['post__in'] ) ? $vars['post__in'] : [ 43, 42, 41 ];
			return [ array_slice( $ids, 0, (int) $vars['posts_per_page'] ), 0, 0 ];
		} );
		$posts = $this->query->featured( 'featured', 4 );
		$this->assertSame( [ 43, 41 ], array_map( static fn( \WP_Post $p ): int => $p->ID, $posts ) );
		$vars = $GLOBALS['bfrc_test_queries'][0];
		$this->assertSame( [ 43, 41 ], $vars['post__in'], 'the cached id set, never a postmeta join' );
		$this->assertSame( [ 'ids', true, true, 4 ], [ $vars['fields'], $vars['no_found_rows'], $vars['ignore_sticky_posts'], $vars['posts_per_page'] ] );
		$this->query->featured( 'featured', 4 );
		$this->assertSame( 1, $queries, 'the second read is from the cache' );
		$this->assertSame( 2, count( $this->query->featured( 'recent', 2 ) ) );
		$this->assertSame( 2, $queries, 'a different mode or count is its own key' );
		$this->assertArrayNotHasKey( 'post__in', $GLOBALS['bfrc_test_queries'][1], 'recent: no id set' );
		$this->assertSame( 1, count( $this->query->featured( 'recent', 1 ) ) );
		$this->assertCount( 2, $this->query->featured( 'bogus', 100 ), 'bogus mode is featured (two are), 100 is 12' );
		$this->assertSame( 12, $GLOBALS['bfrc_test_queries'][3]['posts_per_page'] ?? 0, 'the count is capped at 12' );
		Cache::bump(); // What a recipe edit does through the hooks.
		$this->query->featured( 'featured', 4 );
		$this->assertSame( 5, $queries, 'after a bump the old key is never read' );
		$GLOBALS['bfrc_test_posts'][43]->post_status = 'draft';
		$this->assertSame( [ 41 ], array_map( static fn( \WP_Post $p ): int => $p->ID, $this->query->featured( 'featured', 4 ) ), 'a recipe unpublished since the set was cached is left out' );
		$this->wpdb->queue( 'get_col', [] );
		Cache::bump();
		Meta::flush_featured_ids( 1, 41, Meta::FEATURED );
		$this->assertSame( [], $this->query->featured( 'featured', 4 ), 'no featured recipes: nothing, and no query for them' );
		$this->assertSame( 5, $queries );
		$this->assertNotEmpty( $GLOBALS['bfrc_test_cache']['bfrc'] ?? [], 'cached under the bfrc group' );
		foreach ( $GLOBALS['bfrc_test_cache_expires']['bfrc'] as $key => $expires ) {
			if ( str_starts_with( (string) $key, 'featured:' ) ) {
				$this->assertSame( bfrc_test_now() + RecipeQuery::FEATURED_TTL, $expires, 'TTL 300' );
			}
		}
	}

	public function test_requested_page_reads_the_block_page_var_bounded(): void {
		$this->assertSame( 1, RecipeQuery::requested_page() );
		$_GET['bfrc_page'] = '3';
		$this->assertSame( 3, RecipeQuery::requested_page() );
		$_GET['bfrc_page'] = '-2';
		$this->assertSame( 1, RecipeQuery::requested_page() );
		$_GET['bfrc_page'] = '99999';
		$this->assertSame( RecipeQuery::MAX_PAGE, RecipeQuery::requested_page() );
		$_GET['bfrc_page'] = [ '2' ];
		$this->assertSame( 1, RecipeQuery::requested_page(), 'an array is no page' );
	}
}
