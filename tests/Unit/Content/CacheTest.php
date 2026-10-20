<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Content;

use BlueFairy\Recipes\Content\Cache;
use BlueFairy\Recipes\Tests\TestCase;

final class CacheTest extends TestCase {

	public function test_register_hooks_bumps_on_post_meta_term_assignment_and_term_edits(): void {
		$cache = new Cache();
		$cache->register_hooks();
		$this->assertSame( 10, has_action( 'clean_post_cache', [ Cache::class, 'on_post_cache' ] ) );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			$this->assertSame( 10, has_action( $hook, [ Cache::class, 'on_meta' ] ), $hook );
		}
		$this->assertSame( 10, has_action( 'set_object_terms', [ Cache::class, 'on_terms' ] ) );
		$this->assertSame( 10, has_action( 'edited_term', [ Cache::class, 'on_term' ] ) );
		$this->assertSame( 10, has_action( 'delete_term', [ Cache::class, 'on_term' ] ) );
	}

	public function test_key_changes_with_the_arguments_and_with_every_bump(): void {
		$a = Cache::key( 'featured', [ 'mode' => 'featured', 'count' => 4 ] );
		$this->assertSame( $a, Cache::key( 'featured', [ 'mode' => 'featured', 'count' => 4 ] ), 'stable until something changes' );
		$this->assertNotSame( $a, Cache::key( 'featured', [ 'mode' => 'recent', 'count' => 4 ] ) );
		$this->assertNotSame( $a, Cache::key( 'recent', [ 'mode' => 'featured', 'count' => 4 ] ) );
		$this->assertStringEndsWith( ':' . Cache::last_changed(), $a );
		Cache::bump();
		$this->assertNotSame( $a, Cache::key( 'featured', [ 'mode' => 'featured', 'count' => 4 ] ), 'a bump is a new generation of keys' );
	}

	public function test_only_recipe_changes_bump(): void {
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
		bfrc_test_add_post( [ 'ID' => 7, 'post_type' => 'post' ] );
		$before = Cache::last_changed();
		Cache::on_post_cache( 7, get_post( 7 ) );
		Cache::on_meta( 1, 41, '_thumbnail_id' );
		Cache::on_meta( 1, 7, '_bfrc_featured' );
		Cache::on_terms( 41, [], [], 'category' );
		Cache::on_terms( 7, [], [], 'bfrc_diet' );
		Cache::on_term( 3, 3, 'category' );
		Cache::on_post_cache( 41, null );
		$this->assertSame( $before, Cache::last_changed(), 'another post type, a core meta key, a core taxonomy, and a missing post object: no bump' );
		Cache::on_post_cache( 41, get_post( 41 ) );
		$one = Cache::last_changed();
		$this->assertNotSame( $before, $one, 'a recipe\'s post cache cleaned' );
		Cache::on_meta( 1, 41, '_bfrc_featured' );
		$two = Cache::last_changed();
		$this->assertNotSame( $one, $two, 'a recipe field written' );
		Cache::on_terms( 41, [ 3 ], [ 3 ], 'bfrc_diet' );
		$three = Cache::last_changed();
		$this->assertNotSame( $two, $three, 'a recipe\'s terms set' );
		Cache::on_term( 3, 3, 'bfrc_diet' );
		$this->assertNotSame( $three, Cache::last_changed(), 'a recipe taxonomy term edited or deleted' );
	}
}
