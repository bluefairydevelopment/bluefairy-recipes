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
		foreach ( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$GLOBALS['bfrc_test_taxonomies'][ $tax ] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		}
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann <b>Lee</b>', 'user_nicename' => 'ann' ] );
		bfrc_test_add_user( 9, [ 'roles' => [ 'subscriber' ], 'display_name' => 'Eve', 'user_nicename' => 'eve' ] );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Tomato "Soup"', 'post_name' => 'tomato-soup', 'post_excerpt' => 'A <em>warm</em> bowl & more.' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 9, 'post_title' => 'Bread', 'post_name' => 'bread' ] );
	}

	private function recipe_meta( int $id ): void {
		add_filter( 'bfrc_ingredient_types', static fn( array $t ): array => array_merge( $t, [ 'internal' ] ) );
		update_post_meta( $id, Meta::PREP_MINUTES, 20 );
		update_post_meta( $id, Meta::COOK_MINUTES, 75 );
		update_post_meta( $id, Meta::SERVINGS_MIN, 2 );
		update_post_meta( $id, Meta::SERVINGS_MAX, 4 );
		update_post_meta( $id, Meta::FEATURED, true );
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
		$this->assertStringContainsString( '<h2 class="bfrc-card__title"><a href="https://example.com/?p=41">', $html, 'h2 under the archive\'s h1' );
		$this->assertStringContainsString( '<h3 class="bfrc-card__title">', $this->renderer->card( get_post( 41 ), null, 3 ), 'h3 under a writer page\'s "Recipes by" h2' );
		$this->assertStringContainsString( '<h6 class="bfrc-card__title">', $this->renderer->card( get_post( 41 ), null, 9 ), 'clamped' );
		$this->assertStringContainsString( 'href="https://example.com/?p=41"', $html );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', $html );
		$this->assertStringContainsString( 'Tomato &quot;Soup&quot;</a></h2>', $html );
		$this->assertStringContainsString( '<dt>Prep</dt><dd>20 min</dd>', $html );
		$this->assertStringContainsString( '<dt>Cook</dt><dd>1 hr 15 min</dd>', $html );
		$this->assertStringNotContainsString( '<dt>Total</dt>', $html, 'cards keep it short' );
		$this->assertStringContainsString( '<dt>Servings</dt><dd>2–4</dd>', $html, 'not "Serves: 2–4 servings"' );
		$this->assertStringContainsString( 'By <a href="https://example.com/recipes/chef/ann/" rel="author">Ann &lt;b&gt;Lee&lt;/b&gt;</a>', $html );
		$this->assertStringContainsString( '<p class="bfrc-card__excerpt">A warm bowl &amp; more.</p>', $html, 'excerpt stripped of tags, escaped' );
		$plain = $this->renderer->card( get_post( 42 ), null );
		$this->assertStringContainsString( 'bfrc-card__placeholder', $plain, 'no photo: placeholder' );
		$this->assertStringContainsString( 'By <span>Eve</span>', $plain, 'an unlisted writer\'s recipe shows without an author link' );
		$this->assertStringNotContainsString( 'bfrc-card__excerpt', $plain );
		bfrc_test_add_post( [ 'ID' => 43, 'post_type' => 'bfrc_recipe', 'post_author' => 99999, 'post_title' => 'Orphan' ] );
		$this->assertStringNotContainsString( 'bfrc-card__writer', $this->renderer->card( get_post( 43 ), null ), 'a deleted author: no empty writer line' );
	}

	public function test_grid_primes_thumbnails_and_writer_links_once_for_all_cards_and_handles_an_empty_set(): void {
		update_post_meta( 41, '_thumbnail_id', 77 );
		update_post_meta( 42, '_thumbnail_id', 78 );
		bfrc_test_add_attachment( 77 );
		bfrc_test_add_attachment( 78 );
		$html = $this->renderer->grid( [ get_post( 41 ), get_post( 42 ), 'junk' ] );
		$this->assertSame( 2, substr_count( $html, '<li class="bfrc-grid__item">' ) );
		$this->assertStringContainsString( 'rel="author">Ann', $html, 'Ann: Writer role, published recipe' );
		$this->assertStringContainsString( 'By <span>Eve</span>', $html, 'Eve: subscriber, not listed' );
		$this->assertCount( 1, $GLOBALS['bfrc_test_count_calls'], 'one count query for the whole grid' );
		$this->assertSame( [ [ 5, 9 ] ], $GLOBALS['bfrc_test_cached_users'], 'users primed in one go' );
		$this->assertSame( [ [ 77, 78 ] ], $GLOBALS['bfrc_test_primed'], 'thumbnails primed in one go (core only primes inside the loop)' );
		$this->assertSame( [ [ [ 77, 78 ], false, true ] ], $GLOBALS['bfrc_test_prime_args'], 'posts and meta (the image sizes), no term cache for attachments' );
		$this->assertStringContainsString( 'No recipes match', $this->renderer->grid( [] ) );
	}

	public function test_a_password_protected_card_shows_the_title_and_photo_and_none_of_the_fields(): void {
		$this->recipe_meta( 41 );
		update_post_meta( 41, '_thumbnail_id', 77 );
		bfrc_test_add_attachment( 77 );
		$GLOBALS['bfrc_test_posts'][41]->post_password = 'hunter2';
		$html                                           = $this->renderer->card( get_post( 41 ), 'https://example.com/recipes/chef/ann/' );
		$this->assertStringContainsString( '<article class="bfrc-card bfrc-card--featured bfrc-card--protected">', $html );
		$this->assertStringContainsString( 'Tomato &quot;Soup&quot;</a></h2>', $html );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', $html, 'the photo shows, as in core\'s own loops' );
		$this->assertStringContainsString( 'rel="author">Ann', $html );
		foreach ( [ '<dl', 'Prep', '20 min', 'Servings', 'bfrc-card__excerpt', 'warm bowl' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $html, "$leak must not leak from a locked recipe" );
		}
		$GLOBALS['bfrc_test_password_unlocked'][41] = true;
		$open                                       = $this->renderer->card( get_post( 41 ), null );
		$this->assertStringContainsString( '<dt>Prep</dt><dd>20 min</dd>', $open, 'unlocked (cookie): the fields are back' );
		$this->assertStringContainsString( 'bfrc-card__excerpt', $open );
		$this->assertStringNotContainsString( 'bfrc-card--protected', $open );
		$forced = $this->renderer->card( get_post( 41 ), null, 2, true );
		$this->assertStringContainsString( 'bfrc-card--protected', $forced, 'the REST endpoint forces the locked card whatever the cookie says' );
		$this->assertStringNotContainsString( '20 min', $forced );
		$this->assertSame( $html, $this->renderer->card( get_post( 41 ), 'https://example.com/recipes/chef/ann/', 2, true ), 'forced locked is the locked card, byte for byte' );
		$this->assertStringNotContainsString( 'bfrc-card--protected', $this->renderer->card( get_post( 41 ), null, 2, false ), 'and false forces it open' );
	}

	public function test_prime_thumbnails_primes_every_photo_once_without_terms(): void {
		update_post_meta( 41, '_thumbnail_id', 77 );
		update_post_meta( 42, '_thumbnail_id', 77 );
		$this->renderer->prime_thumbnails( [ get_post( 41 ), get_post( 42 ), 'not a post' ] );
		$this->assertSame( [ [ [ 77 ], false, true ] ], $GLOBALS['bfrc_test_prime_args'], 'one id, no term cache, the meta cache' );
		$this->renderer->prime_thumbnails( [ get_post( 43 ) ?? new \WP_Post( [ 'ID' => 43 ] ) ] );
		$this->assertCount( 1, $GLOBALS['bfrc_test_prime_args'], 'nothing to prime: no call' );
	}

	public function test_grid_empty_line_is_the_text_the_store_config_carries(): void {
		$this->assertSame( '<p class="bfrc-empty">' . esc_html( Renderer::empty_text() ) . '</p>', $this->renderer->grid( [] ) );
		$this->assertSame( 'No recipes match. Try fewer filters.', Renderer::empty_text() );
	}

	public function test_terms_by_taxonomy_asks_nothing_when_every_taxonomy_is_off(): void {
		$taxonomies = [];
		foreach ( [ 'meal_type', 'diet', 'season', 'cuisine' ] as $code ) {
			$taxonomies[ $code ] = [ 'enabled' => false, 'sort_order' => 10 ];
		}
		$this->settings->update( [ 'taxonomies' => $taxonomies ] + $this->settings->all() );
		$renderer = new Renderer( $this->settings, $this->writers, new Taxonomies( $this->settings ) );
		$this->assertSame( [], $renderer->terms_by_taxonomy() );
		$this->assertSame( [], $GLOBALS['bfrc_test_get_terms_calls'], 'get_terms( taxonomy => [] ) would load every term on the site' );
		$this->assertStringNotContainsString( '<select', $renderer->filters( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ), 'keyword search only' );
	}

	public function test_writer_links_respect_the_enabled_switch(): void {
		$this->assertSame( [ 5 => 'https://example.com/recipes/chef/ann/', 9 => null ], $this->renderer->writer_links( [ get_post( 41 ), get_post( 42 ) ] ) );
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		$this->assertSame( [ 5 => null, 9 => null ], $this->renderer->writer_links( [ get_post( 41 ), get_post( 42 ) ] ), 'disabled: no writer pages, so no links' );
		$this->assertSame( [], $this->renderer->writer_links( [] ) );
	}

	public function test_filters_use_their_own_field_names_one_terms_query_and_reset_when_filtered(): void {
		wp_insert_term( 'Vegan', 'bfrc_diet' );
		wp_insert_term( 'Dinner', 'bfrc_meal_type' );
		$_GET = [ 'bfrc_filter' => [ 'diet' => 'vegan' ], 'bfrc_s' => 'soup' ];
		$q    = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$html = $this->renderer->filters( $q );
		$this->assertStringContainsString( '<form class="bfrc-filters" method="get" action="https://example.com/recipes/" data-bfrc-filters role="search"', $html );
		$this->assertStringContainsString( '<select name="bfrc_filter[meal_type]"><option value="">All Meal Types</option><option value="dinner">Dinner</option></select>', $html, 'not the taxonomy\'s query var: core would turn the choice into a term page' );
		$this->assertStringContainsString( '<select name="bfrc_filter[diet]"><option value="">All Diets</option><option value="vegan" selected="selected">Vegan</option></select>', $html );
		$this->assertStringNotContainsString( 'bfrc_filter[season]', $html, 'a taxonomy without terms has no select' );
		$this->assertStringContainsString( 'name="bfrc_s" value="soup" maxlength="100"', $html );
		$this->assertStringContainsString( 'bfrc-filters__reset', $html );
		$calls = $GLOBALS['bfrc_test_get_terms_calls'];
		$this->assertCount( 1, $calls, 'one get_terms for every enabled taxonomy' );
		$this->assertSame( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ], $calls[0]['taxonomy'] );
		$this->assertTrue( $calls[0]['hide_empty'] );
		$this->assertSame( Renderer::TERMS_LIMIT * 4, $calls[0]['number'], 'bounded' );
		$_GET = [];
		$this->assertStringNotContainsString( 'bfrc-filters__reset', $this->renderer->filters( $q ), 'nothing to reset' );
		$tax                 = bfrc_test_front_query( [], 'tax' );
		$tax->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan' ] );
		$html                = $this->renderer->filters( $tax );
		$this->assertStringContainsString( 'action="https://example.com/recipes/diet/vegan/"', $html, 'a term page filters within the term' );
		$this->assertStringNotContainsString( 'bfrc_filter[diet]', $html, 'the URL\'s own taxonomy is not offered again' );
	}

	public function test_terms_by_taxonomy_caps_each_taxonomy_and_copes_with_errors(): void {
		for ( $i = 1; $i <= Renderer::TERMS_LIMIT + 5; $i++ ) {
			wp_insert_term( 'Diet ' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ), 'bfrc_diet' );
		}
		wp_insert_term( 'Lunch', 'bfrc_meal_type' );
		$terms = $this->renderer->terms_by_taxonomy();
		$this->assertCount( Renderer::TERMS_LIMIT, $terms['bfrc_diet'] );
		$this->assertCount( 1, $terms['bfrc_meal_type'] );
		$this->assertArrayNotHasKey( 'bfrc_season', $terms );
		unset( $GLOBALS['bfrc_test_taxonomies']['bfrc_meal_type'] );
		$this->assertSame( [], $this->renderer->terms_by_taxonomy(), 'an unregistered taxonomy makes get_terms() return WP_Error: no filters, no fatal' );
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
		$tax->queried_object = new \WP_Term( [ 'term_id' => 3, 'taxonomy' => 'bfrc_diet', 'slug' => 'vegan', 'name' => 'Vegan', 'description' => "No <em>animal</em> products <script>x()</script>\n\nNone at all." ] );
		$GLOBALS['bfrc_test_terms']['bfrc_diet'][3] = $tax->queried_object; // term_description() looks the term up by id, as core does.
		$html                = $this->renderer->archive( $tax );
		$this->assertStringContainsString( '<h1 class="bfrc-archive__title">Vegan</h1>', $html );
		$this->assertStringContainsString( '<p>No <em>animal</em> products </p><p>None at all.</p>', $html, 'core\'s term_description() filters (paragraphs) apply, kses strips the script' );
		$this->assertStringNotContainsString( '<script>', $html );
		$bare                 = bfrc_test_front_query( [], 'tax' );
		$bare->queried_object = new \WP_Term( [ 'term_id' => 4, 'taxonomy' => 'bfrc_diet', 'slug' => 'keto', 'name' => 'Keto', 'description' => '' ] );
		$GLOBALS['bfrc_test_terms']['bfrc_diet'][4] = $bare->queried_object;
		$this->assertStringNotContainsString( 'bfrc-archive__description', $this->renderer->archive( $bare ), 'no description, no empty box' );
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
		$q        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$q->posts = [ get_post( 41 ) ];
		update_user_meta( 5, 'bfrc_header_image_id', 78 );
		update_user_meta( 5, 'bfrc_website', 'https://ann.test/?a=1&b=2' );
		update_user_meta( 5, 'bfrc_youtube', 'javascript:alert(1)' );
		bfrc_test_add_attachment( 78 );
		$this->writers->reset();
		\BlueFairy\Recipes\Front\Writers::bump(); // core's user_register hook, which the stubs do not fire.
		$page = $this->renderer->archive( $q );
		$this->assertSame( $page, $this->renderer->writer_page( get_userdata( 5 ), $q ), 'archive() delegates to writer_page()' );
		$this->assertStringContainsString( '<div class="bfrc-writer__header"><img src="https://example.test/uploads/78.jpg"', $page );
		$this->assertStringContainsString( '<h1 class="bfrc-writer__name">Ann &lt;b&gt;Lee&lt;/b&gt;</h1>', $page );
		$this->assertStringContainsString( '<div class="bfrc-writer__about"><p>I bake <b>bread</b>', $page, 'about keeps post-safe HTML' );
		$this->assertStringContainsString( 'href="https://ann.test/?a=1&#038;b=2" rel="noopener nofollow me" target="_blank">Website<span class="screen-reader-text"> (opens in a new tab)</span></a>', $page );
		$this->assertStringNotContainsString( 'javascript:', $page, 'a link that bypassed the profile sanitiser is dropped' );
		$this->assertStringNotContainsString( 'YouTube', $page );
		$this->assertStringContainsString( 'Recipes by Ann &lt;b&gt;Lee&lt;/b&gt;</h2>', $page );
		$this->assertStringContainsString( 'bfrc-grid__item', $page );
		$this->assertStringContainsString( '<h3 class="bfrc-card__title">', $page, 'cards under the "Recipes by" h2 are h3' );
		$this->assertStringNotContainsString( '<h2 class="bfrc-card__title">', $page );
		$this->assertSame( '', $this->renderer->archive( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'eve' ], 'author' ) ), 'no public page: nothing (the guard already 404ed)' );
	}

	public function test_without_pretty_permalinks_the_filter_form_keeps_the_archive_query_and_writers_page_by_paged(): void {
		wp_insert_term( 'Vegan', 'bfrc_diet' );
		$structure                                   = $GLOBALS['wp_rewrite']->permalink_structure;
		$GLOBALS['wp_rewrite']->permalink_structure = '';
		try {
			$html = $this->renderer->filters( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) );
			$this->assertStringContainsString( 'method="get" action="https://example.com/"', $html, 'the query string is not left in the action, where a GET form drops it' );
			$this->assertStringContainsString( '<input type="hidden" name="post_type" value="bfrc_recipe">', $html, 'the archive is carried as a field' );
			for ( $i = 1; $i <= 25; $i++ ) {
				bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'W%02d', $i ), 'user_nicename' => 'w' . $i ] );
				bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
			}
			$this->writers->reset();
			\BlueFairy\Recipes\Front\Writers::bump();
			$this->renderer->writers_list( 2 );
			$call = $GLOBALS['bfrc_test_paginate_calls'][0];
			$this->assertSame( 'https://example.com/?post_type=bfrc_recipe&bfrc_writers=1&paged=%#%', $call['base'], 'core\'s ?paged=N of the listing, not /page/N/ glued onto a query string' );
			$this->assertSame( '', $call['format'] );
		} finally {
			$GLOBALS['wp_rewrite']->permalink_structure = $structure;
		}
	}

	public function test_writers_list_paginates_and_says_when_empty(): void {
		$GLOBALS['bfrc_test_users'] = [];
		$this->assertStringContainsString( 'No recipe writers yet.', $this->renderer->writers_list( 1 ) );
		for ( $i = 1; $i <= 25; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'W%02d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		$this->writers->reset();
		\BlueFairy\Recipes\Front\Writers::bump(); // core's user_register hook, which the stubs do not fire.
		$html = $this->renderer->writers_list( 2 );
		$this->assertSame( 1, substr_count( $html, '<li class="bfrc-writer-card">' ) );
		$call = $GLOBALS['bfrc_test_paginate_calls'][0];
		$this->assertSame( 'https://example.com/recipes/chef/%_%', $call['base'] );
		$this->assertSame( 'page/%#%/', $call['format'] );
		$this->assertSame( [ 2, 2 ], [ $call['total'], $call['current'] ] );
		$this->assertStringContainsString( '<h1 class="bfrc-archive__title">Recipe writers</h1>', $html );
		$bare = $this->renderer->writers_list( 2, false, 'https://example.com/cook/' );
		$this->assertStringStartsWith( '<div class="bfrc-writers"><ul class="bfrc-writers__grid">', $bare, 'inside a page: no heading, no "All recipes" link' );
		$this->assertStringNotContainsString( 'All recipes', $bare );
		$call = $GLOBALS['bfrc_test_paginate_calls'][1];
		$this->assertSame( 'https://example.com/cook/?bfrc_page=%#%', $call['base'], 'paged on the page that holds it' );
		$this->assertSame( '', $call['format'] );
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
		add_filter( 'bfrc_ingredient_label_html', static fn( string $html, array $row ): string => 'internal' === $row['type'] ? '<a href="https://shop.test/salt">' . $html . '</a><script>x()</script>' : $html, 10, 2 );
		$html = $this->renderer->single( get_post( 41 ) );
		$this->assertStringContainsString( '<article class="bfrc-recipe bfrc-recipe--featured" id="bfrc-recipe-41">', $html );
		$this->assertStringContainsString( '<h1 class="bfrc-recipe__title">Tomato &quot;Soup&quot;</h1>', $html );
		$this->assertStringContainsString( 'rel="author">Ann', $html );
		$this->assertStringContainsString( '<dt>Total</dt><dd>1 hr 35 min</dd>', $html );
		$this->assertStringContainsString( '<span class="bfrc-recipe__terms-label">Diet:</span> <a href="https://example.com/recipes/diet/vegan/" rel="tag">Vegan</a>', $html );
		$this->assertStringContainsString( '<div class="bfrc-recipe__hero"><img src="https://example.test/uploads/77.jpg"', $html, 'photo hero' );
		$this->assertStringContainsString( '<p class="bfrc-recipe__summary">A warm bowl &amp; more.</p>', $html );
		$this->assertStringContainsString( '<li class="bfrc-ingredient bfrc-ingredient--plain"><span class="bfrc-ingredient__measure">2 cups</span> <span class="bfrc-ingredient__label">flour &amp; co</span></li>', $html );
		$this->assertStringContainsString( '<li class="bfrc-ingredient bfrc-ingredient--internal"><span class="bfrc-ingredient__label"><a href="https://shop.test/salt">salt</a></span></li>', $html, 'the add-on may link a row; its script does not survive kses' );
		$this->assertStringContainsString( '<ol class="bfrc-steps"><li class="bfrc-step" id="bfrc-step-1">Mix <em>well</em></li><li class="bfrc-step" id="bfrc-step-2">Bake</li></ol></section><i data-seam="bfrc_single_after_steps">41</i>', $html, 'the seam fires after the section, not inside it' );
		$this->assertStringContainsString( '<th scope="row">Calories</th><td>250 kcal</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Protein</th><td>12.5 g</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Sodium</th><td>0.25 mg</td>', $html );
		$this->assertStringNotContainsString( 'Fat</th>', $html, 'empty fields hidden' );
		foreach ( [ 'bfrc_render_ingredient_actions', 'bfrc_single_after_steps', 'bfrc_single_after_nutrition', 'bfrc_single_footer' ] as $seam ) {
			$this->assertStringContainsString( '<i data-seam="' . $seam . '">41</i>', $html, $seam );
		}
		$this->assertStringContainsString( '<nav class="bfrc-share" aria-label="Share this recipe">', $html );
		$this->assertStringContainsString( 'https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fexample.com%2F%3Fp%3D41', $html );
		$this->assertStringContainsString( 'https://twitter.com/intent/tweet?url=', $html );
		$this->assertStringContainsString( '&#038;media=https%3A%2F%2Fexample.test%2Fuploads%2F77.jpg', $html, 'Pinterest media = the recipe photo' );
		$this->assertStringContainsString( 'target="_blank" rel="noopener nofollow">Share on X<span class="screen-reader-text"> (opens in a new tab)</span></a>', $html );
		$this->assertStringContainsString( '<button type="button" class="bfrc-button bfrc-button--print" data-bfrc-print hidden>', $html, 'hidden until the script shows it: without JavaScript it would do nothing' );
		$this->assertStringNotContainsString( 'bfrc-recipe__content', $html, 'no body content: no block' );
	}

	public function test_the_seams_after_steps_and_nutrition_fire_on_a_recipe_without_either(): void {
		foreach ( [ 'bfrc_render_ingredient_actions', 'bfrc_single_after_steps', 'bfrc_single_after_nutrition', 'bfrc_single_footer' ] as $seam ) {
			add_action( $seam, static function ( int $id ) use ( $seam ): void {
				echo '<i data-seam="' . esc_attr( $seam ) . '">' . $id . '</i>';
			} );
		}
		$html = $this->renderer->single( get_post( 42 ) );
		$this->assertStringNotContainsString( 'bfrc-steps', $html );
		$this->assertStringNotContainsString( 'bfrc-nutrition', $html );
		$this->assertStringContainsString( '<i data-seam="bfrc_single_after_steps">42</i>', $html, 'the add-on\'s blocks appear where the method would be' );
		$this->assertStringContainsString( '<i data-seam="bfrc_single_after_nutrition">42</i>', $html );
		$this->assertStringContainsString( '<i data-seam="bfrc_single_footer">42</i>', $html );
		$this->assertStringNotContainsString( 'bfrc_render_ingredient_actions', $html, 'the ingredient actions seam needs ingredients to act on' );
	}

	public function test_has_body_counts_text_or_media_but_not_an_empty_paragraph_block(): void {
		$this->assertFalse( Renderer::has_body( '' ) );
		$this->assertFalse( Renderer::has_body( "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->" ), 'the editor\'s leftover' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:paragraph --><p>&nbsp; </p><!-- /wp:paragraph --><!-- wp:spacer --><div style="height:10px" class="wp-block-spacer"></div><!-- /wp:spacer -->' ) );
		$this->assertTrue( Renderer::has_body( '<!-- wp:paragraph --><p>Serve warm.</p><!-- /wp:paragraph -->' ) );
		$this->assertTrue( Renderer::has_body( 'plain text' ) );
		$this->assertTrue( Renderer::has_body( '<!-- wp:image --><figure class="wp-block-image"><img src="https://x/a.jpg" alt=""/></figure><!-- /wp:image -->' ), 'media alone is a body' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:embed {"url":"https://x"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://x</div></figure><!-- /wp:embed -->' ) );
		// Dynamic blocks carry no static HTML: their comment alone is the body.
		$this->assertTrue( Renderer::has_body( '<!-- wp:block {"ref":12} /-->' ), 'a synced pattern (the owner\'s disclosure block on every recipe)' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:latest-posts {"postsToShow":3} /-->' ) );
		$this->assertTrue( Renderer::has_body( '<!-- wp:query {"queryId":1} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->' ), 'a Query Loop with no static text' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:shortcode -->[gallery]<!-- /wp:shortcode -->' ) );
		$this->assertTrue( Renderer::has_body( '<!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:block {"ref":12} /-->' ), 'an empty paragraph before a pattern' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:spacer --><div style="height:10px" class="wp-block-spacer"></div><!-- /wp:spacer --><!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->' ), 'spacing alone is not a body' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:heading --><h2 class="wp-block-heading"></h2><!-- /wp:heading --><!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li></li><!-- /wp:list-item --></ul><!-- /wp:list -->' ), 'an empty heading and an empty list the editor left behind' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p></p><!-- /wp:paragraph --></div><!-- /wp:group -->' ), 'a group around an empty paragraph' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:group --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- /wp:group -->' ), 'a container with children and no HTML of its own is judged by its children, not taken as dynamic' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:paragraph --><p>&#160;&#8203;</p><!-- /wp:paragraph -->' ), 'numeric entities for a non-breaking and a zero-width space' );
		$this->assertFalse( Renderer::has_body( "<!-- wp:paragraph --><p>\u{200B}\u{2003}</p><!-- /wp:paragraph -->" ), 'raw zero-width and em spaces' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:image --><figure class="wp-block-image"></figure><!-- /wp:image -->' ), 'an empty figure holds no media' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:paragraph --><p><!-- <video> --></p><!-- /wp:paragraph -->' ), 'an HTML comment naming a tag is not media' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:paragraph --><p>&#169; 2026</p><!-- /wp:paragraph -->' ), 'an entity that is text' );
		$this->assertFalse( Renderer::has_body( '<!-- wp:paragraph --><p>&ZeroWidthSpace;&NoBreak;</p><!-- /wp:paragraph -->' ), 'named HTML5 entities for invisible characters' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:paragraph --><p>&amp;</p><!-- /wp:paragraph -->' ), 'an ampersand is text' );
		$this->assertTrue( Renderer::has_body( "<!-- wp:paragraph --><p>Bad \xFF byte</p><!-- /wp:paragraph -->" ), 'invalid UTF-8 (legacy content) does not hide the body: preg gives up, the plain text decides' );
		$this->assertTrue( Renderer::has_body( "<!-- wp:paragraph --><p> \xFF </p><!-- /wp:paragraph -->" ), 'an invalid byte alone is still text to keep, not whitespace to drop' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Inside.</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ), 'a group with text' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:group --><div class="wp-block-group"><!-- wp:block {"ref":12} /--></div><!-- /wp:group -->' ), 'a group around a synced pattern' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->' ) );
		$GLOBALS['bfrc_test_block_types'] = [
			'core/html'  => new \WP_Block_Type( 'core/html' ),
			'core/block' => new \WP_Block_Type( 'core/block', [ 'render_callback' => static fn(): string => '' ] ),
		];
		$this->assertFalse( Renderer::has_body( '<!-- wp:html /-->' ), 'an empty Custom HTML block is static and empty: not a body' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:block {"ref":12} /-->' ), 'a synced pattern renders on request' );
		$this->assertTrue( Renderer::has_body( '<!-- wp:bfrcp/reviews /-->' ), 'a block this site did not register (an add-on\'s) is taken as dynamic');
		$GLOBALS['bfrc_test_block_types'] = [];
		$this->assertSame( [ 'core/group' ], array_column( parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ), 'blockName' ), 'the harness parser nests children like core' );
		$this->assertSame( 'core/paragraph', parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->' )[0]['innerBlocks'][0]['blockName'] );
		// Classic (non-block) content.
		$this->assertTrue( Renderer::has_body( '<img src="https://x/a.jpg">' ), 'a classic-editor image without a figure' );
		$this->assertTrue( Renderer::has_body( '<IMG SRC="https://x/a.jpg">' ), 'case-insensitive' );
		$this->assertFalse( Renderer::has_body( "<p>\xC2\xA0</p>\n<p>&nbsp;</p>" ), 'raw and entity non-breaking spaces' );
		$this->assertFalse( Renderer::has_body( "<!-- wp:paragraph -->\n<p>\n\n</p>\n<!-- /wp:paragraph -->" ), 'newlines inside the block' );
		$this->assertTrue( Renderer::has_body( "<!-- wp:paragraph -->\n<p>text</p>\n<!-- /wp:paragraph -->" ) );
		add_filter( 'the_content', static fn( string $c ): string => $c . '<aside>promo</aside>' );
		$GLOBALS['bfrc_test_posts'][42]->post_content = "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
		$this->assertStringNotContainsString( 'promo', $this->renderer->single( get_post( 42 ) ), 'an empty paragraph block is not a body: the_content does not run' );
	}

	public function test_single_renders_once_when_its_own_body_asks_for_the_recipe_again(): void {
		$renderer                                     = $this->renderer;
		$GLOBALS['bfrc_test_posts'][41]->post_content = '<!-- wp:paragraph --><p>Serve warm.</p><!-- /wp:paragraph --><!-- wp:bfrc/recipe-single /-->';
		$depth                                        = 0;
		add_filter( 'the_content', static function ( string $c ) use ( $renderer, &$depth ): string {
			++$depth; // do_blocks() rendering the Single Recipe block inside the body.
			return $c . $renderer->single( get_post( 41 ) );
		} );
		$this->assertFalse( Renderer::is_rendering_single() );
		$html = $renderer->single( get_post( 41 ) );
		$this->assertSame( 1, $depth, 'the nested render returned at once' );
		$this->assertSame( 1, substr_count( $html, 'id="bfrc-recipe-41"' ) );
		$this->assertStringContainsString( '<p>Serve warm.</p>', $html );
		$this->assertFalse( Renderer::is_rendering_single(), 'released afterwards' );
		$this->assertFalse( Renderer::is_rendering_single(), 'and after a protected recipe, which never sets it' );
	}

	public function test_single_handles_body_content_a_missing_hero_and_a_deleted_author(): void {
		add_filter( 'the_content', static fn( string $c ): string => $c . '<aside>promo</aside>' );
		$this->assertStringNotContainsString( 'promo', $this->renderer->single( get_post( 42 ) ), 'other plugins\' the_content additions do not decorate an empty body' );
		$this->assertStringNotContainsString( 'bfrc-recipe__hero', $this->renderer->single( get_post( 42 ) ), 'no photo, no video: no empty hero box' );
		$GLOBALS['bfrc_test_posts'][42]->post_content = '<figure class="wp-block-image"><img src="https://x/a.jpg" alt=""></figure>';
		$with = $this->renderer->single( get_post( 42 ) );
		$this->assertStringContainsString( '<div class="bfrc-recipe__content"><figure class="wp-block-image"><img src="https://x/a.jpg" alt=""></figure><aside>promo</aside></div>', $with, 'a media-only body counts as written' );
		bfrc_test_add_post( [ 'ID' => 43, 'post_type' => 'bfrc_recipe', 'post_author' => 99999, 'post_title' => 'Orphan' ] );
		$orphan = $this->renderer->single( get_post( 43 ) );
		$this->assertStringContainsString( '<p class="bfrc-recipe__writer"><span class="bfrc-recipe__date">', $orphan, 'a deleted author: the date alone' );
	}

	public function test_a_password_protected_recipe_shows_only_the_password_form(): void {
		$this->recipe_meta( 41 );
		$GLOBALS['bfrc_test_posts'][41]->post_password = 'hunter2';
		$html                                           = $this->renderer->single( get_post( 41 ) );
		$this->assertStringContainsString( '<article class="bfrc-recipe bfrc-recipe--protected" id="bfrc-recipe-41">', $html );
		$this->assertStringContainsString( '<form class="post-password-form" data-post="41">', $html );
		foreach ( [ 'flour', 'Mix', 'Calories', 'bfrc-share', 'bfrc-recipe__hero', 'data-seam' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $html, "$leak must not leak" );
		}
		$GLOBALS['bfrc_test_password_unlocked'][41] = true;
		$this->assertStringContainsString( 'flour', $this->renderer->single( get_post( 41 ) ), 'unlocked: the full recipe' );
	}

	public function test_single_shows_the_video_embed_instead_of_the_photo_decodes_the_share_text_and_honours_share_settings(): void {
		update_post_meta( 41, Meta::MEDIA_TYPE, 'video' );
		update_post_meta( 41, Meta::VIDEO_URL, 'https://vimeo.com/123456789/abc123' );
		$GLOBALS['bfrc_test_posts'][41]->post_title = 'Mom&#8217;s Soup';
		$this->settings->update( [ 'share_x' => false, 'share_facebook' => false ] + $this->settings->all() );
		$html = $this->renderer->single( get_post( 41 ) );
		$this->assertStringContainsString( '<div class="bfrc-recipe__hero"><div class="bfrc-recipe__video"><iframe src="https://player.vimeo.com/video/123456789?h=abc123&#038;dnt=1" title="Mom&#8217;s Soup" loading="lazy"', $html );
		$this->assertStringNotContainsString( 'bfrc-recipe__ingredients', $html, 'no rows: no section' );
		$this->assertStringContainsString( 'bfrc-share__link--pinterest', $html );
		$this->assertStringContainsString( 'description=Mom%E2%80%99s%20Soup', $html, 'entities decoded for the share text' );
		$this->assertStringNotContainsString( 'bfrc-share__link--x', $html );
		$this->assertStringNotContainsString( 'bfrc-share__link--facebook', $html );
		$this->settings->update( [ 'share_enabled' => false ] + $this->settings->all() );
		$this->assertStringNotContainsString( 'bfrc-share', $this->renderer->single( get_post( 41 ) ) );
	}

	public function test_stored_markup_that_bypassed_the_sanitisers_is_still_filtered_on_output(): void {
		$GLOBALS['bfrc_test_post_meta'][41][ Meta::STEPS ] = [ [ 'text' => 'Mix <script>x()</script><em>well</em>' ] ];
		update_user_meta( 5, 'bfrc_about', 'Hi <script>alert(1)</script><b>there</b>' );
		$html = $this->renderer->single( get_post( 41 ) );
		$this->assertStringContainsString( '<li class="bfrc-step" id="bfrc-step-1">Mix <em>well</em></li>', $html );
		$this->assertSame( 'bfrc-step-7', Renderer::step_anchor( 7 ), 'the anchor the JSON-LD HowToStep url names' );
		$this->assertStringNotContainsString( '<script>', $html );
		$q        = bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe', 'bfrc_writer' => '1', 'author_name' => 'ann' ], 'author' );
		$q->posts = [];
		$page     = $this->renderer->archive( $q );
		$this->assertStringContainsString( 'Hi <b>there</b>', $page );
		$this->assertStringNotContainsString( '<script>', $page );
	}

	public function test_share_text_is_the_plain_title_with_entities_and_markup_gone(): void {
		$GLOBALS['bfrc_test_posts'][41]->post_title = 'Tomato &#039;Soup&#039; <b>&amp; Bread</b>';
		$html                                        = $this->renderer->share_buttons( get_post( 41 ) );
		$this->assertStringContainsString( '&#038;text=Tomato%20%27Soup%27%20%26%20Bread"', $html, 'X: quotes decoded (ENT_QUOTES), tags stripped, then URL-encoded' );
		$this->assertStringContainsString( '&#038;description=Tomato%20%27Soup%27%20%26%20Bread', $html, 'Pinterest the same' );
		$GLOBALS['bfrc_test_posts'][41]->post_title = "Grandma's <Soup> & Co"; // One space each side of the tag: stripping it leaves two.
		$this->assertStringContainsString( '&#038;text=Grandma%27s%20%26%20Co"', $this->renderer->share_buttons( get_post( 41 ) ), 'a bracketed word is stripped as a tag (as core\'s <title> does) and the whitespace collapses' );
		$GLOBALS['bfrc_test_posts'][41]->post_title = "Grandma's\n<Soup>\t&\xC2\xA0Co";
		$this->assertStringContainsString( '&#038;text=Grandma%27s%20%26%20Co"', $this->renderer->share_buttons( get_post( 41 ) ), 'newline, tab and non-breaking space collapse too' );
		$GLOBALS['bfrc_test_posts'][41]->post_title = "Bad \xFF byte";
		$this->assertStringContainsString( '&#038;text=Bad%20%FF%20byte"', $this->renderer->share_buttons( get_post( 41 ) ), 'invalid UTF-8 makes preg give up: the text is kept, not emptied' );
		$this->assertStringNotContainsString( '%3Cb%3E', $html, 'no markup in the share text' );
	}

	public function test_the_hero_photo_falls_back_to_the_recipe_title_for_its_alt_text(): void {
		update_post_meta( 41, '_thumbnail_id', 77 );
		bfrc_test_add_attachment( 77 );
		$hero = $this->renderer->hero( get_post( 41 ), RecipeData::for_post( 41 ) );
		$this->assertStringContainsString( 'class="bfrc-recipe__image" alt="Tomato &quot;Soup&quot;"', $hero, 'no alt stored: the recipe name' );
		update_post_meta( 77, '_wp_attachment_image_alt', 'A red bowl' );
		$this->assertStringContainsString( 'alt="A red bowl"', $this->renderer->hero( get_post( 41 ), RecipeData::for_post( 41 ) ), 'a stored alt wins' );
		$this->assertStringContainsString( 'aria-describedby="bfrc-filters-hint"', $this->renderer->filters( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ) );
		$this->assertStringContainsString( '<p id="bfrc-filters-hint" class="screen-reader-text bfrc-filters__hint">Changing a filter or pausing while typing updates the list', $this->renderer->filters( bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] ) ), 'WCAG 3.2.2: the change of context is announced' );
	}

	public function test_badges_hero_term_badges_and_section_helpers_handle_empty_data(): void {
		$data = RecipeData::for_post( 42 );
		$this->assertSame( '', $this->renderer->badges( $data, true ) );
		$this->assertSame( '', $this->renderer->term_badges( get_post( 42 ) ) );
		$this->assertSame( '', $this->renderer->ingredients( $data ) );
		$this->assertSame( '', $this->renderer->steps( $data ) );
		$this->assertSame( '', $this->renderer->nutrition( $data ) );
		$this->assertSame( '', $this->renderer->hero( get_post( 42 ), $data ), 'no photo, no video' );
		$this->assertStringContainsString( 'bfrc-share__link--pinterest', $this->renderer->share_buttons( get_post( 42 ) ), 'share buttons render without a photo (no media parameter)' );
		$this->assertStringNotContainsString( 'media=', $this->renderer->share_buttons( get_post( 42 ) ) );
		$this->assertSame( 'By <span>Eve</span>', $this->renderer->author_line( get_post( 42 ), null ) );
		bfrc_test_add_post( [ 'ID' => 43, 'post_type' => 'bfrc_recipe', 'post_author' => 0 ] );
		$this->assertSame( '', $this->renderer->author_line( get_post( 43 ), null ), 'no author' );
		$this->assertStringContainsString( 'opens in a new tab', Renderer::new_tab_hint() );
	}

	public function test_term_badges_skip_what_is_not_a_term_and_share_buttons_need_one_service_on(): void {
		$GLOBALS['bfrc_test_taxonomies']['bfrc_diet'] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		$GLOBALS['bfrc_test_object_terms'][42]['bfrc_diet'] = [ 'stray', new \WP_Term( [ 'term_id' => 5, 'name' => 'Vegan', 'slug' => 'vegan', 'taxonomy' => 'bfrc_diet' ] ) ];
		$html = $this->renderer->term_badges( get_post( 42 ) );
		$this->assertStringContainsString( 'Vegan', $html, 'a filter on get_the_terms may hand back anything: the terms are kept' );
		$this->assertStringNotContainsString( 'stray', $html, 'and anything else is skipped' );
		$this->settings->update( [ 'share_enabled' => true, 'share_facebook' => false, 'share_x' => false, 'share_pinterest' => false ] + $this->settings->all() );
		$this->assertSame( '', $this->renderer->share_buttons( get_post( 42 ) ), 'sharing on, every service off: no empty list' );
	}

	public function test_format_amount(): void {
		$this->assertSame( '250', Renderer::format_amount( 250.0 ) );
		$this->assertSame( '12.5', Renderer::format_amount( 12.5 ) );
		$this->assertSame( '0.25', Renderer::format_amount( 0.25 ) );
		$this->assertSame( '1,200', Renderer::format_amount( 1200.0 ) );
	}
}
