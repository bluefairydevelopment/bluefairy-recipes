<?php
/**
 * Recipe JSON-LD on the recipe page.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Front;

use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One `<script type="application/ld+json">` in `wp_head` on a recipe page
 * (`is_singular( bfrc_recipe )`, the plugin enabled, Rich snippets on, the
 * recipe not password-protected, and with a photo: Google requires `image`
 * for a Recipe rich result, so a recipe without one gets no markup rather
 * than markup that fails validation). The data is built when `wp_head`
 * runs, passed through `bfrc_schema_data` (the Pro add-on adds `aggregateRating`)
 * and encoded with `JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP |
 * JSON_UNESCAPED_UNICODE`, so a `</script>` in a title cannot close the
 * element. A filter returning `[]` prints nothing: the switch for a site
 * whose SEO plugin prints its own Recipe.
 *
 * Properties, after Google's Recipe documentation: required `name` and
 * `image`; recommended `author`, `datePublished`, `description`,
 * `prepTime`/`cookTime`/`totalTime` (ISO 8601 durations),
 * `recipeCategory` (meal types), `recipeCuisine` (cuisines), `keywords`
 * (the other filter types' terms: Google says not to repeat the category
 * and cuisine there), `recipeIngredient`, `recipeInstructions` (`HowToStep`
 * with a url to the step's own anchor on the page), `recipeYield`,
 * `nutrition` (`NutritionInformation`, numbers in machine form whatever
 * the site's locale), `video` (`VideoObject` with `embedUrl`; a watch-page
 * URL is not a media file, so no `contentUrl`) for a video recipe, plus
 * `suitableForDiet` for the diet terms schema.org names. A draft (no
 * publish date) gets no `datePublished`.
 * `tests/fixtures/recipe-jsonld.schema.json` is the contract the unit test
 * and `bin/check-jsonld.php` validate against.
 */
final class Schema {

	/**
	 * Image sizes offered to Google, largest first; duplicates collapse.
	 *
	 * @var list<string>
	 */
	private const IMAGE_SIZES = [ 'full', 'large', 'medium_large', 'medium' ];

	/**
	 * The schema.org RestrictedDiet values by diet term slug.
	 *
	 * @var array<string, string>
	 */
	private const DIETS = [
		'vegan'       => 'https://schema.org/VeganDiet',
		'vegetarian'  => 'https://schema.org/VegetarianDiet',
		'gluten-free' => 'https://schema.org/GlutenFreeDiet',
		'dairy-free'  => 'https://schema.org/LowLactoseDiet',
		'halal'       => 'https://schema.org/HalalDiet',
		'kosher'      => 'https://schema.org/KosherDiet',
		'low-calorie' => 'https://schema.org/LowCalorieDiet',
		'low-fat'     => 'https://schema.org/LowFatDiet',
		'low-salt'    => 'https://schema.org/LowSaltDiet',
		'diabetic'    => 'https://schema.org/DiabeticDiet',
	];

	/**
	 * Nutrition fields to schema.org NutritionInformation properties and units.
	 *
	 * @var array<string, array{string, string}>
	 */
	private const NUTRITION = [
		'calories'      => [ 'calories', 'calories' ],
		'fat'           => [ 'fatContent', 'g' ],
		'saturated_fat' => [ 'saturatedFatContent', 'g' ],
		'carbohydrates' => [ 'carbohydrateContent', 'g' ],
		'sugar'         => [ 'sugarContent', 'g' ],
		'fiber'         => [ 'fiberContent', 'g' ],
		'protein'       => [ 'proteinContent', 'g' ],
		'sodium'        => [ 'sodiumContent', 'mg' ],
	];

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Taxonomies $taxonomies Filter types.
	 * @param Writers    $writers    Writer pages.
	 */
	public function __construct( private Settings $settings, private Taxonomies $taxonomies, private Writers $writers ) {}

	/**
	 * Hook registration.
	 */
	public function register_hooks(): void {
		add_action( 'wp_head', [ $this, 'print_head' ] );
	}

	/**
	 * `wp_head`: the recipe page's markup, or nothing.
	 */
	public function print_head(): void {
		$json = $this->head_markup();
		if ( '' !== $json ) {
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode() with HEX_TAG|HEX_AMP inside a script element built here.
		}
	}

