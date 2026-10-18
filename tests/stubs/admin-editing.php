<?php
/**
 * Admin-editing stubs: meta boxes, attachments, object terms, media.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting

if ( ! function_exists( 'add_meta_box' ) ) {
	function add_meta_box( string $id, string $title, callable $callback, mixed $screen = null, string $context = 'advanced', string $priority = 'default', mixed $args = null ): void {
		$GLOBALS['bfrc_test_meta_boxes'][ $id ] = compact( 'title', 'callback', 'screen', 'context', 'priority', 'args' );
	}
}
if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media( array $args = [] ): void {
		$GLOBALS['bfrc_test_media_enqueued'] = true;
	}
}
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( mixed $post = null ): int|false {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		$t  = (int) get_post_meta( $id, '_thumbnail_id', true );
		return $t > 0 ? $t : false;
	}
	function wp_get_attachment_url( int $attachment_id ): string|false {
		$row = $GLOBALS['bfrc_test_attachments'][ $attachment_id ] ?? false;
		return is_array( $row ) ? $row['url'] : $row;
	}
	function wp_get_attachment_image_src( int $attachment_id, mixed $size = 'thumbnail' ): array|false {
		$url = wp_get_attachment_url( $attachment_id );
		return false === $url ? false : [ $url, 150, 150, true ];
	}
	function wp_get_attachment_image( int $attachment_id, mixed $size = 'thumbnail', bool $icon = false, mixed $attr = '' ): string {
		$url = wp_get_attachment_url( $attachment_id );
		return false === $url ? '' : '<img src="' . esc_url( $url ) . '" alt="">';
	}
	function get_the_post_thumbnail( mixed $post = null, mixed $size = 'post-thumbnail', mixed $attr = '' ): string {
		$id = get_post_thumbnail_id( $post );
		return false === $id ? '' : wp_get_attachment_image( $id, $size );
	}
}
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( int $object_id, mixed $terms, string $taxonomy, bool $append = false ): array|WP_Error {
		$ids = [];
		foreach ( (array) $terms as $t ) {
			$found = term_exists( $t, $taxonomy );
			if ( null === $found ) {
				$ins = wp_insert_term( (string) $t, $taxonomy );
				if ( is_wp_error( $ins ) ) {
					return $ins;
				}
				$found = [ 'term_id' => (string) $ins['term_id'] ];
			}
			$ids[] = (int) $found['term_id'];
		}
		$list = [];
		foreach ( $ids as $id ) {
			$list[] = $GLOBALS['bfrc_test_terms'][ $taxonomy ][ $id ];
		}
		$GLOBALS['bfrc_test_object_terms'][ $object_id ][ $taxonomy ] = $append ? array_merge( $GLOBALS['bfrc_test_object_terms'][ $object_id ][ $taxonomy ] ?? [], $list ) : $list;
		return $ids;
	}
	function get_the_terms( mixed $post, string $taxonomy ): array|false|WP_Error {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}
		$terms = $GLOBALS['bfrc_test_object_terms'][ $id ][ $taxonomy ] ?? [];
		return [] === $terms ? false : $terms;
	}
	function get_edit_post_link( mixed $post = 0, string $context = 'display' ): ?string {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return $id > 0 ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : null;
	}
	function wp_dropdown_categories( array $args = [] ): string {
		$GLOBALS['bfrc_test_dropdowns'][ (string) ( $args['name'] ?? 'cat' ) ] = $args;
		$out = '<select name="' . esc_attr( (string) ( $args['name'] ?? 'cat' ) ) . '">';
		$terms = get_terms( [ 'taxonomy' => (string) ( $args['taxonomy'] ?? 'category' ), 'hide_empty' => false ] );
		$terms = is_wp_error( $terms ) ? [] : $terms;
		if ( [] === $terms && ! empty( $args['hide_if_empty'] ) ) {
			return ''; // As core.
		}
		if ( ! empty( $args['show_option_all'] ) && [] !== $terms ) {
			$out .= '<option value="0">' . esc_html( (string) $args['show_option_all'] ) . '</option>'; // As core: value 0, only when there are terms.
		}
		foreach ( $terms as $term ) {
			$term = (object) $term;
			$sel  = ( (string) ( $args['selected'] ?? '' ) === $term->slug ) ? ' selected' : '';
			$out .= '<option value="' . esc_attr( $term->slug ) . '"' . $sel . '>' . esc_html( $term->name ) . '</option>';
		}
		$out .= '</select>';
		if ( ! array_key_exists( 'echo', $args ) || ! empty( $args['echo'] ) ) { // core echoes by default.
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $out;
	}
}

if ( ! class_exists( 'WP_Screen' ) ) {
	/**
	 * Just enough of core's screen object for `get_current_screen()` callers.
	 */
	final class WP_Screen {
		public string $id        = '';
		public string $base      = '';
		public string $post_type = '';
		public bool $is_block_editor = false;
	}
}

