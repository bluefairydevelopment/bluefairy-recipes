<?php
/**
 * Stubs for milestone A6: users, attachments, uploads, dates and terms by
 * name, for the sample installer and the JSON-LD builder.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:ignoreFile

if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object(): mixed {
		$q = $GLOBALS['wp_query'] ?? null;
		return $q instanceof WP_Query ? $q->get_queried_object() : null;
	}
}
if ( ! function_exists( 'get_post_time' ) ) {
	function get_post_time( string $format = 'U', bool $gmt = false, mixed $post = null, bool $translate = false ): string|int|false {
		$p = get_post( $post );
		if ( ! $p instanceof WP_Post ) {
			return false;
		}
		// Core: get_post_datetime() reads post_date_gmt (UTC) or post_date (site time; the harness site is UTC+2), and a zero date is false, not the epoch.
		$time = $gmt ? $p->post_date_gmt : $p->post_date;
		if ( '' === $time || str_starts_with( $time, '0000' ) ) {
			return false;
		}
		$ts = strtotime( $time . ( $gmt ? ' UTC' : ' +02:00' ) );
		return 'U' === $format ? (int) $ts : ( $gmt ? gmdate( $format, (int) $ts ) : ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( new DateTimeZone( '+02:00' ) )->format( $format ) );
	}
}
if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( string $format = 'U', bool $gmt = false, mixed $post = null, bool $translate = false ): string|int|false {
		$p = get_post( $post );
		if ( ! $p instanceof WP_Post ) {
			return false;
		}
		$time = $gmt ? $p->post_modified_gmt : $p->post_modified;
		if ( '' === $time || str_starts_with( $time, '0000' ) ) {
			return false;
		}
		$ts = strtotime( $time . ( $gmt ? ' UTC' : ' +02:00' ) );
		return 'U' === $format ? (int) $ts : ( $gmt ? gmdate( $format, (int) $ts ) : ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( new DateTimeZone( '+02:00' ) )->format( $format ) );
	}
}
if ( ! function_exists( 'username_exists' ) ) {
	function username_exists( string $username ): int|false {
		foreach ( $GLOBALS['bfrc_test_users'] ?? [] as $id => $user ) {
			if ( $user instanceof WP_User && ( $user->user_login ?? '' ) === $username ) {
				return (int) $id;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wp_insert_user' ) ) {
	function wp_insert_user( array $userdata ): int|WP_Error {
		if ( ! empty( $GLOBALS['bfrc_test_user_insert_fails'] ) ) {
			return new WP_Error( 'user_insert_failed', 'refused' );
		}
		if ( false !== username_exists( (string) ( $userdata['user_login'] ?? '' ) ) ) {
			return new WP_Error( 'existing_user_login', 'Sorry, that username already exists!' );
		}
		$id   = (int) ( $GLOBALS['bfrc_test_next_user_id'] ?? 900 );
		$GLOBALS['bfrc_test_next_user_id'] = $id + 1;
		$user = bfrc_test_add_user( $id, [ 'roles' => [ (string) ( $userdata['role'] ?? 'subscriber' ) ], 'display_name' => (string) ( $userdata['display_name'] ?? '' ), 'user_login' => (string) ( $userdata['user_login'] ?? '' ), 'user_email' => (string) ( $userdata['user_email'] ?? '' ), 'user_nicename' => sanitize_title( (string) ( $userdata['user_login'] ?? '' ) ) ] );
		$GLOBALS['bfrc_test_inserted_users'][ $id ] = $userdata;
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_user' ) ) {
	/** As core: on a single site the users row goes; on multisite the user is only removed from the current site (remove_user_from_blog). */
	function wp_delete_user( int $id, ?int $reassign = null ): bool {
		$GLOBALS['bfrc_test_deleted_users'][]         = $id;
		$GLOBALS['bfrc_test_deleted_users_reassign'][] = [ $id, $reassign ];
		if ( is_multisite() ) {
			$GLOBALS['bfrc_test_removed_from_blog'][] = [ $id, get_current_blog_id() ];
			unset( $GLOBALS['bfrc_test_user_blogs'][ $id ][ get_current_blog_id() ] );
			return true;
		}
		unset( $GLOBALS['bfrc_test_users'][ $id ], $GLOBALS['bfrc_test_user_meta'][ $id ] );
		return true;
	}
}
if ( ! function_exists( 'wp_set_password' ) ) {
	function wp_set_password( string $password, int $user_id ): void {
		$GLOBALS['bfrc_test_passwords_set'][] = $user_id;
	}
}
if ( ! function_exists( 'get_post_stati' ) ) {
	/** Core's built-in statuses with a floating creation date. */
	function get_post_stati( array $args = [], string $output = 'names' ): array {
		if ( ! empty( $args['date_floating'] ) ) {
			return [ 'draft', 'pending', 'auto-draft' ];
		}
		return [ 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ];
	}
}
if ( ! function_exists( 'wp_update_user' ) ) {
	function wp_update_user( array $userdata ): int|WP_Error {
		if ( ! empty( $GLOBALS['bfrc_test_user_update_fails'] ) ) {
			return new WP_Error( 'empty_user_nicename', 'Nicename may not be empty.' );
		}
		$id   = (int) ( $userdata['ID'] ?? 0 );
		$user = $GLOBALS['bfrc_test_users'][ $id ] ?? null;
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'invalid_user_id', 'Invalid user ID.' );
		}
		foreach ( $userdata as $key => $value ) {
			if ( 'ID' !== $key ) {
				$user->$key = $value;
			}
		}
		$GLOBALS['bfrc_test_updated_users'][] = $userdata;
		return $id;
	}
}
if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	class WP_Application_Passwords {
		public static function delete_all_application_passwords( int $user_id ): int|WP_Error {
			$GLOBALS['bfrc_test_app_passwords_deleted'][] = $user_id;
			return 1;
		}
	}
}
if ( ! class_exists( 'WP_Session_Tokens' ) ) {
	class WP_Session_Tokens {
		public function __construct( private int $user_id ) {}
		public static function get_instance( int $user_id ): self {
			return new self( $user_id );
		}
		public function destroy_all(): void {
			$GLOBALS['bfrc_test_sessions_destroyed'][] = $this->user_id;
		}
	}
}
if ( ! function_exists( 'wpmu_delete_user' ) ) {
	function wpmu_delete_user( int $id ): bool {
		$GLOBALS['bfrc_test_network_deleted_users'][] = $id;
		unset( $GLOBALS['bfrc_test_users'][ $id ], $GLOBALS['bfrc_test_user_meta'][ $id ] );
		return true;
	}
}
if ( ! function_exists( 'get_blogs_of_user' ) ) {
	/** Scripted: `$GLOBALS['bfrc_test_user_blogs'][$id]` (default: none). */
	function get_blogs_of_user( int $id, bool $all = false ): array {
		return $GLOBALS['bfrc_test_user_blogs'][ $id ] ?? [];
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ): int|WP_Error {
		if ( ! empty( $GLOBALS['bfrc_test_post_insert_fails'] ) ) {
			return $wp_error ? new WP_Error( 'db_insert_error', 'refused' ) : 0;
		}
		$id = (int) ( $GLOBALS['bfrc_test_next_post_id'] ?? 9000 );
		$GLOBALS['bfrc_test_next_post_id'] = $id + 1;
		// As core: the post's own fields are stored unslashed (wp_insert_post() runs
		// wp_unslash() on them), and each meta_input entry goes through
		// update_post_meta(), which unslashes it once more as it always does.
		bfrc_test_add_post( [ 'ID' => $id ] + wp_unslash( array_intersect_key( $postarr, array_flip( [ 'post_type', 'post_status', 'post_author', 'post_title', 'post_name', 'post_excerpt', 'post_content' ] ) ) ) );
		$GLOBALS['bfrc_test_inserted_posts'][ $id ] = $postarr;
		foreach ( (array) ( $postarr['meta_input'] ?? [] ) as $key => $value ) {
			update_post_meta( $id, (string) $key, $value );
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_upload_bits' ) ) {
	function wp_upload_bits( string $name, mixed $deprecated, string $bits ): array {
		if ( ! empty( $GLOBALS['bfrc_test_upload_fails'] ) ) {
			return [ 'error' => 'Unable to create directory.' ];
		}
		$GLOBALS['bfrc_test_uploads'][] = [ 'name' => $name, 'bytes' => strlen( $bits ) ];
		return [ 'file' => '/uploads/' . $name, 'url' => 'https://example.com/wp-content/uploads/' . $name, 'type' => 'image/jpeg', 'error' => false ];
	}
}
if ( ! function_exists( 'wp_insert_attachment' ) ) {
	function wp_insert_attachment( array $args, mixed $file = false, int $parent = 0, bool $wp_error = false ): int|WP_Error {
		$id = (int) ( $GLOBALS['bfrc_test_next_post_id'] ?? 9000 );
		$GLOBALS['bfrc_test_next_post_id'] = $id + 1;
		bfrc_test_add_attachment( $id, (string) ( $args['post_mime_type'] ?? 'image/jpeg' ), 'https://example.com/wp-content/uploads/' . basename( (string) $file ) );
		$GLOBALS['bfrc_test_posts'][ $id ]->post_title = (string) ( $args['post_title'] ?? '' );
		$GLOBALS['bfrc_test_attachment_parents'][ $id ] = $parent;
		return $id;
	}
}
if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( int $attachment_id, string $file ): array {
		return [ 'file' => basename( $file ), 'width' => 1200, 'height' => 800 ];
	}
}
if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	function wp_update_attachment_metadata( int $attachment_id, array $data ): bool {
		$GLOBALS['bfrc_test_attachment_meta'][ $attachment_id ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( int $post_id, bool $force_delete = false ): WP_Post|false|null {
		$post = $GLOBALS['bfrc_test_posts'][ $post_id ] ?? null;
		$GLOBALS['bfrc_test_deleted_attachments'][] = $post_id;
		unset( $GLOBALS['bfrc_test_posts'][ $post_id ], $GLOBALS['bfrc_test_attachments'][ $post_id ] );
		return $post instanceof WP_Post ? $post : false;
	}
}
if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( mixed $post, int $thumbnail_id ): bool {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		update_post_meta( $id, '_thumbnail_id', $thumbnail_id );
		return true;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( string $field, mixed $value, string $taxonomy = '' ): WP_Term|false {
		foreach ( $GLOBALS['bfrc_test_terms'][ $taxonomy ] ?? [] as $term ) {
			if ( $term instanceof WP_Term && (string) ( $term->$field ?? '' ) === (string) $value ) {
				return $term;
			}
		}
		return false;
	}
}
