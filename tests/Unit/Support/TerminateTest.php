<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Support;

use BlueFairy\Recipes\Support\Terminate;
use BlueFairy\Recipes\Tests\TestCase;

/**
 * The redirect is asserted in-process (the harness's wp_safe_redirect()
 * throws where a site would end the request); the exit itself is run in a
 * process of its own by tests/exit-probe.php, which proves the request ends
 * and, under the pcov gate (BFRC_EXIT_COVERAGE_DIR), records the lines it ran.
 */
final class TerminateTest extends TestCase {

	/**
	 * Run a snippet in tests/exit-probe.php; [exit code, output].
	 *
	 * @return array{0: int, 1: string}
	 */
	public static function probe( string $code, string $name ): array {
		$dir = (string) getenv( 'BFRC_EXIT_COVERAGE_DIR' );
		$out = '' === $dir ? '' : rtrim( $dir, '/' ) . '/' . $name . '.json';
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d pcov.enabled=1 -d ' . escapeshellarg( 'pcov.directory=' . rtrim( BFRC_DIR, '/' ) ) . ' '
			. escapeshellarg( dirname( __DIR__, 2 ) . '/exit-probe.php' ) . ' ' . escapeshellarg( $out ) . ' ' . escapeshellarg( base64_encode( $code ) );
		exec( $cmd . ' 2>&1', $lines, $status );
		return [ (int) $status, implode( "\n", $lines ) ];
	}

	public function test_redirect_sends_the_location_through_core_and_ends_the_request(): void {
		$exit = bfrc_test_catch_exit( static fn() => Terminate::redirect( 'https://example.com/recipes/', 301, 'BlueFairy Recipes' ) );
		$this->assertSame( [ 'redirect', 'https://example.com/recipes/', 301 ], [ $exit->kind, $exit->payload, $exit->status ] );
		[ $status, $output ] = self::probe(
			'register_shutdown_function( static function (): void { echo "REDIRECTS=" . json_encode( $GLOBALS["bfrc_test_redirects"] ?? [] ); } );'
			. ' \BlueFairy\Recipes\Support\Terminate::redirect( "https://example.com/recipes/", 301, "BlueFairy Recipes" );',
			'terminate-redirect'
		);
		$this->assertSame( 0, $status, $output );
		$this->assertStringNotContainsString( 'NOT-EXITED', $output, 'the request ended at the redirect' );
		$this->assertStringContainsString( 'REDIRECTS=[["https:\/\/example.com\/recipes\/",301,"BlueFairy Recipes"]]', $output, 'core was asked to redirect first' );
	}

	public function test_now_ends_the_request(): void {
		$this->assertTrue( is_callable( [ Terminate::class, 'now' ] ), 'run below in its own process: it cannot return here' );
		[ $status, $output ] = self::probe( '\BlueFairy\Recipes\Support\Terminate::now();', 'terminate-now' );
		$this->assertSame( 0, $status, $output );
		$this->assertStringNotContainsString( 'NOT-EXITED', $output );
		[ , $control ] = self::probe( 'echo "ran";', 'terminate-control' );
		$this->assertStringContainsString( 'NOT-EXITED', $control, 'control: a snippet that does not exit is reported, so the probe can tell' );
	}
}
