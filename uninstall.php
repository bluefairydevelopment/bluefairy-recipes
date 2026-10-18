<?php
/**
 * Uninstall routine for BlueFairy Recipes.
 *
 * Standalone: no autoloader, inline SQL only. WordPress runs this file in a
 * bare context where the plugin's classes are not loaded.
 *
 * Always removes: options, transients, the two roles (their users keep a
 * role: the fallback `subscriber` when it was their only one), the recipe
 * capabilities granted to core roles, and the object-cache group.
 *
 * Removes the CONTENT — recipes with their meta and terms, the recipe
 * taxonomies' terms, and the writer profile fields on users — ONLY when the
 * administrator ticked "Delete all data on uninstall" in Settings. Recipes
 * are the customer's work; deleting them by default is not a cleanup, it is
 * a loss, and a reinstall must find them intact.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Every option this plugin creates, and nothing else. Installer::OPTIONS must
 * stay equal to this list in both directions.
 */
$bfrc_option_keys = [
	'bfrc_settings',
	'bfrc_db_version',
	'bfrc_version',
];

/** Installer::TRANSIENTS. */
$bfrc_transients = [
	'bfrc_flush_rewrite',
];

/** Capabilities::ROLE_WRITER, ROLE_MANAGER. */
$bfrc_roles = [ 'bfrc_writer', 'bfrc_recipe_manager' ];

/** Capabilities::all_caps(), minus the core caps `upload_files` and `read` that are never ours to revoke. */
$bfrc_caps = [
	'edit_bfrc_recipes',
	'edit_published_bfrc_recipes',
	'publish_bfrc_recipes',
	'delete_bfrc_recipes',
	'delete_published_bfrc_recipes',
	'assign_bfrc_terms',
	'edit_others_bfrc_recipes',
	'edit_private_bfrc_recipes',
	'read_private_bfrc_recipes',
	'delete_others_bfrc_recipes',
	'delete_private_bfrc_recipes',
	'manage_bfrc_terms',
	'edit_bfrc_terms',
	'delete_bfrc_terms',
	'bfrc_manage_recipes',
];

/** The writer profile fields (user meta). */
$bfrc_user_meta = [
	'bfrc_avatar_id',
	'bfrc_header_image_id',
	'bfrc_about',
	'bfrc_website',
	'bfrc_youtube',
	'bfrc_facebook',
	'bfrc_instagram',
	'bfrc_snapchat',
	'bfrc_listed',
];

/**
 * Remove everything for the current site.
 *
 * Declared as a closure rather than a named function so it cannot collide
 * with the Pro add-on's uninstall running in the same request.
 */
$bfrc_uninstall_site = static function () use ( $bfrc_option_keys, $bfrc_transients, $bfrc_roles, $bfrc_caps, $bfrc_user_meta ): void {
	global $wpdb;

	$settings    = get_option( 'bfrc_settings', [] );
	$delete_data = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	if ( $delete_data ) {
		// Recipes, in batches of 100, with their meta and term relationships (wp_delete_post does both).
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: the post type is not registered here, so WP_Query cannot see it.
			$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID LIMIT 100", 'bfrc_recipe' ) );
			$seen = count( $ids );
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} while ( 100 === $seen );

		// Terms of every `bfrc_*` taxonomy (the four system ones and any custom filter type): relationships, term_taxonomy rows, then orphaned terms.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the taxonomies are not registered here, so wp_delete_term() refuses them.
		$tt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE %s", $wpdb->esc_like( 'bfrc_' ) . '%' ) );
		if ( [] !== $tt_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders string is built from %d only; the values are bound.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$placeholders})", ...$tt_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$placeholders})", ...$tt_ids ) );
			$wpdb->query( "DELETE t FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.term_id IS NULL" );
			// phpcs:enable
		}

		foreach ( $bfrc_user_meta as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	foreach ( $bfrc_option_keys as $key ) {
		delete_option( $key );
	}
	foreach ( $bfrc_transients as $key ) {
		delete_transient( $key );
	}
	// Dynamically named transients, if any are ever added, are swept here so a site without a persistent object cache keeps a clean options table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bfrc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bfrc_' ) . '%'
		)
	);

	// Roles: move each user off the role (fallback `subscriber` when it was their only one), then remove the role.
	foreach ( $bfrc_roles as $role ) {
		if ( ! get_role( $role ) instanceof WP_Role ) {
			continue;
		}
		do {
			$ids  = get_users(
				[
					'role'   => $role,
					'fields' => 'ID',
					'number' => 200,
					'paged'  => 1,
				]
			);
			$seen = count( $ids );
			foreach ( $ids as $id ) {
				$user = new WP_User( (int) $id );
				$user->remove_role( $role );
				if ( [] === $user->roles ) {
					$user->set_role( 'subscriber' );
				}
			}
		} while ( 200 === $seen );
		remove_role( $role );
	}

	// The recipe capabilities granted to core roles.
	foreach ( [ 'administrator', 'editor', 'author' ] as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role instanceof WP_Role ) {
			continue;
		}
		foreach ( $bfrc_caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'bfrc' );
	}
};

// Multisite awareness: page through EVERY site, not the first hundred.
$bfrc_site_ids = [ null ];
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$bfrc_site_ids = [];
	$bfrc_offset   = 0;
	do {
		$bfrc_batch    = get_sites(
			[
				'number'  => 200,
				'offset'  => $bfrc_offset,
				'fields'  => 'ids',
				'orderby' => 'id',
			]
		);
		$bfrc_site_ids = array_merge( $bfrc_site_ids, $bfrc_batch );
		$bfrc_offset  += 200;
		$bfrc_got      = count( $bfrc_batch );
	} while ( 200 === $bfrc_got );
}

foreach ( $bfrc_site_ids as $bfrc_site ) {
	if ( $bfrc_site ) {
		switch_to_blog( (int) $bfrc_site );
	}
	$bfrc_uninstall_site();
	if ( $bfrc_site ) {
		restore_current_blog();
	}
}
