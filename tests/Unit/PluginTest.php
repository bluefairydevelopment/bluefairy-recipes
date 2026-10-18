<?php
/**
 * The boot sequence and the service registry.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Installer;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class PluginTest extends TestCase {

	public function test_bfrc_loaded_fires_after_the_settings_exist_and_before_bfrc_booted(): void {
		$seen = null;
		add_action(
			'bfrc_loaded',
			static function () use ( &$seen ): void {
				$seen = [
					'settings' => Plugin::service( 'settings' ) instanceof Settings,
					'booted'   => did_action( 'bfrc_booted' ),
					'init'     => has_action( 'init', [ Installer::class, 'maybe_flush_rewrite' ] ),
				];
			}
		);

		Plugin::boot();

		$this->assertSame(
			[
				'settings' => true,
				'booted'   => 0,
				'init'     => 99,
			],
			$seen
		);
		$this->assertSame( 1, did_action( 'bfrc_loaded' ) );
		$this->assertSame( 1, did_action( 'bfrc_booted' ) );
	}

	public function test_boot_provisions_a_fresh_site_through_maybe_upgrade(): void {
		Plugin::boot();
		$this->assertSame( BFRC_DB_VERSION, get_option( 'bfrc_db_version' ) );
	}

	public function test_boot_runs_once(): void {
		Plugin::boot();
		Plugin::boot();
		$this->assertSame( 1, did_action( 'bfrc_loaded' ) );
	}

	public function test_reset_allows_a_second_boot(): void {
		Plugin::boot();
		Plugin::reset();
		$this->assertNull( Plugin::service( 'settings' ) );
		Plugin::boot();
		$this->assertSame( 2, did_action( 'bfrc_loaded' ) );
	}

	public function test_service_and_set(): void {
		$this->assertNull( Plugin::service( 'nope' ) );
		$object = new \stdClass();
		Plugin::set( 'thing', $object );
		$this->assertSame( $object, Plugin::service( 'thing' ) );
	}

	public function test_is_pro_is_false_until_the_filter_says_otherwise(): void {
		$this->assertFalse( Plugin::is_pro() );
		add_filter( 'bfrc_is_pro', '__return_true' );
		$this->assertTrue( Plugin::is_pro() );
	}

	public function test_the_entry_file_declares_the_versions_the_harness_and_readme_expect(): void {
		$source = (string) file_get_contents( BFRC_FILE );
		$this->assertStringContainsString( " * Version:           1.0.0\n", $source );
		$this->assertStringContainsString( "define( 'BFRC_VERSION', '1.0.0' );", $source );
		$this->assertStringContainsString( " * Requires at least: 6.7\n", $source, 'register_block_template and the Interactivity API need 6.7' );
		$this->assertStringContainsString( " * Requires PHP:      8.1\n", $source );
		$this->assertStringNotContainsString( 'Update URI', $source, 'a free plugin on WordPress.org has no updater' );
		$this->assertStringContainsString( "add_action( 'plugins_loaded', [ BlueFairy\\Recipes\\Plugin::class, 'boot' ], 10 );", $source );
		$this->assertStringContainsString( 'function bfrc_plugin(): string', $source );
	}
}
