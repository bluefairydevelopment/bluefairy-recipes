<?php
/**
 * Role and rewrite stubs the bootstrap does not model: add_role/remove_role,
 * get_users() by role, flush_rewrite_rules() and wp_delete_post().
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting

if ( ! function_exists( 'add_role' ) ) {
	/** As core: returns the new role, or null when it already exists. */
	function add_role( string $role, string $display_name, array $capabilities = [] ): ?WP_Role {
		if ( isset( $GLOBALS['bfrc_test_roles'][ $role ] ) ) {
			return null;
		}
		$GLOBALS['bfrc_test_roles'][ $role ] = new WP_Role( $role, array_fill_keys( array_keys( array_filter( $capabilities ) ), true ) );
		$GLOBALS['bfrc_test_role_names'][ $role ] = $display_name;
		return $GLOBALS['bfrc_test_roles'][ $role ];
	}
}

if ( ! function_exists( 'remove_role' ) ) {
	function remove_role( string $role ): void {
		unset( $GLOBALS['bfrc_test_roles'][ $role ], $GLOBALS['bfrc_test_role_names'][ $role ] );
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Supports the arguments the plugin uses: `role`, `fields => 'ID'`,
	 * `number`, `paged`. Reads the user registry.
	 */
	function get_users( array $args = [] ): array {
		$users = array_values( array_filter( $GLOBALS['bfrc_test_users'] ?? [], static fn( WP_User $u ): bool => ! isset( $args['role'] ) || in_array( $args['role'], $u->roles, true ) ) );
		usort( $users, static fn( WP_User $a, WP_User $b ): int => $a->ID <=> $b->ID );
		$number = (int) ( $args['number'] ?? -1 );
		$paged  = max( 1, (int) ( $args['paged'] ?? 1 ) );
		if ( $number > 0 ) {
			$users = array_slice( $users, ( $paged - 1 ) * $number, $number );
		}
		$GLOBALS['bfrc_test_get_users_calls'][] = $args;
		$role_calls = count( array_filter( $GLOBALS['bfrc_test_get_users_calls'], static fn( array $a ): bool => ( $a['role'] ?? null ) === ( $args['role'] ?? null ) ) );
		if ( $role_calls > 50 ) {
			throw new RuntimeException( 'get_users() called more than 50 times for one role: a loop is spinning' ); // fail fast instead of hanging the suite
		}
		if ( 'ID' === ( $args['fields'] ?? '' ) ) {
			return array_map( static fn( WP_User $u ): string => (string) $u->ID, $users ); // core returns numeric strings
		}
		return $users;
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( bool $hard = true ): void {
		$GLOBALS['bfrc_test_rewrite_flushes'][] = $hard;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	/**
	 * Records the deletion; returns the (fake) post as core does when it
	 * existed. As core, an existing post fires `before_delete_post` and then
	 * `delete_post` (post id, post) before its row goes, and `deleted_post`
	 * after; a missing one fires nothing.
	 */
	function wp_delete_post( int $post_id, bool $force_delete = false ): WP_Post|false|null {
		$GLOBALS['bfrc_test_deleted_posts'][] = [ $post_id, $force_delete ];
		$post = $GLOBALS['bfrc_test_posts'][ $post_id ] ?? null;
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		do_action( 'before_delete_post', $post_id, $post );
		do_action( 'delete_post', $post_id, $post );
		unset( $GLOBALS['bfrc_test_posts'][ $post_id ] );
		do_action( 'deleted_post', $post_id, $post );
		return $post;
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return rtrim( dirname( $file ), '/' ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, callable $callback ): void {
		$GLOBALS['bfrc_test_activation_hooks']['activate'][ $file ] = $callback;
	}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $callback ): void {
		$GLOBALS['bfrc_test_activation_hooks']['deactivate'][ $file ] = $callback;
	}
}
if ( ! function_exists( 'wp_suspend_cache_invalidation' ) ) {
	function wp_suspend_cache_invalidation( bool $suspend = true ): bool {
		$GLOBALS['bfrc_test_suspended_invalidation'][] = $suspend;
		return ! $suspend;
	}
}

if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
	function wp_cache_flush_runtime(): bool {
		$GLOBALS['bfrc_test_runtime_flushes'] = ( $GLOBALS['bfrc_test_runtime_flushes'] ?? 0 ) + 1;
		return true;
	}
}
