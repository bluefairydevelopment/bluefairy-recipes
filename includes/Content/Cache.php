<?php
/**
 * The recipe cache incrementor: every cached list of recipes carries it in
 * its key, so a change to any recipe, its fields or its terms starts a new
 * key instead of having to find and delete the old ones.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp_cache_get_last_changed( 'bfrc_recipes' )` is bumped when a recipe's
 * post cache is cleaned (every insert, update, delete, status change and
 * term assignment through core goes through `clean_post_cache`), when a
 * `_bfrc*` meta value is written (the edit screen writes meta after the post
 * is saved), and when a recipe taxonomy's term is edited or deleted (for
 * any list cached by term). Today only the featured and
 * recent id sets are cached (`RecipeQuery::featured()`); lists cached under
 * `key()` are stale by key, never by content.
 */
final class Cache {

	/**
	 * Object-cache group of every cached recipe list.
	 */
	public const GROUP = 'bfrc';

	/**
	 * The incrementor's group (core keeps one `last_changed` per group).
	 */
	public const LAST_CHANGED_GROUP = 'bfrc_recipes';

	/**
	 * Hooks that bump the incrementor.
	 */
	public function register_hooks(): void {
		add_action( 'clean_post_cache', [ self::class, 'on_post_cache' ], 10, 2 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_meta' ], 10, 3 );
		}
		add_action( 'set_object_terms', [ self::class, 'on_terms' ], 10, 4 );
		add_action( 'edited_term', [ self::class, 'on_term' ], 10, 3 );
		add_action( 'delete_term', [ self::class, 'on_term' ], 10, 3 );
	}

	/**
	 * The current incrementor.
	 */
	public static function last_changed(): string {
		return (string) wp_cache_get_last_changed( self::LAST_CHANGED_GROUP );
	}

	/**
	 * Start a new generation of keys.
	 */
	public static function bump(): void {
		wp_cache_set_last_changed( self::LAST_CHANGED_GROUP );
	}

	/**
	 * A cache key for a recipe list: the prefix, a hash of the arguments and
	 * the incrementor.
	 *
	 * @param string               $prefix What is cached (`featured`, …).
	 * @param array<string, mixed> $args   What it was built from.
	 */
	public static function key( string $prefix, array $args ): string {
		return $prefix . ':' . md5( (string) wp_json_encode( $args ) ) . ':' . self::last_changed();
	}

	/**
	 * `clean_post_cache`: a recipe changed.
	 *
	 * @param mixed $post_id The post id.
	 * @param mixed $post    The post (core passes WP_Post).
	 */
	public static function on_post_cache( mixed $post_id, mixed $post = null ): void {
		unset( $post_id );
		if ( $post instanceof \WP_Post && PostType::NAME === $post->post_type ) {
			self::bump();
		}
	}

	/**
	 * `added/updated/deleted_post_meta`: a recipe field changed.
	 *
	 * @param mixed $meta_ids  Ignored.
	 * @param mixed $object_id The post id.
	 * @param mixed $meta_key  The key written.
	 */
	public static function on_meta( mixed $meta_ids, mixed $object_id, mixed $meta_key ): void {
		unset( $meta_ids );
		if ( is_string( $meta_key ) && str_starts_with( $meta_key, Meta::PREFIX ) && PostType::NAME === get_post_type( (int) $object_id ) ) {
			self::bump();
		}
	}

	/**
	 * `set_object_terms`: a recipe's terms changed.
	 *
	 * @param mixed $object_id The post id.
	 * @param mixed $terms     Ignored.
	 * @param mixed $tt_ids    Ignored.
	 * @param mixed $taxonomy  The taxonomy.
	 */
	public static function on_terms( mixed $object_id, mixed $terms, mixed $tt_ids, mixed $taxonomy ): void {
		unset( $terms, $tt_ids );
		if ( is_string( $taxonomy ) && str_starts_with( $taxonomy, Taxonomies::PREFIX ) && PostType::NAME === get_post_type( (int) $object_id ) ) {
			self::bump();
		}
	}

	/**
	 * `edited_term` / `delete_term`: a recipe taxonomy's term changed.
	 *
	 * @param mixed $term_id  Ignored.
	 * @param mixed $tt_id    Ignored.
	 * @param mixed $taxonomy The taxonomy.
	 */
	public static function on_term( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		unset( $term_id, $tt_id );
		if ( is_string( $taxonomy ) && str_starts_with( $taxonomy, Taxonomies::PREFIX ) ) {
			self::bump();
		}
	}
}
