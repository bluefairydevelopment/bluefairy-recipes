<?php
/**
 * Front-end stubs: a WP_Query with the conditionals and result fields the
 * public views read, template/theme functions, user queries, URLs.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * The query-var bag, the main-query flag, the conditionals the plugin
	 * asks, and the result fields a template reads. Tests set `posts`,
	 * `max_num_pages`, `queried_object` and the `is_*` flags directly.
	 */
	final class WP_Query {
		/** @var array<string, mixed> */
		public array $query_vars = [];
		public bool $main        = true;
		/** @var array<int, WP_Post> */
		public array $posts        = [];
		public int $found_posts    = 0;
		public int $max_num_pages  = 0;
		public mixed $queried_object = null;
		public bool $is_singular   = false;
		public bool $is_archive    = false;
		public bool $is_post_type_archive = false;
		public bool $is_tax        = false;
		public bool $is_author     = false;
		public bool $is_404        = false;
		public int $set_404_calls  = 0;
		/**
		 * @param array<string, mixed> $vars Query vars.
		 */
		public function __construct( array $vars = [], bool $main = true ) {
			$this->query_vars = $vars;
			$this->main       = $main;
		}
		public function get( string $var, mixed $default_value = '' ): mixed {
			return $this->query_vars[ $var ] ?? $default_value;
		}
		public function set( string $var, mixed $value ): void {
			$this->query_vars[ $var ] = $value;
		}
		public function is_main_query(): bool {
			return $this->main;
		}
		/** As core: no argument = any singular; a type = singular of that type (the queried post's). */
		public function is_singular( mixed $post_types = '' ): bool {
			if ( ! $this->is_singular ) {
				return false;
			}
			if ( '' === $post_types || [] === $post_types ) {
				return true;
			}
			$type = $this->queried_object instanceof WP_Post ? $this->queried_object->post_type : (string) $this->get( 'post_type' );
			return in_array( $type, (array) $post_types, true );
		}
		public function is_post_type_archive( mixed $post_types = '' ): bool {
			if ( ! $this->is_post_type_archive ) {
				return false;
			}
			return '' === $post_types || [] === $post_types || in_array( (string) $this->get( 'post_type' ), (array) $post_types, true );
		}
		/** As core: a taxonomy name (or list) narrows to that taxonomy of the queried term. */
		public function is_tax( mixed $taxonomy = '', mixed $term = '' ): bool {
			if ( ! $this->is_tax ) {
				return false;
			}
			if ( '' === $taxonomy || [] === $taxonomy ) {
				return true;
			}
			return $this->queried_object instanceof WP_Term && in_array( $this->queried_object->taxonomy, (array) $taxonomy, true );
		}
		public function is_author(): bool {
			return $this->is_author;
		}
		public function is_404(): bool {
			return $this->is_404;
		}
		public function set_404(): void {
			$this->is_404 = true;
			++$this->set_404_calls;
		}
		public function get_queried_object(): mixed {
			return $this->queried_object;
		}
	}
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	/**
	 * Resolves against the user registry: `role__in`, `meta_key`/`meta_value`,
	 * a NOT EXISTS / != `meta_query`, `number`, display-name order.
	 */
	final class WP_User_Query {
		/** @var array<string, mixed> */
		public array $query_vars;
		/** @var array<int, object> */
		private array $results = [];
		/**
		 * @param array<string, mixed> $args The query.
		 */
		public function __construct( array $args = [] ) {
			$this->query_vars                   = $args;
			$GLOBALS['bfrc_test_user_queries'][] = $args;
			$users                              = [];
			foreach ( $GLOBALS['bfrc_test_users'] ?? [] as $id => $user ) {
				if ( ! $user instanceof WP_User ) {
					continue;
				}
				if ( isset( $args['role__in'] ) && [] === array_intersect( (array) $args['role__in'], $user->roles ) ) {
					continue;
				}
				$meta = $GLOBALS['bfrc_test_user_meta'][ $id ] ?? [];
				if ( isset( $args['meta_key'] ) ) {
					if ( ! array_key_exists( $args['meta_key'], $meta ) || (string) $meta[ $args['meta_key'] ] !== (string) ( $args['meta_value'] ?? '' ) ) {
						continue;
					}
				}
				if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
					$ok = false;
					foreach ( $args['meta_query'] as $clause ) {
						if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
							continue;
						}
						$exists = array_key_exists( $clause['key'], $meta );
						$cmp    = $clause['compare'] ?? '=';
						if ( 'NOT EXISTS' === $cmp && ! $exists ) {
							$ok = true;
						} elseif ( '!=' === $cmp && $exists && (string) $meta[ $clause['key'] ] !== (string) $clause['value'] ) {
							$ok = true;
						} elseif ( '=' === $cmp && $exists && (string) $meta[ $clause['key'] ] === (string) $clause['value'] ) {
							$ok = true;
						}
					}
					if ( ! $ok ) {
						continue;
					}
				}
				$users[] = $user;
			}
			usort( $users, static fn( WP_User $a, WP_User $b ): int => strcasecmp( $a->display_name, $b->display_name ) ?: $a->ID <=> $b->ID );
			if ( isset( $args['number'] ) && (int) $args['number'] > 0 ) {
				$users = array_slice( $users, 0, (int) $args['number'] );
			}
			if ( isset( $args['fields'] ) && is_array( $args['fields'] ) ) {
				// As core: a list of fields returns plain objects with those properties only.
				$users = array_map(
					static function ( WP_User $u ) use ( $args ): object {
						$row = new stdClass();
						foreach ( $args['fields'] as $f ) {
							$row->$f = $u->$f;
						}
						return $row;
					},
					$users
				);
			}
			$this->results = $users;
		}
		/** @return array<int, object> */
		public function get_results(): array {
			return $this->results;
		}
	}
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( string $regex, mixed $query, string $after = 'bottom' ): void {
		$GLOBALS['bfrc_test_rewrite_rules'][] = [ $regex, $query, $after ];
	}
}
if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( mixed $block_type, array $args = [] ): object|false {
		$GLOBALS['bfrc_test_blocks'][ (string) $block_type ] = $args;
		return (object) [ 'name' => (string) $block_type ];
	}
}
if ( ! function_exists( 'register_block_template' ) ) {
	function register_block_template( string $template_name, array $args = [] ): object {
		$GLOBALS['bfrc_test_block_templates'][ $template_name ] = $args;
		return (object) [ 'id' => $template_name ];
	}
}
if ( ! function_exists( 'wp_is_block_theme' ) ) {
	function wp_is_block_theme(): bool {
		return (bool) ( $GLOBALS['bfrc_test_block_theme'] ?? false );
	}
}
if ( ! function_exists( 'locate_template' ) ) {
	/** The theme "has" whichever relative paths a test lists in bfrc_test_theme_files. */
	function locate_template( mixed $template_names, bool $load = false, bool $load_once = true, array $args = [] ): string {
		foreach ( (array) $template_names as $name ) {
			if ( in_array( $name, $GLOBALS['bfrc_test_theme_files'] ?? [], true ) ) {
				return '/theme/' . $name;
			}
		}
		return '';
	}
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code, string $description = '' ): void {
		$GLOBALS['bfrc_test_status_headers'][] = $code;
	}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['bfrc_test_nocache'] = true;
	}
}
if ( ! function_exists( 'cache_users' ) ) {
	function cache_users( array $user_ids ): void {
		$GLOBALS['bfrc_test_cached_users'][] = array_values( $user_ids );
	}
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( mixed $data ): mixed {
		return $data;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( mixed $post = 0 ): string {
		$p = get_post( $post );
		return $p instanceof WP_Post ? $p->post_title : '';
	}
}
if ( ! function_exists( 'has_excerpt' ) ) {
	function has_excerpt( mixed $post = 0 ): bool {
		$p = get_post( $post );
		return $p instanceof WP_Post && '' !== ( $p->post_excerpt ?? '' );
	}
}
if ( ! function_exists( 'get_the_excerpt' ) ) {
	function get_the_excerpt( mixed $post = null ): string {
		$p = get_post( $post );
		return $p instanceof WP_Post ? (string) ( $p->post_excerpt ?? '' ) : '';
	}
}
if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( string $format = '', mixed $post = null ): string {
		$p = get_post( $post );
		return $p instanceof WP_Post && '' !== $p->post_date_gmt ? substr( $p->post_date_gmt, 0, 10 ) : '2026-01-01';
	}
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( string $text, int $num_words = 55, mixed $more = null ): string {
		$words = preg_split( '/\s+/', trim( $text ) ) ?: [];
		if ( count( $words ) <= $num_words ) {
			return trim( $text );
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( null === $more ? '&hellip;' : (string) $more );
	}
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( string $text, bool $br = true ): string {
		return '' === trim( $text ) ? '' : '<p>' . implode( '</p><p>', preg_split( '/\n\s*\n/', trim( $text ) ) ?: [] ) . '</p>';
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( string $classname, string $fallback = '' ): string {
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $classname ) ?? '';
		return '' === $sanitized ? $fallback : $sanitized;
	}
}
if ( ! function_exists( 'user_trailingslashit' ) ) {
	function user_trailingslashit( string $url, string $type_of_url = '' ): string {
		return rtrim( $url, '/' ) . '/';
	}
}
if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( string $post_type ): string|false {
		$slug = $GLOBALS['bfrc_test_archive_slugs'][ $post_type ] ?? ( 'bfrc_recipe' === $post_type ? 'recipes' : $post_type );
		return home_url( '/' . $slug . '/' );
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( mixed $term, string $taxonomy = '' ): string|WP_Error {
		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'invalid_term', 'Empty Term.' );
		}
		return home_url( '/recipes/' . str_replace( '_', '-', (string) preg_replace( '/^bfrc_/', '', $term->taxonomy ) ) . '/' . $term->slug . '/' );
	}
}
if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( mixed $post = null, mixed $size = 'post-thumbnail' ): string|false {
		$id = get_post_thumbnail_id( $post );
		return $id ? wp_get_attachment_url( (int) $id ) : false;
	}
}
if ( ! function_exists( 'get_avatar' ) ) {
	function get_avatar( mixed $id_or_email, int $size = 96, string $default_value = '', string $alt = '', mixed $args = null ): string|false {
		$class = is_array( $args ) && isset( $args['class'] ) ? ' class="' . esc_attr( (string) $args['class'] ) . '"' : '';
		return '<img src="https://gravatar.test/' . (int) $id_or_email . '" width="' . $size . '" height="' . $size . '" alt=""' . $class . '>';
	}
}
if ( ! function_exists( 'paginate_links' ) ) {
	/** Records the arguments; returns a plain list with one link per page. */
	function paginate_links( mixed $args = '' ): string|array|null {
		$args                                 = is_array( $args ) ? $args : [];
		$GLOBALS['bfrc_test_paginate_calls'][] = $args;
		$total                                = (int) ( $args['total'] ?? 1 );
		if ( $total < 2 ) {
			return null;
		}
		$items = [];
		for ( $i = 1; $i <= $total; $i++ ) {
			$items[] = '<li><span class="page-numbers' . ( (int) ( $args['current'] ?? 1 ) === $i ? ' current' : '' ) . '">' . $i . '</span></li>';
		}
		return "<ul class='page-numbers'>" . implode( '', $items ) . '</ul>';
	}
}
if ( ! function_exists( 'get_header' ) ) {
	function get_header( ?string $name = null, array $args = [] ): void {
		echo '<!-- header -->';
	}
}
if ( ! function_exists( 'get_footer' ) ) {
	function get_footer( ?string $name = null, array $args = [] ): void {
		echo '<!-- footer -->';
	}
}
if ( ! function_exists( 'have_posts' ) ) {
	function have_posts(): bool {
		$q = $GLOBALS['wp_query'] ?? null;
		return $q instanceof WP_Query && isset( $q->posts[ $GLOBALS['bfrc_test_loop_index'] ?? 0 ] );
	}
}
if ( ! function_exists( 'the_post' ) ) {
	function the_post(): void {
		$i                               = $GLOBALS['bfrc_test_loop_index'] ?? 0;
		$GLOBALS['post']                 = $GLOBALS['wp_query']->posts[ $i ];
		$GLOBALS['bfrc_test_loop_index'] = $i + 1;
	}
}

