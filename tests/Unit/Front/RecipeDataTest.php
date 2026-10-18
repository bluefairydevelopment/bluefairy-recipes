<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Front;

use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Front\RecipeData;
use BlueFairy\Recipes\Tests\TestCase;

final class RecipeDataTest extends TestCase {

	public function test_for_post_reads_every_field_through_the_sanitisers_in_one_meta_read(): void {
		$post = bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
		update_post_meta( 41, Meta::MEDIA_TYPE, 'video' );
		update_post_meta( 41, Meta::VIDEO_URL, 'https://youtu.be/dQw4w9WgXcQ' );
		update_post_meta( 41, Meta::PREP_MINUTES, '20' );
		update_post_meta( 41, Meta::COOK_MINUTES, 99999 );
		update_post_meta( 41, Meta::SERVINGS_MIN, 2 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 4 );
		update_post_meta( 41, Meta::FEATURED, true );
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '2 cups', 'label' => 'flour', 'type' => 'plain' ], [ 'label' => '' ] ] );
		update_post_meta( 41, Meta::STEPS, [ [ 'text' => 'Mix <script>x()</script>well' ] ] );
		update_post_meta( 41, Meta::NUTRITION, [ 'calories' => '250', 'protein' => 12.5 ] );
		$data = RecipeData::for_post( $post );
		$this->assertSame( 41, $data->id );
		$this->assertSame( 'video', $data->media_type );
		$this->assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $data->video_url, 'normalised' );
		$this->assertSame( 20, $data->prep_minutes );
		$this->assertSame( 6000, $data->cook_minutes, 'clamped' );
		$this->assertSame( [ 2, 4 ], [ $data->servings_min, $data->servings_max ] );
		$this->assertTrue( $data->featured );
		$this->assertSame( [ [ 'measure' => '2 cups', 'label' => 'flour', 'type' => 'plain' ] ], $data->ingredients );
		$this->assertSame( [ [ 'text' => 'Mix well' ] ], $data->steps );
		$this->assertSame( [ 'calories' => 250.0, 'protein' => 12.5 ], $data->nutrition_present() );
		$this->assertTrue( $data->has_video() );
		$this->assertSame( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $data->embed_url() );
		$this->assertSame( 6020, $data->total_minutes() );
		$this->assertSame( RecipeData::for_post( 41 )->prep_minutes, $data->prep_minutes, 'by id too' );
	}

	public function test_an_empty_recipe_reads_as_defaults(): void {
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe' ] );
		$data = RecipeData::for_post( 42 );
		$this->assertSame( 'image', $data->media_type );
		$this->assertSame( '', $data->video_url );
		$this->assertFalse( $data->has_video() );
		$this->assertSame( '', $data->embed_url() );
		$this->assertSame( [], $data->ingredients );
		$this->assertSame( [], $data->steps );
		$this->assertSame( [], $data->nutrition_present() );
		$this->assertSame( '', $data->servings_text() );
		$this->assertFalse( $data->featured );
	}

	public function test_a_video_type_without_a_stored_url_shows_the_photo(): void {
		bfrc_test_add_post( [ 'ID' => 43, 'post_type' => 'bfrc_recipe' ] );
		update_post_meta( 43, Meta::MEDIA_TYPE, 'video' );
		$this->assertFalse( RecipeData::for_post( 43 )->has_video() );
	}

	public function test_format_minutes(): void {
		$this->assertSame( '', RecipeData::format_minutes( 0 ) );
		$this->assertSame( '', RecipeData::format_minutes( -5 ) );
		$this->assertSame( '45 min', RecipeData::format_minutes( 45 ) );
		$this->assertSame( '1 hr', RecipeData::format_minutes( 60 ) );
		$this->assertSame( '1 hr 15 min', RecipeData::format_minutes( 75 ) );
		$this->assertSame( '100 hr', RecipeData::format_minutes( 6000 ) );
	}

	public function test_servings_text(): void {
		$make = function ( int $min, int $max ): RecipeData {
			static $id = 100;
			++$id;
			bfrc_test_add_post( [ 'ID' => $id, 'post_type' => 'bfrc_recipe' ] );
			update_post_meta( $id, Meta::SERVINGS_MIN, $min );
			update_post_meta( $id, Meta::SERVINGS_MAX, $max );
			return RecipeData::for_post( $id );
		};
		$this->assertSame( '', $make( 0, 0 )->servings_text() );
		$this->assertSame( '4 servings', $make( 4, 4 )->servings_text() );
		$this->assertSame( '1 serving', $make( 1, 0 )->servings_text() );
		$this->assertSame( '6 servings', $make( 0, 6 )->servings_text() );
		$this->assertSame( '2–4 servings', $make( 2, 4 )->servings_text() );
		$this->assertSame( '5 servings', $make( 5, 3 )->servings_text(), 'a max below min (never written by the plugin) reads as min' );
	}
}
