<?php
/**
 * The opt-in sample recipes.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Admin;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Support\WritesOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three synthetic recipes by one sample writer, with the plugin's own
 * photos (`assets/samples/`), installed from Recipes → Settings → Data on
 * request and never on activation. Created as drafts under a Recipe Writer
 * account `bfrc_sample_writer` (random password, no e-mail delivery), with
 * terms from the starter sets where they exist. What was created is
 * recorded in the option `bfrc_samples` so Remove deletes exactly that,
 * recipes, photos and the writer; a second Install is refused while the
 * record (or the writer login) exists. Nothing here is a port of the
 * Magento module's sample content.
 */
final class Samples {

	use WritesOptions;

	public const OPTION         = 'bfrc_samples';
	public const ACTION_INSTALL = 'bfrc_install_samples';
	public const ACTION_REMOVE  = 'bfrc_remove_samples';
	public const WRITER_LOGIN   = 'bfrc_sample_writer';

	/**
	 * The sample set. Terms are the starter terms' names (skipped when a
	 * site renamed or removed them); photos live in `assets/samples/`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function recipes(): array {
		return [
			[
				'slug'        => 'lemon-herb-roast-chicken',
				'title'       => __( 'Lemon Herb Roast Chicken', 'bluefairy-recipes' ),
				'excerpt'     => __( 'A whole chicken roasted over lemon halves and a handful of garden herbs, with crisp skin and a pan sauce.', 'bluefairy-recipes' ),
				'content'     => __( 'Sunday lunch for four, with enough left for sandwiches. Bring the chicken to room temperature before it goes in: a cold bird steams before it roasts.', 'bluefairy-recipes' ),
				'prep'        => 20,
				'cook'        => 85,
				'servings'    => [ 4, 6 ],
				'ingredients' => [ [ '1', __( 'whole chicken, about 1.8 kg', 'bluefairy-recipes' ) ], [ '2', __( 'lemons, halved', 'bluefairy-recipes' ) ], [ '1 small bunch', __( 'thyme and rosemary', 'bluefairy-recipes' ) ], [ '4 cloves', __( 'garlic, crushed', 'bluefairy-recipes' ) ], [ '2 tbsp', __( 'olive oil', 'bluefairy-recipes' ) ], [ '1 tsp', __( 'flaky salt', 'bluefairy-recipes' ) ], [ '', __( 'black pepper', 'bluefairy-recipes' ) ] ],
				'steps'       => [ __( 'Heat the oven to 200 °C (390 °F). Pat the chicken dry and season inside and out.', 'bluefairy-recipes' ), __( 'Put the lemon halves, garlic and half the herbs in the cavity; rub the skin with oil and scatter the rest of the herbs over.', 'bluefairy-recipes' ), __( 'Roast for 75 to 85 minutes, until the juices run clear at the thigh. Rest for 15 minutes before carving.', 'bluefairy-recipes' ), __( 'Skim the pan juices, squeeze in the roasted lemon and spoon over the carved chicken.', 'bluefairy-recipes' ) ],
				'nutrition'   => [
					'calories'      => 520,
					'fat'           => 32,
					'saturated_fat' => 9,
					'carbohydrates' => 4,
					'protein'       => 52,
					'sodium'        => 640,
				],
				'terms'       => [
					'meal_type' => 'Dinner',
					'diet'      => 'Gluten-Free',
					'season'    => 'Autumn',
					'cuisine'   => 'American',
				],
			],
			[
				'slug'        => 'garden-vegetable-soup',
				'title'       => __( 'Garden Vegetable Soup', 'bluefairy-recipes' ),
				'excerpt'     => __( 'A big pot of soup from whatever the garden gives: leeks, carrots, courgettes and beans in a tomato broth.', 'bluefairy-recipes' ),
				'content'     => __( 'It keeps for three days and freezes well. Add the greens at the end so they stay bright.', 'bluefairy-recipes' ),
				'prep'        => 15,
				'cook'        => 35,
				'servings'    => [ 6, 6 ],
				'ingredients' => [ [ '2 tbsp', __( 'olive oil', 'bluefairy-recipes' ) ], [ '2', __( 'leeks, sliced', 'bluefairy-recipes' ) ], [ '3', __( 'carrots, diced', 'bluefairy-recipes' ) ], [ '2', __( 'courgettes, diced', 'bluefairy-recipes' ) ], [ '1 can', __( 'cannellini beans, drained', 'bluefairy-recipes' ) ], [ '1 can', __( 'chopped tomatoes', 'bluefairy-recipes' ) ], [ '1.2 l', __( 'vegetable stock', 'bluefairy-recipes' ) ], [ '2 handfuls', __( 'spinach or chard', 'bluefairy-recipes' ) ] ],
				'steps'       => [ __( 'Soften the leeks and carrots in the oil over a low heat for 10 minutes.', 'bluefairy-recipes' ), __( 'Add the courgettes, beans, tomatoes and stock; simmer for 20 minutes.', 'bluefairy-recipes' ), __( 'Stir in the greens, season, and serve with bread.', 'bluefairy-recipes' ) ],
				'nutrition'   => [
					'calories'      => 210,
					'fat'           => 6,
					'saturated_fat' => 1,
					'carbohydrates' => 30,
					'sugar'         => 9,
					'fiber'         => 8,
					'protein'       => 9,
					'sodium'        => 480,
				],
				'terms'       => [
					'meal_type' => 'Lunch',
					'diet'      => 'Vegan',
					'season'    => 'Summer',
					'cuisine'   => 'Mediterranean',
				],
			],
			[
				'slug'        => 'blueberry-oat-pancakes',
				'title'       => __( 'Blueberry Oat Pancakes', 'bluefairy-recipes' ),
				'excerpt'     => __( 'Thick, fluffy pancakes with oats in the batter and blueberries dropped onto each one as it cooks.', 'bluefairy-recipes' ),
				'content'     => __( 'Let the batter rest for ten minutes so the oats soften. A low heat and a lid make them rise.', 'bluefairy-recipes' ),
				'prep'        => 10,
				'cook'        => 20,
				'servings'    => [ 3, 4 ],
				'ingredients' => [ [ '150 g', __( 'plain flour', 'bluefairy-recipes' ) ], [ '50 g', __( 'rolled oats', 'bluefairy-recipes' ) ], [ '2 tsp', __( 'baking powder', 'bluefairy-recipes' ) ], [ '1 tbsp', __( 'sugar', 'bluefairy-recipes' ) ], [ '1', __( 'egg', 'bluefairy-recipes' ) ], [ '250 ml', __( 'milk', 'bluefairy-recipes' ) ], [ '150 g', __( 'blueberries', 'bluefairy-recipes' ) ], [ '', __( 'butter, for the pan', 'bluefairy-recipes' ) ] ],
				'steps'       => [ __( 'Mix the dry ingredients; whisk the egg into the milk and stir it in until just combined. Rest for 10 minutes.', 'bluefairy-recipes' ), __( 'Heat a knob of butter in a pan over a low heat. Ladle in the batter and drop a few blueberries onto each pancake.', 'bluefairy-recipes' ), __( 'Cook for 2 to 3 minutes a side, until golden. Keep warm while you finish the batch.', 'bluefairy-recipes' ) ],
				'nutrition'   => [
					'calories'      => 340,
					'fat'           => 8,
					'saturated_fat' => 4,
					'carbohydrates' => 56,
					'sugar'         => 12,
					'fiber'         => 4,
					'protein'       => 11,
					'sodium'        => 390,
				],
				'terms'       => [
					'meal_type' => 'Breakfast',
					'diet'      => 'Vegetarian',
					'season'    => 'Spring',
					'cuisine'   => 'American',
				],
			],
		];
	}

	/**
	 * What is installed: `writer` (user id), `writer_login`, `recipes`
	 * (post ids), `attachments` (ids); `[]` when nothing is.
	 *
	 * @return array<string, mixed>
	 */
	public function installed(): array {
		$stored = get_option( self::OPTION, [] );
		return is_array( $stored ) && isset( $stored['recipes'] ) ? $stored : [];
	}

