<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Support;

use BlueFairy\Recipes\Support\WritesOptions;
use BlueFairy\Recipes\Tests\TestCase;

final class WritesOptionsTest extends TestCase {

	/**
	 * A minimal user of the trait that exposes write_option().
	 */
	private function writer(): object {
		return new class() {
			use WritesOptions;

			public function write( string $option, mixed $value, bool $autoload ): bool {
				return $this->write_option( $option, $value, $autoload );
			}
		};
	}

	public function test_a_new_value_is_written_and_reported_true(): void {
		$this->assertTrue( $this->writer()->write( 'bfrc_w', [ 'a' => 1 ], true ) );
		$this->assertSame( [ 'a' => 1 ], get_option( 'bfrc_w' ) );
		$this->assertSame( 'on', $GLOBALS['bfrc_test_option_autoload']['bfrc_w'] );
	}

	public function test_an_unchanged_array_is_reported_true_though_update_option_returns_false(): void {
		update_option( 'bfrc_w', [ 'a' => 1 ] );
		$this->assertFalse( update_option( 'bfrc_w', [ 'a' => 1 ] ), 'core returns false for an unchanged value' );
		$this->assertTrue( $this->writer()->write( 'bfrc_w', [ 'a' => 1 ], true ) );
	}

	public function test_an_unchanged_scalar_is_compared_in_its_stored_string_form(): void {
		update_option( 'bfrc_w', 12 );
		$this->assertTrue( $this->writer()->write( 'bfrc_w', 12, true ), '12 is stored as "12"' );
		$this->assertTrue( $this->writer()->write( 'bfrc_w', '12', true ) );
	}

	public function test_a_refused_write_is_reported_false(): void {
		$GLOBALS['bfrc_test_option_write_fails'][] = 'bfrc_w';
		$this->assertFalse( $this->writer()->write( 'bfrc_w', [ 'a' => 1 ], true ) );
		$this->assertFalse( get_option( 'bfrc_w' ) );
		update_option( 'bfrc_other', 'x' );
		$GLOBALS['bfrc_test_option_write_fails'][] = 'bfrc_other';
		$this->assertFalse( $this->writer()->write( 'bfrc_other', 'y', false ), 'a refused change to an existing option is a failure, not "unchanged"' );
	}
}