/**
 * A main front-end query for one of the public views.
 *
 * @param array<string, mixed> $vars Query vars.
 */
function bfrc_test_front_query( array $vars, string $kind = 'archive' ): WP_Query {
	$q = new WP_Query( $vars );
	switch ( $kind ) {
		case 'single':
			$q->is_singular = true;
			break;
		case 'tax':
			$q->is_archive = true;
			$q->is_tax     = true;
			break;
		case 'author':
			$q->is_archive = true;
			$q->is_author  = true;
			$q->is_post_type_archive = true;
			break;
		default:
			$q->is_archive           = true;
			$q->is_post_type_archive = true;
	}
	$GLOBALS['wp_query']           = $q;
	$GLOBALS['bfrc_test_loop_index'] = 0;
	return $q;
}

if ( ! function_exists( 'post_password_required' ) ) {
	function post_password_required( mixed $post = null ): bool {
		$p = get_post( $post );
		return $p instanceof WP_Post && '' !== ( $p->post_password ?? '' ) && empty( $GLOBALS['bfrc_test_password_unlocked'][ $p->ID ] );
	}
}
if ( ! function_exists( 'get_the_password_form' ) ) {
	function get_the_password_form( mixed $post = 0 ): string {
		$p = get_post( $post );
		return '<form class="post-password-form" data-post="' . ( $p instanceof WP_Post ? $p->ID : 0 ) . '"><input type="password" name="post_password"></form>';
	}
}
if ( ! function_exists( 'term_description' ) ) {
	/** As core: wpautop and friends over the description. */
	function term_description( mixed $term = 0, mixed $deprecated = null ): string {
		$t = $term instanceof WP_Term ? $term : null;
		if ( null === $t && is_int( $term ) ) {
			foreach ( $GLOBALS['bfrc_test_terms'] ?? [] as $terms ) {
				foreach ( $terms as $candidate ) {
					if ( $candidate instanceof WP_Term && $candidate->term_id === $term ) {
						$t = $candidate;
					}
				}
			}
		}
		return $t ? wpautop( $t->description ) : '';
	}
}
