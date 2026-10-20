<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Admin;

use BlueFairy\Recipes\Admin\Samples;
use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Tests\TestCase;

final class SamplesTest extends TestCase {

	private Samples $samples;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		Capabilities::add_roles();
		foreach ( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$GLOBALS['bfrc_test_taxonomies'][ $tax ] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		}
		wp_insert_term( 'Dinner', 'bfrc_meal_type' );
		wp_insert_term( 'Vegan', 'bfrc_diet' );
		wp_insert_term( 'American', 'bfrc_cuisine' );
		$this->samples = new Samples();
	}

	public function test_install_creates_a_writer_three_draft_recipes_with_fields_terms_and_photos_and_records_them(): void {
		$this->assertSame( [], $this->samples->installed() );
		$record = $this->samples->install();
		$this->assertIsArray( $record );
		$this->assertSame( 900, $record['writer'] );
		$this->assertSame( 'bfrc_sample_writer', $record['writer_login'] );
		$this->assertCount( 3, $record['recipes'] );
		$this->assertCount( 3, $record['attachments'] );
		$this->assertSame( $record, $this->samples->installed(), 'recorded in the option' );
		$this->assertSame( 'off', $GLOBALS['bfrc_test_option_autoload'][ Samples::OPTION ] ?? 'missing', 'not autoloaded' );
		$writer = $GLOBALS['bfrc_test_inserted_users'][900];
		$this->assertSame( [ 'bfrc_sample_writer', 'bfrc_writer', 'bfrc_sample_writer@example.invalid' ], [ $writer['user_login'], $writer['role'], $writer['user_email'] ], 'a Recipe Writer with an undeliverable address' );
		$this->assertGreaterThanOrEqual( 32, strlen( (string) $writer['user_pass'] ), 'a random password' );
		$this->assertNotSame( '', (string) get_user_meta( 900, 'bfrc_about', true ) );
		$first = $GLOBALS['bfrc_test_inserted_posts'][ $record['recipes'][0] ];
		$this->assertSame( [ 'bfrc_recipe', 'draft', 900, 'Lemon Herb Roast Chicken', 'lemon-herb-roast-chicken' ], [ $first['post_type'], $first['post_status'], $first['post_author'], $first['post_title'], $first['post_name'] ], 'drafts the owner publishes' );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $first['post_content'] );
		$id = $record['recipes'][0];
		$this->assertSame( [ '20', '85', '4', '6', 'image' ], [ get_post_meta( $id, Meta::PREP_MINUTES, true ), get_post_meta( $id, Meta::COOK_MINUTES, true ), get_post_meta( $id, Meta::SERVINGS_MIN, true ), get_post_meta( $id, Meta::SERVINGS_MAX, true ), get_post_meta( $id, Meta::MEDIA_TYPE, true ) ], 'as core stores scalars: strings' );
		$this->assertCount( 7, get_post_meta( $id, Meta::INGREDIENTS, true ) );
		$this->assertSame( 'plain', get_post_meta( $id, Meta::INGREDIENTS, true )[0]['type'] );
		$this->assertCount( 4, get_post_meta( $id, Meta::STEPS, true ) );
		$this->assertSame( 520.0, get_post_meta( $id, Meta::NUTRITION, true )['calories'] );
		$this->assertSame( [ 'Dinner' ], array_map( static fn( \WP_Term $t ): string => $t->name, get_the_terms( $id, 'bfrc_meal_type' ) ) );
		$this->assertSame( [ 'American' ], array_map( static fn( \WP_Term $t ): string => $t->name, get_the_terms( $id, 'bfrc_cuisine' ) ) );
		$this->assertFalse( get_the_terms( $id, 'bfrc_diet' ), 'Gluten-Free is not among this site\'s diet terms: skipped, not created' );
		$this->assertSame( [ 'Vegan' ], array_map( static fn( \WP_Term $t ): string => $t->name, get_the_terms( $record['recipes'][1], 'bfrc_diet' ) ) );
		$this->assertSame( $record['attachments'][0], (int) get_post_meta( $id, '_thumbnail_id', true ), 'the photo is the featured image' );
		$this->assertSame( [ 'bfrc-sample-lemon-herb-roast-chicken.jpg', filesize( BFRC_DIR . 'assets/samples/lemon-herb-roast-chicken.jpg' ) ], [ $GLOBALS['bfrc_test_uploads'][0]['name'], $GLOBALS['bfrc_test_uploads'][0]['bytes'] ], 'the shipped photo, byte for byte' );
		$this->assertSame( $id, $GLOBALS['bfrc_test_attachment_parents'][ $record['attachments'][0] ] );
		$this->assertSame( 'Lemon Herb Roast Chicken', get_post_meta( $record['attachments'][0], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 1200, $GLOBALS['bfrc_test_attachment_meta'][ $record['attachments'][0] ]['width'] );
	}

	public function test_install_is_refused_while_installed_or_while_the_writer_login_exists(): void {
		$this->samples->install();
		$again = $this->samples->install();
		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 'exists', $again->get_error_code() );
		$this->assertCount( 3, $GLOBALS['bfrc_test_inserted_posts'], 'nothing created twice' );
		$this->samples->remove();
		bfrc_test_add_user( 7, [ 'roles' => [ 'bfrc_writer' ], 'user_login' => 'bfrc_sample_writer' ] ); // The record is gone (an uninstall that kept content) but the writer remains.
		$this->assertSame( 'exists', $this->samples->install()->get_error_code() );
	}

	public function test_remove_deletes_exactly_what_was_recorded_and_the_record(): void {
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 5, 'post_title' => 'Keep me' ] );
		$record = $this->samples->install();
		$this->samples->remove();
		$this->assertSame( [], $this->samples->installed() );
		$this->assertSame( [ 900 ], $GLOBALS['bfrc_test_deleted_users'] );
		$this->assertSame( $record['attachments'], $GLOBALS['bfrc_test_deleted_attachments'] );
		foreach ( $record['recipes'] as $id ) {
			$this->assertNull( get_post( $id ), "recipe $id deleted" );
		}
		$this->assertInstanceOf( \WP_Post::class, get_post( 41 ), 'the site\'s own recipe untouched' );
		$this->assertNull( get_option( Samples::OPTION, null ) );
		$this->samples->remove();
		$this->assertSame( [ 900 ], $GLOBALS['bfrc_test_deleted_users'], 'a second remove is a no-op' );
	}

	public function test_a_failure_part_way_removes_what_was_created_and_reports_it(): void {
		$GLOBALS['bfrc_test_post_insert_fails'] = true;
		$result                                 = $this->samples->install();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'failed', $result->get_error_code() );
		$this->assertSame( [ 900 ], $GLOBALS['bfrc_test_deleted_users'], 'the writer created before the failure is removed' );
		$this->assertSame( [], $this->samples->installed() );
		$GLOBALS['bfrc_test_post_insert_fails'] = false;
		$GLOBALS['bfrc_test_user_insert_fails'] = true;
		$this->assertSame( 'failed', $this->samples->install()->get_error_code() );
		$GLOBALS['bfrc_test_user_insert_fails'] = false;
		$GLOBALS['bfrc_test_upload_fails']      = true;
		$record                                 = $this->samples->install();
		$this->assertIsArray( $record );
		$this->assertSame( [], $record['attachments'], 'a failed upload keeps the recipe without a photo' );
		$this->assertCount( 3, $record['recipes'] );
		$GLOBALS['bfrc_test_upload_fails'] = false;
		$this->samples->remove();
		$GLOBALS['bfrc_test_option_write_fails'] = [ Samples::OPTION ];
		$this->assertSame( 'failed', $this->samples->install()->get_error_code(), 'an unrecorded set would be unremovable: refused and undone' );
		$this->assertSame( [], $this->samples->installed() );
	}

	public function test_the_set_is_three_new_recipes_with_shipped_photos(): void {
		$set = Samples::recipes();
		$this->assertCount( 3, $set );
		foreach ( $set as $recipe ) {
			$this->assertFileExists( BFRC_DIR . 'assets/samples/' . $recipe['slug'] . '.jpg' );
			$this->assertGreaterThanOrEqual( 3, count( $recipe['steps'] ) );
			$this->assertGreaterThanOrEqual( 5, count( $recipe['ingredients'] ) );
			$this->assertSame( [ 'meal_type', 'diet', 'season', 'cuisine' ], array_keys( $recipe['terms'] ) );
		}
	}
}
