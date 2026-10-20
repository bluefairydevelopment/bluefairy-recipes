#!/usr/bin/env php
<?php
/**
 * Fetch a recipe page and validate its Recipe JSON-LD against the plugin's
 * contract, tests/fixtures/recipe-jsonld.schema.json (the E2E half of the A6
 * acceptance; the unit test is the other half).
 *
 *   php bin/check-jsonld.php https://site/recipes/some-recipe/          # exit 0 when valid
 *   php bin/check-jsonld.php https://site/recipes/some-recipe/ --drop=name   # positive control: must exit 1
 *   php bin/check-jsonld.php --file=page.html                             # an already-fetched page
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile -- developer tooling, not shipped.

require __DIR__ . '/../vendor/autoload.php';

$url  = null;
$file = null;
$drop = [];
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--drop=' ) ) {
		$drop[] = substr( $arg, 7 );
	} elseif ( str_starts_with( $arg, '--file=' ) ) {
		$file = substr( $arg, 7 );
	} else {
		$url = $arg;
	}
}
if ( null === $url && null === $file ) {
	fwrite( STDERR, "usage: check-jsonld.php <url> [--drop=property] | --file=<html>\n" );
	exit( 2 );
}
if ( null !== $url && ! preg_match( '#^https?://#i', $url ) ) {
	fwrite( STDERR, "the url must start with http:// or https://\n" ); // Never a curl option, a file: or a bare host.
	exit( 2 );
}
$html = null !== $file ? file_get_contents( $file ) : (string) shell_exec( 'curl -sk -- ' . escapeshellarg( (string) $url ) );
if ( false === $html || '' === $html ) {
	fwrite( STDERR, "no page\n" );
	exit( 2 );
}
if ( ! preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m ) ) {
	fwrite( STDERR, "no application/ld+json script in the page\n" );
	exit( 1 );
}
$recipe = null;
foreach ( $m[1] as $json ) {
	$data = json_decode( $json );
	if ( is_object( $data ) && 'Recipe' === ( $data->{'@type'} ?? '' ) ) {
		$recipe = $data;
		break;
	}
}
if ( null === $recipe ) {
	fwrite( STDERR, "no @type Recipe object among " . count( $m[1] ) . " JSON-LD script(s)\n" );
	exit( 1 );
}
foreach ( $drop as $property ) {
	unset( $recipe->$property );
}
$schema    = json_decode( (string) file_get_contents( __DIR__ . '/../tests/fixtures/recipe-jsonld.schema.json' ) );
$validator = new JsonSchema\Validator();
$validator->validate( $recipe, $schema, JsonSchema\Constraints\Constraint::CHECK_MODE_NORMAL );
$props = array_keys( get_object_vars( $recipe ) );
if ( $validator->isValid() ) {
	echo 'VALID: ', count( $props ), " properties (", implode( ', ', $props ), ")\n";
	exit( 0 );
}
echo 'INVALID: ', count( $validator->getErrors() ), " error(s)\n";
foreach ( $validator->getErrors() as $error ) {
	echo '  ', ( '' !== $error['property'] ? $error['property'] : '(root)' ), ': ', $error['message'], "\n";
}
exit( 1 );
