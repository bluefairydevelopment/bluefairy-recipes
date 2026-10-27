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
		foreach ( [ '/node_modules', '/vendor', '/tests', '/bin', '/.git', '/FEATURES.md', '/docs', '/README.md' ] as $excluded ) { // FEATURES.md, README.md and docs/ are the repository's (README links into docs/); readme.txt is the shipped one; Plugin Check flags stray markdown in a shipped plugin, and docs/screenshots must never ship.
			$this->assertContains( $excluded, $lines, "$excluded never ships" );
		}
		$this->assertFileExists( BFRC_DIR . 'webpack.config.js' );
		$this->assertFileExists( BFRC_DIR . 'package.json' );
		$this->assertSame( 'wp-scripts build --experimental-modules', json_decode( (string) file_get_contents( BFRC_DIR . 'package.json' ), true )['scripts']['build'], 'the documented build command' );
	}

	public function test_the_build_honours_the_same_list_through_export_ignore(): void {
		// wp-release.sh builds the zip with `git archive`, which reads
		// .gitattributes and never .distignore: without these rules the zip
		// carried docs/ (screenshots included), bin/ and the repository docs.
		$norm        = static fn( string $l ): string => '/' . trim( trim( $l ), '/' );
		$distignore  = array_values( array_filter( array_map( $norm, file( BFRC_DIR . '.distignore' ) ?: [] ), static fn( string $l ): bool => '/' !== $l && '/#' !== substr( $l, 0, 2 ) ) );
		$attributes  = [];
		foreach ( file( BFRC_DIR . '.gitattributes' ) ?: [] as $line ) {
			if ( preg_match( '#^(\S+)\s+export-ignore\s*$#', trim( $line ), $m ) ) {
				$attributes[] = $norm( $m[1] );
			}
		}
		sort( $distignore );
		sort( $attributes );
		$this->assertGreaterThan( 10, count( $attributes ), 'the export-ignore rules were read' );
		$this->assertSame( $distignore, $attributes, '.gitattributes export-ignore and .distignore name the same paths' );
	}
}
