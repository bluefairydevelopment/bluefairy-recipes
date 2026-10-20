<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Front;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\Blocks;
use BlueFairy\Recipes\Front\RecipeQuery;
use BlueFairy\Recipes\Front\Renderer;
use BlueFairy\Recipes\Front\Templates;
use BlueFairy\Recipes\Front\Writers;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class BlocksTest extends TestCase {

	private Settings $settings;
	private Blocks $blocks;
	private Renderer $renderer;
	private Writers $writers;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		Capabilities::add_roles();
		$this->settings = new Settings();
		$taxonomies     = new Taxonomies( $this->settings );
		$this->writers  = new Writers( $this->settings );
		$this->renderer = new Renderer( $this->settings, $this->writers, $taxonomies );
		$templates      = new Templates( $this->settings, $this->writers, $this->renderer, $taxonomies );
		Plugin::set( 'front.templates', $templates );
		$this->blocks = new Blocks( $this->settings, $taxonomies, $this->renderer, new RecipeQuery( $this->settings, $taxonomies ), $templates );
		foreach ( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$GLOBALS['bfrc_test_taxonomies'][ $tax ] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		}
		wp_insert_term( 'Vegan', 'bfrc_diet' );
		wp_insert_term( 'Dinner', 'bfrc_meal_type' );
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann', 'user_nicename' => 'ann' ] );
		for ( $i = 41; $i <= 45; $i++ ) {
			bfrc_test_add_post( [ 'ID' => $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Recipe ' . $i, 'post_name' => 'recipe-' . $i ] );
		}
		$_SERVER['REQUEST_URI'] = '/cook/?bfrc_page=2';
		// Four recipes a page out of five: page 1 is 41..44, page 2 is 45.
		bfrc_test_query_resolver(
			static function ( array $vars ): array {
				$all   = isset( $vars['post__in'] ) ? array_values( array_intersect( [ 45, 44, 43, 42, 41 ], $vars['post__in'] ) ) : [ 41, 42, 43, 44, 45 ];
				$per   = (int) $vars['posts_per_page'];
				$page  = (int) ( $vars['paged'] ?? 1 );
				$slice = array_slice( $all, ( $page - 1 ) * $per, $per );
				return [ ( $vars['fields'] ?? '' ) === 'ids' ? $slice : array_map( 'get_post', $slice ), count( $all ), (int) ceil( count( $all ) / $per ) ];
			}
		);
	}

	private function shortcode_as_block( string $shortcode_html, string $block_html ): void {
		$this->assertMatchesRegularExpression( '#^<div class="wp-block-bfrc-test bfrc-block-[a-z]+">.*</div>$#s', $block_html, 'the block is the wrapper element around the inner markup' );
		$inner = (string) preg_replace( '#^<div [^>]*>#', '', $block_html, 1 );
		$inner = (string) preg_replace( '#</div>$#', '', $inner, 1 );
		$this->assertSame( $inner, $shortcode_html, 'the shortcode\'s markup is the block\'s inner markup, byte for byte' );
	}

	public function test_register_hooks_registers_four_blocks_from_build_and_three_shortcodes(): void {
		$b = $this->blocks;
		$b->register_hooks();
		$this->assertSame( 20, has_action( 'init', [ $b, 'register' ] ) );
		$this->assertSame( 10, has_action( 'init', [ $b, 'register_shortcodes' ] ) );
		$b->register();
		$dirs = array_keys( $GLOBALS['bfrc_test_blocks'] );
		$this->assertSame( [ 'recipe-filters', 'recipe-list', 'featured-recipes', 'recipe-card' ], array_map( 'basename', $dirs ), 'the filters block first: it owns the view module the list block names' );
		foreach ( $GLOBALS['bfrc_test_blocks'] as $dir => $args ) {
			$this->assertFileExists( $dir . '/block.json', 'the built metadata is committed' );
			$this->assertTrue( is_callable( $args['render_callback'] ), $dir );
			$meta = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );
			$this->assertSame( 'bfrc/' . basename( $dir ), $meta['name'] );
			$this->assertSame( 3, $meta['apiVersion'] );
			$this->assertArrayNotHasKey( 'style', $meta, basename( $dir ) . ': a block style handle would load on every page of a classic theme' );
			$this->assertContains( 'bfrc-recipes', (array) $meta['editorStyle'], 'styled in the editor by the front stylesheet' );
			$this->assertFileExists( $dir . '/index.js' );
			$this->assertFileExists( $dir . '/index.asset.php' );
		}
		$filters = json_decode( (string) file_get_contents( BFRC_DIR . 'build/blocks/recipe-filters/block.json' ), true );
		$list    = json_decode( (string) file_get_contents( BFRC_DIR . 'build/blocks/recipe-list/block.json' ), true );
		$this->assertSame( 'file:./view.js', $filters['viewScriptModule'] );
		$this->assertTrue( $filters['supports']['interactivity'] );
		$this->assertTrue( $list['supports']['interactivity'] );
		$this->assertSame( Blocks::MODULE, $list['viewScriptModule'], 'the id core derives for the filters block\'s module: {namespace}-{name}-view-script-module' );
		$this->assertFileExists( BFRC_DIR . 'build/blocks/recipe-filters/view.js' );
		$asset = include BFRC_DIR . 'build/blocks/recipe-filters/view.asset.php';
		$this->assertSame( [ '@wordpress/interactivity' ], $asset['dependencies'] );
		$this->assertSame( 'module', $asset['type'] );
		$this->assertSame( [ 'perPage', 'filters', 'showFilters', 'showPagination' ], array_keys( $list['attributes'] ) );
		$featured = json_decode( (string) file_get_contents( BFRC_DIR . 'build/blocks/featured-recipes/block.json' ), true );
		$this->assertSame( [ 4, 1, 12 ], [ $featured['attributes']['count']['default'], $featured['attributes']['count']['minimum'], $featured['attributes']['count']['maximum'] ] );
		$this->assertSame( [ 'featured', 'recent' ], $featured['attributes']['mode']['enum'] );
		$b->register_shortcodes();
		$this->assertSame( [ 'bfrc_recipes', 'bfrc_featured', 'bfrc_writers' ], array_keys( $GLOBALS['bfrc_test_shortcodes'] ) );
	}

	public function test_the_list_block_renders_filters_status_grid_and_pagination_as_one_interactive_region(): void {
		$_GET = [ 'bfrc_page' => '2', 'bfrc_s' => 'pie', 'bfrc_filter' => [ 'diet' => 'vegan', 'meal_type' => 'dinner' ] ];
		$html = $this->blocks->render_list( [ 'perPage' => 4, 'filters' => [ 'meal_type' => 'dinner' ] ] );
		$this->assertStringStartsWith( '<div class="wp-block-bfrc-test bfrc-block-list"><div class="bfrc-list" data-wp-interactive="bfrc/recipes" data-wp-class--is-loading="state.loading">', $html );
		$this->assertStringContainsString( '<form class="bfrc-filters" method="get" action="https://example.com/cook/" role="search"', $html, 'the page itself, path only: the plain-form fallback posts to the page' );
		$this->assertStringContainsString( 'data-wp-interactive="bfrc/recipes" data-wp-on--submit="actions.submit"', $html );
		$this->assertStringContainsString( '<select name="bfrc_filter[diet]" data-bfrc-code="diet" data-wp-on--change="actions.filter"><option value="">All Diets</option><option value="vegan" selected="selected">Vegan</option></select>', $html );
		$this->assertStringNotContainsString( 'bfrc_filter[meal_type]', $html, 'a fixed filter has no select' );
		$this->assertStringContainsString( 'name="bfrc_s" value="pie" maxlength="100" placeholder="Recipe title" data-wp-on--input="actions.keyword" data-wp-on--compositionend="actions.keyword"', $html );
		$this->assertStringContainsString( '<a class="bfrc-filters__reset" href="https://example.com/cook/" data-wp-on--click="actions.reset" data-wp-bind--hidden="!state.filtered">Reset</a>', $html );
		$this->assertStringContainsString( '<p class="bfrc-list__status" role="status" aria-live="polite" data-wp-text="state.status"></p>', $html );
		$this->assertStringContainsString( '<div class="bfrc-list__results" data-wp-watch="callbacks.renderResults"><ul class="bfrc-grid"><li class="bfrc-grid__item"><article class="bfrc-card">', $html );
		$this->assertSame( 1, substr_count( $html, 'bfrc-grid__item' ), 'page 2 of 5 at 4 a page' );
		$this->assertStringContainsString( '<nav class="bfrc-pagination" aria-label="Recipes pagination" data-wp-bind--hidden="!state.hasPages" data-wp-watch="callbacks.renderPagination" data-wp-on--click="actions.paginate"><ul class="page-numbers">', $html );
		$this->assertStringContainsString( '<li><a class="prev page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_filter%5Bdiet%5D=vegan" data-bfrc-page="1">&laquo; Previous</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_filter%5Bdiet%5D=vegan" data-bfrc-page="1">1</a></li><li><span aria-current="page" class="page-numbers current">2</span></li></ul></nav></div></div>', $html, 'page links keep the keyword and the user\'s filters, not the fixed one, and page 1 has no page argument' );
		$vars = $GLOBALS['bfrc_test_queries'][0];
		$this->assertSame( [ 4, 2, 'pie' ], [ $vars['posts_per_page'], $vars['paged'], $vars['s'] ] );
		$this->assertSame( [ 'bfrc_meal_type', 'bfrc_diet' ], [ $vars['tax_query'][0]['taxonomy'], $vars['tax_query'][1]['taxonomy'] ], 'the fixed filter and the request\'s' );
		$state = $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes'];
		$this->assertSame( 'https://example.com/wp-json/bfrc/v1/recipes', $state['restUrl'] );
		$this->assertSame( 'https://example.com/cook/', $state['baseUrl'] );
		$this->assertEquals( [ 's' => 'pie', 'filter' => (object) [ 'diet' => 'vegan' ], 'page' => 2 ], (array) $state['query'], 'the request\'s own choices (not the fixed one), as the store starts in step with the page' );
		$this->assertIsObject( $state['query']['filter'], 'an empty filter set must reach the script as {} not []' );
		$this->assertEquals( (object) [ 'meal_type' => 'dinner' ], $state['fixed'] );
		$this->assertSame( [ 4, 5, 2, true, null ], [ $state['perPage'], $state['total'], $state['totalPages'], $state['hasPages'], $state['cards'] ] );
		$config = $GLOBALS['bfrc_test_interactivity_config']['bfrc/recipes'];
		$this->assertSame( [ 's' => 'bfrc_s', 'filter' => 'bfrc_filter', 'page' => 'bfrc_page' ], $config['vars'] );
		$this->assertSame( 'No recipes match. Try fewer filters.', $config['emptyText'] );
		$this->assertSame( 600, $config['debounce'] );
		$this->assertSame( [ Blocks::MODULE ], $GLOBALS['bfrc_test_enqueued_modules'] );
		$this->assertContains( 'bfrc-recipes', $GLOBALS['bfrc_test_enqueued']['style'] ?? [] );
	}

	public function test_the_list_block_without_filters_or_pagination_and_an_empty_result(): void {
		bfrc_test_query_resolver( static fn( array $vars ): array => [ [], 0, 0 ] );
		$html = $this->blocks->render_list( [ 'showFilters' => false, 'showPagination' => false ] );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<nav', $html );
		$this->assertStringContainsString( '<div class="bfrc-list__results" data-wp-watch="callbacks.renderResults"><p class="bfrc-empty">No recipes match. Try fewer filters.</p></div>', $html );
		$this->assertSame( 12, $GLOBALS['bfrc_test_queries'][0]['posts_per_page'], 'perPage 0 is the setting' );
		$this->assertFalse( $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes']['hasPages'] );
		$one = $this->blocks->render_list( [ 'perPage' => 100 ] );
		$this->assertStringContainsString( ' hidden data-wp-bind--hidden="!state.hasPages"', $one, 'one page: the nav is there for the store to fill, hidden' );
	}

	public function test_shortcodes_render_the_blocks_inner_markup_and_process_directives(): void {
		$_GET  = [ 'bfrc_s' => 'pie' ];
		$block = $this->blocks->render_list( [ 'perPage' => 4, 'filters' => [ 'diet' => 'vegan' ], 'showPagination' => true ] );
		$GLOBALS['bfrc_test_enqueued_modules'] = [];
		$short                                 = $this->blocks->shortcode_recipes( [ 'per_page' => '4', 'diet' => 'vegan' ] );
		$this->shortcode_as_block( $short, $block );
		$this->assertSame( 1, $GLOBALS['bfrc_test_directives_processed'], 'outside an interactive block, the shortcode runs the server-side directive pass itself' );
		$this->assertSame( [ Blocks::MODULE ], $GLOBALS['bfrc_test_enqueued_modules'] );
		$GLOBALS['bfrc_test_shortcodes'] = [];
		$this->blocks->register_shortcodes();
		$this->assertSame( $short, do_shortcode( '[bfrc_recipes per_page="4" diet="vegan"]' ) );
		$no_filters = $this->blocks->shortcode_recipes( [ 'show_filters' => '0', 'show_pagination' => 'no' ] );
		$this->assertStringNotContainsString( '<form', $no_filters );
		$this->assertStringNotContainsString( '<nav', $no_filters );
		$this->assertStringContainsString( 'bfrc-grid', $this->blocks->shortcode_recipes( '' ) );
	}

	public function test_featured_block_and_shortcode_agree_and_vanish_when_there_is_nothing(): void {
		update_post_meta( 42, Meta::FEATURED, true );
		$this->wpdb->queue( 'get_col', [ 42 ] );
		$block = $this->blocks->render_featured( [ 'title' => 'Chef\'s picks', 'mode' => 'featured', 'count' => 3 ] );
		$this->assertStringStartsWith( '<div class="wp-block-bfrc-test bfrc-block-featured"><section class="bfrc-featured"><h2 class="bfrc-featured__title">Chef&#039;s picks</h2><ul class="bfrc-grid"><li class="bfrc-grid__item"><article class="bfrc-card bfrc-card--featured">', $block );
		$this->assertStringContainsString( '<h3 class="bfrc-card__title">', $block, 'cards sit under the section heading' );
		$this->shortcode_as_block( $this->blocks->shortcode_featured( [ 'title' => 'Chef\'s picks', 'count' => '3' ] ), $block );
		$this->assertSame( [ 42 ], $GLOBALS['bfrc_test_queries'][0]['post__in'] );
		$untitled = $this->blocks->render_featured( [ 'title' => '', 'mode' => 'recent', 'count' => 2 ] );
		$this->assertStringNotContainsString( 'bfrc-featured__title', $untitled );
		$this->assertStringContainsString( '<h2 class="bfrc-card__title">', $untitled, 'no heading: cards keep h2' );
		$this->assertCount( 2, $GLOBALS['bfrc_test_queries'], 'the featured set came from the cache the second time; recent is a new query' );
		$this->assertSame( 2, $GLOBALS['bfrc_test_queries'][1]['posts_per_page'] );
		bfrc_test_query_resolver( static fn( array $vars ): array => [ [], 0, 0 ] );
		\BlueFairy\Recipes\Content\Cache::bump();
		$this->assertSame( '', $this->blocks->render_featured( [ 'mode' => 'recent' ] ), 'nothing to show: no wrapper, no empty section' );
		$this->assertSame( '', $this->blocks->shortcode_featured( [ 'mode' => 'recent' ] ) );
		$this->assertSame( '', $this->blocks->render_featured( 'not an array' ) );
	}

	public function test_card_block_renders_one_published_recipe_only(): void {
		$block = $this->blocks->render_card( [ 'recipeId' => 41 ] );
		$this->assertStringStartsWith( '<div class="wp-block-bfrc-test bfrc-block-card"><article class="bfrc-card">', $block );
		$this->assertStringContainsString( 'Recipe 41', $block );
		$this->assertStringContainsString( 'rel="author">Ann', $block, 'the writer link is resolved as on the archive' );
		$this->assertSame( '', $this->blocks->render_card( [ 'recipeId' => 0 ] ) );
		$this->assertSame( '', $this->blocks->render_card( [ 'recipeId' => 999 ] ) );
		$GLOBALS['bfrc_test_posts'][42]->post_status = 'draft';
		$this->assertSame( '', $this->blocks->render_card( [ 'recipeId' => 42 ] ), 'a draft is not shown' );
		bfrc_test_add_post( [ 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish' ] );
		$this->assertSame( '', $this->blocks->render_card( [ 'recipeId' => 7 ] ), 'not a recipe' );
	}

	public function test_filters_block_is_the_form_alone(): void {
		$html = $this->blocks->render_filters( [] );
		$this->assertStringStartsWith( '<div class="wp-block-bfrc-test bfrc-block-filters"><form class="bfrc-filters" method="get" action="https://example.com/cook/"', $html );
		$this->assertStringContainsString( 'bfrc_filter[meal_type]', $html, 'nothing fixed: every filter type with terms' );
		$this->assertSame( [ Blocks::MODULE ], $GLOBALS['bfrc_test_enqueued_modules'] );
	}

	public function test_writers_shortcode_is_the_listing_without_its_page_heading_paged_on_the_page(): void {
		$this->assertStringContainsString( '<li class="bfrc-writer-card">', $this->blocks->shortcode_writers(), 'Ann, with her five recipes' );
		for ( $i = 1; $i <= 25; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'W%02d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		$this->writers->reset();
		$_GET['bfrc_page'] = '2';
		$html              = $this->blocks->shortcode_writers();
		$this->assertStringStartsWith( '<div class="bfrc-writers"><ul class="bfrc-writers__grid">', $html, 'no h1 and no "All recipes" link inside a page' );
		$this->assertSame( 2, substr_count( $html, '<li class="bfrc-writer-card">' ), '26 writers, 24 a page: two on page 2' );
		$call = $GLOBALS['bfrc_test_paginate_calls'][0];
		$this->assertSame( 'https://example.com/cook/?bfrc_page=%#%', $call['base'] );
		$this->assertSame( '', $call['format'] );
		$this->assertSame( [ 2, 2 ], [ $call['total'], $call['current'] ] );
		$this->assertSame( $this->renderer->writers_list( 2, false, 'https://example.com/cook/' ), $html, 'the same markup the writers view block renders, less its heading' );
		$this->settings->update( [ 'writers_listing' => false ] + $this->settings->all() );
		$this->assertSame( '', $this->blocks->shortcode_writers(), 'the listing switch is honoured' );
	}

	public function test_the_inner_renderers_are_what_the_blocks_wrap(): void {
		$list = [ 'per_page' => 4, 'filters' => [], 'show_filters' => true, 'show_pagination' => true ];
		$this->assertSame( '<div class="wp-block-bfrc-test bfrc-block-list">' . $this->blocks->list_inner( $list ) . '</div>', $this->blocks->render_list( [ 'perPage' => 4 ] ) );
		$this->assertSame( '<div class="wp-block-bfrc-test bfrc-block-filters">' . $this->blocks->filters_inner( [], 'https://example.com/cook/' ) . '</div>', $this->blocks->render_filters() );
		$this->assertSame( '<div class="wp-block-bfrc-test bfrc-block-card">' . $this->blocks->card_inner( 41 ) . '</div>', $this->blocks->render_card( [ 'recipeId' => 41 ] ) );
		$this->wpdb->queue( 'get_col', [] );
		$this->assertSame( '', $this->blocks->featured_inner( 'x', 'featured', 4 ), 'no featured recipes' );
		$this->assertSame( '', $this->blocks->render_featured( [ 'title' => 'x' ] ) );
		$this->assertStringContainsString( '<section class="bfrc-featured"><h2 class="bfrc-featured__title">Newest</h2>', $this->blocks->featured_inner( ' Newest ', 'recent', 2 ), 'the heading is sanitised text' );
		$this->assertStringNotContainsString( 'bfrc_filter[diet]', $this->blocks->filters_inner( [ 'diet' ], 'https://example.com/cook/' ), 'a hidden code has no select' );
	}

	public function test_everything_renders_nothing_while_disabled(): void {
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		$this->assertSame( '', $this->blocks->render_list( [] ) );
		$this->assertSame( '', $this->blocks->list_inner( [ 'per_page' => 0, 'filters' => [], 'show_filters' => true, 'show_pagination' => true ] ) );
		$this->assertSame( '', $this->blocks->featured_inner( '', 'recent', 4 ) );
		$this->assertSame( '', $this->blocks->card_inner( 41 ) );
		$this->assertSame( '', $this->blocks->render_filters( [] ) );
		$this->assertSame( '', $this->blocks->render_featured( [] ) );
		$this->assertSame( '', $this->blocks->render_card( [ 'recipeId' => 41 ] ) );
		$this->assertSame( '', $this->blocks->shortcode_recipes( [] ) );
		$this->assertSame( '', $this->blocks->shortcode_featured( [] ) );
		$this->assertSame( '', $this->blocks->shortcode_writers( [] ) );
		$this->assertSame( [], $GLOBALS['bfrc_test_queries'], 'and queries nothing' );
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued_modules'] );
	}

	public function test_base_url_is_the_page_the_edited_post_or_the_request_path(): void {
		$this->assertSame( 'https://example.com/cook/', $this->blocks->base_url(), 'the request path without its query string' );
		$q                     = bfrc_test_front_query( [], 'single' );
		$q->queried_object_id  = 9;
		bfrc_test_add_post( [ 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish' ] );
		$this->assertSame( 'https://example.com/?p=9', $this->blocks->base_url(), 'a page being viewed: its permalink' );
		$GLOBALS['bfrc_test_serving_rest']       = true;
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/block-renderer/bfrc/recipe-list';
		$this->assertSame( 'https://example.com/', $this->blocks->base_url(), 'the Site Editor\'s preview: nowhere in particular' );
		$_REQUEST['post_id'] = '9';
		$this->assertSame( 'https://example.com/?p=9', $this->blocks->base_url(), 'the post editor\'s preview: the post being edited' );
		unset( $_REQUEST['post_id'], $_SERVER['REQUEST_URI'] );
		$GLOBALS['bfrc_test_serving_rest'] = false;
		$GLOBALS['wp_query'] = null;
		$this->assertSame( 'https://example.com/', $this->blocks->base_url() );
	}

	public function test_page_items_and_page_links_follow_core_paginate_links_defaults(): void {
		$this->assertSame( [ 1 ], Blocks::page_items( 1, 1 ) );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], Blocks::page_items( 3, 5 ) );
		$this->assertSame( [ 1, 2, 3, 'dots', 10 ], Blocks::page_items( 1, 10 ) );
		$this->assertSame( [ 1, 'dots', 3, 4, 5, 6, 7, 'dots', 10 ], Blocks::page_items( 5, 10 ) );
		$this->assertSame( [ 1, 'dots', 8, 9, 10 ], Blocks::page_items( 10, 10 ) );
		$this->assertSame( [ 1, 2, 3, 4, 'dots', 10 ], Blocks::page_items( 2, 10 ) );
		$this->assertSame( '', Blocks::page_links( 1, 1, 'https://example.com/cook/', [] ) );
		$html = Blocks::page_links( 5, 10, 'https://example.com/cook/', [ 'bfrc_s' => 'a%26b' ] ); // Values arrive encoded (url_args); add_query_arg() does not encode.
		$this->assertSame( '<ul class="page-numbers"><li><a class="prev page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=4" data-bfrc-page="4">&laquo; Previous</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b" data-bfrc-page="1">1</a></li><li><span class="page-numbers dots">&hellip;</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=3" data-bfrc-page="3">3</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=4" data-bfrc-page="4">4</a></li><li><span aria-current="page" class="page-numbers current">5</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=6" data-bfrc-page="6">6</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=7" data-bfrc-page="7">7</a></li><li><span class="page-numbers dots">&hellip;</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=10" data-bfrc-page="10">10</a></li><li><a class="next page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=6" data-bfrc-page="6">Next &raquo;</a></li></ul>', $html );
		$this->assertSame( 'https://example.com/cook/?bfrc_filter%5Bdiet%5D=vegan&#038;bfrc_page=2', (string) preg_match( '#href="([^"]+)" data-bfrc-page="2"#', Blocks::page_links( 1, 2, 'https://example.com/cook/', [ 'bfrc_filter' => [ 'diet' => 'vegan' ] ] ), $m ) ? $m[1] : '', 'a filter array becomes bfrc_filter[diet]' );
	}
}
