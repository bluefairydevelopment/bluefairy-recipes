<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs, no database, no WordPress loaded.
 *
 * Unit tests run against this file alone. The stubs are deliberately small
 * and recorded: hooks really dispatch, options and transients live in arrays,
 * HTTP calls are queued and captured, and $wpdb is a FakeWpdb that records
 * every query and answers from a scripted queue (tests/FakeWpdb.php).
 *
 * Module-specific stubs go in tests/stubs/<module>.php — every file there is
 * included after this one — so parallel work on different modules never edits
 * the same file.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidFunctionName, WordPress.WP.AlternativeFunctions, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

$bfrc_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $bfrc_autoload ) ) {
	require_once $bfrc_autoload;
}
// Prepended (third argument): Composer resolves __DIR__ through symlinks, so a
// worktree whose vendor/ is a symlink to the main checkout would otherwise test
// the main checkout's includes/ while reporting on the worktree's tests.
spl_autoload_register(
	static function ( string $class ): void {
		$map = [
			'BlueFairy\\Recipes\\Tests\\' => __DIR__ . '/',
			'BlueFairy\\Recipes\\'        => dirname( __DIR__ ) . '/includes/',
		];
		foreach ( $map as $prefix => $dir ) {
			if ( str_starts_with( $class, $prefix ) ) {
				$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
				return;
			}
		}
	},
	true,
	true
);

