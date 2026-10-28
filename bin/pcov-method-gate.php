#!/usr/bin/env php
<?php
/**
 * The method gate on executed lines: every method under includes/ must have
 * run every one of its statements in the unit suite (pcov), private methods
 * included. A method that ran only in part is not covered.
 *
 *   BFRC_EXIT_COVERAGE_DIR=/tmp/exitcov php -d pcov.enabled=1 -d pcov.directory=. \
 *       vendor/bin/phpunit --coverage-clover /tmp/clover.xml
 *   php bin/pcov-method-gate.php /tmp/clover.xml /tmp/exitcov
 *   php bin/pcov-method-gate.php --self-check
 *
 * The second argument is the folder tests/exit-probe.php wrote to: the lines
 * the request-ending code ran in processes of its own (exit cannot run inside
 * the test runner), merged with the suite's. Exit 0 when every method is
 * covered; 1 when one is not, or when no method was seen at all (an empty or
 * wrong report is a failure, not a pass); 2 on a usage error.
 *
 * @package BlueFairy
 */

declare(strict_types=1);

// phpcs:ignoreFile -- developer tooling, not shipped.

/**
 * Methods and their statements from a Clover report, with the exit-probe hits merged.
 *
 * @return array<string, array{file: string, lines: array<int, bool>}>
 */
function bfrc_gate_methods( string $clover, string $extra ): array {
	$xml = @simplexml_load_file( $clover );
	if ( false === $xml ) {
		return [];
	}
	$ran = [];
	$GLOBALS['bfrc_gate_probe_lines'] = 0;
	foreach ( '' !== $extra && is_dir( $extra ) ? (array) glob( rtrim( $extra, '/' ) . '/*.json' ) : [] as $json ) {
		foreach ( (array) json_decode( (string) file_get_contents( (string) $json ), true ) as $file => $lines ) {
			foreach ( (array) $lines as $line ) {
				$ran[ (string) realpath( (string) $file ) ][ (int) $line ] = true;
				++$GLOBALS['bfrc_gate_probe_lines'];
			}
		}
	}
	$out = [];
	foreach ( $xml->xpath( '//file' ) ?: [] as $file ) {
		$name = (string) $file['name'];
		if ( ! str_contains( $name, '/includes/' ) ) {
			continue;
		}
		$real   = (string) realpath( $name );
		$method = null;
		$rows   = [];
		foreach ( $file->line as $line ) {
			$rows[ (int) $line['num'] ] = $line;
		}
		ksort( $rows );
		foreach ( $rows as $num => $line ) {
			if ( 'method' === (string) $line['type'] ) {
				$method         = $name . '::' . (string) $line['name'] . '@' . $num;
				$out[ $method ] = [ 'file' => $name, 'lines' => [] ];
			} elseif ( null !== $method && 'stmt' === (string) $line['type'] ) {
				$out[ $method ]['lines'][ $num ] = (int) $line['count'] > 0 || isset( $ran[ $real ][ $num ] );
			}
		}
	}
	return array_filter( $out, static fn( array $m ): bool => [] !== $m['lines'] );
}

/** Report; returns the exit code. */
function bfrc_gate_report( string $clover, string $extra ): int {
	$methods = bfrc_gate_methods( $clover, $extra );
	$partial = [];
	foreach ( $methods as $key => $m ) {
		$missed = array_keys( array_filter( $m['lines'], static fn( bool $hit ): bool => ! $hit ) );
		if ( [] !== $missed ) {
			$partial[] = sprintf( '%s (lines %s)', $key, implode( ', ', $missed ) );
		}
	}
	$total = count( $methods );
	printf( "pcov methods fully executed: %d/%d (statements recorded by exit probes, seen to end the process: %d)\n", $total - count( $partial ), $total, (int) ( $GLOBALS['bfrc_gate_probe_lines'] ?? 0 ) );
	foreach ( $partial as $line ) {
		echo "  NOT FULLY RUN: $line\n";
	}
	return 0 === $total || [] !== $partial ? 1 : 0;
}

if ( in_array( '--self-check', $argv, true ) ) {
	$dir = sys_get_temp_dir() . '/bfrc-gate-self-' . getmypid();
	@mkdir( $dir . '/includes', 0777, true );
	$src = $dir . '/includes/X.php';
	file_put_contents( $src, "<?php\n" );
	$clover = static fn( int $count ): string => '<?xml version="1.0"?><coverage><project><file name="' . $src . '"><line num="3" type="method" name="m" count="1"/><line num="4" type="stmt" count="1"/><line num="5" type="stmt" count="' . $count . '"/></file></project></coverage>';
	file_put_contents( $dir . '/partial.xml', $clover( 0 ) );
	file_put_contents( $dir . '/full.xml', $clover( 1 ) );
	file_put_contents( $dir . '/empty.xml', '<?xml version="1.0"?><coverage><project/></coverage>' );
	@mkdir( $dir . '/exit', 0777, true );
	ob_start();
	$partial = bfrc_gate_report( $dir . '/partial.xml', '' );
	$full    = bfrc_gate_report( $dir . '/full.xml', '' );
	$empty   = bfrc_gate_report( $dir . '/empty.xml', '' );
	file_put_contents( $dir . '/exit/p.json', json_encode( [ $src => [ 5 ] ] ) );
	$merged = bfrc_gate_report( $dir . '/partial.xml', $dir . '/exit' );
	ob_end_clean();
	$ok = 1 === $partial && 0 === $full && 1 === $empty && 0 === $merged;
	echo $ok ? "self-check: a partly run method fails, a fully run one passes, an empty report fails, an exit-probe line counts\n" : "self-check FAILED ($partial $full $empty $merged)\n";
	exit( $ok ? 0 : 1 );
}

if ( ! isset( $argv[1] ) || ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "usage: php bin/pcov-method-gate.php <clover.xml> [exit-coverage-dir] | --self-check\n" );
	exit( 2 );
}
exit( bfrc_gate_report( $argv[1], (string) ( $argv[2] ?? '' ) ) );
