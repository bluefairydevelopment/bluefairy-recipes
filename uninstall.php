<?php
/**
 * Uninstall routine for BlueFairy Recipes.
 *
 * Standalone: no autoloader, inline SQL only. WordPress runs this file in a
 * bare context where the plugin's classes are not loaded.
 *
 * Order: every site's choice is read first, then the writer profiles (when
 * every site opted in), then each site: content (if opted in), roles, caps,
 * and its options last, so an interrupted run can be repeated and still
 * knows what each remaining site chose.
 *
 * Always removes: options (the listed ones and anything else named `bfrc_…`,
 * such as a taxonomy's `_children` cache), the stored rewrite rules (so core
 * regenerates them without this plugin's), transients, the two roles (their
 * users keep a role: `subscriber` when it was their only one), the recipe
 * capabilities granted to core roles, and the object-cache group (where the
 * cache supports flushing a group).
 *
 * Removes the CONTENT — recipes with their meta and terms, the recipe
 * taxonomies' terms, the sample set's images and writer account (while the
 * account is still the plugin's own), and the writer profile fields on
 * users — ONLY when the
 * administrator ticked "Delete all data on uninstall" in Settings. Recipes
 * are the customer's work; deleting them by default is not a cleanup, it is
 * a loss. After a reinstall the recipes and profiles are intact; writers must
 * be given the Recipe Writer role again, because roles are removed here.
 *
 * On a network, user meta is shared by every site, so the writer profiles are
 * removed only when EVERY site opted in. One site's choice cannot delete the
 * profiles of users who also write on a site that kept its data.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Every option this plugin creates by name. Installer::OPTIONS must stay
 * equal to this list in both directions. Options core creates under our
 * prefix (`bfrc_<taxonomy>_children`) are swept by prefix below.
 */
$bfrc_option_keys = [
	'bfrc_settings',
	'bfrc_db_version',
	'bfrc_version',
	'bfrc_flush_rewrite',
	'bfrc_samples',
];

/** Capabilities::ROLE_WRITER, ROLE_MANAGER. */
$bfrc_roles = [ 'bfrc_writer', 'bfrc_recipe_manager' ];

/** The keys of Capabilities::core_grants(). */
$bfrc_core_roles = [ 'author', 'editor', 'administrator' ];

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

/** Rows per batch: recipes deleted, users moved, term ids per statement. */
$bfrc_batch = 100;

/**
 * Has this site opted in to deleting its content? Only a real `true` counts:
 * the option is normally written by Settings::sanitize() as a bool, but a
 * value put there by `wp option update`, an import or a restore (`"false"`,
 * `["0"]`) must never read as "yes".
 */
$bfrc_site_deletes_data = static function (): bool {
	$settings = get_option( 'bfrc_settings', [] );
	return is_array( $settings ) && true === ( $settings['delete_data_on_uninstall'] ?? false );
};

/**
 * Remove everything for the current site.
 *
 * Declared as a closure rather than a named function so it cannot collide
 * with the Pro add-on's uninstall running in the same request.
 *
 * @param bool $delete_data Whether this site opted in to losing its content.
 */
