<?php
/**
 * Routing, templates and assets for the public recipe pages.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Front;

use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Both theme kinds render the same partials (Renderer):
 *
 * - Block themes: two block templates registered with
 *   `register_block_template()` (`single-bfrc_recipe`, `archive-bfrc_recipe`),
 *   each wrapping one server-rendered view block between the theme's header
 *   and footer parts; the taxonomy and author hierarchies are pointed at the
 *   archive template for recipe views.
 * - Classic themes: `template_include` returns the plugin's PHP template
 *   unless the theme ships `bluefairy-recipes/{name}.php`.
 *
 * Writer pages live at `{archive}/chef/` (listing) and `{archive}/chef/{nicename}/`
 * (that writer's recipes as an author archive of the recipe type). A writer
 * without a public page, or the listing while it is switched off, is a 404.
 */
final class Templates {

	public const STYLE        = 'bfrc-recipes';
	public const SCRIPT       = 'bfrc-recipes';
	public const BLOCK_SINGLE = 'bfrc/recipe-single';
	public const BLOCK_LIST   = 'bfrc/recipe-archive';
	public const SEARCH_VAR   = 'bfrc_s';
	public const SEARCH_MAX   = 100;

	/**
	 * Wire the dependencies.
	 *
	 * @param Settings   $settings   The settings service.
	 * @param Writers    $writers    The writer rules.
	 * @param Renderer   $renderer   The partials.
	 * @param Taxonomies $taxonomies The filter types.
	 */
	public function __construct( private Settings $settings, private Writers $writers, private Renderer $renderer, private Taxonomies $taxonomies ) {}

	/**
	 * Hook everything.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 20 );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'pre_get_posts', [ $this, 'shape_query' ] );
		add_action( 'template_redirect', [ $this, 'guard' ] );
		add_filter( 'template_include', [ $this, 'template_include' ] );
		add_filter( 'taxonomy_template_hierarchy', [ $this, 'archive_hierarchy' ] );
		add_filter( 'author_template_hierarchy', [ $this, 'archive_hierarchy' ] );
		add_filter( 'archive_template_hierarchy', [ $this, 'archive_hierarchy' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'comments_open', [ $this, 'comments_closed' ], 10, 2 );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Routes, query vars and block templates.
	 */
	public function register(): void {
		$this->writers->add_rewrite_rules();
		register_block_type(
			self::BLOCK_SINGLE,
			[
				'api_version'     => '3',
				'render_callback' => [ $this, 'render_single_block' ],
			]
		);
		register_block_type(
			self::BLOCK_LIST,
			[
				'api_version'     => '3',
				'render_callback' => [ $this, 'render_archive_block' ],
			]
		);
		if ( function_exists( 'register_block_template' ) ) {
			register_block_template(
				'bluefairy-recipes//single-' . PostType::NAME,
				[
					'title'       => __( 'Single Recipe', 'bluefairy-recipes' ),
					'description' => __( 'A recipe: photo or video, timings, ingredients, steps, nutrition and sharing.', 'bluefairy-recipes' ),
					'content'     => (string) file_get_contents( BFRC_DIR . 'templates/block/single-recipe.html' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a plugin file, not a remote resource.
					'post_types'  => [ PostType::NAME ],
				]
			);
			register_block_template(
				'bluefairy-recipes//archive-' . PostType::NAME,
				[
					'title'       => __( 'Recipes Archive', 'bluefairy-recipes' ),
					'description' => __( 'The recipe grid with filters, a filter type, a writer\'s recipes, or the writers list.', 'bluefairy-recipes' ),
					'content'     => (string) file_get_contents( BFRC_DIR . 'templates/block/archive-recipe.html' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- as above.
				]
			);
		}
	}

	/**
	 * The writer query vars and the search var.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function query_vars( array $vars ): array {
		$vars   = $this->writers->query_vars( $vars );
		$vars[] = self::SEARCH_VAR;
		return $vars;
	}

	/**
	 * Is this the main query for one of our public views?
	 *
	 * @param \WP_Query $query The query.
	 */
	public function is_recipe_query( \WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() ) {
			return false;
		}
		if ( $query->is_singular( PostType::NAME ) || $query->is_post_type_archive( PostType::NAME ) ) {
			return true;
		}
		if ( $query->is_tax( $this->taxonomy_names() ) ) {
			return true;
		}
		return (bool) $query->get( Writers::LIST_VAR ) || (bool) $query->get( Writers::QUERY_VAR );
	}

	/**
	 * The archive, taxonomy and writer queries: per-page, title-only keyword
	 * search, taxonomy filters from the filter form, and the writers listing
	 * (which needs no posts at all).
	 *
	 * @param \WP_Query $query The query.
	 */
	public function shape_query( \WP_Query $query ): void {
		if ( ! $this->is_recipe_query( $query ) || $query->is_singular() ) {
			return;
		}
		$query->set( 'post_type', PostType::NAME );
		if ( (bool) $query->get( Writers::LIST_VAR ) ) {
			// The listing page: the post query is only a vehicle for the template, one recipe per
			// page. `paged` stays: the writers listing reads it, and core's canonical redirect must
			// see a real page (every public writer has a recipe, so recipe pages ≥ writer pages).
			$query->set( 'posts_per_page', 1 );
			$query->set( 'ignore_sticky_posts', true );
			return;
		}
		$query->set( 'posts_per_page', $this->per_page() );
		$query->set( 'ignore_sticky_posts', true );
		$search = $this->requested_search();
		if ( '' !== $search ) {
			$query->set( 's', $search );
			$query->set( 'search_columns', [ 'post_title' ] );
		}
		$tax_query = $query->get( 'tax_query' );
		$tax_query = is_array( $tax_query ) ? $tax_query : [];
		foreach ( $this->requested_filters() as $taxonomy => $slug ) {
			if ( $query->is_tax( $taxonomy ) ) {
				continue; // The URL already names this term.
			}
			$tax_query[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => [ $slug ],
			];
		}
		if ( [] !== $tax_query ) {
			$query->set( 'tax_query', $tax_query );
		}
		/**
		 * The public recipe list query is about to run.
		 *
		 * @since 1.0.0
		 *
		 * @param \WP_Query $query The main query, already shaped.
		 */
		do_action( 'bfrc_archive_query', $query );
	}

	/**
	 * 404 a writer page that must not exist, and the listing while it is off.
	 */
	public function guard(): void {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || is_admin() ) {
			return;
		}
		$writer  = (bool) $wp_query->get( Writers::QUERY_VAR );
		$listing = (bool) $wp_query->get( Writers::LIST_VAR );
		if ( ! $writer && ! $listing ) {
			return;
		}
		$ok = $listing ? $this->writers->listing_enabled() : null !== $this->writers->public_writer( (string) $wp_query->get( 'author_name' ) );
		if ( $ok ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Classic themes: our template unless the theme overrides it.
	 *
	 * @param string $template The template core chose.
	 */
	public function template_include( string $template ): string {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_404() || ! $this->is_recipe_query( $wp_query ) ) {
			return $template;
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return $template; // The block templates registered above apply through the hierarchy.
		}
		return self::locate( $wp_query->is_singular( PostType::NAME ) ? 'single-recipe' : 'archive-recipe' );
	}

	/**
	 * The theme's `bluefairy-recipes/{name}.php` or the plugin's template.
	 *
	 * @param string $name Template name without extension.
	 */
	public static function locate( string $name ): string {
		$theme = locate_template( [ 'bluefairy-recipes/' . $name . '.php' ] );
		return '' !== $theme ? $theme : BFRC_DIR . 'templates/' . $name . '.php';
	}

	/**
	 * Block themes: taxonomy, author and generic archive views of recipes use
	 * the recipe archive template.
	 *
	 * @param array<int, string> $templates Core's candidates.
	 * @return array<int, string>
	 */
	public function archive_hierarchy( array $templates ): array {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_singular() || ! $this->is_recipe_query( $wp_query ) ) {
			return $templates;
		}
		array_unshift( $templates, 'archive-' . PostType::NAME );
		return array_values( array_unique( $templates ) );
	}

