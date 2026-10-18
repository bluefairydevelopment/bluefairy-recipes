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
	}

	public function test_register_adds_the_routes_the_view_blocks_and_the_two_block_templates(): void {
		$this->templates->register();
		$this->assertCount( 4, $GLOBALS['bfrc_test_rewrite_rules'] );
		$this->assertSame( [ 'bfrc/recipe-single', 'bfrc/recipe-archive' ], array_keys( $GLOBALS['bfrc_test_blocks'] ) );
		$this->assertTrue( is_callable( $GLOBALS['bfrc_test_blocks']['bfrc/recipe-archive']['render_callback'] ) );
		$templates = $GLOBALS['bfrc_test_block_templates'];
		$this->assertSame( [ 'bluefairy-recipes//single-bfrc_recipe', 'bluefairy-recipes//archive-bfrc_recipe' ], array_keys( $templates ) );
		$this->assertStringContainsString( '<!-- wp:bfrc/recipe-single /-->', $templates['bluefairy-recipes//single-bfrc_recipe']['content'] );
		$this->assertStringContainsString( '<!-- wp:template-part {"slug":"header"', $templates['bluefairy-recipes//archive-bfrc_recipe']['content'] );
		$this->assertStringContainsString( '<!-- wp:bfrc/recipe-archive /-->', $templates['bluefairy-recipes//archive-bfrc_recipe']['content'] );
		$this->assertSame( [ 'bfrc_recipe' ], $templates['bluefairy-recipes//single-bfrc_recipe']['post_types'] );
	}

	public function test_query_vars_adds_the_writer_vars_and_the_search_var(): void {
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
		$this->assertTrue( $t->is_recipe_query( new \WP_Query( [ 'bfrc_writers' => '1' ] ) ) );
		$this->assertTrue( $t->is_recipe_query( new \WP_Query( [ 'bfrc_writer' => '1', 'author_name' => 'ann' ] ) ) );
		$this->assertFalse( $t->is_recipe_query( new \WP_Query( [ 'post_type' => 'bfrc_recipe' ], false ) ), 'a secondary query' );
		$GLOBALS['bfrc_test_is_admin'] = true;
		$this->assertFalse( $t->is_recipe_query( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ), 'admin' );
	}

	public function test_shape_query_sets_per_page_title_only_search_and_the_filter_tax_query(): void {
		$this->settings->update( [ 'per_page' => 9 ] + $this->settings->all() );
		$_GET = [ 'bfrc_s' => ' <b>soup</b> ', 'bfrc_diet' => 'Vegan Food', 'bfrc_season' => '0', 'bfrc_cuisine' => [ 'x' ], 'bfrc_nope' => 'y' ];
		$seen = null;
		add_action( 'bfrc_archive_query', static function ( \WP_Query $q ) use ( &$seen ): void {
			$seen = $q->get( 'posts_per_page' );
		} );
		$q = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 9, $q->get( 'posts_per_page' ) );
		$this->assertTrue( $q->get( 'ignore_sticky_posts' ) );
		$this->assertSame( 'soup', $q->get( 's' ) );
		$this->assertSame( [ 'post_title' ], $q->get( 'search_columns' ), 'title only, like Magento' );
		$this->assertSame( [ [ 'taxonomy' => 'bfrc_diet', 'field' => 'slug', 'terms' => [ 'vegan-food' ] ] ], $q->get( 'tax_query' ), '0 and an array are no filter; unknown parameters are ignored' );
		$this->assertSame( 9, $seen, 'the seam fires after shaping' );
		$_GET = [ 'bfrc_s' => str_repeat( 'a', 150 ) ];
		$q    = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 100, strlen( $q->get( 's' ) ), 'keyword capped' );
	}

	public function test_shape_query_skips_the_term_already_in_the_url_and_merges_an_existing_tax_query(): void {
		$_GET                = [ 'bfrc_diet' => 'vegan', 'bfrc_meal_type' => 'lunch' ];
		$q                   = bfrc_test_front_query( [ 'tax_query' => [ [ 'taxonomy' => 'other', 'field' => 'slug', 'terms' => [ 'x' ] ] ] ], 'tax' );
		$q->queried_object   = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan' ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 'bfrc_recipe', $q->get( 'post_type' ) );
		$this->assertSame( [ 'other', 'bfrc_meal_type' ], array_column( $q->get( 'tax_query' ), 'taxonomy' ), 'the URL\'s own taxonomy is not repeated' );
	}

	public function test_shape_query_keeps_the_writers_listing_query_to_one_row_and_leaves_other_queries_alone(): void {
		$q = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 3 ] );
		$this->templates->shape_query( $q );
		$this->assertSame( 1, $q->get( 'posts_per_page' ) );
		$this->assertSame( 3, $q->get( 'paged' ), 'paged stays: the writers listing reads it and core\'s canonical redirect must see a real page' );
		$this->assertSame( '', $q->get( 'no_found_rows' ), 'found rows are needed so core knows the page exists' );
		$single                 = bfrc_test_front_query( [], 'single' );
		$single->queried_object = get_post( 41 );
		$this->templates->shape_query( $single );
		$this->assertSame( '', $single->get( 'posts_per_page' ), 'a single recipe is not reshaped' );
		$blog = bfrc_test_front_query( [ 'post_type' => 'post' ] );
		$this->templates->shape_query( $blog );
		$this->assertSame( 'post', $blog->get( 'post_type' ) );
	}

	public function test_guard_404s_an_unknown_writer_and_the_listing_when_it_is_off(): void {
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
		$this->settings->update( [ 'writers_listing' => false ] + $this->settings->all() );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->templates->guard();
		$this->assertTrue( $GLOBALS['wp_query']->is_404(), 'listing switched off' );
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
		$single                 = bfrc_test_front_query( [], 'single' );
		$single->queried_object = get_post( 41 );
		$this->assertSame( BFRC_DIR . 'templates/single-recipe.php', $t->template_include( '/theme/single.php' ) );
		$GLOBALS['bfrc_test_theme_files'] = [ 'bluefairy-recipes/single-recipe.php' ];
		$this->assertSame( '/theme/bluefairy-recipes/single-recipe.php', $t->template_include( '/theme/single.php' ), 'the theme\'s override wins' );
		$single->set_404();
		$this->assertSame( '/theme/404.php', $t->template_include( '/theme/404.php' ), 'a 404 keeps core\'s template' );
		$GLOBALS['bfrc_test_block_theme'] = true;
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( '/theme/archive.html', $t->template_include( '/theme/archive.html' ), 'block theme: the registered block templates apply through the hierarchy' );
		$this->assertSame( BFRC_DIR . 'templates/archive-recipe.php', Templates::locate( 'archive-recipe' ) );
		$this->assertSame( '/theme/bluefairy-recipes/single-recipe.php', Templates::locate( 'single-recipe' ), 'locate() prefers the theme file' );
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

	public function test_document_title_names_the_writer_pages(): void {
		$t = $this->templates;
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( [ 'title' => 'Recipes' ], $t->document_title( [ 'title' => 'Recipes' ] ), 'the archive keeps core\'s title' );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1' ] );
		$this->assertSame( [ 'title' => 'Recipe writers' ], $t->document_title( [ 'title' => 'Recipes' ] ) );
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$this->assertSame( [ 'title' => 'Ann' ], $t->document_title( [ 'title' => 'Recipes' ] ) );
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
		$q        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$q->posts = [ get_post( 41 ) ];
		$this->assertStringContainsString( 'bfrc-grid', $t->render_archive_block() );
	}

	public function test_requested_filters_and_search_read_only_strings(): void {
		$_GET = [ 'bfrc_s' => [ 'x' ], 'bfrc_meal_type' => 'Dinner Party', 'bfrc_diet' => '' ];
		$this->assertSame( '', $this->templates->requested_search() );
		$this->assertSame( [ 'bfrc_meal_type' => 'dinner-party' ], $this->templates->requested_filters() );
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
