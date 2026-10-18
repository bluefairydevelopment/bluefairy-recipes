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
		return $GLOBALS['bfrc_test_attachments'][ $attachment_id ] ?? false;
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
		$out = '<select name="' . esc_attr( (string) ( $args['name'] ?? 'cat' ) ) . '">';
		if ( ! empty( $args['show_option_all'] ) ) {
			$out .= '<option value="">' . esc_html( (string) $args['show_option_all'] ) . '</option>';
		}
		foreach ( (array) get_terms( [ 'taxonomy' => (string) ( $args['taxonomy'] ?? 'category' ), 'hide_empty' => false ] ) as $term ) {
			$sel  = ( (string) ( $args['selected'] ?? '' ) === $term->slug ) ? ' selected' : '';
			$out .= '<option value="' . esc_attr( $term->slug ) . '"' . $sel . '>' . esc_html( $term->name ) . '</option>';
		}
		$out .= '</select>';
		if ( ! empty( $args['echo'] ) ) {
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
