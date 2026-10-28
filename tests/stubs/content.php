<?php
/**
 * Content-model stubs: post types, taxonomies, terms, registered meta and
 * post meta, modelled on core closely enough that a wrong argument shows.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id          = 0;
		public string $name          = '';
		public string $slug          = '';
		public string $taxonomy      = '';
		public int $term_taxonomy_id = 0;
		public int $count            = 0;
		public int $parent           = 0;
		public string $description   = '';
		public function __construct( array $data = [] ) {
			foreach ( $data as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	/** As core: the name must be 1–20 characters; returns the object or a WP_Error. */
	function register_post_type( string $post_type, array $args = [] ): object {
		if ( '' === $post_type || strlen( $post_type ) > 20 ) {
			return new WP_Error( 'post_type_length_invalid', 'Post type names must be between 1 and 20 characters in length.' );
		}
		$GLOBALS['bfrc_test_post_types'][ $post_type ] = $args;
		return (object) array_merge( $args, [ 'name' => $post_type ] );
	}
	function post_type_exists( string $post_type ): bool {
		return isset( $GLOBALS['bfrc_test_post_types'][ $post_type ] ) || in_array( $post_type, [ 'post', 'page', 'attachment' ], true );
	}
	function get_post_type_object( string $post_type ): ?object {
		return isset( $GLOBALS['bfrc_test_post_types'][ $post_type ] ) ? (object) array_merge( $GLOBALS['bfrc_test_post_types'][ $post_type ], [ 'name' => $post_type ] ) : null;
	}
}

if ( ! function_exists( 'register_taxonomy' ) ) {
	/** As core: the name must be 1–32 characters. */
	function register_taxonomy( string $taxonomy, mixed $object_type, array $args = [] ): object {
		if ( '' === $taxonomy || strlen( $taxonomy ) > 32 ) {
			return new WP_Error( 'taxonomy_length_invalid', 'Taxonomy names must be between 1 and 32 characters in length.' );
		}
		// As core: before init there is no $wp_rewrite, and WP_Taxonomy::add_rewrite_rules() fatals on it unless rewrite is false.
		if ( 0 === did_action( 'init' ) && false !== ( $args['rewrite'] ?? true ) ) {
			throw new Error( 'Call to a member function add_rewrite_tag() on null (register_taxonomy with rewrite before init)' );
		}
		$GLOBALS['bfrc_test_taxonomies'][ $taxonomy ] = [ 'object_type' => (array) $object_type, 'args' => $args ];
		return (object) array_merge( $args, [ 'name' => $taxonomy ] );
	}
	function taxonomy_exists( string $taxonomy ): bool {
		return isset( $GLOBALS['bfrc_test_taxonomies'][ $taxonomy ] ) || in_array( $taxonomy, [ 'category', 'post_tag' ], true );
	}
	function get_taxonomy( string $taxonomy ): object|false {
		return isset( $GLOBALS['bfrc_test_taxonomies'][ $taxonomy ] ) ? (object) array_merge( $GLOBALS['bfrc_test_taxonomies'][ $taxonomy ]['args'], [ 'name' => $taxonomy ] ) : false;
	}
}

