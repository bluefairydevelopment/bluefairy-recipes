<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Content;

use BlueFairy\Recipes\Content\Slugs;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class SlugsTest extends TestCase {

	private function slugs(): Slugs {
		return new Slugs( new Taxonomies( new Settings() ) );
	}

	public function test_reserved_is_the_fixed_list_plus_every_taxonomy_code_in_both_spellings_plus_the_filter(): void {
		add_filter( 'bfrc_reserved_slugs', static fn( array $l ): array => array_merge( $l, [ 'Special', '', 7 ] ) );
		$reserved = $this->slugs()->reserved();
		foreach ( Slugs::FIXED as $fixed ) {
			$this->assertContains( $fixed, $reserved );
		}
		$this->assertContains( 'meal-type', $reserved, 'the URL spelling' );
		$this->assertContains( 'meal_type', $reserved, 'the code spelling' );
		$this->assertContains( 'special', $reserved, 'lower-cased' );
		$this->assertNotContains( '', $reserved );
		$this->assertSame( array_values( array_unique( $reserved ) ), $reserved );
	}

	public function test_is_reserved_is_case_insensitive(): void {
		$this->assertTrue( $this->slugs()->is_reserved( 'Chef' ) );
		$this->assertTrue( $this->slugs()->is_reserved( 'cuisine' ) );
		$this->assertFalse( $this->slugs()->is_reserved( 'chefs' ) );
		$this->assertFalse( $this->slugs()->is_reserved( 'lasagne' ) );
	}

	public function test_a_reserved_recipe_slug_gets_the_first_free_numeric_suffix(): void {
		$this->wpdb->queue( 'get_var', '55' ); // chef-2 taken by another recipe
		$this->wpdb->queue( 'get_var', null ); // chef-3 free
		$this->assertSame( 'chef-3', $this->slugs()->filter_unique_slug( 'chef', 9, 'publish', 'bfrc_recipe', 0, 'chef' ) );
		$lookups = $this->wpdb->queries_matching( 'SELECT ID FROM bfw_posts WHERE post_name' );
		$this->assertCount( 2, $lookups );
		$this->assertStringContainsString( "post_name = 'chef-2' AND post_type = 'bfrc_recipe' AND ID != 9 LIMIT 1", $lookups[0], 'the post being saved does not count against itself' );
	}

	public function test_other_post_types_and_unreserved_slugs_pass_through_untouched(): void {
		$this->assertSame( 'chef', $this->slugs()->filter_unique_slug( 'chef', 9, 'publish', 'page', 0, 'chef' ), 'a Page may be called chef' );
		$this->assertSame( 'lasagne', $this->slugs()->filter_unique_slug( 'lasagne', 9, 'publish', 'bfrc_recipe', 0, 'lasagne' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'SELECT' ), 'no lookup for an unreserved slug' );
	}

	public function test_a_suffixed_slug_that_is_itself_reserved_is_skipped(): void {
		add_filter( 'bfrc_reserved_slugs', static fn( array $l ): array => array_merge( $l, [ 'feed-2' ] ) );
		$this->assertSame( 'feed-3', $this->slugs()->filter_unique_slug( 'feed', 1, 'publish', 'bfrc_recipe', 0, 'feed' ) );
	}

	public function test_when_every_suffix_is_taken_a_random_one_beyond_the_range_is_used(): void {
		$this->wpdb->always( 'get_var', '1' );
		$slug = $this->slugs()->filter_unique_slug( 'submit', 1, 'publish', 'bfrc_recipe', 0, 'submit' );
		$this->assertSame( 1, preg_match( '/^submit-(\d+)$/', $slug, $m ) );
		$this->assertGreaterThan( Slugs::MAX_SUFFIX, (int) $m[1] );
		$this->assertCount( Slugs::MAX_SUFFIX - 1, $this->wpdb->queries_matching( 'SELECT ID FROM bfw_posts' ), 'bounded: 2..100' );
	}

	public function test_register_hooks_filters_wp_unique_post_slug_with_all_six_arguments(): void {
		$s = $this->slugs();
		$s->register_hooks();
		$this->assertSame( 10, has_filter( 'wp_unique_post_slug', [ $s, 'filter_unique_slug' ] ) );
		$this->assertSame( 'chef-2', apply_filters( 'wp_unique_post_slug', 'chef', 3, 'publish', 'bfrc_recipe', 0, 'chef' ) );
	}
}
