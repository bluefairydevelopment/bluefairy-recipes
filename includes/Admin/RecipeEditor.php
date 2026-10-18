<?php
/**
 * The recipe edit screen: meta boxes and their save handler.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Admin;

use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Support\VideoUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Five PHP meta boxes (Details, Media, Ingredients, Steps, Nutrition) that
 * work in the block editor and the classic editor alike, with repeatable
 * rows driven by a small vanilla script. One save handler: capability first,
 * then the nonce, then every field through the same sanitisers REST uses
 * (Content\Meta), then `update_post_meta`. The add-on adds its own boxes on
 * `bfrc_recipe_meta_boxes` and its ingredient fields through the Meta seams.
 *
 * A video URL that is not a YouTube or Vimeo video is not stored; the writer
 * is told so by an admin notice on the reloaded screen rather than finding
 * the field silently empty.
 */
final class RecipeEditor {

	public const NONCE_ACTION = 'bfrc_recipe_meta';
	public const NONCE_FIELD  = 'bfrc_recipe_nonce';
	public const FIELD        = 'bfrc';
	public const SCRIPT       = 'bfrc-recipe-editor';
	public const NOTICE_TTL   = 300;

	/**
	 * Hook everything.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostType::NAME, [ $this, 'add_boxes' ] );
		add_action( 'save_post_' . PostType::NAME, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	/**
	 * Register the boxes on the recipe screen, then let the add-on add its own.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function add_boxes( \WP_Post $post ): void {
		add_meta_box( 'bfrc-details', __( 'Recipe Details', 'bluefairy-recipes' ), [ $this, 'render_details' ], PostType::NAME, 'side', 'high' );
		add_meta_box( 'bfrc-media', __( 'Recipe Video', 'bluefairy-recipes' ), [ $this, 'render_media' ], PostType::NAME, 'side', 'default' );
		add_meta_box( 'bfrc-ingredients', __( 'Ingredients', 'bluefairy-recipes' ), [ $this, 'render_ingredients' ], PostType::NAME, 'normal', 'high' );
		add_meta_box( 'bfrc-steps', __( 'Steps', 'bluefairy-recipes' ), [ $this, 'render_steps' ], PostType::NAME, 'normal', 'high' );
		add_meta_box( 'bfrc-nutrition', __( 'Nutrition', 'bluefairy-recipes' ), [ $this, 'render_nutrition' ], PostType::NAME, 'normal', 'default' );
		/**
		 * The recipe edit screen is adding its meta boxes; add yours.
		 *
		 * @since 1.0.0
		 *
		 * @param \WP_Post $post The recipe being edited.
		 */
		do_action( 'bfrc_recipe_meta_boxes', $post );
	}

	/**
	 * Details: times, servings, featured. Carries the nonce for every box.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function render_details( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$prep = Meta::sanitize_minutes( get_post_meta( $post->ID, Meta::PREP_MINUTES, true ) );
		$cook = Meta::sanitize_minutes( get_post_meta( $post->ID, Meta::COOK_MINUTES, true ) );
		$min  = Meta::sanitize_servings( get_post_meta( $post->ID, Meta::SERVINGS_MIN, true ) );
		$max  = Meta::sanitize_servings( get_post_meta( $post->ID, Meta::SERVINGS_MAX, true ) );
		$feat = Meta::sanitize_bool( get_post_meta( $post->ID, Meta::FEATURED, true ) );
		echo '<div class="bfrc-editor bfrc-editor--details">';
		$this->number_field( 'prep_minutes', __( 'Prep time (minutes)', 'bluefairy-recipes' ), $prep, Meta::MAX_MINUTES );
		$this->number_field( 'cook_minutes', __( 'Cook time (minutes)', 'bluefairy-recipes' ), $cook, Meta::MAX_MINUTES );
		$this->number_field( 'servings_min', __( 'Servings (min)', 'bluefairy-recipes' ), $min, Meta::MAX_SERVINGS );
		$this->number_field( 'servings_max', __( 'Servings (max)', 'bluefairy-recipes' ), $max, Meta::MAX_SERVINGS );
		echo '<p><label><input type="checkbox" name="' . esc_attr( self::FIELD . '[featured]' ) . '" value="1"' . checked( $feat, true, false ) . '> ' . esc_html__( 'Featured recipe', 'bluefairy-recipes' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( '0 means not shown. Servings max never drops below min.', 'bluefairy-recipes' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Media: image or video, and the video URL.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function render_media( \WP_Post $post ): void {
		$type = Meta::sanitize_media_type( get_post_meta( $post->ID, Meta::MEDIA_TYPE, true ) );
		$url  = VideoUrl::normalise( get_post_meta( $post->ID, Meta::VIDEO_URL, true ) );
		echo '<div class="bfrc-editor bfrc-editor--media">';
		echo '<p><label><input type="radio" name="' . esc_attr( self::FIELD . '[media_type]' ) . '" value="image"' . checked( $type, 'image', false ) . '> ' . esc_html__( 'Show the recipe photo', 'bluefairy-recipes' ) . '</label><br>';
		echo '<label><input type="radio" name="' . esc_attr( self::FIELD . '[media_type]' ) . '" value="video"' . checked( $type, 'video', false ) . '> ' . esc_html__( 'Show a video', 'bluefairy-recipes' ) . '</label></p>';
		echo '<p><label for="bfrc-video-url">' . esc_html__( 'YouTube or Vimeo URL', 'bluefairy-recipes' ) . '</label><br>';
		echo '<input type="url" id="bfrc-video-url" class="widefat" name="' . esc_attr( self::FIELD . '[video_url]' ) . '" value="' . esc_attr( $url ) . '" placeholder="https://www.youtube.com/watch?v=…"></p>';
		echo '<p class="description">' . esc_html__( 'Only YouTube and Vimeo videos are accepted; the video is embedded by its id, without cookies.', 'bluefairy-recipes' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Ingredients: repeatable rows of measure, label and type (plus the
	 * add-on's fields for its types).
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function render_ingredients( \WP_Post $post ): void {
		$rows   = Meta::sanitize_ingredients( get_post_meta( $post->ID, Meta::INGREDIENTS, true ) );
		$types  = Meta::ingredient_types();
		$extras = [];
		foreach ( $types as $type ) {
			$extras[ $type ] = array_keys( Meta::ingredient_row_fields( $type ) );
		}
		echo '<div class="bfrc-editor bfrc-rows" data-bfrc-rows="ingredients" data-bfrc-max="' . esc_attr( (string) Meta::MAX_ROWS ) . '">';
		echo '<table class="widefat bfrc-rows__table"><thead><tr><th class="bfrc-rows__handle"></th><th>' . esc_html__( 'Measure', 'bluefairy-recipes' ) . '</th><th>' . esc_html__( 'Ingredient', 'bluefairy-recipes' ) . '</th>';
		if ( count( $types ) > 1 ) {
			echo '<th>' . esc_html__( 'Type', 'bluefairy-recipes' ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			$this->ingredient_row( $i, $row, $types, $extras );
		}
		echo '</tbody></table>';
		echo '<template class="bfrc-rows__template">';
		$this->ingredient_row(
			'__INDEX__',
			[
				'measure' => '',
				'label'   => '',
				'type'    => 'plain',
			],
			$types,
			$extras
		);
		echo '</template>';
		echo '<p><button type="button" class="button bfrc-rows__add">' . esc_html__( 'Add ingredient', 'bluefairy-recipes' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Steps: repeatable numbered textareas.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function render_steps( \WP_Post $post ): void {
		$steps = Meta::sanitize_steps( get_post_meta( $post->ID, Meta::STEPS, true ) );
		echo '<div class="bfrc-editor bfrc-rows" data-bfrc-rows="steps" data-bfrc-max="' . esc_attr( (string) Meta::MAX_ROWS ) . '">';
		echo '<ol class="bfrc-rows__list">';
		foreach ( $steps as $i => $step ) {
			$this->step_row( $i, $step['text'] );
		}
		echo '</ol>';
		echo '<template class="bfrc-rows__template">';
		$this->step_row( '__INDEX__', '' );
		echo '</template>';
		echo '<p><button type="button" class="button bfrc-rows__add">' . esc_html__( 'Add step', 'bluefairy-recipes' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Nutrition: one number per field.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function render_nutrition( \WP_Post $post ): void {
		$values = Meta::sanitize_nutrition( get_post_meta( $post->ID, Meta::NUTRITION, true ) );
		$labels = self::nutrition_labels();
		echo '<div class="bfrc-editor bfrc-editor--nutrition">';
		foreach ( Meta::NUTRITION_FIELDS as $field => $unit ) {
			$value = $values[ $field ];
			echo '<p><label for="bfrc-nutrition-' . esc_attr( $field ) . '">' . esc_html( $labels[ $field ] ) . ' <span class="bfrc-unit">(' . esc_html( $unit ) . ')</span></label><br>';
			echo '<input type="number" step="0.01" min="0" max="' . esc_attr( (string) Meta::MAX_NUTRITION ) . '" id="bfrc-nutrition-' . esc_attr( $field ) . '" name="' . esc_attr( self::FIELD . '[nutrition][' . $field . ']' ) . '" value="' . esc_attr( null === $value ? '' : (string) $value ) . '"></p>';
		}
		echo '<p class="description">' . esc_html__( 'Leave a field empty to hide it.', 'bluefairy-recipes' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Translated nutrition field labels, keyed like Meta::NUTRITION_FIELDS.
	 *
	 * @return array<string, string>
	 */
	public static function nutrition_labels(): array {
		return [
			'calories'      => __( 'Calories', 'bluefairy-recipes' ),
			'fat'           => __( 'Fat', 'bluefairy-recipes' ),
			'saturated_fat' => __( 'Saturated fat', 'bluefairy-recipes' ),
			'carbohydrates' => __( 'Carbohydrates', 'bluefairy-recipes' ),
			'sugar'         => __( 'Sugar', 'bluefairy-recipes' ),
			'fiber'         => __( 'Fiber', 'bluefairy-recipes' ),
			'protein'       => __( 'Protein', 'bluefairy-recipes' ),
			'sodium'        => __( 'Sodium', 'bluefairy-recipes' ),
		];
	}

	/**
	 * Load the editor script and style on the recipe edit screen only.
	 *
	 * @param string $hook The admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || PostType::NAME !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( self::SCRIPT, BFRC_URL . 'assets/admin/recipe-editor.css', [], BFRC_VERSION );
		wp_enqueue_script( self::SCRIPT . '-rows', BFRC_URL . 'assets/admin/recipe-rows.js', [], BFRC_VERSION, true );
		wp_enqueue_script( self::SCRIPT, BFRC_URL . 'assets/admin/recipe-editor.js', [ self::SCRIPT . '-rows' ], BFRC_VERSION, true );
		wp_localize_script(
			self::SCRIPT,
			'bfrcRecipeEditor',
			[
				'maxRows' => Meta::MAX_ROWS,
				'i18n'    => [
					'remove'  => __( 'Remove', 'bluefairy-recipes' ),
					'up'      => __( 'Move up', 'bluefairy-recipes' ),
					'down'    => __( 'Move down', 'bluefairy-recipes' ),
					'maxRows' => __( 'No more rows can be added.', 'bluefairy-recipes' ),
				],
			]
		);
	}

	/**
	 * `save_post_bfrc_recipe`: capability, then nonce, then every field.
	 *
	 * Returns silently when the form was not posted (REST, quick edit, a
	 * programmatic save), on autosaves and revisions, so nothing is wiped by a
	 * request that did not carry the boxes.
	 *
	 * @param int      $post_id The recipe.
	 * @param \WP_Post $post    The recipe.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( PostType::NAME !== $post->post_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return; // Not our form.
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field below goes through its Content\Meta sanitiser.
		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : [];
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$video_raw = is_string( $raw['video_url'] ?? null ) ? trim( $raw['video_url'] ) : '';
		$video     = VideoUrl::normalise( $video_raw );
		update_post_meta( $post_id, Meta::MEDIA_TYPE, Meta::sanitize_media_type( $raw['media_type'] ?? 'image' ) );
		update_post_meta( $post_id, Meta::VIDEO_URL, $video );
		update_post_meta( $post_id, Meta::PREP_MINUTES, Meta::sanitize_minutes( $raw['prep_minutes'] ?? 0 ) );
		update_post_meta( $post_id, Meta::COOK_MINUTES, Meta::sanitize_minutes( $raw['cook_minutes'] ?? 0 ) );
		update_post_meta( $post_id, Meta::SERVINGS_MIN, Meta::sanitize_servings( $raw['servings_min'] ?? 0 ) );
		update_post_meta( $post_id, Meta::SERVINGS_MAX, Meta::sanitize_servings( $raw['servings_max'] ?? 0 ) );
		update_post_meta( $post_id, Meta::FEATURED, Meta::sanitize_bool( $raw['featured'] ?? false ) );
		update_post_meta( $post_id, Meta::INGREDIENTS, Meta::sanitize_ingredients( $raw['ingredients'] ?? [] ) );
		update_post_meta( $post_id, Meta::STEPS, Meta::sanitize_steps( $raw['steps'] ?? [] ) );
		update_post_meta( $post_id, Meta::NUTRITION, Meta::sanitize_nutrition( $raw['nutrition'] ?? [] ) );
		if ( '' !== $video_raw && '' === $video ) {
			set_transient( self::notice_key(), [ 'video_url' => $video_raw ], self::NOTICE_TTL );
		}
	}

	/**
	 * Tell the writer what was not stored, once, on the reloaded screen.
	 */
	public function notices(): void {
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::notice_key() );
		if ( isset( $notice['video_url'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			/* translators: %s: the URL that was typed */
			echo esc_html( sprintf( __( 'The video URL "%s" is not a YouTube or Vimeo video, so it was not saved. Paste the video’s own page URL.', 'bluefairy-recipes' ), (string) $notice['video_url'] ) );
			echo '</p></div>';
		}
	}

	/**
	 * The per-user transient that carries a notice across the redirect.
	 */
	private static function notice_key(): string {
		return 'bfrc_editor_notice_' . get_current_user_id();
	}

	/**
	 * One labelled number input.
	 *
	 * @param string $name  Field name under FIELD.
	 * @param string $label Label text.
	 * @param int    $value Current value.
	 * @param int    $max   Upper bound.
	 */
	private function number_field( string $name, string $label, int $value, int $max ): void {
		echo '<p><label for="bfrc-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><br>';
		echo '<input type="number" min="0" max="' . esc_attr( (string) $max ) . '" step="1" id="bfrc-' . esc_attr( $name ) . '" name="' . esc_attr( self::FIELD . '[' . $name . ']' ) . '" value="' . esc_attr( (string) $value ) . '"></p>';
	}

	/**
	 * One ingredient row.
	 *
	 * @param int|string                        $index  Row index, or the template placeholder.
	 * @param array<string, mixed>              $row    Row values.
	 * @param array<int, string>                $types  Ingredient types in force.
	 * @param array<string, array<int, string>> $extras Extra field names per type.
	 */
	private function ingredient_row( int|string $index, array $row, array $types, array $extras ): void {
		$base = self::FIELD . '[ingredients][' . $index . ']';
		echo '<tr class="bfrc-rows__row" draggable="true">';
		echo '<td class="bfrc-rows__handle" aria-hidden="true">⋮⋮</td>';
		echo '<td><input type="text" class="widefat" name="' . esc_attr( $base . '[measure]' ) . '" value="' . esc_attr( (string) ( $row['measure'] ?? '' ) ) . '" maxlength="' . esc_attr( (string) Meta::MEASURE_MAX ) . '" placeholder="2 cups"></td>';
		echo '<td><input type="text" class="widefat" name="' . esc_attr( $base . '[label]' ) . '" value="' . esc_attr( (string) ( $row['label'] ?? '' ) ) . '" maxlength="' . esc_attr( (string) Meta::LABEL_MAX ) . '" placeholder="' . esc_attr__( 'flour', 'bluefairy-recipes' ) . '"></td>';
		if ( count( $types ) > 1 ) {
			echo '<td><select name="' . esc_attr( $base . '[type]' ) . '" class="bfrc-rows__type">';
			foreach ( $types as $type ) {
				echo '<option value="' . esc_attr( $type ) . '"' . selected( (string) ( $row['type'] ?? 'plain' ), $type, false ) . '>' . esc_html( ucfirst( $type ) ) . '</option>';
			}
			echo '</select>';
			foreach ( $extras as $type => $fields ) {
				foreach ( $fields as $field ) {
					echo '<input type="text" class="widefat bfrc-rows__extra" data-bfrc-type="' . esc_attr( $type ) . '" name="' . esc_attr( $base . '[' . $field . ']' ) . '" value="' . esc_attr( is_scalar( $row[ $field ] ?? null ) ? (string) $row[ $field ] : '' ) . '" placeholder="' . esc_attr( $field ) . '">';
				}
			}
			echo '</td>';
		} else {
			echo '<input type="hidden" name="' . esc_attr( $base . '[type]' ) . '" value="plain">';
		}
		echo '<td class="bfrc-rows__actions"><button type="button" class="button-link bfrc-rows__up" aria-label="' . esc_attr__( 'Move up', 'bluefairy-recipes' ) . '">▲</button> <button type="button" class="button-link bfrc-rows__down" aria-label="' . esc_attr__( 'Move down', 'bluefairy-recipes' ) . '">▼</button> <button type="button" class="button-link bfrc-rows__remove" aria-label="' . esc_attr__( 'Remove', 'bluefairy-recipes' ) . '">✕</button></td>';
		echo '</tr>';
	}

	/**
	 * One step row.
	 *
	 * @param int|string $index Row index, or the template placeholder.
	 * @param string     $text  Step text.
	 */
	private function step_row( int|string $index, string $text ): void {
		echo '<li class="bfrc-rows__row" draggable="true"><span class="bfrc-rows__handle" aria-hidden="true">⋮⋮</span>';
		echo '<textarea class="widefat" rows="3" name="' . esc_attr( self::FIELD . '[steps][' . $index . '][text]' ) . '">' . esc_textarea( $text ) . '</textarea>';
		echo '<span class="bfrc-rows__actions"><button type="button" class="button-link bfrc-rows__up" aria-label="' . esc_attr__( 'Move up', 'bluefairy-recipes' ) . '">▲</button> <button type="button" class="button-link bfrc-rows__down" aria-label="' . esc_attr__( 'Move down', 'bluefairy-recipes' ) . '">▼</button> <button type="button" class="button-link bfrc-rows__remove" aria-label="' . esc_attr__( 'Remove', 'bluefairy-recipes' ) . '">✕</button></span></li>';
	}
}
