<?php
/**
 * The entry file, executed for real in a bare PHP process (the harness
 * already defines the constants, so it cannot be required in-process).
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Installer;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Tests\TestCase;

final class EntryFileTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function probe(): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 ' . escapeshellarg( BFRC_DIR . 'tests/fixtures/entry-file-probe.php' ) . ' 2>&1';
		exec( $cmd, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- test: a fresh process is the only way to run the entry file.
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertCount( 1, $output, 'one JSON line, no notices' );
		$decoded = json_decode( $output[0], true );
		$this->assertIsArray( $decoded, $output[0] );
		return $decoded;
	}

	public function test_file_scope_registers_the_hooks_and_the_boot_and_defines_the_versions(): void {
		$probe = $this->probe();
		$this->assertSame( [ 'bluefairy-recipes.php', [ Installer::class, 'activate' ] ], $probe['hooks']['activate'] );
		$this->assertSame( [ 'bluefairy-recipes.php', [ Installer::class, 'deactivate' ] ], $probe['hooks']['deactivate'] );
		$this->assertSame( [ [ 'plugins_loaded', [ Plugin::class, 'boot' ], 10 ] ], $probe['actions'], 'nothing else runs at file scope' );
		$this->assertSame( BFRC_VERSION, $probe['first']['version'] );
		$this->assertSame( BFRC_DB_VERSION, $probe['first']['db'] );
		$this->assertSame( BFRC_VERSION, $probe['first']['fn'], 'bfrc_plugin() returns the version' );
		$this->assertSame( BFRC_DIR, $probe['first']['dir'] );
	}

	public function test_a_second_copy_loading_is_a_no_op_not_a_fatal(): void {
		$probe = $this->probe();
		$this->assertTrue( $probe['second_load_ok'] );
		$this->assertCount( 1, $probe['actions'], 'the second load registered nothing' );
	}

	public function test_the_autoloader_resolves_only_well_formed_names_in_its_own_namespace(): void {
		$probe = $this->probe();
		$this->assertTrue( $probe['autoload']['own_class'] );
		$this->assertTrue( $probe['autoload']['own_trait'] );
		$this->assertFalse( $probe['autoload']['foreign'] );
		$this->assertFalse( $probe['autoload']['traversal'], 'a class name carrying .. never becomes a path' );
		$this->assertFalse( $probe['autoload']['bad_chars'] );
	}
}