// ------------------------------------------------------------------
// Constants.
// ------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/abspath/' );
}
// Both versions are read from the plugin file, never mirrored here: a mirror
// drifts (this harness once said 1.0.1 while the plugin said 1.0.0, and no
// test could tell).
$bfrc_plugin_source = (string) file_get_contents( dirname( __DIR__ ) . '/bluefairy-recipes.php' );
if ( ! defined( 'BFRC_VERSION' ) ) {
	if ( 1 !== preg_match( "/define\\( 'BFRC_VERSION', '([^']+)' \\);/", $bfrc_plugin_source, $bfrc_version_match ) ) {
		throw new RuntimeException( 'tests/bootstrap.php: BFRC_VERSION not found in bluefairy-recipes.php' );
	}
	define( 'BFRC_VERSION', $bfrc_version_match[1] );
}
if ( ! defined( 'BFRC_DB_VERSION' ) ) {
	if ( 1 !== preg_match( "/define\\( 'BFRC_DB_VERSION', '([^']+)' \\);/", $bfrc_plugin_source, $bfrc_db_version_match ) ) {
		throw new RuntimeException( 'tests/bootstrap.php: BFRC_DB_VERSION not found in bluefairy-recipes.php' );
	}
	define( 'BFRC_DB_VERSION', $bfrc_db_version_match[1] );
}
if ( ! defined( 'BFRC_FILE' ) ) {
	define( 'BFRC_FILE', dirname( __DIR__ ) . '/bluefairy-recipes.php' );
}
if ( ! defined( 'BFRC_DIR' ) ) {
	define( 'BFRC_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'BFRC_URL' ) ) {
	define( 'BFRC_URL', 'https://example.com/wp-content/plugins/bluefairy-recipes/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'ARRAY_N', 'ARRAY_N' );
}

// ------------------------------------------------------------------
// Test state. bfrc_test_reset() returns everything to a known state and is
// called from every TestCase::setUp() via BlueFairy\Recipes\Tests\TestCase.
// ------------------------------------------------------------------
function bfrc_test_reset(): void {
	$GLOBALS['bfrc_test_options']       = [];
	$GLOBALS['bfrc_test_option_autoload']    = []; // option => on|off|auto, as core stores it.
	$GLOBALS['bfrc_test_option_write_fails'] = []; // Options whose writes the "database" refuses.
	$GLOBALS['bfrc_test_transients']    = [];
	$GLOBALS['bfrc_test_cache']         = [];
	$GLOBALS['bfrc_test_cache_expires'] = [];
	$GLOBALS['bfrc_test_hooks']         = [];
	$GLOBALS['bfrc_test_fired']         = [];
	$GLOBALS['bfrc_test_rest_routes']   = [];
	$GLOBALS['bfrc_test_settings_errors'] = [];
	$GLOBALS['bfrc_test_http_queue']    = [];
	$GLOBALS['bfrc_test_http_calls']    = [];
	$GLOBALS['bfrc_test_mail']          = [];
	$GLOBALS['bfrc_test_cron']          = [];
	$GLOBALS['bfrc_test_single_events'] = [];
	$GLOBALS['bfrc_test_user_id']       = 0;
	$GLOBALS['bfrc_test_users']         = null; // Unscripted: every positive id is a user. The base TestCase turns the registry on.
	$GLOBALS['bfrc_test_caps']          = [];
	$GLOBALS['bfrc_test_ext_cache']     = null; // core: null until an object-cache.php drop-in sets it.
	$GLOBALS['bfrc_test_is_admin']      = false;
	$GLOBALS['bfrc_test_nonce_valid']   = true;
	$GLOBALS['bfrc_test_time']          = null;
	$GLOBALS['bfrc_test_roles']         = bfrc_test_core_roles();
	$GLOBALS['bfrc_test_role_removal_blocked'] = false; // true models a plugin vetoing WP_User::remove_role().
	$GLOBALS['bfrc_test_activation_hooks'] = []; // register_(de)activation_hook() calls.
	$GLOBALS['bfrc_test_cache_flushes']    = 0;
	$GLOBALS['bfrc_test_post_types']       = []; // register_post_type() name => args.
	$GLOBALS['bfrc_test_taxonomies']       = []; // register_taxonomy() name => [object_type, args].
	$GLOBALS['bfrc_test_registered_meta']  = []; // register_post_meta() type => key => args.
	$GLOBALS['bfrc_test_terms']            = []; // taxonomy => term_id => WP_Term.
	$GLOBALS['bfrc_test_next_term_id']     = 100;
	$GLOBALS['bfrc_test_post_meta']        = []; // post id => key => value.
	$GLOBALS['bfrc_test_autosaves']        = []; // post ids wp_is_post_autosave() reports.
	$GLOBALS['bfrc_test_revisions']        = []; // post ids wp_is_post_revision() reports.
	$GLOBALS['bfrc_test_meta_boxes']       = []; // add_meta_box() calls.
	$GLOBALS['bfrc_test_attachments']      = []; // id => url, or id => [ 'mime', 'url' ].
	$GLOBALS['bfrc_test_count_calls']      = [];
	$GLOBALS['bfrc_test_dropdowns']        = [];
	$GLOBALS['bfrc_test_user_queries']     = [];
	if ( class_exists( BlueFairy\Recipes\Content\Meta::class, false ) ) {
		// The REST cache flag and dispatch depth are per PHP request in production; per test here.
		foreach ( [ 'saw_protected' => false, 'dispatch_depth' => 0 ] as $prop => $value ) {
			$ref = new ReflectionProperty( BlueFairy\Recipes\Content\Meta::class, $prop );
			$ref->setValue( null, $value );
		}
	}
	$GLOBALS['bfrc_test_doing_filter']     = []; // doing_filter() answers true for these hook names.
	$GLOBALS['bfrc_test_shortcodes']       = []; // add_shortcode() tag => callback.
	$GLOBALS['bfrc_test_script_modules']   = []; // wp_register_script_module() id => args.
	$GLOBALS['bfrc_test_enqueued_modules'] = []; // wp_enqueue_script_module() ids.
	$GLOBALS['bfrc_test_interactivity_state']  = []; // wp_interactivity_state() namespace => state.
	$GLOBALS['bfrc_test_interactivity_config'] = []; // wp_interactivity_config() namespace => config.
	$GLOBALS['bfrc_test_directives_processed'] = 0;
	$GLOBALS['bfrc_test_queries']          = []; // every new WP_Query( $args ) with args.
	$GLOBALS['bfrc_test_query_resolver']   = null;
	$GLOBALS['bfrc_test_last_changed_seq'] = 0;
	$GLOBALS['bfrc_test_unique_id']        = 0;
	$GLOBALS['bfrc_test_next_user_id']     = 900;
	$GLOBALS['bfrc_test_next_post_id']     = 9000;
	$GLOBALS['bfrc_test_inserted_users']   = [];
	$GLOBALS['bfrc_test_inserted_posts']   = [];
	$GLOBALS['bfrc_test_deleted_users']    = [];
	$GLOBALS['bfrc_test_deleted_users_reassign'] = [];
	$GLOBALS['bfrc_test_network_deleted_users']  = [];
	$GLOBALS['bfrc_test_user_blogs']             = [];
	$GLOBALS['bfrc_test_removed_from_blog']      = [];
	$GLOBALS['bfrc_test_passwords_set']          = [];
	$GLOBALS['bfrc_test_sessions_destroyed']     = [];
	$GLOBALS['bfrc_test_app_passwords_deleted']  = [];
	$GLOBALS['bfrc_test_updated_users']          = [];
	$GLOBALS['bfrc_test_user_caps_cleared']      = [];
	$GLOBALS['bfrc_test_user_update_fails']      = false;
	$GLOBALS['bfrc_test_deleted_attachments'] = [];
	$GLOBALS['bfrc_test_uploads']          = [];
	$GLOBALS['bfrc_test_attachment_meta']  = [];
	$GLOBALS['bfrc_test_attachment_parents'] = [];
	$GLOBALS['bfrc_test_user_insert_fails'] = false;
	$GLOBALS['bfrc_test_post_insert_fails'] = false;
	$GLOBALS['bfrc_test_upload_fails']     = false;
	BlueFairy\Recipes\Front\Blocks::reset();
	$_COOKIE                               = [];
	$_REQUEST                              = [];
	$GLOBALS['bfrc_test_user_query_where'] = [];
	$GLOBALS['bfrc_test_get_terms_calls']  = [];
	$GLOBALS['bfrc_test_password_unlocked'] = [];
	$GLOBALS['bfrc_test_rewrite_rules']    = [];
	$GLOBALS['bfrc_test_blocks']           = [];
	$GLOBALS['bfrc_test_block_types']      = []; // name => WP_Block_Type, for WP_Block_Type_Registry::get_registered().
	$GLOBALS['bfrc_test_block_templates']  = [];
	$GLOBALS['bfrc_test_block_theme']      = false;
	$GLOBALS['bfrc_test_theme_files']      = [];
	$GLOBALS['bfrc_test_status_headers']   = [];
	$GLOBALS['bfrc_test_nocache']          = false;
	$GLOBALS['bfrc_test_cached_users']     = [];
	$GLOBALS['bfrc_test_paginate_calls']   = [];
	$GLOBALS['bfrc_test_archive_slugs']    = [];
	unset( $GLOBALS['wp_query'] );
	$GLOBALS['wp_rewrite'] = class_exists( 'WP_Rewrite' ) ? new WP_Rewrite() : null; // Pretty permalinks, `page`, unless a test says otherwise.
	$GLOBALS['wp']         = class_exists( 'WP' ) ? new WP() : null;
	$GLOBALS['bfrc_test_primed']           = [];
	$GLOBALS['bfrc_test_prime_args']       = [];
	$GLOBALS['bfrc_test_thumbnail_cache_calls'] = [];
	$GLOBALS['bfrc_test_get_posts_calls']  = [];
	unset( $GLOBALS['post'] );
	$GLOBALS['bfrc_test_object_terms']     = []; // post id => taxonomy => list<WP_Term>.
	$GLOBALS['bfrc_test_media_enqueued']   = false;
	$GLOBALS['bfrc_test_group_flushes']    = [];
	$GLOBALS['bfrc_test_runtime_flushes']  = 0;
	$GLOBALS['bfrc_test_sanitize_title_raw'] = false; // true: a plugin's filter keeps raw UTF-8 instead of percent-encoding.
	$GLOBALS['bfrc_test_suspended_invalidation'] = [];
	$GLOBALS['bfrc_test_posts']         = [];
	$GLOBALS['bfrc_test_db_delta']      = [];
	$GLOBALS['bfrc_test_db_delta_errors'] = [];
	$GLOBALS['bfrc_test_admin_pages']   = [];
	$GLOBALS['bfrc_test_enqueued']      = [];
	$GLOBALS['bfrc_test_registered']    = [];
	$GLOBALS['bfrc_test_script_translations'] = [];
	$GLOBALS['bfrc_test_output']        = [];
	$GLOBALS['bfrc_test_screen']        = null;
	$GLOBALS['bfrc_test_user_meta']     = [];
	$GLOBALS['bfrc_test_multisite']     = false;
	$GLOBALS['bfrc_test_sites']         = [];
	$GLOBALS['bfrc_test_blog_id']       = 1;
	$GLOBALS['bfrc_test_blog_stack']    = [];
	$GLOBALS['bfrc_test_site_state']    = [];
	$GLOBALS['bfrc_test_switched']      = [];
	$GLOBALS['bfrc_test_network_active'] = true;
	$GLOBALS['bfrc_test_doing_cron']    = false;
	$GLOBALS['bfrc_test_serving_rest']  = false; // As core: true only while WP_REST_Server serves the HTTP request (TF4).
	$GLOBALS['bfrc_test_cache_supports'] = [ 'flush_group', 'flush_runtime' ]; // Features wp_cache_supports() reports (TF6).
	$GLOBALS['bfrc_test_suspended']     = []; // Every wp_suspend_cache_addition() call, in order.
	$GLOBALS['bfrc_test_role_names']      = []; // add_role() display names.
	$GLOBALS['bfrc_test_get_users_calls'] = []; // Every get_users() call's args.
	$GLOBALS['bfrc_test_rewrite_flushes'] = []; // Every flush_rewrite_rules() call ($hard).
	$GLOBALS['bfrc_test_deleted_posts']   = []; // Every wp_delete_post() call [id, force].
	$GLOBALS['wpdb']                    = new BlueFairy\Recipes\Tests\FakeWpdb();
	$_SERVER['REMOTE_ADDR']             = '203.0.113.10';
	$_SERVER['REQUEST_METHOD']          = 'GET';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_TRUE_CLIENT_IP'], $_SERVER['HTTP_X_REAL_IP'] );
	$_POST    = [];
	$_GET     = [];
	$_REQUEST = [];
	BlueFairy\Recipes\Plugin::reset();
}

// ------------------------------------------------------------------
// Options, transients, object cache.
// ------------------------------------------------------------------
function get_option( string $option, mixed $default_value = false ): mixed {
	if ( ! array_key_exists( $option, $GLOBALS['bfrc_test_options'] ) ) {
		return $default_value;
	}
	$value = $GLOBALS['bfrc_test_options'][ $option ];
	// As core: a scalar comes back from the options table as a string
	// (`4` → '4', true → '1', false → ''); arrays and objects are unserialised.
	return is_scalar( $value ) || null === $value ? (string) $value : $value;
}
/**
 * The autoload value core stores for a given argument (WP 6.6+): `on`, `off`
 * or `auto` (null, the default, lets core decide by size).
 */
function bfrc_test_autoload_value( bool|string|null $autoload ): string {
	if ( true === $autoload || 'yes' === $autoload || 'on' === $autoload ) {
		return 'on';
	}
	if ( false === $autoload || 'no' === $autoload || 'off' === $autoload ) {
		return 'off';
	}
	return 'auto';
}
/**
 * The form a value takes in the options table, for core's "unchanged"
 * test: scalars as strings (MySQL reports zero affected rows when `2`
 * replaces `'2'`), arrays and objects serialised.
 */
function bfrc_test_option_form( mixed $value ): string {
	return is_scalar( $value ) || null === $value ? (string) $value : serialize( $value );
}
// As core (WP 6.6+): a missing option is added (with $autoload); a value
// identical to the stored one is NOT written and returns false, and the
// autoload flag of an existing option changes only together with its value.
// $GLOBALS['bfrc_test_option_write_fails'] lists options whose writes fail
// (the database refused the statement): nothing stored, false returned.
// $GLOBALS['bfrc_test_option_autoload'] records the flag per option.
function update_option( string $option, mixed $value, bool|string|null $autoload = null ): bool {
	if ( ! array_key_exists( $option, $GLOBALS['bfrc_test_options'] ) ) {
		return add_option( $option, $value, '', $autoload );
	}
	if ( bfrc_test_option_form( $value ) === bfrc_test_option_form( $GLOBALS['bfrc_test_options'][ $option ] ) ) {
		return false;
	}
	if ( in_array( $option, $GLOBALS['bfrc_test_option_write_fails'] ?? [], true ) ) {
		return false;
	}
	$GLOBALS['bfrc_test_options'][ $option ] = $value;
	if ( null !== $autoload ) {
		$GLOBALS['bfrc_test_option_autoload'][ $option ] = bfrc_test_autoload_value( $autoload );
	}
	return true;
}
function add_option( string $option, mixed $value = '', string $deprecated = '', bool|string|null $autoload = null ): bool {
	if ( array_key_exists( $option, $GLOBALS['bfrc_test_options'] ) ) {
		return false;
	}
	if ( in_array( $option, $GLOBALS['bfrc_test_option_write_fails'] ?? [], true ) ) {
		return false;
	}
	$GLOBALS['bfrc_test_options'][ $option ]         = $value;
	$GLOBALS['bfrc_test_option_autoload'][ $option ] = bfrc_test_autoload_value( $autoload );
	return true;
}
function delete_option( string $option ): bool {
	$existed = array_key_exists( $option, $GLOBALS['bfrc_test_options'] );
	unset( $GLOBALS['bfrc_test_options'][ $option ], $GLOBALS['bfrc_test_option_autoload'][ $option ] );
	return $existed;
}
/**
 * As core (WP 6.4+): set the autoload flag of existing options whatever
 * their value; returns option => whether the flag changed.
 *
 * @param array<string, bool|string|null> $options Option => autoload.
 * @return array<string, bool>
 */
function wp_set_option_autoload_values( array $options ): array {
	$out = [];
	foreach ( $options as $option => $autoload ) {
		$want = bfrc_test_autoload_value( $autoload );
		if ( ! array_key_exists( $option, $GLOBALS['bfrc_test_options'] ) || ( $GLOBALS['bfrc_test_option_autoload'][ $option ] ?? null ) === $want ) {
			$out[ $option ] = false;
			continue;
		}
		$GLOBALS['bfrc_test_option_autoload'][ $option ] = $want;
		$out[ $option ]                                  = true;
	}
	return $out;
}
function get_transient( string $transient ): mixed {
	$row = $GLOBALS['bfrc_test_transients'][ $transient ] ?? null;
	if ( null === $row ) {
		return false;
	}
	if ( 0 !== $row['expires'] && $row['expires'] < bfrc_test_now() ) {
		unset( $GLOBALS['bfrc_test_transients'][ $transient ] );
		return false;
	}
	return $row['value'];
}
function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['bfrc_test_transients'][ $transient ] = [
		'value'   => $value,
		'expires' => $expiration > 0 ? bfrc_test_now() + $expiration : 0,
	];
	return true;
}
function delete_transient( string $transient ): bool {
	$existed = isset( $GLOBALS['bfrc_test_transients'][ $transient ] );
	unset( $GLOBALS['bfrc_test_transients'][ $transient ] );
	return $existed;
}
// Entries live in $GLOBALS['bfrc_test_cache'][group][key] (the value, so a
// test may read it directly); an expiry, when one was given, sits beside it in
// $GLOBALS['bfrc_test_cache_expires'] and is checked against bfrc_test_now().
function wp_cache_get( int|string $key, string $group = '', bool $force = false, mixed &$found = null ): mixed {
	$found = isset( $GLOBALS['bfrc_test_cache'][ $group ][ $key ] );
	if ( $found ) {
		$expires = $GLOBALS['bfrc_test_cache_expires'][ $group ][ $key ] ?? 0;
		if ( 0 !== $expires && $expires < bfrc_test_now() ) {
			wp_cache_delete( $key, $group );
			$found = false;
		}
	}
	return $found ? $GLOBALS['bfrc_test_cache'][ $group ][ $key ] : false;
}
function wp_cache_set( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
	$GLOBALS['bfrc_test_cache'][ $group ][ $key ]         = $data;
	$GLOBALS['bfrc_test_cache_expires'][ $group ][ $key ] = $expire > 0 ? bfrc_test_now() + $expire : 0;
	return true;
}
function wp_cache_add( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
	wp_cache_get( $key, $group, false, $found );
	if ( $found ) {
		return false;
	}
	return wp_cache_set( $key, $data, $group, $expire );
}
function wp_cache_delete( int|string $key, string $group = '' ): bool {
	$existed = isset( $GLOBALS['bfrc_test_cache'][ $group ][ $key ] );
	unset( $GLOBALS['bfrc_test_cache'][ $group ][ $key ], $GLOBALS['bfrc_test_cache_expires'][ $group ][ $key ] );
	return $existed;
}
function wp_cache_incr( int|string $key, int $offset = 1, string $group = '' ): int|false {
	$current = wp_cache_get( $key, $group, false, $found );
	if ( ! $found ) {
		return false;
	}
	$GLOBALS['bfrc_test_cache'][ $group ][ $key ] = (int) $current + $offset;
	return $GLOBALS['bfrc_test_cache'][ $group ][ $key ];
}
function wp_cache_flush_group( string $group ): bool {
	$GLOBALS['bfrc_test_group_flushes'][] = $group;
	unset( $GLOBALS['bfrc_test_cache'][ $group ], $GLOBALS['bfrc_test_cache_expires'][ $group ] );
	return true;
}
function wp_cache_flush(): bool {
	$GLOBALS['bfrc_test_cache_flushes'] = ( $GLOBALS['bfrc_test_cache_flushes'] ?? 0 ) + 1;
	$GLOBALS['bfrc_test_cache']         = [];
	$GLOBALS['bfrc_test_cache_expires'] = [];
	return true;
}
function wp_cache_supports( string $feature ): bool {
	return in_array( $feature, $GLOBALS['bfrc_test_cache_supports'] ?? [ 'flush_group', 'flush_runtime' ], true );
}
function wp_using_ext_object_cache( ?bool $using = null ): ?bool {
	// Mirrors core: $_wp_using_ext_object_cache is null on an install without a
	// drop-in, and the function returns the value as it stood before this call.
	$current = $GLOBALS['bfrc_test_ext_cache'];
	if ( null !== $using ) {
		$GLOBALS['bfrc_test_ext_cache'] = $using;
	}
	return $current;
}

// ------------------------------------------------------------------
// Hooks — a real, minimal dispatcher so EventBus and the purger can be tested
// by firing the hooks they subscribe to.
// ------------------------------------------------------------------
function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
	$GLOBALS['bfrc_test_hooks'][ $hook_name ][ $priority ][] = [ $callback, $accepted_args ];
	return true;
}
function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}
function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
	if ( empty( $GLOBALS['bfrc_test_hooks'][ $hook_name ][ $priority ] ) ) {
		return false;
	}
	$before = count( $GLOBALS['bfrc_test_hooks'][ $hook_name ][ $priority ] );
	$GLOBALS['bfrc_test_hooks'][ $hook_name ][ $priority ] = array_values(
		array_filter(
			$GLOBALS['bfrc_test_hooks'][ $hook_name ][ $priority ],
			static fn( array $entry ): bool => $entry[0] != $callback // phpcs:ignore Universal.Operators.StrictComparisons -- closures compare by identity, arrays by value, as WP does.
		)
	);
	return count( $GLOBALS['bfrc_test_hooks'][ $hook_name ][ $priority ] ) < $before;
}
function remove_action( string $hook_name, callable $callback, int $priority = 10 ): bool {
	return remove_filter( $hook_name, $callback, $priority );
}
function has_filter( string $hook_name, callable|false $callback = false ): bool|int {
	if ( empty( $GLOBALS['bfrc_test_hooks'][ $hook_name ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return true;
	}
	foreach ( $GLOBALS['bfrc_test_hooks'][ $hook_name ] as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry[0] == $callback ) { // phpcs:ignore Universal.Operators.StrictComparisons
				return $priority;
			}
		}
	}
	return false;
}
function has_action( string $hook_name, callable|false $callback = false ): bool|int {
	return has_filter( $hook_name, $callback );
}
function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	$GLOBALS['bfrc_test_fired'][ $hook_name ] = ( $GLOBALS['bfrc_test_fired'][ $hook_name ] ?? 0 ) + 1;
	if ( empty( $GLOBALS['bfrc_test_hooks'][ $hook_name ] ) ) {
		return $value;
	}
	$levels = $GLOBALS['bfrc_test_hooks'][ $hook_name ];
	ksort( $levels );
	foreach ( $levels as $entries ) {
		foreach ( $entries as [ $callback, $accepted ] ) {
			$params = array_slice( array_merge( [ $value ], $args ), 0, max( 0, $accepted ) );
			$value  = $callback( ...$params );
		}
	}
	return $value;
}
function do_action( string $hook_name, mixed ...$args ): void {
	$GLOBALS['bfrc_test_fired'][ $hook_name ] = ( $GLOBALS['bfrc_test_fired'][ $hook_name ] ?? 0 ) + 1;
	if ( empty( $GLOBALS['bfrc_test_hooks'][ $hook_name ] ) ) {
		return;
	}
	$levels = $GLOBALS['bfrc_test_hooks'][ $hook_name ];
	ksort( $levels );
	foreach ( $levels as $entries ) {
		foreach ( $entries as [ $callback, $accepted ] ) {
			$callback( ...array_slice( $args, 0, max( 0, $accepted ) ) );
		}
	}
}
function __return_zero(): int {
	return 0;
}
function __return_true(): bool {
	return true;
}
function __return_false(): bool {
	return false;
}
function __return_empty_array(): array {
	return [];
}
function did_action( string $hook_name ): int {
	return (int) ( $GLOBALS['bfrc_test_fired'][ $hook_name ] ?? 0 );
}
function doing_action( ?string $hook_name = null ): bool {
	return doing_filter( $hook_name );
}
/** A test sets $GLOBALS['bfrc_test_doing_filter'] to the hook names that are "running". */
function doing_filter( ?string $hook_name = null ): bool {
	$running = $GLOBALS['bfrc_test_doing_filter'] ?? [];
	return null === $hook_name ? [] !== $running : in_array( $hook_name, $running, true );
}