	/**
	 * Install the set. Refused (`exists`) while the record or the writer
	 * login exists; on any failure everything created so far is removed
	 * (`failed`).
	 *
	 * @return array<string, mixed>|\WP_Error The record, or why not.
	 */
	public function install(): array|\WP_Error {
		if ( [] !== $this->installed() || false !== username_exists( self::WRITER_LOGIN ) ) {
			return new \WP_Error( 'exists', __( 'The sample recipes are already installed.', 'bluefairy-recipes' ) );
		}
		$record = [
			'writer'       => 0,
			'writer_login' => self::WRITER_LOGIN,
			'recipes'      => [],
			'attachments'  => [],
			'installed_at' => (string) current_time( 'mysql', true ),
		];
		$writer = wp_insert_user(
			[
				'user_login'   => self::WRITER_LOGIN,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => self::WRITER_LOGIN . '@example.invalid', // RFC 2606: never deliverable.
				'display_name' => __( 'Sam Sample', 'bluefairy-recipes' ),
				'role'         => Capabilities::ROLE_WRITER,
			]
		);
		if ( is_wp_error( $writer ) ) {
			return new \WP_Error( 'failed', $writer->get_error_message() );
		}
		$record['writer'] = (int) $writer;
		update_user_meta( (int) $writer, 'bfrc_about', __( 'The sample writer. Remove the sample recipes from Recipes → Settings → Data when you are done looking.', 'bluefairy-recipes' ) );
		foreach ( self::recipes() as $recipe ) {
			$post_id = wp_insert_post(
				[
					'post_type'    => PostType::NAME,
					'post_status'  => 'draft',
					'post_author'  => (int) $writer,
					'post_title'   => $recipe['title'],
					'post_name'    => $recipe['slug'],
					'post_excerpt' => $recipe['excerpt'],
					'post_content' => '<!-- wp:paragraph --><p>' . $recipe['content'] . '</p><!-- /wp:paragraph -->',
				],
				true
			);
			if ( is_wp_error( $post_id ) ) {
				$this->delete_record( $record );
				return new \WP_Error( 'failed', $post_id->get_error_message() );
			}
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				$this->delete_record( $record );
				return new \WP_Error( 'failed', __( 'The recipe could not be created.', 'bluefairy-recipes' ) );
			}
			$record['recipes'][] = $post_id;
			update_post_meta( $post_id, Meta::PREP_MINUTES, (int) $recipe['prep'] );
			update_post_meta( $post_id, Meta::COOK_MINUTES, (int) $recipe['cook'] );
			update_post_meta( $post_id, Meta::SERVINGS_MIN, (int) $recipe['servings'][0] );
			update_post_meta( $post_id, Meta::SERVINGS_MAX, (int) $recipe['servings'][1] );
			update_post_meta( $post_id, Meta::MEDIA_TYPE, 'image' );
			update_post_meta(
				$post_id,
				Meta::INGREDIENTS,
				Meta::sanitize_ingredients(
					array_map(
						static fn( array $row ): array => [
							'measure' => $row[0],
							'label'   => $row[1],
							'type'    => 'plain',
						],
						$recipe['ingredients']
					)
				)
			);
			update_post_meta( $post_id, Meta::STEPS, Meta::sanitize_steps( array_map( static fn( string $text ): array => [ 'text' => $text ], $recipe['steps'] ) ) );
			update_post_meta( $post_id, Meta::NUTRITION, Meta::sanitize_nutrition( $recipe['nutrition'] ) );
			foreach ( $recipe['terms'] as $code => $name ) {
				$term = get_term_by( 'name', $name, Taxonomies::taxonomy_name( (string) $code ) );
				if ( $term instanceof \WP_Term ) {
					wp_set_object_terms( $post_id, [ (int) $term->term_id ], Taxonomies::taxonomy_name( (string) $code ) );
				}
			}
			$attachment = $this->sideload_photo( (string) $recipe['slug'], $post_id, (string) $recipe['title'] );
			if ( $attachment > 0 ) {
				$record['attachments'][] = $attachment;
				set_post_thumbnail( $post_id, $attachment );
			}
		}
		if ( ! $this->write_option( self::OPTION, $record, false ) ) {
			$this->delete_record( $record );
			return new \WP_Error( 'failed', __( 'The record of the sample recipes could not be saved.', 'bluefairy-recipes' ) );
		}
		return $record;
	}

	/**
	 * Remove what install() recorded (recipes, photos, the writer) and the
	 * record. Safe to call when nothing is installed.
	 */
	public function remove(): void {
		$record = $this->installed();
		if ( [] === $record ) {
			return;
		}
		$this->delete_record( $record );
	}

	/**
	 * Delete everything a record names, then the record.
	 *
	 * @param array<string, mixed> $record The record (complete or partial).
	 */
	private function delete_record( array $record ): void {
		foreach ( (array) ( $record['recipes'] ?? [] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( (array) ( $record['attachments'] ?? [] ) as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		$writer = (int) ( $record['writer'] ?? 0 );
		if ( $writer > 0 ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( $writer );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Copy a shipped photo into the uploads directory as an attachment of
	 * the recipe; 0 when the file is missing or the upload fails (the
	 * recipe is kept without a photo).
	 *
	 * @param string $slug    The recipe slug (= the photo's file name).
	 * @param int    $post_id The recipe.
	 * @param string $title   The recipe title (the attachment's title and alt text).
	 */
	private function sideload_photo( string $slug, int $post_id, string $title ): int {
		$file = BFRC_DIR . 'assets/samples/' . $slug . '.jpg';
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$bits = wp_upload_bits( 'bfrc-sample-' . $slug . '.jpg', null, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own shipped file.
		if ( ! is_array( $bits ) || ! empty( $bits['error'] ) || ! is_string( $bits['file'] ?? null ) ) {
			return 0;
		}
		$attachment = wp_insert_attachment(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $title,
				'post_status'    => 'inherit',
			],
			$bits['file'],
			$post_id
		);
		if ( is_wp_error( $attachment ) || (int) $attachment <= 0 ) {
			return 0;
		}
		$attachment = (int) $attachment;
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $bits['file'] ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', $title );
		return $attachment;
	}
}
