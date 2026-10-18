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
		$this->assertSame( 10, has_filter( 'wp_sitemaps_users_query_args', [ $this->writers, 'sitemap_users_query' ] ), 'opted-out writers leave core\'s users sitemap too' );
		$this->assertSame( 10, has_action( 'pre_user_query', [ $this->writers, 'exclude_opted_out' ] ), 'the NOT EXISTS clause behind the listing and the sitemap' );
		$this->assertSame( 10, has_action( 'wp_head', [ $t, 'rel_canonical' ] ) );
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
		$GLOBALS['bfrc_test_options']['permalink_structure'] = '/%postname%/';
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
		$_GET          = [ 'bfrc_writer' => '1', 'author_name' => 'ann', 'feed' => 'rss2' ];
		$this->templates->guard();
		$this->assertFalse( $feed->is_404(), 'a feed is never redirected' );
		$GLOBALS['bfrc_test_options']['permalink_structure'] = '';
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$_GET = [ 'bfrc_writer' => '1', 'author_name' => 'ann' ];
		$this->templates->guard();
		$this->assertFalse( $GLOBALS['wp_query']->is_404(), 'plain permalinks: the query string is the only URL, so it stands' );
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
		foreach ( $GLOBALS['bfrc_test_blocks'] as $name => $args ) {
			$this->assertTrue( is_callable( $args['render_callback'] ), $name );
			$this->assertTrue( $args['supports']['autoRegister'], "$name: the Site Editor gets a server-rendered preview on WordPress 7.0+" );
			$this->assertSame( '3', $args['api_version'], "$name: block API v3 (core documents the register arg as a string)" );
		}
		$templates = $GLOBALS['bfrc_test_block_templates'];
		$this->assertSame( [ 'bluefairy-recipes//single-bfrc_recipe', 'bluefairy-recipes//archive-bfrc_recipe' ], array_keys( $templates ) );
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
		$this->assertSame( [ 'archive-bfrc_recipe', 'author-ann', 'author' ], $t->archive_hierarchy( [ 'author-ann', 'author' ] ) );
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
		$html                                = $t->render_single_block();
		$this->assertStringContainsString( 'class="bfrc-recipe__title">Soup<', $html );
		$this->assertSame( [ 'bfrc-recipes' ], $GLOBALS['bfrc_test_enqueued']['style'] );
		$GLOBALS['post'] = bfrc_test_add_post( [ 'ID' => 50, 'post_type' => 'post' ] );
		$this->assertSame( '', $t->render_single_block(), 'not a recipe' );
		$this->assertFalse( Templates::is_editor_preview() );
		$GLOBALS['bfrc_test_serving_rest'] = true;
		$this->assertTrue( Templates::is_editor_preview(), 'the block-renderer route is a REST request' );
		$this->assertStringContainsString( 'class="bfrc-recipe__title">Soup<', $t->render_single_block(), 'the Site Editor\'s block-renderer request has no recipe in context: preview the newest published one' );
		$this->assertSame( 'publish', $GLOBALS['bfrc_test_get_posts_calls'][0]['post_status'] );
		$this->assertSame( 1, $GLOBALS['bfrc_test_get_posts_calls'][0]['numberposts'] );
		$GLOBALS['bfrc_test_posts'][41]->post_status = 'draft';
		$this->assertStringContainsString( '<article class="bfrc-recipe bfrc-recipe--placeholder"><p>The recipe shows here', $t->render_single_block(), 'no published recipe yet: say what the block is, never an empty canvas' );
		$GLOBALS['bfrc_test_serving_rest'] = false;
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
		$this->assertSame( [ get_post( 41 ) ], $empty->posts );
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
