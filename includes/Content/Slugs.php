<?php
/**
 * Recipe slugs that would shadow a route under the archive.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Under `/{archive}/`, a recipe's slug shares the namespace with the writer
 * pages (`chef`), the submission page (`submit`), pagination (`page`), feeds
 * and every filter taxonomy's archive (`meal-type`, …). A recipe titled
 * "Chef" would otherwise take the writer listing's URL. Core asks
 * `wp_unique_post_slug` for every saved slug of a published or scheduled
 * post (drafts keep theirs until publish, as core does for duplicates);
 * reserved ones get a numeric suffix the way a duplicate would.
 */
final class Slugs {

	/**
	 * Reserved in every install, before the taxonomy codes and the filter.
	 *
	 * @var list<string>
	 */
	public const FIXED = [ 'chef', 'submit', 'page', 'feed', 'rss', 'rss2', 'rdf', 'atom', 'embed', 'trackback' ];

	/**
	 * Suffixes tried before giving up and letting core's own uniqueness run.
	 */
	public const MAX_SUFFIX = 100;

	/**
	 * Constructor.
	 *
	 * @param Taxonomies $taxonomies The filter types, whose codes are reserved.
	 */
	public function __construct( private Taxonomies $taxonomies ) {
	}

	/**
	 * Hook the slug filter.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_unique_post_slug', [ $this, 'filter_unique_slug' ], 10, 6 );
	}

	/**
	 * Every reserved slug: FIXED, the taxonomy codes as they appear in URLs,
	 * and whatever `bfrc_reserved_slugs` adds.
	 *
	 * @return list<string>
	 */
	public function reserved(): array {
		$list = self::FIXED;
		foreach ( $this->taxonomies->definitions() as $code => $def ) {
			$list[] = str_replace( '_', '-', $code );
			$list[] = $code;
		}
		/**
		 * Slugs a recipe may not have because they are routes under the archive.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $list Reserved slugs.
		 */
		$filtered = apply_filters( 'bfrc_reserved_slugs', $list );
		$out      = [];
		foreach ( is_array( $filtered ) ? $filtered : $list as $slug ) {
			if ( is_string( $slug ) && '' !== $slug ) {
				$out[] = strtolower( $slug );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Is $slug reserved?
	 *
	 * @param string $slug A post slug.
	 */
	public function is_reserved( string $slug ): bool {
		return in_array( strtolower( $slug ), $this->reserved(), true );
	}

	/**
	 * `wp_unique_post_slug` callback: a reserved recipe slug becomes
	 * `{slug}-2`, `{slug}-3`, … the first one no other recipe uses.
	 *
	 * @param string $slug          The slug core settled on.
	 * @param int    $post_id       The post.
	 * @param string $post_status   Its status.
	 * @param string $post_type     Its type.
	 * @param int    $post_parent   Its parent.
	 * @param string $original_slug The slug before core's own de-duplication.
	 */
	public function filter_unique_slug( string $slug, int $post_id, string $post_status, string $post_type, int $post_parent, string $original_slug ): string {
		unset( $post_status, $post_parent, $original_slug );
		if ( PostType::NAME !== $post_type || ! $this->is_reserved( $slug ) ) {
			return $slug;
		}
		$reserved = $this->reserved();
		for ( $n = 2; $n <= self::MAX_SUFFIX; $n++ ) {
			$candidate = $slug . '-' . $n;
			if ( ! in_array( strtolower( $candidate ), $reserved, true ) && ! $this->slug_in_use( $candidate, $post_id ) ) {
				return $candidate;
			}
		}
		return $slug . '-' . wp_rand( self::MAX_SUFFIX + 1, PHP_INT_MAX );
	}

	/**
	 * Does another recipe already use $slug?
	 *
	 * @param string $slug    Candidate slug.
	 * @param int    $post_id The post being saved (its own row does not count).
	 */
	private function slug_in_use( string $slug, int $post_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the same bounded lookup core's wp_unique_post_slug() makes; runs on save only.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d LIMIT 1", $slug, PostType::NAME, $post_id ) );
		return null !== $found && '' !== (string) $found;
	}
}
