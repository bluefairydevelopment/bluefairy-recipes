<?php
/**
 * The static API table in docs/ARCHITECTURE.md is real and complete.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Tests\TestCase;

/**
 * Every row of "Static API an add-on may use" exists, has the kind it says,
 * and carries `@api`; every `@api` symbol in includes/ has a row. The Pro
 * add-on's BaseApiTest checks the other direction: Pro uses nothing else.
 */
final class ApiTest extends TestCase {

	/**
	 * The table's rows: symbol => kind.
	 *
	 * @return array<string, string>
	 */
	public static function rows(): array {
		$doc = (string) file_get_contents( BFRC_DIR . 'docs/ARCHITECTURE.md' );
		$at  = strpos( $doc, '## Static API an add-on may use' );
		self::assertNotFalse( $at, 'the section exists' );
		$section = substr( $doc, (int) $at, (int) strpos( $doc, "\n## ", (int) $at + 5 ) - (int) $at );
		preg_match_all( '/^\| `([^`]+)` \| ([a-z ]+) \| 1\.0\.0 \|$/m', $section, $m, PREG_SET_ORDER );
		$rows = [];
		foreach ( $m as $row ) {
			$rows[ $row[1] ] = $row[2];
		}
		return $rows;
	}

	private static function has_api( string|false $doc ): bool {
		return is_string( $doc ) && str_contains( $doc, '@api' );
	}

	public function test_every_row_exists_with_its_kind_and_carries_api(): void {
		$rows = self::rows();
		$this->assertGreaterThan( 30, count( $rows ), 'the table was read' );
		$entry = (string) file_get_contents( BFRC_DIR . 'bluefairy-recipes.php' );
		foreach ( $rows as $symbol => $kind ) {
			if ( 'constant' === $kind && ! str_contains( $symbol, '::' ) ) {
				$this->assertTrue( defined( $symbol ), $symbol );
				$this->assertSame( 1, preg_match( '/@api\s+\*\s+@since[^\n]*\n\s*\*\/\s*\ndefine\( \'' . preg_quote( $symbol, '/' ) . '\'/', $entry ), $symbol . ' carries @api' );
				continue;
			}
			if ( 'function' === $kind ) {
				// Defined by the entry file, which the suite does not load: read it there.
				$name = rtrim( $symbol, '()' );
				$this->assertSame( 1, preg_match( '/@api\s+\*\s+@since[^\n]*\n\s*\*\/\s*\n\s*function ' . preg_quote( $name, '/' ) . '\(/', $entry ), $symbol . ' is defined with @api' );
				continue;
			}
			if ( 'class' === $kind ) {
				$this->assertTrue( class_exists( $symbol ), $symbol );
				$this->assertTrue( self::has_api( ( new \ReflectionClass( $symbol ) )->getDocComment() ), $symbol . ' carries @api' );
				continue;
			}
			$static = str_contains( $symbol, '::' );
			[ $class, $member ] = explode( $static ? '::' : '->', $symbol );
			$this->assertTrue( class_exists( $class ), $symbol );
			if ( 'constant' === $kind ) {
				$const = new \ReflectionClassConstant( $class, $member );
				$this->assertTrue( $const->isPublic(), $symbol );
				$this->assertTrue( self::has_api( $const->getDocComment() ), $symbol . ' carries @api' );
				continue;
			}
			$method = new \ReflectionMethod( $class, rtrim( $member, '()' ) );
			$this->assertTrue( $method->isPublic(), $symbol );
			$this->assertSame( 'static method' === $kind, $method->isStatic(), $symbol . ' is ' . $kind );
			$this->assertSame( $static, $method->isStatic(), $symbol . ': :: for static, -> for instance' );
			$this->assertTrue( self::has_api( $method->getDocComment() ), $symbol . ' carries @api' );
		}
	}

	public function test_every_api_tagged_symbol_has_a_row(): void {
		$rows  = self::rows();
		$found = [];
		$iter  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BFRC_DIR . 'includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$src = (string) file_get_contents( (string) $file );
			if ( ! str_contains( $src, '@api' ) || 1 !== preg_match( '/^namespace ([^;]+);/m', $src, $ns ) ) {
				continue;
			}
			preg_match( '/^(?:final |abstract )?class (\w+)/m', $src, $cls );
			$class = $ns[1] . '\\' . $cls[1];
			$ref   = new \ReflectionClass( $class );
			if ( self::has_api( $ref->getDocComment() ) ) {
				$found[] = $class;
			}
			foreach ( $ref->getReflectionConstants() as $c ) {
				if ( $c->getDeclaringClass()->getName() === $class && self::has_api( $c->getDocComment() ) ) {
					$found[] = $class . '::' . $c->getName();
				}
			}
			foreach ( $ref->getMethods() as $m ) {
				if ( $m->getDeclaringClass()->getName() === $class && self::has_api( $m->getDocComment() ) ) {
					$found[] = $class . ( $m->isStatic() ? '::' : '->' ) . $m->getName() . '()';
				}
			}
		}
		$this->assertNotEmpty( $found );
		$this->assertSame( [], array_values( array_diff( $found, array_keys( $rows ) ) ), 'an @api symbol with no row in the table' );
	}

	public function test_the_api_version_is_an_integer(): void {
		$this->assertIsInt( BFRC_API_VERSION );
		$this->assertSame( 1, BFRC_API_VERSION );
	}
}
