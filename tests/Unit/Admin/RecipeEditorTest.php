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
	 * A valid form post for recipe 41, with every box's sentinel unless told otherwise.
	 *
	 * @param array<string, mixed> $fields   The `bfrc` fields.
	 * @param array<int, string>   $sections The boxes that "arrived".
	 */
	private function post_form( array $fields, bool $nonce = true, array $sections = RecipeEditor::SECTIONS ): void {
		$fields['_has'] = array_fill_keys( $sections, '1' );
		$_POST          = [
			RecipeEditor::NONCE_FIELD => $nonce ? wp_create_nonce( RecipeEditor::NONCE_ACTION ) : 'wrong',
			RecipeEditor::FIELD       => wp_slash( $fields ), // As core: request data arrives slashed.
		];
		$GLOBALS['bfrc_test_nonce_valid'] = $nonce;
	}

	private function as_curator(): void {
		bfrc_test_grant( 'edit_post', RecipeEditor::CURATE_CAP );
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

	public function test_can_curate_is_edit_others_bfrc_recipes(): void {
		$this->assertFalse( RecipeEditor::can_curate() );
		bfrc_test_grant( 'edit_post' );
		$this->assertFalse( RecipeEditor::can_curate(), 'editing the recipe is not curating' );
		bfrc_test_grant( 'edit_others_bfrc_recipes' );
		$this->assertTrue( RecipeEditor::can_curate() );
		$this->assertSame( Meta::CURATE_CAP, RecipeEditor::CURATE_CAP, 'one capability, shared with the REST auth callback' );
	}

	public function test_render_details_carries_the_nonce_the_values_no_html_constraints_and_the_sentinel(): void {
		update_post_meta( 41, Meta::PREP_MINUTES, 20 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 6 );
		update_post_meta( 41, Meta::FEATURED, true );
		$html = bfrc_test_render( fn() => ( new RecipeEditor() )->render_details( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc_recipe_nonce"', $html );
		$this->assertStringNotContainsString( '_wp_http_referer', $html, 'the form already has one' );
		$this->assertStringContainsString( 'name="bfrc[prep_minutes]" value="20"', $html );
		$this->assertStringContainsString( 'name="bfrc[servings_max]" value="6"', $html );
		$this->assertStringNotContainsString( 'max="', $html, 'no HTML constraint: it would block Publish from a collapsed classic-editor box' );
		$this->assertStringNotContainsString( 'min="', $html );
		$this->assertStringContainsString( 'name="bfrc[_has][details]" value="1"', $html, 'the sentinel' );
		$this->assertStringNotContainsString( 'name="bfrc[featured]"', $html, 'a writer sees Featured read-only' );
		$this->assertStringContainsString( 'Featured recipe: yes', $html );
		$this->as_curator();
		$html = bfrc_test_render( fn() => ( new RecipeEditor() )->render_details( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc[featured]" value="1" checked="checked"', $html, 'a curator gets the checkbox' );
	}

	public function test_render_media_and_nutrition_show_stored_values_and_escape_them(): void {
		update_post_meta( 41, Meta::MEDIA_TYPE, 'video' );
		update_post_meta( 41, Meta::VIDEO_URL, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
		update_post_meta( 41, Meta::NUTRITION, Meta::sanitize_nutrition( [ 'calories' => 250, 'sodium' => '12.5' ] ) );
		$media = bfrc_test_render( fn() => ( new RecipeEditor() )->render_media( $this->recipe ) );
		$this->assertStringContainsString( 'value="video" checked="checked"', $media );
		$this->assertStringContainsString( 'type="text" inputmode="url" id="bfrc-video-url"', $media, 'not type=url: the server decides and says so' );
		$this->assertStringContainsString( 'value="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $media );
		$this->assertStringContainsString( 'name="bfrc[_has][media]" value="1"', $media );
		$nutrition = bfrc_test_render( fn() => ( new RecipeEditor() )->render_nutrition( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc[nutrition][calories]" value="250"', $nutrition );
		$this->assertStringContainsString( 'name="bfrc[nutrition][sodium]" value="12.5"', $nutrition );
		$this->assertStringContainsString( 'name="bfrc[nutrition][fat]" value=""', $nutrition, 'null renders empty, never 0' );
		$this->assertSame( 8, substr_count( $nutrition, 'type="number" step="any"' ) );
		$this->assertStringNotContainsString( 'max="', $nutrition );
		$this->assertStringContainsString( 'name="bfrc[_has][nutrition]" value="1"', $nutrition );
	}

	public function test_render_ingredients_and_steps_render_rows_a_template_labels_and_escape_content(): void {
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '2 cups', 'label' => 'flour "00"', 'type' => 'plain' ], [ 'measure' => '', 'label' => 'salt', 'type' => 'plain' ] ] );
		update_post_meta( 41, Meta::STEPS, [ [ 'text' => 'Mix <em>well</em>' ], [ 'text' => 'Bake & serve' ] ] );
		$ing = bfrc_test_render( fn() => ( new RecipeEditor() )->render_ingredients( $this->recipe ) );
		$this->assertSame( 3, substr_count( $ing, '<tr class="bfrc-rows__row">' ), 'two stored rows plus the template row' );
		$this->assertStringContainsString( 'name="bfrc[ingredients][0][label]" value="flour &quot;00&quot;"', $ing );
		$this->assertStringContainsString( 'aria-label="Ingredient 2 name"', $ing );
		$this->assertStringContainsString( 'name="bfrc[ingredients][1][measure]" value=""', $ing );
		$this->assertStringContainsString( 'name="bfrc[ingredients][__INDEX__][label]"', $ing, 'the template row for the script' );
		$this->assertStringContainsString( 'type="hidden" name="bfrc[ingredients][0][type]" value="plain"', $ing, 'one type: no selector' );
		$this->assertStringContainsString( 'data-bfrc-max="200"', $ing );
		$this->assertStringContainsString( '<td class="bfrc-rows__handle" draggable="true"', $ing, 'the handle drags, not the row' );
		$this->assertStringNotContainsString( '<tr class="bfrc-rows__row" draggable', $ing );
		$this->assertStringContainsString( 'name="bfrc[_has][ingredients]" value="1"', $ing );
		$steps = bfrc_test_render( fn() => ( new RecipeEditor() )->render_steps( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc[steps][0][text]" aria-label="Step 1">Mix &lt;em&gt;well&lt;/em&gt;</textarea>', $steps, 'textarea content is escaped' );
		$this->assertStringContainsString( 'name="bfrc[steps][1][text]" aria-label="Step 2">Bake &amp; serve</textarea>', $steps );
		$this->assertStringContainsString( 'name="bfrc[steps][__INDEX__][text]"', $steps );
		$this->assertStringContainsString( '<li class="bfrc-rows__row"><div class="bfrc-rows__grid"><span class="bfrc-rows__handle" draggable="true"', $steps, 'the li stays a list item (numbered); the grid is inside' );
		$this->assertStringContainsString( 'name="bfrc[_has][steps]" value="1"', $steps );
	}

	public function test_an_empty_recipe_renders_one_blank_row_so_it_works_without_javascript(): void {
		$ing   = bfrc_test_render( fn() => ( new RecipeEditor() )->render_ingredients( $this->recipe ) );
		$steps = bfrc_test_render( fn() => ( new RecipeEditor() )->render_steps( $this->recipe ) );
		$this->assertStringContainsString( 'name="bfrc[ingredients][0][label]" value=""', $ing );
		$this->assertStringContainsString( '<noscript>', $ing );
		$this->assertStringContainsString( 'name="bfrc[steps][0][text]" aria-label="Step 1"></textarea>', $steps );
	}

	public function test_render_ingredients_shows_a_type_selector_with_labels_and_only_the_active_types_extras_enabled(): void {
		add_filter( 'bfrc_ingredient_types', static fn( array $t ): array => array_merge( $t, [ 'internal', 'affiliate' ] ) );
		add_filter( 'bfrc_ingredient_row_fields', static fn( array $f, string $type ): array => match ( $type ) { 'internal' => [ 'product_id' => 'absint' ], 'affiliate' => [ 'url' => 'esc_url_raw' ], default => $f }, 10, 2 );
		add_filter( 'bfrc_ingredient_type_labels', static fn( array $l ): array => $l + [ 'internal' => 'Shop product' ] );
		add_filter( 'bfrc_ingredient_row_field_labels', static fn( array $l, string $type ): array => 'internal' === $type ? [ 'product_id' => 'Product' ] : $l, 10, 2 );
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '', 'label' => 'butter', 'type' => 'internal', 'product_id' => 12 ] ] );
		$ing = bfrc_test_render( fn() => ( new RecipeEditor() )->render_ingredients( $this->recipe ) );
		$this->assertStringContainsString( '<option value="plain">Plain</option><option value="internal" selected="selected">Shop product</option><option value="affiliate">Affiliate</option>', $ing, 'filtered label, ucfirst fallback' );
		$this->assertStringContainsString( 'data-bfrc-type="internal" data-bfrc-field="product_id" name="bfrc[ingredients][0][product_id]" value="12" placeholder="Product" aria-label="Ingredient 1 Product"', $ing, 'the active type: enabled, with its value and label' );
		$this->assertStringContainsString( 'data-bfrc-type="affiliate" data-bfrc-field="url" hidden disabled name="bfrc[ingredients][0][url]" value=""', $ing, 'another type: hidden AND disabled, so it is not posted' );
	}

	public function test_type_and_field_label_helpers_fall_back_and_ignore_junk_from_the_filters(): void {
		add_filter( 'bfrc_ingredient_type_labels', static fn(): string => 'junk' );
		$this->assertSame( [ 'plain' => 'Plain', 'x_y' => 'X_y' ], RecipeEditor::ingredient_type_labels( [ 'plain', 'x_y' ] ) );
		add_filter( 'bfrc_ingredient_row_fields', static fn( array $f, string $type ): array => 'internal' === $type ? [ 'product_id' => 'absint', 'note' => 'sanitize_text_field' ] : $f, 10, 2 );
		add_filter( 'bfrc_ingredient_row_field_labels', static fn( array $l ): array => [ 'product_id' => '', 'other' => 'ignored' ] );
		$this->assertSame( [ 'product_id' => 'product_id', 'note' => 'note' ], RecipeEditor::ingredient_row_field_labels( 'internal' ), 'an empty label falls back; an unknown field is dropped' );
		$this->assertSame( [], RecipeEditor::ingredient_row_field_labels( 'plain' ) );
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
		$config = $GLOBALS['bfrc_test_enqueued']['localize']['bfrc-recipe-editor']['bfrcRecipeEditor'];
		$this->assertSame( '200', $config['maxRows'], 'as core: a string in the browser' );
		$this->assertSame( (string) RecipeEditor::max_input_vars(), $config['maxInputVars'] );
		$this->assertSame( '', $config['restPath'], 'classic editor: no read-back, the reload shows the notice' );
		$this->assertSame( [ 'bfrc-recipe-editor-rows', 'wp-a11y' ], $GLOBALS['bfrc_test_enqueued']['deps']['bfrc-recipe-editor'] );
		$this->assertSame( [ 'ingredients', 'steps' ], array_keys( $config['i18n']['rowLabels'] ) );
		$this->assertStringContainsString( '%d', $config['i18n']['rowLabels']['steps']['text'] );
	}

	public function test_enqueue_in_the_block_editor_adds_the_read_back_path_and_its_dependencies(): void {
		$screen                      = bfrc_test_screen_for( 'bfrc_recipe' );
		$screen->is_block_editor     = true;
		$GLOBALS['bfrc_test_screen'] = $screen;
		$GLOBALS['post']             = $this->recipe;
		( new RecipeEditor() )->enqueue( 'post.php' );
		$config = $GLOBALS['bfrc_test_enqueued']['localize']['bfrc-recipe-editor']['bfrcRecipeEditor'];
		$this->assertSame( '/wp/v2/bfrc_recipe/41', $config['restPath'] );
		$this->assertSame( '_bfrc_video_url', $config['videoKey'] );
		$this->assertSame( RecipeEditor::video_rejected_message(), $config['i18n']['videoRejected'], 'the script and the server notice say the same thing' );
		$this->assertSame( [ 'bfrc-recipe-editor-rows', 'wp-a11y', 'wp-data', 'wp-api-fetch' ], $GLOBALS['bfrc_test_enqueued']['deps']['bfrc-recipe-editor'] );
	}

	public function test_max_input_vars_reads_the_ini_setting(): void {
		$this->assertSame( (int) ini_get( 'max_input_vars' ), RecipeEditor::max_input_vars() );
		$this->assertGreaterThan( 0, RecipeEditor::max_input_vars(), 'PHP always defines it (default 1000)' );
	}

	public function test_save_writes_every_field_through_the_sanitisers_and_keeps_backslashes(): void {
		$this->as_curator();
		$seen = null;
		add_action( 'bfrc_recipe_saved', static function ( int $id, array $clean ) use ( &$seen ): void {
			$seen = [ $id, array_keys( $clean ) ];
		}, 10, 2 );
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
				'steps'        => [ [ 'text' => ' Mix 1\\2 tsp <script>x()</script> ' ], [ 'text' => '' ] ],
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
		$this->assertSame( [ [ 'text' => 'Mix 1\\2 tsp' ] ], get_post_meta( 41, Meta::STEPS, true ), 'core unslashes on write, so the value is slashed first: the backslash survives' );
		$this->assertSame( 250.0, get_post_meta( 41, Meta::NUTRITION, true )['calories'] );
		$this->assertNull( get_post_meta( 41, Meta::NUTRITION, true )['fat'] );
		$this->assertFalse( get_transient( 'bfrc_editor_notice_9' ), 'a valid video URL: no notice' );
		$this->assertSame( 41, $seen[0] );
		$this->assertSame( [ Meta::PREP_MINUTES, Meta::COOK_MINUTES, Meta::SERVINGS_MIN, Meta::SERVINGS_MAX, Meta::FEATURED, Meta::MEDIA_TYPE, Meta::VIDEO_URL, Meta::INGREDIENTS, Meta::STEPS, Meta::NUTRITION ], $seen[1], 'the seam carries every value written' );
	}

	public function test_a_writer_cannot_set_or_clear_featured_but_everything_else_saves(): void {
		bfrc_test_grant( 'edit_post' );
		update_post_meta( 41, Meta::FEATURED, true );
		$this->post_form( [ 'prep_minutes' => '5', 'featured' => '0' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertTrue( get_post_meta( 41, Meta::FEATURED, true ), 'the manager\'s flag survives the writer\'s save' );
		$this->assertSame( 5, get_post_meta( 41, Meta::PREP_MINUTES, true ) );
		update_post_meta( 41, Meta::FEATURED, false );
		$this->post_form( [ 'featured' => '1' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertFalse( get_post_meta( 41, Meta::FEATURED, true ), 'a crafted featured=1 from a writer is ignored' );
	}

	public function test_an_unticked_checkbox_and_missing_rows_clear_the_stored_values(): void {
		$this->as_curator();
		update_post_meta( 41, Meta::FEATURED, true );
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '', 'label' => 'x', 'type' => 'plain' ] ] );
		$this->post_form( [ 'prep_minutes' => '5' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertFalse( get_post_meta( 41, Meta::FEATURED, true ), 'form semantics: an absent checkbox is unticked' );
		$this->assertSame( [], get_post_meta( 41, Meta::INGREDIENTS, true ), 'all rows removed in the editor' );
	}

	public function test_a_section_whose_sentinel_did_not_arrive_keeps_its_values(): void {
		$this->as_curator();
		update_post_meta( 41, Meta::INGREDIENTS, [ [ 'measure' => '', 'label' => 'kept', 'type' => 'plain' ] ] );
		update_post_meta( 41, Meta::STEPS, [ [ 'text' => 'kept too' ] ] );
		update_post_meta( 41, Meta::NUTRITION, Meta::sanitize_nutrition( [ 'calories' => 9 ] ) );
		update_post_meta( 41, Meta::FEATURED, true );
		// The form was truncated after the media box (or the other boxes were removed): only details + media arrived.
		$this->post_form( [ 'prep_minutes' => '7', 'video_url' => '' ], true, [ 'details', 'media' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertSame( 7, get_post_meta( 41, Meta::PREP_MINUTES, true ), 'the sections that arrived are written' );
		$this->assertFalse( get_post_meta( 41, Meta::FEATURED, true ), 'details arrived: its unticked box counts' );
		$this->assertSame( 'kept', get_post_meta( 41, Meta::INGREDIENTS, true )[0]['label'] );
		$this->assertSame( 'kept too', get_post_meta( 41, Meta::STEPS, true )[0]['text'] );
		$this->assertSame( 9.0, get_post_meta( 41, Meta::NUTRITION, true )['calories'] );
		$this->post_form( [ 'prep_minutes' => '8' ], true, [] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertSame( 7, get_post_meta( 41, Meta::PREP_MINUTES, true ), 'no sentinel at all: nothing written' );
	}

	public function test_servings_are_judged_as_a_whole_so_the_field_the_writer_changed_wins(): void {
		bfrc_test_grant( 'edit_post' );
		( new Meta() )->register_hooks();
		update_post_meta( 41, Meta::SERVINGS_MIN, 2 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 4 );
		$this->post_form( [ 'servings_min' => '6', 'servings_max' => '4' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertSame( [ 6, 6 ], [ get_post_meta( 41, Meta::SERVINGS_MIN, true ), get_post_meta( 41, Meta::SERVINGS_MAX, true ) ], 'min raised above max: max follows' );
		$this->post_form( [ 'servings_min' => '6', 'servings_max' => '3' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertSame( [ 3, 3 ], [ get_post_meta( 41, Meta::SERVINGS_MIN, true ), get_post_meta( 41, Meta::SERVINGS_MAX, true ) ], 'max lowered below min: min follows' );
		$this->post_form( [ 'servings_min' => '10', 'servings_max' => '8' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertSame( [ 10, 10 ], [ get_post_meta( 41, Meta::SERVINGS_MIN, true ), get_post_meta( 41, Meta::SERVINGS_MAX, true ) ], 'both changed and min > max: max is raised to min' );
		$this->post_form( [ 'servings_min' => '2', 'servings_max' => '0' ] );
		( new RecipeEditor() )->save( 41, $this->recipe );
		$this->assertSame( [ 2, 0 ], [ get_post_meta( 41, Meta::SERVINGS_MIN, true ), get_post_meta( 41, Meta::SERVINGS_MAX, true ) ], 'max 0 = not set, left alone' );
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
		$_POST = [ RecipeEditor::FIELD => [ 'prep_minutes' => '5', '_has' => [ 'details' => '1' ] ] ];
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::PREP_MINUTES, true ), 'no nonce field at all (REST, quick edit): not our form, nothing wiped' );
		$_POST = [ RecipeEditor::NONCE_FIELD => [ 'x' ], RecipeEditor::FIELD => [ 'prep_minutes' => '5', '_has' => [ 'details' => '1' ] ] ];
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::PREP_MINUTES, true ), 'an array nonce: refused without a warning' );
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
		$GLOBALS['bfrc_test_screen'] = bfrc_test_screen_for( 'bfrc_recipe' );
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
		$this->post_form( [ 'video_url' => 'https://example.com/' . str_repeat( 'a', 1000 ) ] );
		$e->save( 41, $this->recipe );
		$this->assertSame( RecipeEditor::NOTICE_MAX, mb_strlen( get_transient( 'bfrc_editor_notice_9' )['video_url'] ), 'the stored notice is capped' );
	}

	public function test_the_notice_is_held_for_the_recipe_screen_and_skipped_for_a_background_meta_box_save(): void {
		bfrc_test_grant( 'edit_post' );
		$e = new RecipeEditor();
		$this->post_form( [ 'video_url' => 'https://example.com/my-video' ] );
		$e->save( 41, $this->recipe );
		$this->assertIsArray( get_transient( 'bfrc_editor_notice_9' ) );
		$this->assertSame( '', bfrc_test_render( fn() => $e->notices() ), 'no screen: held' );
		$GLOBALS['bfrc_test_screen'] = bfrc_test_screen_for( 'post' );
		$this->assertSame( '', bfrc_test_render( fn() => $e->notices() ), 'another edit screen: held' );
		$GLOBALS['bfrc_test_screen'] = bfrc_test_screen_for( 'bfrc_recipe', 'edit' );
		$this->assertSame( '', bfrc_test_render( fn() => $e->notices() ), 'the recipes list: held' );
		$GLOBALS['bfrc_test_screen'] = bfrc_test_screen_for( 'bfrc_recipe' );
		$this->assertStringContainsString( 'my-video', bfrc_test_render( fn() => $e->notices() ), 'the recipe edit screen: shown' );
		$_REQUEST['meta-box-loader'] = '1';
		$this->post_form( [ 'video_url' => 'https://example.com/my-video' ] );
		$e->save( 41, $this->recipe );
		$this->assertSame( '', get_post_meta( 41, Meta::VIDEO_URL, true ), 'still refused' );
		$this->assertFalse( get_transient( 'bfrc_editor_notice_9' ), 'block editor background save: the script reports it, no transient is left for a later screen' );
		unset( $_REQUEST['meta-box-loader'] );
	}

	public function test_nutrition_labels_cover_every_field_and_the_message_has_a_placeholder(): void {
		$this->assertSame( array_keys( Meta::NUTRITION_FIELDS ), array_keys( RecipeEditor::nutrition_labels() ) );
		$this->assertStringContainsString( '%s', RecipeEditor::video_rejected_message() );
	}
}
