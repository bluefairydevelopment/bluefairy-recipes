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

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'administrator', 'editor', 'author', 'subscriber' ] as $role ) {
			$GLOBALS['bfrc_test_roles'][ $role ] = new \WP_Role( $role, [ 'read' => true ] );
		}
	}

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require BFRC_DIR . 'uninstall.php';
	}

	/**
	 * The invariant: everything activation creates, with content and a writer on top.
	 */
	private function seed( bool $delete_data ): void {
		Installer::activate();
		update_option( 'bfrc_settings', array_merge( Settings::DEFAULTS, [ 'delete_data_on_uninstall' => $delete_data ] ) );
		bfrc_test_add_user( 5, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		update_user_meta( 5, 'bfrc_about', 'Cooks.' );
		update_user_meta( 5, 'other_plugin_meta', 'keep' );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'post' ] );
	}

	public function test_default_removes_options_transients_roles_and_caps_but_keeps_content_and_profiles(): void {
		$this->seed( false );
		$this->run_uninstall();

		foreach ( Installer::OPTIONS as $key ) {
			$this->assertFalse( get_option( $key ), $key );
		}
		$this->assertFalse( get_transient( Settings::FLUSH_TRANSIENT ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_options WHERE option_name LIKE' ), 'dynamically named transients swept' );
		$this->assertNull( get_role( Capabilities::ROLE_WRITER ) );
		$this->assertNull( get_role( Capabilities::ROLE_MANAGER ) );
		$this->assertSame( [ 'subscriber' ], ( new \WP_User( 5 ) )->roles, 'the writer keeps an account with a role' );
		foreach ( [ 'administrator', 'editor', 'author' ] as $role ) {
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
		$this->assertSame( 'Cooks.', get_user_meta( 5, 'bfrc_about', true ), 'writer profiles stay' );
	}

	public function test_opt_in_deletes_recipes_in_batches_terms_and_profile_meta_and_only_ours(): void {
		$this->seed( true );
		$this->wpdb->queue( 'get_col', [ '41', '43' ] ); // a short page: the loop ends after it
		$this->wpdb->queue( 'get_col', [ '7', '8' ] ); // term_taxonomy_ids of bfrc_* taxonomies
		$this->run_uninstall();

		$this->assertSame( [ [ 41, true ], [ 43, true ] ], $GLOBALS['bfrc_test_deleted_posts'], 'force-deleted, no trash' );
		$selects = $this->wpdb->queries_matching( 'SELECT ID FROM bfw_posts' );
		$this->assertCount( 1, $selects, 'a page of 2 is a short page, so the loop ends after it' );
		$this->assertStringContainsString( "post_type = 'bfrc_recipe' ORDER BY ID LIMIT 100", $selects[0] );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_term_relationships WHERE term_taxonomy_id IN (7,8)' ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE FROM bfw_term_taxonomy WHERE term_taxonomy_id IN (7,8)' ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'DELETE t FROM bfw_terms t LEFT JOIN' ) );
		$this->assertStringContainsString( "taxonomy LIKE 'bfrc\\\\_%'", $this->wpdb->queries_matching( 'SELECT term_taxonomy_id' )[0], 'esc_like() escapes the underscore and prepare() escapes the backslash, so MySQL sees \\_ and bfrcX never matches' );
		$this->assertSame( '', get_user_meta( 5, 'bfrc_about', true ), 'profile fields removed from every user' );
		$this->assertSame( 'keep', get_user_meta( 5, 'other_plugin_meta', true ), 'only our keys' );
		$this->assertFalse( get_option( 'bfrc_settings' ) );
	}

	public function test_opt_in_with_no_terms_issues_no_term_deletes(): void {
		$this->seed( true );
		$this->wpdb->queue( 'get_col', [] ); // no recipes
		$this->wpdb->queue( 'get_col', [] ); // no bfrc terms
		$this->run_uninstall();
		$this->assertSame( [], $this->wpdb->queries_matching( 'DELETE FROM bfw_term_relationships' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'DELETE t FROM bfw_terms' ) );
	}

	public function test_corrupt_settings_do_not_delete_content(): void {
		$this->seed( false );
		update_option( 'bfrc_settings', 'garbage' );
		$this->run_uninstall();
		$this->assertSame( [], $GLOBALS['bfrc_test_deleted_posts'] );
		$this->assertSame( [], $this->wpdb->queries_matching( 'SELECT ID FROM bfw_posts' ) );
	}

	public function test_a_user_with_another_role_keeps_it_and_is_not_made_a_subscriber(): void {
		$this->seed( false );
		bfrc_test_add_user( 6, [ 'roles' => [ 'editor', Capabilities::ROLE_MANAGER ] ] );
		$this->run_uninstall();
		$this->assertSame( [ 'editor' ], ( new \WP_User( 6 ) )->roles );
	}

	public function test_on_multisite_every_site_is_cleaned_and_only_an_opted_in_site_loses_content(): void {
		$GLOBALS['bfrc_test_multisite'] = true;
		$GLOBALS['bfrc_test_sites']     = [ 1, 2, 3 ];
		foreach ( [ 1, 2, 3 ] as $id ) {
			switch_to_blog( $id );
			Installer::activate();
			update_option( 'bfrc_settings', array_merge( Settings::DEFAULTS, [ 'delete_data_on_uninstall' => 2 === $id ] ) );
			restore_current_blog();
		}
		$GLOBALS['bfrc_test_switched'] = [];
		$this->wpdb->queue( 'get_col', [ '9' ] );
		$this->wpdb->queue( 'get_col', [] ); // no bfrc terms on site 2

		$this->run_uninstall();

		$this->assertSame( [ 1, 2, 3 ], $GLOBALS['bfrc_test_switched'], 'every site visited, in order' );
		$this->assertSame( 1, get_current_blog_id(), 'restored to the main site' );
		$this->assertSame( [ [ 9, true ] ], $GLOBALS['bfrc_test_deleted_posts'], 'only site 2 opted in' );
		foreach ( [ 1, 2, 3 ] as $id ) {
			switch_to_blog( $id );
			foreach ( Installer::OPTIONS as $key ) {
				$this->assertFalse( get_option( $key ), "site {$id}: {$key}" );
			}
			$this->assertNull( get_role( Capabilities::ROLE_WRITER ), "site {$id}: role" );
			restore_current_blog();
		}
	}

	public function test_exits_without_the_uninstall_constant(): void {
		$source = (string) file_get_contents( BFRC_DIR . 'uninstall.php' );
		$this->assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;\n}", $source );
		$this->assertStringNotContainsString( 'bfrcp_', $source, 'never touches the Pro add-on' );
		$this->assertStringNotContainsString( 'bfrc_submission', $source, 'the submission post type is Pro\'s to remove' );
	}
}
