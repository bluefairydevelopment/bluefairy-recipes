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

	public function test_every_place_a_version_is_declared_agrees(): void {
		$source = (string) file_get_contents( BFRC_FILE );
		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $source, $header ) );
		$this->assertSame( BFRC_VERSION, $header[1], 'header vs constant (the harness reads the constant from this file)' );
		$this->assertSame( 1, preg_match( '/^Stable tag: (\S+)$/m', (string) file_get_contents( BFRC_DIR . 'readme.txt' ), $stable ) );
		$this->assertSame( BFRC_VERSION, $stable[1], 'readme.txt Stable tag' );
		$this->assertStringContainsString( "define( 'BFRC_VERSION', '" . BFRC_VERSION . "' );", (string) file_get_contents( BFRC_DIR . 'phpstan-bootstrap.php' ), 'phpstan bootstrap' );
		$composer = json_decode( (string) file_get_contents( BFRC_DIR . 'composer.json' ), true );
		$this->assertIsArray( $composer );
		$this->assertArrayNotHasKey( 'version', $composer, 'composer.json carries no version: the header is the one declaration' );
		$this->assertStringContainsString( '## 1.0.0', (string) file_get_contents( BFRC_DIR . 'CHANGELOG.md' ) );
		$this->assertStringContainsString( " * Requires at least: 6.7\n", $source, 'register_block_template and the Interactivity API need 6.7' );
		$this->assertStringContainsString( " * Requires PHP:      8.1\n", $source );
		$this->assertStringNotContainsString( 'Update URI', $source, 'a free plugin on WordPress.org has no updater' );
		$this->assertStringContainsString( "add_action( 'plugins_loaded', [ BlueFairy\\Recipes\\Plugin::class, 'boot' ], 10 );", $source );
		$this->assertStringContainsString( 'function bfrc_plugin(): string', $source );
	}

	public function test_directly_hooked_callbacks_take_no_typed_first_parameter(): void {
		foreach ( [ [ Plugin::class, 'boot' ], [ Installer::class, 'maybe_flush_rewrite' ] ] as [ $class, $method ] ) {
			$params = ( new \ReflectionMethod( $class, $method ) )->getParameters();
			$this->assertTrue( [] === $params || null === $params[0]->getType(), "{$class}::{$method}: WordPress passes '' to an argument-less action's callbacks" );
		}
		foreach ( [ [ Installer::class, 'activate' ], [ Installer::class, 'deactivate' ] ] as [ $class, $method ] ) {
			$params = ( new \ReflectionMethod( $class, $method ) )->getParameters();
			$this->assertSame( 'bool', (string) $params[0]->getType(), "{$class}::{$method} receives network_wide as a bool from core" );
			$this->assertTrue( $params[0]->isDefaultValueAvailable() );
		}
	}
}
