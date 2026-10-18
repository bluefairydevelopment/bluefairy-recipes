<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Content;

use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class PostTypeTest extends TestCase {

	private function post_type(): PostType {
		return new PostType( new Settings() );
	}

	public function test_registers_a_public_block_editor_recipe_type_with_our_capability_type_and_the_archive_slug(): void {
		$this->post_type()->register();
		$args = $GLOBALS['bfrc_test_post_types']['bfrc_recipe'] ?? null;
		$this->assertIsArray( $args );
		$this->assertTrue( $args['public'] );
		$this->assertTrue( $args['publicly_queryable'] );
		$this->assertFalse( $args['exclude_from_search'] );
		$this->assertTrue( $args['show_in_rest'], 'block editor + REST' );
		$this->assertSame( 'recipes', $args['has_archive'] );
		$this->assertSame( [ 'slug' => 'recipes', 'with_front' => false, 'feeds' => true ], $args['rewrite'] );
		$this->assertSame( [ 'bfrc_recipe', 'bfrc_recipes' ], $args['capability_type'] );
		$this->assertTrue( $args['map_meta_cap'] );
		$this->assertSame( [ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ], $args['supports'] );
		$this->assertSame( 'Recipe photo', $args['labels']['featured_image'] );
		$this->assertFalse( $args['delete_with_user'], 'deleting a writer must not delete their recipes' );
		$this->assertSame( 20, strlen( 'bfrc_recipe' ) <= 20 ? 20 : 0, 'the name fits core\'s 20-character limit' );
	}

	public function test_follows_the_archive_slug_setting(): void {
		update_option( 'bfrc_settings', [ 'archive_slug' => 'cookbook' ] );
		$this->post_type()->register();
		$args = $GLOBALS['bfrc_test_post_types']['bfrc_recipe'];
		$this->assertSame( 'cookbook', $args['has_archive'] );
		$this->assertSame( 'cookbook', $args['rewrite']['slug'] );
		$this->assertSame( 'cookbook', $this->post_type()->archive_slug() );
	}

	public function test_disabled_plugin_keeps_the_admin_but_hides_every_recipe_from_visitors(): void {
		update_option( 'bfrc_settings', [ 'enabled' => false ] );
		$pt = $this->post_type();
		$this->assertFalse( $pt->is_enabled() );
		$pt->register();
		$args = $GLOBALS['bfrc_test_post_types']['bfrc_recipe'];
		$this->assertTrue( $args['show_ui'] );
		$this->assertFalse( $args['publicly_queryable'], 'single recipes 404' );
		$this->assertFalse( $args['has_archive'], 'the archive 404s' );
		$this->assertTrue( $args['exclude_from_search'] );
		$this->assertFalse( $args['show_in_nav_menus'] );
		$this->assertFalse( $args['rewrite']['feeds'] );
	}

	public function test_sitemap_lists_recipes_only_while_enabled(): void {
		$types = [ 'post' => (object) [], 'bfrc_recipe' => (object) [] ];
		$this->assertSame( [ 'post', 'bfrc_recipe' ], array_keys( $this->post_type()->filter_sitemap_post_types( $types ) ) );
		update_option( 'bfrc_settings', [ 'enabled' => false ] );
		$this->assertSame( [ 'post' ], array_keys( $this->post_type()->filter_sitemap_post_types( $types ) ) );
	}

	public function test_register_hooks_registers_after_the_taxonomies_and_filters_the_sitemap(): void {
		$pt = $this->post_type();
		$pt->register_hooks();
		$this->assertSame( 6, has_action( 'init', [ $pt, 'register' ] ) );
		$this->assertSame( 10, has_filter( 'wp_sitemaps_post_types', [ $pt, 'filter_sitemap_post_types' ] ) );
	}

	public function test_args_is_what_register_uses(): void {
		$pt = $this->post_type();
		$pt->register();
		$this->assertSame( $pt->args(), $GLOBALS['bfrc_test_post_types']['bfrc_recipe'] );
	}
}
