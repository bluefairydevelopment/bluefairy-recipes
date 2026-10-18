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
 *   archive template for recipe views. A theme's own `archive-bfrc_recipe.html`
 *   wins, as core resolves theme files before plugin templates.
 * - Classic themes: `template_include` returns the plugin's PHP template
 *   unless the theme ships `bluefairy-recipes/{name}.php`, or its own
 *   `single-bfrc_recipe.php` / `archive-bfrc_recipe.php` / `taxonomy-bfrc_*.php`.
 *
 * Writer pages live at `{archive}/chef/` (listing) and `{archive}/chef/{nicename}/`
 * (that writer's recipes as an author archive of the recipe type). A writer
 * without a public page, the listing while it is switched off, and a listing
 * page past the last writer are 404s.
 */
final class Templates {

	public const STYLE        = 'bfrc-recipes';
	public const SCRIPT       = 'bfrc-recipes';
	public const BLOCK_SINGLE = 'bfrc/recipe-single';
	public const BLOCK_LIST   = 'bfrc/recipe-archive';
	public const SEARCH_VAR   = 'bfrc_s';
	public const FILTER_VAR   = 'bfrc_filter';
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
		add_filter( 'document_title_parts', [ $this, 'document_title' ] );
		add_filter( 'wp_sitemaps_users_query_args', [ $this->writers, 'sitemap_users_query' ] );
	}

	/**
	 * Routes, query vars and block templates.
	 */
	public function register(): void {
		$this->writers->add_rewrite_rules();
		// `autoRegister` (WordPress 7.0+) gives the Site Editor a server-rendered preview of a
		// block that ships no editor script; older versions show a placeholder to leave in place.
		$block_args = [
			'api_version' => '3',
			'supports'    => [ 'autoRegister' => true ],
		];
		register_block_type( self::BLOCK_SINGLE, $block_args + [ 'render_callback' => [ $this, 'render_single_block' ] ] );
		register_block_type( self::BLOCK_LIST, $block_args + [ 'render_callback' => [ $this, 'render_archive_block' ] ] );
		if ( function_exists( 'register_block_template' ) ) {
			register_block_template(
				'bluefairy-recipes//single-' . PostType::NAME,
				[
					'title'       => __( 'Single Recipe', 'bluefairy-recipes' ),
					'description' => __( 'A recipe: photo or video, timings, ingredients, steps, nutrition and sharing.', 'bluefairy-recipes' ),
					'content'     => self::template_markup( 'single-recipe' ),
					'post_types'  => [ PostType::NAME ],
				]
			);
			register_block_template(
				'bluefairy-recipes//archive-' . PostType::NAME,
				[
					'title'       => __( 'Recipes Archive', 'bluefairy-recipes' ),
					'description' => __( 'The recipe grid with filters, a filter type, a writer\'s recipes, or the writers list.', 'bluefairy-recipes' ),
					'content'     => self::template_markup( 'archive-recipe' ),
				]
			);
		}
	}

	/**
	 * A block template's markup from `templates/block/`, '' when the file is
	 * missing (core then shows an empty template rather than a warning).
	 *
	 * @param string $name File name without extension.
	 */
	public static function template_markup( string $name ): string {
		$path = BFRC_DIR . 'templates/block/' . $name . '.html';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$markup = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a plugin file, not a remote resource.
		return is_string( $markup ) ? $markup : '';
	}

	/**
	 * The writer query vars and the search var (the filter var is an array
	 * read straight from the request, never a public query var: a taxonomy
	 * query var would turn the filtered archive into a term page).
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
	 * Is this the main query for one of our public views? Never while the
	 * plugin is disabled (the guard still 404s the writer vars then).
	 *
	 * @param \WP_Query $query The query.
	 */
	public function is_recipe_query( \WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() || ! (bool) $this->settings->get( 'enabled' ) ) {
			return false;
		}
		if ( $query->is_singular( PostType::NAME ) || $query->is_post_type_archive( PostType::NAME ) ) {
			return true;
		}
		$names = $this->taxonomy_names();
		if ( [] !== $names && $query->is_tax( $names ) ) {
			return true; // Never is_tax( [] ): core reads an empty list as "any taxonomy".
		}
		// The writer routes always carry the post type; a bare `?bfrc_writers=1` on another URL is not ours.
		return PostType::NAME === $query->get( 'post_type' ) && ( (bool) $query->get( Writers::LIST_VAR ) || (bool) $query->get( Writers::QUERY_VAR ) );
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
			// page. `paged` stays: the writers listing reads it, and guard() 404s pages past the
			// last writer; core must still see a real page for the pages that exist.
			$query->set( 'posts_per_page', 1 );
			return;
		}
		$query->set( 'posts_per_page', $this->per_page() );
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
	 * 404 a writer page that must not exist, the listing while it is off,
	 * and a listing page past the last writer. Runs whether or not the
	 * plugin is enabled: the query vars are registered either way.
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
		if ( $listing ) {
			$ok = $this->writers->listing_enabled();
			if ( $ok ) {
				$page = max( 1, (int) $wp_query->get( 'paged' ) );
				$ok   = 1 === $page || $page <= $this->writers->listed( $page )['pages'];
			}
		} else {
			$ok = (bool) $this->settings->get( 'enabled' ) && null !== $this->writers->public_writer( (string) $wp_query->get( 'author_name' ) );
		}
		if ( $ok ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Classic themes: our template unless the theme overrides it, either
	 * with `bluefairy-recipes/{name}.php` or with its own recipe template
	 * (`single-bfrc_recipe.php`, `archive-bfrc_recipe.php`, `taxonomy-bfrc_*.php`).
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
		if ( self::is_theme_recipe_template( $template ) ) {
			return $template;
		}
		return self::locate( $wp_query->is_singular( PostType::NAME ) ? 'single-recipe' : 'archive-recipe' );
	}

	/**
	 * Did the theme pick one of its own recipe-specific templates?
	 *
	 * @param string $template Core's chosen template path.
	 */
	public static function is_theme_recipe_template( string $template ): bool {
		$name = basename( $template );
		return in_array( $name, [ 'single-' . PostType::NAME . '.php', 'archive-' . PostType::NAME . '.php' ], true ) || str_starts_with( $name, 'taxonomy-bfrc_' );
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
		return $templates;
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
	 * Writer pages get their own document title: the writer's name, or
	 * "Recipe writers" for the listing (core would say "Recipes" for both).
	 *
	 * @param array<string, string> $parts Core's title parts.
	 * @return array<string, string>
	 */
	public function document_title( array $parts ): array {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_404() || $wp_query->is_singular() || ! $this->is_recipe_query( $wp_query ) ) {
			return $parts;
		}
		if ( (bool) $wp_query->get( Writers::LIST_VAR ) ) {
			$parts['title'] = __( 'Recipe writers', 'bluefairy-recipes' );
		} elseif ( (bool) $wp_query->get( Writers::QUERY_VAR ) ) {
			$writer = $this->writers->public_writer( (string) $wp_query->get( 'author_name' ) );
			if ( $writer instanceof \WP_User ) {
				$parts['title'] = $writer->display_name;
			}
		}
		return $parts;
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
	 * The filter form's taxonomy choices, `bfrc_filter[{code}]=slug`:
	 * taxonomy name => term slug. Only enabled codes; a non-string, empty or
	 * `0` value is no choice.
	 *
	 * @return array<string, string>
	 */
	public function requested_filters(): array {
		$raw = $_GET[ self::FILTER_VAR ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- a public read-only filter; each value is type-checked and sanitised below.
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( array_keys( $this->taxonomies->enabled() ) as $code ) {
			$value = $raw[ $code ] ?? '';
			if ( ! is_string( $value ) || '' === $value || '0' === $value ) {
				continue;
			}
			$slug = sanitize_title( wp_unslash( $value ) );
			if ( '' !== $slug ) {
				$out[ Taxonomies::taxonomy_name( $code ) ] = $slug;
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
