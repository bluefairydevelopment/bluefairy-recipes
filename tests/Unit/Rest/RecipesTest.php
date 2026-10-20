<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Rest;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\RecipeQuery;
use BlueFairy\Recipes\Front\Renderer;
use BlueFairy\Recipes\Front\Templates;
use BlueFairy\Recipes\Front\Writers;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Rest\Recipes;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class RecipesTest extends TestCase {

	private Settings $settings;
	private Recipes $rest;
	private Renderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		Capabilities::add_roles();
		$this->settings = new Settings();
		$taxonomies     = new Taxonomies( $this->settings );
		$writers        = new Writers( $this->settings );
		$this->renderer = new Renderer( $this->settings, $writers, $taxonomies );
		Plugin::set( 'front.templates', new Templates( $this->settings, $writers, $this->renderer, $taxonomies ) );
		$this->rest = new Recipes( $this->settings, $taxonomies, new RecipeQuery( $this->settings, $taxonomies ), $this->renderer );
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann', 'user_nicename' => 'ann' ] );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Tomato Soup', 'post_name' => 'tomato-soup', 'post_excerpt' => 'A <em>warm</em> bowl.' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Secret Pie', 'post_name' => 'secret-pie', 'post_password' => 'hunter2', 'post_excerpt' => 'The secret filling.' ] );
		update_post_meta( 41, Meta::PREP_MINUTES, 20 );
		update_post_meta( 41, Meta::COOK_MINUTES, 25 );
		update_post_meta( 42, Meta::PREP_MINUTES, 99 );
		update_post_meta( 41, '_thumbnail_id', 77 );
		bfrc_test_add_attachment( 77 );
	}

	public function test_register_hooks_and_routes_register_one_public_bounded_get(): void {
		$this->rest->register_hooks();
		$this->assertSame( 10, has_action( 'rest_api_init', [ $this->rest, 'register_routes' ] ) );
		$this->rest->register_routes();
		$route = $GLOBALS['bfrc_test_rest_routes']['/bfrc/v1/recipes'];
		$this->assertSame( 'GET', $route[0]['methods'] );
		$this->assertSame( '__return_true', $route[0]['permission_callback'], 'a public read of published recipes, as the archive page' );
		$this->assertSame( [ $this->rest, 'get_items' ], $route[0]['callback'] );
		$this->assertSame( [ $this->rest, 'item_schema' ], $route['schema'] );
		$args = $route[0]['args'];
		$this->assertSame( [ 's', 'page', 'per_page', 'filter' ], array_keys( $args ) );
		$this->assertSame( 100, $args['s']['maxLength'] );
		$this->assertSame( [ 1, 1000 ], [ $args['page']['minimum'], $args['page']['maximum'] ] );
		$this->assertSame( [ 1, 100, 12 ], [ $args['per_page']['minimum'], $args['per_page']['maximum'], $args['per_page']['default'] ] );
		$this->assertFalse( $args['filter']['additionalProperties'], 'filter[page] is not a code' );
		$this->assertSame( [ 'meal_type', 'diet', 'season', 'cuisine' ], array_keys( $args['filter']['properties'] ) );
		foreach ( [ 's' => 'validate_search', 'page' => 'validate_page', 'per_page' => 'validate_per_page', 'filter' => 'validate_filter' ] as $arg => $method ) {
			$this->assertSame( [ $this->rest, $method ], $args[ $arg ]['validate_callback'], "$arg is validated here as well as by the schema" );
		}
		$this->assertSame( 'sanitize_text_field', $args['s']['sanitize_callback'] );
		$schema = $this->rest->item_schema();
		$this->assertSame( [ 'id', 'title', 'url', 'thumb', 'writer', 'times', 'excerpt', 'html' ], array_keys( $schema['properties'] ) );
	}

	public function test_validators_refuse_what_the_schema_refuses(): void {
		$r = $this->rest;
		$this->assertTrue( $r->validate_search( str_repeat( 'é', 100 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_search( str_repeat( 'é', 101 ) ), '101 characters' );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_search( [ 'x' ] ) );
		$this->assertTrue( $r->validate_page( '1000' ) );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_page( 1001 ) );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_page( 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_page( '2.5' ) );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_page( true ) );
		$this->assertTrue( $r->validate_per_page( 100 ) );
		$per_page = $r->validate_per_page( 101 );
		$this->assertInstanceOf( \WP_Error::class, $per_page, 'per_page=101 is a 400' );
		$this->assertSame( 400, $per_page->get_error_data()['status'] );
		$this->assertSame( 'rest_invalid_param', $per_page->get_error_code() );
		$this->assertArrayHasKey( 'per_page', $per_page->get_error_data()['params'] );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_per_page( '0' ) );
		$this->assertTrue( $r->validate_filter( [ 'diet' => 'vegan', 'cuisine' => '' ] ) );
		$this->assertTrue( $r->validate_filter( [] ) );
		$page = $r->validate_filter( [ 'page' => '2' ] );
		$this->assertInstanceOf( \WP_Error::class, $page, 'filter[page] is rejected as a code' );
		$this->assertStringContainsString( 'page is not a filter type', $page->get_error_message() );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_filter( [ 'diet' => [ 'vegan' ] ] ), 'one slug, not a list' );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_filter( 'vegan' ) );
		$this->assertInstanceOf( \WP_Error::class, $r->validate_filter( [ 0 => 'vegan' ] ), 'a list is not an object of codes' );
		$this->settings->update( [ 'taxonomies' => [ 'diet' => [ 'enabled' => false ] ] ] + $this->settings->all() );
		$rest = new Recipes( $this->settings, new Taxonomies( $this->settings ), new RecipeQuery( $this->settings, new Taxonomies( $this->settings ) ), $this->renderer );
		$this->assertInstanceOf( \WP_Error::class, $rest->validate_filter( [ 'diet' => 'vegan' ] ), 'a disabled filter type is not a code' );
	}

	public function test_get_items_lists_cards_with_totals_and_a_public_cache_header_and_never_unlocks_a_protected_recipe(): void {
		bfrc_test_query_resolver( static fn( array $vars ): array => [ [ get_post( 41 ), get_post( 42 ) ], 14, 2 ] );
		$GLOBALS['bfrc_test_password_unlocked'][42] = true; // The caller holds the unlock cookie.
		$request = $this->request( 'GET', '/bfrc/v1/recipes', [], [] );
		$request->set_param( 's', 'soup' );
		$request->set_param( 'filter', [ 'diet' => 'vegan' ] );
		$request->set_param( 'page', 2 );
		$request->set_param( 'per_page', 7 );
		$response = $this->rest->get_items( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'X-WP-Total' => '14', 'X-WP-TotalPages' => '2', 'Cache-Control' => 'public, max-age=60' ], $response->get_headers() );
		$vars = $GLOBALS['bfrc_test_queries'][0];
		$this->assertSame( [ 'soup', 2, 7, 'bfrc_diet' ], [ $vars['s'], $vars['paged'], $vars['posts_per_page'], $vars['tax_query'][0]['taxonomy'] ], 'the request\'s arguments reach the query, normalised' );
		$items = $response->get_data();
		$this->assertCount( 2, $items );
		$soup = $items[0];
		$this->assertSame( [ 'id', 'title', 'url', 'thumb', 'writer', 'times', 'excerpt', 'html' ], array_keys( $soup ) );
		$this->assertSame( [ 41, 'Tomato Soup', 'https://example.com/?p=41', 'https://example.test/uploads/77.jpg' ], [ $soup['id'], $soup['title'], $soup['url'], $soup['thumb'] ] );
		$this->assertSame( [ 'name' => 'Ann', 'url' => 'https://example.com/recipes/chef/ann/' ], $soup['writer'] );
		$this->assertSame( [ 'prep' => 20, 'cook' => 25, 'total' => 45 ], $soup['times'] );
		$this->assertSame( 'A warm bowl.', $soup['excerpt'] );
		$this->assertSame( [ [ 77 ], false, true ], $GLOBALS['bfrc_test_prime_args'][0] ?? null, 'every card\'s photo primed in one query before the loop, as the grid does' );
		$this->assertSame( $this->renderer->card( get_post( 41 ), 'https://example.com/recipes/chef/ann/' ), $soup['html'], 'the card the archive renders' );
		$pie = $items[1];
		$this->assertSame( [ 'prep' => 0, 'cook' => 0, 'total' => 0 ], $pie['times'], 'a locked recipe: no times' );
		$this->assertSame( '', $pie['excerpt'] );
		$this->assertSame( '', $pie['thumb'] );
		$this->assertStringContainsString( 'bfrc-card--protected', $pie['html'], 'locked even for a caller with the unlock cookie: the response never varies by cookie' );
		$this->assertStringNotContainsString( '99', $pie['html'] );
		$this->assertStringNotContainsString( 'secret filling', $pie['html'] );
		wp_set_current_user( 5 );
		$this->assertArrayNotHasKey( 'Cache-Control', $this->rest->get_items( $request )->get_headers(), 'a logged-in caller gets core\'s no-cache headers, not a public one' );
	}

	public function test_get_items_is_an_empty_list_while_disabled_without_querying(): void {
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		bfrc_test_query_resolver( static fn( array $vars ): array => [ [ get_post( 41 ) ], 1, 1 ] );
		$response = $this->rest->get_items( $this->request( 'GET', '/bfrc/v1/recipes' ) );
		$this->assertSame( [], $response->get_data() );
		$this->assertSame( '0', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( [], $GLOBALS['bfrc_test_queries'] );
	}

	public function test_get_items_without_arguments_uses_the_defaults_and_drops_unknown_filter_keys(): void {
		bfrc_test_query_resolver( static fn( array $vars ): array => [ [], 0, 0 ] );
		$request = $this->request( 'GET', '/bfrc/v1/recipes' );
		$request->set_param( 'filter', [ 'page' => '2', 'diet' => 'vegan' ] ); // What a server that skipped validation would hand us.
		$this->assertSame( [], $this->rest->get_items( $request )->get_data() );
		$vars = $GLOBALS['bfrc_test_queries'][0];
		$this->assertSame( [ 1, 12 ], [ $vars['paged'], $vars['posts_per_page'] ] );
		$this->assertCount( 2, $vars['tax_query'], 'relation + the one known code' );
		$this->assertArrayNotHasKey( 's', $vars );
	}

	public function test_item_handles_a_deleted_author_and_no_writer_page(): void {
		$GLOBALS['bfrc_test_posts'][41]->post_author = 999;
		$item                                        = $this->rest->item( get_post( 41 ), null );
		$this->assertSame( [ 'name' => '', 'url' => '' ], $item['writer'] );
	}

	public function test_item_title_is_plain_text_without_entities_or_markup(): void {
		$GLOBALS['bfrc_test_posts'][41]->post_title   = 'Mom&#8217;s <b>Soup</b> &#038; Co';
		$GLOBALS['bfrc_test_posts'][41]->post_excerpt = 'A &amp; B <b>bold</b>';
		$item                                         = $this->rest->item( get_post( 41 ), null );
		$this->assertSame( 'Mom’s Soup & Co', $item['title'], 'the_title filters texturise; the item does not' );
		$this->assertSame( 'A & B bold', $item['excerpt'], 'the excerpt is plain text the same way' );
	}
}
