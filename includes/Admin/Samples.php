<?php
/**
 * The opt-in sample recipes.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Admin;

use BlueFairy\Recipes\Admin\WriterProfile;
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
 * placeholder images (`assets/samples/`), installed from Recipes → Settings → Data on
 * request and never on activation. Created as drafts under a Recipe Writer
 * account `bfrc_sample_writer` (random password, no e-mail delivery), with
 * terms from the starter sets where they exist. What was created is
 * recorded in the option `bfrc_samples` so Remove deletes exactly that,
 * recipes, images and the writer (the writer only while the account is
 * still the sample writer: the login and exactly the Writer role; posts
 * of theirs outside the record are reassigned to the remover, never
 * deleted). A second Install is refused while the record exists; a
 * leftover `bfrc_sample_writer` account from an uninstall that kept
 * content is adopted, not a dead end. Nothing here is a port of the
 * Magento module's sample content.
 */
final class Samples {

	use WritesOptions;

	public const OPTION         = 'bfrc_samples';
	public const ACTION_INSTALL = 'bfrc_install_samples';
	public const ACTION_REMOVE  = 'bfrc_remove_samples';
	public const WRITER_LOGIN   = 'bfrc_sample_writer';

	/**
	 * The sample set. Terms are the starter terms' names, translated as the
	 * starter terms are seeded (skipped when a site renamed or removed
	 * them); measures with a unit are translatable too; the images live in
	 * `assets/samples/`.
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
				'ingredients' => [ [ '1', __( 'whole chicken, about 1.8 kg', 'bluefairy-recipes' ) ], [ '2', __( 'lemons, halved', 'bluefairy-recipes' ) ], [ __( '1 small bunch', 'bluefairy-recipes' ), __( 'thyme and rosemary', 'bluefairy-recipes' ) ], [ __( '4 cloves', 'bluefairy-recipes' ), __( 'garlic, crushed', 'bluefairy-recipes' ) ], [ __( '2 tbsp', 'bluefairy-recipes' ), __( 'olive oil', 'bluefairy-recipes' ) ], [ __( '1 tsp', 'bluefairy-recipes' ), __( 'flaky salt', 'bluefairy-recipes' ) ], [ '', __( 'black pepper', 'bluefairy-recipes' ) ] ],
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
					'meal_type' => __( 'Dinner', 'bluefairy-recipes' ),
					'diet'      => __( 'Gluten-Free', 'bluefairy-recipes' ),
					'season'    => __( 'Autumn', 'bluefairy-recipes' ),
					'cuisine'   => __( 'American', 'bluefairy-recipes' ),
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
				'ingredients' => [ [ __( '2 tbsp', 'bluefairy-recipes' ), __( 'olive oil', 'bluefairy-recipes' ) ], [ '2', __( 'leeks, sliced', 'bluefairy-recipes' ) ], [ '3', __( 'carrots, diced', 'bluefairy-recipes' ) ], [ '2', __( 'courgettes, diced', 'bluefairy-recipes' ) ], [ __( '1 can', 'bluefairy-recipes' ), __( 'cannellini beans, drained', 'bluefairy-recipes' ) ], [ __( '1 can', 'bluefairy-recipes' ), __( 'chopped tomatoes', 'bluefairy-recipes' ) ], [ __( '1.2 l', 'bluefairy-recipes' ), __( 'vegetable stock', 'bluefairy-recipes' ) ], [ __( '2 handfuls', 'bluefairy-recipes' ), __( 'spinach or chard', 'bluefairy-recipes' ) ] ],
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
					'meal_type' => __( 'Lunch', 'bluefairy-recipes' ),
					'diet'      => __( 'Vegan', 'bluefairy-recipes' ),
					'season'    => __( 'Summer', 'bluefairy-recipes' ),
					'cuisine'   => __( 'Mediterranean', 'bluefairy-recipes' ),
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
				'ingredients' => [ [ __( '150 g', 'bluefairy-recipes' ), __( 'plain flour', 'bluefairy-recipes' ) ], [ __( '50 g', 'bluefairy-recipes' ), __( 'rolled oats', 'bluefairy-recipes' ) ], [ __( '2 tsp', 'bluefairy-recipes' ), __( 'baking powder', 'bluefairy-recipes' ) ], [ __( '1 tbsp', 'bluefairy-recipes' ), __( 'sugar', 'bluefairy-recipes' ) ], [ '1', __( 'egg', 'bluefairy-recipes' ) ], [ __( '250 ml', 'bluefairy-recipes' ), __( 'milk', 'bluefairy-recipes' ) ], [ __( '150 g', 'bluefairy-recipes' ), __( 'blueberries', 'bluefairy-recipes' ) ], [ '', __( 'butter, for the pan', 'bluefairy-recipes' ) ] ],
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
					'meal_type' => __( 'Breakfast', 'bluefairy-recipes' ),
					'diet'      => __( 'Vegetarian', 'bluefairy-recipes' ),
					'season'    => __( 'Spring', 'bluefairy-recipes' ),
					'cuisine'   => __( 'American', 'bluefairy-recipes' ),
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
	 * Install the set. Refused (`exists`) while the record exists and
	 * (`writer_taken`) while an account with the writer's login exists that
	 * is not the plugin's own leftover (see is_adoptable()); a leftover is
	 * adopted with a new password, its sessions and application passwords
	 * ended and its profile reset. On any failure everything created so
	 * far is removed (`failed`), the adopted account included.
	 *
	 * @return array<string, mixed>|\WP_Error The record, or why not.
	 */
	public function install(): array|\WP_Error {
		if ( [] !== $this->installed() ) {
			return new \WP_Error( 'exists', __( 'The sample recipes are already installed.', 'bluefairy-recipes' ) );
		}
		$record   = [
			'writer'       => 0,
			'writer_login' => self::WRITER_LOGIN,
			'recipes'      => [],
			'attachments'  => [],
			'installed_at' => (string) current_time( 'mysql', true ),
		];
		$adopted  = false;
		$existing = username_exists( self::WRITER_LOGIN );
		if ( false !== $existing ) {
			// An uninstall that kept content removed the record and the role but not the account: adopt it,
			// but only an account that is demonstrably the plugin's own (its address, no role beyond Writer or
			// the role-less leftover). Anything else is someone's account: never demoted, never handed the samples.
			$user = get_userdata( (int) $existing );
			if ( ! $user instanceof \WP_User || ! self::is_adoptable( $user ) ) {
				/* translators: %s: the sample writer's login */
				return new \WP_Error( 'writer_taken', sprintf( __( 'An account named %s exists and is not the sample writer the plugin created. Rename or delete it, then install the samples.', 'bluefairy-recipes' ), self::WRITER_LOGIN ) );
			}
			// Every way into the account is closed and its profile is ours again: whoever held it before
			// (an uninstall's leftover, or someone who registered the login and set our address) keeps nothing.
			wp_set_password( wp_generate_password( 32, true, true ), (int) $existing );
			\WP_Session_Tokens::get_instance( (int) $existing )->destroy_all();
			\WP_Application_Passwords::delete_all_application_passwords( (int) $existing );
			wp_update_user(
				[
					'ID'           => (int) $existing,
					'display_name' => __( 'Sam Sample', 'bluefairy-recipes' ),
					'user_url'     => '',
					'description'  => '',
				]
			);
			foreach ( array_keys( WriterProfile::FIELDS ) as $meta_key ) {
				delete_user_meta( (int) $existing, $meta_key );
			}
			if ( [ Capabilities::ROLE_WRITER ] !== array_values( (array) $user->roles ) ) {
				$user->set_role( Capabilities::ROLE_WRITER );
			}
			$writer  = (int) $existing;
			$adopted = true;
		} else {
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
		}
		$record['writer'] = (int) $writer;
		update_user_meta( (int) $writer, 'bfrc_about', __( 'Sam is the sample writer who comes with BlueFairy Recipes: three recipes that show what a recipe page, the archive and a writer page look like.', 'bluefairy-recipes' ) );
		foreach ( self::recipes() as $recipe ) {
			$post_id = wp_insert_post(
				[
					'post_type'    => PostType::NAME,
					'post_status'  => 'draft',
					'post_author'  => (int) $writer,
					'post_title'   => sanitize_text_field( (string) $recipe['title'] ),
					'post_name'    => $recipe['slug'],
					'post_excerpt' => sanitize_text_field( (string) $recipe['excerpt'] ),
					'post_content' => '<!-- wp:paragraph --><p>' . wp_kses_post( (string) $recipe['content'] ) . '</p><!-- /wp:paragraph -->', // The strings are the plugin's own, but a translation catalogue is input too.
				],
				true
			);
			if ( is_wp_error( $post_id ) ) {
				$this->delete_record( $record, 0, ! $adopted );
				return new \WP_Error( 'failed', $post_id->get_error_message() );
			}
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				$this->delete_record( $record, 0, ! $adopted );
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
			$this->delete_record( $record, 0, ! $adopted );
			return new \WP_Error( 'failed', __( 'The record of the sample recipes could not be saved.', 'bluefairy-recipes' ) );
		}
		return $record;
	}