$bfrc_uninstall_site = static function ( bool $delete_data ) use ( $bfrc_option_keys, $bfrc_roles, $bfrc_core_roles, $bfrc_caps, $bfrc_batch ): void {
	global $wpdb;

	if ( $delete_data ) {
		// The sample set first, while its record is still there: its images are
		// attachments (not recipes, so the loop below would leave them) and its
		// writer account goes with them while it is still the plugin's own
		// (the login and exactly the Writer role; a renamed or promoted account
		// is the owner's). The sample recipes are recipes: the loop takes them.
		$samples = get_option( 'bfrc_samples', [] );
		if ( is_array( $samples ) ) {
			// An image the site reused since (another post's featured image, or placed in its content) is
			// the site's now and stays: the same check as Samples::attachment_in_use_elsewhere().
			$sample_exclude = implode( ',', array_map( 'intval', array_merge( [ 0 ], (array) ( $samples['recipes'] ?? [] ) ) ) );
			foreach ( (array) ( $samples['attachments'] ?? [] ) as $attachment_id ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $sample_exclude is intval'd ids; uninstall runs once.
				$sample_used = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id NOT IN ({$sample_exclude})
						UNION SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type <> 'revision' AND p.ID NOT IN ({$sample_exclude}) AND p.post_content LIKE %s LIMIT 1",
						(string) (int) $attachment_id,
						'%' . $wpdb->esc_like( 'wp-image-' . (int) $attachment_id ) . '%'
					)
				);
				// phpcs:enable
				if ( null === $sample_used || '' === (string) $sample_used ) {
					wp_delete_attachment( (int) $attachment_id, true );
				}
			}
			$sample_writer = (int) ( $samples['writer'] ?? 0 );
			$sample_user   = $sample_writer > 0 ? get_userdata( $sample_writer ) : false;
			// Only with someone to inherit anything else of theirs (whoever uninstalls). From WP-CLI there
			// is no current user and core would delete those posts with the account, so it stays, as
			// Samples::delete_record() keeps it without an inheritor.
			if ( get_current_user_id() > 0 && $sample_user instanceof WP_User && 'bfrc_sample_writer' === (string) $sample_user->user_login && [ 'bfrc_writer' ] === array_values( (array) $sample_user->roles ) ) {
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}
				wp_delete_user( $sample_writer, get_current_user_id() );
				if ( is_multisite() && [] === get_blogs_of_user( $sample_writer ) ) {
					if ( ! function_exists( 'wpmu_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/ms.php';
					}
					wpmu_delete_user( $sample_writer );
				}
			}
		}
		// Recipes, in keyset batches (ID > last), force-deleted so meta,
		// revisions and comments go with them through core's own routine.
		// Cache invalidation is suspended for the loop and the cache flushed
		// once at the end; the time limit is lifted where PHP allows, because
		// a site with thousands of recipes does not finish inside a web
		// request's default 30 seconds (`wp plugin uninstall` is the
		// recommended route for such a site; the loop is idempotent, so a
		// timed-out run can simply be repeated).
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- an opted-in uninstall must finish; see above.
		}
		wp_suspend_cache_invalidation( true );
		try {
			$last = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: the post type is not registered here, so WP_Query cannot see it.
				$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID LIMIT %d", 'bfrc_recipe', $last, $bfrc_batch ) );
				$seen = count( $ids );
				foreach ( $ids as $id ) {
					$last = max( $last, (int) $id );
					wp_delete_post( (int) $id, true );
					// Invalidation is suspended, so core left this post in a persistent cache: only it goes.
					wp_cache_delete( (int) $id, 'posts' );
					wp_cache_delete( (int) $id, 'post_meta' );
				}
				// With invalidation suspended every deleted post stays in the in-memory
				// cache; drop the runtime cache per batch so memory does not grow with
				// the number of recipes.
				if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}
			} while ( $bfrc_batch === $seen );
		} finally {
			wp_suspend_cache_invalidation( false );
		}
		// Every cached post and comment query is now stale by key (their reviews went with the recipes).
		wp_cache_set_last_changed( 'posts' );
		wp_cache_set_last_changed( 'comment' );

		// Terms of every `bfrc_*` taxonomy (the four system ones and any
		// custom filter type), in batches: relationships and term_taxonomy
		// rows by term_taxonomy_id, then the term meta and the terms
		// themselves — only those terms, and only where no other taxonomy
		// still uses the term (NOT EXISTS: an index probe per row, never a
		// materialised list of every term). The taxonomies are not registered
		// here, so wp_delete_term() would refuse them. No ORDER BY: each
		// batch deletes what it read, so the next read is the next batch; a
		// batch whose delete did not take (a lock, a refused statement) ends
		// the loop instead of re-reading the same rows for ever.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholder strings are built from %d only; every value is bound.
		$deleted_terms = [];
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE %s LIMIT %d", $wpdb->esc_like( 'bfrc_' ) . '%', $bfrc_batch ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : [];
			if ( [] === $rows ) {
				break;
			}
			$tt_ids   = array_map( static fn( array $r ): int => (int) $r['term_taxonomy_id'], $rows );
			$term_ids = array_values( array_unique( array_map( static fn( array $r ): int => (int) $r['term_id'], $rows ) ) );
			$tt_in    = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
			$term_in  = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$tt_in})", ...$tt_ids ) );
			$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$tt_in})", ...$tt_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$term_in}) AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = {$wpdb->termmeta}.term_id)", ...$term_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->terms} WHERE term_id IN ({$term_in}) AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = {$wpdb->terms}.term_id)", ...$term_ids ) );
			$got           = count( $rows );
			$deleted_terms = array_merge( $deleted_terms, $term_ids );
		} while ( $got === $bfrc_batch && is_int( $removed ) && $removed > 0 );
		// phpcs:enable
		// Only these terms leave the object cache (core's term API would have done this). The
		// cache is shared with every other plugin: it is never flushed whole.
		foreach ( array_unique( $deleted_terms ) as $term_id ) {
			wp_cache_delete( $term_id, 'terms' );
			wp_cache_delete( $term_id, 'term_meta' );
		}
		if ( [] !== $deleted_terms ) {
			wp_cache_set_last_changed( 'terms' );
		}
	}

	// Roles: move each user off the role (fallback `subscriber` when it was
	// their only one), then remove the role. Each pass re-reads page 1
	// because the previous pass removed the role from the users it saw; a
	// pass that returns the same users as the one before (a plugin vetoing
	// the role change) ends the loop instead of spinning until the timeout.
	foreach ( $bfrc_roles as $role ) {
		if ( ! get_role( $role ) instanceof WP_Role ) {
			continue;
		}
		$previous = null;
		do {
			$users = get_users(
				[
					'role'    => $role,
					'fields'  => 'all',
					'number'  => $bfrc_batch,
					'paged'   => 1,
					'orderby' => 'ID',
				]
			);
			$got   = count( $users );
			$ids   = array_map( static fn( WP_User $u ): int => (int) $u->ID, $users );
			if ( $ids === $previous ) {
				break;
			}
			$previous = $ids;
			foreach ( $users as $user ) {
				if ( 0 === (int) $user->ID ) {
					continue;
				}
				$user->remove_role( $role );
				if ( [] === $user->roles ) {
					$user->set_role( 'subscriber' );
				}
			}
		} while ( $got === $bfrc_batch );
		remove_role( $role );
	}

	// The recipe capabilities granted to core roles.
	foreach ( $bfrc_core_roles as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role instanceof WP_Role ) {
			continue;
		}
		foreach ( $bfrc_caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	// A drop-in without flush_group() would fatal here; ask the cache what it supports (the function itself always exists).
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'bfrc' );
	}

	// Did this site ever run the plugin? On a network the uninstall visits every site, and the prefix
	// sweep below must not take another plugin's `bfrc_` options from a site that never had this one.
	$ran_here = false !== get_option( 'bfrc_settings', false ) || false !== get_option( 'bfrc_db_version', false );
	// Options last: the settings option holds this site's choice until the site is done.
	foreach ( $bfrc_option_keys as $key ) {
		delete_option( $key );
	}
	if ( ! $ran_here ) {
		return;
	}
	// Anything else under our prefix: core's `bfrc_<taxonomy>_children`
	// caches, and dynamically named transients. `bfrc\_` never matches the
	// Pro add-on's `bfrcp_`.
	$where = $wpdb->prepare(
		'option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
		$wpdb->esc_like( 'bfrc_' ) . '%',
		$wpdb->esc_like( '_transient_bfrc_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bfrc_' ) . '%',
		$wpdb->esc_like( '_site_transient_bfrc_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_bfrc_' ) . '%'
	);
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE {$where}" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE {$where}" );
	// phpcs:enable
	// Those options leave the object cache by name, with the two lists core keeps of them.
	foreach ( is_array( $names ) ? $names : [] as $name ) {
		wp_cache_delete( (string) $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	// The stored rewrite rules include this plugin's; core rebuilds them on
	// the next request, when the plugin is no longer there to add any.
	delete_option( 'rewrite_rules' );
};

// Multisite awareness: page through EVERY site, not the first hundred.
$bfrc_site_ids = [ null ];
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$bfrc_site_ids = [];
	$bfrc_offset   = 0;
	do {
		$bfrc_page     = get_sites(
			[
				'number'  => 200,
				'offset'  => $bfrc_offset,
				'fields'  => 'ids',
				'orderby' => 'id',
			]
		);
		$bfrc_site_ids = array_merge( $bfrc_site_ids, $bfrc_page );
		$bfrc_offset  += 200;
		$bfrc_got      = count( $bfrc_page );
	} while ( 200 === $bfrc_got );
}

