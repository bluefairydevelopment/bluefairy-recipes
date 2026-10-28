<?php
/**
 * Runs the real uninstall.php against the stubs, in both modes.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Installer;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class UninstallTest extends TestCase {

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require BFRC_DIR . 'uninstall.php';
	}

	/**
	 * The invariant: everything activation creates, with content and a writer on top.
	 *
	 * @param mixed $delete_data The stored value of the delete-data switch.
	 */
	private function seed( mixed $delete_data ): void {
		Installer::activate();
		update_option( 'bfrc_settings', array_merge( Settings::DEFAULTS, [ 'delete_data_on_uninstall' => $delete_data ] ) );
		update_option( 'bfrc_meal_type_children', [ 1 => [ 2 ] ] );
		update_option( 'bfrcp_keep', 'pro' );
		update_option( 'rewrite_rules', [ 'recipes/?$' => 'index.php?post_type=bfrc_recipe' ] );
		bfrc_test_add_user( 5, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		foreach ( $this->listed_user_meta() as $key ) {
			update_user_meta( 5, $key, 'v' );
		}
		update_user_meta( 5, 'other_plugin_meta', 'keep' );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'post' ] );
		bfrc_test_add_user( 6, [ 'roles' => [ Capabilities::ROLE_WRITER ], 'user_login' => 'bfrc_sample_writer', 'user_email' => 'bfrc_sample_writer@example.invalid' ] );
		update_option( 'bfrc_samples', [ 'writer' => 6, 'writer_login' => 'bfrc_sample_writer', 'recipes' => [ 41 ], 'attachments' => [ 43, 44 ] ] );
	}

	public function test_opt_in_deletes_the_sample_photos_and_the_sample_writer_only_while_it_is_still_the_plugins_own(): void {
		$this->seed( true );
		wp_set_current_user( 1 ); // Uninstalling from the plugins screen.
		$this->wpdb->queue( 'get_col', [ '41' ] );
		$this->wpdb->queue( 'get_col', [] );
		$this->run_uninstall();
		$this->assertSame( [ 43, 44 ], $GLOBALS['bfrc_test_deleted_attachments'], 'the photos are attachments, not recipes: the recipe loop would leave them' );
		$this->assertSame( [ 6 ], $GLOBALS['bfrc_test_deleted_users'], 'the sample writer account goes with the content' );
		$this->assertSame( [ [ 6, 1 ] ], $GLOBALS['bfrc_test_deleted_users_reassign'], 'anything else of theirs goes to whoever uninstalls' );
		$this->assertSame( [], $GLOBALS['bfrc_test_network_deleted_users'], 'single site' );
		$this->assertFalse( get_option( 'bfrc_samples' ) );
		$this->seed( true );
		get_userdata( 6 )->add_role( 'editor' ); // The owner promoted Sam: the account is theirs.
		$this->wpdb->queue( 'get_col', [] );
		$this->run_uninstall();
		$this->assertSame( [ 6 ], $GLOBALS['bfrc_test_deleted_users'], 'not deleted a second time: a promoted account stays' );
		$this->assertSame( [ 43, 44, 43, 44 ], $GLOBALS['bfrc_test_deleted_attachments'], 'its photos still go' );
	}

	public function test_opt_in_on_multisite_drops_the_sample_writers_network_row_only_when_no_other_site_has_it(): void {
		$GLOBALS['bfrc_test_multisite'] = true;
		$GLOBALS['bfrc_test_sites']     = [ 1 ];
		$this->seed( true );
		$GLOBALS['bfrc_test_user_blogs'][6] = [ 1 => 'this site', 2 => 'another site' ];
		$this->wpdb->queue( 'get_col', [] );
		$this->run_uninstall();
		$this->assertSame( [ [ 6, 1 ] ], $GLOBALS['bfrc_test_removed_from_blog'], 'removed from the site' );
		$this->assertSame( [], $GLOBALS['bfrc_test_network_deleted_users'], 'still a member of another site: the network row stays' );
		$this->seed( true );
		$GLOBALS['bfrc_test_user_blogs'][6] = [ 1 => 'this site' ];
		$this->wpdb->queue( 'get_col', [] );
		$this->run_uninstall();
		$this->assertSame( [ 6 ], $GLOBALS['bfrc_test_network_deleted_users'], 'nowhere else: the network row goes' );
	}

	public function test_default_keeps_the_sample_writer_and_photos(): void {
		$this->seed( false );
		$this->run_uninstall();
		$this->assertSame( [], $GLOBALS['bfrc_test_deleted_attachments'] );
		$this->assertSame( [], $GLOBALS['bfrc_test_deleted_users'] );
		$this->assertFalse( get_option( 'bfrc_samples' ), 'the record goes with the options; the next Install adopts the account' );
	}

	/**
	 * The user-meta keys uninstall.php lists.
	 *
	 * @return list<string>
	 */
	private function listed_user_meta(): array {
		$this->assertSame( 1, preg_match( '/\$bfrc_user_meta = \[(.*?)\];/s', (string) file_get_contents( BFRC_DIR . 'uninstall.php' ), $m ) );
		preg_match_all( "/'([a-z_]+)'/", $m[1], $keys );
		$this->assertCount( 9, $keys[1], 'the nine writer profile fields of the plan' );
		return $keys[1];
	}

	public function test_default_removes_options_rewrite_rules_roles_and_caps_but_keeps_content_and_profiles(): void {
		$this->seed( false );
		$this->run_uninstall();

		foreach ( Installer::OPTIONS as $key ) {
			$this->assertFalse( get_option( $key ), $key );
		}
		$this->assertFalse( get_option( 'rewrite_rules' ), 'core rebuilds the rules without ours' );
		$sweeps = $this->wpdb->queries_matching( 'DELETE FROM bfw_options WHERE option_name LIKE' );
		$this->assertCount( 1, $sweeps );
		$this->assertStringContainsString( "option_name LIKE 'bfrc\\\\_%' OR option_name LIKE '\\\\_transient\\\\_bfrc\\\\_%'", $sweeps[0], 'the prefix sweep (taxonomy _children caches, dynamic transients) escapes the underscore, so bfrcp_ never matches' );
		$this->assertSame( 'pro', get_option( 'bfrcp_keep' ), 'the Pro add-on\'s options are not ours' );
		$this->assertNull( get_role( Capabilities::ROLE_WRITER ) );
		$this->assertNull( get_role( Capabilities::ROLE_MANAGER ) );
		$this->assertSame( [ 'subscriber' ], ( new \WP_User( 5 ) )->roles, 'the writer keeps an account with a role' );
		foreach ( array_keys( Capabilities::core_grants() ) as $role ) {
			foreach ( Capabilities::all_caps() as $cap ) {
				if ( in_array( $cap, [ 'upload_files', 'read' ], true ) ) {
					continue;
				}
				$this->assertFalse( get_role( $role )->has_cap( $cap ), "{$role}: {$cap}" );
			}
			$this->assertTrue( get_role( $role )->has_cap( 'read' ), "{$role}: core caps untouched" );
		}

		$this->assertSame( [], $GLOBALS['bfrc_test_deleted_posts'], 'recipes stay' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'SELECT ID FROM bfw_posts' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'term_taxonomy' ), 'terms stay' );
		foreach ( $this->listed_user_meta() as $key ) {
			$this->assertSame( 'v', get_user_meta( 5, $key, true ), "{$key} stays" );
		}
		$this->assertSame( [], $GLOBALS['bfrc_test_suspended_invalidation'], 'no content loop ran' );
		$this->assertSame( 0, $GLOBALS['bfrc_test_cache_flushes'], 'no full cache flush when nothing was deleted' );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function not_a_real_true(): array {
		return [
			'string false' => [ 'false' ],
			'string off'   => [ 'off' ],
			'string 1'     => [ '1' ],
			'int 1'        => [ 1 ],
			'array'        => [ [ '0' ] ],
			'null'         => [ null ],
		];
	}

	/**
	 * @dataProvider not_a_real_true
	 */
	public function test_only_a_real_true_deletes_content( mixed $stored ): void {
		$this->seed( $stored );
		$this->run_uninstall();
		$this->assertSame( [], $GLOBALS['bfrc_test_deleted_posts'], var_export( $stored, true ) . ' is not an opt-in' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'SELECT ID FROM bfw_posts' ) );
		$this->assertSame( 'v', get_user_meta( 5, 'bfrc_about', true ) );
	}

	public function test_corrupt_settings_do_not_delete_content(): void {
		$this->seed( false );
		update_option( 'bfrc_settings', 'garbage' );
		$this->run_uninstall();
		$this->assertSame( [], $GLOBALS['bfrc_test_deleted_posts'] );
		$this->assertFalse( get_option( 'bfrc_settings' ), 'the corrupt option still goes' );
	}

	public function test_opt_in_deletes_recipes_in_keyset_batches_then_terms_profiles_and_flushes_once(): void {
		$this->seed( true );
		$first = range( 1001, 1100 );
		$this->wpdb->queue( 'get_col', array_map( 'strval', $first ) ); // a full page
		$this->wpdb->queue( 'get_col', [ '1250', '1251' ] );            // a short page ends the loop
		$this->wpdb->queue( 'get_col', [ 'bfrc_meal_type_children' ] );  // the options under our prefix
		$this->wpdb->queue( 'get_results', [ [ 'term_taxonomy_id' => '7', 'term_id' => '70' ], [ 'term_taxonomy_id' => '8', 'term_id' => '70' ], [ 'term_taxonomy_id' => '9', 'term_id' => '91' ] ] );
		foreach ( [ [ 1001, 'posts' ], [ 1251, 'post_meta' ], [ 70, 'terms' ], [ 70, 'term_meta' ], [ 91, 'terms' ], [ 55, 'terms' ], [ 999, 'posts' ], [ 'bfrc_meal_type_children', 'options' ], [ 'alloptions', 'options' ], [ 'notoptions', 'options' ], [ 'other_plugin_option', 'options' ], [ 1, 'other_plugin_group' ] ] as [ $key, $group ] ) {
			wp_cache_set( $key, 'cached', $group );
		}
		$this->run_uninstall();

		$deleted = array_map( static fn( array $d ): int => $d[0], $GLOBALS['bfrc_test_deleted_posts'] );
		$this->assertSame( array_merge( $first, [ 1250, 1251 ] ), $deleted, '102 recipes across two batches' );
		$this->assertSame( [ true ], array_values( array_unique( array_map( static fn( array $d ): bool => $d[1], $GLOBALS['bfrc_test_deleted_posts'] ) ) ), 'force-deleted, no trash' );
		$selects = $this->wpdb->queries_matching( 'SELECT ID FROM bfw_posts' );
		$this->assertCount( 2, $selects );
		$this->assertStringContainsString( "post_type = 'bfrc_recipe' AND ID > 0 ORDER BY ID LIMIT 100", $selects[0] );
		$this->assertStringContainsString( 'AND ID > 1100 ORDER BY ID LIMIT 100', $selects[1], 'keyset paging: the second page starts after the last id seen' );
		$this->assertSame( [ true, false ], $GLOBALS['bfrc_test_suspended_invalidation'], 'cache invalidation suspended for the loop, restored after' );
		$this->assertSame( 2, $GLOBALS['bfrc_test_runtime_flushes'], 'the runtime cache is dropped once per batch so memory does not grow with the recipes' );

		$term_selects = $this->wpdb->queries_matching( 'SELECT term_taxonomy_id, term_id FROM bfw_term_taxonomy' );
		$this->assertCount( 1, $term_selects, 'three rows is a short page' );
		$this->assertStringContainsString( "taxonomy LIKE 'bfrc\\\\_%' LIMIT 100", $term_selects[0], 'no ORDER BY: each batch deletes what it read' );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_term_relationships WHERE term_taxonomy_id IN (7,8,9)' ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_term_taxonomy WHERE term_taxonomy_id IN (7,8,9)' ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_termmeta WHERE term_id IN (70,91) AND NOT EXISTS (SELECT 1 FROM bfw_term_taxonomy tt WHERE tt.term_id = bfw_termmeta.term_id)' ), 'term meta of our terms, only where no other taxonomy still uses the term' );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_terms WHERE term_id IN (70,91) AND NOT EXISTS (SELECT 1 FROM bfw_term_taxonomy tt WHERE tt.term_id = bfw_terms.term_id)' ), 'only our terms, never every orphan on the site' );
		$this->assertSame( [], $this->wpdb->queries_matching( 'LEFT JOIN' ) );

		foreach ( $this->listed_user_meta() as $key ) {
			$this->assertSame( '', get_user_meta( 5, $key, true ), "{$key} removed from every user" );
		}
		$this->assertSame( 'keep', get_user_meta( 5, 'other_plugin_meta', true ), 'only our keys' );
		$this->assertSame( 0, $GLOBALS['bfrc_test_cache_flushes'] ?? 0, 'the object cache is shared with every plugin: never flushed whole' );
		$this->assertSame( [ 'bfrc' ], $GLOBALS['bfrc_test_group_flushes'], 'the plugin\'s cache group is flushed' );
		foreach ( [ [ 1001, 'posts' ], [ 1251, 'post_meta' ], [ 70, 'terms' ], [ 70, 'term_meta' ], [ 91, 'terms' ], [ 'bfrc_meal_type_children', 'options' ], [ 'alloptions', 'options' ], [ 'notoptions', 'options' ] ] as [ $key, $group ] ) {
			$this->assertFalse( wp_cache_get( $key, $group ), "{$group}/{$key}: what the direct deletes made stale leaves the cache" );
		}
		foreach ( [ [ 55, 'terms' ], [ 999, 'posts' ], [ 'other_plugin_option', 'options' ], [ 1, 'other_plugin_group' ] ] as [ $key, $group ] ) {
			$this->assertSame( 'cached', wp_cache_get( $key, $group ), "{$group}/{$key}: another plugin's entry stays" );
		}
		foreach ( [ 'posts', 'comment', 'terms' ] as $group ) {
			$this->assertNotFalse( wp_cache_get( 'last_changed', $group ), "{$group}: cached queries are stale by key" );
		}
		$this->assertCount( 1, $this->wpdb->queries_matching( "SELECT option_name FROM bfw_options WHERE option_name LIKE 'bfrc\\\\_%'" ), 'the names are read with the same condition the delete uses' );
		$this->assertFalse( get_option( 'bfrc_settings' ) );
	}

	public function test_opt_in_pages_through_terms_in_batches_of_one_hundred(): void {
		$this->seed( true );
		$this->wpdb->queue( 'get_col', [] );
		$page = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$page[] = [ 'term_taxonomy_id' => (string) $i, 'term_id' => (string) ( 500 + $i ) ];
		}
		$this->wpdb->queue( 'get_results', $page );
		$this->wpdb->queue( 'get_results', [ [ 'term_taxonomy_id' => '101', 'term_id' => '601' ] ] );
		$this->wpdb->always( 'query', 100 );
		$this->run_uninstall();
		$this->assertCount( 2, $this->wpdb->queries_matching( 'SELECT term_taxonomy_id, term_id FROM bfw_term_taxonomy' ), 'a full page, then the short page that ends the loop' );
		$this->assertCount( 2, $this->wpdb->queries_matching( 'DELETE FROM bfw_term_taxonomy WHERE' ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_terms WHERE term_id IN (601)' ) );
	}

	public function test_opt_in_with_no_terms_issues_no_term_deletes(): void {
		$this->seed( true );
		$this->wpdb->queue( 'get_col', [] );
		$this->wpdb->queue( 'get_results', [] );
		$this->run_uninstall();
		$this->assertSame( [], $this->wpdb->queries_matching( 'DELETE FROM bfw_term_relationships' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'DELETE FROM bfw_terms' ) );
	}

	public function test_role_removal_pages_through_a_large_role_and_keeps_users_other_roles(): void {
		$this->seed( false );
		for ( $i = 1; $i <= 203; $i++ ) {
			bfrc_test_add_user( 100 + $i, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		}
		bfrc_test_add_user( 6, [ 'roles' => [ 'editor', Capabilities::ROLE_MANAGER ] ] );
		$this->run_uninstall();

		$writer_calls = array_values( array_filter( $GLOBALS['bfrc_test_get_users_calls'], static fn( array $a ): bool => Capabilities::ROLE_WRITER === $a['role'] ) );
		$this->assertCount( 3, $writer_calls, '100, 100, then the short page of 4 (incl. user 5) that ends the loop' );
		foreach ( $writer_calls as $call ) {
			$this->assertSame(
				[
					'role'    => Capabilities::ROLE_WRITER,
					'fields'  => 'all',
					'number'  => 100,
					'paged'   => 1,
					'orderby' => 'ID',
				],
				$call,
				'whole user objects (primed in one query), always page 1'
			);
		}
		$this->assertSame( [ 'subscriber' ], ( new \WP_User( 303 ) )->roles, 'the last user of the last page was moved too' );
		$this->assertSame( [ 'editor' ], ( new \WP_User( 6 ) )->roles, 'another role stays, no subscriber added' );
	}

	public function test_role_removal_stops_when_a_pass_makes_no_progress(): void {
		$this->seed( false );
		for ( $i = 1; $i <= 150; $i++ ) {
			bfrc_test_add_user( 100 + $i, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		}
		$GLOBALS['bfrc_test_role_removal_blocked'] = true;
		$this->run_uninstall();
		$writer_calls = array_filter( $GLOBALS['bfrc_test_get_users_calls'], static fn( array $a ): bool => Capabilities::ROLE_WRITER === $a['role'] );
		$this->assertCount( 2, $writer_calls, 'the second pass returned the same users, so the loop ended instead of spinning' );
		$this->assertNull( get_role( Capabilities::ROLE_WRITER ), 'the role itself is still removed' );
	}

	public function test_term_loop_stops_when_a_full_batch_could_not_be_deleted(): void {
		$this->seed( true );
		$this->wpdb->queue( 'get_col', [] );
		$page = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$page[] = [ 'term_taxonomy_id' => (string) $i, 'term_id' => (string) ( 500 + $i ) ];
		}
		$this->wpdb->always( 'get_results', $page ); // the same full page for ever
		$this->wpdb->always( 'query', false );        // every DELETE refused (locked table, read-only user)
		$this->run_uninstall();
		$this->assertCount( 1, $this->wpdb->queries_matching( 'SELECT term_taxonomy_id, term_id FROM bfw_term_taxonomy' ), 'one pass: a batch that did not delete ends the loop instead of re-reading it for ever' );
	}

	public function test_cache_group_is_not_flushed_on_a_cache_without_flush_group_support(): void {
		$this->seed( false );
		$GLOBALS['bfrc_test_cache_supports'] = [];
		$this->run_uninstall();
		$this->assertSame( [], $GLOBALS['bfrc_test_group_flushes'], 'a drop-in without flush_group() would fatal; wp_cache_supports() is asked first' );
	}

	public function test_runtime_cache_is_not_flushed_on_a_cache_without_flush_runtime_support(): void {
		$this->seed( true );
		$GLOBALS['bfrc_test_cache_supports'] = [ 'flush_group' ];
		$this->wpdb->queue( 'get_col', [ '41' ] );
		$this->wpdb->queue( 'get_results', [] );
		$this->run_uninstall();
		$this->assertSame( [ [ 41, true ] ], $GLOBALS['bfrc_test_deleted_posts'] );
		$this->assertSame( 0, $GLOBALS['bfrc_test_runtime_flushes'], 'core would _doing_it_wrong(): wp_cache_supports() is asked first' );
	}

	public function test_on_multisite_an_empty_site_list_deletes_nothing_and_never_counts_as_every_site_opting_in(): void {
		$GLOBALS['bfrc_test_multisite'] = true;
		$GLOBALS['bfrc_test_sites']     = [];
		bfrc_test_add_user( 5, [ 'roles' => [ 'editor' ] ] );
		update_user_meta( 5, 'bfrc_about', 'kept' );
		$this->run_uninstall();
		$this->assertSame( 'kept', get_user_meta( 5, 'bfrc_about', true ), 'no site examined: "every site opted in" is false, not vacuously true' );
		$this->assertSame( [], $GLOBALS['bfrc_test_switched'] );
		$this->assertSame( 0, $GLOBALS['bfrc_test_cache_flushes'] );
	}

	public function test_profiles_are_removed_before_any_site_is_cleaned_so_an_interrupted_run_has_honoured_the_choice(): void {
		$this->seed( true );
		$this->wpdb->queue(
			'get_col',
			static function (): never {
				throw new \RuntimeException( 'db gone' );
			}
		);
		try {
			$this->run_uninstall();
			$this->fail( 'expected the exception to propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'db gone', $e->getMessage() );
		}
		$this->assertSame( '', get_user_meta( 5, 'bfrc_about', true ), 'profiles went first' );
		$this->assertSame( Settings::DEFAULTS['per_page'], get_option( 'bfrc_settings' )['per_page'], 'the settings option is still there, so a repeated run reads the same choice' );
	}

	public function test_on_multisite_every_site_is_cleaned_only_opted_in_sites_lose_content_and_profiles_survive_unless_all_opted_in(): void {
		$GLOBALS['bfrc_test_multisite'] = true;
		$GLOBALS['bfrc_test_sites']     = [ 1, 2, 3 ];
		foreach ( [ 1, 2, 3 ] as $id ) {
			switch_to_blog( $id );
			Installer::activate();
			update_option( 'bfrc_settings', array_merge( Settings::DEFAULTS, [ 'delete_data_on_uninstall' => 2 === $id ] ) );
			restore_current_blog();
		}
		bfrc_test_add_user( 5, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		update_user_meta( 5, 'bfrc_about', 'Cooks on sites 1 and 2.' );
		$GLOBALS['bfrc_test_switched'] = [];
		$this->wpdb->queue( 'get_col', [] );    // site 1: its option names (it keeps its content)
		$this->wpdb->queue( 'get_col', [ '9' ] ); // site 2: its recipes
		$this->wpdb->queue( 'get_results', [] );

		$this->run_uninstall();

		$this->assertSame( [ 1, 2, 3, 1, 2, 3 ], $GLOBALS['bfrc_test_switched'], 'every site visited twice, in order: the decisions pass, then the cleanup pass' );
		$this->assertSame( 1, get_current_blog_id(), 'restored to the main site' );
		$this->assertSame( [ [ 9, true ] ], $GLOBALS['bfrc_test_deleted_posts'], 'only site 2 opted in' );
		$this->assertSame( 'Cooks on sites 1 and 2.', get_user_meta( 5, 'bfrc_about', true ), 'user meta is network-wide: one opted-in site cannot delete profiles sites 1 and 3 kept' );
		foreach ( [ 1, 2, 3 ] as $id ) {
			switch_to_blog( $id );
			foreach ( Installer::OPTIONS as $key ) {
				$this->assertFalse( get_option( $key ), "site {$id}: {$key}" );
			}
			$this->assertNull( get_role( Capabilities::ROLE_WRITER ), "site {$id}: role" );
			$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_others_bfrc_recipes' ), "site {$id}: editor caps revoked" );
			restore_current_blog();
		}
	}

	public function test_on_multisite_profiles_go_when_every_site_opted_in(): void {
		$GLOBALS['bfrc_test_multisite'] = true;
		$GLOBALS['bfrc_test_sites']     = [ 1, 2 ];
		foreach ( [ 1, 2 ] as $id ) {
			switch_to_blog( $id );
			Installer::activate();
			update_option( 'bfrc_settings', array_merge( Settings::DEFAULTS, [ 'delete_data_on_uninstall' => true ] ) );
			restore_current_blog();
		}
		bfrc_test_add_user( 5, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		update_user_meta( 5, 'bfrc_about', 'x' );
		foreach ( [ 1, 2 ] as $site ) {
			$this->wpdb->queue( 'get_col', [] );
			$this->wpdb->queue( 'get_results', [] );
		}
		$this->run_uninstall();
		$this->assertSame( '', get_user_meta( 5, 'bfrc_about', true ) );
	}

	public function test_a_site_that_throws_does_not_leave_the_request_switched(): void {
		$GLOBALS['bfrc_test_multisite'] = true;
		$GLOBALS['bfrc_test_sites']     = [ 1, 2 ];
		switch_to_blog( 1 );
		Installer::activate();
		update_option( 'bfrc_settings', array_merge( Settings::DEFAULTS, [ 'delete_data_on_uninstall' => true ] ) );
		restore_current_blog();
		$this->wpdb->queue(
			'get_col',
			static function (): never {
				throw new \RuntimeException( 'db gone' );
			}
		);
		try {
			$this->run_uninstall();
			$this->fail( 'expected the exception to propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'db gone', $e->getMessage() );
		}
		$this->assertSame( 1, get_current_blog_id(), 'restore_current_blog() ran in finally' );
	}

	public function test_without_the_uninstall_constant_the_file_does_nothing(): void {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 ' . escapeshellarg( BFRC_DIR . 'uninstall.php' ) . ' 2>&1';
		exec( $cmd, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- test: the file is run as PHP would without the constant.
		$this->assertSame( 0, $status );
		$this->assertSame( [], $output, 'no output, no fatal: it exits before touching anything (none of the WordPress functions exist in this process)' );
		$source = (string) file_get_contents( BFRC_DIR . 'uninstall.php' );
		$this->assertSame( 1, substr_count( $source, 'bfrcp_' ), 'the Pro prefix appears once, in the comment explaining the LIKE; never in code' );
		$this->assertStringNotContainsString( 'bfrc_submission', $source, 'the submission post type is Pro\'s to remove' );
	}
}
