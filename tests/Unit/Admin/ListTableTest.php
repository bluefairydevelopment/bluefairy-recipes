<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Admin;

use BlueFairy\Recipes\Admin\ListTable;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Tests\TestCase;

final class ListTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$GLOBALS['bfrc_test_is_admin']      = true;
		wp_set_current_user( 9 );
		\BlueFairy\Recipes\Plugin::set( 'content.taxonomies', new \BlueFairy\Recipes\Content\Taxonomies( \BlueFairy\Recipes\Plugin::service( 'settings' ) ?? new \BlueFairy\Recipes\Settings() ) );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_title' => 'Soup' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe', 'post_title' => 'Bread' ] );
		bfrc_test_add_post( [ 'ID' => 50, 'post_type' => 'post' ] );
	}

	public function test_register_hooks(): void {
		$t = new ListTable();
		$t->register_hooks();
		$this->assertSame( 10, has_filter( 'manage_bfrc_recipe_posts_columns', [ $t, 'columns' ] ) );
		$this->assertSame( 10, has_action( 'manage_bfrc_recipe_posts_custom_column', [ $t, 'column' ] ) );
		$this->assertSame( 10, has_filter( 'manage_edit-bfrc_recipe_sortable_columns', [ $t, 'sortable_columns' ] ) );
		$this->assertSame( 10, has_action( 'restrict_manage_posts', [ $t, 'filters' ] ) );
		$this->assertSame( 10, has_action( 'pre_get_posts', [ $t, 'filter_query' ] ) );
		$this->assertSame( 10, has_filter( 'bulk_actions-edit-bfrc_recipe', [ $t, 'bulk_actions' ] ) );
		$this->assertSame( 10, has_filter( 'handle_bulk_actions-edit-bfrc_recipe', [ $t, 'handle_bulk' ] ) );
		$this->assertSame( 10, has_action( 'admin_notices', [ $t, 'notices' ] ) );
	}

	public function test_columns_places_photo_after_the_checkbox_and_ours_before_the_date(): void {
		$cols = ( new ListTable() )->columns( [ 'cb' => '<input>', 'title' => 'Title', 'taxonomy-bfrc_meal_type' => 'Meal', 'date' => 'Date' ] );
		$this->assertSame( [ 'cb', 'bfrc_photo', 'title', 'taxonomy-bfrc_meal_type', 'bfrc_times', 'bfrc_servings', 'bfrc_featured', 'date' ], array_keys( $cols ) );
		$this->assertSame( 'Featured', $cols['bfrc_featured'] );
		$this->assertArrayHasKey( 'bfrc_featured', ( new ListTable() )->columns( [ 'cb' => '', 'title' => 'T' ] ), 'no date column (a filtered list): appended instead' );
	}

	public function test_column_renders_each_cell_from_meta(): void {
		$t = new ListTable();
		update_post_meta( 41, Meta::PREP_MINUTES, 15 );
		update_post_meta( 41, Meta::COOK_MINUTES, 30 );
		update_post_meta( 41, Meta::SERVINGS_MIN, 2 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 4 );
		update_post_meta( 41, Meta::FEATURED, true );
		update_post_meta( 41, '_thumbnail_id', 77 );
		bfrc_test_add_attachment( 77 );
		$this->assertStringContainsString( 'Prep 15 / Cook 30 min', bfrc_test_render( fn() => $t->column( 'bfrc_times', 41 ) ) );
		$this->assertSame( '2–4', bfrc_test_render( fn() => $t->column( 'bfrc_servings', 41 ) ) );
		$this->assertStringContainsString( 'dashicons-star-filled', bfrc_test_render( fn() => $t->column( 'bfrc_featured', 41 ) ) );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', bfrc_test_render( fn() => $t->column( 'bfrc_photo', 41 ) ) );
		foreach ( [ 'bfrc_times', 'bfrc_servings', 'bfrc_featured', 'bfrc_photo' ] as $col ) {
			$this->assertStringContainsString( '&mdash;', bfrc_test_render( fn() => $t->column( $col, 42 ) ), "$col empty" );
		}
		update_post_meta( 42, Meta::SERVINGS_MIN, 6 );
		$this->assertSame( '6', bfrc_test_render( fn() => $t->column( 'bfrc_servings', 42 ) ), 'min only: one number' );
		$this->assertSame( '', bfrc_test_render( fn() => $t->column( 'title', 41 ) ), 'not ours: silent' );
	}

	public function test_sortable_columns(): void {
		$this->assertSame( [ 'title' => 'title', 'bfrc_featured' => 'bfrc_featured' ], ( new ListTable() )->sortable_columns( [ 'title' => 'title' ] ) );
	}

	public function test_filters_render_a_dropdown_per_enabled_taxonomy_and_the_featured_select_on_the_recipe_list_only(): void {
		$t = new ListTable();
		$this->assertSame( '', bfrc_test_render( fn() => $t->filters( 'post', 'top' ) ) );
		$this->assertSame( '', bfrc_test_render( fn() => $t->filters( 'bfrc_recipe', 'bottom' ) ) );
		$_GET = [ 'bfrc_meal_type' => 'Dinner Party', 'bfrc_featured' => '1' ];
		$html = bfrc_test_render( fn() => $t->filters( 'bfrc_recipe', 'top' ) );
		foreach ( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$this->assertStringContainsString( 'name="' . $tax . '"', $html );
		}
		$this->assertSame( 'dinner-party', $GLOBALS['bfrc_test_dropdowns']['bfrc_meal_type']['selected'], 'the request value is sanitised as a slug' );
		$this->assertSame( 'slug', $GLOBALS['bfrc_test_dropdowns']['bfrc_meal_type']['value_field'] );
		$this->assertStringContainsString( '<option value="1" selected="selected">Featured only</option>', $html );
	}

	public function test_filter_query_touches_only_the_main_recipe_list_query(): void {
		$t = new ListTable();
		$_GET = [ 'bfrc_featured' => '1' ];
		$q    = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->filter_query( $q );
		$this->assertSame( [ [ 'key' => '_bfrc_featured', 'value' => '1' ] ], $q->get( 'meta_query' ) );
		$_GET = [ 'bfrc_featured' => '0' ];
		$q    = new \WP_Query( [ 'post_type' => 'bfrc_recipe', 'orderby' => 'bfrc_featured' ] );
		$t->filter_query( $q );
		$this->assertSame( 'OR', $q->get( 'meta_query' )['relation'] );
		$this->assertSame( 'NOT EXISTS', $q->get( 'meta_query' )[0]['compare'], 'never-saved recipes count as not featured' );
		$this->assertSame( 'meta_value_num', $q->get( 'orderby' ) );
		$this->assertSame( '_bfrc_featured', $q->get( 'meta_key' ) );
		$_GET = [ 'bfrc_featured' => 'yes' ];
		$q    = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->filter_query( $q );
		$this->assertSame( '', $q->get( 'meta_query' ), 'an unknown value filters nothing' );
		$_GET = [ 'bfrc_featured' => '1' ];
		foreach ( [ new \WP_Query( [ 'post_type' => 'post' ] ), new \WP_Query( [ 'post_type' => 'bfrc_recipe' ], false ) ] as $other ) {
			$t->filter_query( $other );
			$this->assertSame( '', $other->get( 'meta_query' ), 'another post type or a secondary query: untouched' );
		}
		$GLOBALS['bfrc_test_is_admin'] = false;
		$q                             = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->filter_query( $q );
		$this->assertSame( '', $q->get( 'meta_query' ), 'front end: untouched' );
	}

	public function test_bulk_actions(): void {
		$actions = ( new ListTable() )->bulk_actions( [ 'trash' => 'Move to Trash' ] );
		$this->assertSame( [ 'trash', 'bfrc_feature', 'bfrc_unfeature' ], array_keys( $actions ) );
	}

	public function test_handle_bulk_flips_featured_only_where_the_user_may_edit_and_reports_the_count(): void {
		$t = new ListTable();
		$this->assertSame( 'edit.php?x=1', $t->handle_bulk( 'edit.php?x=1', 'trash', [ 41 ] ), 'another action: untouched' );
		$GLOBALS['bfrc_test_caps']['edit_post'] = static fn( array $args ): bool => 41 === ( $args[0] ?? 0 );
		$redirect = $t->handle_bulk( 'edit.php?post_type=bfrc_recipe&bfrc_featured_changed=9', 'bfrc_feature', [ 41, 42, 50, 0, 999 ] );
		$this->assertTrue( get_post_meta( 41, Meta::FEATURED, true ) );
		$this->assertSame( '', get_post_meta( 42, Meta::FEATURED, true ), 'no edit_post on 42: untouched' );
		$this->assertSame( '', get_post_meta( 50, Meta::FEATURED, true ), 'a plain post: untouched' );
		$this->assertSame( 'edit.php?post_type=bfrc_recipe&bfrc_featured_changed=1', $redirect, 'the stale count was replaced, not appended' );
		$this->assertStringEndsWith( 'bfrc_featured_changed=1', $t->handle_bulk( 'edit.php', 'bfrc_unfeature', [ 41 ] ) );
		$this->assertFalse( get_post_meta( 41, Meta::FEATURED, true ) );
	}

	public function test_notices_shows_the_count_on_the_recipe_list_screen_only(): void {
		$t    = new ListTable();
		$_GET = [ 'bfrc_featured_changed' => '3' ];
		$this->assertSame( '', bfrc_test_render( fn() => $t->notices() ), 'no screen' );
		$GLOBALS['bfrc_test_screen'] = bfrc_test_screen_for( 'post', 'edit' );
		$GLOBALS['bfrc_test_screen']->id = 'edit-post';
		$this->assertSame( '', bfrc_test_render( fn() => $t->notices() ), 'another list' );
		$GLOBALS['bfrc_test_screen']->id = 'edit-bfrc_recipe';
		$this->assertStringContainsString( '3 recipes updated.', bfrc_test_render( fn() => $t->notices() ) );
		$_GET = [ 'bfrc_featured_changed' => '1' ];
		$this->assertStringContainsString( '1 recipe updated.', bfrc_test_render( fn() => $t->notices() ) );
		$_GET = [];
		$this->assertSame( '', bfrc_test_render( fn() => $t->notices() ) );
	}
}
