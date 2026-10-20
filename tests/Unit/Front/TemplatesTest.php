<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Front;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\Renderer;
use BlueFairy\Recipes\Front\Templates;
use BlueFairy\Recipes\Front\Writers;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class TemplatesTest extends TestCase {

	private Settings $settings;
	private Writers $writers;
	private Templates $templates;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		Capabilities::add_roles();
		$this->settings  = new Settings();
		$taxonomies      = new Taxonomies( $this->settings );
		$this->writers   = new Writers( $this->settings );
		$renderer        = new Renderer( $this->settings, $this->writers, $taxonomies );
		$this->templates = new Templates( $this->settings, $this->writers, $renderer, $taxonomies );
		Plugin::set( 'front.templates', $this->templates );
		Plugin::set( 'front.renderer', $renderer );
		Plugin::set( 'front.writers', $this->writers );
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann', 'user_nicename' => 'ann' ] );
		bfrc_test_add_user( 6, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Bob', 'user_nicename' => 'bob' ] );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Soup' ] );
	}

	/** A fresh Writers (no memos) with new settings. */
	private function with_settings( array $changes ): void {
		$this->settings->update( $changes + $this->settings->all() );
		$this->writers->reset();
	}

	public function test_register_hooks(): void {
		$t = $this->templates;
		$t->register_hooks();
		$this->assertSame( 20, has_action( 'init', [ $t, 'register' ] ) );
		foreach ( [ 'query_vars' => 'query_vars', 'document_title_parts' => 'document_title', 'template_include' => 'template_include', 'taxonomy_template_hierarchy' => 'archive_hierarchy', 'author_template_hierarchy' => 'archive_hierarchy', 'archive_template_hierarchy' => 'archive_hierarchy', 'comments_open' => 'comments_closed', 'body_class' => 'body_class' ] as $hook => $method ) {
			$this->assertSame( 10, has_filter( $hook, [ $t, $method ] ), $hook );
		}
		foreach ( [ 'pre_get_posts' => 'shape_query', 'template_redirect' => 'guard', 'wp_enqueue_scripts' => 'enqueue' ] as $hook => $method ) {
			$this->assertSame( 10, has_action( $hook, [ $t, $method ] ), $hook );
		}
		$this->assertSame( 10, has_action( 'wp_head', [ $t, 'rel_canonical' ] ) );
		$this->assertSame( 10, has_filter( 'rest_queried_resource_route', [ $t, 'rest_resource_route' ] ) );
		$this->assertSame( 1, has_action( 'enqueue_block_assets', [ Templates::class, 'register_assets' ] ), 'before core enqueues the editor_style handle at 10' );
		foreach ( [ 'wpseo_canonical', 'rank_math/frontend/canonical', 'aioseo_canonical_url' ] as $hook ) {
			$this->assertSame( 10, has_filter( $hook, [ $t, 'seo_plugin_canonical' ] ), "$hook: the SEO plugin prints our canonical, not the post type's" );
		}
		$this->assertFalse( has_filter( 'wp_sitemaps_users_query_args', [ $this->writers, 'sitemap_users_query' ] ), 'Writers registers its own hooks' );
	}

	public function test_rest_resource_route_names_the_writer_not_users_0(): void {
		$t = $this->templates;
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->assertSame( '/wp/v2/users/5', $t->rest_resource_route( '/wp/v2/users/0' ), 'core sees the post type as the queried object and would print users/0' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->assertSame( '', $t->rest_resource_route( '/wp/v2/users/0' ), 'the listing is no single resource' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( '/wp/v2/types/bfrc_recipe', $t->rest_resource_route( '/wp/v2/types/bfrc_recipe' ), 'the archive: core\'s own' );
		bfrc_test_front_query( [ 'post_type' => 'post' ] );
		$this->assertSame( 'x', $t->rest_resource_route( 'x' ) );
	}

	public function test_shape_query_ignores_a_keyword_or_filter_on_a_writers_page(): void {
		$_GET = [ 'bfrc_s' => 'soup', 'bfrc_filter' => [ 'diet' => 'vegan' ] ];
		$q    = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->templates->shape_query( $q );
		$this->assertSame( 12, $q->get( 'posts_per_page' ) );
		$this->assertSame( '', $q->get( 's' ), 'no filter form on a writer page, so nothing silently narrows it' );
		$this->assertSame( '', $q->get( 'tax_query' ) );
		$archive = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->templates->shape_query( $archive );
		$this->assertSame( 'soup', $archive->get( 's' ), 'the archive honours them' );
		$_GET = [];
	}

	public function test_seo_plugin_canonical_replaces_the_plugins_url_on_writer_views_only(): void {
		$t = $this->templates;
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->assertSame( 'https://example.com/recipes/chef/ann/', $t->seo_plugin_canonical( 'https://example.com/recipes/' ), 'Yoast would name the post-type archive' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 2 ] );
		$this->assertSame( 'https://example.com/recipes/chef/page/2/', $t->seo_plugin_canonical( false ) );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( 'https://example.com/recipes/', $t->seo_plugin_canonical( 'https://example.com/recipes/' ), 'the archive is the SEO plugin\'s own' );
		$gone = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'bob' ], 'author' );
		$gone->set_404();
		$this->assertSame( 'x', $t->seo_plugin_canonical( 'x' ) );
		bfrc_test_front_query( [ 'post_type' => 'post' ] );
		$this->assertNull( $t->seo_plugin_canonical( null ), 'not ours: whatever the plugin had' );
		$this->assertFalse( Templates::seo_plugin_present(), 'no SEO plugin in the harness' );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function test_with_an_seo_plugin_active_the_plugin_prints_no_canonical_of_its_own(): void {
		define( 'WPSEO_VERSION', '99.0' );
		$this->assertTrue( Templates::seo_plugin_present() );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		ob_start();
		$this->templates->rel_canonical();
		$this->assertSame( '', ob_get_clean(), 'two rel=canonical tags cancel each other out: Yoast prints the one we hand it' );
		$this->assertSame( 'https://example.com/recipes/chef/ann/', $this->templates->seo_plugin_canonical( 'https://example.com/recipes/' ) );
		add_filter( 'bfrc_writer_canonical', static fn( string $url ): string => str_replace( 'example.com', 'cdn.example.com', $url ) );
		$this->assertSame( 'https://cdn.example.com/recipes/chef/ann/', $this->templates->seo_plugin_canonical( 'https://example.com/recipes/' ), 'the site\'s filter still has its say with an SEO plugin active' );
		$silence = static fn(): string => '';
		add_filter( 'bfrc_writer_canonical', $silence, 20 );
		$this->assertSame( 'https://example.com/recipes/', $this->templates->seo_plugin_canonical( 'https://example.com/recipes/' ), 'an empty answer leaves the SEO plugin\'s own canonical' );
	}

	public function test_under_plain_permalinks_the_writer_views_live_at_their_query_string_urls(): void {
		$GLOBALS['wp_rewrite']->permalink_structure = '';
		$this->assertFalse( Writers::uses_permalinks() );
		$t = $this->templates;
		$this->assertSame( 'https://example.com/?post_type=bfrc_recipe&bfrc_writers=1', $this->writers->listing_url() );
		$this->assertSame( 'https://example.com/?post_type=bfrc_recipe&author_name=ann&bfrc_writer=1', $this->writers->url( get_userdata( 5 ) ) );
		$this->assertSame( 'https://example.com/?post_type=bfrc_recipe&bfrc_writers=1&paged=2', $t->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 2 ] ) ) );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$_GET = [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ];
		$t->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404(), 'no rewrite rules run: the query string is the page, never redirected' );
		ob_start();
		$t->rel_canonical();
		$this->assertSame( '<link rel="canonical" href="https://example.com/?post_type=bfrc_recipe&amp;author_name=ann&amp;bfrc_writer=1">' . "\n", ob_get_clean(), 'the canonical names a URL that resolves (the pretty one would serve the home page)' );
		$_GET = [];
		$GLOBALS['bfrc_test_rewrite_rules'] = [];
		$this->writers->add_rewrite_rules();
		$this->assertCount( 6, $GLOBALS['bfrc_test_rewrite_rules'], 'the rules are still registered for when permalinks come on' );
	}

	public function test_a_custom_pagination_base_is_used_in_the_routes_the_canonical_and_the_listing_pagination(): void {
		$GLOBALS['wp_rewrite']->pagination_base = 'seite';
		$this->assertSame( 'seite', Writers::pagination_base() );
		$this->writers->add_rewrite_rules();
		$rules = array_column( $GLOBALS['bfrc_test_rewrite_rules'], 0 );
		$this->assertContains( '^recipes/chef/seite/([0-9]{1,})/?$', $rules );
		$this->assertContains( '^recipes/chef/([^/]+)/seite/([0-9]{1,})/?$', $rules );
		$this->assertNotContains( '^recipes/chef/page/([0-9]{1,})/?$', $rules );
		$this->assertSame( 'https://example.com/recipes/chef/seite/3/', $this->templates->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 3 ] ) ) );
		for ( $i = 1; $i <= 30; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'W' . $i, 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		$this->writers->reset();
		Plugin::service( 'front.renderer' )->writers_list( 1 );
		$this->assertSame( 'seite/%#%/', $GLOBALS['bfrc_test_paginate_calls'][0]['format'] );
		$GLOBALS['wp_rewrite']->pagination_base = '';
		$this->assertSame( 'page', Writers::pagination_base(), 'an empty base (no rewrite object yet) falls back to core\'s default' );
	}

	public function test_guard_leaves_no_trace_of_a_hidden_writer_or_a_recipe_on_the_404(): void {
		$q                    = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'bob' ], 'author' );
		$q->queried_object    = get_userdata( 6 );
		$q->queried_object_id = 6;
		$q->post              = get_post( 41 );
		$GLOBALS['post']      = get_post( 41 );
		$this->templates->guard();
		$this->assertTrue( $q->is_404() );
		$this->assertNull( $q->queried_object, 'an SEO plugin building og: tags from the queried object must not name the hidden writer' );
		$this->assertSame( 0, $q->queried_object_id );
		$this->assertNull( $q->post );
		$this->assertNull( $GLOBALS['post'] );
	}

	public function test_the_redirect_keeps_the_other_query_arguments_and_fires_on_an_author_name_override_or_a_posted_var(): void {
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$_GET = [ 'bfrc_writer' => '1', 'author_name' => 'ann', 'bfrc_s' => 'so\\\'up <b>', 'bfrc_filter' => [ 'diet' => 'vegan', 'x' => [ 'deep' => '<i>y</i>' ] ], '' => 'dropped' ];
		try {
			$this->templates->guard();
			$this->fail( 'expected a redirect' );
		} catch ( \BfrcTestExit $e ) {
			$this->assertSame( 'https://example.com/recipes/chef/ann/?bfrc_s=so%27up&bfrc_filter%5Bdiet%5D=vegan&bfrc_filter%5Bx%5D%5Bdeep%5D=y', $e->payload, 'the keyword and the filters travel, unslashed and sanitised; the writer vars do not' );
		}
		// /recipes/chef/nobody-here/?author_name=ann: the query string overrides what the route matched.
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$_GET = [ 'author_name' => 'ann' ];
		try {
			$this->templates->guard();
			$this->fail( 'expected a redirect' );
		} catch ( \BfrcTestExit $e ) {
			$this->assertSame( 'https://example.com/recipes/chef/ann/', $e->payload, 'any /chef/<x>/ URL carrying ?author_name= serves one writer only at their own address' );
		}
		$_GET  = [];
		$_POST = [ 'bfrc_writers' => '1' ];
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		try {
			$this->templates->guard();
			$this->fail( 'expected a redirect' );
		} catch ( \BfrcTestExit $e ) {
			$this->assertSame( 'https://example.com/recipes/chef/', $e->payload, 'core reads query vars from the request body too' );
		}
		$_POST = [];
	}

	public function test_the_view_blocks_render_nothing_inside_a_recipes_own_body(): void {
		$t = $this->templates;
		// A writer pasted the Single Recipe block (and the archive block) into the recipe's body.
		$GLOBALS['bfrc_test_posts'][41]->post_content = '<!-- wp:paragraph --><p>Serve warm.</p><!-- /wp:paragraph --><!-- wp:bfrc/recipe-single /--><!-- wp:bfrc/recipe-archive /-->';
		$GLOBALS['post']                              = get_post( 41 );
		$single                                       = bfrc_test_front_query( [], 'single' ); // The recipe page's own main query, so the archive block's guard is what stops it, not a missing query.
		$single->queried_object                       = get_post( 41 );
		$single->posts                                = [ get_post( 41 ) ];
		$nested                                       = [];
		add_filter( 'the_content', static function ( string $c ) use ( $t, &$nested ): string {
			$nested[] = $t->render_single_block();
			$nested[] = $t->render_archive_block();
			return $c;
		} );
		$html = $t->render_single_block();
		$this->assertSame( 1, substr_count( $html, '<article class="bfrc-recipe' ), 'rendered once, not until the stack runs out' );
		$this->assertStringContainsString( '<p>Serve warm.</p>', $html, 'the body still renders' );
		$this->assertSame( [ '', '' ], $nested, 'inside the body both view blocks are inert' );
		$this->assertFalse( Renderer::is_rendering_single(), 'the guard is released after the render' );
		$q        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$q->posts = [ get_post( 41 ) ];
		$this->assertStringContainsString( 'bfrc-grid__item', $t->render_archive_block(), 'outside a recipe render the archive block works' );
	}

	public function test_guard_empties_a_404ed_query_so_a_writer_feed_carries_no_recipes(): void {
		$q              = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'bob', 'feed' => 'rss2' ], 'author' );
		$q->is_feed     = true;
		$q->posts       = [ get_post( 41 ) ];
		$q->post_count  = 1;
		$q->found_posts = 1;
		$q->max_num_pages = 1;
		$this->templates->guard();
		$this->assertTrue( $q->is_404() );
		$this->assertSame( [ [], 0, 0, 0 ], [ $q->posts, $q->post_count, $q->found_posts, $q->max_num_pages ], 'core\'s feed loader ignores set_404(): the 404 body must have nothing to list' );
		$ok        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann', 'feed' => 'rss2' ], 'author' );
		$ok->posts = [ get_post( 41 ) ];
		$this->templates->guard();
		$this->assertSame( [ get_post( 41 ) ], $ok->posts, 'a public writer\'s feed keeps its recipes' );
	}

	public function test_guard_404s_only_past_the_last_listing_page_and_keeps_an_empty_listing_at_200(): void {
		for ( $i = 1; $i <= 30; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'Writer %02d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		$this->writers->reset();
		$this->assertSame( 2, $this->writers->listed( 1 )['pages'] );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 2 ] );
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404(), 'the last page exists' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 3 ] );
		$this->templates->guard();
		$this->assertTrue( $GLOBALS['wp_query']->is_404(), 'one past the last page' );
		$GLOBALS['bfrc_test_users'] = [];
		$this->writers->reset();
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404(), 'no writers at all: page 1 is an empty listing, not a 404' );
	}

	public function test_writer_views_have_one_canonical_url_the_query_string_form_redirects_to_it_and_wp_head_prints_it(): void {
		$t = $this->templates;
		$this->assertSame( 'https://example.com/recipes/chef/', $t->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] ) ) );
		$this->assertSame( 'https://example.com/recipes/chef/page/2/', $t->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 2 ] ) ) );
		$this->assertSame( 'https://example.com/recipes/chef/ann/', $t->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' ) ) );
		$this->assertSame( 'https://example.com/recipes/chef/ann/page/3/', $t->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann', 'paged' => 3 ], 'author' ) ) );
		$this->assertSame( '', $t->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'bob' ], 'author' ) ), 'no page, no canonical' );
		$this->assertSame( '', $t->canonical_url( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ), 'the archive is core\'s to canonicalise' );

		// The pretty route: the vars arrive through the rewrite, $_GET is empty, no redirect.
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404() );
		// The duplicate: ?bfrc_writer=1&author_name=ann.
		$_GET = [ 'bfrc_writer' => '1', 'author_name' => 'ann' ];
		try {
			$this->templates->guard();
			$this->fail( 'expected a redirect' );
		} catch ( \BfrcTestExit $e ) {
			$this->assertSame( [ 'redirect', 'https://example.com/recipes/chef/ann/', 301 ], [ $e->kind, $e->payload, $e->status ] );
		}
		$_GET = [ 'bfrc_writers' => '1', 'paged' => '2' ];
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 2 ] );
		$this->with_settings( [] );
		for ( $i = 1; $i <= 30; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'W' . $i, 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		try {
			$this->templates->guard();
			$this->fail( 'expected a redirect' );
		} catch ( \BfrcTestExit $e ) {
			$this->assertSame( 'https://example.com/recipes/chef/page/2/', $e->payload, 'the page survives the redirect' );
		}
		$feed          = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann', 'feed' => 'rss2' ], 'author' );
		$feed->is_feed = true;
		$_GET          = [ 'bfrc_writer' => '1', 'feed' => 'rss2' ];
		$this->templates->guard();
		$this->assertFalse( $feed->is_404(), 'a pretty feed is never redirected (its URL is core\'s)' );
		$_GET = [ 'author_name' => 'ann' ]; // /recipes/chef/someone-else/feed/?author_name=ann would carry Ann's items at the other writer's address.
		try {
			$this->templates->guard();
			$this->fail( 'expected a redirect' );
		} catch ( \BfrcTestExit $e ) {
			$this->assertSame( 'https://example.com/recipes/chef/ann/feed/', $e->payload, 'the override goes to the resolved writer\'s own feed' );
		}
		$atom          = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann', 'feed' => 'atom', 'paged' => 2 ], 'author' );
		$atom->is_feed = true;
		$_GET          = [ 'author_name' => 'ann', 'paged' => '2' ];
		try {
			$this->templates->guard();
			$this->fail( 'expected a redirect' );
		} catch ( \BfrcTestExit $e ) {
			$this->assertSame( 'https://example.com/recipes/chef/ann/feed/atom/', $e->payload, 'the format is kept, the page segment is not (no feed rule serves /page/N/, core paginates feeds by query argument)' );
		}
		foreach ( [ '../../wp-login.php', 'junk', 'rss2' ] as $bad ) {
			$f          = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann', 'feed' => $bad ], 'author' );
			$f->is_feed = true;
			$_GET       = [ 'author_name' => 'ann', 'feed' => $bad ];
			try {
				$this->templates->guard();
				$this->fail( 'expected a redirect' );
			} catch ( \BfrcTestExit $e ) {
				$this->assertSame( 'https://example.com/recipes/chef/ann/feed/', $e->payload, "feed=$bad: only a format core serves goes into the Location; the default and anything else mean the plain feed" );
			}
		}
		$GLOBALS['wp_rewrite']->permalink_structure = '';
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$_GET = [ 'bfrc_writer' => '1', 'author_name' => 'ann' ];
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404(), 'plain permalinks: the query string is the only URL, so it stands' );
		$GLOBALS['wp_rewrite']->permalink_structure = '/%postname%/';
		$_GET = [];

		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		ob_start();
		$t->rel_canonical();
		$this->assertSame( '<link rel="canonical" href="https://example.com/recipes/chef/ann/">' . "\n", ob_get_clean() );
		$silence = static fn(): string => '';
		add_filter( 'bfrc_writer_canonical', $silence );
		ob_start();
		$t->rel_canonical();
		$this->assertSame( '', ob_get_clean(), 'an SEO plugin may take the canonical over' );
		remove_filter( 'bfrc_writer_canonical', $silence );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		ob_start();
		$t->rel_canonical();
		$this->assertSame( '', ob_get_clean(), 'nothing on the archive' );
		$gone = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$gone->set_404();
		ob_start();
		$t->rel_canonical();
		$this->assertSame( '', ob_get_clean(), 'nothing on a 404' );
	}

	public function test_template_include_keeps_the_writer_views_off_a_themes_own_recipe_archive_template(): void {
		$t = $this->templates;
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->assertSame( BFRC_DIR . 'templates/archive-recipe.php', $t->template_include( '/theme/archive-bfrc_recipe.php' ), 'the listing is not a recipe archive: the theme\'s archive template would loop one recipe' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->assertSame( BFRC_DIR . 'templates/archive-recipe.php', $t->template_include( '/theme/archive-bfrc_recipe.php' ) );
		$GLOBALS['bfrc_test_theme_files'] = [ 'bluefairy-recipes/archive-recipe.php' ];
		$this->assertSame( '/theme/bluefairy-recipes/archive-recipe.php', $t->template_include( '/theme/archive-bfrc_recipe.php' ), 'the plugin-aware override still wins: it calls the Renderer' );
	}

	public function test_register_adds_the_routes_the_view_blocks_and_the_two_block_templates(): void {
		$this->templates->register();
		$this->assertCount( 6, $GLOBALS['bfrc_test_rewrite_rules'] );
		$this->assertSame( [ 'bfrc/recipe-single', 'bfrc/recipe-archive' ], array_keys( $GLOBALS['bfrc_test_blocks'] ) );
		$this->assertSame( [], $GLOBALS['bfrc_test_registered'], 'nothing registered at init: that builds WP_Scripts on sitemaps, feeds and redirects' );
		Templates::register_assets();
		$this->assertStringEndsWith( 'assets/front/recipes.css', $GLOBALS['bfrc_test_registered']['style']['bfrc-recipes'], 'registered on enqueue_block_assets so the editor_style handle resolves' );
		$this->assertStringEndsWith( 'assets/front/recipes.js', $GLOBALS['bfrc_test_registered']['script']['bfrc-recipes'] );
		foreach ( $GLOBALS['bfrc_test_blocks'] as $name => $args ) {
			$this->assertTrue( is_callable( $args['render_callback'] ), $name );
			$this->assertTrue( $args['supports']['autoRegister'], "$name: the Site Editor gets a server-rendered preview on WordPress 7.0+" );
			$this->assertSame( '3', $args['api_version'], "$name: block API v3 (core documents the register arg as a string)" );
			$this->assertArrayNotHasKey( 'style', $args, "$name: a block 'style' handle is enqueued on EVERY page of a classic theme; the front end enqueues when a view renders" );
			$this->assertSame( 'bfrc-recipes', $args['editor_style'], "$name: styled in the Site Editor's canvas" );
			$this->assertSame( [ 'type' => 'string', 'default' => '' ], $args['attributes']['view'] );
		}
		$templates = $GLOBALS['bfrc_test_block_templates'];
		$this->assertSame( [ 'bluefairy-recipes//single-bfrc_recipe', 'bluefairy-recipes//archive-bfrc_recipe', 'bluefairy-recipes//bfrc-writers' ], array_keys( $templates ) );
		$this->assertStringContainsString( '<!-- wp:bfrc/recipe-archive {"view":"writers"} /-->', $templates['bluefairy-recipes//bfrc-writers']['content'], 'the writer views: their own slug (a theme\'s archive-bfrc_recipe.html does not catch them) and a preview of the writers list' );
		$this->assertStringContainsString( '<!-- wp:template-part {"slug":"header"', $templates['bluefairy-recipes//bfrc-writers']['content'] );
		$this->assertStringContainsString( '<!-- wp:bfrc/recipe-single /-->', $templates['bluefairy-recipes//single-bfrc_recipe']['content'] );
		$this->assertStringContainsString( '<!-- wp:template-part {"slug":"header"', $templates['bluefairy-recipes//archive-bfrc_recipe']['content'] );
		$this->assertStringContainsString( '<!-- wp:bfrc/recipe-archive /-->', $templates['bluefairy-recipes//archive-bfrc_recipe']['content'] );
		$this->assertSame( [ 'bfrc_recipe' ], $templates['bluefairy-recipes//single-bfrc_recipe']['post_types'] );
		$this->assertSame( '', Templates::template_markup( 'no-such-template' ), 'a missing file is an empty template, not a warning' );
	}

	public function test_query_vars_adds_the_writer_vars_and_the_search_var_but_not_the_filter_array(): void {
		$this->assertSame( [ 'p', 'bfrc_writer', 'bfrc_writers', 'bfrc_s' ], $this->templates->query_vars( [ 'p' ] ) );
	}

	public function test_is_recipe_query_recognises_every_public_view_and_nothing_else(): void {
		$t = $this->templates;
		$this->assertTrue( $t->is_recipe_query( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ) );
		$single                 = bfrc_test_front_query( [], 'single' );
		$single->queried_object = get_post( 41 );
		$this->assertTrue( $t->is_recipe_query( $single ) );
		$post                 = bfrc_test_front_query( [], 'single' );
		$post->queried_object = bfrc_test_add_post( [ 'ID' => 50, 'post_type' => 'post' ] );
		$this->assertFalse( $t->is_recipe_query( $post ), 'a plain post' );
		$tax                 = bfrc_test_front_query( [], 'tax' );
		$tax->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan' ] );
		$this->assertTrue( $t->is_recipe_query( $tax ) );
		$cat                 = bfrc_test_front_query( [], 'tax' );
		$cat->queried_object = new \WP_Term( [ 'term_id' => 4, 'taxonomy' => 'category', 'slug' => 'news', 'name' => 'News' ] );
		$this->assertFalse( $t->is_recipe_query( $cat ), 'another taxonomy' );
		$this->assertTrue( $t->is_recipe_query( new \WP_Query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] ) ) );
		$this->assertTrue( $t->is_recipe_query( new \WP_Query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ] ) ) );
		$this->assertFalse( $t->is_recipe_query( new \WP_Query( [ 'bfrc_writers' => '1' ] ) ), 'a bare writer var on another URL (the home page) is not ours' );
		$this->assertFalse( $t->is_recipe_query( new \WP_Query( [ 'post_type' => 'post', 'bfrc_writer' => '1', 'author_name' => 'ann' ] ) ) );
		$this->assertFalse( $t->is_recipe_query( new \WP_Query( [ 'post_type' => 'bfrc_recipe' ], false ) ), 'a secondary query' );
		$this->with_settings( [ 'enabled' => false ] );
		$this->assertFalse( $t->is_recipe_query( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ), 'disabled: no public views' );
		$this->with_settings( [ 'enabled' => true ] );
		$GLOBALS['bfrc_test_is_admin'] = true;
		$this->assertFalse( $t->is_recipe_query( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ), 'admin' );
	}

	public function test_with_every_taxonomy_disabled_other_plugins_term_pages_are_not_recipe_views(): void {
		$taxonomies = [];
		foreach ( [ 'meal_type', 'diet', 'season', 'cuisine' ] as $code ) {
			$taxonomies[ $code ] = [ 'enabled' => false, 'sort_order' => 10 ];
		}
		$this->with_settings( [ 'taxonomies' => $taxonomies ] );
		$t = new Templates( $this->settings, $this->writers, new Renderer( $this->settings, $this->writers, new Taxonomies( $this->settings ) ), new Taxonomies( $this->settings ) );
		$this->assertSame( [], $t->taxonomy_names() );
		$shop                 = bfrc_test_front_query( [], 'tax' );
		$shop->queried_object = new \WP_Term( [ 'term_id' => 9, 'taxonomy' => 'product_cat', 'slug' => 'hats', 'name' => 'Hats' ] );
		$this->assertFalse( $t->is_recipe_query( $shop ), 'core reads is_tax( [] ) as "any taxonomy"; a shop category must stay a shop category' );
	}

	public function test_shape_query_sets_per_page_title_only_search_and_the_filter_tax_query(): void {
		$this->with_settings( [ 'per_page' => 9 ] );
		$_GET = [ 'bfrc_s' => ' <b>soup</b> ', 'bfrc_filter' => [ 'diet' => 'Vegan Food', 'season' => '0', 'cuisine' => [ 'x' ], 'nope' => 'y' ], 'bfrc_meal_type' => 'lunch' ];
		$seen = null;
		add_action( 'bfrc_archive_query', static function ( \WP_Query $q ) use ( &$seen ): void {
			$seen = $q->get( 'posts_per_page' );
		} );
		$q = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 9, $q->get( 'posts_per_page' ) );
		$this->assertSame( 'soup', $q->get( 's' ) );
		$this->assertSame( [ 'post_title' ], $q->get( 'search_columns' ), 'title only, like Magento' );
		$this->assertSame( [ [ 'taxonomy' => 'bfrc_diet', 'field' => 'slug', 'terms' => [ 'vegan-food' ] ] ], $q->get( 'tax_query' ), '0, arrays and unknown codes are no filter; a taxonomy query var outside bfrc_filter[] is core\'s business' );
		$this->assertSame( 9, $seen, 'the seam fires after shaping' );
		$_GET = [ 'bfrc_s' => str_repeat( 'a', 150 ) ];
		$q    = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 100, strlen( $q->get( 's' ) ), 'keyword capped' );
	}

	public function test_shape_query_skips_the_term_already_in_the_url_and_merges_an_existing_tax_query(): void {
		$_GET              = [ 'bfrc_filter' => [ 'diet' => 'vegan', 'meal_type' => 'lunch' ] ];
		$q                 = bfrc_test_front_query( [ 'tax_query' => [ [ 'taxonomy' => 'other', 'field' => 'slug', 'terms' => [ 'x' ] ] ] ], 'tax' );
		$q->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan' ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 'bfrc_recipe', $q->get( 'post_type' ) );
		$this->assertSame( [ 'other', 'bfrc_meal_type' ], array_column( $q->get( 'tax_query' ), 'taxonomy' ), 'the URL\'s own taxonomy is not repeated' );
	}

	public function test_shape_query_keeps_the_writers_listing_query_to_one_row_per_page_and_leaves_other_queries_alone(): void {
		$q = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 3 ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 1, $q->get( 'posts_per_page' ) );
		$this->assertSame( 3, $q->get( 'paged' ), 'paged stays: the listing reads it and core must see a real page' );
		$this->assertTrue( $q->get( 'no_found_rows' ), 'no SQL_CALC_FOUND_ROWS: the page count comes from Writers::listed()' );
		$this->assertFalse( $q->get( 'update_post_meta_cache' ), 'the one vehicle recipe is never rendered' );
		$this->assertFalse( $q->get( 'update_post_term_cache' ) );
		$single                 = bfrc_test_front_query( [], 'single' );
		$single->queried_object = get_post( 41 );
		$this->templates->shape_query( $single );
		$this->assertSame( '', $single->get( 'posts_per_page' ), 'a single recipe is not reshaped' );
		$blog = bfrc_test_front_query( [ 'post_type' => 'post' ] );
		$this->templates->shape_query( $blog );
		$this->assertSame( 'post', $blog->get( 'post_type' ) );
	}

	public function test_guard_404s_an_unknown_writer_the_listing_when_off_or_past_its_last_page_and_everything_when_disabled(): void {
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404(), 'Ann has a published recipe and is listed' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'bob' ], 'author' );
		$this->templates->guard();
		$this->assertTrue( $GLOBALS['wp_query']->is_404(), 'Bob has no published recipe' );
		$this->assertSame( [ 404 ], $GLOBALS['bfrc_test_status_headers'] );
		$this->assertTrue( $GLOBALS['bfrc_test_nocache'] );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404() );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 2 ] );
		$this->templates->guard();
		$this->assertTrue( $GLOBALS['wp_query']->is_404(), 'one writer page only: page 2 is a 404, not an empty 200' );
		$this->with_settings( [ 'writers_listing' => false ] );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->templates->guard();
		$this->assertTrue( $GLOBALS['wp_query']->is_404(), 'listing switched off' );
		$this->with_settings( [ 'writers_listing' => true, 'enabled' => false ] );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->templates->guard();
		$this->assertTrue( $GLOBALS['wp_query']->is_404(), 'disabled: even Ann\'s page' );
		$this->with_settings( [ 'enabled' => true ] );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404(), 'the plain archive is never touched' );
	}

	public function test_template_include_returns_the_plugin_template_in_a_classic_theme_unless_the_theme_overrides(): void {
		$t = $this->templates;
		bfrc_test_front_query( [ 'post_type' => 'post' ] );
		$this->assertSame( '/theme/index.php', $t->template_include( '/theme/index.php' ), 'not ours' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( BFRC_DIR . 'templates/archive-recipe.php', $t->template_include( '/theme/archive.php' ) );
		$this->assertSame( '/theme/archive-bfrc_recipe.php', $t->template_include( '/theme/archive-bfrc_recipe.php' ), 'the theme\'s own recipe archive template wins' );
		$tax                 = bfrc_test_front_query( [], 'tax' );
		$tax->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan' ] );
		$this->assertSame( '/theme/taxonomy-bfrc_diet.php', $t->template_include( '/theme/taxonomy-bfrc_diet.php' ) );
		$this->assertSame( BFRC_DIR . 'templates/archive-recipe.php', $t->template_include( '/theme/taxonomy.php' ), 'a generic taxonomy template is not specific enough' );
		$single                 = bfrc_test_front_query( [], 'single' );
		$single->queried_object = get_post( 41 );
		$this->assertSame( BFRC_DIR . 'templates/single-recipe.php', $t->template_include( '/theme/single.php' ) );
		$this->assertSame( '/theme/single-bfrc_recipe.php', $t->template_include( '/theme/single-bfrc_recipe.php' ) );
		$GLOBALS['bfrc_test_theme_files'] = [ 'bluefairy-recipes/single-recipe.php' ];
		$this->assertSame( '/theme/bluefairy-recipes/single-recipe.php', $t->template_include( '/theme/single.php' ), 'the theme\'s override wins' );
		$this->assertSame( BFRC_DIR . 'templates/archive-recipe.php', Templates::locate( 'archive-recipe' ) );
		$this->assertSame( '/theme/bluefairy-recipes/single-recipe.php', Templates::locate( 'single-recipe' ), 'locate() prefers the theme file' );
		$single->set_404();
		$this->assertSame( '/theme/404.php', $t->template_include( '/theme/404.php' ), 'a 404 keeps core\'s template' );
		$GLOBALS['bfrc_test_block_theme'] = true;
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( '/theme/archive.html', $t->template_include( '/theme/archive.html' ), 'block theme: the registered block templates apply through the hierarchy' );
		$this->assertTrue( Templates::is_theme_recipe_template( '/x/taxonomy-bfrc_season.php' ) );
		$this->assertFalse( Templates::is_theme_recipe_template( '/x/taxonomy-category.php' ) );
		$this->assertFileExists( BFRC_DIR . 'templates/archive-recipe.php' );
		$this->assertFileExists( BFRC_DIR . 'templates/single-recipe.php' );
	}

	public function test_archive_hierarchy_prepends_the_recipe_archive_for_recipe_list_views_only(): void {
		$t                   = $this->templates;
		$tax                 = bfrc_test_front_query( [], 'tax' );
		$tax->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan' ] );
		$this->assertSame( [ 'archive-bfrc_recipe', 'taxonomy-bfrc_diet-vegan', 'taxonomy-bfrc_diet', 'taxonomy' ], $t->archive_hierarchy( [ 'taxonomy-bfrc_diet-vegan', 'taxonomy-bfrc_diet', 'taxonomy' ] ) );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->assertSame( [ 'bfrc-writers', 'archive-bfrc_recipe', 'author-ann', 'author' ], $t->archive_hierarchy( [ 'author-ann', 'author' ] ), 'a writer page: the writers template first' );
		$list = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->assertTrue( Templates::is_writer_view( $list ) );
		$this->assertSame( [ 'bfrc-writers', 'archive-bfrc_recipe', 'archive' ], $t->archive_hierarchy( [ 'archive' ] ) );
		$plain = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertFalse( Templates::is_writer_view( $plain ) );
		$this->assertSame( [ 'archive-bfrc_recipe', 'archive-bfrc_recipe', 'archive' ], $t->archive_hierarchy( [ 'archive-bfrc_recipe', 'archive' ] ), 'the archive itself: no writers template' );
		$cat                 = bfrc_test_front_query( [], 'tax' );
		$cat->queried_object = new \WP_Term( [ 'term_id' => 4, 'taxonomy' => 'category', 'slug' => 'news', 'name' => 'News' ] );
		$this->assertSame( [ 'category-news', 'category' ], $t->archive_hierarchy( [ 'category-news', 'category' ] ), 'another taxonomy: untouched' );
		$single                 = bfrc_test_front_query( [], 'single' );
		$single->queried_object = get_post( 41 );
		$this->assertSame( [ 'single' ], $t->archive_hierarchy( [ 'single' ] ) );
	}

	public function test_enqueue_body_class_and_comments_on_recipe_views_only(): void {
		$t = $this->templates;
		bfrc_test_front_query( [ 'post_type' => 'post' ] );
		$t->enqueue();
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued'] );
		$this->assertSame( [ 'home' ], $t->body_class( [ 'home' ] ) );
		$gone = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'bob' ], 'author' );
		$gone->set_404();
		$t->enqueue();
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued'], 'a 404 loads nothing' );
		$this->assertSame( [ 'error404' ], $t->body_class( [ 'error404' ] ) );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->enqueue();
		$this->assertSame( [ 'bfrc-recipes' ], $GLOBALS['bfrc_test_enqueued']['style'] );
		Templates::enqueue_assets();
		$this->assertSame( [ 'bfrc-recipes', 'bfrc-recipes' ], $GLOBALS['bfrc_test_enqueued']['style'], 'blocks and shortcodes call enqueue_assets() directly' );
		$this->assertSame( [ 'bfrc-recipes', 'bfrc-recipes' ], $GLOBALS['bfrc_test_enqueued']['script'] );
		$this->assertSame( [ 'archive', 'bfrc-recipes' ], $t->body_class( [ 'archive' ] ) );
		$this->assertFalse( $t->comments_closed( true, 41 ), 'recipes take no comments' );
		bfrc_test_add_post( [ 'ID' => 50, 'post_type' => 'post' ] );
		$this->assertTrue( $t->comments_closed( true, 50 ) );
		$this->assertFalse( $t->comments_closed( false, 50 ), 'another post type keeps whatever it had' );
	}

	public function test_document_title_names_the_writer_pages_on_recipe_views_only(): void {
		$t = $this->templates;
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( [ 'title' => 'Recipes' ], $t->document_title( [ 'title' => 'Recipes' ] ), 'the archive keeps core\'s title' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->assertSame( [ 'title' => 'Recipe writers' ], $t->document_title( [ 'title' => 'Recipes' ] ) );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->assertSame( [ 'title' => 'Ann' ], $t->document_title( [ 'title' => 'Recipes' ] ) );
		$blog = bfrc_test_front_query( [ 'post_type' => 'post', 'bfrc_writers' => '1' ] );
		$this->assertSame( [ 'title' => 'Blog' ], $t->document_title( [ 'title' => 'Blog' ] ), 'a stray writer var on the blog does not retitle it' );
		$q = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'bob' ], 'author' );
		$q->set_404();
		$this->assertSame( [ 'title' => 'Not found' ], $t->document_title( [ 'title' => 'Not found' ] ), 'a 404 keeps its title' );
	}

	public function test_view_blocks_render_from_the_main_query_and_enqueue_the_assets(): void {
		$t                                   = $this->templates;
		$GLOBALS['post']                     = get_post( 41 );
		$single                              = bfrc_test_front_query( [], 'single' );
		$single->queried_object              = get_post( 41 );
		$html                                = $t->render_single_block();
		$this->assertStringContainsString( 'class="bfrc-recipe__title">Soup<', $html );
		$this->assertSame( [ 'bfrc-recipes' ], $GLOBALS['bfrc_test_enqueued']['style'] );
		$GLOBALS['post'] = bfrc_test_add_post( [ 'ID' => 50, 'post_type' => 'post' ] );
		$this->assertSame( '', $t->render_single_block(), 'not a recipe' );
		$this->assertFalse( Templates::is_editor_preview() );
		$GLOBALS['post'] = get_post( 41 );
		$feed            = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'feed' => 'rss2' ] );
		$feed->is_feed   = true;
		$this->assertSame( '', $t->render_single_block(), 'a feed item never carries a whole recipe page' );
		$feed->posts = [ get_post( 41 ) ];
		$this->assertSame( '', $t->render_archive_block(), 'nor a grid' );
		$recipe_page                 = bfrc_test_front_query( [], 'single' );
		$recipe_page->queried_object = get_post( 41 );
		$recipe_page->posts          = [ get_post( 41 ) ];
		$this->assertSame( '', $t->render_archive_block(), 'the archive block belongs to list views: on a recipe page it would be a one-card grid of that recipe' );
		$list        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$list->posts = [ get_post( 41 ) ];
		$GLOBALS['bfrc_test_doing_filter'] = [ 'the_content' ];
		$this->assertSame( '', $t->render_archive_block(), 'inside a post body rendered on the archive (a Query Loop with a Post Content block in the template): a pasted archive block renders no second grid' );
		$GLOBALS['bfrc_test_doing_filter'] = [];
		$this->assertStringContainsString( 'bfrc-grid__item', $t->render_archive_block(), 'the template itself still gets the grid' );
		$recipe_page->main = false;
		$this->assertSame( '', $t->render_single_block(), 'a secondary query is not the page: the single block renders from the main query only' );
		$page                 = bfrc_test_front_query( [], 'single' );
		$page->queried_object = bfrc_test_add_post( [ 'ID' => 51, 'post_type' => 'page', 'post_title' => 'About' ] );
		$page->posts          = [ get_post( 51 ) ];
		$this->assertSame( '', $t->render_archive_block(), 'the archive block on a page does not list the page as a recipe card' );
		$GLOBALS['post'] = get_post( 51 );
		$this->assertSame( '', $t->render_single_block() );
		$GLOBALS['post'] = get_post( 50 ); // Not a recipe: the editor has no recipe in context.
		$GLOBALS['bfrc_test_serving_rest'] = true;
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/pages/5';
		$this->assertFalse( Templates::is_editor_preview(), 'a page\'s content.rendered over REST renders as on the site' );
		$this->assertSame( '', $t->render_single_block() );
		$GLOBALS['post']                   = get_post( 41 );
		$rest_single                       = bfrc_test_front_query( [], 'single' );
		$rest_single->queried_object       = get_post( 41 );
		$this->assertSame( '', $t->render_single_block(), 'even with the recipe in context, a REST item\'s content.rendered never carries the recipe page' );
		$GLOBALS['post'] = get_post( 50 );
		$rest_single->posts = null; // As core: no main query ran for a REST request.
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/block-renderer/bfrc/recipe-archive';
		$this->assertStringContainsString( 'bfrc-grid__item', $t->render_archive_block(), 'a null posts list is an empty editor query: previewed on a clone' );
		$this->assertNull( $rest_single->posts, 'the clone was filled, not the request\'s query' );
		$GLOBALS['bfrc_test_get_posts_calls']    = []; // The single-preview assertions below index the calls from here.
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/pages/5';
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/block-renderer/bfrc/recipe-single';
		$this->assertTrue( Templates::is_editor_preview(), 'the block-renderer route is the Site Editor asking' );
		$this->assertStringContainsString( 'class="bfrc-recipe__title">Soup<', $t->render_single_block(), 'the Site Editor\'s block-renderer request has no recipe in context: preview the newest published one' );
		$this->assertSame( 'publish', $GLOBALS['bfrc_test_get_posts_calls'][0]['post_status'] );
		$this->assertSame( 1, $GLOBALS['bfrc_test_get_posts_calls'][0]['numberposts'] );
		$GLOBALS['bfrc_test_posts'][41]->post_status = 'draft';
		$this->assertStringContainsString( '<article class="bfrc-recipe bfrc-recipe--placeholder"><p>The recipe shows here', $t->render_single_block(), 'no published recipe yet: say what the block is, never an empty canvas' );
		$GLOBALS['bfrc_test_serving_rest'] = false;
		bfrc_test_front_query( [], 'single' )->queried_object = get_post( 50 );
		$this->assertSame( '', $t->render_single_block(), 'a visitor\'s page never previews another recipe' );
		$GLOBALS['bfrc_test_posts'][41]->post_status = 'publish';
		$q        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$q->posts = [ get_post( 41 ) ];
		$this->assertStringContainsString( 'bfrc-grid', $t->render_archive_block() );
		$empty = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertStringContainsString( 'No recipes match', $t->render_archive_block(), 'a visitor\'s empty result stays empty' );
		$GLOBALS['bfrc_test_serving_rest'] = true;
		$this->assertStringContainsString( 'bfrc-grid__item', $t->render_archive_block(), 'the Site Editor previews the newest recipes in the grid' );
		$this->assertSame( 12, end( $GLOBALS['bfrc_test_get_posts_calls'] )['numberposts'], 'one page, per the setting' );
		$this->assertSame( [], $empty->posts, 'on a copy: the request\'s own query is left alone' );
		$writers = $t->render_archive_block( [ 'view' => 'writers' ] );
		$this->assertStringContainsString( 'Recipe writers</h1>', $writers, 'the Recipe Writers template previews the writers list' );
		$this->assertStringNotContainsString( 'bfrc-filters', $writers );
		$GLOBALS['bfrc_test_serving_rest'] = false;
		$this->assertStringContainsString( 'No recipes match', $t->render_archive_block( [ 'view' => 'writers' ] ), 'on the site the query decides, never the attribute' );
		$GLOBALS['bfrc_test_serving_rest'] = true;
		$full        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$full->posts = [ get_post( 41 ) ];
		$calls       = count( $GLOBALS['bfrc_test_get_posts_calls'] );
		$this->assertStringContainsString( 'bfrc-grid__item', $t->render_archive_block() );
		$this->assertSame( $calls, count( $GLOBALS['bfrc_test_get_posts_calls'] ), 'a query with posts is not refilled' );
		foreach ( $GLOBALS['bfrc_test_get_posts_calls'] as $args ) {
			$this->assertFalse( $args['has_password'], 'a preview is never a password form' );
		}
		bfrc_test_add_post( [ 'ID' => 60, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_title' => 'Locked', 'post_password' => 'x', 'post_date_gmt' => '2030-01-01 00:00:00' ] );
		$GLOBALS['post'] = bfrc_test_add_post( [ 'ID' => 50, 'post_type' => 'post' ] );
		$this->assertStringContainsString( 'class="bfrc-recipe__title">Soup<', $t->render_single_block(), 'the newest recipe is locked: preview the newest open one' );
		$GLOBALS['bfrc_test_serving_rest'] = false;
	}

	public function test_requested_filters_and_search_read_only_strings_under_the_filter_array(): void {
		$_GET = [ 'bfrc_s' => [ 'x' ], 'bfrc_filter' => [ 'meal_type' => 'Dinner Party', 'diet' => '', 'season' => '0', 'cuisine' => [ 'a' ] ], 'bfrc_meal_type' => 'lunch' ];
		$this->assertSame( '', $this->templates->requested_search() );
		$this->assertSame( [ 'bfrc_meal_type' => 'dinner-party' ], $this->templates->requested_filters() );
		$_GET = [ 'bfrc_filter' => 'junk' ];
		$this->assertSame( [], $this->templates->requested_filters() );
		$this->assertSame( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ], $this->templates->taxonomy_names() );
		$this->assertSame( 12, $this->templates->per_page() );
	}

	public function test_classic_templates_render_through_the_renderer(): void {
		$q        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$q->posts = [ get_post( 41 ) ];
		$html     = bfrc_test_render( static function (): void {
			include BFRC_DIR . 'templates/archive-recipe.php';
		} );
		$this->assertStringContainsString( '<!-- header --><main id="bfrc-main"', $html );
		$this->assertStringContainsString( 'bfrc-card__title', $html );
		$this->assertStringEndsWith( '<!-- footer -->', $html );
		$single                 = bfrc_test_front_query( [], 'single' );
		$single->posts          = [ get_post( 41 ) ];
		$single->queried_object = get_post( 41 );
		$html                   = bfrc_test_render( static function (): void {
			include BFRC_DIR . 'templates/single-recipe.php';
		} );
		$this->assertStringContainsString( 'class="bfrc-recipe__title">Soup<', $html );
	}
}
