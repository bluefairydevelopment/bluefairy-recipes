<?php
/**
 * Runs one PHP snippet in a process of its own, for the code that ends the
 * request (Support\Terminate): exit cannot run inside the test runner. With
 * pcov loaded, the lines the snippet ran under includes/ are written as JSON
 * to the file named by the first argument (the pcov method gate merges them).
 *
 *   php tests/exit-probe.php <out.json|''> <base64 snippet>
 *
 * Prints NOT-EXITED if the snippet returns.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile -- test tooling.

$bfrc_probe_out  = (string) ( $argv[1] ?? '' );
$bfrc_probe_code = (string) base64_decode( (string) ( $argv[2] ?? '' ), true );
require __DIR__ . '/bootstrap.php';
$GLOBALS['bfrc_test_redirect_returns'] = true;
if ( function_exists( '\pcov\start' ) ) {
	\pcov\clear();
	\pcov\start();
}
register_shutdown_function(
	static function () use ( $bfrc_probe_out ): void {
		if ( '' === $bfrc_probe_out || ! function_exists( '\pcov\collect' ) ) {
			return;
		}
		\pcov\stop();
		$ran = [];
		foreach ( \pcov\collect( \pcov\inclusive ) as $file => $lines ) {
			$ran[ $file ] = array_keys( array_filter( $lines, static fn( int $count ): bool => $count > 0 ) );
		}
		file_put_contents( $bfrc_probe_out, json_encode( $ran ) );
	}
);
eval( $bfrc_probe_code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
echo "\nNOT-EXITED\n";
