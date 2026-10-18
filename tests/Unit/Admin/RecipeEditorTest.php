<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Admin;

use BlueFairy\Recipes\Admin\RecipeEditor;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Tests\TestCase;

final class RecipeEditorTest extends TestCase {

	private \WP_Post $recipe;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$this->recipe                        = bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
		wp_set_current_user( 9 );
	}

	/**
	 * A valid form post for recipe 41.
	 *
	 * @param array<string, mixed> $fields The `bfrc` fields.
	 */
	private function post_form( array $fields, bool $nonce = true ): void {
		$_POST = [
			RecipeEditor::NONCE_FIELD => $nonce ? wp_create_nonce( RecipeEditor::NONCE_ACTION ) : 'wrong',
			RecipeEditor::FIELD       => $fields,
		];
		$GLOBALS['bfrc_test_nonce_valid'] = $nonce;
	}

	public function test_register_hooks(): void {
		$e = new RecipeEditor();
		$e->register_hooks();
		$this->assertSame( 10, has_action( 'add_meta_boxes_bfrc_recipe', [ $e, 'add_boxes' ] ) );
		$this->assertSame( 10, has_action( 'save_post_bfrc_recipe', [ $e, 'save' ] ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', [ $e, 'enqueue' ] ) );
		$this->assertSame( 10, has_action( 'admin_notices', [ $e, 'notices' ] ) );
	}

	public function test_add_boxes_registers_five_boxes_on_the_recipe_screen_then_fires_the_seam(): void {
		$seen = null;
		add_action( 'bfrc_recipe_meta_boxes', static function ( \WP_Post $p ) use ( &$seen ): void {
			$seen = [ $p->ID, array_keys( $GLOBALS['bfrc_test_meta_boxes'] ) ];
		} );
		( new RecipeEditor() )->add_boxes( $this->recipe );
		$this->assertSame( [ 41, [ 'bfrc-details', 'bfrc-media', 'bfrc-ingredients', 'bfrc-steps', 'bfrc-nutrition' ] ], $seen, 'the add-on sees the five boxes already there' );
		foreach ( $GLOBALS['bfrc_test_meta_boxes'] as $id => $box ) {
			$this->assertSame( 'bfrc_recipe', $box['screen'], $id );
			$this->assertTrue( is_callable( $box['callback'] ), $id );
		}
		$this->assertSame( 'side', $GLOBALS['bfrc_test_meta_boxes']['bfrc-details']['context'] );
	}

	public function test_render_details_carries_the_nonce_and_the_stored_values(): void {
		update_post_meta( 41, Meta::PREP_MINUTES, 20 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 6 );
		update_post_meta( 41, Meta::FEATURED, true );
		$html = bfrc_test_render( fn() => ( new RecipeEditor() )->render_details( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc_recipe_nonce"', $html );
		$this->assertStringContainsString( 'name="bfrc[prep_minutes]" value="20"', $html );
		$this->assertStringContainsString( 'name="bfrc[servings_max]" value="6"', $html );
		$this->assertStringContainsString( 'max="6000"', $html );
		$this->assertStringContainsString( 'name="bfrc[featured]" value="1" checked="checked"', $html );
	}

	public function test_render_media_and_nutrition_show_stored_values_and_escape_them(): void {
		update_post_meta( 41, Meta::MEDIA_TYPE, 'video' );
		update_post_meta( 41, Meta::VIDEO_URL, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		update_post_meta( 41, Meta::NUTRITION, Meta::sanitize_nutrition( [ 'calories' => 250, 'sodium' => '12.5' ] ) );
		$media = bfrc_test_render( fn() => ( new RecipeEditor() )->render_media( $this->recipe ) );
		$this->assertStringContainsString( 'value="video" checked="checked"', $media );
		$this->assertStringContainsString( 'value="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $media );
		$nutrition = bfrc_test_render( fn() => ( new RecipeEditor() )->render_nutrition( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc[nutrition][calories]" value="250"', $nutrition );
		$this->assertStringContainsString( 'name="bfrc[nutrition][sodium]" value="12.5"', $nutrition );
		$this->assertStringContainsString( 'name="bfrc[nutrition][fat]" value=""', $nutrition, 'null renders empty, never 0' );
		$this->assertSame( 8, substr_count( $nutrition, 'type="number"' ) );
	}

	public function test_render_ingredients_and_steps_render_rows_a_template_and_escape_content(): void {
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '2 cups', 'label' => 'flour "00"', 'type' => 'plain' ], [ 'measure' => '', 'label' => 'salt', 'type' => 'plain' ] ] );
		update_post_meta( 41, Meta::STEPS, [ [ 'text' => 'Mix <em>well</em>' ], [ 'text' => 'Bake & serve' ] ] );
		$ing = bfrc_test_render( fn() => ( new RecipeEditor() )->render_ingredients( $this->recipe ) );
		$this->assertSame( 2, substr_count( $ing, '<tr class="bfrc-rows__row"' ) + 0 - substr_count( $ing, '__INDEX__' ) / 3, 'two stored rows (the template row carries __INDEX__ three times)' );
		$this->assertStringContainsString( 'name="bfrc[ingredients][0][label]" value="flour &quot;00&quot;"', $ing );
		$this->assertStringContainsString( 'name="bfrc[ingredients][1][measure]" value=""', $ing );
		$this->assertStringContainsString( 'name="bfrc[ingredients][__INDEX__][label]"', $ing, 'the template row for the script' );
		$this->assertStringContainsString( 'type="hidden" name="bfrc[ingredients][0][type]" value="plain"', $ing, 'one type: no selector' );
		$this->assertStringContainsString( 'data-bfrc-max="200"', $ing );
		$steps = bfrc_test_render( fn() => ( new RecipeEditor() )->render_steps( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc[steps][0][text]">Mix &lt;em&gt;well&lt;/em&gt;</textarea>', $steps, 'textarea content is escaped' );
		$this->assertStringContainsString( 'name="bfrc[steps][1][text]">Bake &amp; serve</textarea>', $steps );
		$this->assertStringContainsString( 'name="bfrc[steps][__INDEX__][text]"', $steps );
	}

	public function test_render_ingredients_shows_a_type_selector_and_extra_fields_when_the_add_on_adds_types(): void {
		add_filter( 'bfrc_ingredient_types', static fn( array $t ): array => array_merge( $t, [ 'internal' ] ) );
		add_filter( 'bfrc_ingredient_row_fields', static fn( array $f, string $type ): array => 'internal' === $type ? [ 'product_id' => 'absint' ] : $f, 10, 2 );
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '', 'label' => 'butter', 'type' => 'internal', 'product_id' => 12 ] ] );
		$ing = bfrc_test_render( fn() => ( new RecipeEditor() )->render_ingredients( $this->recipe ) );
		$this->assertStringContainsString( '<select name="bfrc[ingredients][0][type]" class="bfrc-rows__type"><option value="plain">Plain</option><option value="internal" selected="selected">Internal</option></select>', $ing );
		$this->assertStringContainsString( 'data-bfrc-type="internal" name="bfrc[ingredients][0][product_id]" value="12"', $ing );
	}

	public function test_enqueue_loads_assets_only_on_the_recipe_edit_screen(): void {
		$e = new RecipeEditor();
		$e->enqueue( 'edit.php' );
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued'] );
		$GLOBALS['bfrc_test_screen'] = bfrc_test_screen_for( 'post' );
		$e->enqueue( 'post.php' );
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued'], 'another post type' );
		$GLOBALS['bfrc_test_screen'] = bfrc_test_screen_for( 'bfrc_recipe' );
		$e->enqueue( 'post-new.php' );
		$this->assertSame( [ 'bfrc-recipe-editor-rows', 'bfrc-recipe-editor' ], $GLOBALS['bfrc_test_enqueued']['script'] );
		$this->assertSame( [ 'bfrc-recipe-editor' ], $GLOBALS['bfrc_test_enqueued']['style'] );
		$this->assertSame( 200, $GLOBALS['bfrc_test_enqueued']['localize']['bfrc-recipe-editor']['bfrcRecipeEditor']['maxRows'] );
	}

	public function test_save_writes_every_field_through_the_sanitisers(): void {
		bfrc_test_grant( 'edit_post' );
		$this->post_form(
			[
				'media_type'   => 'video',
				'video_url'    => 'https://youtu.be/dQw4w9WgXcQ',
				'prep_minutes' => '25',
				'cook_minutes' => '99999',
				'servings_min' => '2',
				'servings_max' => '4',
				'featured'     => '1',
				'ingredients'  => [ [ 'measure' => ' 1 tsp ', 'label' => ' <b>salt</b> ' ], [ 'label' => '' ] ],
				'steps'        => [ [ 'text' => ' Stir <script>x()</script> ' ], [ 'text' => '' ] ],
				'nutrition'    => [ 'calories' => '250', 'fat' => 'abc' ],
			]
		);
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertSame( 'video', get_post_meta( 41, Meta::MEDIA_TYPE, true ) );
		$this->assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', get_post_meta( 41, Meta::VIDEO_URL, true ) );
		$this->assertSame( 25, get_post_meta( 41, Meta::PREP_MINUTES, true ) );
		$this->assertSame( 6000, get_post_meta( 41, Meta::COOK_MINUTES, true ), 'clamped' );
		$this->assertSame( 2, get_post_meta( 41, Meta::SERVINGS_MIN, true ) );
		$this->assertSame( 4, get_post_meta( 41, Meta::SERVINGS_MAX, true ) );
		$this->assertTrue( get_post_meta( 41, Meta::FEATURED, true ) );
		$this->assertSame( [ [ 'measure' => '1 tsp', 'label' => 'salt', 'type' => 'plain' ] ], get_post_meta( 41, Meta::INGREDIENTS, true ) );
		$this->assertSame( [ [ 'text' => 'Stir' ] ], get_post_meta( 41, Meta::STEPS, true ) );
		$this->assertSame( 250.0, get_post_meta( 41, Meta::NUTRITION, true )['calories'] );
		$this->assertNull( get_post_meta( 41, Meta::NUTRITION, true )['fat'] );
		$this->assertFalse( get_transient( 'bfrc_editor_notice_9' ), 'a valid video URL: no notice' );
	}

	public function test_an_unticked_checkbox_and_missing_rows_clear_the_stored_values(): void {
		bfrc_test_grant( 'edit_post' );
		update_post_meta( 41, Meta::FEATURED, true );
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '', 'label' => 'x', 'type' => 'plain' ] ] );
		$this->post_form( [ 'prep_minutes' => '5' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertFalse( get_post_meta( 41, Meta::FEATURED, true ), 'form semantics: an absent checkbox is unticked' );
		$this->assertSame( [], get_post_meta( 41, Meta::INGREDIENTS, true ), 'all rows removed in the editor' );
	}

	public function test_save_refuses_without_capability_or_with_a_bad_nonce_and_ignores_other_requests(): void {
		$e = new RecipeEditor();
		$this->post_form( [ 'prep_minutes' => '5' ] );
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::PREP_MINUTES, true ), 'no edit_post: nothing written' );
		bfrc_test_grant( 'edit_post' );
		$this->post_form( [ 'prep_minutes' => '5' ], false );
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::PREP_MINUTES, true ), 'bad nonce: nothing written' );
		$_POST = [ RecipeEditor::FIELD => [ 'prep_minutes' => '5' ] ];
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::PREP_MINUTES, true ), 'no nonce field at all (REST, quick edit): not our form, nothing wiped' );
		$this->post_form( [ 'prep_minutes' => '5' ] );
		$GLOBALS['bfrc_test_autosaves'] = [ 41 ];
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::PREP_MINUTES, true ), 'autosave: skipped' );
		$GLOBALS['bfrc_test_autosaves'] = [];
		$other = bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'post' ] );
		$e->save( 42, $other );
		$this->assertSame( '', get_post_meta( 42, Meta::PREP_MINUTES, true ), 'another post type: skipped' );
	}

	public function test_a_rejected_video_url_is_not_stored_and_the_writer_is_told_once(): void {
		bfrc_test_grant( 'edit_post' );
		update_post_meta( 41, Meta::VIDEO_URL, 'https://vimeo.com/123456789' );
		$this->post_form( [ 'video_url' => 'https://example.com/my-video' ] );
		$e = new RecipeEditor();
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::VIDEO_URL, true ), 'a bad URL replaces the old one with nothing (what the writer asked for), and says so' );
		$notice = bfrc_test_render( fn() => $e->notices() );
		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( 'https://example.com/my-video', $notice );
		$this->assertStringContainsString( 'not a YouTube or Vimeo video', $notice );
		$this->assertSame( '', bfrc_test_render( fn() => $e->notices() ), 'shown once' );
		$this->post_form( [ 'video_url' => '' ] );
		$e->save( 41, $this->recipe );
		$this->assertSame( '', bfrc_test_render( fn() => $e->notices() ), 'an empty field is a deliberate clear, not an error' );
	}

	public function test_nutrition_labels_cover_every_field(): void {
		$this->assertSame( array_keys( Meta::NUTRITION_FIELDS ), array_keys( RecipeEditor::nutrition_labels() ) );
	}
}
