<?php
/**
 * Recipe reviews kept off core's comment surfaces.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\Recipes\Plugin;

/**
 * BlueFairy Recipes Pro stores a recipe review as a comment of the type
 * `bfrc_review`. While Pro runs, it keeps reviews out of the theme's comment
 * list, the comment widgets and feeds and the "N comments" count itself.
 * When Pro is deactivated the reviews stay in the database, and without
 * this class they would appear on the recipe as ordinary comments, without
 * their stars. So the base does the same while Pro is not running.
 *
 * Whether Pro runs or not, core's REST API leaves out a review author's
 * avatar addresses for visitors who cannot moderate: an avatar address
 * carries a hash of the reviewer's e-mail address, which would let anyone
 * test whether a given address wrote a review.
 */
final class ReviewComments {

	/**
	 * The comment type Pro writes reviews as.
	 */
	public const TYPE = 'bfrc_review';

	/**
	 * Attach the hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'comments_template_query_args', [ $this, 'without_reviews' ] );
		add_filter( 'widget_comments_args', [ $this, 'without_reviews' ] );
		add_filter( 'comment_feed_where', [ $this, 'feed_where' ] );
		add_filter( 'get_comments_number', [ $this, 'comments_number' ], 10, 2 );
		add_filter( 'rest_prepare_comment', [ $this, 'rest_prepare' ], 10, 2 );
	}

	/**
	 * `comments_template_query_args`, `widget_comments_args`: leave reviews
	 * out of a comment query while Pro is not running (Pro does it when it is).
	 *
	 * @param mixed $args Comment query arguments.
	 * @return mixed
	 */
	public function without_reviews( mixed $args ): mixed {
		if ( Plugin::is_pro() || ! is_array( $args ) ) {
			return $args;
		}
		$not = $args['type__not_in'] ?? [];
		$not = is_array( $not ) ? $not : ( '' === (string) $not ? [] : [ (string) $not ] );
		if ( ! in_array( self::TYPE, $not, true ) ) {
			$not[] = self::TYPE;
		}
		$args['type__not_in'] = $not;
		return $args;
	}

	/**
	 * `comment_feed_where`: leave reviews out of the comment feeds while Pro
	 * is not running.
	 *
	 * @param mixed $where The feed query's WHERE clause.
	 * @return mixed
	 */
	public function feed_where( mixed $where ): mixed {
		if ( Plugin::is_pro() ) {
			return $where;
		}
		global $wpdb;
		return (string) $where . $wpdb->prepare( " AND {$wpdb->comments}.comment_type <> %s", self::TYPE );
	}

	/**
	 * `get_comments_number`: while Pro is not running, a recipe's count is its
	 * approved comments that are not reviews (core's `comment_count` holds
	 * both). Other posts, and every post while Pro runs, keep core's count.
	 *
	 * @param mixed $count   Core's count.
	 * @param mixed $post_id The post.
	 * @return mixed
	 */
	public function comments_number( mixed $count, mixed $post_id = 0 ): mixed {
		if ( Plugin::is_pro() || (int) $count < 1 ) {
			return $count;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return $count;
		}
		// Cached until any comment changes (core bumps the group's last_changed), so a
		// theme that prints the count several times, or a list of recipes, counts once.
		$key    = 'bfrc_comments_number:' . (int) $post->ID . ':' . wp_cache_get_last_changed( 'comment' );
		$cached = wp_cache_get( $key, 'comment-queries' );
		if ( is_int( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- one count, cached above, only on a recipe with comments, only while Pro (which keeps its own count) is off.
		$number = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_type <> %s", (int) $post->ID, self::TYPE ) );
		wp_cache_set( $key, $number, 'comment-queries' );
		return $number;
	}

	/**
	 * `rest_prepare_comment`: a review's avatar addresses are left out for a
	 * visitor who cannot moderate comments (they carry a hash of the
	 * reviewer's e-mail address).
	 *
	 * @param mixed $response The response.
	 * @param mixed $comment  The comment.
	 * @return mixed
	 */
	public function rest_prepare( mixed $response, mixed $comment ): mixed {
		if ( ! $response instanceof \WP_REST_Response || ! $comment instanceof \WP_Comment || self::TYPE !== $comment->comment_type || current_user_can( 'moderate_comments' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && array_key_exists( 'author_avatar_urls', $data ) ) {
			unset( $data['author_avatar_urls'] );
			$response->set_data( $data );
		}
		return $response;
	}
}
