<?php
/**
 * The editor blocks and the shortcodes that mirror them.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Front;

use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Rest\Recipes;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Four blocks, registered from `build/blocks/{name}/block.json` (built by
 * `@wordpress/scripts` from `src/blocks/{name}`; `build/` is committed and
 * shipped, `src/` ships too): Recipe List (`bfrc/recipe-list`), Recipe
 * Filters (`bfrc/recipe-filters`), Featured Recipes (`bfrc/featured-recipes`)
 * and Recipe Card (`bfrc/recipe-card`). Their render callbacks live here so
 * the shortcodes `[bfrc_recipes]`, `[bfrc_featured]` and `[bfrc_writers]`
 * return the same inner markup: a block is `<div {wrapper attributes}>` +
 * inner + `</div>`, a shortcode is the inner markup alone (a test asserts
 * the two are equal once the wrapper element is stripped).
 *
 * The list and the filters are one Interactivity API store (`bfrc/recipes`,
 * `build/blocks/recipe-filters/view.js`): a change in the form fetches
 * `GET /bfrc/v1/recipes` after 600 ms and the grid and pagination are
 * replaced with the cards the endpoint rendered (the same Renderer), the
 * page's URL kept in step. Without the module (no script modules, a failed
 * fetch) the form is a plain GET form to the same page and the pagination
 * links are plain links: `bfrc_s`, `bfrc_filter[{code}]` and `bfrc_page` are
 * read from the request. The store's state is global to the page, so one
 * list per page is kept in step with the filters; a second list renders from
 * the request only.
 */
final class Blocks {

	public const LIST     = 'bfrc/recipe-list';
	public const FILTERS  = 'bfrc/recipe-filters';
	public const FEATURED = 'bfrc/featured-recipes';
	public const CARD     = 'bfrc/recipe-card';

	/**
	 * The Interactivity API store namespace.
	 */
	public const STORE = 'bfrc/recipes';

	/**
	 * The view module id core derives from the filters block's
	 * `viewScriptModule: file:./view.js` (`generate_block_asset_handle()`);
	 * the list block's block.json names it, and the shortcodes enqueue it.
	 */
	public const MODULE = 'bfrc-recipe-filters-view-script-module';

	public const SHORTCODE_RECIPES  = 'bfrc_recipes';
	public const SHORTCODE_FEATURED = 'bfrc_featured';
	public const SHORTCODE_WRITERS  = 'bfrc_writers';

	/**
	 * Build directory of each block, in registration order (the filters
	 * block first: it owns the module the list block refers to).
	 *
	 * @var array<string, string> block name => render method.
	 */
	private const BLOCKS = [
		'recipe-filters'   => 'render_filters',
		'recipe-list'      => 'render_list',
		'featured-recipes' => 'render_featured',
		'recipe-card'      => 'render_card',
	];

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings   Settings.
	 * @param Taxonomies  $taxonomies Filter types.
	 * @param Renderer    $renderer   The renderer.
	 * @param RecipeQuery $query      The list query.
	 * @param Templates   $templates  The request readers and the assets.
	 */
	public function __construct( private Settings $settings, private Taxonomies $taxonomies, private Renderer $renderer, private RecipeQuery $query, private Templates $templates ) {}

	/**
	 * Hook registration.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 20 );
		add_action( 'init', [ $this, 'register_shortcodes' ] );
	}

	/**
	 * `init` 20: the blocks, from their built metadata.
	 */
	public function register(): void {
		foreach ( self::BLOCKS as $dir => $method ) {
			register_block_type( BFRC_DIR . 'build/blocks/' . $dir, [ 'render_callback' => [ $this, $method ] ] );
		}
	}

	/**
	 * `init`: the shortcodes.
	 */
	public function register_shortcodes(): void {
		add_shortcode( self::SHORTCODE_RECIPES, [ $this, 'shortcode_recipes' ] );
		add_shortcode( self::SHORTCODE_FEATURED, [ $this, 'shortcode_featured' ] );
		add_shortcode( self::SHORTCODE_WRITERS, [ $this, 'shortcode_writers' ] );
	}

	/**
	 * Recipe List block.
	 *
	 * @param mixed $attributes Block attributes (core passes an array).
	 */
	public function render_list( mixed $attributes = [] ): string {
		$attributes = is_array( $attributes ) ? $attributes : [];
		return self::wrap(
			$this->list_inner(
				[
					'per_page'        => (int) ( $attributes['perPage'] ?? 0 ),
					'filters'         => is_array( $attributes['filters'] ?? null ) ? $attributes['filters'] : [],
					'show_filters'    => Settings::to_bool( $attributes['showFilters'] ?? true ),
					'show_pagination' => Settings::to_bool( $attributes['showPagination'] ?? true ),
				]
			),
			'bfrc-block-list'
		);
	}