// ------------------------------------------------------------------
// Sanitising and escaping.
// ------------------------------------------------------------------
function sanitize_text_field( mixed $str ): string {
	$str = is_scalar( $str ) ? (string) $str : '';
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str ) ?? '';
	return trim( $str );
}
function sanitize_textarea_field( mixed $str ): string {
	return trim( strip_tags( is_scalar( $str ) ? (string) $str : '' ) );
}
function sanitize_key( mixed $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( is_scalar( $key ) ? (string) $key : '' ) ) ?? '';
}
function sanitize_email( mixed $email ): string {
	return (string) filter_var( is_scalar( $email ) ? (string) $email : '', FILTER_SANITIZE_EMAIL );
}
function is_email( mixed $email ): string|false {
	return is_string( $email ) && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
/** As core's sanitize_title_with_dashes: lower-cased, non-ASCII percent-encoded (utf8_uri_encode), everything else but [a-z0-9-%] becomes a dash. */
function sanitize_title( mixed $title ): string {
	if ( ! is_scalar( $title ) ) {
		throw new TypeError( 'preg_match(): Argument #2 ($subject) must be of type string, ' . gettype( $title ) . ' given' ); // As core: remove_accents() has no scalar guard.
	}
	$title = mb_strtolower( (string) $title );
	if ( ! empty( $GLOBALS['bfrc_test_sanitize_title_raw'] ) ) {
		return trim( preg_replace( '/[^\p{L}\p{N}%_\-]+/u', '-', $title ) ?? '', '-' );
	}
	$encoded = preg_replace_callback( '/[^\x00-\x7F]+/u', static fn( array $m ): string => strtolower( rawurlencode( $m[0] ) ), $title ) ?? '';
	return trim( preg_replace( '/[^a-z0-9%_\-]+/', '-', $encoded ) ?? '', '-' ); // core keeps underscores
}
function esc_url_raw( mixed $url, ?array $protocols = null ): string {
	$url = is_scalar( $url ) ? trim( (string) $url ) : '';
	if ( '' === $url ) {
		return '';
	}
	$allowed = $protocols ?? [ 'http', 'https' ];
	$scheme  = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( '' !== $scheme && ! in_array( $scheme, $allowed, true ) ) {
		return '';
	}
	return $url;
}
function sanitize_url( mixed $url, ?array $protocols = null ): string {
	return esc_url_raw( $url, $protocols );
}
function esc_url( mixed $url, ?array $protocols = null ): string {
	// As core (display context): `&` and `&amp;` become `&#038;`, a quote `&#039;`; other entities stay.
	$clean = esc_url_raw( $url, $protocols );
	$clean = str_replace( '&amp;', '&', $clean );
	return str_replace( [ '&', "'" ], [ '&#038;', '&#039;' ], $clean );
}
function esc_html( mixed $text ): string {
	// As core's _wp_specialchars(): existing entities (`&laquo;`, `&#038;`) are not encoded again.
	return htmlspecialchars( is_scalar( $text ) ? (string) $text : '', ENT_QUOTES, 'UTF-8', false );
}
function esc_attr( mixed $text ): string {
	return esc_html( $text );
}
function esc_js( mixed $text ): string {
	return addslashes( is_scalar( $text ) ? (string) $text : '' );
}
function esc_textarea( mixed $text ): string {
	return esc_html( $text );
}
function wp_kses_post( mixed $data ): string {
	// Closer to core than a pass-through: script/style blocks go, and only post-safe tags stay.
	$data = is_scalar( $data ) ? (string) $data : '';
	$data = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $data ) ?? $data;
	return strip_tags( $data, '<a><abbr><b><blockquote><br><cite><code><del><div><em><h1><h2><h3><h4><h5><h6><i><img><ins><li><ol><p><pre><q><s><span><strong><sub><sup><table><tbody><td><th><thead><tr><u><ul>' );
}
function wp_kses( mixed $data, mixed $allowed ): string {
	// Keeps the allowed elements (attributes unchecked), strips everything else.
	return strip_tags( is_scalar( $data ) ? (string) $data : '', is_array( $allowed ) ? array_map( 'strval', array_keys( $allowed ) ) : [] );
}
function __( string $text, string $domain = 'default' ): string {
	// TF7: core's translate() runs the `gettext` filter; a test may plant one
	// to tell a translated string from the literal (D6: a string that must
	// not be translated before `init`).
	$translated = apply_filters( 'gettext', $text, $text, $domain );
	return is_string( $translated ) ? $translated : $text;
}
function _x( string $text, string $context, string $domain = 'default' ): string {
	return $text;
}
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return 1 === $number ? $single : $plural;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( __( $text, $domain ) );
}
function esc_attr__( string $text, string $domain = 'default' ): string {
	return esc_attr( __( $text, $domain ) );
}
function esc_html_x( string $text, string $context, string $domain = 'default' ): string {
	return esc_html( $text );
}
function esc_attr_x( string $text, string $context, string $domain = 'default' ): string {
	return esc_attr( $text );
}
function _e( string $text, string $domain = 'default' ): void {
	bfrc_test_echo( $text );
}
function esc_html_e( string $text, string $domain = 'default' ): void {
	bfrc_test_echo( esc_html( $text ) );
}
function esc_attr_e( string $text, string $domain = 'default' ): void {
	bfrc_test_echo( esc_attr( $text ) );
}
function bfrc_test_echo( string $text ): void {
	$GLOBALS['bfrc_test_output'][] = $text;
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput
}
function absint( mixed $maybeint ): int {
	return abs( (int) $maybeint );
}
function wp_slash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}
function wp_unslash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
	return json_encode( $data, $options, $depth );
}
function wp_parse_args( mixed $args, array $defaults = [] ): array {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	return array_merge( $defaults, is_array( $args ) ? $args : [] );
}
function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}
function add_query_arg( mixed ...$args ): string {
	$url = is_string( $args[ count( $args ) - 1 ] ) ? array_pop( $args ) : '';
	$add = is_array( $args[0] ?? null ) ? $args[0] : ( isset( $args[1] ) ? [ $args[0] => $args[1] ] : [] );
	$sep = str_contains( $url, '?' ) ? '&' : '?';
	return $url . ( [] === $add ? '' : $sep . bfrc_test_http_build_query( $add ) );
}
/** As core's _http_build_query() with urlencode false: values are NOT encoded (callers encode), nested keys are `k%5Bsub%5D`. */
function bfrc_test_http_build_query( array $data, string $key = '' ): string {
	$out = [];
	foreach ( $data as $k => $v ) {
		$k = '' !== $key ? $key . '%5B' . $k . '%5D' : (string) $k;
		if ( null === $v ) {
			continue;
		}
		if ( false === $v ) {
			$v = '0';
		}
		$out[] = is_array( $v ) ? bfrc_test_http_build_query( $v, $k ) : $k . '=' . ( is_scalar( $v ) ? (string) $v : '' );
	}
	return implode( '&', $out );
}
function remove_query_arg( mixed $key, string $url = '' ): string {
	// As core: drop the named keys from the query string.
	$keys  = array_map( 'strval', (array) $key );
	$parts = explode( '?', $url, 2 );
	if ( ! isset( $parts[1] ) ) {
		return $url;
	}
	parse_str( $parts[1], $query );
	foreach ( $keys as $k ) {
		unset( $query[ $k ] );
	}
	$rebuilt = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	return $parts[0] . ( '' === $rebuilt ? '' : '?' . $rebuilt );
}
function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
}
function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min, 0 === $max ? PHP_INT_MAX : $max );
}
function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}
function wp_hash( string $data, string $scheme = 'auth' ): string {
	return hash_hmac( 'md5', $data, wp_salt( $scheme ) );
}
/**
 * As core: the largest unit (B, KB, MB, GB, TB at 1024) that keeps the
 * number at least 1, formatted with $decimals; false for a non-number.
 */
