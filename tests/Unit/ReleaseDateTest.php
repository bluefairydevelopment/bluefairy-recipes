<?php
/**
 * bin/release-date.php: the CHANGELOG is dated on the tag day, never guessed.
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Tests\TestCase;

final class ReleaseDateTest extends TestCase {

	private string $copy = '';

	protected function setUp(): void {
		parent::setUp();
		$this->copy = (string) tempnam( sys_get_temp_dir(), 'bfrc-changelog' );
		copy( BFRC_DIR . 'CHANGELOG.md', $this->copy );
	}

	protected function tearDown(): void {
		@unlink( $this->copy ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		parent::tearDown();
	}

	/** Run the script; [exit code, output]. */
	private function run_script( string ...$args ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( BFRC_DIR . 'bin/release-date.php' );
		foreach ( $args as $arg ) {
			$cmd .= ' ' . escapeshellarg( $arg );
		}
		exec( $cmd . ' --file=' . escapeshellarg( $this->copy ) . ' 2>&1', $out, $code );
		return [ $code, implode( "\n", $out ) ];
	}

	public function test_check_fails_while_the_placeholder_remains_and_passes_once_dated(): void {
		file_put_contents( $this->copy, "# Changelog\n\n## 9.9.9 — RELEASE-DATE\n\n- x\n" );
		$this->assertSame( 1, $this->run_script( '--check' )[0], 'a zip must not be built with the placeholder' );
		[ $code ] = $this->run_script( '--set=2026-11-03' );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( "## 9.9.9 — 2026-11-03\n", (string) file_get_contents( $this->copy ) );
		$this->assertSame( 0, $this->run_script( '--check' )[0] );
		$this->assertSame( 1, $this->run_script( '--set=2026-11-04' )[0], 'nothing left to date: never re-dated silently' );
	}

	public function test_set_without_a_date_takes_today_and_a_bad_date_is_refused(): void {
		file_put_contents( $this->copy, "## 1.2.3 — RELEASE-DATE\n" );
		$this->assertSame( 2, $this->run_script( '--set=2026-02-30' )[0], 'not a date' );
		$this->assertStringContainsString( 'RELEASE-DATE', (string) file_get_contents( $this->copy ), 'unchanged' );
		$this->assertSame( 0, $this->run_script( '--set' )[0] );
		$this->assertStringContainsString( '## 1.2.3 — ' . date( 'Y-m-d' ), (string) file_get_contents( $this->copy ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$this->assertSame( 2, $this->run_script()[0], 'no mode: usage' );
	}

	public function test_the_repository_changelog_is_dated_or_holds_the_placeholder(): void {
		$this->assertSame( 1, preg_match( '/^## \d+\.\d+\.\d+ — (\d{4}-\d{2}-\d{2}|RELEASE-DATE)$/m', (string) file_get_contents( BFRC_DIR . 'CHANGELOG.md' ) ) );
	}
}