	/**
	 * Remove what install() recorded (recipes, images, the writer) and the
	 * record. Safe to call when nothing is installed.
	 *
	 * @param int $reassign_to User id that inherits any other post of the writer's (0: the current user).
	 */
	public function remove( int $reassign_to = 0 ): void {
		$record = $this->installed();
		if ( [] === $record ) {
			return;
		}
		$this->delete_record( $record, $reassign_to );
	}

	/**
	 * May install() adopt this leftover `bfrc_sample_writer` account? Only
	 * the plugin's own: the `@example.invalid` address it created it with,
	 * and no role beyond Writer (uninstall.php leaves a user whose only role
	 * was Writer as a subscriber; an older cleanup may leave no role). What
	 * adoption then does to the account, see install().
	 *
	 * @param \WP_User $user The account with the sample writer's login.
	 */
	public static function is_adoptable( \WP_User $user ): bool {
		$roles = array_values( (array) $user->roles );
		return self::WRITER_LOGIN . '@example.invalid' === strtolower( (string) $user->user_email )
			&& [] === array_diff( $roles, [ Capabilities::ROLE_WRITER, 'subscriber' ] );
	}

	/**
	 * Is the account still the sample writer: the login and exactly the
	 * Writer role? An account the owner renamed or promoted is theirs.
	 *
	 * @param int $user_id The recorded writer id.
	 */
	public static function is_sample_writer( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user instanceof \WP_User && self::WRITER_LOGIN === (string) $user->user_login && [ Capabilities::ROLE_WRITER ] === array_values( (array) $user->roles );
	}