function size_format( int|string $bytes, int $decimals = 0 ): string|false {
	if ( ! is_numeric( $bytes ) ) {
		return false;
	}
	$bytes = (float) $bytes;
	foreach ( [ 'TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024, 'B' => 1 ] as $unit => $size ) {
		if ( $bytes >= $size ) {
			return number_format( $bytes / $size, $decimals ) . ' ' . $unit;
		}
	}
	return '0 B';
}
function number_format_i18n( float|int $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}
function wp_strip_all_tags( string $text ): string {
	return trim( strip_tags( $text ) );
}
function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

// ------------------------------------------------------------------
// Time. Tests can freeze the clock with bfrc_test_set_time().
// ------------------------------------------------------------------
function bfrc_test_now(): int {
	return $GLOBALS['bfrc_test_time'] ?? time();
}
function bfrc_test_set_time( ?int $timestamp ): void {
	$GLOBALS['bfrc_test_time'] = $timestamp;
}
function current_time( string $type, int|bool $gmt = 0 ): int|string {
	$now = bfrc_test_now();
	if ( 'timestamp' === $type || 'U' === $type ) {
		return $now;
	}
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s', $now );
	}
	return gmdate( $type, $now );
}
function wp_date( string $format, ?int $timestamp = null, mixed $timezone = null ): string|false {
	return gmdate( $format, $timestamp ?? bfrc_test_now() );
}
/**
 * The site timezone: option `timezone_string`, UTC when unset or invalid (TF1).
 */
function bfrc_test_timezone(): DateTimeZone {
	$name = (string) get_option( 'timezone_string', '' );
	try {
		return new DateTimeZone( '' === $name ? 'UTC' : $name );
	} catch ( Exception ) {
		return new DateTimeZone( 'UTC' );
	}
}
/**
 * As core: with no timestamp, "now" in the site timezone (UTC when $gmt);
 * with one, the timestamp is a "WP timestamp" already shifted by the site
 * offset — get_date_from_gmt() + strtotime() produce one — so it is formatted
 * as-is in UTC.
 */
function date_i18n( string $format, int|bool $timestamp = false, bool $gmt = false ): string {
	if ( false === $timestamp ) {
		$now = new DateTimeImmutable( '@' . bfrc_test_now() );
		return $now->setTimezone( $gmt ? new DateTimeZone( 'UTC' ) : bfrc_test_timezone() )->format( $format );
	}
	return gmdate( $format, (int) $timestamp );
}
/**
 * As core's phrasing: seconds under a minute, then minutes, hours and days
 * (rounded, at least 1), singular or plural.
 */
function human_time_diff( int $from, int $to = 0 ): string {
	$diff = abs( ( 0 === $to ? bfrc_test_now() : $to ) - $from );
	foreach ( [ [ 86400, 'day' ], [ 3600, 'hour' ], [ 60, 'min' ] ] as [ $size, $unit ] ) {
		if ( $diff >= $size ) {
			$n = max( 1, (int) round( $diff / $size ) );
			return $n . ' ' . $unit . ( 1 === $n ? '' : 's' );
		}
	}
	return $diff . ' ' . ( 1 === $diff ? 'second' : 'seconds' );
}
/**
 * As core: a GMT `Y-m-d H:i:s` rendered in the site timezone (TF1).
 */
function get_date_from_gmt( string $date, string $format = 'Y-m-d H:i:s' ): string {
	$utc = date_create( $date, new DateTimeZone( 'UTC' ) );
	if ( false === $utc ) {
		return gmdate( $format, 0 );
	}
	return $utc->setTimezone( bfrc_test_timezone() )->format( $format );
}
function mysql2date( string $format, string $date, bool $translate = true ): string|int|false {
	return gmdate( $format, (int) strtotime( $date ) );
}

