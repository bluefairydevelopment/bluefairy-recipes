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
		$lines = array_values( array_filter( array_map( 'trim', file( BFRC_DIR . '.distignore' ) ?: [] ), static fn( string $l ): bool => '' !== $l && '#' !== $l[0] ) );
		$this->assertGreaterThan( 10, count( $lines ), 'the ignore list was read' );
		foreach ( [ '/src', '/build', '/package.json', '/package-lock.json', '/webpack.config.js' ] as $shipped ) {
			$this->assertNotContains( $shipped, $lines, "$shipped ships (README: the zip carries the source and the build command)" );
		}
		foreach ( [ '/node_modules', '/vendor', '/tests', '/bin', '/.git' ] as $excluded ) {
			$this->assertContains( $excluded, $lines, "$excluded never ships" );
		}
		$this->assertFileExists( BFRC_DIR . 'webpack.config.js' );
		$this->assertFileExists( BFRC_DIR . 'package.json' );
		$this->assertSame( 'wp-scripts build --experimental-modules', json_decode( (string) file_get_contents( BFRC_DIR . 'package.json' ), true )['scripts']['build'], 'the documented build command' );
	}
}
