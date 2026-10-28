<?php
/**
 * Ending a request: the one place the plugin calls exit.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A redirect is sent and the request ends here, so nothing can print after a
 * Location header. Every handler that redirects calls redirect(); nothing
 * else in the plugin calls exit.
 */
final class Terminate {

	/**
	 * Redirect (core's wp_safe_redirect(): a host off the site falls back to
	 * the admin) and end the request.
	 *
	 * @param string $url    Where to.
	 * @param int    $status HTTP status.
	 * @param string $by     The X-Redirect-By value.
	 */
	public static function redirect( string $url, int $status = 302, string $by = 'WordPress' ): never {
		wp_safe_redirect( $url, $status, $by );
		self::now();
	}

	/**
	 * End the request.
	 */
	public static function now(): never {
		exit;
	}
}
