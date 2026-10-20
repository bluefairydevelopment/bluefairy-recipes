<?php
/**
 * Stubs for milestone A5: blocks, shortcodes, script modules, the
 * Interactivity API and the cache incrementor.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( string $tag, callable $callback ): void {
		$GLOBALS['bfrc_test_shortcodes'][ $tag ] = $callback;
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	/** As core: defaults win the key set; the filter `shortcode_atts_{tag}` applies. */
	function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
		$out = [];
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return '' !== $shortcode ? (array) apply_filters( "shortcode_atts_{$shortcode}", $out, $pairs, $atts, $shortcode ) : $out;
	}
}
if ( ! function_exists( 'do_shortcode' ) ) {
	/** Only `[tag attr="v"]` forms, enough to run a registered handler. */
	function do_shortcode( string $content ): string {
		return (string) preg_replace_callback(
			'/\[([a-z_]+)((?:\s+[a-z_]+="[^"]*")*)\s*\]/',
			static function ( array $m ): string {
				$cb = $GLOBALS['bfrc_test_shortcodes'][ $m[1] ] ?? null;
				if ( ! is_callable( $cb ) ) {
					return $m[0];
				}
				$atts = [];
				if ( preg_match_all( '/([a-z_]+)="([^"]*)"/', $m[2], $am, PREG_SET_ORDER ) ) {
					foreach ( $am as $a ) {
						$atts[ $a[1] ] = $a[2];
					}
				}
				return (string) call_user_func( $cb, $atts, '', $m[1] );
			},
			$content
		);
	}
}
if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	function get_block_wrapper_attributes( array $extra_attributes = [] ): string {
		$class = trim( 'wp-block-bfrc-test ' . (string) ( $extra_attributes['class'] ?? '' ) );
		return 'class="' . esc_attr( $class ) . '"';
	}
}
if ( ! function_exists( 'wp_register_script_module' ) ) {
	function wp_register_script_module( string $id, string $src, array $deps = [], mixed $version = false, array $args = [] ): void {
		$GLOBALS['bfrc_test_script_modules'][ $id ] = [ 'src' => $src, 'deps' => $deps, 'version' => $version, 'args' => $args ];
	}
}
if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
	function wp_enqueue_script_module( string $id, string $src = '', array $deps = [], mixed $version = false, array $args = [] ): void {
		$GLOBALS['bfrc_test_enqueued_modules'][] = $id;
	}
}
if ( ! function_exists( 'wp_interactivity_state' ) ) {
	/** As core: merges into the namespace's state and returns it. */
	function wp_interactivity_state( ?string $store_namespace = null, array $state = [] ): array {
		if ( null === $store_namespace ) {
			return [];
		}
		$GLOBALS['bfrc_test_interactivity_state'][ $store_namespace ] = array_replace( $GLOBALS['bfrc_test_interactivity_state'][ $store_namespace ] ?? [], $state );
		return $GLOBALS['bfrc_test_interactivity_state'][ $store_namespace ];
	}
}
if ( ! function_exists( 'wp_interactivity_config' ) ) {
	function wp_interactivity_config( string $store_namespace, array $config = [] ): array {
		$GLOBALS['bfrc_test_interactivity_config'][ $store_namespace ] = array_replace( $GLOBALS['bfrc_test_interactivity_config'][ $store_namespace ] ?? [], $config );
		return $GLOBALS['bfrc_test_interactivity_config'][ $store_namespace ];
	}
}
if ( ! function_exists( 'wp_interactivity_process_directives' ) ) {
	/** The server-side pass is a no-op here: a test compares raw markup. */
	function wp_interactivity_process_directives( string $html ): string {
		$GLOBALS['bfrc_test_directives_processed'] = ( $GLOBALS['bfrc_test_directives_processed'] ?? 0 ) + 1;
		return $html;
	}
}
if ( ! function_exists( 'wp_cache_get_last_changed' ) ) {
	function wp_cache_get_last_changed( string $group ): string {
		$value = wp_cache_get( 'last_changed', $group );
		if ( ! is_string( $value ) || '' === $value ) {
			$value = '1.0000 ' . (string) ( $GLOBALS['bfrc_test_last_changed_seq'] = ( $GLOBALS['bfrc_test_last_changed_seq'] ?? 0 ) + 1 );
			wp_cache_set( 'last_changed', $value, $group );
		}
		return $value;
	}
}
if ( ! function_exists( 'wp_cache_set_last_changed' ) ) {
	function wp_cache_set_last_changed( string $group ): string {
		$value = '1.0000 ' . (string) ( $GLOBALS['bfrc_test_last_changed_seq'] = ( $GLOBALS['bfrc_test_last_changed_seq'] ?? 0 ) + 1 );
		wp_cache_set( 'last_changed', $value, $group );
		return $value;
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( mixed $post_types = '' ): bool {
		$q = $GLOBALS['wp_query'] ?? null;
		return $q instanceof WP_Query && $q->is_singular( $post_types );
	}
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int {
		$q = $GLOBALS['wp_query'] ?? null;
		return $q instanceof WP_Query ? (int) $q->queried_object_id : 0;
	}
}
if ( ! function_exists( 'bfrc_test_query_resolver' ) ) {
	/**
	 * Script what `new WP_Query( $args )` returns: the callable gets the
	 * args and returns [ posts, found_posts, max_num_pages ].
	 */
	function bfrc_test_query_resolver( ?callable $resolver ): void {
		$GLOBALS['bfrc_test_query_resolver'] = $resolver;
	}
}