	/**
	 * Recipe Filters block: the form alone, for a list elsewhere on the page.
	 *
	 * @param mixed $attributes Block attributes.
	 */
	public function render_filters( mixed $attributes = [] ): string {
		unset( $attributes );
		if ( ! (bool) $this->settings->get( 'enabled' ) ) {
			return '';
		}
		$this->enqueue();
		return self::wrap( $this->filters_inner( [], $this->base_url() ), 'bfrc-block-filters' );
	}

	/**
	 * Featured Recipes block.
	 *
	 * @param mixed $attributes Block attributes.
	 */
	public function render_featured( mixed $attributes = [] ): string {
		$attributes = is_array( $attributes ) ? $attributes : [];
		return self::wrap(
			$this->featured_inner(
				is_string( $attributes['title'] ?? null ) ? $attributes['title'] : '',
				is_string( $attributes['mode'] ?? null ) ? $attributes['mode'] : 'featured',
				(int) ( $attributes['count'] ?? 4 )
			),
			'bfrc-block-featured'
		);
	}

	/**
	 * Recipe Card block.
	 *
	 * @param mixed $attributes Block attributes.
	 */
	public function render_card( mixed $attributes = [] ): string {
		$attributes = is_array( $attributes ) ? $attributes : [];
		return self::wrap( $this->card_inner( (int) ( $attributes['recipeId'] ?? 0 ) ), 'bfrc-block-card' );
	}

	/**
	 * `[bfrc_recipes per_page="12" show_filters="1" show_pagination="1" diet="vegan" …]`:
	 * one attribute per enabled filter type fixes that filter.
	 *
	 * @param mixed $atts Shortcode attributes (core passes an array or '').
	 */
	public function shortcode_recipes( mixed $atts = [] ): string {
		$defaults = [
			'per_page'        => '',
			'show_filters'    => '1',
			'show_pagination' => '1',
		];
		$codes    = array_keys( $this->taxonomies->enabled() );
		foreach ( $codes as $code ) {
			$defaults[ $code ] = '';
		}
		$atts    = shortcode_atts( $defaults, is_array( $atts ) ? $atts : [], self::SHORTCODE_RECIPES );
		$filters = [];
		foreach ( $codes as $code ) {
			if ( is_string( $atts[ $code ] ) && '' !== $atts[ $code ] ) {
				$filters[ $code ] = $atts[ $code ];
			}
		}
		return wp_interactivity_process_directives(
			$this->list_inner(
				[
					'per_page'        => is_numeric( $atts['per_page'] ) ? (int) $atts['per_page'] : 0,
					'filters'         => $filters,
					'show_filters'    => Settings::to_bool( $atts['show_filters'] ),
					'show_pagination' => Settings::to_bool( $atts['show_pagination'] ),
				]
			)
		);
	}

	/**
	 * `[bfrc_featured title="Featured recipes" mode="featured|recent" count="4"]`.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode_featured( mixed $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'title' => __( 'Featured recipes', 'bluefairy-recipes' ),
				'mode'  => 'featured',
				'count' => '4',
			],
			is_array( $atts ) ? $atts : [],
			self::SHORTCODE_FEATURED
		);
		return $this->featured_inner( (string) $atts['title'], (string) $atts['mode'], is_numeric( $atts['count'] ) ? (int) $atts['count'] : 4 );
	}

	/**
	 * `[bfrc_writers]`: the writers listing, paged by `?bfrc_page=N` on the
	 * page that holds it. The same markup as the Recipe Writers template's
	 * block (`view=writers`) less its page heading.
	 *
	 * @param mixed $atts Shortcode attributes (none).
	 */
	public function shortcode_writers( mixed $atts = [] ): string {
		unset( $atts );
		if ( ! (bool) $this->settings->get( 'enabled' ) || ! (bool) $this->settings->get( 'writers_listing' ) ) {
			return '';
		}
		Templates::enqueue_assets();
		return $this->renderer->writers_list( RecipeQuery::requested_page(), false, $this->base_url() );
	}

