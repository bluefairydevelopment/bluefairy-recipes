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
 * Each box ends with a hidden sentinel (`bfrc[_has][section]`); a section is
 * written only when its sentinel arrived, so a box another plugin removed, or
 * a form PHP truncated at `max_input_vars`, keeps its stored values instead
 * of being wiped.
 *
 * A video URL that is not a YouTube or Vimeo video is not stored; the writer
 * is told so (inline in the block editor, by an admin notice after the
 * classic editor's reload) rather than finding the field silently empty.
 */
final class RecipeEditor {

	public const NONCE_ACTION = 'bfrc_recipe_meta';
	public const NONCE_FIELD  = 'bfrc_recipe_nonce';
	public const FIELD        = 'bfrc';
	public const SCRIPT       = 'bfrc-recipe-editor';
	public const NOTICE_TTL   = 300;
	public const NOTICE_MAX   = 300;
	public const CURATE_CAP   = Meta::CURATE_CAP;
	public const SECTIONS     = [ 'details', 'media', 'ingredients', 'steps', 'nutrition' ];

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
	 * May the current user curate (set Featured)? Recipe Managers, Editors and
	 * Administrators hold `edit_others_bfrc_recipes`; a Writer does not.
	 */
	public static function can_curate(): bool {
		return current_user_can( self::CURATE_CAP );
	}

	/**
	 * Details: times, servings, featured. Carries the nonce for every box.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function render_details( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
		$prep = Meta::sanitize_minutes( get_post_meta( $post->ID, Meta::PREP_MINUTES, true ) );
		$cook = Meta::sanitize_minutes( get_post_meta( $post->ID, Meta::COOK_MINUTES, true ) );
		$min  = Meta::sanitize_servings( get_post_meta( $post->ID, Meta::SERVINGS_MIN, true ) );
		$max  = Meta::sanitize_servings( get_post_meta( $post->ID, Meta::SERVINGS_MAX, true ) );
		$feat = Meta::sanitize_bool( get_post_meta( $post->ID, Meta::FEATURED, true ) );
		echo '<div class="bfrc-editor bfrc-editor--details">';
		$this->number_field( 'prep_minutes', __( 'Prep time (minutes)', 'bluefairy-recipes' ), $prep );
		$this->number_field( 'cook_minutes', __( 'Cook time (minutes)', 'bluefairy-recipes' ), $cook );
		$this->number_field( 'servings_min', __( 'Servings (min)', 'bluefairy-recipes' ), $min );
		$this->number_field( 'servings_max', __( 'Servings (max)', 'bluefairy-recipes' ), $max );
		if ( self::can_curate() ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( self::FIELD . '[featured]' ) . '" value="1"' . checked( $feat, true, false ) . '> ' . esc_html__( 'Featured recipe', 'bluefairy-recipes' ) . '</label></p>';
		} else {
			echo '<p class="bfrc-editor__readonly">' . esc_html( $feat ? __( 'Featured recipe: yes', 'bluefairy-recipes' ) : __( 'Featured recipe: no', 'bluefairy-recipes' ) ) . ' <span class="description">' . esc_html__( '(set by a recipe manager)', 'bluefairy-recipes' ) . '</span></p>';
		}
		echo '<p class="description">' . esc_html__( '0 means not shown. Servings max never drops below min; raising min above max raises max.', 'bluefairy-recipes' ) . '</p>';
		$this->sentinel( 'details' );
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
		// type="text": the server decides validity and says so; a type="url" field in a collapsed classic-editor box would block Publish with no message.
		echo '<input type="text" inputmode="url" id="bfrc-video-url" class="widefat" name="' . esc_attr( self::FIELD . '[video_url]' ) . '" value="' . esc_attr( $url ) . '" placeholder="https://www.youtube.com/watch?v=…"></p>';
		echo '<p class="description">' . esc_html__( 'Only YouTube and Vimeo videos are accepted; the video is embedded by its id, without cookies.', 'bluefairy-recipes' ) . '</p>';
		$this->sentinel( 'media' );
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
		$labels = self::ingredient_type_labels( $types );
		$extras = [];
		foreach ( $types as $type ) {
			$extras[ $type ] = self::ingredient_row_field_labels( $type );
		}
		echo '<div class="bfrc-editor bfrc-rows" data-bfrc-rows="ingredients" data-bfrc-max="' . esc_attr( (string) Meta::MAX_ROWS ) . '">';
		if ( [] === $rows ) {
			echo '<noscript><p class="description">' . esc_html__( 'Enable JavaScript to add more than one row at a time.', 'bluefairy-recipes' ) . '</p></noscript>';
			$rows[] = [
				'measure' => '',
				'label'   => '',
				'type'    => 'plain',
			];
		}
		echo '<table class="widefat bfrc-rows__table"><thead><tr><th class="bfrc-rows__handle"><span class="screen-reader-text">' . esc_html__( 'Order', 'bluefairy-recipes' ) . '</span></th><th>' . esc_html__( 'Measure', 'bluefairy-recipes' ) . '</th><th>' . esc_html__( 'Ingredient', 'bluefairy-recipes' ) . '</th>';
		if ( count( $types ) > 1 ) {
			echo '<th>' . esc_html__( 'Type', 'bluefairy-recipes' ) . '</th>';
		}
		echo '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'bluefairy-recipes' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			$this->ingredient_row( $i, $row, $types, $labels, $extras );
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
			$labels,
			$extras
		);
		echo '</template>';
		echo '<p><button type="button" class="button bfrc-rows__add">' . esc_html__( 'Add ingredient', 'bluefairy-recipes' ) . '</button> <span class="bfrc-rows__limit description" role="status" aria-live="polite"></span></p>';
		$this->sentinel( 'ingredients' );
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
		if ( [] === $steps ) {
			$steps[] = [ 'text' => '' ];
		}
		echo '<ol class="bfrc-rows__list">';
		foreach ( $steps as $i => $step ) {
			$this->step_row( $i, $step['text'] );
		}
		echo '</ol>';
		echo '<template class="bfrc-rows__template">';
		$this->step_row( '__INDEX__', '' );
		echo '</template>';
		echo '<p><button type="button" class="button bfrc-rows__add">' . esc_html__( 'Add step', 'bluefairy-recipes' ) . '</button> <span class="bfrc-rows__limit description" role="status" aria-live="polite"></span></p>';
		$this->sentinel( 'steps' );
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
			// No min/max/step constraints: the sanitiser clamps, and a constraint in a collapsed classic-editor box would block Publish silently.
			echo '<input type="number" step="any" inputmode="decimal" id="bfrc-nutrition-' . esc_attr( $field ) . '" name="' . esc_attr( self::FIELD . '[nutrition][' . $field . ']' ) . '" value="' . esc_attr( null === $value ? '' : (string) $value ) . '"></p>';
		}
		echo '<p class="description">' . esc_html__( 'Leave a field empty to hide it.', 'bluefairy-recipes' ) . '</p>';
		$this->sentinel( 'nutrition' );
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
	 * Human labels for ingredient row types.
	 *
	 * @param array<int, string> $types The types in force.
	 * @return array<string, string> type => label
	 */
	public static function ingredient_type_labels( array $types ): array {
		$labels = [ 'plain' => __( 'Plain', 'bluefairy-recipes' ) ];
		/**
		 * Labels for ingredient row types; the add-on names its own types.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $labels type => label.
		 * @param array<int, string>    $types  The types in force.
		 */
		$labels = apply_filters( 'bfrc_ingredient_type_labels', $labels, $types );
		$out    = [];
		foreach ( $types as $type ) {
			$label        = is_array( $labels ) && isset( $labels[ $type ] ) && is_string( $labels[ $type ] ) && '' !== $labels[ $type ] ? $labels[ $type ] : ucfirst( $type );
			$out[ $type ] = $label;
		}
		return $out;
	}

	/**
	 * Labels for a type's extra row fields (the fields themselves come from
	 * `Meta::ingredient_row_fields()`).
	 *
	 * @param string $type The row type.
	 * @return array<string, string> field => label
	 */
	public static function ingredient_row_field_labels( string $type ): array {
		$fields = array_keys( Meta::ingredient_row_fields( $type ) );
		/**
		 * Labels for the extra fields of an ingredient row type.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $labels field => label (defaults to the field name).
		 * @param string                $type   The row type.
		 */
		$labels = apply_filters( 'bfrc_ingredient_row_field_labels', array_combine( $fields, $fields ), $type );
		$out    = [];
		foreach ( $fields as $field ) {
			$out[ $field ] = is_array( $labels ) && isset( $labels[ $field ] ) && is_string( $labels[ $field ] ) && '' !== $labels[ $field ] ? $labels[ $field ] : $field;
		}
		return $out;
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
		// The block editor posts the meta boxes in the background and never reloads, so the
		// server's notice about a refused video URL cannot reach the writer; the script reads
		// the stored value back after each meta-box save instead. Classic editor: full reload.
		$block = $screen->is_block_editor;
		$post  = get_post();
		$deps  = [ self::SCRIPT . '-rows', 'wp-a11y' ];
		if ( $block ) {
			$deps[] = 'wp-data';
			$deps[] = 'wp-api-fetch';
		}
		wp_enqueue_style( self::SCRIPT, BFRC_URL . 'assets/admin/recipe-editor.css', [], BFRC_VERSION );
		wp_enqueue_script( self::SCRIPT . '-rows', BFRC_URL . 'assets/admin/recipe-rows.js', [], BFRC_VERSION, true );
		wp_enqueue_script( self::SCRIPT, BFRC_URL . 'assets/admin/recipe-editor.js', $deps, BFRC_VERSION, true );
		wp_localize_script(
			self::SCRIPT,
			'bfrcRecipeEditor',
			[
				'maxRows'      => (string) Meta::MAX_ROWS,
				'maxInputVars' => (string) self::max_input_vars(),
				'restPath'     => $block && $post instanceof \WP_Post ? '/wp/v2/' . PostType::NAME . '/' . $post->ID : '',
				'videoKey'     => Meta::VIDEO_URL,
				'i18n'         => [
					'maxRows'       => __( 'No more rows can be added.', 'bluefairy-recipes' ),
					'tooManyFields' => __( 'This recipe has more fields than the server accepts in one save (PHP max_input_vars). Remove some rows or ask your host to raise the limit; otherwise some panels will keep their previous values.', 'bluefairy-recipes' ),
					/* translators: %d: the row's new position */
					'moved'         => __( 'Moved to position %d.', 'bluefairy-recipes' ),
					'videoRejected' => self::video_rejected_message(),
					'rowLabels'     => [
						'ingredients' => [
							/* translators: %d: row number */
							'measure' => __( 'Ingredient %d measure', 'bluefairy-recipes' ),
							/* translators: %d: row number */
							'label'   => __( 'Ingredient %d name', 'bluefairy-recipes' ),
							/* translators: %d: row number */
							'type'    => __( 'Ingredient %d type', 'bluefairy-recipes' ),
							/* translators: 1: row number, 2: field label */
							'extra'   => __( 'Ingredient %1$d %2$s', 'bluefairy-recipes' ),
						],
						'steps'       => [
							/* translators: %d: step number */
							'text' => __( 'Step %d', 'bluefairy-recipes' ),
						],
					],
				],
			]
		);
	}

	/**
	 * PHP's input-variable ceiling for one request (0 when unknown).
	 */
	public static function max_input_vars(): int {
		$value = ini_get( 'max_input_vars' );
		return is_string( $value ) && is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * `save_post_bfrc_recipe`: capability, then nonce, then every field.
	 *
	 * Returns silently when the form was not posted (REST, quick edit, a
	 * programmatic save), on autosaves and revisions, so nothing is wiped by a
	 * request that did not carry the boxes. A section whose sentinel did not
	 * arrive (box removed, or the form truncated by PHP) keeps its values.
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
		$nonce = $_POST[ self::NONCE_FIELD ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- this IS the nonce; type-checked, unslashed and verified on the next line.
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), self::NONCE_ACTION ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field below goes through its Content\Meta sanitiser.
		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : [];
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$has = is_array( $raw['_has'] ?? null ) ? $raw['_has'] : [];
		foreach ( self::SECTIONS as $section ) {
			$has[ $section ] = isset( $has[ $section ] ) && '1' === $has[ $section ];
		}
		$clean = [];
		if ( $has['details'] ) {
			$clean[ Meta::PREP_MINUTES ] = Meta::sanitize_minutes( $raw['prep_minutes'] ?? 0 );
			$clean[ Meta::COOK_MINUTES ] = Meta::sanitize_minutes( $raw['cook_minutes'] ?? 0 );
			$this->write( $post_id, Meta::PREP_MINUTES, $clean[ Meta::PREP_MINUTES ] );
			$this->write( $post_id, Meta::COOK_MINUTES, $clean[ Meta::COOK_MINUTES ] );
			$this->write_servings( $post_id, Meta::sanitize_servings( $raw['servings_min'] ?? 0 ), Meta::sanitize_servings( $raw['servings_max'] ?? 0 ), $clean );
			if ( self::can_curate() ) {
				// A writer's form carries no Featured field; the manager's flag stays as it is.
				$clean[ Meta::FEATURED ] = Meta::sanitize_bool( $raw['featured'] ?? false );
				$this->write( $post_id, Meta::FEATURED, $clean[ Meta::FEATURED ] );
			}
		}
		$video_raw = '';
		if ( $has['media'] ) {
			$video_raw                 = is_string( $raw['video_url'] ?? null ) ? trim( $raw['video_url'] ) : '';
			$clean[ Meta::MEDIA_TYPE ] = Meta::sanitize_media_type( $raw['media_type'] ?? 'image' );
			$clean[ Meta::VIDEO_URL ]  = VideoUrl::normalise( $video_raw );
			$this->write( $post_id, Meta::MEDIA_TYPE, $clean[ Meta::MEDIA_TYPE ] );
			$this->write( $post_id, Meta::VIDEO_URL, $clean[ Meta::VIDEO_URL ] );
		}
		if ( $has['ingredients'] ) {
			$clean[ Meta::INGREDIENTS ] = Meta::sanitize_ingredients( $raw['ingredients'] ?? [] );
			$this->write( $post_id, Meta::INGREDIENTS, $clean[ Meta::INGREDIENTS ] );
		}
		if ( $has['steps'] ) {
			$clean[ Meta::STEPS ] = Meta::sanitize_steps( $raw['steps'] ?? [] );
			$this->write( $post_id, Meta::STEPS, $clean[ Meta::STEPS ] );
		}
		if ( $has['nutrition'] ) {
			$clean[ Meta::NUTRITION ] = Meta::sanitize_nutrition( $raw['nutrition'] ?? [] );
			$this->write( $post_id, Meta::NUTRITION, $clean[ Meta::NUTRITION ] );
		}
		// A background meta-box save (block editor, `meta-box-loader`) never reloads the page:
		// the editor script reports the refusal there, so no transient is left behind.
		if ( '' !== $video_raw && '' === $clean[ Meta::VIDEO_URL ] && ! isset( $_REQUEST['meta-box-loader'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce was verified above; this only picks the channel for the notice.
			set_transient( self::notice_key(), [ 'video_url' => mb_substr( $video_raw, 0, self::NOTICE_MAX ) ], self::NOTICE_TTL );
		}
		/**
		 * The recipe edit form was saved; `$clean` holds every value written,
		 * keyed by meta key, after sanitising. Capability and nonce have been
		 * checked.
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $post_id The recipe.
		 * @param array<string, mixed> $clean   Meta key => value written.
		 * @param array<string, mixed> $raw     The unslashed form fields, unsanitised.
		 */
		do_action( 'bfrc_recipe_saved', $post_id, $clean, $raw );
	}

	/**
	 * Tell the writer what was not stored, once, on the reloaded screen.
	 */
	public function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base || PostType::NAME !== $screen->post_type ) {
			return; // Only on the recipe edit screen the writer was sent back to.
		}
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::notice_key() );
		if ( isset( $notice['video_url'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html( sprintf( self::video_rejected_message(), (string) $notice['video_url'] ) );
			echo '</p></div>';
		}
	}

	/**
	 * The warning for a refused video URL; %s is the URL that was typed.
	 */
	public static function video_rejected_message(): string {
		/* translators: %s: the URL that was typed */
		return __( 'The video URL "%s" is not a YouTube or Vimeo video, so it was not saved. Paste the video’s own page URL.', 'bluefairy-recipes' );
	}

	/**
	 * The per-user transient that carries a notice across the redirect.
	 */
	private static function notice_key(): string {
		return 'bfrc_editor_notice_' . get_current_user_id();
	}

	/**
	 * Store one value. Core's `update_metadata()` unslashes what it is given
	 * (its callers pass slashed request data), so a clean value is slashed
	 * first or every backslash the writer typed would be lost.
	 *
	 * @param int    $post_id The recipe.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Clean value.
	 */
	private function write( int $post_id, string $key, mixed $value ): void {
		update_post_meta( $post_id, $key, is_string( $value ) || is_array( $value ) ? wp_slash( $value ) : $value );
	}

	/**
	 * Servings: the form is judged as a whole. The key the writer changed is
	 * written last so it wins the reconciliation in Content\Meta; when both
	 * changed and min exceeds max, max is raised to min.
	 *
	 * @param int                  $post_id The recipe.
	 * @param int                  $min     Posted min.
	 * @param int                  $max     Posted max.
	 * @param array<string, mixed> $clean   Receives the values written.
	 */
	private function write_servings( int $post_id, int $min, int $max, array &$clean ): void {
		$stored_min  = Meta::sanitize_servings( get_post_meta( $post_id, Meta::SERVINGS_MIN, true ) );
		$stored_max  = Meta::sanitize_servings( get_post_meta( $post_id, Meta::SERVINGS_MAX, true ) );
		$min_changed = $min !== $stored_min;
		$max_changed = $max !== $stored_max;
		if ( $min_changed && $max_changed && $max > 0 && $min > $max ) {
			$max = $min;
		}
		$order = [
			Meta::SERVINGS_MIN => $min,
			Meta::SERVINGS_MAX => $max,
		];
		if ( $min_changed && ! $max_changed ) {
			$order = array_reverse( $order, true ); // Write max first so the changed min is written last and wins.
		}
		foreach ( $order as $key => $value ) {
			$this->write( $post_id, $key, $value );
		}
		$clean[ Meta::SERVINGS_MIN ] = Meta::sanitize_servings( get_post_meta( $post_id, Meta::SERVINGS_MIN, true ) );
		$clean[ Meta::SERVINGS_MAX ] = Meta::sanitize_servings( get_post_meta( $post_id, Meta::SERVINGS_MAX, true ) );
	}

	/**
	 * The hidden field that proves a box's fields arrived in full.
	 *
	 * @param string $section One of SECTIONS.
	 */
	private function sentinel( string $section ): void {
		echo '<input type="hidden" name="' . esc_attr( self::FIELD . '[_has][' . $section . ']' ) . '" value="1">';
	}

	/**
	 * One labelled number input. No min/max: the sanitiser clamps, and an
	 * HTML constraint inside a collapsed classic-editor box would block
	 * Publish without any message.
	 *
	 * @param string $name  Field name under FIELD.
	 * @param string $label Label text.
	 * @param int    $value Current value.
	 */
	private function number_field( string $name, string $label, int $value ): void {
		echo '<p><label for="bfrc-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><br>';
		echo '<input type="number" step="1" inputmode="numeric" id="bfrc-' . esc_attr( $name ) . '" name="' . esc_attr( self::FIELD . '[' . $name . ']' ) . '" value="' . esc_attr( (string) $value ) . '"></p>';
	}

	/**
	 * One ingredient row. Extra fields of the other types are rendered
	 * hidden AND disabled, so only the chosen type's fields are posted.
	 *
	 * @param int|string                           $index  Row index, or the template placeholder.
	 * @param array<string, mixed>                 $row    Row values.
	 * @param array<int, string>                   $types  Ingredient types in force.
	 * @param array<string, string>                $labels Type labels.
	 * @param array<string, array<string, string>> $extras Field labels per type.
	 */
	private function ingredient_row( int|string $index, array $row, array $types, array $labels, array $extras ): void {
		$base     = self::FIELD . '[ingredients][' . $index . ']';
		$n        = is_int( $index ) ? $index + 1 : 0;
		$row_type = (string) ( $row['type'] ?? 'plain' );
		echo '<tr class="bfrc-rows__row">';
		echo '<td class="bfrc-rows__handle" draggable="true" title="' . esc_attr__( 'Drag to reorder', 'bluefairy-recipes' ) . '"><span aria-hidden="true">⋮⋮</span></td>';
		/* translators: %d: row number */
		echo '<td><input type="text" class="widefat" name="' . esc_attr( $base . '[measure]' ) . '" value="' . esc_attr( (string) ( $row['measure'] ?? '' ) ) . '" maxlength="' . esc_attr( (string) Meta::MEASURE_MAX ) . '" placeholder="' . esc_attr__( '2 cups', 'bluefairy-recipes' ) . '" aria-label="' . esc_attr( sprintf( __( 'Ingredient %d measure', 'bluefairy-recipes' ), $n ) ) . '"></td>';
		/* translators: %d: row number */
		echo '<td><input type="text" class="widefat" name="' . esc_attr( $base . '[label]' ) . '" value="' . esc_attr( (string) ( $row['label'] ?? '' ) ) . '" maxlength="' . esc_attr( (string) Meta::LABEL_MAX ) . '" placeholder="' . esc_attr__( 'flour', 'bluefairy-recipes' ) . '" aria-label="' . esc_attr( sprintf( __( 'Ingredient %d name', 'bluefairy-recipes' ), $n ) ) . '"></td>';
		if ( count( $types ) > 1 ) {
			/* translators: %d: row number */
			echo '<td><select name="' . esc_attr( $base . '[type]' ) . '" class="bfrc-rows__type" aria-label="' . esc_attr( sprintf( __( 'Ingredient %d type', 'bluefairy-recipes' ), $n ) ) . '">';
			foreach ( $types as $type ) {
				echo '<option value="' . esc_attr( $type ) . '"' . selected( $row_type, $type, false ) . '>' . esc_html( $labels[ $type ] ) . '</option>';
			}
			echo '</select>';
			foreach ( $extras as $type => $fields ) {
				$active = $type === $row_type;
				foreach ( $fields as $field => $label ) {
					/* translators: 1: row number, 2: field label */
					echo '<input type="text" class="widefat bfrc-rows__extra" data-bfrc-type="' . esc_attr( $type ) . '" data-bfrc-field="' . esc_attr( $field ) . '"' . ( $active ? '' : ' hidden disabled' ) . ' name="' . esc_attr( $base . '[' . $field . ']' ) . '" value="' . esc_attr( $active && is_scalar( $row[ $field ] ?? null ) ? (string) $row[ $field ] : '' ) . '" placeholder="' . esc_attr( $label ) . '" aria-label="' . esc_attr( sprintf( __( 'Ingredient %1$d %2$s', 'bluefairy-recipes' ), $n, $label ) ) . '">';
				}
			}
			echo '</td>';
		} else {
			echo '<input type="hidden" name="' . esc_attr( $base . '[type]' ) . '" value="plain">';
		}
		echo '<td class="bfrc-rows__actions">' . $this->row_actions() . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped literals in row_actions().
		echo '</tr>';
	}

	/**
	 * One step row. The `<li>` stays a list item (so it is numbered); the
	 * grid is on the inner wrapper.
	 *
	 * @param int|string $index Row index, or the template placeholder.
	 * @param string     $text  Step text.
	 */
	private function step_row( int|string $index, string $text ): void {
		$n = is_int( $index ) ? $index + 1 : 0;
		echo '<li class="bfrc-rows__row"><div class="bfrc-rows__grid">';
		echo '<span class="bfrc-rows__handle" draggable="true" title="' . esc_attr__( 'Drag to reorder', 'bluefairy-recipes' ) . '"><span aria-hidden="true">⋮⋮</span></span>';
		/* translators: %d: step number */
		echo '<textarea class="widefat" rows="3" name="' . esc_attr( self::FIELD . '[steps][' . $index . '][text]' ) . '" aria-label="' . esc_attr( sprintf( __( 'Step %d', 'bluefairy-recipes' ), $n ) ) . '">' . esc_textarea( $text ) . '</textarea>';
		echo '<span class="bfrc-rows__actions">' . $this->row_actions() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped literals in row_actions().
		echo '</div></li>';
	}

	/**
	 * The up / down / remove buttons of a row.
	 */
	private function row_actions(): string {
		return '<button type="button" class="button-link bfrc-rows__up" aria-label="' . esc_attr__( 'Move up', 'bluefairy-recipes' ) . '">▲</button> <button type="button" class="button-link bfrc-rows__down" aria-label="' . esc_attr__( 'Move down', 'bluefairy-recipes' ) . '">▼</button> <button type="button" class="button-link bfrc-rows__remove" aria-label="' . esc_attr__( 'Remove', 'bluefairy-recipes' ) . '">✕</button>';
	}
}