	/**
	 * Delete everything a record names, then the record. The writer goes
	 * only while it is still the sample writer AND there is someone to
	 * inherit any other post of theirs (no current user, as from a script:
	 * the account stays rather than core deleting those posts with it).
	 * On multisite the account leaves this site; the network row goes too
	 * when it belongs to no other site and the remover may delete users
	 * (a site administrator may only remove), so a later Install adopts the
	 * leftover instead of being refused.
	 *
	 * @param array<string, mixed> $record      The record (complete or partial).
	 * @param int                  $reassign_to User id that inherits any other post of the writer's (0: the current user).
	 * @param bool                 $created_now Rolling back an install() that just created the account: it has no other posts, so no inheritor is needed.
	 */
	private function delete_record( array $record, int $reassign_to = 0, bool $created_now = false ): void {
		foreach ( (array) ( $record['recipes'] ?? [] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( (array) ( $record['attachments'] ?? [] ) as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		$writer   = (int) ( $record['writer'] ?? 0 );
		$reassign = $reassign_to > 0 ? $reassign_to : (int) get_current_user_id();
		$reassign = $reassign > 0 && $reassign !== $writer ? $reassign : null;
		if ( $writer > 0 && ( null !== $reassign || $created_now ) && self::is_sample_writer( $writer ) ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			if ( is_multisite() ) {
				wp_delete_user( $writer, $reassign ); // This site's posts reassigned, the account removed from the site.
				if ( [] === get_blogs_of_user( $writer ) && current_user_can( 'delete_users' ) ) {
					if ( ! function_exists( 'wpmu_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/ms.php';
					}
					wpmu_delete_user( $writer ); // Nowhere else and allowed: the network row goes too.
				}
			} else {
				wp_delete_user( $writer, $reassign );
			}
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