	/**
	 * The list: the filter form (unless hidden), a status line for screen
	 * readers, the grid and the pagination, as one Interactivity API region.
	 * Fixed filters (`filters`, code => slug) are applied on top of the
	 * request's and their selects are left out of the form.
	 *
	 * @param array{per_page: int, filters: array<string, string>, show_filters: bool, show_pagination: bool} $args The list's settings.
	 */
	public function list_inner( array $args ): string {
		if ( ! (bool) $this->settings->get( 'enabled' ) ) {
			return '';
		}
		$fixed     = $this->query->normalize( [ 'filters' => $args['filters'] ] )['filters'];
		$requested = [];
		foreach ( $this->templates->requested_filters() as $taxonomy => $slug ) {
			$requested[ substr( $taxonomy, strlen( Taxonomies::PREFIX ) ) ] = $slug;
		}
		$requested = array_diff_key( $requested, $fixed );
		$list      = $this->query->normalize(
			[
				's'        => $this->templates->requested_search(),
				'filters'  => $fixed + $requested,
				'page'     => RecipeQuery::requested_page(),
				'per_page' => $args['per_page'],
			]
		);
		$query     = $this->query->run( $list, 'block' );
		$posts     = array_values( array_filter( is_array( $query->posts ) ? $query->posts : [], static fn( mixed $p ): bool => $p instanceof \WP_Post ) );
		$base      = $this->base_url();
		$pages     = (int) $query->max_num_pages;
		$url_args  = $this->url_args( $list['s'], $requested );
		wp_interactivity_state(
			self::STORE,
			[
				'restUrl'    => rest_url( Recipes::REST_NAMESPACE . Recipes::ROUTE ),
				'baseUrl'    => $base,
				'query'      => [
					's'      => $list['s'],
					'filter' => (object) $requested,
					'page'   => $list['page'],
				],
				'fixed'      => (object) $fixed,
				'perPage'    => $list['per_page'],
				'total'      => (int) $query->found_posts,
				'totalPages' => $pages,
				'hasPages'   => $pages > 1,
				'loading'    => false,
				'error'      => false,
				'cards'      => null,
				'status'     => '',
				'version'    => 0,
			]
		);
		wp_interactivity_config(
			self::STORE,
			[
				'vars'        => [
					's'      => Templates::SEARCH_VAR,
					'filter' => Templates::FILTER_VAR,
					'page'   => RecipeQuery::PAGE_VAR,
				],
				'emptyText'   => Renderer::empty_text(),
				'loadingText' => __( 'Loading recipes…', 'bluefairy-recipes' ),
				'errorText'   => __( 'The recipes could not be loaded. Press Filter to reload the page.', 'bluefairy-recipes' ),
				/* translators: %s: a number */
				'foundText'   => __( '%s recipes shown.', 'bluefairy-recipes' ),
				'prevText'    => __( '&laquo; Previous', 'bluefairy-recipes' ),
				'nextText'    => __( 'Next &raquo;', 'bluefairy-recipes' ),
				'debounce'    => 600,
			]
		);
		$this->enqueue();
		$html  = '<div class="bfrc-list" data-wp-interactive="' . esc_attr( self::STORE ) . '" data-wp-class--is-loading="state.loading">';
		$html .= $args['show_filters'] ? $this->filters_inner( array_keys( $fixed ), $base ) : '';
		$html .= '<p class="bfrc-list__status" role="status" aria-live="polite" data-wp-text="state.status"></p>';
		$html .= '<div class="bfrc-list__results" data-wp-watch="callbacks.renderResults">' . $this->renderer->grid( $posts ) . '</div>';
		if ( $args['show_pagination'] ) {
			$html .= '<nav class="bfrc-pagination" aria-label="' . esc_attr__( 'Recipes pagination', 'bluefairy-recipes' ) . '"' . ( $pages > 1 ? '' : ' hidden' ) . ' data-wp-bind--hidden="!state.hasPages" data-wp-watch="callbacks.renderPagination" data-wp-on--click="actions.paginate">';
			$html .= self::page_links( $list['page'], $pages, $base, $url_args ) . '</nav>';
		}
		return $html . '</div>';
	}