// ------------------------------------------------------------------
// Users, capabilities, roles.
// ------------------------------------------------------------------
class WP_Site {
	public string $blog_id = '0';
	public function __construct( int $id = 0 ) {
		$this->blog_id = (string) $id;
	}
}
class WP_Role {
	/** @var array<string, bool> */
	public array $capabilities = [];
	public function __construct( public string $name, array $capabilities ) {
		$this->capabilities = $capabilities;
	}
	public function add_cap( string $cap, bool $grant = true ): void {
		$this->capabilities[ $cap ] = $grant;
	}
	public function remove_cap( string $cap ): void {
		unset( $this->capabilities[ $cap ] );
	}
	public function has_cap( string $cap ): bool {
		return ! empty( $this->capabilities[ $cap ] );
	}
}
class WP_User {
	public int $ID       = 0;
	public string $user_login = '';
	public string $user_email = '';
	public string $display_name = '';
	public string $user_nicename = '';
	public string $user_url = '';
	public string $description = '';
	public string $nickname = '';
	public string $first_name = '';
	public string $last_name = '';
	/** @var list<string> */
	public array $roles = [];
	public function __construct( int $id = 0 ) {
		$this->ID = $id;
		$known    = $GLOBALS['bfrc_test_users'][ $id ] ?? null;
		if ( $known instanceof WP_User ) {
			$this->roles        = $known->roles;
			$this->user_login   = $known->user_login;
			$this->user_email   = $known->user_email;
			$this->display_name = $known->display_name;
			$this->user_nicename = $known->user_nicename;
		}
	}
	public function exists(): bool {
		return $this->ID > 0;
	}
	public function has_cap( string $cap ): bool {
		return user_can( $this, $cap ) || ( $this->ID === get_current_user_id() && ! empty( $GLOBALS['bfrc_test_caps'][ $cap ] ) );
	}
	/** As core: replaces every role with $role ('' removes them all). */
	public function set_role( string $role ): void {
		$this->roles = '' === $role ? [] : [ $role ];
		bfrc_test_store_user( $this );
	}
	public function add_role( string $role ): void {
		if ( ! in_array( $role, $this->roles, true ) ) {
			$this->roles[] = $role;
		}
		bfrc_test_store_user( $this );
	}
	/** As core: every role and every individual capability goes. */
	public function remove_all_caps(): void {
		$this->roles = [];
		$GLOBALS['bfrc_test_user_caps_cleared'][] = $this->ID;
		bfrc_test_store_user( $this );
	}
	public function remove_role( string $role ): void {
		if ( ! empty( $GLOBALS['bfrc_test_role_removal_blocked'] ) ) {
			return; // A plugin vetoed the change: the role stays, as core's remove_role() would leave it when update_user_meta is filtered out.
		}
		$this->roles = array_values( array_diff( $this->roles, [ $role ] ) );
		bfrc_test_store_user( $this );
	}
}
/** Keep a user's roles in the registry so get_users() and a later WP_User see them. */
function bfrc_test_store_user( WP_User $user ): void {
	if ( ! is_array( $GLOBALS['bfrc_test_users'] ?? null ) ) {
		$GLOBALS['bfrc_test_users'] = [];
	}
	$GLOBALS['bfrc_test_users'][ $user->ID ] = $user;
}
function get_role( string $role ): ?WP_Role {
	return $GLOBALS['bfrc_test_roles'][ $role ] ?? null;
}
/** The five roles a fresh WordPress site has, each with core's `read`. Per site on multisite, as core. */
function bfrc_test_core_roles(): array {
	$roles = [];
	foreach ( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ] as $name ) {
		$roles[ $name ] = new WP_Role( $name, [ 'read' => true ] );
	}
	return $roles;
}
/**
 * As core: the current user has a capability when one of their roles grants
 * it. bfrc_test_grant() is an explicit override for the current user, so a
 * test can say "the current user can" without building a role.
 */
function current_user_can( string $capability, mixed ...$args ): bool {
	$granted = $GLOBALS['bfrc_test_caps'][ $capability ] ?? false;
	if ( is_callable( $granted ) ) {
		return (bool) $granted( $args ); // Per-object grants, e.g. edit_post on one id only.
	}
	if ( ! empty( $granted ) ) {
		return true;
	}
	return user_can( get_current_user_id(), $capability );
}
/** Resolves the user's roles through the role registry; a deleted role grants nothing, as core. */
function user_can( mixed $user, string $capability, mixed ...$args ): bool {
	$user = $user instanceof WP_User ? $user : new WP_User( (int) $user );
	foreach ( $user->roles as $role ) {
		$object = get_role( $role );
		if ( $object instanceof WP_Role && $object->has_cap( $capability ) ) {
			return true;
		}
	}
	return false;
}
function bfrc_test_grant( string ...$caps ): void {
	foreach ( $caps as $cap ) {
		$GLOBALS['bfrc_test_caps'][ $cap ] = true;
	}
}
function get_current_user_id(): int {
	return (int) $GLOBALS['bfrc_test_user_id'];
}
function is_user_logged_in(): bool {
	return get_current_user_id() > 0;
}
function wp_get_current_user(): WP_User {
	return new WP_User( get_current_user_id() );
}
function wp_set_current_user( ?int $id, string $name = '' ): WP_User {
	$GLOBALS['bfrc_test_user_id'] = (int) $id;
	return new WP_User( (int) $id );
}
// Users (T5 / WPMAP-114): $GLOBALS['bfrc_test_users'] is the registry, id =>
// WP_User. As core, an id that is not in it is no user — so "an unknown user
// is refused" is provable. The base suite runs with the registry on (TestCase
// sets it to [] for its own tests); null — the reset default — is the
// unscripted mode in which every positive id resolves to a user, kept for a
// suite that extends TestCase from another namespace (the Pro add-on's) until
// it registers its users with bfrc_test_add_user().
function bfrc_test_add_user( int $id, array $props = [] ): WP_User {
	$user = new WP_User( $id );
	foreach ( $props as $key => $value ) {
		$user->$key = $value;
	}
	if ( ! is_array( $GLOBALS['bfrc_test_users'] ?? null ) ) {
		$GLOBALS['bfrc_test_users'] = [];
	}
	$GLOBALS['bfrc_test_users'][ $id ] = $user;
	return $user;
}
/** Core's regex, verbatim. */
function wp_make_link_relative( string $link ): string {
	return (string) preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', $link );
}
function get_userdata( int $user_id ): WP_User|false {
	if ( $user_id <= 0 ) {
		return false;
	}
	$registry = $GLOBALS['bfrc_test_users'] ?? null;
	if ( ! is_array( $registry ) ) {
		return new WP_User( $user_id );
	}
	return $registry[ $user_id ] ?? false;
}
function get_user_by( string $field, mixed $value ): WP_User|false {
	if ( 'id' === $field ) {
		return get_userdata( (int) $value );
	}
	if ( 'email' === $field ) {
		foreach ( $GLOBALS['bfrc_test_users'] ?? [] as $user ) {
			if ( $user instanceof WP_User && isset( $user->user_email ) && $user->user_email === (string) $value ) {
				return $user;
			}
		}
		return false;
	}
	if ( 'slug' === $field ) {
		foreach ( $GLOBALS['bfrc_test_users'] ?? [] as $user ) {
			if ( $user instanceof WP_User && $user->user_nicename === (string) $value ) {
				return new WP_User( $user->ID );
			}
		}
	}
	return false;
}
function is_admin(): bool {
	return (bool) $GLOBALS['bfrc_test_is_admin'];
}
// Multisite: $GLOBALS['bfrc_test_multisite'] switches it on and
// $GLOBALS['bfrc_test_sites'] lists the site ids. Options, cron events and
// roles are per-site in core (they are options), so switch_to_blog() parks the
// current site's copies in $GLOBALS['bfrc_test_site_state'] and loads the
// target site's; $wpdb->prefix becomes `bfw_<id>_` as core does. Every switch
// is recorded in $GLOBALS['bfrc_test_switched'].
function is_multisite(): bool {
	return (bool) $GLOBALS['bfrc_test_multisite'];
}
function get_current_blog_id(): int {
	return (int) $GLOBALS['bfrc_test_blog_id'];
}
function bfrc_test_load_site( int $blog_id ): void {
	$state                              = $GLOBALS['bfrc_test_site_state'][ $blog_id ] ?? [];
	$GLOBALS['bfrc_test_blog_id']       = $blog_id;
	$GLOBALS['bfrc_test_options']       = $state['options'] ?? [];
	$GLOBALS['bfrc_test_option_autoload'] = $state['autoload'] ?? [];
	$GLOBALS['bfrc_test_cron']          = $state['cron'] ?? [];
	$GLOBALS['bfrc_test_single_events'] = $state['single_events'] ?? [];
	$GLOBALS['bfrc_test_roles']         = $state['roles'] ?? bfrc_test_core_roles();
	$GLOBALS['wpdb']->prefix            = 1 === $blog_id ? 'bfw_' : 'bfw_' . $blog_id . '_';
	$GLOBALS['wpdb']->options           = $GLOBALS['wpdb']->prefix . 'options';
}
function bfrc_test_park_site(): void {
	$GLOBALS['bfrc_test_site_state'][ get_current_blog_id() ] = [
		'options'       => $GLOBALS['bfrc_test_options'],
		'autoload'      => $GLOBALS['bfrc_test_option_autoload'],
		'cron'          => $GLOBALS['bfrc_test_cron'],
		'single_events' => $GLOBALS['bfrc_test_single_events'],
		'roles'         => $GLOBALS['bfrc_test_roles'],
	];
}
function switch_to_blog( int $new_blog_id ): bool {
	$GLOBALS['bfrc_test_switched'][]  = $new_blog_id;
	$GLOBALS['bfrc_test_blog_stack'][] = get_current_blog_id();
	bfrc_test_park_site();
	bfrc_test_load_site( $new_blog_id );
	return true;
}
function restore_current_blog(): bool {
	if ( [] === $GLOBALS['bfrc_test_blog_stack'] ) {
		return false;
	}
	bfrc_test_park_site();
	bfrc_test_load_site( (int) array_pop( $GLOBALS['bfrc_test_blog_stack'] ) );
	return true;
}
/**
 * The scripted site ids, honouring `number` and `offset` (so a paging loop
 * terminates as it would against core) and `fields => ids`.
 */
