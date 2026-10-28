<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Installer;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class InstallerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1; // registration and activation run after init
	}

	public function test_the_lists_in_uninstall_php_match_the_code_in_both_directions(): void {
		$uninstall = (string) file_get_contents( BFRC_DIR . 'uninstall.php' );
		$this->assertSame( 1, preg_match( '/\$bfrc_option_keys = \[(.*?)\];/s', $uninstall, $m ) );
		preg_match_all( "/'([a-z_]+)'/", $m[1], $keys );
		$this->assertSame( Installer::OPTIONS, $keys[1] );
		$this->assertSame( 1, preg_match( '/\$bfrc_caps = \[(.*?)\];/s', $uninstall, $c ) );
		preg_match_all( "/'([a-z_]+)'/", $c[1], $ckeys );
		$this->assertSame( array_values( array_diff( Capabilities::all_caps(), [ 'upload_files', 'read' ] ) ), $ckeys[1], 'uninstall revokes exactly our caps' );
		$this->assertSame( 1, preg_match( '/\$bfrc_roles = \[(.*?)\];/s', $uninstall, $r ) );
		preg_match_all( "/'([a-z_]+)'/", $r[1], $rkeys );
		$this->assertSame( [ Capabilities::ROLE_WRITER, Capabilities::ROLE_MANAGER ], $rkeys[1] );
		$this->assertSame( 1, preg_match( '/\$bfrc_core_roles = \[(.*?)\];/s', $uninstall, $cr ) );
		preg_match_all( "/'([a-z_]+)'/", $cr[1], $crkeys );
		$this->assertSame( array_keys( Capabilities::core_grants() ), $crkeys[1], 'uninstall revokes from exactly the roles grant_core_roles() grants to' );
	}

	public function test_db_version_in_the_harness_is_read_from_the_plugin_file(): void {
		$this->assertSame( 1, preg_match( "/define\\( 'BFRC_DB_VERSION', '([^']+)' \\);/", (string) file_get_contents( BFRC_FILE ), $v ) );
		$this->assertSame( $v[1], BFRC_DB_VERSION );
		$this->assertSame( '1.0.0', $v[1] );
	}

	public function test_activate_creates_roles_writes_sanitised_defaults_and_versions_grants_core_roles_and_raises_the_flush_flag(): void {
		Installer::activate();
		$this->assertInstanceOf( \WP_Role::class, get_role( Capabilities::ROLE_WRITER ) );
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfrc_settings' ), 'sanitize( DEFAULTS ) is DEFAULTS' );
		$this->assertSame( 'on', $GLOBALS['bfrc_test_option_autoload']['bfrc_settings'] );
		$this->assertSame( BFRC_VERSION, get_option( 'bfrc_version' ) );
		$this->assertSame( BFRC_DB_VERSION, get_option( 'bfrc_db_version' ) );
		$this->assertSame( 'on', $GLOBALS['bfrc_test_option_autoload']['bfrc_db_version'] );
		$this->assertTrue( get_role( 'author' )->has_cap( 'edit_bfrc_recipes' ), 'grant_core_roles defaults on' );
		$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ) );
		$this->assertSame( 'on', $GLOBALS['bfrc_test_option_autoload'][ Settings::FLUSH_OPTION ], 'the flag is autoloaded, so reading it costs no query' );
		$this->assertSame( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ], array_keys( $GLOBALS['bfrc_test_taxonomies'] ), 'the taxonomies are registered on the fly: activation runs after init' );
		$this->assertSame( [ 'Breakfast', 'Dessert', 'Dinner', 'Lunch', 'Snack' ], get_terms( [ 'taxonomy' => 'bfrc_meal_type', 'fields' => 'names', 'hide_empty' => false ] ), 'starter terms seeded' );
		$this->assertSame( [], $GLOBALS['bfrc_test_rewrite_flushes'], 'the flush itself waits for init' );
	}

	public function test_activate_leaves_existing_terms_alone(): void {
		( new \BlueFairy\Recipes\Content\Taxonomies( new Settings() ) )->register();
		wp_insert_term( 'Brunch', 'bfrc_meal_type' );
		Installer::activate();
		$this->assertSame( [ 'Brunch' ], get_terms( [ 'taxonomy' => 'bfrc_meal_type', 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [ 'Dairy-Free', 'Gluten-Free', 'Vegan', 'Vegetarian' ], get_terms( [ 'taxonomy' => 'bfrc_diet', 'fields' => 'names', 'hide_empty' => false ] ), 'an empty taxonomy still gets its starters' );
	}

	public function test_activate_keeps_existing_settings_and_honours_grant_core_roles_off(): void {
		update_option( 'bfrc_settings', [ 'per_page' => 7, 'grant_core_roles' => false ] );
		Installer::activate();
		$this->assertSame( [ 'per_page' => 7, 'grant_core_roles' => false ], get_option( 'bfrc_settings' ), 'never rewritten' );
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_bfrc_recipes' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'administrators hold MANAGE regardless' );
	}

	public function test_a_refused_db_version_write_does_not_raise_the_flush_flag(): void {
		$GLOBALS['bfrc_test_option_write_fails'][] = 'bfrc_db_version';
		Installer::activate();
		$this->assertFalse( get_option( 'bfrc_db_version' ) );
		$this->assertSame( '0', get_option( Settings::FLUSH_OPTION ), 'otherwise every request would re-provision and flush' );
	}

	public function test_provision_site_is_idempotent_and_unprovision_site_only_lowers_the_flag_and_drops_the_rewrite_rules(): void {
		Installer::provision_site();
		Installer::provision_site();
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfrc_settings' ) );
		$this->assertInstanceOf( \WP_Role::class, get_role( Capabilities::ROLE_WRITER ) );
		update_option( 'rewrite_rules', [ 'x' => 'y' ] );
		Installer::unprovision_site();
		$this->assertSame( '0', get_option( Settings::FLUSH_OPTION ) );
		$this->assertFalse( get_option( 'rewrite_rules' ), 'core rebuilds the rules on the next request, without ours' );
		$this->assertSame( [], $GLOBALS['bfrc_test_rewrite_flushes'], 'no flush while the post type is still registered: that would write our rules back' );
		$this->assertInstanceOf( \WP_Role::class, get_role( Capabilities::ROLE_WRITER ), 'roles stay' );
		$this->assertSame( BFRC_VERSION, get_option( 'bfrc_version' ), 'versions stay' );
	}

	public function test_deactivate_keeps_roles_settings_and_versions(): void {
		Installer::activate();
		Installer::deactivate();
		$this->assertInstanceOf( \WP_Role::class, get_role( Capabilities::ROLE_WRITER ), 'roles are data: they stay until uninstall' );
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfrc_settings' ) );
		$this->assertSame( BFRC_VERSION, get_option( 'bfrc_version' ) );
		$this->assertSame( '0', get_option( Settings::FLUSH_OPTION ) );
	}

	public function test_maybe_upgrade_provisions_a_site_with_no_version_before_init_without_touching_rewrite_rules(): void {
		$GLOBALS['bfrc_test_fired']['init'] = 0; // plugins_loaded on a never-provisioned sub-site: core has no $wp_rewrite yet
		Installer::maybe_upgrade();
		$this->assertSame( [], $GLOBALS['bfrc_test_terms']['bfrc_meal_type'] ?? [], 'no starter terms before init: they would be English on any site, translations load at init' );
		$this->assertSame( 20, has_action( 'init', [ Installer::class, 'seed_default_terms' ] ), 'they wait for init 20' );
		Installer::maybe_upgrade();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		( new \BlueFairy\Recipes\Content\Taxonomies( new Settings() ) )->register(); // The base's own init 5.
		Installer::seed_default_terms();
		$this->assertSame( [ 'Breakfast', 'Dessert', 'Dinner', 'Lunch', 'Snack' ], get_terms( [ 'taxonomy' => 'bfrc_meal_type', 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ), 'the real registration at init 5 adds the rules and the flag writes them' );
		$this->assertSame( BFRC_DB_VERSION, get_option( 'bfrc_db_version' ) );
		$this->assertInstanceOf( \WP_Role::class, get_role( Capabilities::ROLE_MANAGER ) );
		$this->assertSame( Settings::DEFAULTS, get_option( 'bfrc_settings' ) );
	}

	public function test_maybe_upgrade_is_a_no_op_at_the_current_version(): void {
		Installer::activate();
		$GLOBALS['bfrc_test_roles'][ Capabilities::ROLE_WRITER ]->remove_cap( 'publish_bfrc_recipes' );
		$before = $GLOBALS['bfrc_test_options'];
		Installer::maybe_upgrade();
		$this->assertFalse( get_role( Capabilities::ROLE_WRITER )->has_cap( 'publish_bfrc_recipes' ), 'nothing re-asserted when the version is current' );
		$this->assertSame( $before, $GLOBALS['bfrc_test_options'], 'nothing written on a normal boot' );
	}

	public function test_maybe_upgrade_from_an_older_data_version_runs_the_steps_reasserts_roles_and_records_the_version(): void {
		Installer::activate();
		update_option( 'bfrc_db_version', '0.9.0' );
		update_option( 'bfrc_version', '0.9.0' );
		$GLOBALS['bfrc_test_roles'][ Capabilities::ROLE_WRITER ]->remove_cap( 'publish_bfrc_recipes' );
		Installer::maybe_upgrade();
		$this->assertSame( BFRC_DB_VERSION, get_option( 'bfrc_db_version' ) );
		$this->assertSame( BFRC_VERSION, get_option( 'bfrc_version' ), 'the plugin version is brought up to date too' );
		$this->assertTrue( get_role( Capabilities::ROLE_WRITER )->has_cap( 'publish_bfrc_recipes' ), 'roles re-asserted on upgrade' );
	}

	public function test_maybe_upgrade_updates_only_the_plugin_version_on_a_code_only_release(): void {
		Installer::activate();
		update_option( 'bfrc_version', '0.9.9' );
		Installer::maybe_upgrade();
		$this->assertSame( BFRC_VERSION, get_option( 'bfrc_version' ) );
		$this->assertSame( BFRC_DB_VERSION, get_option( 'bfrc_db_version' ) );
	}

	public function test_upgrade_data_is_a_placeholder_with_no_steps_yet(): void {
		// Named as such: it exists so the method is covered, and proves nothing until a step is added.
		Installer::upgrade_data( '0.0.1' );
		$this->assertSame( [], $GLOBALS['bfrc_test_options'] );
	}

	public function test_maybe_flush_rewrite_flushes_once_when_flagged_and_never_otherwise(): void {
		Installer::maybe_flush_rewrite();
		$this->assertSame( [], $GLOBALS['bfrc_test_rewrite_flushes'], 'no flag at all: nothing' );
		Installer::provision_site();
		Installer::maybe_flush_rewrite();
		Installer::maybe_flush_rewrite();
		$this->assertSame( [ false ], $GLOBALS['bfrc_test_rewrite_flushes'], 'the flag is consumed: one soft flush' );
		$this->assertSame( '0', get_option( Settings::FLUSH_OPTION ), 'lowered, not deleted: the row stays so the read is free' );
		Installer::request_rewrite_flush();
		Installer::maybe_flush_rewrite();
		$this->assertSame( [ false, false ], $GLOBALS['bfrc_test_rewrite_flushes'] );
	}

	public function test_network_activation_provisions_every_site_in_pages_and_single_activation_only_the_current_one(): void {
		$GLOBALS['bfrc_test_multisite'] = true;
		$GLOBALS['bfrc_test_sites']     = range( 1, Installer::SITES_PER_PAGE + 3 );
		Installer::activate( false );
		$this->assertSame( [], $GLOBALS['bfrc_test_switched'] );
		Installer::activate( true );
		$this->assertSame( range( 1, Installer::SITES_PER_PAGE + 3 ), $GLOBALS['bfrc_test_switched'], 'every site, across two pages' );
		$this->assertSame( 1, get_current_blog_id(), 'restored to the main site' );
		foreach ( [ 2, Installer::SITES_PER_PAGE + 3 ] as $id ) {
			switch_to_blog( $id );
			$this->assertSame( BFRC_DB_VERSION, get_option( 'bfrc_db_version' ), "site {$id}" );
			$this->assertInstanceOf( \WP_Role::class, get_role( Capabilities::ROLE_WRITER ), "site {$id}: role" );
			$this->assertTrue( get_role( 'editor' )->has_cap( 'edit_others_bfrc_recipes' ), "site {$id}: core grant" );
			$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ), "site {$id}: flag" );
			restore_current_blog();
		}
	}

	public function test_a_site_created_after_network_activation_is_provisioned_on_wp_initialize_site(): void {
		$GLOBALS['bfrc_test_multisite']      = true;
		$GLOBALS['bfrc_test_sites']          = [ 1, 3 ];
		$GLOBALS['bfrc_test_network_active'] = true;
		$site                                = new \WP_Site();
		$site->blog_id                       = '3';
		Installer::initialize_site( $site );
		$this->assertSame( 1, get_current_blog_id(), 'back on the site it started on' );
		switch_to_blog( 3 );
		$this->assertSame( BFRC_DB_VERSION, get_option( 'bfrc_db_version' ), 'the new site is provisioned' );
		$this->assertInstanceOf( \WP_Role::class, get_role( Capabilities::ROLE_WRITER ) );
		restore_current_blog();
		$GLOBALS['bfrc_test_network_active'] = false;
		$GLOBALS['bfrc_test_switched']       = [];
		$site->blog_id                       = '2';
		Installer::initialize_site( $site );
		$this->assertSame( [], $GLOBALS['bfrc_test_switched'], 'not network-active: the new site is left alone' );
	}
}