	/**
	 * The filter form of a list on a page: the archive's fields with the
	 * store's directives; a GET form to the page without the module.
	 *
	 * @param array<int, string> $hidden Filter-type codes fixed by the list (no select).
	 * @param string             $action The page URL.
	 */
	public function filters_inner( array $hidden, string $action ): string {
		$chosen = $this->templates->requested_filters();
		$search = $this->templates->requested_search();
		$html   = '<form class="bfrc-filters" method="get" action="' . esc_url( $action ) . '" role="search" aria-label="' . esc_attr__( 'Filter recipes', 'bluefairy-recipes' ) . '" aria-describedby="bfrc-filters-hint" data-wp-interactive="' . esc_attr( self::STORE ) . '" data-wp-on--submit="actions.submit">';
		$html  .= '<p id="bfrc-filters-hint" class="screen-reader-text bfrc-filters__hint">' . esc_html__( 'Changing a filter or pausing while typing updates the list below; the Filter button applies it at once.', 'bluefairy-recipes' ) . '</p>';
		$terms  = $this->renderer->terms_by_taxonomy();
		foreach ( $this->taxonomies->enabled() as $code => $definition ) {
			if ( in_array( $code, $hidden, true ) ) {
				continue;
			}
			$name    = Taxonomies::taxonomy_name( $code );
			$options = $terms[ $name ] ?? [];
			if ( [] === $options ) {
				continue;
			}
			$html .= '<label class="bfrc-filters__field"><span class="bfrc-filters__label">' . esc_html( $definition['singular'] ) . '</span>';
			$html .= '<select name="' . esc_attr( Templates::FILTER_VAR . '[' . $code . ']' ) . '" data-bfrc-code="' . esc_attr( $code ) . '" data-wp-on--change="actions.filter">';
			$html .= '<option value="">' . esc_html( sprintf( /* translators: %s: filter type plural name */ __( 'All %s', 'bluefairy-recipes' ), $definition['label'] ) ) . '</option>';
			foreach ( $options as $option ) {
				$html .= '<option value="' . esc_attr( $option->slug ) . '"' . selected( $chosen[ $name ] ?? '', $option->slug, false ) . '>' . esc_html( $option->name ) . '</option>';
			}
			$html .= '</select></label>';
		}
		$html .= '<label class="bfrc-filters__field bfrc-filters__field--search"><span class="bfrc-filters__label">' . esc_html__( 'Search by title', 'bluefairy-recipes' ) . '</span>';
		$html .= '<input type="search" name="' . esc_attr( Templates::SEARCH_VAR ) . '" value="' . esc_attr( $search ) . '" maxlength="' . esc_attr( (string) Templates::SEARCH_MAX ) . '" placeholder="' . esc_attr__( 'Recipe title', 'bluefairy-recipes' ) . '" data-wp-on--input="actions.keyword" data-wp-on--compositionend="actions.keyword"></label>';
		$html .= '<div class="bfrc-filters__actions"><button type="submit" class="bfrc-button">' . esc_html__( 'Filter', 'bluefairy-recipes' ) . '</button>';
		$html .= ' <a class="bfrc-filters__reset" href="' . esc_url( $action ) . '" data-wp-on--click="actions.reset"' . ( [] !== $chosen || '' !== $search ? '' : ' hidden' ) . ' data-wp-bind--hidden="!state.filtered">' . esc_html__( 'Reset', 'bluefairy-recipes' ) . '</a>';
		return $html . '</div></form>';
	}

	/**
	 * The featured (or recent) recipes: a heading and a grid; '' when there
	 * are none, so a page shows no empty section.
	 *
	 * @param string $title The heading ('' for none).
	 * @param string $mode  `featured` or `recent`.
	 * @param int    $count 1..12.
	 */
	public function featured_inner( string $title, string $mode, int $count ): string {
		if ( ! (bool) $this->settings->get( 'enabled' ) ) {
			return '';
		}
		$posts = $this->query->featured( $mode, $count );
		if ( [] === $posts ) {
			return '';
		}
		Templates::enqueue_assets();
		$title = sanitize_text_field( $title );
		$html  = '<section class="bfrc-featured">';
		if ( '' !== $title ) {
			$html .= '<h2 class="bfrc-featured__title">' . esc_html( $title ) . '</h2>';
		}
		return $html . $this->renderer->grid( $posts, '' !== $title ? 3 : 2 ) . '</section>';
	}

