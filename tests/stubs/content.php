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
	function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
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
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev = '' ): int|bool {
		$existed                                          = array_key_exists( $key, $GLOBALS['bfrc_test_post_meta'][ $post_id ] ?? [] );
		$GLOBALS['bfrc_test_post_meta'][ $post_id ][ $key ] = $value;
		return $existed ? true : 1;
	}
	function add_post_meta( int $post_id, string $key, mixed $value, bool $unique = false ): int|false {
		if ( $unique && array_key_exists( $key, $GLOBALS['bfrc_test_post_meta'][ $post_id ] ?? [] ) ) {
			return false;
		}
		$GLOBALS['bfrc_test_post_meta'][ $post_id ][ $key ] = $value;
		return 1;
	}
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		$existed = array_key_exists( $key, $GLOBALS['bfrc_test_post_meta'][ $post_id ] ?? [] );
		unset( $GLOBALS['bfrc_test_post_meta'][ $post_id ][ $key ] );
		return $existed;
	}
}