if ( ! function_exists( 'register_post_meta' ) ) {
	/** As core: a default that does not match the schema type (or enum) is refused and the key is not registered. */
	function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
		if ( array_key_exists( 'default', $args ) && isset( $args['show_in_rest']['schema'] ) ) {
			$schema  = $args['show_in_rest']['schema'];
			$default = $args['default'];
			$type_ok = match ( $schema['type'] ?? null ) {
				'string'  => is_string( $default ),
				'integer' => is_int( $default ),
				'number'  => is_int( $default ) || is_float( $default ),
				'boolean' => is_bool( $default ),
				'array'   => is_array( $default ) && array_is_list( $default ),
				'object'  => is_array( $default ),
				default   => true,
			};
			if ( ! $type_ok || ( isset( $schema['enum'] ) && ! in_array( $default, $schema['enum'], true ) ) ) {
				return false;
			}
		}
		$GLOBALS['bfrc_test_registered_meta'][ $post_type ][ $meta_key ] = $args;
		return true;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	/** As core: refuses an unregistered taxonomy, an empty name and a duplicate slug in the taxonomy. */
	function wp_insert_term( string $term, string $taxonomy, array $args = [] ): array|WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}
		$name = trim( $term );
		if ( '' === $name ) {
			return new WP_Error( 'empty_term_name', 'A name is required for this term.' );
		}
		$slug = (string) ( $args['slug'] ?? sanitize_title( $name ) );
		foreach ( $GLOBALS['bfrc_test_terms'][ $taxonomy ] ?? [] as $existing ) {
			if ( $existing->slug === $slug ) {
				return new WP_Error( 'term_exists', 'A term with the name provided already exists in this taxonomy.', $existing->term_id );
			}
		}
		$id = $GLOBALS['bfrc_test_next_term_id']++;
		$GLOBALS['bfrc_test_terms'][ $taxonomy ][ $id ] = new WP_Term(
			[
				'term_id'          => $id,
				'term_taxonomy_id' => $id,
				'name'             => $name,
				'slug'             => $slug,
				'taxonomy'         => $taxonomy,
			]
		);
		return [
			'term_id'          => $id,
			'term_taxonomy_id' => $id,
		];
	}
	/** Supports `taxonomy` (string or list), `hide_empty` (ignored: every stub term is "empty"), `fields => ids|names|all`, `number`. */
	function get_terms( array $args = [] ): array|WP_Error {
		$GLOBALS['bfrc_test_get_terms_calls'][] = $args;
		$taxonomies = (array) ( $args['taxonomy'] ?? array_keys( $GLOBALS['bfrc_test_terms'] ) );
		$out        = [];
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
			}
			foreach ( $GLOBALS['bfrc_test_terms'][ $taxonomy ] ?? [] as $term ) {
				$out[] = $term;
			}
		}
		usort( $out, static fn( WP_Term $a, WP_Term $b ): int => strcmp( $a->name, $b->name ) );
		$number = (int) ( $args['number'] ?? 0 );
		if ( $number > 0 ) {
			$out = array_slice( $out, 0, $number );
		}
		return match ( $args['fields'] ?? 'all' ) {
			'ids'   => array_map( static fn( WP_Term $t ): int => $t->term_id, $out ),
			'names' => array_map( static fn( WP_Term $t ): string => $t->name, $out ),
			default => $out,
		};
	}
	function wp_count_terms( array $args = [] ): string|WP_Error {
		$terms = get_terms( array_merge( $args, [ 'fields' => 'ids' ] ) );
		return is_wp_error( $terms ) ? $terms : (string) count( $terms );
	}
	function term_exists( mixed $term, string $taxonomy = '' ): mixed {
		foreach ( $GLOBALS['bfrc_test_terms'][ $taxonomy ] ?? [] as $existing ) {
			if ( $existing->slug === $term || $existing->name === $term || $existing->term_id === $term ) {
				return [
					'term_id'          => (string) $existing->term_id,
					'term_taxonomy_id' => (string) $existing->term_taxonomy_id,
				];
			}
		}
		return null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$all = $GLOBALS['bfrc_test_post_meta'][ $post_id ] ?? [];
		if ( '' === $key ) {
			return array_map( static fn( mixed $v ): array => [ $v ], $all );
		}
		if ( ! array_key_exists( $key, $all ) ) {
			return $single ? '' : [];
		}
		return $single ? $all[ $key ] : [ $all[ $key ] ];
	}
	/** As core: fires updated_post_meta (existing key) or added_post_meta (new key) with (meta_id, object_id, key, value). */
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev = '' ): int|bool {
		if ( ! empty( $GLOBALS['bfrc_test_meta_fails'][ $key ] ) ) {
			return false; // As core when the database refuses the write.
		}
		$value = wp_unslash( $value ); // As core's update_metadata(): callers pass slashed data.
		$value = bfrc_test_meta_storage( $value );
		$existed = array_key_exists( $key, $GLOBALS['bfrc_test_post_meta'][ $post_id ] ?? [] );
		if ( $existed && $GLOBALS['bfrc_test_post_meta'][ $post_id ][ $key ] === $value ) {
			return false; // As core: an unchanged value is not written and fires nothing.
		}
		$GLOBALS['bfrc_test_post_meta'][ $post_id ][ $key ] = $value;
		do_action( $existed ? 'updated_post_meta' : 'added_post_meta', 1, $post_id, $key, $value );
		return $existed ? true : 1;
	}
	function add_post_meta( int $post_id, string $key, mixed $value, bool $unique = false ): int|false {
		$value = wp_unslash( $value ); // As core.
		$value = bfrc_test_meta_storage( $value );
		if ( $unique && array_key_exists( $key, $GLOBALS['bfrc_test_post_meta'][ $post_id ] ?? [] ) ) {
			return false;
		}
		$GLOBALS['bfrc_test_post_meta'][ $post_id ][ $key ] = $value;
		do_action( 'added_post_meta', 1, $post_id, $key, $value );
		return 1;
	}
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		$existed = array_key_exists( $key, $GLOBALS['bfrc_test_post_meta'][ $post_id ] ?? [] );
		unset( $GLOBALS['bfrc_test_post_meta'][ $post_id ][ $key ] );
		return $existed;
	}
}

if ( ! function_exists( 'bfrc_test_meta_storage' ) ) {
	/** As core: a scalar comes back from the database as a string (false as '', true as '1'); arrays round-trip. */
	function bfrc_test_meta_storage( mixed $value ): mixed {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_int( $value ) || is_float( $value ) ? (string) $value : $value;
	}
}

if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * As core: the post of the given type(s) whose path is $page_path, in any
	 * status (trash included); a one-segment path matches a top-level post only.
	 */
	function get_page_by_path( string $page_path, string $output = OBJECT, string|array $post_type = 'page' ): ?WP_Post {
		foreach ( $GLOBALS['bfrc_test_posts'] ?? [] as $post ) {
			if ( $post instanceof WP_Post && in_array( $post->post_type, (array) $post_type, true ) && (string) ( $post->post_name ?? '' ) === $page_path && 0 === (int) ( $post->post_parent ?? 0 ) ) {
				return $post;
			}
		}
		return null;
	}
}