	/**
	 * One published recipe's card; '' for anything else.
	 *
	 * @param int $recipe_id The recipe.
	 */
	public function card_inner( int $recipe_id ): string {
		if ( $recipe_id < 1 || ! (bool) $this->settings->get( 'enabled' ) ) {
			return '';
		}
		$post = get_post( $recipe_id );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}
		Templates::enqueue_assets();
		$links = $this->renderer->writer_links( [ $post ] );
		return $this->renderer->card( $post, $links[ (int) $post->post_author ] ?? null );
	}

	/**
	 * The page list's pagination (`ul.page-numbers` as core's
	 * `paginate_links()` list form; the view script builds the same markup):
	 * first and last page, two either side of the current one, dots between.
	 * Every link carries `data-bfrc-page` for the store and a plain href for
	 * everyone else; '' when there is one page.
	 *
	 * @param int                  $current The current page.
	 * @param int                  $total   Pages.
	 * @param string               $base    The page URL.
	 * @param array<string, mixed> $args    Query arguments every link keeps (keyword, filters).
	 */
	public static function page_links( int $current, int $total, string $base, array $args ): string {
		if ( $total < 2 ) {
			return '';
		}
		$link = static function ( int $page, string $class_name, string $text ) use ( $base, $args ): string {
			$query = $page > 1 ? $args + [ RecipeQuery::PAGE_VAR => $page ] : $args;
			$url   = [] === $query ? $base : add_query_arg( $query, $base );
			return '<li><a class="' . esc_attr( $class_name ) . '" href="' . esc_url( $url ) . '" data-bfrc-page="' . esc_attr( (string) $page ) . '">' . $text . '</a></li>';
		};
		$html = '<ul class="page-numbers">';
		if ( $current > 1 ) {
			$html .= $link( $current - 1, 'prev page-numbers', esc_html__( '&laquo; Previous', 'bluefairy-recipes' ) );
		}
		foreach ( self::page_items( $current, $total ) as $item ) {
			if ( 'dots' === $item ) {
				$html .= '<li><span class="page-numbers dots">&hellip;</span></li>';
			} elseif ( $item === $current ) {
				$html .= '<li><span aria-current="page" class="page-numbers current">' . esc_html( (string) $item ) . '</span></li>';
			} else {
				$html .= $link( (int) $item, 'page-numbers', esc_html( (string) $item ) );
			}
		}
		if ( $current < $total ) {
			$html .= $link( $current + 1, 'next page-numbers', esc_html__( 'Next &raquo;', 'bluefairy-recipes' ) );
		}
		return $html . '</ul>';
	}

	/**
	 * Which page numbers to show (core's `paginate_links()` defaults:
	 * `end_size` 1, `mid_size` 2), `dots` for each gap.
	 *
	 * @param int $current The current page.
	 * @param int $total   Pages.
	 * @return array<int, int|string>
	 */
	public static function page_items( int $current, int $total ): array {
		$items = [];
		$dots  = false;
		for ( $n = 1; $n <= $total; $n++ ) {
			if ( 1 === $n || $n === $total || ( $n >= $current - 2 && $n <= $current + 2 ) ) {
				$items[] = $n;
				$dots    = true;
			} elseif ( $dots ) {
				$items[] = 'dots';
				$dots    = false;
			}
		}
		return $items;
	}

	/**
	 * The URL the list's form posts to and its page links build on: the
	 * page being viewed (its permalink), the post being edited in the
	 * editor's preview, or the request's path.
	 */
	public function base_url(): string {
		if ( Templates::is_editor_preview() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the block-renderer route verified its own nonce; only the id is read.
			$post_id = absint( wp_unslash( $_REQUEST['post_id'] ?? 0 ) );
			$link    = $post_id > 0 ? get_permalink( $post_id ) : false;
			return is_string( $link ) ? $link : home_url( '/' );
		}
		$id = is_singular() ? (int) get_queried_object_id() : 0;
		if ( $id > 0 ) {
			$link = get_permalink( $id );
			if ( is_string( $link ) ) {
				return $link;
			}
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
		return home_url( '' !== $path ? $path : '/' );
	}

	/**
	 * The query arguments a page link keeps.
	 *
	 * @param string                $search  The keyword.
	 * @param array<string, string> $filters code => slug, the request's (not the fixed ones).
	 * @return array<string, mixed>
	 */
	private function url_args( string $search, array $filters ): array {
		$args = []; // add_query_arg() does not encode values: encode them here.
		if ( '' !== $search ) {
			$args[ Templates::SEARCH_VAR ] = rawurlencode( $search );
		}
		if ( [] !== $filters ) {
			$args[ Templates::FILTER_VAR ] = array_map( 'rawurlencode', $filters );
		}
		return $args;
	}

	/**
	 * The front-end style and script, and the view module.
	 */
	private function enqueue(): void {
		Templates::enqueue_assets();
		wp_enqueue_script_module( self::MODULE );
	}

	/**
	 * A block's output: the wrapper element around the inner markup; ''
	 * when there is nothing to show.
	 *
	 * @param string $inner      The inner markup.
	 * @param string $class_name The block's own class.
	 */
	private static function wrap( string $inner, string $class_name ): string {
		if ( '' === $inner ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes( [ 'class' => $class_name ] ) . '>' . $inner . '</div>';
	}
}
