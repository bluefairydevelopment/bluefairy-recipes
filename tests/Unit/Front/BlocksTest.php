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
		$GLOBALS['wp']->request = 'cook'; // The matched request path: /cook/.
		// Four recipes a page out of five: page 1 is 41..44, page 2 is 45.
		bfrc_test_query_resolver(
			static function ( array $vars ): array {
				$all   = isset( $vars['post__in'] ) ? array_values( array_intersect( [ 45, 44, 43, 42, 41 ], $vars['post__in'] ) ) : [ 41, 42, 43, 44, 45 ];
				$per   = (int) $vars['posts_per_page'];
				$page  = (int) ( $vars['paged'] ?? 1 );
				$slice = array_slice( $all, ( $page - 1 ) * $per, $per );
				$count = empty( $vars['no_found_rows'] ) && [] !== $slice; // As core: set_found_posts() bails on an empty page, so a page past the end counts nothing.
				return [ ( $vars['fields'] ?? '' ) === 'ids' ? $slice : array_map( 'get_post', $slice ), $count ? count( $all ) : 0, $count ? (int) ceil( count( $all ) / $per ) : 0 ];
			}
		);
	}

	private function shortcode_as_block( string $shortcode_html, string $block_html ): void {
		$this->assertMatchesRegularExpression( '#^<div class="wp-block-bfrc-test bfrc-block-[a-z]+">.*</div>$#s', $block_html, 'the block is the wrapper element around the inner markup' );
		$inner = (string) preg_replace( '#^<div [^>]*>#', '', $block_html, 1 );
		$inner = (string) preg_replace( '#</div>$#', '', $inner, 1 );
		$this->assertSame( $inner, $shortcode_html, 'the shortcode\'s markup is the block\'s inner markup, byte for byte' );
	}

	/** Render as the first list of a fresh page: the one that takes the store. */
	private function fresh(): void {
		Blocks::reset();
		$GLOBALS['bfrc_test_unique_id'] = 0;
	}

	public function test_register_hooks_registers_four_blocks_from_build_and_three_shortcodes(): void {
		$b = $this->blocks;
		$b->register_hooks();
		$this->assertSame( 20, has_action( 'init', [ $b, 'register' ] ) );
		$this->assertSame( 10, has_action( 'init', [ $b, 'register_shortcodes' ] ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_head', [ Blocks::class, 'reset_before_body' ] ), 'a classic theme\'s head renders before the loop: nothing there may take the live slot' );
		$this->blocks->render_list( [] );
		Blocks::reset_before_body();
		$this->assertStringContainsString( 'data-wp-interactive', $this->blocks->render_list( [] ), 'classic theme: the slot is free again for the body' );
		$GLOBALS['bfrc_test_block_theme'] = true;
		$this->blocks->render_list( [] );
		Blocks::reset_before_body();
		$this->assertStringNotContainsString( 'data-wp-interactive', $this->blocks->render_list( [] ), 'block theme: the template rendered before wp_head, the slot stays taken' );
		$GLOBALS['bfrc_test_block_theme'] = false;
		Blocks::reset();
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
		$this->assertArrayNotHasKey( 'default', $featured['attributes']['title'], 'no literal default: an unset title is translated on the server' );
		$b->register_shortcodes();
		$this->assertSame( [ 'bfrc_recipes', 'bfrc_featured', 'bfrc_writers' ], array_keys( $GLOBALS['bfrc_test_shortcodes'] ) );
	}

	public function test_the_list_block_renders_filters_status_grid_and_pagination_as_one_interactive_region(): void {
		$_GET = [ 'bfrc_page' => '2', 'bfrc_s' => 'pie', 'bfrc_filter' => [ 'diet' => 'vegan', 'meal_type' => 'dinner' ] ];
		$html = $this->blocks->render_list( [ 'perPage' => 4, 'filters' => [ 'meal_type' => 'dinner' ] ] );
		$this->assertStringStartsWith( '<div class="wp-block-bfrc-test bfrc-block-list"><div class="bfrc-list" data-wp-interactive="bfrc/recipes" data-wp-class--is-loading="state.loading">', $html );
		$this->assertStringContainsString( '<form class="bfrc-filters" method="get" action="https://example.com/cook/" role="search" aria-label="Filter recipes" aria-describedby="bfrc-filters-hint-1" data-wp-interactive="bfrc/recipes" data-wp-on--submit="actions.submit"><p id="bfrc-filters-hint-1"', $html, 'the page itself as the plain-form fallback\'s action; a unique hint id' );
		$this->assertStringContainsString( '<label class="bfrc-filters__field" data-wp-bind--hidden="state.fixed.diet"><span class="bfrc-filters__label">Diet</span><select name="bfrc_filter[diet]" data-bfrc-code="diet" data-wp-on--change="actions.filter"><option value="">All Diets</option><option value="vegan" selected="selected">Vegan</option></select>', $html, 'a select hides itself once the page scripts know a list fixed its code' );
		$this->assertStringNotContainsString( 'bfrc_filter[meal_type]', $html, 'a fixed filter has no select' );
		$this->assertStringContainsString( 'name="bfrc_s" value="pie" maxlength="100" placeholder="Recipe title" data-wp-on--input="actions.keyword" data-wp-on--compositionend="actions.keyword"', $html );
		$this->assertStringContainsString( '<a class="bfrc-filters__reset" href="https://example.com/cook/" data-wp-on--click="actions.reset" data-wp-bind--hidden="!state.filtered">Reset</a>', $html );
		$this->assertStringContainsString( '<p class="bfrc-list__status" role="status" aria-live="polite" data-wp-text="state.status"></p>', $html );
		$this->assertStringContainsString( '<div class="bfrc-list__results" data-wp-watch="callbacks.renderResults"><ul class="bfrc-grid"><li class="bfrc-grid__item"><article class="bfrc-card">', $html );
		$this->assertSame( 1, substr_count( $html, 'bfrc-grid__item' ), 'page 2 of 5 at 4 a page' );
		$this->assertStringContainsString( '<nav class="bfrc-pagination" aria-label="Recipes pagination" data-wp-bind--hidden="!state.hasPages" data-wp-watch="callbacks.renderPagination" data-wp-on--click="actions.paginate"><ul class="page-numbers">', $html );
		$this->assertStringContainsString( '<li><a class="prev page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_filter%5Bdiet%5D=vegan" data-bfrc-page="1">&laquo; Previous</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=pie&#038;bfrc_filter%5Bdiet%5D=vegan" data-bfrc-page="1">1</a></li><li><span aria-current="page" class="page-numbers current" tabindex="-1">2</span></li></ul></nav></div></div>', $html, 'page links keep the keyword and the user\'s filters, not the fixed one, and page 1 has no page argument' );
		$vars = $GLOBALS['bfrc_test_queries'][0];
		$this->assertSame( [ 4, 2, 'pie', false, false ], [ $vars['posts_per_page'], $vars['paged'], $vars['s'], $vars['update_post_term_cache'], $vars['no_found_rows'] ], 'no term cache (cards read none); counted, for the pagination' );
		$this->assertSame( [ 'bfrc_meal_type', 'bfrc_diet' ], [ $vars['tax_query'][0]['taxonomy'], $vars['tax_query'][1]['taxonomy'] ], 'the fixed filter and the request\'s' );
		$state = $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes'];
		$this->assertSame( 'https://example.com/wp-json/bfrc/v1/recipes', $state['restUrl'] );
		$this->assertSame( 'https://example.com/cook/', $state['baseUrl'] );
		$this->assertEquals( [ 's' => 'pie', 'filter' => (object) [ 'diet' => 'vegan' ], 'page' => 2 ], (array) $state['query'], 'the request\'s own choices (not the fixed one), as the store starts in step with the page' );
		$this->assertIsObject( $state['query']['filter'], 'an empty filter set must reach the script as {} not []' );
		$this->assertEquals( (object) [ 'meal_type' => 'dinner' ], $state['fixed'] );
		$this->assertSame( [ 4, 5, 2, true, true, null ], [ $state['perPage'], $state['total'], $state['totalPages'], $state['hasPages'], $state['filtered'], $state['cards'] ], 'filtered is server state too: the server-side directive pass reads it for the Reset link' );
		$config = $GLOBALS['bfrc_test_interactivity_config']['bfrc/recipes'];
		$this->assertSame( [ 's' => 'bfrc_s', 'filter' => 'bfrc_filter', 'page' => 'bfrc_page' ], $config['vars'] );
		$this->assertSame( 'No recipes match. Try fewer filters.', $config['emptyText'] );
		$this->assertSame( [ 600, 100 ], [ $config['debounce'], $config['searchMax'] ] );
		$this->assertSame( Blocks::found_texts(), $config['foundTexts'] );
		$this->assertCount( RecipeQuery::MAX_PER_PAGE + 1, $config['foundTexts'], 'a text for every count a page can show' );
		$this->assertSame( [ '0 recipes shown.', '1 recipe shown.', '2 recipes shown.' ], array_slice( $config['foundTexts'], 0, 3 ) );
		$this->assertSame( '100 recipes shown.', $config['foundTexts'][100] );
		$this->assertSame( [ Blocks::MODULE ], $GLOBALS['bfrc_test_enqueued_modules'] );
		$this->assertContains( 'bfrc-recipes', $GLOBALS['bfrc_test_enqueued']['style'] ?? [] );
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued']['script'] ?? [], 'the archive script binds nothing on a block page: not loaded' );
	}

	public function test_only_the_first_list_on_a_page_is_live_and_the_rest_render_from_the_request(): void {
		$_GET   = [ 'bfrc_s' => 'pie' ];
		$first  = $this->blocks->render_list( [ 'perPage' => 4 ] );
		$second = $this->blocks->render_list( [ 'perPage' => 2, 'filters' => [ 'diet' => 'vegan' ] ] );
		$this->assertStringContainsString( 'data-wp-interactive', $first );
		$this->assertStringNotContainsString( 'data-wp-', $second, 'no directive at all: the store belongs to the first list' );
		$this->assertStringContainsString( '<div class="bfrc-list"><form class="bfrc-filters" method="get" action="https://example.com/cook/" role="search" aria-label="Filter recipes" aria-describedby="bfrc-filters-hint-2"><p id="bfrc-filters-hint-2"', $second, 'its own form, plain, with its own hint id' );
		$this->assertStringNotContainsString( 'bfrc-list__status', $second );
		$this->assertStringContainsString( '<nav class="bfrc-pagination" aria-label="Recipes pagination"><ul class="page-numbers">', $second, 'plain page links' );
		$state = $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes'];
		$this->assertSame( 4, $state['perPage'], 'the state is the first list\'s' );
		$this->assertEquals( (object) [], $state['fixed'] );
		$this->assertSame( [ Blocks::MODULE ], $GLOBALS['bfrc_test_enqueued_modules'], 'the module once' );
		Blocks::reset();
		$this->assertStringContainsString( 'data-wp-interactive', $this->blocks->render_list( [ 'perPage' => 2 ] ), 'a new page starts over' );
	}

	public function test_the_list_block_without_filters_or_pagination_and_an_empty_result(): void {
		bfrc_test_query_resolver( static fn( array $vars ): array => [ [], 0, 0 ] );
		$html = $this->blocks->render_list( [ 'showFilters' => false, 'showPagination' => false ] );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<nav', $html );
		$this->assertStringContainsString( '<div class="bfrc-list__results" data-wp-watch="callbacks.renderResults"><p class="bfrc-empty">No recipes match. Try fewer filters.</p></div>', $html );
		$vars = $GLOBALS['bfrc_test_queries'][0];
		$this->assertSame( 12, $vars['posts_per_page'], 'perPage 0 is the setting' );
		$this->assertTrue( $vars['no_found_rows'], 'no pagination: no SQL_CALC_FOUND_ROWS' );
		$this->assertFalse( $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes']['hasPages'] );
		$this->fresh();
		$one = $this->blocks->render_list( [ 'perPage' => 100 ] );
		$this->assertStringContainsString( ' hidden data-wp-bind--hidden="!state.hasPages"', $one, 'one page: the nav is there for the store to fill, hidden' );
	}

	public function test_a_page_past_the_end_shows_the_last_page(): void {
		$_GET = [ 'bfrc_page' => '900' ];
		$html = $this->blocks->render_list( [ 'perPage' => 4 ] );
		$this->assertSame( 1, substr_count( $html, 'bfrc-grid__item' ), 'page 2 of 2' );
		$this->assertStringContainsString( '<span aria-current="page" class="page-numbers current" tabindex="-1">2</span>', $html );
		$queries = $GLOBALS['bfrc_test_queries'];
		$this->assertCount( 3, $queries, 'the empty page (core counts nothing for it), page 1 for the count, then the last page' );
		$this->assertSame( [ 900, 1, 2 ], [ $queries[0]['paged'], $queries[1]['paged'], $queries[2]['paged'] ] );
		$this->assertSame( 2, $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes']['query']['page'] );
		$this->fresh();
		$GLOBALS['bfrc_test_queries'] = [];
		$this->assertSame( 5, substr_count( $this->blocks->render_list( [ 'perPage' => 100 ] ), 'bfrc-grid__item' ), 'one page of five and page 900 asked: the first page is the last page' );
		$this->assertCount( 2, $GLOBALS['bfrc_test_queries'], 'the page-1 run is the answer itself: no third query' );
		$this->fresh();
		$GLOBALS['bfrc_test_queries'] = [];
		bfrc_test_query_resolver( static fn( array $vars ): array => [ [], 0, 0 ] );
		$this->assertStringContainsString( 'bfrc-empty', $this->blocks->render_list( [ 'perPage' => 4 ] ), 'no recipes at all: the empty line' );
		$this->assertCount( 2, $GLOBALS['bfrc_test_queries'], 'page 900 then page 1, which is empty too: nothing more to run' );
		$this->fresh();
		$GLOBALS['bfrc_test_queries'] = [];
		$this->blocks->render_list( [ 'perPage' => 4, 'showPagination' => false ] );
		$this->assertCount( 1, $GLOBALS['bfrc_test_queries'], 'without pagination there is no count and no clamp' );
	}

	public function test_shortcodes_render_the_blocks_inner_markup_and_process_directives(): void {
		$_GET  = [ 'bfrc_s' => 'pie' ];
		$block = $this->blocks->render_list( [ 'perPage' => 4, 'filters' => [ 'diet' => 'vegan' ], 'showPagination' => true ] );
		$this->fresh();
		$GLOBALS['bfrc_test_enqueued_modules'] = [];
		$GLOBALS['bfrc_test_queries']          = [];
		$short                                 = $this->blocks->shortcode_recipes( [ 'per_page' => '4', 'diet' => 'vegan' ] );
		$this->shortcode_as_block( $short, $block );
		$this->assertSame( 1, $GLOBALS['bfrc_test_directives_processed'], 'outside an interactive block, the shortcode runs the server-side directive pass itself' );
		$this->assertSame( [ Blocks::MODULE ], $GLOBALS['bfrc_test_enqueued_modules'] );
		$seen = [];
		add_filter( 'bfrc_list_query_args', static function ( array $q, array $n, string $context ) use ( &$seen ): array {
			$seen[] = $context;
			return $q;
		}, 10, 3 );
		$this->fresh();
		$this->blocks->shortcode_recipes( [] );
		$this->fresh();
		$this->blocks->render_list( [] );
		$this->assertSame( [ 'shortcode', 'block' ], $seen, 'the context names the surface' );
		$GLOBALS['bfrc_test_shortcodes'] = [];
		$this->blocks->register_shortcodes();
		$this->fresh();
		$this->assertSame( $short, do_shortcode( '[bfrc_recipes per_page="4" diet="vegan"]' ) );
		$this->fresh();
		$no_filters = $this->blocks->shortcode_recipes( [ 'show_filters' => '0', 'show_pagination' => 'no' ] );
		$this->assertStringNotContainsString( '<form', $no_filters );
		$this->assertStringNotContainsString( '<nav', $no_filters );
		$this->fresh();
		$this->assertStringContainsString( 'bfrc-grid', $this->blocks->shortcode_recipes( '' ), 'core passes \'\' for a bare tag: still a list' );
	}

	public function test_featured_block_and_shortcode_agree_and_vanish_when_there_is_nothing(): void {
		update_post_meta( 42, Meta::FEATURED, true );
		$this->wpdb->queue( 'get_col', [ 42 ] );
		$block = $this->blocks->render_featured( [ 'title' => 'Chef\'s picks', 'mode' => 'featured', 'count' => 3 ] );
		$this->assertStringStartsWith( '<div class="wp-block-bfrc-test bfrc-block-featured"><section class="bfrc-featured"><h2 class="bfrc-featured__title">Chef&#039;s picks</h2><ul class="bfrc-grid"><li class="bfrc-grid__item"><article class="bfrc-card bfrc-card--featured">', $block );
		$this->assertStringContainsString( '<h3 class="bfrc-card__title">', $block, 'cards sit under the section heading' );
		$this->shortcode_as_block( $this->blocks->shortcode_featured( [ 'title' => 'Chef\'s picks', 'count' => '3' ] ), $block );
		$this->assertSame( [ 42 ], $GLOBALS['bfrc_test_queries'][0]['post__in'] );
		$this->assertStringContainsString( '<h2 class="bfrc-featured__title">Featured recipes</h2>', $this->blocks->render_featured( [ 'mode' => 'featured', 'count' => 3 ] ), 'no title attribute (never edited): the translated default' );
		$untitled = $this->blocks->render_featured( [ 'title' => '', 'mode' => 'recent', 'count' => 2 ] );
		$this->assertStringNotContainsString( 'bfrc-featured__title', $untitled, 'an empty title is no heading' );
		$this->assertStringContainsString( '<h2 class="bfrc-card__title">', $untitled, 'no heading: cards keep h2' );
		$this->assertCount( 2, $GLOBALS['bfrc_test_queries'], 'the featured set came from the cache the second and third time; recent is a new query' );
		$this->assertSame( 2, $GLOBALS['bfrc_test_queries'][1]['posts_per_page'] );
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued']['script'] ?? [], 'style only' );
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

	public function test_filters_block_is_the_form_alone_and_seeds_the_store_unless_a_list_did(): void {
		$_GET = [ 'bfrc_s' => 'pie' ];
		$html = $this->blocks->render_filters( [] );
		$this->assertStringStartsWith( '<div class="wp-block-bfrc-test bfrc-block-filters"><form class="bfrc-filters" method="get" action="https://example.com/cook/"', $html );
		$this->assertStringContainsString( 'bfrc_filter[meal_type]', $html, 'nothing fixed: every filter type with terms' );
		$this->assertStringContainsString( 'data-wp-on--submit="actions.submit"', $html );
		$this->assertSame( [ Blocks::MODULE ], $GLOBALS['bfrc_test_enqueued_modules'] );
		$state = $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes'];
		$this->assertSame( [ 'https://example.com/wp-json/bfrc/v1/recipes', 'https://example.com/cook/', 'pie', 12, 0, true, null ], [ $state['restUrl'], $state['baseUrl'], $state['query']['s'], $state['perPage'], $state['totalPages'], $state['filtered'], $state['cards'] ], 'a form alone still has a store: a change fetches and the URL follows' );
		$this->assertArrayHasKey( 'vars', $GLOBALS['bfrc_test_interactivity_config']['bfrc/recipes'] );
		$this->assertSame( [], $GLOBALS['bfrc_test_queries'], 'and queries nothing' );
		// A list rendered first owns the state: the filters block leaves it alone.
		$this->fresh();
		$GLOBALS['bfrc_test_interactivity_state'] = [];
		$this->blocks->render_list( [ 'perPage' => 3, 'filters' => [ 'diet' => 'vegan' ] ] );
		$this->blocks->render_filters( [] );
		$state = $GLOBALS['bfrc_test_interactivity_state']['bfrc/recipes'];
		$this->assertSame( 3, $state['perPage'] );
		$this->assertEquals( (object) [ 'diet' => 'vegan' ], $state['fixed'], 'the list\'s fixed filter survives a filters block rendered after it' );
	}

	public function test_inside_the_editor_the_form_and_the_pagination_are_inert(): void {
		bfrc_test_add_post( [ 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish' ] );
		$GLOBALS['bfrc_test_serving_rest']       = true;
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/block-renderer/bfrc/recipe-list';
		$_GET['post_id']                         = '9';
		$html                                    = $this->blocks->render_list( [ 'perPage' => 4 ] );
		$this->assertStringContainsString( '<form class="bfrc-filters" method="get" action="https://example.com/" role="search" aria-label="Filter recipes" aria-describedby="bfrc-filters-hint-1" inert data-wp-interactive', $html, 'a mouse click or Enter in the canvas must not submit the preview' );
		$this->assertStringContainsString( '<nav class="bfrc-pagination" aria-label="Recipes pagination" inert data-wp-bind--hidden', $html );
		$this->assertStringContainsString( '<input type="hidden" name="p" value="9">', $html, 'the base URL\'s own argument travels with the plain form' );
		$this->assertStringContainsString( '<form class="bfrc-filters" method="get" action="https://example.com/" role="search" aria-label="Filter recipes" aria-describedby="bfrc-filters-hint-2" inert', $this->blocks->render_filters( [] ) );
		$GLOBALS['bfrc_test_serving_rest'] = false;
		unset( $_GET['post_id'] );
		$this->fresh();
		$this->assertStringNotContainsString( ' inert', $this->blocks->render_list( [ 'perPage' => 4 ] ), 'on the site the form is live' );
	}

	public function test_form_target_turns_the_base_urls_arguments_into_hidden_fields(): void {
		$this->assertSame( [ 'action' => 'https://example.com/cook/', 'hidden' => '' ], Blocks::form_target( 'https://example.com/cook/' ) );
		$this->assertSame(
			[ 'action' => 'https://example.com/', 'hidden' => '<input type="hidden" name="page_id" value="5"><input type="hidden" name="lang" value="fr">' ],
			Blocks::form_target( 'https://example.com/?page_id=5&lang=fr&bfrc_s=old&bfrc_filter%5Bdiet%5D=vegan&bfrc_page=2' ),
			'plain permalinks: page_id survives the GET form; the list\'s own arguments come from the fields'
		);
		$_GET = [ 'bfrc_s' => 'pie' ];
		$q    = bfrc_test_front_query( [], 'single' );
		$q->queried_object_id = 9;
		bfrc_test_add_post( [ 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish' ] );
		$html = $this->blocks->render_list( [ 'perPage' => 4 ] );
		$this->assertStringContainsString( 'action="https://example.com/" role="search"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="p" value="9"><p id=', $html );
		$this->assertStringContainsString( 'href="https://example.com/?p=9&#038;bfrc_s=pie&#038;bfrc_page=2"', $html, 'page links keep the page argument' );
	}

	public function test_writers_shortcode_is_the_listing_without_its_page_heading_paged_on_the_page(): void {
		$this->assertStringContainsString( '<li class="bfrc-writer-card">', $this->blocks->shortcode_writers(), 'Ann, with her five recipes' );
		for ( $i = 1; $i <= 25; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'W%02d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		$this->writers->reset();
		\BlueFairy\Recipes\Front\Writers::bump(); // core's user_register hook, which the stubs do not fire.
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
		$a    = $this->blocks->render_list( [ 'perPage' => 4 ] );
		$this->fresh();
		$this->assertSame( '<div class="wp-block-bfrc-test bfrc-block-list">' . $this->blocks->list_inner( $list ) . '</div>', $a );
		$this->fresh();
		$b = $this->blocks->render_filters();
		$this->fresh();
		$this->assertSame( '<div class="wp-block-bfrc-test bfrc-block-filters">' . $this->blocks->filters_inner( [], 'https://example.com/cook/' ) . '</div>', $b );
		$this->assertSame( '<div class="wp-block-bfrc-test bfrc-block-card">' . $this->blocks->card_inner( 41 ) . '</div>', $this->blocks->render_card( [ 'recipeId' => 41 ] ) );
		$this->wpdb->queue( 'get_col', [] );
		$this->assertSame( '', $this->blocks->featured_inner( 'x', 'featured', 4 ), 'no featured recipes' );
		$this->assertSame( '', $this->blocks->render_featured( [ 'title' => 'x' ] ) );
		$this->assertStringContainsString( '<section class="bfrc-featured"><h2 class="bfrc-featured__title">Newest</h2>', $this->blocks->featured_inner( ' Newest ', 'recent', 2 ), 'the heading is sanitised text' );
		$this->assertStringNotContainsString( 'bfrc_filter[diet]', $this->blocks->filters_inner( [ 'diet' ], 'https://example.com/cook/' ), 'a hidden code has no select' );
		$this->assertStringNotContainsString( 'data-wp-', $this->blocks->filters_inner( [], 'https://example.com/cook/', false ), 'a form that is not live carries no directive' );
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
		$this->assertSame( [], $GLOBALS['bfrc_test_interactivity_state'] );
	}

	public function test_base_url_is_the_page_the_rendered_or_edited_post_or_the_request_path(): void {
		$this->assertSame( 'https://example.com/cook/', $this->blocks->base_url(), 'the matched request path, home-relative' );
		$GLOBALS['wp']->request = 'recettes-%C3%A9t%C3%A9/page/2';
		$this->assertSame( 'https://example.com/recettes-%C3%A9t%C3%A9/page/2/', $this->blocks->base_url(), 'a percent-encoded path survives' );
		$GLOBALS['wp']->request = '';
		$this->assertSame( 'https://example.com/', $this->blocks->base_url(), 'the front page' );
		$GLOBALS['wp']->query_vars = [ 's' => "O\\'Brien b", 'cat' => '1', 'bfrc_s' => 'pie', 'bfrc_filter' => [ 'diet' => 'vegan' ], 'bfrc_page' => '2', 'empty' => '' ]; // Core stores query vars slashed.
		$this->assertSame( 'https://example.com/?s=O%27Brien%20b&cat=1', $this->blocks->base_url(), 'a query-only request keeps its own public query vars (unslashed, encoded), not the list\'s' );
		$this->assertSame( [ 'action' => 'https://example.com/', 'hidden' => '<input type="hidden" name="s" value="O&#039;Brien b"><input type="hidden" name="cat" value="1">' ], Blocks::form_target( $this->blocks->base_url() ), 'and the form carries them as hidden fields, unslashed (a re-submit must not grow a backslash)' );
		$GLOBALS['wp']->query_vars = [];
		$q                    = bfrc_test_front_query( [], 'single' );
		$q->queried_object_id = 9;
		bfrc_test_add_post( [ 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish' ] );
		$this->assertSame( 'https://example.com/?p=9', $this->blocks->base_url(), 'a page being viewed: its permalink' );
		$GLOBALS['bfrc_test_serving_rest'] = true;
		$GLOBALS['post']                   = get_post( 9 );
		$this->assertSame( 'https://example.com/?p=9', $this->blocks->base_url(), 'a page\'s content.rendered over REST: the page, never the REST route' );
		$GLOBALS['post'] = get_post( 41 );
		$this->assertSame( 'https://example.com/', $this->blocks->base_url(), 'a recipe in context is not a page to post to' );
		$GLOBALS['post'] = null;
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/block-renderer/bfrc/recipe-list';
		$this->assertSame( 'https://example.com/', $this->blocks->base_url(), 'the Site Editor\'s preview: nowhere in particular' );
		$_GET['post_id'] = '9';
		$this->assertSame( 'https://example.com/?p=9', $this->blocks->base_url(), 'the post editor\'s preview: the post being edited' );
		$_REQUEST['post_id'] = '9';
		unset( $_GET['post_id'] );
		$this->assertSame( 'https://example.com/', $this->blocks->base_url(), 'read from the query string only, never from a cookie through $_REQUEST' );
		unset( $_REQUEST['post_id'] );
		$GLOBALS['bfrc_test_serving_rest'] = false;
		$GLOBALS['wp_query']               = null;
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
		$this->assertSame( '<ul class="page-numbers"><li><a class="prev page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=4" data-bfrc-page="4">&laquo; Previous</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b" data-bfrc-page="1">1</a></li><li><span class="page-numbers dots">&hellip;</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=3" data-bfrc-page="3">3</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=4" data-bfrc-page="4">4</a></li><li><span aria-current="page" class="page-numbers current" tabindex="-1">5</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=6" data-bfrc-page="6">6</a></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=7" data-bfrc-page="7">7</a></li><li><span class="page-numbers dots">&hellip;</span></li><li><a class="page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=10" data-bfrc-page="10">10</a></li><li><a class="next page-numbers" href="https://example.com/cook/?bfrc_s=a%26b&#038;bfrc_page=6" data-bfrc-page="6">Next &raquo;</a></li></ul>', $html );
		$this->assertSame( 'https://example.com/cook/?bfrc_filter%5Bdiet%5D=vegan&#038;bfrc_page=2', (string) preg_match( '#href="([^"]+)" data-bfrc-page="2"#', Blocks::page_links( 1, 2, 'https://example.com/cook/', [ 'bfrc_filter' => [ 'diet' => 'vegan' ] ] ), $m ) ? $m[1] : '', 'a filter array becomes bfrc_filter[diet]' );
	}
}
