#!/usr/bin/env php
<?php
/**
 * Every public and protected method in includes/ must be named in a unit test.
 *
 * "Named" means the token stream of a file under tests/Unit/ (comments and
 * docblocks removed) contains `->method(`, `::method(` or the string literal
 * `'method'`. Private methods are reached through the public ones and are
 * reported for information only. Exit 1 when a method is missing or when the
 * scan saw nothing (an empty or unparsable tree is a failure, not a pass).
 *
 *   php bin/method-coverage.php              # report
 *   php bin/method-coverage.php --self-check # the matcher must catch a planted method and must not count a comment
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile -- developer tooling, not shipped.

$root = dirname( __DIR__ );
foreach ( $argv as $i => $arg ) {
	if ( '--root' === $arg && isset( $argv[ $i + 1 ] ) ) {
		$root = $argv[ $i + 1 ]; // the self-check points the scan at an empty tree to prove it exits 1
	}
}

/**
 * @return array<string, array{visibility: string, file: string}> "Class::method" => details.
 */
function bfrc_cov_methods( string $dir ): array {
	$out = [];
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
			continue;
		}
		$src = (string) file_get_contents( $file->getPathname() );
		// class, final class, abstract class, readonly class, trait, interface, enum.
		if ( 1 !== preg_match( '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', $src, $cm ) ) {
			continue;
		}
		// Modifiers in any order: `final public static function`, `static protected function`, …
		if ( preg_match_all( '/^\s*((?:(?:final|abstract|static|public|protected|private)\s+)+)function\s+(\w+)\s*\(/m', $src, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $m ) {
				$visibility = 'public';
				foreach ( [ 'private', 'protected', 'public' ] as $v ) {
					if ( str_contains( $m[1], $v ) ) {
						$visibility = $v;
					}
				}
				$out[ $cm[1] . '::' . $m[2] ] = [ 'visibility' => $visibility, 'file' => substr( $file->getPathname(), strlen( dirname( $dir ) ) + 1 ) ];
			}
		}
	}
	ksort( $out );
	return $out;
}

/** The code of every test file, comments and docblocks removed. */
function bfrc_cov_test_text( string $dir ): string {
	$text = '';
	if ( ! is_dir( $dir ) ) {
		return $text;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
			continue;
		}
		foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
					continue;
				}
				$text .= $token[1];
			} else {
				$text .= $token;
			}
		}
		$text .= "\n";
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
$tests   = bfrc_cov_test_text( $root . '/tests/Unit' );

if ( in_array( '--self-check', $argv, true ) ) {
	$ok = true;
	// 1. A planted unnamed method is caught.
	$planted = $methods + [ 'Planted::never_named_anywhere' => [ 'visibility' => 'public', 'file' => 'includes/Planted.php' ] ];
	$ok      = $ok && in_array( 'Planted::never_named_anywhere (includes/Planted.php)', bfrc_cov_missing( $planted, $tests ), true );
	// 2. A mention inside a comment does not count: strip a scratch file's comment and the name must be absent.
	$scratch = sys_get_temp_dir() . '/bfrc-cov-' . getmypid();
	mkdir( $scratch );
	file_put_contents( $scratch . '/CommentOnlyTest.php', "<?php\n// \$x->only_in_a_comment(1);\n/** ::only_in_a_doc( */\n\$y = 1;\n" );
	$stripped = bfrc_cov_test_text( $scratch );
	$ok       = $ok && ! str_contains( $stripped, 'only_in_a_comment' ) && ! str_contains( $stripped, 'only_in_a_doc' );
	// 3. The scanner sees modifiers in any order and non-plain class kinds.
	file_put_contents( $scratch . '/Kinds.php', "<?php\nfinal readonly class Kinds {\n\tfinal public static function a(): void {}\n\tstatic protected function b(): void {}\n\tprivate function c(): void {}\n}\n" );
	$kinds = bfrc_cov_methods( $scratch );
	$ok    = $ok && isset( $kinds['Kinds::a'], $kinds['Kinds::b'], $kinds['Kinds::c'] ) && 'protected' === $kinds['Kinds::b']['visibility'];
	// 4. An empty tree is a failure, not a pass: the script itself, pointed at an empty root, must exit 1.
	$empty = $scratch . '/empty';
	mkdir( $empty );
	mkdir( $empty . '/includes' );
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --root ' . escapeshellarg( $empty ) . ' 2>&1', $empty_out, $empty_status );
	$ok = $ok && 1 === $empty_status;
	rmdir( $empty . '/includes' );
	array_map( 'unlink', glob( $scratch . '/*.php' ) ?: [] );
	rmdir( $empty );
	rmdir( $scratch );
	echo $ok ? "self-check: planted method caught, comments ignored, modifiers and class kinds scanned\n" : "self-check: FAILED\n";
	exit( $ok ? 0 : 1 );
}

$classes = count( array_unique( array_map( static fn( string $k ): string => explode( '::', $k )[0], array_keys( $methods ) ) ) );
if ( 0 === $classes || '' === trim( $tests ) ) {
	echo "method coverage: nothing scanned (0 classes or no tests under tests/Unit) — that is a failure, not a pass\n";
	exit( 1 );
}
$missing = bfrc_cov_missing( $methods, $tests );
$public  = count( array_filter( $methods, static fn( array $i ): bool => 'private' !== $i['visibility'] ) );
printf( "%d classes/traits scanned, %d public/protected methods, %d private (not required)\n", $classes, $public, count( $methods ) - $public );
if ( [] === $missing ) {
	printf( "method coverage: %d/%d public/protected methods named in tests/Unit\n", $public, $public );
	exit( 0 );
}
printf( "method coverage: %d/%d named; MISSING:\n", $public - count( $missing ), $public );
foreach ( $missing as $m ) {
	echo "  - {$m}\n";
}
exit( 1 );
