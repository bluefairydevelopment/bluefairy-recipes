<?php
/**
 * Loads the real entry file in a bare PHP process with the handful of
 * WordPress functions it calls at file scope, then reports what it did.
 * Run by EntryFileTest; prints one JSON object.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile -- test fixture, not shipped.

define( 'ABSPATH', __DIR__ . '/abspath/' );
$bfrc_probe = [ 'hooks' => [], 'actions' => [] ];
function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}
function plugin_dir_url( string $file ): string {
	return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
function register_activation_hook( string $file, mixed $callback ): void {
	$GLOBALS['bfrc_probe']['hooks']['activate'] = [ basename( $file ), $callback ];
}
function register_deactivation_hook( string $file, mixed $callback ): void {
	$GLOBALS['bfrc_probe']['hooks']['deactivate'] = [ basename( $file ), $callback ];
}
function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['bfrc_probe']['actions'][] = [ $hook, $callback, $priority ];
	return true;
}

$bfrc_entry = dirname( __DIR__, 2 ) . '/bluefairy-recipes.php';
require $bfrc_entry;
$bfrc_first  = [ 'version' => BFRC_VERSION, 'db' => BFRC_DB_VERSION, 'dir' => BFRC_DIR, 'fn' => function_exists( 'bfrc_plugin' ) ? bfrc_plugin() : null ];
$bfrc_before = count( $bfrc_probe['actions'] );
// A second load (a duplicate copy) must be a no-op, not a fatal.
require $bfrc_entry;
$bfrc_probe['first']           = $bfrc_first;
$bfrc_probe['second_load_ok']  = count( $bfrc_probe['actions'] ) === $bfrc_before;

// Every crafted name points at tests/fixtures/Planted.php (which exists and
// sets a marker when loaded) or at a file inside includes/ with illegal
// characters. The marker must stay unset: the autoloader refuses the name.
$GLOBALS['bfrc_planted_loaded'] = 0;
foreach ( [ 'BlueFairy\\Recipes\\..\\tests\\fixtures\\Planted', 'BlueFairy\\Recipes\\../tests/fixtures/Planted', 'BlueFairy\\Recipes\\Support\\..\\..\\tests\\fixtures\\Planted', "BlueFairy\\Recipes\\..\\tests\\fixtures\\Planted\n" ] as $bfrc_name ) {
	spl_autoload_call( $bfrc_name );
}
$bfrc_probe['autoload'] = [
	'own_class'      => class_exists( 'BlueFairy\\Recipes\\Settings' ),
	'own_trait'      => trait_exists( 'BlueFairy\\Recipes\\Support\\WritesOptions' ),
	'foreign'        => class_exists( 'Some\\Other\\Thing' ),
	'planted_loaded' => $GLOBALS['bfrc_planted_loaded'],
	'planted_exists' => is_file( __DIR__ . '/Planted.php' ),
];
echo json_encode( $bfrc_probe );
