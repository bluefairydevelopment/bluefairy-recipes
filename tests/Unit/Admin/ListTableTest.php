<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Admin;

use BlueFairy\Recipes\Admin\ListTable;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class ListTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$GLOBALS['bfrc_test_is_admin']      = true;
		wp_set_current_user( 9 );
		Plugin::set( 'content.taxonomies', new Taxonomies( Plugin::service( 'settings' ) ?? new Settings() ) );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_title' => 'Soup' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe', 'post_title' => 'Bread' ] );
		bfrc_test_add_post( [ 'ID' => 50, 'post_type' => 'post' ] );
	}

	private function as_curator(): void {
		bfrc_test_grant( 'edit_post', 'edit_others_bfrc_recipes' );
	}

	public function test_register_hooks(): void {
		$t = new ListTable();
		$t->register_hooks();
		$this->assertSame( 10, has_filter( 'manage_bfrc_recipe_posts_columns', [ $t, 'columns' ] ) );
		$this->assertSame( 10, has_action( 'manage_bfrc_recipe_posts_custom_column', [ $t, 'column' ] ) );
		$this->assertSame( 10, has_filter( 'manage_edit-bfrc_recipe_sortable_columns', [ $t, 'sortable_columns' ] ) );
		$this->assertSame( 10, has_action( 'restrict_manage_posts', [ $t, 'filters' ] ) );
		$this->assertSame( 10, has_action( 'pre_get_posts', [ $t, 'filter_query' ] ) );
		$this->assertSame( 10, has_filter( 'posts_clauses', [ $t, 'order_clauses' ] ) );
		$this->assertSame( 10, has_filter( 'bulk_actions-edit-bfrc_recipe', [ $t, 'bulk_actions' ] ) );
		$this->assertSame( 10, has_filter( 'handle_bulk_actions-edit-bfrc_recipe', [ $t, 'handle_bulk' ] ) );
		$this->assertSame( 10, has_filter( 'removable_query_args', [ $t, 'removable_query_args' ] ) );
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
		$this->assertSame( 'Prep 15 / Cook 30 min', bfrc_test_render( fn() => $t->column( 'bfrc_times', 41 ) ) );
		$this->assertSame( '2–4', bfrc_test_render( fn() => $t->column( 'bfrc_servings', 41 ) ) );
		$this->assertStringContainsString( 'dashicons-star-filled', bfrc_test_render( fn() => $t->column( 'bfrc_featured', 41 ) ) );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', bfrc_test_render( fn() => $t->column( 'bfrc_photo', 41 ) ) );
		foreach ( [ 'bfrc_times', 'bfrc_servings', 'bfrc_featured', 'bfrc_photo' ] as $col ) {
			$this->assertStringContainsString( '&mdash;', bfrc_test_render( fn() => $t->column( $col, 42 ) ), "$col empty" );
		}
		$this->assertStringContainsString( 'Not featured', bfrc_test_render( fn() => $t->column( 'bfrc_featured', 42 ) ), 'screen readers hear the state too' );
		update_post_meta( 42, Meta::SERVINGS_MIN, 6 );
		$this->assertSame( '6', bfrc_test_render( fn() => $t->column( 'bfrc_servings', 42 ) ), 'min only: one number' );
		$this->assertSame( '', bfrc_test_render( fn() => $t->column( 'title', 41 ) ), 'not ours: silent' );
	}

	public function test_times_text_has_a_string_for_each_case(): void {
		$this->assertSame( '', ListTable::times_text( 0, 0 ), 'the cell renders the shared aria-hidden dash' );
		$this->assertSame( 'Prep 15 min', ListTable::times_text( 15, 0 ) );
		$this->assertSame( 'Cook 30 min', ListTable::times_text( 0, 30 ) );
		$this->assertSame( 'Prep 15 / Cook 30 min', ListTable::times_text( 15, 30 ) );
	}

	public function test_sortable_columns(): void {
		$this->assertSame( [ 'title' => 'title', 'bfrc_featured' => [ 'bfrc_featured', true ] ], ( new ListTable() )->sortable_columns( [ 'title' => 'title' ] ), 'desc first: the first click on Featured puts featured recipes at the top' );
	}

	public function test_filters_render_labelled_dropdowns_for_taxonomies_with_terms_and_the_featured_select_on_the_recipe_list_only(): void {
		$t = new ListTable();
		$this->assertSame( '', bfrc_test_render( fn() => $t->filters( 'post', 'top' ) ) );
		$this->assertSame( '', bfrc_test_render( fn() => $t->filters( 'bfrc_recipe', 'bottom' ) ) );
		$GLOBALS['bfrc_test_taxonomies']['bfrc_meal_type'] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		$GLOBALS['bfrc_test_taxonomies']['bfrc_diet']      = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		wp_insert_term( 'Dinner Party', 'bfrc_meal_type' );
		$GLOBALS['wp_query'] = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$_GET                = [ 'bfrc_meal_type' => 'Dinner Party', 'bfrc_diet' => [ 'array' ], 'bfrc_featured' => '1' ];
		$html                = bfrc_test_render( fn() => $t->filters( 'bfrc_recipe', 'top' ) );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="bfrc_meal_type">Filter by Meal Types</label><select name="bfrc_meal_type"', $html, 'a taxonomy with terms: label then select' );
		$this->assertStringNotContainsString( 'for="bfrc_diet"', $html, 'a taxonomy without terms renders neither select nor orphan label' );
		$args = $GLOBALS['bfrc_test_dropdowns']['bfrc_meal_type'];
		$this->assertSame( true, $args['hide_if_empty'] );
		$this->assertSame( false, $args['hide_empty'], 'terms used only on drafts still filter' );
		$this->assertSame( false, $args['echo'] );
		$this->assertSame( ListTable::DROPDOWN_TERMS, $args['number'] );
		$this->assertSame( 'bfrc_meal_type', $args['id'] );
		$this->assertSame( 'dinner-party', $args['selected'], 'the request value is sanitised as a slug' );
		$this->assertSame( '', $GLOBALS['bfrc_test_dropdowns']['bfrc_diet']['selected'], 'an array parameter is ignored (core would fatal in sanitize_title)' );
		$this->assertSame( 'slug', $args['value_field'] );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="bfrc-featured-filter">', $html );
		$this->assertStringContainsString( '<option value="1" selected="selected">Featured only</option>', $html );
		$this->assertCount( 1, $GLOBALS['bfrc_test_thumbnail_cache_calls'], 'the thumbnail cache is primed once for the rows about to render' );
		$this->assertSame( $GLOBALS['wp_query'], $GLOBALS['bfrc_test_thumbnail_cache_calls'][0] );
		unset( $GLOBALS['wp_query'] );
	}

	public function test_filter_query_touches_only_the_main_recipe_list_query_and_never_joins_postmeta(): void {
		$t = new ListTable();
		$GLOBALS['wpdb']->always( 'get_col', [ '41', '7' ] );
		$_GET = [ 'bfrc_featured' => '1' ];
		$q    = new \WP_Query( [ 'post_type' => 'bfrc_recipe', 'meta_query' => [ [ 'key' => 'other', 'value' => 'x' ] ] ] );
		$t->filter_query( $q );
		$this->assertSame( [ 41, 7 ], $q->get( 'post__in' ), 'featured only = the curated set' );
		$this->assertSame( [ [ 'key' => 'other', 'value' => 'x' ] ], $q->get( 'meta_query' ), 'an existing meta query is left alone, and none of ours is added' );
		$q = new \WP_Query( [ 'post_type' => 'bfrc_recipe', 'post__in' => [ 7, 8 ] ] );
		$t->filter_query( $q );
		$this->assertSame( [ 7 ], $q->get( 'post__in' ), 'merged with an existing post__in' );
		wp_cache_delete( Meta::FEATURED_IDS_CACHE, 'bfrc' );
		$GLOBALS['wpdb']->always( 'get_col', [] );
		$q = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->filter_query( $q );
		$this->assertSame( [ 0 ], $q->get( 'post__in' ), 'nothing featured: the filter matches nothing (core treats an empty post__in as no filter)' );
		wp_cache_delete( Meta::FEATURED_IDS_CACHE, 'bfrc' );
		$GLOBALS['wpdb']->always( 'get_col', [ '41' ] );
		$_GET = [ 'bfrc_featured' => '0' ];
		$q    = new \WP_Query( [ 'post_type' => 'bfrc_recipe', 'post__not_in' => [ 7 ] ] );
		$t->filter_query( $q );
		$this->assertSame( [ 7, 41 ], $q->get( 'post__not_in' ), 'not featured = everything but the featured set' );
		$this->assertSame( '', $q->get( 'meta_query' ) );
		$_GET = [];
		$q    = new \WP_Query( [ 'post_type' => 'bfrc_recipe', 'orderby' => 'bfrc_featured', 'order' => 'asc' ] );
		$t->filter_query( $q );
		$this->assertSame( 'ASC', $q->get( ListTable::SORT_FLAG ) );
		$this->assertSame( 'date', $q->get( 'orderby' ), 'no meta_key, no meta_query: the sort happens in order_clauses()' );
		$this->assertSame( '', $q->get( 'meta_query' ) );
		$_GET = [ 'bfrc_featured' => 'yes' ];
		$q    = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->filter_query( $q );
		$this->assertSame( '', $q->get( 'post__in' ), 'an unknown value filters nothing' );
		$_GET = [ 'bfrc_featured' => '1' ];
		foreach ( [ new \WP_Query( [ 'post_type' => 'post' ] ), new \WP_Query( [ 'post_type' => 'bfrc_recipe' ], false ) ] as $other ) {
			$t->filter_query( $other );
			$this->assertSame( '', $other->get( 'post__in' ), 'another post type or a secondary query: untouched' );
		}
		$GLOBALS['bfrc_test_is_admin'] = false;
		$q                             = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->filter_query( $q );
		$this->assertSame( '', $q->get( 'post__in' ), 'front end: untouched' );
	}

	public function test_order_clauses_ranks_by_membership_in_the_featured_set_on_flagged_queries_only(): void {
		$t       = new ListTable();
		$clauses = [ 'orderby' => 'bfw_posts.post_date DESC', 'where' => '' ];
		$this->assertSame( $clauses, $t->order_clauses( $clauses, new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] ) ), 'not flagged: untouched' );
		$GLOBALS['wpdb']->always( 'get_col', [ '41', '7' ] );
		$out = $t->order_clauses( $clauses, new \WP_Query( [ ListTable::SORT_FLAG => 'DESC' ] ) );
		$this->assertSame( '(bfw_posts.ID IN (41,7)) DESC, bfw_posts.post_date DESC', $out['orderby'], 'featured first; a recipe that never had the key ranks as not featured' );
		$this->assertSame( '', $out['where'], 'no join, no where: only the order changes' );
		$out = $t->order_clauses( $clauses, new \WP_Query( [ ListTable::SORT_FLAG => 'ASC' ] ) );
		$this->assertStringStartsWith( '(bfw_posts.ID IN (41,7)) ASC', $out['orderby'] );
		$this->assertSame( $clauses, $t->order_clauses( $clauses, new \WP_Query( [ ListTable::SORT_FLAG => 'junk' ] ) ) );
		wp_cache_delete( Meta::FEATURED_IDS_CACHE, 'bfrc' );
		$GLOBALS['wpdb']->always( 'get_col', [] );
		$this->assertSame( $clauses, $t->order_clauses( $clauses, new \WP_Query( [ ListTable::SORT_FLAG => 'DESC' ] ) ), 'nothing featured: the date order stands' );
	}

	public function test_filter_query_applies_a_taxonomy_filter_itself_only_while_the_taxonomy_has_no_query_var(): void {
		$t    = new ListTable();
		$_GET = [ 'bfrc_meal_type' => 'lunch', 'bfrc_diet' => 'vegan', 'bfrc_season' => '0', 'bfrc_cuisine' => [ 'x' ] ];
		$GLOBALS['bfrc_test_taxonomies']['bfrc_meal_type'] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [ 'query_var' => 'bfrc_meal_type' ] ];
		foreach ( [ 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$GLOBALS['bfrc_test_taxonomies'][ $tax ] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [ 'query_var' => false ] ];
		}
		$q = new \WP_Query( [ 'post_type' => 'bfrc_recipe' ] );
		$t->filter_query( $q );
		$this->assertSame( [ [ 'taxonomy' => 'bfrc_diet', 'field' => 'slug', 'terms' => [ 'vegan' ] ] ], $q->get( 'tax_query' ), 'the plugin is disabled (no query var): we filter; meal type has a query var: core does; the 0 that core\'s "All" option posts and an array are no filter at all' );
	}

	public function test_bulk_actions_are_offered_to_curators_only(): void {
		$this->assertSame( [ 'trash' ], array_keys( ( new ListTable() )->bulk_actions( [ 'trash' => 'Move to Trash' ] ) ), 'a writer sees no featured actions' );
		$this->as_curator();
		$this->assertSame( [ 'trash', 'bfrc_feature', 'bfrc_unfeature' ], array_keys( ( new ListTable() )->bulk_actions( [ 'trash' => 'Move to Trash' ] ) ) );
	}

	public function test_handle_bulk_flips_featured_only_for_curators_where_they_may_edit_and_counts_changes(): void {
		$t = new ListTable();
		$this->assertSame( 'edit.php?x=1', $t->handle_bulk( 'edit.php?x=1', 'trash', [ 41 ] ), 'another action: untouched' );
		bfrc_test_grant( 'edit_post' );
		$this->assertSame( 'edit.php?bfrc_featured_changed=0', $t->handle_bulk( 'edit.php', 'bfrc_feature', [ 41 ] ), 'a writer with edit_post but no curation cap changes nothing' );
		$this->assertSame( '', get_post_meta( 41, Meta::FEATURED, true ) );
		$GLOBALS['bfrc_test_caps']['edit_post'] = static fn( array $args ): bool => 41 === ( $args[0] ?? 0 );
		bfrc_test_grant( 'edit_others_bfrc_recipes' );
		$redirect = $t->handle_bulk( 'edit.php?post_type=bfrc_recipe&bfrc_featured_changed=9', 'bfrc_feature', [ 41, 42, 50, 0, 999 ] );
		$this->assertSame( '1', get_post_meta( 41, Meta::FEATURED, true ) );
		$this->assertSame( '', get_post_meta( 42, Meta::FEATURED, true ), 'no edit_post on 42: untouched' );
		$this->assertSame( '', get_post_meta( 50, Meta::FEATURED, true ), 'a plain post: untouched' );
		$this->assertSame( 'edit.php?post_type=bfrc_recipe&bfrc_featured_changed=1', $redirect, 'the stale count was replaced, not appended' );
		$this->assertSame( [ 41, 42, 50, 999 ], $GLOBALS['bfrc_test_primed'][0], 'posts primed in one go, zero dropped' );
		$this->assertStringEndsWith( 'bfrc_featured_changed=0', $t->handle_bulk( 'edit.php', 'bfrc_feature', [ 41 ] ), 'already featured: not counted, not rewritten' );
		$GLOBALS['bfrc_test_caps']['edit_post'] = true;
		$this->assertStringEndsWith( 'bfrc_featured_changed=0', $t->handle_bulk( 'edit.php', 'bfrc_unfeature', [ 42 ] ), 'unfeaturing a recipe that never had the key: already not featured, nothing written' );
		$this->assertFalse( metadata_exists( 'post', 42, Meta::FEATURED ) );
		$GLOBALS['bfrc_test_caps']['edit_post'] = static fn( array $args ): bool => 41 === ( $args[0] ?? 0 );
		$this->assertStringEndsWith( 'bfrc_featured_changed=1', $t->handle_bulk( 'edit.php', 'bfrc_unfeature', [ 41 ] ) );
		$this->assertSame( '', get_post_meta( 41, Meta::FEATURED, true ) );
	}

	public function test_removable_query_args_names_the_notice_argument(): void {
		$this->assertSame( [ 'updated', 'bfrc_featured_changed' ], ( new ListTable() )->removable_query_args( [ 'updated' ] ) );
	}

	public function test_notices_shows_the_count_on_the_recipe_list_screen_only(): void {
		$t    = new ListTable();
		$_GET = [ 'bfrc_featured_changed' => '3' ];
		$this->assertSame( '', bfrc_test_render( fn() => $t->notices() ), 'no screen' );
		$GLOBALS['bfrc_test_screen']     = bfrc_test_screen_for( 'post', 'edit' );
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
