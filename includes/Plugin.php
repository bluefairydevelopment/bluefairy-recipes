<?php
/**
 * Plugin bootstrap — wires all subsystems.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registered as the plugins_loaded callback. Instantiates subsystems, wires
 * hooks and exposes the instances to the Pro add-on through service().
 *
 * Order matters: `bfrc_loaded` fires BEFORE any subsystem binds so the add-on
 * can attach its filters first — a filter taxonomy added after the content
 * model has already read the definitions is a taxonomy added too late.
 */
final class Plugin {

	/**
	 * Guard against booting twice.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Shared instances, keyed by id.
	 *
	 * @var array<string, object>
	 */
	private static array $services = [];

	/**
	 * Boot the plugin on plugins_loaded.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Installer::maybe_upgrade();
		// A changed archive slug (or a fresh activation) flags a rewrite flush; it
		// runs once, late on init, after the post type and taxonomies exist.
		add_action( 'init', [ Installer::class, 'maybe_flush_rewrite' ], 99 );

		$settings = new Settings();
		self::set( 'settings', $settings );

		/**
		 * The base plugin is ready to be extended.
		 *
		 * Fired before the subsystems register. Add-ons hook here to attach
		 * the `bfrc_*` filters that add filter taxonomies, ingredient types,
		 * schema data, settings tabs and admin menu items. Does not fire when
		 * the base plugin is inactive, so an add-on must also check the
		 * dependency on plugins_loaded if it wants to explain itself.
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfrc_loaded' );

		// Content model: taxonomies first (the post type and the slug rules read their definitions).
		$taxonomies = new Content\Taxonomies( $settings );
		$post_type  = new Content\PostType( $settings );
		$meta       = new Content\Meta();
		$slugs      = new Content\Slugs( $taxonomies );
		$taxonomies->register_hooks();
		$post_type->register_hooks();
		$meta->register_hooks();
		$slugs->register_hooks();
		self::set( 'content.taxonomies', $taxonomies );
		self::set( 'content.post_type', $post_type );
		self::set( 'content.meta', $meta );
		self::set( 'content.slugs', $slugs );

		if ( is_admin() ) {
			$editor = new Admin\RecipeEditor();
			$editor->register_hooks();
			self::set( 'admin.editor', $editor );
		}

		/**
		 * Every subsystem is wired. Add-ons that need an instance (the
		 * settings, a repository) fetch it with Plugin::service().
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfrc_booted' );
	}

	/**
	 * Is the Pro add-on active? Pro answers true from its own boot, after it
	 * has confirmed this base is new enough — so a Pro that refused to boot
	 * leaves this false and the upsell visible.
	 */
	public static function is_pro(): bool {
		/**
		 * Whether the Pro add-on is running.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $is_pro Default false.
		 */
		return (bool) apply_filters( 'bfrc_is_pro', false );
	}

	/**
	 * A shared instance by id, or null.
	 *
	 * @param string $id Service id, e.g. 'settings'.
	 */
	public static function service( string $id ): ?object {
		return self::$services[ $id ] ?? null;
	}

	/**
	 * Register a shared instance.
	 *
	 * @param string $id       Service id.
	 * @param object $instance The instance.
	 */
	public static function set( string $id, object $instance ): void {
		self::$services[ $id ] = $instance;
	}

	/**
	 * Reset boot state. A test seam: nothing in the plugin calls it, and
	 * nothing outside the test suite should.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$booted   = false;
		self::$services = [];
	}
}