function get_sites( array $args = [] ): array {
	$ids    = array_values( array_map( 'intval', (array) $GLOBALS['bfrc_test_sites'] ) );
	$offset = (int) ( $args['offset'] ?? 0 );
	$number = (int) ( $args['number'] ?? 100 );
	$page   = array_slice( $ids, $offset, $number > 0 ? $number : null );
	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return $page;
	}
	return array_map( static fn( int $id ): object => (object) [ 'blog_id' => (string) $id, 'id' => $id ], $page );
}
function is_plugin_active_for_network( string $plugin ): bool {
	return (bool) $GLOBALS['bfrc_test_network_active'];
}
function wp_doing_ajax(): bool {
	return false;
}
function wp_doing_cron(): bool {
	return (bool) $GLOBALS['bfrc_test_doing_cron'];
}
function wp_is_serving_rest_request(): bool {
	return (bool) $GLOBALS['bfrc_test_serving_rest'];
}
function wp_is_json_request(): bool {
	return false;
}
function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
	if ( ! isset( $GLOBALS['bfrc_test_user_meta'][ $user_id ][ $key ] ) ) {
		return $single ? '' : [];
	}
	$value = $GLOBALS['bfrc_test_user_meta'][ $user_id ][ $key ];
	return $single ? $value : [ $value ];
}
function update_user_meta( int $user_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
	$meta_value = wp_unslash( $meta_value ); // As core.
	$GLOBALS['bfrc_test_user_meta'][ $user_id ][ $meta_key ] = $meta_value;
	return true;
}
function delete_user_meta( int $user_id, string $meta_key, mixed $meta_value = '' ): bool {
	$existed = isset( $GLOBALS['bfrc_test_user_meta'][ $user_id ][ $meta_key ] );
	unset( $GLOBALS['bfrc_test_user_meta'][ $user_id ][ $meta_key ] );
	return $existed;
}

// ------------------------------------------------------------------
// Nonces and redirects. Redirects, wp_die and wp_send_json* throw so a
// handler stops exactly where WordPress would have exited.
// ------------------------------------------------------------------
class BfrcTestExit extends RuntimeException {
	public function __construct( public string $kind, public mixed $payload = null, public int $status = 0 ) {
		parent::__construct( $kind );
	}
}
function wp_create_nonce( string|int $action = -1 ): string {
	return 'nonce-' . $action;
}
function wp_verify_nonce( mixed $nonce, string|int $action = -1 ): int|false {
	return $GLOBALS['bfrc_test_nonce_valid'] && 'nonce-' . $action === $nonce ? 1 : false;
}
function check_admin_referer( string|int $action = -1, string $query_arg = '_wpnonce' ): int|false {
	$nonce  = $_REQUEST[ $query_arg ] ?? '';
	$result = wp_verify_nonce( $nonce, $action );
	if ( false === $result ) {
		wp_die( 'The link you followed has expired.', '', [ 'response' => 403 ] );
	}
	return $result;
}
function check_ajax_referer( string|int $action = -1, string|false $query_arg = false, bool $stop = true ): int|false {
	$nonce  = $_REQUEST[ $query_arg ?: '_ajax_nonce' ] ?? ( $_REQUEST['_wpnonce'] ?? '' );
	$result = wp_verify_nonce( $nonce, $action );
	if ( false === $result && $stop ) {
		wp_die( '-1', '', [ 'response' => 403 ] );
	}
	return $result;
}
function wp_nonce_field( string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	if ( $display ) {
		bfrc_test_echo( $field );
	}
	return $field;
}
function wp_nonce_url( string $actionurl, string|int $action = -1, string $name = '_wpnonce' ): string {
	return add_query_arg( $name, wp_create_nonce( $action ), $actionurl );
}
function wp_die( mixed $message = '', mixed $title = '', mixed $args = [] ): never {
	throw new BfrcTestExit( 'die', $message, is_array( $args ) ? (int) ( $args['response'] ?? 500 ) : 500 );
}
function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): never {
	throw new BfrcTestExit( 'redirect', $location, $status );
}
function wp_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): never {
	throw new BfrcTestExit( 'redirect', $location, $status );
}
function wp_send_json_success( mixed $value = null, ?int $status_code = null ): never {
	throw new BfrcTestExit( 'json_success', $value, $status_code ?? 200 );
}
function wp_send_json_error( mixed $value = null, ?int $status_code = null ): never {
	throw new BfrcTestExit( 'json_error', $value, $status_code ?? 200 );
}
function wp_send_json( mixed $response, ?int $status_code = null ): never {
	throw new BfrcTestExit( 'json', $response, $status_code ?? 200 );
}
function admin_url( string $path = '', string $scheme = 'admin' ): string {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}
function home_url( string $path = '', ?string $scheme = null ): string {
	return 'https://example.com' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}
function site_url( string $path = '', ?string $scheme = null ): string {
	return home_url( $path, $scheme );
}
function rest_url( string $path = '', string $scheme = 'rest' ): string {
	return 'https://example.com/wp-json/' . ltrim( $path, '/' );
}
function get_rest_url( ?int $blog_id = null, string $path = '/', string $scheme = 'rest' ): string {
	return rest_url( $path );
}
function plugin_basename( string $file ): string {
	return 'bluefairy-recipes/' . basename( $file );
}
function plugins_url( string $path = '', string $plugin = '' ): string {
	return BFRC_URL . ltrim( $path, '/' );
}
function deactivate_plugins( mixed $plugins, bool $silent = false, ?bool $network_wide = null ): void {}
function is_plugin_active( string $plugin ): bool {
	return false;
}
function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
	return match ( $show ) {
		'name'        => 'Test Site',
		'admin_email' => 'admin@example.com',
		'version'     => '7.1',
		'url'         => 'https://example.com',
		default       => '',
	};
}
function get_locale(): string {
	return 'en_US';
}
function wp_get_environment_type(): string {
	return 'production';
}

// ------------------------------------------------------------------
// HTTP: queued responses, recorded calls.
// ------------------------------------------------------------------
class WP_Error {
	/** @var array<string, list<string>> */
	public array $errors = [];
	/** @var array<string, mixed> */
	public array $error_data = [];
	public function __construct( string|int $code = '', string $message = '', mixed $data = '' ) {
		if ( '' !== $code ) {
			$this->add( $code, $message, $data );
		}
	}
	public function add( string|int $code, string $message, mixed $data = '' ): void {
		$this->errors[ $code ][] = $message;
		if ( '' !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}
	public function get_error_code(): string|int {
		return array_key_first( $this->errors ) ?? '';
	}
	/** @return list<string|int> */
	public function get_error_codes(): array {
		return array_keys( $this->errors );
	}
	public function get_error_message( string|int $code = '' ): string {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->errors[ $code ][0] ?? '';
	}
	public function get_error_data( string|int $code = '' ): mixed {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->error_data[ $code ] ?? null;
	}
	public function has_errors(): bool {
		return [] !== $this->errors;
	}
}
function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}
/**
 * Queue a response for the next HTTP call. Pass a WP_Error to simulate a
 * transport failure.
 *
 * @param array<string, string> $headers Response headers.
 */
function bfrc_test_queue_http( int $code = 200, string $body = '', array $headers = [] ): void {
	$GLOBALS['bfrc_test_http_queue'][] = [
		'response' => [
			'code'    => $code,
			'message' => 'OK',
		],
		'body'     => $body,
		'headers'  => $headers,
	];
}
function bfrc_test_queue_http_error( string $code = 'http_request_failed', string $message = 'Connection refused' ): void {
	$GLOBALS['bfrc_test_http_queue'][] = new WP_Error( $code, $message );
}
function wp_remote_request( string $url, array $args = [] ): array|WP_Error {
	$GLOBALS['bfrc_test_http_calls'][] = [
		'url'  => $url,
		'args' => $args,
	];
	if ( [] === $GLOBALS['bfrc_test_http_queue'] ) {
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => '',
			'headers'  => [],
		];
	}
	return array_shift( $GLOBALS['bfrc_test_http_queue'] );
}
function wp_remote_post( string $url, array $args = [] ): array|WP_Error {
	$args['method'] = 'POST';
	return wp_remote_request( $url, $args );
}
function wp_remote_get( string $url, array $args = [] ): array|WP_Error {
	$args['method'] = 'GET';
	return wp_remote_request( $url, $args );
}
function wp_safe_remote_post( string $url, array $args = [] ): array|WP_Error {
	return wp_remote_post( $url, $args );
}
function wp_safe_remote_get( string $url, array $args = [] ): array|WP_Error {
	return wp_remote_get( $url, $args );
}
function wp_remote_retrieve_response_code( mixed $response ): int|string {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : '';
}
function wp_remote_retrieve_body( mixed $response ): string {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}
function wp_remote_retrieve_headers( mixed $response ): array {
	return is_array( $response ) ? (array) ( $response['headers'] ?? [] ) : [];
}
function wp_remote_retrieve_header( mixed $response, string $header ): string {
	$headers = array_change_key_case( wp_remote_retrieve_headers( $response ), CASE_LOWER );
	return (string) ( $headers[ strtolower( $header ) ] ?? '' );
}
function wp_http_validate_url( string $url ): string|false {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) ) {
		return false;
	}
	// As core: no userinfo, and only the ports `http_allowed_safe_ports` lists.
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}
	if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], [ 80, 443, 8080 ], true ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	if ( 'localhost' === $host || str_ends_with( $host, '.local' ) ) {
		return false;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false;
	}
	return $url;
}

