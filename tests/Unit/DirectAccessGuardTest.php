<?php
/**
 * Plugin Check: every PHP file the plugin ships must refuse direct access.
 * Class files guard on `ABSPATH`; uninstall.php guards on
 * `WP_UNINSTALL_PLUGIN`; the entry file guards on `ABSPATH`.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Tests\TestCase;

/**
 * A scan test. The scanner is first shown planted specimens (no guard, a
 * guard too late, a guard only inside a comment) so a green sweep is a green
 * from an instrument seen to go red.
 */
final class DirectAccessGuardTest extends TestCase {

	private const MAX_LINE = 40;

	/**
	 * Every PHP file under the shipped class directories, recursively.
	 * `admin/` does not exist yet; when it appears it is swept too.
	 *
	 * @return list<string>
	 */
	private static function files(): array {
		$files = [];
		foreach ( [ 'includes', 'admin' ] as $dir ) {
			if ( ! is_dir( BFRC_DIR . $dir ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BFRC_DIR . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * The 1-based line of the first code line carrying `defined( '$constant' )`
	 * within the first MAX_LINE lines of $source, or null. A mention inside a
	 * comment line does not count.
	 */
	private static function guard_line( string $source, string $constant = 'ABSPATH' ): ?int {
		$lines = array_slice( explode( "\n", $source ), 0, self::MAX_LINE );
		foreach ( $lines as $i => $line ) {
			$trimmed = ltrim( $line );
			if ( str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}
			if ( str_contains( $line, "defined( '{$constant}' )" ) ) {
				return $i + 1;
			}
		}
		return null;
	}

	public function test_the_scanner_flags_a_file_without_a_guard_one_whose_guard_is_too_late_and_one_whose_guard_is_a_comment(): void {
		$this->assertNull( self::guard_line( "<?php\ndeclare(strict_types=1);\nnamespace X;\nfinal class Y {}\n" ), 'no guard at all' );
		$this->assertNull( self::guard_line( "<?php\n" . str_repeat( "// filler\n", self::MAX_LINE ) . "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n" ), 'a guard after line 40 does not count' );
		$this->assertNull( self::guard_line( "<?php\nif ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;\n}\n" ), 'another constant is not this guard' );
		$this->assertNull( self::guard_line( "<?php\n/**\n * if ( ! defined( 'ABSPATH' ) ) { exit; }\n */\n// defined( 'ABSPATH' )\nfinal class Y {}\n" ), 'a guard mentioned only in comments does not count' );
		$this->assertSame( 4, self::guard_line( "<?php\ndeclare(strict_types=1);\nnamespace X;\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n" ) );
		$this->assertSame( 3, self::guard_line( "<?php\n\ndefined( 'ABSPATH' ) || exit;\n" ), 'the short form counts too' );
		$this->assertSame( 2, self::guard_line( "<?php\nif ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;\n}\n", 'WP_UNINSTALL_PLUGIN' ) );
	}

	public function test_every_shipped_class_file_guards_against_direct_access(): void {
		$files = self::files();
		$this->assertGreaterThanOrEqual( 5, count( $files ), 'the sweep must see the whole tree (5 classes at the time of writing)' );

		$missing = [];
		foreach ( $files as $file ) {
			if ( null === self::guard_line( (string) file_get_contents( $file ) ) ) {
				$missing[] = substr( $file, strlen( BFRC_DIR ) );
			}
		}
		$this->assertSame( [], $missing, sprintf( 'files without a defined( \'ABSPATH\' ) guard in their first %d lines', self::MAX_LINE ) );
	}

	public function test_the_entry_file_and_uninstall_guard_too(): void {
		$this->assertNotNull( self::guard_line( (string) file_get_contents( BFRC_FILE ) ), 'bluefairy-recipes.php' );
		$this->assertNotNull( self::guard_line( (string) file_get_contents( BFRC_DIR . 'uninstall.php' ), 'WP_UNINSTALL_PLUGIN' ), 'uninstall.php' );
	}
}
