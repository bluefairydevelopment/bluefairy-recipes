<?php
/**
 * The CHANGELOG's release date, set on the tag day and never guessed.
 *
 * Between releases the newest heading reads `## X.Y.Z — RELEASE-DATE`.
 * On the day the release is tagged, before the zip is built:
 *
 *   php bin/release-date.php --set            (today, local date)
 *   php bin/release-date.php --set=2026-11-03 (an explicit date)
 *   php bin/release-date.php --check          (exit 1 while the placeholder remains)
 *
 * --file=PATH works on another copy (the tests use it). Exit 0 on success,
 * 1 when --check finds the placeholder or --set finds nothing to set,
 * 2 on a usage error.
 *
 * @package BlueFairy
 */

declare(strict_types=1);

// phpcs:ignoreFile -- developer tooling, not shipped.

const PLACEHOLDER = 'RELEASE-DATE';

$opts = getopt( '', [ 'set::', 'check', 'file:' ] );
$file = is_string( $opts['file'] ?? null ) ? $opts['file'] : dirname( __DIR__ ) . '/CHANGELOG.md';
$text = is_readable( $file ) ? (string) file_get_contents( $file ) : null;
if ( null === $text || ( ! array_key_exists( 'set', $opts ) && ! array_key_exists( 'check', $opts ) ) ) {
	fwrite( STDERR, "usage: php bin/release-date.php --set[=YYYY-MM-DD] | --check [--file=PATH]\n" );
	exit( 2 );
}
$pattern = '/^(## \d+\.\d+\.\d+ — )' . PLACEHOLDER . '$/m';
if ( array_key_exists( 'check', $opts ) ) {
	if ( 1 === preg_match( $pattern, $text ) ) {
		fwrite( STDERR, "CHANGELOG.md still reads " . PLACEHOLDER . ": run --set on the tag day.\n" );
		exit( 1 );
	}
	echo "CHANGELOG.md is dated.\n";
	exit( 0 );
}
$date = is_string( $opts['set'] ) && '' !== $opts['set'] ? $opts['set'] : date( 'Y-m-d' );
$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
	fwrite( STDERR, "not a date: $date\n" );
	exit( 2 );
}
$count = 0;
$out   = (string) preg_replace( $pattern, '${1}' . $date, $text, 1, $count );
if ( 1 !== $count ) {
	fwrite( STDERR, "no '## X.Y.Z — " . PLACEHOLDER . "' heading to date.\n" );
	exit( 1 );
}
file_put_contents( $file, $out );
echo "CHANGELOG.md dated $date.\n";
exit( 0 );
