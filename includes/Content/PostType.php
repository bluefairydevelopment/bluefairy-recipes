<?php
/**
 * The recipe post type.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Content;

use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `bfrc_recipe`: public, with an archive at the configured slug, block-editor
 * enabled, revisions on, and `capability_type bfrc_recipe` with
 * `map_meta_cap` so core maps every post action to the capabilities
 * Capabilities grants.
 *
 * The plugin's `enabled` switch: when off, the post type is still registered
 * (the admin keeps working) but has no rewrite rules, no query var, no
 * archive, is excluded from search and from the core sitemaps, and the core
 * REST routes for recipes and recipe taxonomies answer only users who can
 * edit recipes (gated at dispatch time, after REST authentication, so cookie
 * and application-password clients are treated alike; the block editor keeps
 * working). Every recipe URL is then a 404 for everyone, administrators'
 * previews included; flipping the switch flags a rewrite flush.
 */
final class PostType {

	public const NAME = 'bfrc_recipe';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings The plugin settings (enabled switch, archive slug).
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register on init (after the taxonomies at 5) and keep the sitemap honest.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 6 );
		add_filter( 'wp_sitemaps_post_types', [ $this, 'filter_sitemap_post_types' ] );
		add_filter( 'rest_pre_dispatch', [ $this, 'gate_rest_when_disabled' ], 10, 3 );
		add_filter( 'rest_post_search_query', [ $this, 'filter_rest_search_when_disabled' ], 10, 2 );
	}

	/**
	 * Is the plugin switched on?
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'enabled' );
	}

	/**
	 * The archive slug from settings.
	 */
	public function archive_slug(): string {
		return (string) $this->settings->get( 'archive_slug' );
	}

	/**
	 * The arguments the post type is registered with.
	 *
	 * @return array<string, mixed>
	 */
	public function args(): array {
		$enabled = $this->is_enabled();
		$slug    = $this->archive_slug();
		return [
			'labels'              => [
				'name'                  => __( 'Recipes', 'bluefairy-recipes' ),
				'singular_name'         => __( 'Recipe', 'bluefairy-recipes' ),
				'menu_name'             => __( 'Recipes', 'bluefairy-recipes' ),
				'name_admin_bar'        => __( 'Recipe', 'bluefairy-recipes' ),
				'add_new'               => __( 'Add New', 'bluefairy-recipes' ),
				'add_new_item'          => __( 'Add New Recipe', 'bluefairy-recipes' ),
				'edit_item'             => __( 'Edit Recipe', 'bluefairy-recipes' ),
				'new_item'              => __( 'New Recipe', 'bluefairy-recipes' ),
				'view_item'             => __( 'View Recipe', 'bluefairy-recipes' ),
				'view_items'            => __( 'View Recipes', 'bluefairy-recipes' ),
				'search_items'          => __( 'Search Recipes', 'bluefairy-recipes' ),
				'not_found'             => __( 'No recipes found.', 'bluefairy-recipes' ),
				'not_found_in_trash'    => __( 'No recipes found in Trash.', 'bluefairy-recipes' ),
				'all_items'             => __( 'All Recipes', 'bluefairy-recipes' ),
				'archives'              => __( 'Recipe Archives', 'bluefairy-recipes' ),
				'featured_image'        => __( 'Recipe photo', 'bluefairy-recipes' ),
				'set_featured_image'    => __( 'Set recipe photo', 'bluefairy-recipes' ),
				'remove_featured_image' => __( 'Remove recipe photo', 'bluefairy-recipes' ),
				'use_featured_image'    => __( 'Use as recipe photo', 'bluefairy-recipes' ),
				'item_published'        => __( 'Recipe published.', 'bluefairy-recipes' ),
				'item_updated'          => __( 'Recipe updated.', 'bluefairy-recipes' ),
			],
			'description'         => __( 'Recipes with ingredients, steps, timings and nutrition.', 'bluefairy-recipes' ),
			'public'              => true,
			'publicly_queryable'  => $enabled,
			'exclude_from_search' => ! $enabled,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => $enabled,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'menu_position'       => 20,
			'menu_icon'           => 'dashicons-carrot',
			'has_archive'         => $enabled ? $slug : false,
			'rewrite'             => $enabled ? [
				'slug'       => $slug,
				'with_front' => false,
				'feeds'      => true,
			] : false,
			'query_var'           => $enabled ? self::NAME : false,
			'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ],
			'capability_type'     => [ 'bfrc_recipe', 'bfrc_recipes' ],
			'map_meta_cap'        => true,
			'delete_with_user'    => false,
		];
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		register_post_type( self::NAME, $this->args() );
	}

	/**
	 * `rest_pre_dispatch`: while disabled, the core routes for recipes
	 * (`/wp/v2/bfrc_recipe…`) and for every recipe taxonomy (`/wp/v2/bfrc_…`)
	 * are a 404 for anyone who cannot edit recipes. Runs after authentication,
	 * so a REST-only client (application password) is judged by its real user.
	 *
	 * @param mixed            $result  A short-circuit result, or null.
	 * @param \WP_REST_Server  $server  The server.
	 * @param \WP_REST_Request $request The request.
	 */
	public function gate_rest_when_disabled( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		unset( $server );
		if ( null !== $result || $this->is_enabled() ) {
			return $result;
		}
		if ( 1 !== preg_match( '#^/wp/v2/bfrc_[a-z0-9_]+(?:/|$)#', $request->get_route() ) ) {
			return $result;
		}
		if ( current_user_can( 'edit_bfrc_recipes' ) ) {
			return $result;
		}
		return new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'bluefairy-recipes' ), [ 'status' => 404 ] );
	}

	/**
	 * `rest_post_search_query`: while disabled, `/wp/v2/search` lists no
	 * recipes to anyone who cannot edit them. Core's search handler queries an
	 * explicit post-type list, which ignores `exclude_from_search`.
	 *
	 * @param array<string, mixed> $query_args WP_Query arguments.
	 * @param \WP_REST_Request     $request    The request.
	 * @return array<string, mixed>
	 */
	public function filter_rest_search_when_disabled( array $query_args, \WP_REST_Request $request ): array {
		unset( $request );
		if ( $this->is_enabled() || current_user_can( 'edit_bfrc_recipes' ) ) {
			return $query_args;
		}
		$types = array_values( array_diff( (array) ( $query_args['post_type'] ?? [] ), [ self::NAME ] ) );
		if ( [] === $types ) {
			$query_args['post__in'] = [ 0 ]; // Only recipes were asked for: nothing can match.
		}
		$query_args['post_type'] = [] === $types ? [ self::NAME ] : $types;
		return $query_args;
	}

	/**
	 * Keep recipes out of the core sitemaps while the plugin is disabled.
	 *
	 * @param array<string, object> $post_types Sitemap post types, keyed by name.
	 * @return array<string, object>
	 */
	public function filter_sitemap_post_types( array $post_types ): array {
		if ( ! $this->is_enabled() ) {
			unset( $post_types[ self::NAME ] );
		}
		return $post_types;
	}
}
