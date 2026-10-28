<?php
/**
 * Runs one PHP snippet in a process of its own, for the code that ends the
 * request (Terminate): exit cannot run inside the test runner. (pcov cannot
 * record it either: collecting after exit crashes pcov 1.0 on PHP 8.3, so the
 * test that runs the probe records what it observed; see TerminateTest.)
 *
 *   php tests/exit-probe.php <unused> <base64 snippet>
 *
 * Prints NOT-EXITED if the snippet returns.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile -- test tooling.

$bfrc_probe_code = (string) base64_decode( (string) ( $argv[2] ?? '' ), true );
require __DIR__ . '/bootstrap.php';
$GLOBALS['bfrc_test_redirect_returns'] = true;
eval( $bfrc_probe_code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
echo "\nNOT-EXITED\n";