/**
 * A post edit screen for the given post type.
 */
function bfrc_test_screen_for( string $post_type, string $base = 'post' ): WP_Screen {
	$screen            = new WP_Screen();
	$screen->id        = $post_type;
	$screen->base      = $base;
	$screen->post_type = $post_type;
	return $screen;
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/** Image when the fixture's mime starts with image/. */
	function wp_attachment_is_image( mixed $post = null ): bool {
		$p = get_post( $post );
		return $p instanceof WP_Post && 'attachment' === $p->post_type && str_starts_with( (string) ( $GLOBALS['bfrc_test_attachments'][ $p->ID ]['mime'] ?? '' ), 'image/' );
	}
}
if ( ! function_exists( 'count_many_users_posts' ) ) {
	/**
	 * @param array<int> $users User ids.
	 * @return array<int, int>
	 */
	function count_many_users_posts( array $users, string $post_type = 'post', bool $public_only = false ): array {
		$GLOBALS['bfrc_test_count_calls'][] = [ $users, $post_type, $public_only ];
		$out                                 = [];
		foreach ( $users as $id ) {
			$out[ (int) $id ] = 0;
			foreach ( $GLOBALS['bfrc_test_posts'] as $post ) {
				if ( $post->post_type === $post_type && (int) $post->post_author === (int) $id && ( ! $public_only || 'publish' === $post->post_status ) ) {
					++$out[ (int) $id ];
				}
			}
		}
		return $out;
	}
}

/**
 * An image (or other) attachment fixture.
 */
function bfrc_test_add_attachment( int $id, string $mime = 'image/jpeg', string $url = '' ): WP_Post {
	$GLOBALS['bfrc_test_attachments'][ $id ] = [
		'mime' => $mime,
		'url'  => '' !== $url ? $url : 'https://example.test/uploads/' . $id . '.jpg',
	];
	return bfrc_test_add_post( [ 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit' ] );
}

if ( ! function_exists( 'update_post_thumbnail_cache' ) ) {
	function update_post_thumbnail_cache( mixed $wp_query = null ): void {
		$GLOBALS['bfrc_test_thumbnail_cache_calls'][] = $wp_query;
	}
}
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
		$bag = 'post' === $meta_type ? 'bfrc_test_post_meta' : 'bfrc_test_user_meta';
		return isset( $GLOBALS[ $bag ][ $object_id ] ) && array_key_exists( $meta_key, $GLOBALS[ $bag ][ $object_id ] );
	}
}
if ( ! function_exists( '_prime_post_caches' ) ) {
	function _prime_post_caches( array $ids, bool $update_term_cache = true, bool $update_meta_cache = true ): void {
		$GLOBALS['bfrc_test_primed'][] = $ids;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	/** Enough of get_posts() for an ids lookup by post type and one meta key/value. */
	function get_posts( array $args = [] ): array {
		$GLOBALS['bfrc_test_get_posts_calls'][] = $args;
		$out = [];
		foreach ( $GLOBALS['bfrc_test_posts'] as $post ) {
			if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
				continue;
			}
			if ( isset( $args['meta_key'] ) ) {
				$value = $GLOBALS['bfrc_test_post_meta'][ $post->ID ][ $args['meta_key'] ] ?? null;
				if ( null === $value || ( isset( $args['meta_value'] ) && (string) ( is_bool( $value ) ? ( $value ? '1' : '' ) : $value ) !== (string) $args['meta_value'] ) ) {
					continue;
				}
			}
			$out[] = ( $args['fields'] ?? '' ) === 'ids' ? $post->ID : $post;
		}
		return $out;
	}
}