	/**
	 * The `<script>` element for the current request, or ''.
	 */
	public function head_markup(): string {
		if ( ! is_singular( PostType::NAME ) || ! (bool) $this->settings->get( 'enabled' ) || ! (bool) $this->settings->get( 'rich_snippets' ) ) {
			return '';
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type || post_password_required( $post ) ) {
			return '';
		}
		$data = $this->build( $post );
		if ( [] === $data ) {
			return '';
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? '<script type="application/ld+json">' . $json . '</script>' . "\n" : '';
	}

	/**
	 * The Recipe object for a recipe, through `bfrc_schema_data`; `[]` when
	 * the recipe has no photo.
	 *
	 * @param \WP_Post $post The recipe.
	 * @return array<string, mixed>
	 */
	public function build( \WP_Post $post ): array {
		$images = $this->images( $post );
		if ( [] === $images ) {
			return [];
		}
		$data      = RecipeData::for_post( $post );
		$terms     = $this->terms( $post );
		$out       = [
			'@context' => 'https://schema.org',
			'@type'    => 'Recipe',
			'name'     => self::text( get_the_title( $post ) ),
			'url'      => (string) get_permalink( $post ),
			'image'    => $images,
		];
		$published = get_post_time( 'c', true, $post );
		$modified  = get_post_modified_time( 'c', true, $post );
		if ( is_string( $published ) && '' !== $published ) {
			$out['datePublished'] = $published; // A draft has no date: the key is left out rather than empty.
		}
		if ( is_string( $modified ) && '' !== $modified ) {
			$out['dateModified'] = $modified;
		}
		$description = has_excerpt( $post ) ? self::text( get_the_excerpt( $post ) ) : '';
		if ( '' !== $description ) {
			$out['description'] = $description;
		}
		$author = $this->author( $post );
		if ( [] !== $author ) {
			$out['author'] = $author;
		}
		foreach ( [
			'prepTime'  => $data->prep_minutes,
			'cookTime'  => $data->cook_minutes,
			'totalTime' => $data->total_minutes(),
		] as $key => $minutes ) {
			if ( $minutes > 0 ) {
				$out[ $key ] = self::duration( $minutes );
			}
		}
		$yield = $data->servings_text();
		if ( '' !== $yield ) {
			$out['recipeYield'] = $yield;
		}
		if ( [] !== ( $terms['meal_type'] ?? [] ) ) {
			$out['recipeCategory'] = array_values( $terms['meal_type'] );
		}
		if ( [] !== ( $terms['cuisine'] ?? [] ) ) {
			$out['recipeCuisine'] = array_values( $terms['cuisine'] );
		}
		$others   = array_diff_key( $terms, array_flip( self::NOT_KEYWORDS ) ); // Google: not the category and cuisine again.
		$keywords = array_merge( ...array_values( array_map( 'array_values', $others ) ) );
		if ( [] !== $keywords ) {
			$out['keywords'] = implode( ', ', array_unique( $keywords ) );
		}
		$diets = [];
		foreach ( array_keys( $terms['diet'] ?? [] ) as $slug ) {
			if ( isset( self::DIETS[ $slug ] ) ) {
				$diets[] = self::DIETS[ $slug ];
			}
		}
		if ( [] !== $diets ) {
			$out['suitableForDiet'] = $diets;
		}
		$ingredients = [];
		foreach ( $data->ingredients() as $row ) {
			$line = trim( self::text( (string) ( $row['measure'] ?? '' ) ) . ' ' . self::text( (string) ( $row['label'] ?? '' ) ) );
			if ( '' !== $line ) {
				$ingredients[] = $line;
			}
		}
		if ( [] !== $ingredients ) {
			$out['recipeIngredient'] = $ingredients;
		}
		$steps = [];
		foreach ( array_values( $data->steps() ) as $i => $step ) {
			$text = self::text( (string) ( $step['text'] ?? '' ) );
			if ( '' !== $text ) {
				$n       = $i + 1; // The row's number: the one Renderer::steps() gives the same row's `id`, even when a row before it has markup but no text and is skipped here.
				$steps[] = [
					'@type'    => 'HowToStep',
					'position' => $n,
					'text'     => $text,
					'url'      => (string) get_permalink( $post ) . '#' . Renderer::step_anchor( $n ),
				];
			}
		}
		if ( [] !== $steps ) {
			$out['recipeInstructions'] = $steps;
		}
		$nutrition = [];
		foreach ( $data->nutrition_present() as $field => $value ) {
			if ( isset( self::NUTRITION[ $field ] ) ) {
				[ $property, $unit ]    = self::NUTRITION[ $field ];
				$nutrition[ $property ] = self::amount( $value ) . ' ' . $unit; // Machine form: a German site must not print "12,5 g".
			}
		}
		if ( [] !== $nutrition ) {
			$out['nutrition'] = [ '@type' => 'NutritionInformation' ] + $nutrition;
		}
		if ( $data->has_video() ) {
			$out['video'] = [
				'@type'        => 'VideoObject',
				'name'         => $out['name'],
				'description'  => '' !== $description ? $description : $out['name'],
				'thumbnailUrl' => $images,
				'uploadDate'   => $out['datePublished'] ?? $out['dateModified'] ?? '', // The recipe's date stands in for the video's.
				'embedUrl'     => $data->embed_url(),
			];
		}
		/**
		 * The Recipe JSON-LD data before it is encoded. The Pro add-on adds
		 * `aggregateRating` when a recipe has approved reviews.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $out  The schema.org Recipe object.
		 * @param \WP_Post             $post The recipe.
		 */
		$filtered = apply_filters( 'bfrc_schema_data', $out, $post );
		return is_array( $filtered ) ? $filtered : $out;
	}

	/**
	 * The featured image in the sizes Google asks for (16:9, 4:3 and 1:1
	 * are its wish; the registered sizes are what the site has), largest
	 * first, duplicates removed; `[]` without a photo.
	 *
	 * @param \WP_Post $post The recipe.
	 * @return list<string>
	 */
	public function images( \WP_Post $post ): array {
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb <= 0 ) {
			return [];
		}
		$urls = [];
		foreach ( self::IMAGE_SIZES as $size ) {
			$src = wp_get_attachment_image_src( $thumb, $size );
			if ( is_array( $src ) && is_string( $src[0] ?? null ) && '' !== $src[0] ) {
				$urls[ $src[0] ] = true;
			}
		}
		return array_keys( $urls );
	}