	/**
	 * Styles and the filter script, only on recipe views.
	 */
	public function enqueue(): void {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_404() || ! $this->is_recipe_query( $wp_query ) ) {
			return;
		}
		self::enqueue_assets();
	}

	/**
	 * Enqueue the front-end style and script (also called by blocks and
	 * shortcodes when they render).
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( self::STYLE, BFRC_URL . 'assets/front/recipes.css', [], BFRC_VERSION );
		wp_enqueue_script( self::SCRIPT, BFRC_URL . 'assets/front/recipes.js', [], BFRC_VERSION, true );
	}

	/**
	 * Recipes take no comments (Pro reviews use their own type).
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id The post.
	 */
	public function comments_closed( bool $open, int $post_id ): bool {
		return get_post_type( $post_id ) === PostType::NAME ? false : $open;
	}

	/**
	 * `bfrc-recipes` on every recipe view.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function body_class( array $classes ): array {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query && ! $wp_query->is_404() && $this->is_recipe_query( $wp_query ) ) {
			$classes[] = 'bfrc-recipes';
		}
		return $classes;
	}

	/**
	 * The single-recipe view block.
	 */
	public function render_single_block(): string {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return '';
		}
		self::enqueue_assets();
		return $this->renderer->single( $post );
	}

	/**
	 * The archive view block: grid with filters, a writer's recipes, or the
	 * writers listing, from the main query.
	 */
	public function render_archive_block(): string {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return '';
		}
		self::enqueue_assets();
		return $this->renderer->archive( $wp_query );
	}

	/**
	 * The keyword typed into the filter form, title-only, ≤ 100 characters.
	 */
	public function requested_search(): string {
		$raw = $_GET[ self::SEARCH_VAR ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- a public read-only filter; sanitised on the next line.
		if ( ! is_string( $raw ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( wp_unslash( $raw ) ), 0, self::SEARCH_MAX );
	}

	/**
	 * The filter form's taxonomy choices: taxonomy name => term slug.
	 *
	 * @return array<string, string>
	 */
	public function requested_filters(): array {
		$out = [];
		foreach ( $this->taxonomy_names() as $name ) {
			$raw = $_GET[ $name ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- a public read-only filter; sanitised on the next line.
			if ( ! is_string( $raw ) || '' === $raw || '0' === $raw ) {
				continue;
			}
			$slug = sanitize_title( wp_unslash( $raw ) );
			if ( '' !== $slug ) {
				$out[ $name ] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Recipes per archive page (1..100).
	 */
	public function per_page(): int {
		return max( 1, min( 100, (int) $this->settings->get( 'per_page' ) ) );
	}

	/**
	 * Names of the enabled recipe taxonomies, in sort order.
	 *
	 * @return array<int, string>
	 */
	public function taxonomy_names(): array {
		return array_map( [ Taxonomies::class, 'taxonomy_name' ], array_keys( $this->taxonomies->enabled() ) );
	}
}
