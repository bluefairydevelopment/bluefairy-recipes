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
 */
final class Capabilities {

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
	 * The role users fall back to when a plugin role is removed and it was
	 * their only one. Core's own default for a new user.
	 */
	public const FALLBACK_ROLE = 'subscriber';

	/**
	 * Users moved per page when a role is removed.
	 */
	public const USERS_PER_PAGE = 200;

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
	 * give administrators MANAGE.
	 */
	public static function add_roles(): void {
		$labels = [
			self::ROLE_WRITER  => __( 'Recipe Writer', 'bluefairy-recipes' ),
			self::ROLE_MANAGER => __( 'Recipe Manager', 'bluefairy-recipes' ),
		];
		foreach ( $labels as $role => $label ) {
			$caps     = self::caps_for( $role );
			$existing = get_role( $role );
			if ( ! $existing instanceof \WP_Role ) {
				add_role( $role, $label, array_fill_keys( $caps, true ) );
				continue;
			}
			foreach ( $caps as $cap ) {
				$existing->add_cap( $cap );
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin instanceof \WP_Role ) {
			$admin->add_cap( self::MANAGE );
		}
	}

	/**
	 * Remove the two roles. A user whose only role was one of them becomes a
	 * FALLBACK_ROLE user rather than a user with no role at all; a user who
	 * also holds another role just loses this one. Paged, so a site with
	 * thousands of writers is handled in bounded reads.
	 */
	public static function remove_roles(): void {
		foreach ( [ self::ROLE_WRITER, self::ROLE_MANAGER ] as $role ) {
			if ( ! get_role( $role ) instanceof \WP_Role ) {
				continue;
			}
			// Always page 1: each pass removes the role from the users it saw,
			// so the next page-1 read is the next batch.
			do {
				$ids  = get_users(
					[
						'role'   => $role,
						'fields' => 'ID',
						'number' => self::USERS_PER_PAGE,
						'paged'  => 1,
					]
				);
				$seen = count( $ids );
				foreach ( $ids as $id ) {
					$user = new \WP_User( (int) $id );
					$user->remove_role( $role );
					if ( [] === $user->roles ) {
						$user->set_role( self::FALLBACK_ROLE );
					}
				}
			} while ( self::USERS_PER_PAGE === $seen );
			remove_role( $role );
		}
		$admin = get_role( 'administrator' );
		if ( $admin instanceof \WP_Role ) {
			$admin->remove_cap( self::MANAGE );
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
				$object->add_cap( $cap );
			}
		}
	}

	/**
	 * Take the recipe capabilities back from the core roles. MANAGE stays on
	 * administrators (add_roles() owns that grant; remove_roles() revokes it).
	 */
	public static function revoke_core_roles(): void {
		foreach ( self::core_grants() as $role => $caps ) {
			$object = get_role( $role );
			if ( ! $object instanceof \WP_Role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( self::MANAGE === $cap ) {
					continue;
				}
				$object->remove_cap( $cap );
			}
		}
	}

	/**
	 * Can the current user manage recipes (settings, taxonomies, every recipe)?
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE );
	}
}
