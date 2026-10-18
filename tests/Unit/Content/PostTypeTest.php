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

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1; // registration and activation run after init
	}

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
		$this->assertLessThanOrEqual( 20, strlen( PostType::NAME ), 'the name fits core\'s 20-character limit' );
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
		$this->assertFalse( $args['rewrite'], 'no rewrite rules: a matched rule with a dropped query var would serve the home page with 200' );
		$this->assertFalse( $args['query_var'] );
		$this->assertTrue( $args['show_in_rest'], 'registered for REST regardless: reads are gated at dispatch, after authentication' );
	}

	public function test_rest_reads_of_recipes_and_recipe_taxonomies_are_a_404_for_visitors_while_disabled_and_open_otherwise(): void {
		$pt     = $this->post_type();
		$server = new \WP_REST_Server();
		$recipe = new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe' );
		$term   = new \WP_REST_Request( 'GET', '/wp/v2/bfrc_meal_type/7' );
		$other  = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertNull( $pt->gate_rest_when_disabled( null, $server, $recipe ), 'enabled: untouched' );
		update_option( 'bfrc_settings', [ 'enabled' => false ] );
		$pt     = $this->post_type();
		$denied = $pt->gate_rest_when_disabled( null, $server, $recipe );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'rest_no_route', $denied->get_error_code() );
		$this->assertSame( 404, $denied->get_error_data()['status'] );
		$this->assertInstanceOf( \WP_Error::class, $pt->gate_rest_when_disabled( null, $server, $term ), 'recipe taxonomy routes too' );
		$this->assertNull( $pt->gate_rest_when_disabled( null, $server, $other ), 'other routes are not ours' );
		$early = new \WP_Error( 'x' );
		$this->assertSame( $early, $pt->gate_rest_when_disabled( $early, $server, $recipe ), 'an earlier short-circuit is kept' );
		bfrc_test_grant( 'edit_bfrc_recipes' );
		$this->assertNull( $pt->gate_rest_when_disabled( null, $server, $recipe ), 'an editor (cookie or application password, judged after authentication) keeps REST' );
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
		$this->assertSame( 10, has_filter( 'rest_pre_dispatch', [ $pt, 'gate_rest_when_disabled' ] ) );
	}

	public function test_args_is_what_register_uses(): void {
		$pt = $this->post_type();
		$pt->register();
		$this->assertSame( $pt->args(), $GLOBALS['bfrc_test_post_types']['bfrc_recipe'] );
	}
}
