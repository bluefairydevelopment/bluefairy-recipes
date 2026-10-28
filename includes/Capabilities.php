<?php
/**
 * Roles and capabilities.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two roles and one management capability.
 *
 * - `bfrc_writer` (Recipe Writer) edits, publishes and deletes its OWN
 *   recipes and assigns terms — the shape of core's Author, for recipes.
 * - `bfrc_recipe_manager` (Recipe Manager) does all of that for every
 *   recipe, manages the recipe taxonomies and holds MANAGE, the one
 *   capability that gates the settings screen and every privileged action.
 *
 * The recipe post type is registered with `capability_type => bfrc_recipe`
 * and `map_meta_cap => true`, so core maps `edit_post` on a recipe to the
 * primitive capabilities listed here (`edit_bfrc_recipes`,
 * `edit_others_bfrc_recipes`, …). Nothing here maps anything by hand.
 *
 * Core roles: when the `grant_core_roles` setting is on (the default),
 * Authors get the writer set, Editors the manager set without MANAGE, and
 * Administrators everything — so a site's existing authors can write
 * recipes without being re-assigned. Administrators always hold MANAGE.
 *
 * @api
 * @since 1.0.0
 */
final class Capabilities {

	/**
	 * The capability that manages recipe settings and moderation.
	 *
	 * @api
	 * @since 1.0.0
	 */
	public const MANAGE = 'bfrc_manage_recipes';

	public const ROLE_WRITER  = 'bfrc_writer';
	public const ROLE_MANAGER = 'bfrc_recipe_manager';

	/**
	 * Capabilities core generates for `capability_type => bfrc_recipe` with
	 * `map_meta_cap => true`, plus the taxonomy set. Every one a role can
	 * hold is listed here, so remove_* can revoke exactly what add_* granted.
	 *
	 * @var list<string>
	 */
	public const WRITER_CAPS = [
		'edit_bfrc_recipes',
		'edit_published_bfrc_recipes',
		'publish_bfrc_recipes',
		'delete_bfrc_recipes',
		'delete_published_bfrc_recipes',
		'assign_bfrc_terms',
		'upload_files',
		'read',
	];

	/**
	 * What a manager holds on top of WRITER_CAPS.
	 *
	 * @var list<string>
	 */
	public const MANAGER_EXTRA_CAPS = [
		'edit_others_bfrc_recipes',
		'edit_private_bfrc_recipes',
		'read_private_bfrc_recipes',
		'delete_others_bfrc_recipes',
		'delete_private_bfrc_recipes',
		'manage_bfrc_terms',
		'edit_bfrc_terms',
		'delete_bfrc_terms',
		self::MANAGE,
	];

	/**
	 * Every capability this plugin knows about, deduplicated.
	 *
	 * @return list<string>
	 */
	public static function all_caps(): array {
		return array_values( array_unique( array_merge( self::WRITER_CAPS, self::MANAGER_EXTRA_CAPS ) ) );
	}

	/**
	 * The capabilities of one plugin role.
	 *
	 * @param string $role ROLE_WRITER or ROLE_MANAGER.
	 * @return list<string>
	 */
	public static function caps_for( string $role ): array {
		return match ( $role ) {
			self::ROLE_WRITER  => self::WRITER_CAPS,
			self::ROLE_MANAGER => self::all_caps(),
			default            => [],
		};
	}

	/**
	 * What each core role is granted while `grant_core_roles` is on.
	 * Administrators are listed so the grant is one table; they get MANAGE
	 * from add_roles() regardless.
	 *
	 * @return array<string, list<string>>
	 */
	public static function core_grants(): array {
		return [
			'author'        => array_values( array_diff( self::WRITER_CAPS, [ 'upload_files', 'read' ] ) ),
			'editor'        => array_values( array_diff( self::all_caps(), [ 'upload_files', 'read', self::MANAGE ] ) ),
			'administrator' => array_values( array_diff( self::all_caps(), [ 'upload_files', 'read' ] ) ),
		];
	}

	/**
	 * Create the two roles (or, when they already exist, make sure they hold
	 * every capability — a role saved by an older version keeps its row) and
	 * give administrators MANAGE. Removing the roles is uninstall.php's job:
	 * it runs without this class, so the removal lives there and only there.
	 */
	public static function add_roles(): void {
		// Stored untranslated, as core stores its own role names: add_roles()
		// runs at plugins_loaded, before translations may load (WordPress 6.7+
		// notices on an early __()), and a label translated at install time
		// would be frozen in that locale.
		$labels = [
			self::ROLE_WRITER  => 'Recipe Writer',
			self::ROLE_MANAGER => 'Recipe Manager',
		];
		foreach ( $labels as $role => $label ) {
			$caps     = self::caps_for( $role );
			$existing = get_role( $role );
			if ( ! $existing instanceof \WP_Role ) {
				add_role( $role, $label, array_fill_keys( $caps, true ) );
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $existing->has_cap( $cap ) ) {
					$existing->add_cap( $cap ); // Each add_cap() rewrites the roles option: only the missing ones.
				}
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin instanceof \WP_Role && ! $admin->has_cap( self::MANAGE ) ) {
			$admin->add_cap( self::MANAGE );
		}
	}

	/**
	 * Grant the core roles their recipe capabilities (see core_grants()).
	 */
	public static function grant_core_roles(): void {
		foreach ( self::core_grants() as $role => $caps ) {
			$object = get_role( $role );
			if ( ! $object instanceof \WP_Role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $object->has_cap( $cap ) ) {
					$object->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Take the recipe capabilities back from Authors and Editors. Administrators
	 * keep everything: the setting decides whether a site's existing authors
	 * and editors may work on recipes, never whether an administrator may
	 * (MANAGE on administrators belongs to add_roles(); uninstall.php revokes it).
	 */
	public static function revoke_core_roles(): void {
		foreach ( self::core_grants() as $role => $caps ) {
			if ( 'administrator' === $role ) {
				continue;
			}
			$object = get_role( $role );
			if ( ! $object instanceof \WP_Role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$object->remove_cap( $cap ); // MANAGE is in no grant but the administrator's, skipped above.
			}
		}
	}

	/**
	 * `woocommerce_prevent_admin_access` / `woocommerce_disable_admin_bar`:
	 * WooCommerce sends anyone without `edit_posts`, `manage_woocommerce` or
	 * `view_admin_dashboard` to My Account and hides the admin bar. A Recipe
	 * Writer has none of those (recipes are their own type), so on a shop
	 * they could neither write a recipe nor fill their profile.
	 *
	 * @param mixed $prevent WooCommerce's decision (a bool, unless another filter changed it).
	 */
	public static function allow_writer_admin_access( mixed $prevent ): bool {
		return (bool) $prevent && ! current_user_can( 'edit_bfrc_recipes' );
	}

	/**
	 * Can the current user manage recipes (settings, taxonomies, every recipe)?
	 *
	 * @api
	 * @since 1.0.0
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE );
	}
}