// Pass 1: every site's decision, read before anything is deleted, so a run
// that is interrupted and repeated still knows what each site chose (the
// settings option is the last thing a site loses, below).
$bfrc_decisions = [];
foreach ( $bfrc_site_ids as $bfrc_site ) {
	if ( $bfrc_site ) {
		switch_to_blog( (int) $bfrc_site );
	}
	try {
		$bfrc_decisions[] = [ $bfrc_site, $bfrc_site_deletes_data() ];
	} finally {
		if ( $bfrc_site ) {
			restore_current_blog();
		}
	}
}
$bfrc_every_site_deletes_data = [] !== $bfrc_decisions; // Never vacuously true on an empty site list.
foreach ( $bfrc_decisions as [ , $bfrc_yes ] ) {
	$bfrc_every_site_deletes_data = $bfrc_every_site_deletes_data && $bfrc_yes;
}

// Writer profiles live in user meta, which a network shares: removed only
// when every site chose to lose its data (on a single site: when it did). A
// site that never ran the plugin has no settings and counts as "no". Done
// first, so an interrupted run has already honoured the choice.
if ( $bfrc_every_site_deletes_data ) {
	foreach ( $bfrc_user_meta as $bfrc_key ) {
		delete_metadata( 'user', 0, $bfrc_key, '', true );
	}
}

// Pass 2: each site.
foreach ( $bfrc_decisions as [ $bfrc_site, $bfrc_delete_here ] ) {
	if ( $bfrc_site ) {
		switch_to_blog( (int) $bfrc_site );
	}
	try {
		$bfrc_uninstall_site( $bfrc_delete_here );
	} finally {
		if ( $bfrc_site ) {
			restore_current_blog();
		}
	}
}
