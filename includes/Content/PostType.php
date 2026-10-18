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
 * (the admin keeps working) but is not publicly queryable, has no archive,
 * is excluded from search and from the core sitemaps, so every recipe URL is
 * a 404 for visitors.
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
			'rewrite'             => [
				'slug'       => $slug,
				'with_front' => false,
				'feeds'      => $enabled,
			],
			'query_var'           => self::NAME,
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
