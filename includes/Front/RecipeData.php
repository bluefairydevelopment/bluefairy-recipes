<?php
/**
 * A recipe's fields, read once from post meta.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Front;

use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Support\VideoUrl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the templates, the JSON-LD and the REST item need from a
 * recipe's meta, read through the same sanitisers REST uses, so a value
 * written by any path renders the same way. One `get_post_meta()` per
 * recipe: the list query primes the meta cache for every card.
 */
final class RecipeData {

	/**
	 * Ingredient rows.
	 *
	 * @var array<int, array{measure: string, label: string, type: string}>
	 */
	public array $ingredients = [];
	/**
	 * Steps.
	 *
	 * @var array<int, array{text: string}>
	 */
	public array $steps = [];
	/**
	 * Nutrition, null = not given.
	 *
	 * @var array<string, float|null>
	 */
	public array $nutrition = [];

	/**
	 * Built by for_post().
	 *
	 * @param int    $id           The recipe.
	 * @param string $media_type   image|video.
	 * @param string $video_url    Normalised video URL or ''.
	 * @param int    $prep_minutes Prep minutes.
	 * @param int    $cook_minutes Cook minutes.
	 * @param int    $servings_min Servings min.
	 * @param int    $servings_max Servings max.
	 * @param bool   $featured     Featured.
	 */
	private function __construct(
		public readonly int $id,
		public readonly string $media_type,
		public readonly string $video_url,
		public readonly int $prep_minutes,
		public readonly int $cook_minutes,
		public readonly int $servings_min,
		public readonly int $servings_max,
		public readonly bool $featured,
	) {}

	/**
	 * Read a recipe.
	 *
	 * @param \WP_Post|int $post The recipe.
	 */
	public static function for_post( \WP_Post|int $post ): self {
		$id                = $post instanceof \WP_Post ? $post->ID : $post;
		$meta              = get_post_meta( $id );
		$meta              = is_array( $meta ) ? $meta : [];
		$one               = static fn( string $key ): mixed => isset( $meta[ $key ][0] ) ? maybe_unserialize( $meta[ $key ][0] ) : null;
		$data              = new self(
			$id,
			Meta::sanitize_media_type( $one( Meta::MEDIA_TYPE ) ?? 'image' ),
			VideoUrl::normalise( $one( Meta::VIDEO_URL ) ),
			Meta::sanitize_minutes( $one( Meta::PREP_MINUTES ) ),
			Meta::sanitize_minutes( $one( Meta::COOK_MINUTES ) ),
			Meta::sanitize_servings( $one( Meta::SERVINGS_MIN ) ),
			Meta::sanitize_servings( $one( Meta::SERVINGS_MAX ) ),
			Meta::sanitize_bool( $one( Meta::FEATURED ) ),
		);
		$data->ingredients = Meta::sanitize_ingredients( $one( Meta::INGREDIENTS ) );
		$data->steps       = Meta::sanitize_steps( $one( Meta::STEPS ) );
		$data->nutrition   = Meta::sanitize_nutrition( $one( Meta::NUTRITION ) );
		return $data;
	}

	/**
	 * Prep plus cook.
	 */
	public function total_minutes(): int {
		return $this->prep_minutes + $this->cook_minutes;
	}

	/**
	 * "45 min", "1 hr", "1 hr 15 min"; '' for 0.
	 *
	 * @param int $minutes Whole minutes.
	 */
	public static function format_minutes( int $minutes ): string {
		if ( $minutes <= 0 ) {
			return '';
		}
		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;
		if ( 0 === $hours ) {
			/* translators: %s: number of minutes */
			return sprintf( _n( '%s min', '%s min', $rest, 'bluefairy-recipes' ), number_format_i18n( $rest ) );
		}
		/* translators: %s: number of hours */
		$text = sprintf( _n( '%s hr', '%s hr', $hours, 'bluefairy-recipes' ), number_format_i18n( $hours ) );
		if ( $rest > 0 ) {
			/* translators: 1: hours text, 2: number of minutes */
			$text = sprintf( __( '%1$s %2$s min', 'bluefairy-recipes' ), $text, number_format_i18n( $rest ) );
		}
		return $text;
	}

	/**
	 * "2–4 servings", "4 servings"; '' when unset.
	 */
	public function servings_text(): string {
		$min = $this->servings_min;
		$max = max( $this->servings_max, $min );
		if ( 0 === $min && 0 === $max ) {
			return '';
		}
		if ( 0 === $min || $min === $max ) {
			$n = max( $min, $max );
			/* translators: %s: number of servings */
			return sprintf( _n( '%s serving', '%s servings', $n, 'bluefairy-recipes' ), number_format_i18n( $n ) );
		}
		/* translators: 1: minimum servings, 2: maximum servings */
		return sprintf( __( '%1$s–%2$s servings', 'bluefairy-recipes' ), number_format_i18n( $min ), number_format_i18n( $max ) );
	}

	/**
	 * Does the recipe show a video (type video and a stored URL)?
	 */
	public function has_video(): bool {
		return 'video' === $this->media_type && '' !== $this->video_url;
	}

	/**
	 * The cookie-less embed URL for the stored video, or ''.
	 */
	public function embed_url(): string {
		return $this->has_video() ? VideoUrl::embed_url( $this->video_url ) : '';
	}

	/**
	 * Nutrition fields that have a value, in display order.
	 *
	 * @return array<string, float>
	 */
	public function nutrition_present(): array {
		$out = [];
		foreach ( Meta::NUTRITION_FIELDS as $field => $unit ) {
			if ( isset( $this->nutrition[ $field ] ) ) {
				$out[ $field ] = (float) $this->nutrition[ $field ];
			}
		}
		return $out;
	}
}
