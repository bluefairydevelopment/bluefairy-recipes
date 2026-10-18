<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Front;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\RecipeData;
use BlueFairy\Recipes\Front\Renderer;
use BlueFairy\Recipes\Front\Templates;
use BlueFairy\Recipes\Front\Writers;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class RendererTest extends TestCase {

	private Settings $settings;
	private Renderer $renderer;
	private Writers $writers;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		Capabilities::add_roles();
		$this->settings = new Settings();
		$taxonomies     = new Taxonomies( $this->settings );
		$this->writers  = new Writers( $this->settings );
		$this->renderer = new Renderer( $this->settings, $this->writers, $taxonomies );
		Plugin::set( 'front.templates', new Templates( $this->settings, $this->writers, $this->renderer, $taxonomies ) );
		$GLOBALS['bfrc_test_taxonomies']['bfrc_meal_type'] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		$GLOBALS['bfrc_test_taxonomies']['bfrc_diet']      = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann <b>Lee</b>', 'user_nicename' => 'ann' ] );
		bfrc_test_add_user( 9, [ 'roles' => [ 'subscriber' ], 'display_name' => 'Eve', 'user_nicename' => 'eve' ] );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Tomato "Soup"', 'post_name' => 'tomato-soup', 'post_excerpt' => 'A <em>warm</em> bowl & more.' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 9, 'post_title' => 'Bread', 'post_name' => 'bread' ] );
	}

	private function recipe_meta( int $id ): void {
		update_post_meta( $id, Meta::PREP_MINUTES, 20 );
		update_post_meta( $id, Meta::COOK_MINUTES, 75 );
		update_post_meta( $id, Meta::SERVINGS_MIN, 2 );
		update_post_meta( $id, Meta::SERVINGS_MAX, 4 );
		update_post_meta( $id, Meta::FEATURED, true );
		add_filter( 'bfrc_ingredient_types', static fn( array $t ): array => array_merge( $t, [ 'internal' ] ) );
		update_post_meta( $id, Meta::INGREDIENTS, [ [ 'measure' => '2 cups', 'label' => 'flour & co', 'type' => 'plain' ], [ 'measure' => '', 'label' => 'salt', 'type' => 'internal' ] ] );
		update_post_meta( $id, Meta::STEPS, [ [ 'text' => 'Mix <em>well</em>' ], [ 'text' => 'Bake' ] ] );
		update_post_meta( $id, Meta::NUTRITION, Meta::sanitize_nutrition( [ 'calories' => 250, 'protein' => 12.5, 'sodium' => 0.25 ] ) );
	}

	public function test_card_shows_the_fields_links_the_listed_writer_and_escapes_everything(): void {
		$this->recipe_meta( 41 );
		update_post_meta( 41, '_thumbnail_id', 77 );
		bfrc_test_add_attachment( 77 );
		$html = $this->renderer->card( get_post( 41 ), 'https://example.com/recipes/chef/ann/' );
		$this->assertStringContainsString( '<article class="bfrc-card bfrc-card--featured">', $html );
		$this->assertStringContainsString( 'href="https://example.com/?p=41"', $html );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', $html );
		$this->assertStringContainsString( 'Tomato &quot;Soup&quot;</a></h2>', $html );
		$this->assertStringContainsString( '<dt>Prep</dt><dd>20 min</dd>', $html );
		$this->assertStringContainsString( '<dt>Cook</dt><dd>1 hr 15 min</dd>', $html );
		$this->assertStringNotContainsString( '<dt>Total</dt>', $html, 'cards keep it short' );
		$this->assertStringContainsString( '<dt>Serves</dt><dd>2–4 servings</dd>', $html );
		$this->assertStringContainsString( 'By <a href="https://example.com/recipes/chef/ann/" rel="author">Ann &lt;b&gt;Lee&lt;/b&gt;</a>', $html );
		$this->assertStringContainsString( '<p class="bfrc-card__excerpt">A warm bowl &amp; more.</p>', $html, 'excerpt stripped of tags, escaped' );
		$plain = $this->renderer->card( get_post( 42 ), null );
		$this->assertStringContainsString( 'bfrc-card__placeholder', $plain, 'no photo: placeholder' );
		$this->assertStringContainsString( 'By <span>Eve</span>', $plain, 'an unlisted writer\'s recipe shows without an author link' );
		$this->assertStringNotContainsString( 'bfrc-card__excerpt', $plain );
	}

	public function test_grid_computes_writer_links_once_for_all_cards_and_handles_an_empty_set(): void {
		$html = $this->renderer->grid( [ get_post( 41 ), get_post( 42 ), 'junk' ] );
		$this->assertSame( 2, substr_count( $html, '<li class="bfrc-grid__item">' ) );
		$this->assertStringContainsString( 'rel="author">Ann', $html, 'Ann: Writer role, published recipe' );
		$this->assertStringContainsString( 'By <span>Eve</span>', $html, 'Eve: subscriber, not listed' );
		$this->assertCount( 1, $GLOBALS['bfrc_test_count_calls'], 'one count query for the whole grid' );
		$this->assertSame( [ [ 5, 9 ] ], $GLOBALS['bfrc_test_cached_users'], 'users primed in one go' );
		$this->assertStringContainsString( 'No recipes match', $this->renderer->grid( [] ) );
	}

	public function test_writer_links_respect_the_enabled_switch(): void {
		$this->assertSame( [ 5 => 'https://example.com/recipes/chef/ann/', 9 => null ], $this->renderer->writer_links( [ get_post( 41 ), get_post( 42 ) ] ) );
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		$this->assertSame( [ 5 => null, 9 => null ], $this->renderer->writer_links( [ get_post( 41 ), get_post( 42 ) ] ), 'disabled: no writer pages, so no links' );
		$this->assertSame( [], $this->renderer->writer_links( [] ) );
	}

	public function test_filters_render_a_select_per_taxonomy_with_terms_the_keyword_and_reset_when_filtered(): void {
		wp_insert_term( 'Vegan', 'bfrc_diet' );
		wp_insert_term( 'Dinner', 'bfrc_meal_type' );
		$_GET = [ 'bfrc_diet' => 'vegan', 'bfrc_s' => 'soup' ];
		$q    = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$html = $this->renderer->filters( $q );
		$this->assertStringContainsString( '<form class="bfrc-filters" method="get" action="https://example.com/recipes/" data-bfrc-filters role="search"', $html );
		$this->assertStringContainsString( '<select name="bfrc_meal_type"><option value="">All Meal Types</option><option value="dinner">Dinner</option></select>', $html );
		$this->assertStringContainsString( '<option value="vegan" selected="selected">Vegan</option>', $html );
		$this->assertStringNotContainsString( 'name="bfrc_season"', $html, 'a taxonomy without terms has no select' );
		$this->assertStringContainsString( 'name="bfrc_s" value="soup" maxlength="100"', $html );
		$this->assertStringContainsString( 'bfrc-filters__reset', $html );
		$_GET = [];
		$this->assertStringNotContainsString( 'bfrc-filters__reset', $this->renderer->filters( $q ), 'nothing to reset' );
		$tax                 = bfrc_test_front_query( [], 'tax' );
		$tax->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan' ] );
		$html                = $this->renderer->filters( $tax );
		$this->assertStringContainsString( 'action="https://example.com/recipes/diet/vegan/"', $html, 'a term page filters within the term' );
		$this->assertStringNotContainsString( 'name="bfrc_diet"', $html, 'the URL\'s own taxonomy is not offered again' );
	}

	public function test_archive_puts_header_filters_grid_and_pagination_together(): void {
		$q                = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'paged' => 2 ] );
		$q->posts         = [ get_post( 41 ) ];
		$q->max_num_pages = 3;
		$html             = $this->renderer->archive( $q );
		$this->assertStringContainsString( '<h1 class="bfrc-archive__title">Recipes</h1>', $html );
		$this->assertStringContainsString( 'href="https://example.com/recipes/chef/">Browse recipe writers</a>', $html );
		$this->assertStringContainsString( '<form class="bfrc-filters"', $html );
		$this->assertStringContainsString( 'bfrc-grid__item', $html );
		$this->assertStringContainsString( '<nav class="bfrc-pagination"', $html );
		$this->assertSame( 3, $GLOBALS['bfrc_test_paginate_calls'][0]['total'] );
		$this->assertSame( 2, $GLOBALS['bfrc_test_paginate_calls'][0]['current'] );
		$this->settings->update( [ 'writers_listing' => false, 'archive_title' => 'Our & Kitchen' ] + $this->settings->all() );
		$html = $this->renderer->archive( $q );
		$this->assertStringNotContainsString( 'Browse recipe writers', $html );
		$this->assertStringContainsString( 'Our &amp; Kitchen</h1>', $html );
		$tax                 = bfrc_test_front_query( [], 'tax' );
		$tax->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan', 'description' => 'No <em>animal</em> products <script>x()</script>' ] );
		$html                = $this->renderer->archive( $tax );
		$this->assertStringContainsString( '<h1 class="bfrc-archive__title">Vegan</h1>', $html );
		$this->assertStringContainsString( 'No <em>animal</em> products', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( '', $this->renderer->pagination( bfrc_test_front_query( [] ) ), 'one page: no pagination' );
	}

	public function test_archive_renders_the_writers_listing_and_a_writer_page_from_the_query_vars(): void {
		update_user_meta( 5, 'bfrc_about', 'I bake <b>bread</b> every single day of the week and more words than thirty to trim here for sure yes indeed one two three four five six seven eight nine ten eleven.' );
		update_user_meta( 5, 'bfrc_avatar_id', 77 );
		bfrc_test_add_attachment( 77 );
		$list = $this->renderer->archive( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writers' => '1', 'paged' => 1 ] ) );
		$this->assertStringContainsString( '<h1 class="bfrc-archive__title">Recipe writers</h1>', $list );
		$this->assertStringContainsString( '<li class="bfrc-writer-card">', $list );
		$this->assertStringContainsString( 'href="https://example.com/recipes/chef/ann/">Ann &lt;b&gt;Lee&lt;/b&gt;</a>', $list );
		$this->assertStringContainsString( '1 recipe</p>', $list );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', $list, 'the chosen avatar' );
		$this->assertStringContainsString( 'I bake bread every single day', $list );
		$this->assertStringContainsString( 'nine…</p>', $list, 'about trimmed to 30 words' );
		$this->assertStringNotContainsString( 'Eve', $list, 'not listed' );
		$this->assertSame( [ [ 77 ] ], $GLOBALS['bfrc_test_primed'], 'profile images primed once' );
		$q               = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$q->posts        = [ get_post( 41 ) ];
		update_user_meta( 5, 'bfrc_header_image_id', 78 );
		update_user_meta( 5, 'bfrc_website', 'https://ann.test/?a=1&b=2' );
		bfrc_test_add_attachment( 78 );
		$page = $this->renderer->archive( $q );
		$this->assertSame( $page, $this->renderer->writer_page( get_userdata( 5 ), $q ), 'archive() delegates to writer_page()' );
		$this->assertStringContainsString( '<div class="bfrc-writer__header"><img src="https://example.test/uploads/78.jpg"', $page );
		$this->assertStringContainsString( '<h1 class="bfrc-writer__name">Ann &lt;b&gt;Lee&lt;/b&gt;</h1>', $page );
		$this->assertStringContainsString( '<div class="bfrc-writer__about"><p>I bake <b>bread</b>', $page, 'about keeps post-safe HTML' );
		$this->assertStringContainsString( 'href="https://ann.test/?a=1&amp;b=2" rel="noopener nofollow me" target="_blank">Website</a>', $page );
		$this->assertStringContainsString( 'Recipes by Ann &lt;b&gt;Lee&lt;/b&gt;</h2>', $page );
		$this->assertStringContainsString( 'bfrc-grid__item', $page );
		$this->assertSame( '', $this->renderer->archive( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'eve' ], 'author' ) ), 'no public page: nothing (the guard already 404ed)' );
	}

	public function test_writers_list_paginates_and_says_when_empty(): void {
		$GLOBALS['bfrc_test_users'] = [];
		$this->assertStringContainsString( 'No recipe writers yet.', $this->renderer->writers_list( 1 ) );
		for ( $i = 1; $i <= 25; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'W%02d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		$html = $this->renderer->writers_list( 2 );
		$this->assertSame( 1, substr_count( $html, '<li class="bfrc-writer-card">' ) );
		$call = $GLOBALS['bfrc_test_paginate_calls'][0];
		$this->assertSame( 'https://example.com/recipes/chef/%_%', $call['base'] );
		$this->assertSame( 'page/%#%/', $call['format'] );
		$this->assertSame( [ 2, 2 ], [ $call['total'], $call['current'] ] );
	}

	public function test_single_renders_every_section_the_seams_and_the_share_buttons(): void {
		$this->recipe_meta( 41 );
		update_post_meta( 41, '_thumbnail_id', 77 );
		bfrc_test_add_attachment( 77 );
		wp_set_object_terms( 41, [ 'Vegan' ], 'bfrc_diet' );
		foreach ( [ 'bfrc_render_ingredient_actions', 'bfrc_single_after_steps', 'bfrc_single_after_nutrition', 'bfrc_single_footer' ] as $seam ) {
			add_action( $seam, static function ( int $id ) use ( $seam ): void {
				echo '<i data-seam="' . esc_attr( $seam ) . '">' . $id . '</i>';
			} );
		}
		add_filter( 'bfrc_ingredient_label_html', static fn( string $html, array $row ): string => 'internal' === $row['type'] ? '<a href="https://shop.test/salt">' . $html . '</a>' : $html, 10, 2 );
		$html = $this->renderer->single( get_post( 41 ) );
		$this->assertStringContainsString( '<article class="bfrc-recipe bfrc-recipe--featured" id="bfrc-recipe-41">', $html );
		$this->assertStringContainsString( '<h1 class="bfrc-recipe__title">Tomato &quot;Soup&quot;</h1>', $html );
		$this->assertStringContainsString( 'rel="author">Ann', $html );
		$this->assertStringContainsString( '<dt>Total</dt><dd>1 hr 35 min</dd>', $html );
		$this->assertStringContainsString( '<span class="bfrc-recipe__terms-label">Diet:</span> <a href="https://example.com/recipes/diet/vegan/" rel="tag">Vegan</a>', $html );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', $html, 'photo hero' );
		$this->assertStringContainsString( '<p class="bfrc-recipe__summary">A warm bowl &amp; more.</p>', $html );
		$this->assertStringContainsString( '<li class="bfrc-ingredient bfrc-ingredient--plain"><span class="bfrc-ingredient__measure">2 cups</span> <span class="bfrc-ingredient__label">flour &amp; co</span></li>', $html );
		$this->assertStringContainsString( '<li class="bfrc-ingredient bfrc-ingredient--internal"><span class="bfrc-ingredient__label"><a href="https://shop.test/salt">salt</a></span></li>', $html, 'the add-on may link a row' );
		$this->assertStringContainsString( '<li class="bfrc-step">Mix <em>well</em></li><li class="bfrc-step">Bake</li>', $html );
		$this->assertStringContainsString( '<th scope="row">Calories</th><td>250 kcal</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Protein</th><td>12.5 g</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Sodium</th><td>0.25 mg</td>', $html );
		$this->assertStringNotContainsString( 'Fat</th>', $html, 'empty fields hidden' );
		foreach ( [ 'bfrc_render_ingredient_actions', 'bfrc_single_after_steps', 'bfrc_single_after_nutrition', 'bfrc_single_footer' ] as $seam ) {
			$this->assertStringContainsString( '<i data-seam="' . $seam . '">41</i>', $html, $seam );
		}
		$this->assertStringContainsString( 'https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fexample.com%2F%3Fp%3D41', $html );
		$this->assertStringContainsString( 'https://twitter.com/intent/tweet?url=', $html );
		$this->assertStringContainsString( '&amp;media=https%3A%2F%2Fexample.test%2Fuploads%2F77.jpg', $html, 'Pinterest media = the recipe photo' );
		$this->assertStringContainsString( 'target="_blank" rel="noopener nofollow"', $html );
		$this->assertStringContainsString( 'data-bfrc-print', $html );
		$this->assertStringNotContainsString( 'bfrc-recipe__content', $html, 'no body content: no block' );
	}

	public function test_single_shows_the_video_embed_instead_of_the_photo_and_honours_share_settings(): void {
		update_post_meta( 41, Meta::MEDIA_TYPE, 'video' );
		update_post_meta( 41, Meta::VIDEO_URL, 'https://vimeo.com/123456789/abc123' );
		$this->settings->update( [ 'share_x' => false, 'share_facebook' => false ] + $this->settings->all() );
		$html = $this->renderer->single( get_post( 41 ) );
		$this->assertStringContainsString( '<iframe src="https://player.vimeo.com/video/123456789?h=abc123" title="Tomato &quot;Soup&quot;" loading="lazy"', $html );
		$this->assertStringNotContainsString( 'bfrc-recipe__ingredients', $html, 'no rows: no section' );
		$this->assertStringContainsString( 'bfrc-share__link--pinterest', $html );
		$this->assertStringNotContainsString( 'bfrc-share__link--x', $html );
		$this->assertStringNotContainsString( 'bfrc-share__link--facebook', $html );
		$this->settings->update( [ 'share_enabled' => false ] + $this->settings->all() );
		$this->assertStringNotContainsString( 'bfrc-share', $this->renderer->single( get_post( 41 ) ) );
	}

	public function test_badges_hero_term_badges_and_section_helpers_handle_empty_data(): void {
		$data = RecipeData::for_post( 42 );
		$this->assertSame( '', $this->renderer->badges( $data, true ) );
		$this->assertSame( '', $this->renderer->term_badges( get_post( 42 ) ) );
		$this->assertSame( '', $this->renderer->ingredients( $data ) );
		$this->assertSame( '', $this->renderer->steps( $data ) );
		$this->assertSame( '', $this->renderer->nutrition( $data ) );
		$this->assertSame( '', $this->renderer->hero( get_post( 42 ), $data ), 'no photo, no video' );
		$this->assertSame( '', $this->renderer->share_buttons( get_post( 42 ) ) === '' ? 'x' : '', 'share buttons render even without a photo' );
		$this->assertSame( 'By <span>Eve</span>', $this->renderer->author_line( get_post( 42 ), null ) );
		bfrc_test_add_post( [ 'ID' => 43, 'post_type' => 'bfrc_recipe', 'post_author' => 0 ] );
		$this->assertSame( '', $this->renderer->author_line( get_post( 43 ), null ), 'no author' );
	}

	public function test_format_amount(): void {
		$this->assertSame( '250', Renderer::format_amount( 250.0 ) );
		$this->assertSame( '12.5', Renderer::format_amount( 12.5 ) );
		$this->assertSame( '0.25', Renderer::format_amount( 0.25 ) );
		$this->assertSame( '1,200', Renderer::format_amount( 1200.0 ) );
	}
}
