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
use BlueFairy\Recipes\Support\Terminate;

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
	/** Block template slug of the writer views. */
	public const WRITERS_TEMPLATE = 'bfrc-writers';

	/**
	 * The view blocks' editor script (built from `src/view-blocks.js`): it
	 * registers their `edit` as a server-side render, whose request carries
	 * `post_id` in the post editor and not in the Site Editor.
	 */
	public const VIEW_BLOCKS_SCRIPT = 'bfrc-view-blocks';

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
		// The SEO plugins replace the whole document title (`pre_get_document_title`) with the
		// post-type archive's on a writer view: hand them the writer view's own.
		add_filter( 'wpseo_title', [ $this, 'seo_plugin_title' ] );
		add_filter( 'rank_math/frontend/title', [ $this, 'seo_plugin_title' ] );
		add_filter( 'aioseo_title', [ $this, 'seo_plugin_title' ] );
		add_action( 'wp_head', [ $this, 'rel_canonical' ] );
		add_action( 'enqueue_block_assets', [ self::class, 'register_assets' ], 1 ); // Before core enqueues the blocks' editor_style handle (priority 10); not at init, where it would build WP_Scripts on requests that never print HTML.
		add_filter( 'rest_queried_resource_route', [ $this, 'rest_resource_route' ] );
		// The SEO plugins print their own canonical from the queried object (the post type or the
		// author archive, never the writer view): hand them ours, and print none of our own then.
		add_filter( 'wpseo_canonical', [ $this, 'seo_plugin_canonical' ] );
		add_filter( 'rank_math/frontend/canonical', [ $this, 'seo_plugin_canonical' ] );
		add_filter( 'aioseo_canonical_url', [ $this, 'seo_plugin_canonical' ] );
		// BlueFairy SEO Toolkit 1.1.2+: its og:url (and, with Pro, its rel=canonical) reads this filter.
		add_filter( 'bfst_canonical_url', [ $this, 'seo_plugin_canonical' ] );
	}

	/**
	 * `rest_queried_resource_route`: core's `<link rel="alternate" type="application/json">`
	 * for the main query. A writer page is both an author archive and a
	 * post-type archive, and core's queried object is then the post type, so
	 * it would advertise `/wp/v2/users/0`: name the writer instead, and
	 * nothing on the listing.
	 *
	 * @param mixed $route Core's route ('' or a string; another filter may have changed it).
	 */
	public function rest_resource_route( mixed $route ): mixed {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_404() || ! $this->is_recipe_query( $wp_query ) || ! self::is_writer_view( $wp_query ) ) {
			return $route;
		}
		if ( (bool) $wp_query->get( Writers::LIST_VAR ) ) {
			return '';
		}
		$writer = $this->writers->public_writer( (string) $wp_query->get( 'author_name' ) );
		return null === $writer ? '' : sprintf( '/wp/v2/users/%d', $writer->ID ); // The route core builds for a WP_User (there is no rest_get_route_for_user()).
	}

	/**
	 * Is one of the SEO plugins whose canonical filters we feed active?
	 */
	public static function seo_plugin_present(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'AIOSEO_VERSION' ) || class_exists( 'RankMath', false );
	}

	/**
	 * Will BlueFairy SEO Toolkit print a `rel=canonical` on this request?
	 * Only its Pro add-on registers a canonical renderer; the free plugin
	 * prints `og:url` alone, so with the free plugin the canonical stays
	 * ours. Off when the toolkit pauses for another SEO plugin or the
	 * feature is switched off (`HeadManager::will_render()`, 1.1.0+), and
	 * when its page context has no canonical (it prints none then).
	 */
	public static function seo_toolkit_prints_canonical(): bool {
		$manager  = 'BlueFairy\\SeoToolkit\\Head\\HeadManager';
		$context  = 'BlueFairy\\SeoToolkit\\PageContext';
		$renderer = [ $manager, 'get_renderer' ];
		$will     = [ $manager, 'will_render' ];
		$current  = [ $context, 'current' ];
		// Never autoloaded: without the toolkit loaded there is nothing to defer to.
		if ( ! class_exists( $manager, false ) || ! class_exists( $context, false ) || ! is_callable( $renderer ) || ! is_callable( $will ) || ! is_callable( $current ) ) {
			return false;
		}
		if ( null === call_user_func( $renderer, 'canonical' ) || ! call_user_func( $will, 'canonical' ) ) {
			return false;
		}
		$page = call_user_func( $current );
		return is_object( $page ) && isset( $page->canonical ) && '' !== $page->canonical;
	}

	/**
	 * `wpseo_canonical`, `rank_math/frontend/canonical`, `aioseo_canonical_url`,
	 * `bfst_canonical_url`:
	 * the writer view's URL in place of what the SEO plugin computed.
	 *
	 * @param mixed $url The SEO plugin's canonical.
	 */
	public function seo_plugin_canonical( mixed $url ): mixed {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_404() || ! $this->is_recipe_query( $wp_query ) ) {
			return $url;
		}
		$ours = $this->canonical_url( $wp_query );
		if ( '' === $ours ) {
			return $url;
		}
		/** This filter is documented in rel_canonical(). */
		$ours = (string) apply_filters( 'bfrc_writer_canonical', $ours, $wp_query ); // The site's say applies with an SEO plugin too; '' means "leave the SEO plugin's own".
		return '' !== $ours ? $ours : $url;
	}

	/**
	 * `enqueue_block_assets` (priority 1): register the stylesheet and script
	 * so the blocks' `editor_style` handle resolves in the Site Editor's
	 * canvas and the front end can enqueue by handle. Not done at `init`:
	 * that would build `WP_Scripts` on sitemaps, feeds and redirects too.
	 */
	public static function register_assets(): void {
		wp_register_style( self::STYLE, BFRC_URL . 'assets/front/recipes.css', [], BFRC_VERSION );
		wp_register_script( self::SCRIPT, BFRC_URL . 'assets/front/recipes.js', [], BFRC_VERSION, true );
		self::register_view_blocks_script();
	}

	/**
	 * Register the view blocks' editor script from its built asset file (on
	 * `enqueue_block_assets`, with the other assets: never at `init`); false
	 * when the build is absent (a source checkout), in which case the handle
	 * the blocks name stays unregistered and they keep core's auto-registered
	 * preview.
	 *
	 * @param string $asset_file The asset file ('' for the plugin's build; a test names a missing one).
	 */
	public static function register_view_blocks_script( string $asset_file = '' ): bool {
		$asset_file = '' === $asset_file ? BFRC_DIR . 'build/view-blocks.asset.php' : $asset_file;
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}
		$asset = include $asset_file;
		$asset = is_array( $asset ) ? $asset : [];
		return (bool) wp_register_script(
			self::VIEW_BLOCKS_SCRIPT,
			BFRC_URL . 'build/view-blocks.js',
			is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : [],
			is_string( $asset['version'] ?? null ) ? $asset['version'] : BFRC_VERSION,
			true
		);
	}

	/**
	 * Routes, query vars and block templates.
	 */
	public function register(): void {
		$this->writers->add_rewrite_rules();
		// `autoRegister` (WordPress 7.0+) gives the Site Editor a server-rendered preview of a
		// block that ships no editor script; older versions show a placeholder to leave in place.
		// `editor_style` only: core enqueues a block's `style` handle on EVERY page of a classic theme
		// (block themes load block assets on demand), which would have put the print rules on the
		// whole site. The front end enqueues through enqueue_assets() when a view renders.
		$block_args = [
			'api_version'           => '3',
			'supports'              => [
				'autoRegister' => true,
				'inserter'     => false, // Template blocks: core's auto-registration would otherwise list them in the inserter under their raw names.
			],
			'editor_style'          => self::STYLE,
			'editor_script_handles' => [ self::VIEW_BLOCKS_SCRIPT ], // Registered in register_assets(); core enqueues it in the editors.
			'attributes'            => [
				'view' => [
					'type'    => 'string',
					'default' => '',
				],
			],
		];
		register_block_type(
			self::BLOCK_SINGLE,
			$block_args + [
				'title'           => __( 'Single Recipe', 'bluefairy-recipes' ),
				'description'     => __( 'The recipe the template is showing.', 'bluefairy-recipes' ),
				'category'        => 'theme',
				'render_callback' => [ $this, 'render_single_block' ],
			]
		);
		register_block_type(
			self::BLOCK_LIST,
			$block_args + [
				'title'           => __( 'Recipes Archive', 'bluefairy-recipes' ),
				'description'     => __( 'The recipe grid with filters, a filter type, a writer, or the writers list.', 'bluefairy-recipes' ),
				'category'        => 'theme',
				'render_callback' => [ $this, 'render_archive_block' ],
			]
		);
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
					'description' => __( 'The recipe grid with filters, or a filter type\'s recipes.', 'bluefairy-recipes' ),
					'content'     => self::template_markup( 'archive-recipe' ),
				]
			);
			// The writer views get their own template, so a theme's archive-bfrc_recipe.html (which
			// wins over ours by slug) does not catch them and loop one recipe; a theme overrides
			// this one only by shipping bfrc-writers.html deliberately.
			register_block_template(
				'bluefairy-recipes//' . self::WRITERS_TEMPLATE,
				[
					'title'       => __( 'Recipe Writers', 'bluefairy-recipes' ),
					'description' => __( 'The writers list and a writer\'s page with their recipes.', 'bluefairy-recipes' ),
					'content'     => self::template_markup( 'writers' ),
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
		if ( (int) $query->get( 'paged' ) > RecipeQuery::MAX_PAGE ) {
			// `?paged=99999999` would make MySQL walk that many rows to OFFSET past them. One past
			// the cap is still past the last page (core 404s it), at the cost of a bounded scan.
			$query->set( 'paged', RecipeQuery::MAX_PAGE + 1 );
		}
		if ( (bool) $query->get( Writers::LIST_VAR ) ) {
			// The listing page: the post query is only a vehicle for the template, one recipe per
			// page. `paged` stays: the writers listing reads it, and guard() 404s pages past the
			// last writer; core must still see a real page for the pages that exist.
			$query->set( 'posts_per_page', 1 );
			$query->set( 'no_found_rows', true ); // SQL_CALC_FOUND_ROWS over every recipe (50 ms at 100k) for a count nothing reads.
			$query->set( 'update_post_meta_cache', false );
			$query->set( 'update_post_term_cache', false );
			return;
		}
		$query->set( 'posts_per_page', $this->per_page() );
		if ( (bool) $query->get( Writers::QUERY_VAR ) ) {
			// A writer's page is that writer's recipes, filtered by `author_name`, but it is our
			// view, not the theme's author archive: with `is_author` set, an SEO plugin's "author
			// archives off" (Yoast, Rank Math) redirects it to the home page. The SQL still filters
			// by author: get_posts() reads the query var, not the flag. The queried object is the
			// post type either way (core's order puts the post-type archive first).
			$query->is_author = false;
			return; // A writer's page has no filter form: a keyword or filter in its URL is ignored, not silently applied.
		}
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
	 * plugin is enabled: the query vars are registered either way. The
	 * query's posts are emptied too: core's feed loader does not look at
	 * `set_404()`, so a 404ed writer feed would otherwise still carry the
	 * recipes.
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
			$this->redirect_to_canonical( $wp_query );
			return;
		}
		$wp_query->posts             = [];
		$wp_query->post_count        = 0;
		$wp_query->found_posts       = 0;
		$wp_query->max_num_pages     = 0;
		$wp_query->post              = null;
		$wp_query->queried_object    = null; // The non-public writer must not survive into the 404 page's head.
		$wp_query->queried_object_id = 0;
		$GLOBALS['post']             = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the 404 page must not carry the hidden writer's first recipe.
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * The pretty URL of the current writer view (`{archive}/chef/`,
	 * `{archive}/chef/{nicename}/`, with `/page/N/`), or '' when the query is
	 * not a writer view or the writer has no page.
	 *
	 * @param \WP_Query $query The main query.
	 */
	public function canonical_url( \WP_Query $query ): string {
		$page = max( 1, (int) $query->get( 'paged' ) );
		if ( (bool) $query->get( Writers::LIST_VAR ) ) {
			$base = $this->writers->listing_url();
		} elseif ( (bool) $query->get( Writers::QUERY_VAR ) ) {
			$writer = $this->writers->public_writer( (string) $query->get( 'author_name' ) );
			if ( null === $writer ) {
				return '';
			}
			$base = $this->writers->url( $writer );
		} else {
			return '';
		}
		if ( $page > 1 ) {
			$base = Writers::uses_permalinks()
				? trailingslashit( $base ) . user_trailingslashit( Writers::pagination_base() . '/' . $page, 'paged' )
				: add_query_arg( 'paged', $page, $base );
		}
		return $base;
	}

	/**
	 * The writer views answer to their pretty URLs only. The rewrite rules
	 * pass the writer vars inside the request, never through the query
	 * string, so `?bfrc_writers=1` or `?bfrc_writer=1&author_name=…` in
	 * `$_GET` (or the request body: core reads query vars from `$_POST` too)
	 * is the duplicate form, and so is `?author_name=` or `?paged=` overriding
	 * what the pretty route matched: redirect (301) when pretty permalinks are
	 * on, keeping every other query argument (a keyword, the filters), as
	 * core's `redirect_canonical()` keeps what it did not consume. Nothing
	 * happens for feeds (their URLs are core's) or without permalinks (the
	 * query string is then the only URL there is).
	 *
	 * @param \WP_Query $query The main query, already judged valid.
	 */
	private function redirect_to_canonical( \WP_Query $query ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- public query arguments of a GET view, carried over sanitised.
		$consumed = [ Writers::LIST_VAR, Writers::QUERY_VAR, 'author_name', 'paged', 'post_type' ];
		$in_query = false;
		foreach ( $consumed as $var ) {
			if ( isset( $_GET[ $var ] ) || isset( $_POST[ $var ] ) ) {
				$in_query = true;
				break;
			}
		}
		if ( ! $in_query || ! Writers::uses_permalinks() ) {
			return;
		}
		$url = $this->canonical_url( $query ); // Never '' here: guard() calls this only for a listing it allows or a public writer.
		if ( $query->is_feed() ) {
			// A feed's URL is core's, so a pretty feed is never redirected; but `?author_name=` on a
			// writer's feed URL would serve another writer's items there, so that override goes to
			// the resolved writer's own feed.
			$writer = (bool) $query->get( Writers::QUERY_VAR ) && ( isset( $_GET['author_name'] ) || isset( $_POST['author_name'] ) )
				? $this->writers->public_writer( (string) $query->get( 'author_name' ) )
				: null;
			if ( null !== $writer ) {
				// The writer's feed URL as the rules serve it: no page segment (core paginates feeds by
				// query argument), the requested format kept when it is not the default.
				$feed   = (string) $query->get( 'feed' );
				$suffix = 'feed/' . ( in_array( $feed, Writers::feed_formats(), true ) && 'feed' !== $feed && get_default_feed() !== $feed ? $feed . '/' : '' ); // Only a format core serves (the same list the writer routes use); never a caller's string in the Location.
				Terminate::redirect( trailingslashit( $this->writers->url( $writer ) ) . $suffix, 301, 'BlueFairy Recipes' );
			}
			return;
		}
		$keep = [];
		foreach ( $_GET as $key => $value ) {
			if ( in_array( (string) $key, $consumed, true ) ) {
				continue;
			}
			$keep[ sanitize_key( (string) $key ) ] = map_deep( wp_unslash( $value ), static fn( mixed $v ): string => rawurlencode( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ) ) ); // add_query_arg() does not encode values.
		}
		// phpcs:enable
		unset( $keep[''] );
		if ( [] !== $keep ) {
			$url = add_query_arg( $keep, $url );
		}
		Terminate::redirect( $url, 301, 'BlueFairy Recipes' );
	}

	/**
	 * `wp_head`: a self-referencing canonical on the writer views. Core
	 * prints one for singular pages only; a writer page or the listing is an
	 * archive, so without this an SEO plugin that reads the queried object
	 * (the post type) may point the canonical somewhere else. Filter
	 * `bfrc_writer_canonical` (return '' to print nothing).
	 */
	public function rel_canonical(): void {
		global $wp_query;
		if ( self::seo_plugin_present() || self::seo_toolkit_prints_canonical() || ! $wp_query instanceof \WP_Query || $wp_query->is_404() || ! $this->is_recipe_query( $wp_query ) ) {
			return; // With an SEO plugin, its canonical carries ours (seo_plugin_canonical()); two tags would cancel out.
		}
		$url = $this->canonical_url( $wp_query );
		if ( '' === $url ) {
			return;
		}
		/**
		 * The canonical URL printed on a writer page or the writers listing.
		 *
		 * @param string    $url   The pretty URL of the view.
		 * @param \WP_Query $query The main query.
		 */
		$url = (string) apply_filters( 'bfrc_writer_canonical', $url, $wp_query );
		if ( '' !== $url ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
		}
	}

	/**
	 * Classic themes: our template unless the theme overrides it, either
	 * with `bluefairy-recipes/{name}.php` or with its own recipe template
	 * (`single-bfrc_recipe.php`, `archive-bfrc_recipe.php`, `taxonomy-bfrc_*.php`).
	 * The writer views are not recipe archives (the listing's post query is
	 * one recipe per page, a vehicle for the template), so a theme's own
	 * `archive-bfrc_recipe.php` does not catch them; `bluefairy-recipes/archive-recipe.php`
	 * still does, as it calls the Renderer.
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
		if ( ! self::is_writer_view( $wp_query ) && self::is_theme_recipe_template( $template ) ) {
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
		if ( self::is_writer_view( $wp_query ) ) {
			array_unshift( $templates, self::WRITERS_TEMPLATE );
		}
		return $templates;
	}

	/**
	 * The writers listing or a writer's page?
	 *
	 * @param \WP_Query $query The query.
	 */
	public static function is_writer_view( \WP_Query $query ): bool {
		return (bool) $query->get( Writers::LIST_VAR ) || (bool) $query->get( Writers::QUERY_VAR );
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
		// The src is repeated so the classic templates work even if register() has not run (a theme
		// calling the Renderer before init 20).
		self::enqueue_style();
		wp_enqueue_script( self::SCRIPT, BFRC_URL . 'assets/front/recipes.js', [], BFRC_VERSION, true );
	}

	/**
	 * The stylesheet alone: blocks and shortcodes need no archive script
	 * (it binds the archive's form and the recipe page's print button).
	 */
	public static function enqueue_style(): void {
		wp_enqueue_style( self::STYLE, BFRC_URL . 'assets/front/recipes.css', [], BFRC_VERSION );
	}

	/**
	 * Recipes take no comments (Pro reviews use their own type).
	 *
	 * @param mixed $open    Whether comments are open (a bool unless another filter changed it).
	 * @param mixed $post_id The post.
	 */
	public function comments_closed( mixed $open, mixed $post_id ): bool {
		return get_post_type( (int) $post_id ) === PostType::NAME ? false : (bool) $open;
	}

	/**
	 * May a view block render here? On a recipe view of the main query (a
	 * visitor's page), or in the Site Editor's preview. Never in a feed, a
	 * REST item's `content.rendered`, or another post type's page: a writer
	 * who pastes a view block into a body must not make the public feeds and
	 * the REST collection carry a whole recipe page or grid per item.
	 *
	 * @param \WP_Query|null $wp_query The main query.
	 * @param bool           $single   Require the single recipe view (else any recipe view).
	 */
	private function view_block_allowed( ?\WP_Query $wp_query, bool $single ): bool {
		if ( self::is_editor_preview() ) {
			return true;
		}
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_feed() || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			return false;
		}
		if ( doing_filter( 'the_content' ) ) {
			return false; // Inside a post body (a Query Loop's Post Content block on the archive template included): the view blocks belong to templates, not to bodies.
		}
		return $single
			? $wp_query->is_singular( PostType::NAME ) && $wp_query->is_main_query()
			: ! $wp_query->is_singular() && $this->is_recipe_query( $wp_query ); // The archive block belongs to list views; on a recipe page it would be a one-card grid of that recipe.
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
	 * `wpseo_title`, `rank_math/frontend/title`, `aioseo_title`: on a writer
	 * view, the writer's name (or "Recipe writers"), the page number past
	 * the first, and the site name, in place of the SEO plugin's post-type
	 * archive title. Plain text: each plugin escapes the title it prints.
	 * Anything else is passed through.
	 *
	 * @param mixed $title The SEO plugin's title.
	 * @return mixed
	 */
	public function seo_plugin_title( mixed $title ): mixed {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_404() || ! $this->is_recipe_query( $wp_query ) || ! self::is_writer_view( $wp_query ) ) {
			return $title;
		}
		$parts = $this->document_title(
			[
				'title' => '',
				'site'  => (string) get_bloginfo( 'name' ), // Raw: the SEO plugin escapes the whole title.
			]
		);
		if ( '' === $parts['title'] ) {
			return $title; // A writer without a public page: guard() 404s it.
		}
		$page = max( 1, (int) $wp_query->get( 'paged' ) );
		if ( $page > 1 ) {
			$parts = [
				'title' => $parts['title'],
				/* translators: %s: a page number */
				'page'  => sprintf( __( 'Page %s', 'bluefairy-recipes' ), number_format_i18n( $page ) ),
				'site'  => $parts['site'],
			];
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own separator filter, as wp_get_document_title() applies it.
		$sep = (string) apply_filters( 'document_title_separator', '-' );
		return implode( ' ' . $sep . ' ', array_filter( $parts, static fn( mixed $p ): bool => '' !== (string) $p ) );
	}

	/**
	 * The single-recipe view block.
	 */
	public function render_single_block(): string {
		global $wp_query;
		if ( ! $this->view_block_allowed( $wp_query instanceof \WP_Query ? $wp_query : null, true ) ) {
			return '';
		}
		if ( self::is_post_editor_preview() ) {
			return self::body_note();
		}
		$post = get_post(); // Inside a recipe's own body Renderer::single() returns '' (its re-entrancy guard).
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			if ( ! self::is_editor_preview() ) {
				return '';
			}
			// The Site Editor renders the block over REST with no recipe in context: preview the
			// newest published recipe, or say what the block is when there is none yet.
			$latest = get_posts(
				[
					'post_type'        => PostType::NAME,
					'post_status'      => 'publish',
					'has_password'     => false, // A template preview that is a password form helps nobody.
					'numberposts'      => 1,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => false,
				]
			);
			$post   = $latest[0] ?? null;
			if ( ! $post instanceof \WP_Post ) {
				return '<article class="bfrc-recipe bfrc-recipe--placeholder"><p>' . esc_html__( 'The recipe shows here: its title, writer, timings, photo or video, ingredients, method, nutrition and share buttons. Publish a recipe to preview one.', 'bluefairy-recipes' ) . '</p></article>';
			}
		}
		self::enqueue_assets();
		return $this->renderer->single( $post );
	}

	/**
	 * Is the editor preview for a post being edited? The view blocks' editor
	 * script renders them through `ServerSideRender`, whose request carries
	 * `post_id` in the post editor (a numeric current post) and not in the
	 * Site Editor (templates have string ids). A view block pasted into a
	 * body then shows a note, never another recipe's page or a grid.
	 */
	public static function is_post_editor_preview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a cookie-authenticated REST request passed core's nonce check and the block-renderer route needs edit_posts; only the presence of the id matters here.
		return self::is_editor_preview() && absint( wp_unslash( $_GET['post_id'] ?? 0 ) ) > 0;
	}

	/**
	 * The note a view block shows inside a post being edited.
	 */
	public static function body_note(): string {
		return '<p class="bfrc-block-note">' . esc_html__( 'This block renders from the recipe template on the site; inside a post\'s own text it shows nothing. Use the Recipe List, Featured Recipes or Recipe Card block here.', 'bluefairy-recipes' ) . '</p>';
	}

	/**
	 * Is this the block editor asking for a server-rendered preview (the
	 * block-renderer REST route), rather than a visitor's page or any other
	 * REST request that renders content (a page's `content.rendered`, a
	 * headless consumer), where the blocks render as on the site?
	 */
	public static function is_editor_preview(): bool {
		if ( ! function_exists( 'wp_is_serving_rest_request' ) || ! wp_is_serving_rest_request() ) {
			return false;
		}
		global $wp;
		$route = $wp instanceof \WP && isset( $wp->query_vars['rest_route'] ) ? (string) $wp->query_vars['rest_route'] : '';
		return str_contains( $route, '/block-renderer/' );
	}

	/**
	 * The archive view block: grid with filters, a writer's recipes, or the
	 * writers listing, from the main query. In the Site Editor the `view`
	 * attribute (`writers` in the Recipe Writers template) picks the preview.
	 *
	 * @param mixed $attributes Block attributes (core passes an array).
	 */
	public function render_archive_block( mixed $attributes = [] ): string {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || Renderer::is_rendering_single() || ! $this->view_block_allowed( $wp_query, false ) ) {
			return '';
		}
		if ( self::is_post_editor_preview() ) {
			return self::body_note();
		}
		if ( self::is_editor_preview() && is_array( $attributes ) && 'writers' === ( $attributes['view'] ?? '' ) ) {
			self::enqueue_assets();
			return $this->renderer->writers_list( 1 ); // The Recipe Writers template previews the writers list, not the recipe grid.
		}
		$query = $wp_query;
		if ( self::is_editor_preview() && [] === (array) $wp_query->posts ) {
			// The Site Editor's query has no recipes: preview the newest page of them, on a copy so
			// the request's own query is left alone.
			$query        = clone $wp_query;
			$query->posts = get_posts(
				[
					'post_type'        => PostType::NAME,
					'post_status'      => 'publish',
					'has_password'     => false,
					'numberposts'      => $this->per_page(),
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => false,
				]
			);
		}
		self::enqueue_assets();
		return $this->renderer->archive( $query );
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