// ------------------------------------------------------------------
// Mail and cron, recorded.
// ------------------------------------------------------------------
function wp_mail( mixed $to, string $subject, string $message, mixed $headers = '', mixed $attachments = [] ): bool {
	$GLOBALS['bfrc_test_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
	return true;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false ): bool {
	// Mirrors core: an unknown recurrence is WP_Error 'invalid_schedule' and nothing is scheduled.
	if ( ! isset( wp_get_schedules()[ $recurrence ] ) ) {
		return false;
	}
	$GLOBALS['bfrc_test_cron'][ bfrc_test_cron_key( $hook, $args ) ] = compact( 'timestamp', 'recurrence', 'args' );
	return true;
}
/**
 * The slot of a recurring event in $GLOBALS['bfrc_test_cron']: the hook name
 * alone for an event with no args (what the plugin schedules, so tests may
 * still read `bfrc_test_cron[ $hook ]`), else `hook:md5(serialize(args))` —
 * core keys events by hook AND args (TF5).
 */
function bfrc_test_cron_key( string $hook, array $args ): string {
	return [] === $args ? $hook : $hook . ':' . md5( serialize( $args ) );
}
/**
 * As core (T7 / WPMAP-114): an event with the same hook and args already
 * scheduled within ten minutes of $timestamp makes this a duplicate — nothing
 * is scheduled and false is returned. An event due within the next ten
 * minutes collides with any earlier one; one in the past with any up to ten
 * minutes from now. Recurring events count too.
 */
function wp_schedule_single_event( int $timestamp, string $hook, array $args = [], bool $wp_error = false ): bool {
	$now = bfrc_test_now();
	$min = $timestamp < $now + 600 ? 0 : $timestamp - 600;
	$max = $timestamp < $now ? $now + 600 : $timestamp + 600;
	$key = md5( serialize( $args ) );
	$existing = $GLOBALS['bfrc_test_single_events'];
	foreach ( $GLOBALS['bfrc_test_cron'] as $slot => $event ) {
		$existing[] = [
			'timestamp' => (int) $event['timestamp'],
			'hook'      => explode( ':', (string) $slot, 2 )[0],
			'args'      => $event['args'] ?? [],
		];
	}
	foreach ( $existing as $event ) {
		if ( $event['hook'] === $hook && md5( serialize( $event['args'] ) ) === $key && $event['timestamp'] >= $min && $event['timestamp'] <= $max ) {
			return false;
		}
	}
	$GLOBALS['bfrc_test_single_events'][] = compact( 'timestamp', 'hook', 'args' );
	return true;
}
function wp_next_scheduled( string $hook, array $args = [] ): int|false {
	$key = bfrc_test_cron_key( $hook, $args );
	if ( isset( $GLOBALS['bfrc_test_cron'][ $key ] ) ) {
		return (int) $GLOBALS['bfrc_test_cron'][ $key ]['timestamp'];
	}
	foreach ( $GLOBALS['bfrc_test_single_events'] as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return (int) $event['timestamp'];
		}
	}
	return false;
}
// As core: an event is identified by hook AND md5( serialize( $args ) ), so
// clearing with no args removes only the events scheduled with no args.
// wp_unschedule_hook() is the one that removes every event for a hook.
function wp_clear_scheduled_hook( string $hook, array $args = [], bool $wp_error = false ): int|false {
	$key   = md5( serialize( $args ) );
	$count = 0;
	$slot  = bfrc_test_cron_key( $hook, $args );
	if ( isset( $GLOBALS['bfrc_test_cron'][ $slot ] ) ) {
		unset( $GLOBALS['bfrc_test_cron'][ $slot ] );
		$count = 1;
	}
	$before                             = count( $GLOBALS['bfrc_test_single_events'] );
	$GLOBALS['bfrc_test_single_events'] = array_values( array_filter( $GLOBALS['bfrc_test_single_events'], static fn( array $e ): bool => $e['hook'] !== $hook || md5( serialize( $e['args'] ) ) !== $key ) );
	return $count + ( $before - count( $GLOBALS['bfrc_test_single_events'] ) );
}
function wp_unschedule_hook( string $hook, bool $wp_error = false ): int|false {
	$count = 0;
	foreach ( array_keys( $GLOBALS['bfrc_test_cron'] ) as $slot ) {
		if ( $slot === $hook || str_starts_with( (string) $slot, $hook . ':' ) ) {
			unset( $GLOBALS['bfrc_test_cron'][ $slot ] );
			++$count;
		}
	}
	$before                             = count( $GLOBALS['bfrc_test_single_events'] );
	$GLOBALS['bfrc_test_single_events'] = array_values( array_filter( $GLOBALS['bfrc_test_single_events'], static fn( array $e ): bool => $e['hook'] !== $hook ) );
	return $count + ( $before - count( $GLOBALS['bfrc_test_single_events'] ) );
}
function wp_unschedule_event( int $timestamp, string $hook, array $args = [], bool $wp_error = false ): bool {
	$GLOBALS['bfrc_test_single_events'] = array_values( array_filter( $GLOBALS['bfrc_test_single_events'], static fn( array $e ): bool => ! ( $e['hook'] === $hook && $e['timestamp'] === $timestamp ) ) );
	return true;
}
function wp_get_schedules(): array {
	return apply_filters( 'cron_schedules', [ 'daily' => [ 'interval' => DAY_IN_SECONDS, 'display' => 'Daily' ] ] );
}

// ------------------------------------------------------------------
// Posts (minimal), dbDelta, admin registration — recorded.
// ------------------------------------------------------------------
class WP_Post {
	public int $ID             = 0;
	public string $post_type   = 'post';
	public string $post_status = 'publish';
	public string $post_title  = '';
	public string $post_name   = '';
	public int $post_author    = 0;
	public string $post_date = '';
	public string $post_date_gmt = '';
	public string $post_modified = '';
	public string $post_modified_gmt = '';
	public string $post_content = '';
	public string $post_excerpt = '';
	public string $post_password = '';
	public function __construct( array $data = [] ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}
function bfrc_test_add_post( array $data ): WP_Post {
	$post                                       = new WP_Post( $data );
	$GLOBALS['bfrc_test_posts'][ $post->ID ]    = $post;
	return $post;
}
function get_post( mixed $post = null, string $output = OBJECT ): ?WP_Post {
	if ( $post instanceof WP_Post ) {
		return $post;
	}
	if ( null === $post ) {
		$post = $GLOBALS['post'] ?? null; // core: the global post.
		return $post instanceof WP_Post ? $post : null;
	}
	return $GLOBALS['bfrc_test_posts'][ (int) $post ] ?? null;
}
function get_post_type( mixed $post = null ): string|false {
	$p = get_post( $post );
	return $p ? $p->post_type : false;
}
function get_post_status( mixed $post = null ): string|false {
	$p = get_post( $post );
	return $p ? $p->post_status : false;
}
function get_permalink( mixed $post = 0 ): string|false {
	$p = get_post( $post );
	return $p ? home_url( '/?p=' . $p->ID ) : false;
}
function wp_is_post_revision( mixed $post ): int|false {
	$id = $post instanceof WP_Post ? $post->ID : (int) $post;
	return in_array( $id, $GLOBALS['bfrc_test_revisions'] ?? [], true ) ? 1 : false;
}
function wp_is_post_autosave( mixed $post ): int|false {
	$id = $post instanceof WP_Post ? $post->ID : (int) $post;
	return in_array( $id, $GLOBALS['bfrc_test_autosaves'] ?? [], true ) ? 1 : false;
}
function get_post_types( array $args = [], string $output = 'names' ): array {
	return [ 'post' => 'post', 'page' => 'page' ];
}
function dbDelta( mixed $queries = '', bool $execute = true ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	$GLOBALS['bfrc_test_db_delta'][] = $queries;
	// A queued per-call outcome: each dbDelta() consumes one and sets $wpdb->last_error to it, as core's driver would.
	if ( [] !== ( $GLOBALS['bfrc_test_db_delta_errors'] ?? [] ) ) {
		$GLOBALS['wpdb']->last_error = (string) array_shift( $GLOBALS['bfrc_test_db_delta_errors'] );
	}
	return [];
}
function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', string $icon_url = '', ?int $position = null ): string {
	$GLOBALS['bfrc_test_admin_pages'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	return 'toplevel_page_' . $menu_slug;
}
function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', ?int $position = null ): string|false {
	$GLOBALS['bfrc_test_admin_pages'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	return 'settings_page_' . $menu_slug;
}
function add_options_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', ?int $position = null ): string|false {
	return add_submenu_page( 'options-general.php', $page_title, $menu_title, $capability, $menu_slug, $callback, $position );
}
function wp_enqueue_style( string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all' ): void {
	$GLOBALS['bfrc_test_enqueued']['style'][] = $handle;
}
function wp_enqueue_script( string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = [] ): void {
	$GLOBALS['bfrc_test_enqueued']['script'][]        = $handle;
	$GLOBALS['bfrc_test_enqueued']['deps'][ $handle ] = $deps;
}
function wp_register_script( string $handle, mixed $src, array $deps = [], mixed $ver = false, mixed $args = [] ): bool {
	$GLOBALS['bfrc_test_registered']['script'][ $handle ] = $src;
	$GLOBALS['bfrc_test_registered']['deps'][ $handle ]   = $deps;
	return true;
}
function wp_register_style( string $handle, mixed $src, array $deps = [], mixed $ver = false, string $media = 'all' ): bool {
	$GLOBALS['bfrc_test_registered']['style'][ $handle ] = $src;
	return true;
}
function map_deep( mixed $value, callable $callback ): mixed {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = map_deep( $v, $callback );
		}
		return $value;
	}
	if ( is_object( $value ) ) {
		foreach ( get_object_vars( $value ) as $k => $v ) {
			$value->$k = map_deep( $v, $callback );
		}
		return $value;
	}
	return $callback( $value );
}
function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
	foreach ( $l10n as $k => $v ) {
		if ( is_scalar( $v ) ) {
			$l10n[ $k ] = (string) $v; // As core: top-level scalars reach the browser as strings.
		}
	}
	$GLOBALS['bfrc_test_enqueued']['localize'][ $handle ][ $object_name ] = $l10n;
	return true;
}
function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	return true;
}
function wp_script_is( string $handle, string $status = 'enqueued' ): bool {
	return in_array( $handle, $GLOBALS['bfrc_test_enqueued']['script'] ?? [], true );
}
function get_current_screen(): ?object {
	return $GLOBALS['bfrc_test_screen'];
}
function bfrc_test_set_screen( ?string $id ): void {
	if ( null === $id ) {
		$GLOBALS['bfrc_test_screen'] = null;
		return;
	}
	$screen                      = new WP_Screen();
	$screen->id                  = $id;
	$screen->base                = $id;
	$GLOBALS['bfrc_test_screen'] = $screen;
}
function register_setting( string $option_group, string $option_name, array $args = [] ): void {}
function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	$GLOBALS['bfrc_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}
function get_settings_errors( string $setting = '', bool $sanitize = false ): array {
	return $GLOBALS['bfrc_test_settings_errors'] ?? [];
}
function settings_errors( string $setting = '', bool $sanitize = false, bool $hide_on_update = false ): void {}
function submit_button( ?string $text = null, string $type = 'primary', string $name = 'submit', bool $wrap = true, mixed $other_attributes = null ): void {
	// As core: the id is the name unless $other_attributes['id'] overrides it.
	$id = $name;
	if ( is_array( $other_attributes ) && isset( $other_attributes['id'] ) ) {
		$id = (string) $other_attributes['id'];
	}
	$extra = '';
	foreach ( is_array( $other_attributes ) ? $other_attributes : [] as $attr => $value ) {
		if ( 'id' !== $attr ) {
			$extra .= ' ' . esc_attr( (string) $attr ) . '="' . esc_attr( (string) $value ) . '"'; // As core: every other attribute is printed.
		}
	}
	bfrc_test_echo( '<button type="submit" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" class="button ' . esc_attr( $type ) . '"' . $extra . '>' . esc_html( $text ?? 'Save Changes' ) . '</button>' );
}
function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		bfrc_test_echo( $out );
	}
	return $out;
}
function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
	$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		bfrc_test_echo( $out );
	}
	return $out;
}
function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
	$out = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
	if ( $display ) {
		bfrc_test_echo( $out );
	}
	return $out;
}
function wp_kses_allowed_html( string $context = 'basic' ): array {
	return [];
}
function get_admin_page_title(): string {
	return 'BlueFairy API Gateway';
}
function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
	return [ 'Version' => BFRC_VERSION, 'Name' => 'BlueFairy API Gateway' ];
}
function load_plugin_textdomain( string $domain, mixed $deprecated = false, mixed $plugin_rel_path = false ): bool {
	return true;
}