	/**
	 * The writer as a Person, linked to their page when they have one.
	 *
	 * @param \WP_Post $post The recipe.
	 * @return array<string, string>
	 */
	public function author( \WP_Post $post ): array {
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user instanceof \WP_User || '' === (string) $user->display_name ) {
			return [];
		}
		$out = [
			'@type' => 'Person',
			'name'  => self::text( (string) $user->display_name ),
		];
		if ( (bool) $this->settings->get( 'enabled' ) && $this->writers->is_public( (int) $user->ID ) ) {
			$out['url'] = $this->writers->url( $user );
		}
		return $out;
	}

	/**
	 * Term names of every enabled filter type: code => slug => name.
	 *
	 * @param \WP_Post $post The recipe.
	 * @return array<string, array<string, string>>
	 */
	public function terms( \WP_Post $post ): array {
		$out = [];
		foreach ( array_keys( $this->taxonomies->enabled() ) as $code ) {
			$terms = get_the_terms( $post, Taxonomies::taxonomy_name( $code ) );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$out[ $code ][ $term->slug ] = self::text( $term->name );
				}
			}
		}
		return $out;
	}

	/** Filter types already carried by `recipeCategory` and `recipeCuisine`, so not repeated in `keywords`. */
	public const NOT_KEYWORDS = [ 'meal_type', 'cuisine' ];

	/**
	 * A nutrition amount for machines: a dot, no thousands separator, up to
	 * two decimals with trailing zeros dropped.
	 *
	 * @param float $value The amount.
	 */
	public static function amount( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Minutes as an ISO 8601 duration (`PT1H15M`).
	 *
	 * @param int $minutes Whole minutes, > 0.
	 */
	public static function duration( int $minutes ): string {
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		return 'PT' . ( $hours > 0 ? $hours . 'H' : '' ) . ( $rest > 0 || 0 === $hours ? $rest . 'M' : '' );
	}

	/**
	 * Plain text: tags gone, entities decoded, whitespace collapsed.
	 *
	 * @param string $value Stored or rendered text.
	 */
	public static function text( string $value ): string {
		$plain = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $plain ) );
	}
}
