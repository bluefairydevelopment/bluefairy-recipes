<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Tests\TestCase;

/**
 * The zip's contents are what the docs say: the built blocks AND their
 * human-readable source with the file that builds it (WordPress.org's rule
 * for built JavaScript); never the dev dependencies.
 */
final class DistignoreTest extends TestCase {

	public function test_the_distributable_ships_the_source_and_the_build_but_not_the_tooling(): void {
		$lines = array_values( array_filter( array_map( static fn( string $l ): string => '/' . trim( trim( $l ), '/' ), file( BFRC_DIR . '.distignore' ) ?: [] ), static fn( string $l ): bool => '/' !== $l && '/#' !== substr( $l, 0, 2 ) ) ); // Normalised: `src/` and `/src` mean the same entry.
		$this->assertGreaterThan( 10, count( $lines ), 'the ignore list was read' );
		foreach ( [ '/src', '/build', '/package.json', '/package-lock.json', '/webpack.config.js' ] as $shipped ) {
			$this->assertNotContains( $shipped, $lines, "$shipped ships (README: the zip carries the source and the build command)" );
		}
		foreach ( [ '/node_modules', '/vendor', '/tests', '/bin', '/.git', '/FEATURES.md', '/README.md' ] as $excluded ) { // FEATURES.md, README.md and docs/ are the repository's (README links into docs/); readme.txt is the shipped one; Plugin Check flags stray markdown in a shipped plugin, and docs/screenshots must never ship.
			$this->assertContains( $excluded, $lines, "$excluded never ships" );
		}
		$this->assertFileExists( BFRC_DIR . 'webpack.config.js' );
		$this->assertFileExists( BFRC_DIR . 'package.json' );
		$this->assertSame( 'wp-scripts build --experimental-modules', json_decode( (string) file_get_contents( BFRC_DIR . 'package.json' ), true )['scripts']['build'], 'the documented build command' );
	}

	public function test_the_build_honours_the_same_list_through_export_ignore(): void {
		// wp-release.sh builds the zip with `git archive`, which reads
		// .gitattributes and never .distignore: the two files carry the same
		// rules, in the same order (`-export-ignore` is .distignore's `!`).
		$distignore = self::distignore_rules();
		$attributes = [];
		foreach ( file( BFRC_DIR . '.gitattributes' ) ?: [] as $line ) {
			if ( preg_match( '#^(\S+)\s+(-?)export-ignore\s*$#', trim( $line ), $m ) ) {
				$attributes[] = ( '-' === $m[2] ? '!' : '' ) . '/' . trim( str_replace( '/**', '/*', $m[1] ), '/' );
			}
		}
		$this->assertGreaterThan( 10, count( $attributes ), 'the export-ignore rules were read' );
		$this->assertSame( $distignore, $attributes, '.gitattributes export-ignore and .distignore name the same paths' );
	}

	public function test_of_docs_only_the_features_guide_pdf_ships(): void {
		$this->assertFileExists( BFRC_DIR . 'docs/BlueFairyRecipesFeaturesGuide.pdf', 'the guide the zip carries' );
		$rules = self::distignore_rules();
		foreach ( [ 'docs/BlueFairyRecipesFeaturesGuide.pdf', 'includes/Plugin.php', 'readme.txt' ] as $shipped ) {
			$this->assertFalse( self::ignored( $rules, $shipped ), "$shipped ships" );
		}
		foreach ( [ 'docs/ARCHITECTURE.md', 'docs/images/x.png', 'docs/screenshots/x.png', 'docs/NEW.md', 'docs/Other.pdf', 'tests/Unit/x.php', 'FEATURES.md' ] as $excluded ) {
			$this->assertTrue( self::ignored( $rules, $excluded ), "$excluded never ships" );
		}
	}

	/**
	 * .distignore's rules in order, each `/path` or `!/path`.
	 *
	 * @return list<string>
	 */
	private static function distignore_rules(): array {
		$out = [];
		foreach ( file( BFRC_DIR . '.distignore' ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$not   = str_starts_with( $line, '!' );
			$out[] = ( $not ? '!' : '' ) . '/' . trim( ltrim( $line, '!' ), '/' );
		}
		return $out;
	}

	/**
	 * Does the last rule that matches $path exclude it (gitignore's rule)?
	 *
	 * @param list<string> $rules From distignore_rules().
	 * @param string       $path  A path in the plugin.
	 */
	private static function ignored( array $rules, string $path ): bool {
		$ignored = false;
		foreach ( $rules as $rule ) {
			$not     = str_starts_with( $rule, '!' );
			$pattern = ltrim( ltrim( $rule, '!' ), '/' );
			$match   = str_ends_with( $pattern, '/*' )
				? str_starts_with( $path, substr( $pattern, 0, -1 ) )
				: ( fnmatch( $pattern, $path, FNM_PATHNAME ) || str_starts_with( $path, $pattern . '/' ) );
			if ( $match ) {
				$ignored = ! $not;
			}
		}
		return $ignored;
	}
}