// ------------------------------------------------------------------
// REST classes: enough of WP_REST_Request / WP_REST_Response for the cache
// and rate-limit layers, which only read route, method, params and headers.
// ------------------------------------------------------------------
class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
	public const EDITABLE  = 'POST, PUT, PATCH';
	public const DELETABLE = 'DELETE';
	public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
}
class WP_HTTP_Response {
	/** @var array<string, string> */
	protected array $headers = [];
	public function __construct( protected mixed $data = null, protected int $status = 200, array $headers = [] ) {
		$this->headers = $headers;
	}
	public function get_data(): mixed {
		return $this->data;
	}
	public function set_data( mixed $data ): void {
		$this->data = $data;
	}
	public function get_status(): int {
		return $this->status;
	}
	public function set_status( int $code ): void {
		$this->status = $code;
	}
	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}
	public function set_headers( array $headers ): void {
		$this->headers = $headers;
	}
	public function header( string $key, string $value, bool $replace = true ): void {
		if ( $replace || ! isset( $this->headers[ $key ] ) ) {
			$this->headers[ $key ] = $value;
		} else {
			$this->headers[ $key ] .= ', ' . $value;
		}
	}
	public function remove_header( string $key ): void {
		unset( $this->headers[ $key ] );
	}
}
class WP_REST_Response extends WP_HTTP_Response {
	/** @var array<string, mixed> */
	public array $links = [];
	public function as_error(): ?WP_Error {
		return $this->status >= 400 && is_array( $this->data ) && isset( $this->data['code'] ) ? new WP_Error( $this->data['code'], $this->data['message'] ?? '' ) : null;
	}
	public function is_error(): bool {
		return $this->status >= 400;
	}
}
class WP_REST_Request implements ArrayAccess {
	/** @var array<string, string> */
	private array $headers = [];
	/** @var array<string, mixed> */
	private array $params = [];
	/** @var array<string, mixed> */
	private array $query = [];
	/** @var array<string, mixed> */
	private array $body_params = [];
	/** @var array<string, mixed> */
	private array $url_params = [];
	private string $body = '';
	/** @var array<string, mixed> */
	private array $attributes = [];
	public function __construct( private string $method = 'GET', private string $route = '', array $attributes = [] ) {
		$this->attributes = $attributes;
	}
	public function get_method(): string {
		return $this->method;
	}
	public function set_method( string $method ): void {
		$this->method = $method;
	}
	public function get_route(): string {
		return $this->route;
	}
	public function set_route( string $route ): void {
		$this->route = $route;
	}
	public function get_header( string $key ): ?string {
		$key = strtolower( str_replace( '-', '_', $key ) );
		return $this->headers[ $key ] ?? null;
	}
	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}
	public function set_header( string $key, string $value ): void {
		$this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
	}
	public function remove_header( string $key ): void {
		unset( $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] );
	}
	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? $this->body_params[ $key ] ?? $this->query[ $key ] ?? $this->url_params[ $key ] ?? null;
	}
	public function set_param( string $key, mixed $value ): void {
		$this->params[ $key ] = $value;
	}
	public function has_param( string $key ): bool {
		return null !== $this->get_param( $key );
	}
	/** @return array<string, mixed> */
	public function get_params(): array {
		return array_merge( $this->url_params, $this->query, $this->body_params, $this->params );
	}
	/** @return array<string, mixed> */
	public function get_query_params(): array {
		return $this->query;
	}
	public function set_query_params( array $params ): void {
		$this->query = $params;
	}
	/** @return array<string, mixed> */
	public function get_body_params(): array {
		return $this->body_params;
	}
	public function set_body_params( array $params ): void {
		$this->body_params = $params;
	}
	/** @return array<string, mixed> */
	public function get_url_params(): array {
		return $this->url_params;
	}
	public function set_url_params( array $params ): void {
		$this->url_params = $params;
	}
	/** @return array<string, mixed> */
	public function get_json_params(): array {
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : [];
	}
	public function get_body(): string {
		return $this->body;
	}
	public function set_body( string $body ): void {
		$this->body = $body;
	}
	/** @return array<string, mixed> */
	public function get_attributes(): array {
		return $this->attributes;
	}
	public function set_attributes( array $attributes ): void {
		$this->attributes = $attributes;
	}
	public function get_content_type(): ?array {
		$value = $this->get_header( 'content_type' );
		if ( null === $value ) {
			return null;
		}
		[ $type ] = array_pad( explode( ';', $value, 2 ), 2, '' );
		[ $t, $s ] = array_pad( explode( '/', trim( $type ), 2 ), 2, '' );
		return [ 'value' => trim( $type ), 'type' => $t, 'subtype' => $s, 'parameters' => '' ];
	}
	public function offsetExists( mixed $offset ): bool {
		return $this->has_param( (string) $offset );
	}
	public function offsetGet( mixed $offset ): mixed {
		return $this->get_param( (string) $offset );
	}
	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->set_param( (string) $offset, $value );
	}
	public function offsetUnset( mixed $offset ): void {
		unset( $this->params[ (string) $offset ] );
	}
}
function rest_ensure_response( mixed $response ): WP_REST_Response|WP_Error {
	if ( $response instanceof WP_Error || $response instanceof WP_REST_Response ) {
		return $response;
	}
	if ( $response instanceof WP_HTTP_Response ) {
		// As core: a bare HTTP response becomes a REST response with the same data, status and headers.
		return new WP_REST_Response( $response->get_data(), $response->get_status(), $response->get_headers() );
	}
	return new WP_REST_Response( $response );
}
function register_rest_route( string $route_namespace, string $route, array $args = [], bool $override = false ): bool {
	$GLOBALS['bfrc_test_rest_routes'][ '/' . trim( $route_namespace, '/' ) . '/' . ltrim( $route, '/' ) ] = $args;
	return true;
}
function rest_get_server(): object {
	return new class() {
		public function get_routes(): array {
			return $GLOBALS['bfrc_test_rest_routes'] ?? [];
		}
	};
}
function rest_authorization_required_code(): int {
	return is_user_logged_in() ? 403 : 401;
}
function rest_sanitize_boolean( mixed $value ): bool {
	return BlueFairy\Recipes\Settings::to_bool( $value );
}

// ------------------------------------------------------------------
// Module-specific stubs and the shared TestCase.
// ------------------------------------------------------------------
require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/TestCase.php';
foreach ( glob( __DIR__ . '/stubs/*.php' ) ?: [] as $bfrc_stub_file ) {
	require_once $bfrc_stub_file;
}

bfrc_test_reset();
