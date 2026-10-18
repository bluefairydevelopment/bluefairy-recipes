#!/usr/bin/env php
<?php
/**
 * Every public and protected method in includes/ must be named in a test.
 *
 * A method is "named" when any file under tests/ contains `->method(`,
 * `::method(` or `'method'`. Private methods are reached through the public
 * ones and are reported for information only. Exit 1 when a method is
 * missing, so the gate can fail.
 *
 *   php bin/method-coverage.php              # report
 *   php bin/method-coverage.php --self-check # plant an unnamed method, expect it to be caught
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile -- developer tooling, not shipped.

$root = dirname( __DIR__ );

/**
 * @return array<string, array{visibility: string, file: string}> "Class::method" => details.
 */
function bfrc_cov_methods( string $dir ): array {
	$out = [];
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
			continue;
		}
		$src = (string) file_get_contents( $file->getPathname() );
		if ( 1 !== preg_match( '/^(?:final\s+)?(?:abstract\s+)?(?:class|trait)\s+(\w+)/m', $src, $cm ) ) {
			continue;
		}
		if ( preg_match_all( '/^\s*(public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/m', $src, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $m ) {
				$out[ $cm[1] . '::' . $m[2] ] = [ 'visibility' => $m[1], 'file' => substr( $file->getPathname(), strlen( dirname( $dir ) ) + 1 ) ];
			}
		}
	}
	ksort( $out );
	return $out;
}

function bfrc_cov_test_text( string $dir ): string {
	$text = '';
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
			$text .= (string) file_get_contents( $file->getPathname() ) . "\n";
		}
	}
	return $text;
}

/**
 * @param array<string, array{visibility: string, file: string}> $methods
 * @return list<string> Unnamed public/protected methods.
 */
function bfrc_cov_missing( array $methods, string $tests ): array {
	$missing = [];
	foreach ( $methods as $name => $info ) {
		if ( 'private' === $info['visibility'] ) {
			continue;
		}
		$method = substr( $name, (int) strpos( $name, '::' ) + 2 );
		if ( '__construct' === $method ) {
			continue;
		}
		$named = str_contains( $tests, '->' . $method . '(' ) || str_contains( $tests, '::' . $method . '(' ) || str_contains( $tests, "'" . $method . "'" );
		if ( ! $named ) {
			$missing[] = $name . ' (' . $info['file'] . ')';
		}
	}
	return $missing;
}

$methods = bfrc_cov_methods( $root . '/includes' );
$tests   = bfrc_cov_test_text( $root . '/tests' );

if ( in_array( '--self-check', $argv, true ) ) {
	$planted               = $methods;
	$planted['Planted::never_named_anywhere'] = [ 'visibility' => 'public', 'file' => 'includes/Planted.php' ];
	$caught                = bfrc_cov_missing( $planted, $tests );
	$ok                    = in_array( 'Planted::never_named_anywhere (includes/Planted.php)', $caught, true );
	echo $ok ? "self-check: planted method caught\n" : "self-check: FAILED, planted method not caught\n";
	exit( $ok ? 0 : 1 );
}

$missing = bfrc_cov_missing( $methods, $tests );
$public  = count( array_filter( $methods, static fn( array $i ): bool => 'private' !== $i['visibility'] ) );
printf( "%d classes/traits scanned, %d public/protected methods, %d private (not required)\n", count( array_unique( array_map( static fn( string $k ): string => explode( '::', $k )[0], array_keys( $methods ) ) ) ), $public, count( $methods ) - $public );
if ( [] === $missing ) {
	printf( "method coverage: %d/%d public/protected methods named in tests\n", $public, $public );
	exit( 0 );
}
printf( "method coverage: %d/%d named; MISSING:\n", $public - count( $missing ), $public );
foreach ( $missing as $m ) {
	echo "  - {$m}\n";
}
exit( 1 );
