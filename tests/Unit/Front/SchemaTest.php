<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Front;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\Renderer;
use BlueFairy\Recipes\Front\Schema;
use BlueFairy\Recipes\Front\Writers;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;
use JsonSchema\Validator;

final class SchemaTest extends TestCase {

	private Settings $settings;
	private Schema $schema;
	private Writers $writers;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		Capabilities::add_roles();
		$this->settings = new Settings();
		$taxonomies     = new Taxonomies( $this->settings );
		$this->writers  = new Writers( $this->settings );
		$this->schema   = new Schema( $this->settings, $taxonomies, $this->writers );
		foreach ( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$GLOBALS['bfrc_test_taxonomies'][ $tax ] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		}
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann &amp; <b>Lee</b>', 'user_nicename' => 'ann' ] );
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Tomato &#8220;Soup&#8221; <script>x</script>', 'post_name' => 'tomato-soup', 'post_excerpt' => 'A <em>warm</em> bowl &amp; more.', 'post_date_gmt' => '2026-10-01 12:00:00', 'post_modified_gmt' => '2026-10-02 08:30:00' ] );
		update_post_meta( 41, Meta::PREP_MINUTES, 20 );
		update_post_meta( 41, Meta::COOK_MINUTES, 75 );
		update_post_meta( 41, Meta::SERVINGS_MIN, 2 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 4 );
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '2 cups', 'label' => 'flour &amp; <b>co</b>', 'type' => 'plain' ], [ 'measure' => '', 'label' => 'salt', 'type' => 'plain' ] ] );
		update_post_meta( 41, Meta::STEPS, [ [ 'text' => 'Mix <em>well</em>' ], [ 'text' => '  Bake  ' ] ] );
		update_post_meta( 41, Meta::NUTRITION, Meta::sanitize_nutrition( [ 'calories' => 250, 'protein' => 12.5, 'sodium' => 0.25 ] ) );
		update_post_meta( 41, '_thumbnail_id', 77 );
		bfrc_test_add_attachment( 77 );
		wp_set_object_terms( 41, [ 'Vegan', 'Gluten-Free' ], 'bfrc_diet' );
		wp_set_object_terms( 41, [ 'Dinner' ], 'bfrc_meal_type' );
		wp_set_object_terms( 41, [ 'Italian' ], 'bfrc_cuisine' );
	}

	/** Validate against the fixture; returns the validator for its errors. */
	private function validate( array $data ): Validator {
		$validator = new Validator();
		$value     = json_decode( (string) wp_json_encode( $data ) ); // The validator takes its subject by reference.
		$validator->validate( $value, json_decode( (string) file_get_contents( BFRC_DIR . 'tests/fixtures/recipe-jsonld.schema.json' ) ) );
		return $validator;
	}

	public function test_build_is_a_recipe_object_that_validates_against_the_fixture_and_the_fixture_can_fail(): void {
		$data = $this->schema->build( get_post( 41 ) );
		$this->assertTrue( $this->validate( $data )->isValid(), wp_json_encode( $this->validate( $data )->getErrors() ) );
		$this->assertSame( 'https://schema.org', $data['@context'] );
		$this->assertSame( 'Recipe', $data['@type'] );
		$this->assertSame( 'Tomato “Soup” x', $data['name'], 'plain text: entities decoded, tags stripped' );
		$this->assertSame( [ 'https://example.test/uploads/77.jpg' ], $data['image'], 'every size the site has, duplicates collapsed' );
		$this->assertSame( 'A warm bowl & more.', $data['description'] );
		$this->assertSame( [ '@type' => 'Person', 'name' => 'Ann & Lee', 'url' => 'https://example.com/recipes/chef/ann/' ], $data['author'], 'linked to the writer page when they have one' );
		$this->assertSame( [ '2026-10-01T12:00:00+00:00', '2026-10-02T08:30:00+00:00' ], [ $data['datePublished'], $data['dateModified'] ] );
		$this->assertSame( [ 'PT20M', 'PT1H15M', 'PT1H35M' ], [ $data['prepTime'], $data['cookTime'], $data['totalTime'] ] );
		$this->assertSame( '2–4 servings', $data['recipeYield'] );
		$this->assertSame( [ 'Dinner' ], $data['recipeCategory'] );
		$this->assertSame( [ 'Italian' ], $data['recipeCuisine'] );
		$this->assertSame( 'Vegan, Gluten-Free', $data['keywords'], 'the filter types other than category and cuisine, which Google says not to repeat in keywords' );
		$this->assertSame( [ 'Dinner' ], $data['recipeCategory'] );
		$this->assertSame( [ 'Italian' ], $data['recipeCuisine'] );
		$this->assertFalse( $this->validate( [ 'nutrition' => [ '@type' => 'NutritionInformation', 'proteinContent' => '12,5 g' ] ] + $data )->isValid(), 'a locale-formatted number must fail the fixture' );
		foreach ( [ [ 250.0, '250' ], [ 12.5, '12.5' ], [ 0.25, '0.25' ], [ 1234.567, '1234.57' ] ] as [ $value, $text ] ) {
			$this->assertSame( $text, Schema::amount( $value ), 'machine form, whatever the locale' );
		}
		$this->assertSame( [ 'https://schema.org/VeganDiet', 'https://schema.org/GlutenFreeDiet' ], $data['suitableForDiet'] );
		$this->assertSame( [ '2 cups flour & co', 'salt' ], $data['recipeIngredient'] );
		$this->assertSame( [ [ '@type' => 'HowToStep', 'position' => 1, 'text' => 'Mix well', 'url' => 'https://example.com/?p=41#bfrc-step-1' ], [ '@type' => 'HowToStep', 'position' => 2, 'text' => 'Bake', 'url' => 'https://example.com/?p=41#bfrc-step-2' ] ], $data['recipeInstructions'] );
		update_post_meta( 41, Meta::STEPS, [ [ 'text' => 'Mix <em>well</em>' ], [ 'text' => '<img src="https://example.test/s.jpg" alt="">' ], [ 'text' => 'Bake' ] ] );
		$steps = $this->schema->build( get_post( 41 ) )['recipeInstructions'];
		$this->assertSame( [ [ 1, 'https://example.com/?p=41#bfrc-step-1' ], [ 3, 'https://example.com/?p=41#bfrc-step-3' ] ], array_map( static fn( array $s ): array => [ $s['position'], $s['url'] ], $steps ), 'a row with markup but no text is skipped by the schema yet rendered (and numbered) on the page: the url names the row, not the count' );
		$this->assertStringContainsString( '<li class="bfrc-step" id="bfrc-step-3">Bake</li>', ( new Renderer( $this->settings, new Writers( $this->settings ), new Taxonomies( $this->settings ) ) )->steps( \BlueFairy\Recipes\Front\RecipeData::for_post( 41 ) ), 'the page agrees' );
		update_post_meta( 41, Meta::STEPS, [ [ 'text' => 'Mix <em>well</em>' ], [ 'text' => '  Bake  ' ] ] );
		$this->assertSame( [ '@type' => 'NutritionInformation', 'calories' => '250 calories', 'proteinContent' => '12.5 g', 'sodiumContent' => '0.25 mg' ], $data['nutrition'], 'present fields only, Google\'s units' );
		$this->assertArrayNotHasKey( 'video', $data );
		// The instrument can fail: Google's required `name` removed → invalid; a bad duration → invalid.
		$without_name = $data;
		unset( $without_name['name'] );
		$this->assertFalse( $this->validate( $without_name )->isValid(), 'the fixture requires name' );
		$this->assertSame( 'name', $this->validate( $without_name )->getErrors()[0]['property'] );
		$bad_time             = $data;
		$bad_time['prepTime'] = '20 minutes';
		$this->assertFalse( $this->validate( $bad_time )->isValid(), 'durations must be ISO 8601' );
		$no_image          = $data;
		$no_image['image'] = [];
		$this->assertFalse( $this->validate( $no_image )->isValid(), 'at least one image' );
	}

	public function test_a_video_recipe_carries_a_video_object_and_the_filter_can_add_a_rating(): void {
		update_post_meta( 41, Meta::MEDIA_TYPE, 'video' );
		update_post_meta( 41, Meta::VIDEO_URL, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		add_filter( 'bfrc_schema_data', static function ( array $data, \WP_Post $post ): array {
			$data['aggregateRating'] = [ '@type' => 'AggregateRating', 'ratingValue' => 4.5, 'ratingCount' => 12 ];
			$data['x_post']          = $post->ID;
			return $data;
		}, 10, 2 );
		$data = $this->schema->build( get_post( 41 ) );
		$this->assertTrue( $this->validate( $data )->isValid(), wp_json_encode( $this->validate( $data )->getErrors() ) );
		$this->assertSame( [ '@type' => 'VideoObject', 'name' => 'Tomato “Soup” x', 'description' => 'A warm bowl & more.', 'thumbnailUrl' => [ 'https://example.test/uploads/77.jpg' ], 'uploadDate' => '2026-10-01T12:00:00+00:00', 'embedUrl' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ], $data['video'], 'embedUrl only: a watch-page URL is not a media file, so no contentUrl' );
		$this->assertSame( 12, $data['aggregateRating']['ratingCount'], 'the add-on\'s rating travels' );
		$this->assertSame( 41, $data['x_post'] );
		add_filter( 'bfrc_schema_data', '__return_false', 20 );
		$this->assertSame( 'Recipe', $this->schema->build( get_post( 41 ) )['@type'], 'a filter returning a non-array is ignored' );
	}

	public function test_a_recipe_without_a_photo_builds_nothing_and_a_bare_recipe_still_validates(): void {
		delete_post_meta( 41, '_thumbnail_id' );
		$this->assertSame( [], $this->schema->build( get_post( 41 ) ), 'Google requires image: no markup rather than invalid markup' );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe', 'post_status' => 'draft', 'post_author' => 999, 'post_title' => 'Bread', 'post_name' => 'bread', 'post_date' => '2026-10-20 23:45:53', 'post_modified' => '2026-10-21 01:00:00' ] ); // As core stores a draft: local dates, zero GMT columns.
		update_post_meta( 42, '_thumbnail_id', 77 );
		$data = $this->schema->build( get_post( 42 ) );
		$this->assertTrue( $this->validate( $data )->isValid(), wp_json_encode( $this->validate( $data )->getErrors() ) );
		$this->assertSame( [ '@context', '@type', 'name', 'url', 'image' ], array_keys( $data ), 'nothing invented for an empty field; a deleted author is no author; a draft has no date keys: its date floats, and a preview would carry the request time' );
		foreach ( [ 'pending', 'auto-draft' ] as $status ) {
			$GLOBALS['bfrc_test_posts'][42]->post_status = $status;
			$this->assertArrayNotHasKey( 'datePublished', $this->schema->build( get_post( 42 ) ), $status );
		}
		update_post_meta( 42, Meta::MEDIA_TYPE, 'video' );
		update_post_meta( 42, Meta::VIDEO_URL, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		$draft = $this->schema->build( get_post( 42 ) );
		$this->assertArrayNotHasKey( 'video', $draft, 'a VideoObject needs uploadDate, which a draft cannot give' );
		$this->assertTrue( $this->validate( $draft )->isValid(), 'and the contract holds for a draft video recipe' );
		$GLOBALS['bfrc_test_posts'][42]->post_status = 'publish'; // wp_publish_post(): the status changes, the local date stays, the GMT column stays zero.
		$this->assertSame( '2026-10-20T23:45:53+02:00', $this->schema->build( get_post( 42 ) )['video']['uploadDate'], 'published: the video is back, dated' );
		$data                                        = $this->schema->build( get_post( 42 ) );
		$this->assertSame( [ '2026-10-20T23:45:53+02:00', '2026-10-21T01:00:00+02:00' ], [ $data['datePublished'], $data['dateModified'] ], 'the local time with the site\'s offset stands in for a missing UTC date' );
		$this->assertTrue( $this->validate( $data )->isValid(), 'still ISO 8601 for the fixture' );
	}

	public function test_head_markup_prints_on_a_recipe_page_only_when_enabled_rich_snippets_on_and_not_locked(): void {
		$q                    = bfrc_test_front_query( [], 'single' );
		$q->queried_object    = get_post( 41 );
		$q->queried_object_id = 41;
		$html                 = $this->schema->head_markup();
		$this->assertStringStartsWith( '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Recipe","name":"Tomato “Soup” x"', $html );
		$this->assertStringContainsString( '"description":"A warm bowl \u0026 more."', $html, 'HEX_AMP: an ampersand cannot start an entity' );
		$this->assertStringNotContainsString( '</script>x', $html );
		$this->assertStringContainsString( '"url":"https://example.com/?p=41"', $html, 'slashes unescaped' );
		$this->assertStringEndsWith( "</script>\n", $html );
		ob_start();
		$this->schema->print_head();
		$this->assertSame( $html, ob_get_clean() );
		$this->settings->update( [ 'rich_snippets' => false ] + $this->settings->all() );
		$this->assertSame( '', $this->schema->head_markup(), 'Rich snippets off' );
		$this->settings->update( [ 'rich_snippets' => true, 'enabled' => false ] + $this->settings->all() );
		$this->assertSame( '', $this->schema->head_markup(), 'plugin disabled' );
		$this->settings->update( [ 'enabled' => true ] + $this->settings->all() );
		$GLOBALS['bfrc_test_posts'][41]->post_password = 'hunter2';
		$this->assertSame( '', $this->schema->head_markup(), 'a locked recipe gives nothing away' );
		$GLOBALS['bfrc_test_posts'][41]->post_password = '';
		bfrc_test_front_query( [ 'post_type' => 'bfrc_recipe' ] );
		$this->assertSame( '', $this->schema->head_markup(), 'the archive is not a recipe page' );
		$GLOBALS['wp_query'] = null;
		$this->assertSame( '', $this->schema->head_markup() );
	}

	public function test_register_hooks_author_terms_images_duration_and_text(): void {
		$this->schema->register_hooks();
		$this->assertSame( 10, has_action( 'wp_head', [ $this->schema, 'print_head' ] ) );
		$this->assertSame( [ 'meal_type' => [ 'dinner' => 'Dinner' ], 'diet' => [ 'vegan' => 'Vegan', 'gluten-free' => 'Gluten-Free' ], 'cuisine' => [ 'italian' => 'Italian' ] ], $this->schema->terms( get_post( 41 ) ), 'the enabled filter types with terms, in filter order, by code and slug' );
		$this->assertSame( [ 'https://example.test/uploads/77.jpg' ], $this->schema->images( get_post( 41 ) ) );
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		$this->assertSame( [ '@type' => 'Person', 'name' => 'Ann & Lee' ], $this->schema->author( get_post( 41 ) ), 'no writer page while disabled' );
		foreach ( [ 1 => 'PT1M', 59 => 'PT59M', 60 => 'PT1H', 75 => 'PT1H15M', 120 => 'PT2H' ] as $minutes => $iso ) {
			$this->assertSame( $iso, Schema::duration( $minutes ), (string) $minutes );
		}
		$this->assertSame( 'a b', Schema::text( " a\n\t<b>b</b>&nbsp; " ) );
	}
}
