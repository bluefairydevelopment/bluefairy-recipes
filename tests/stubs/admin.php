<?php
/**
 * Admin-module test helpers: build a verified admin request, catch the exit.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting

/**
 * Populate the superglobals as WordPress would for a form POST (or a nonce
 * link) carrying `bfrc_action=$action` and the matching `bfrc_<action>` nonce.
 *
 * The request carries `page=bfrc-settings` (the plugin's own screen) unless
 * $query overrides it.
 *
 * @param string               $action The action, without the `bfrc_` prefix.
 * @param array<string, mixed> $fields `$_POST['bfrc']`.
 * @param array<string, mixed> $query  Top-level query arguments (`id`, `enabled`, …).
 * @param bool                 $nonce  False to send a wrong nonce.
 */
function bfrc_test_admin_request( string $action, array $fields = [], array $query = [], bool $nonce = true ): void {
	$_GET     = array_merge( [ 'page' => 'bfrc-settings' ], $query );
	$_POST    = array_merge( [ 'bfrc_action' => $action ], [] === $fields ? [] : [ 'bfrc' => $fields ] );
	$_REQUEST = array_merge( $_GET, $_POST, [ '_wpnonce' => $nonce ? wp_create_nonce( 'bfrc_' . $action ) : 'nonce-wrong' ] );
}

/**
 * Populate the superglobals for an admin-ajax call.
 *
 * @param string               $action The AJAX action, without the `bfrc_ajax_` prefix.
 * @param array<string, mixed> $fields POST fields.
 * @param bool                 $nonce  False to send a wrong nonce.
 */
function bfrc_test_ajax_request( string $action, array $fields = [], bool $nonce = true ): void {
	$_POST    = array_merge( $fields, [ 'nonce' => $nonce ? wp_create_nonce( 'bfrc_ajax_' . $action ) : 'nonce-wrong' ] );
	$_GET     = [];
	$_REQUEST = $_POST;
}

/**
 * Run $callback and return the BfrcTestExit it threw (a redirect, wp_die or
 * wp_send_json_*), or null when it returned normally. Output is captured and
 * appended to $GLOBALS['bfrc_test_output'].
 *
 * @param callable $callback What to run.
 */
function bfrc_test_catch_exit( callable $callback ): ?BfrcTestExit {
	ob_start();
	try {
		$callback();
		return null;
	} catch ( BfrcTestExit $e ) {
		return $e;
	} finally {
		$out = (string) ob_get_clean();
		if ( '' !== $out ) {
			$GLOBALS['bfrc_test_output'][] = $out;
		}
	}
}

/**
 * Run $callback with output buffering and return everything it printed.
 *
 * @param callable $callback What to run.
 */
function bfrc_test_render( callable $callback ): string {
	ob_start();
	try {
		$callback();
	} finally {
		$out = (string) ob_get_clean();
	}
	return $out;
}
