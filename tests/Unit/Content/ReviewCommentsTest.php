<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Content;

use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\ReviewComments;
use BlueFairy\Recipes\Tests\TestCase;

final class ReviewCommentsTest extends TestCase {

	private ReviewComments $reviews;

	protected function setUp(): void {
		parent::setUp();
		$this->reviews = new ReviewComments();
		bfrc_test_add_post( [ 'ID' => 10, 'post_type' => PostType::NAME, 'post_status' => 'publish', 'post_title' => 'Soup' ] );
		bfrc_test_add_post( [ 'ID' => 11, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'News' ] );
	}

	public function test_register_hooks_covers_every_core_comment_surface(): void {
		$this->reviews->register_hooks();
		foreach ( [ 'comments_template_query_args' => 'without_reviews', 'widget_comments_args' => 'without_reviews', 'comment_feed_where' => 'feed_where', 'get_comments_number' => 'comments_number', 'rest_prepare_comment' => 'rest_prepare' ] as $hook => $method ) {
			$this->assertSame( 10, has_filter( $hook, [ $this->reviews, $method ] ), $hook );
		}
	}

	public function test_without_pro_reviews_leave_the_theme_list_the_widgets_and_the_feeds(): void {
		$this->assertSame( [ 'type__not_in' => [ ReviewComments::TYPE ] ], $this->reviews->without_reviews( [] ) );
		$this->assertSame( [ 'type__not_in' => [ 'pingback', ReviewComments::TYPE ] ], $this->reviews->without_reviews( [ 'type__not_in' => 'pingback' ] ) );
		$this->assertSame( [ 'type__not_in' => [ ReviewComments::TYPE ] ], $this->reviews->without_reviews( [ 'type__not_in' => [ ReviewComments::TYPE ] ] ), 'never twice' );
		$this->assertSame( [ 'type__not_in' => [ ReviewComments::TYPE ] ], $this->reviews->without_reviews( [ 'type__not_in' => '' ] ) );
		$this->assertSame( 'x', $this->reviews->without_reviews( 'x' ), 'not ours to fix' );
		$this->assertSame( "WHERE 1 AND bfw_comments.comment_type <> 'bfrc_review'", $this->reviews->feed_where( 'WHERE 1' ) );
	}

	public function test_while_pro_runs_it_does_this_itself_and_the_base_leaves_everything_alone(): void {
		add_filter( 'bfrc_is_pro', '__return_true' );
		$this->assertSame( [], $this->reviews->without_reviews( [] ) );
		$this->assertSame( 'WHERE 1', $this->reviews->feed_where( 'WHERE 1' ) );
		$this->assertSame( 3, $this->reviews->comments_number( 3, 10 ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'COUNT(*)' ) );
	}

	public function test_without_pro_a_recipes_comment_count_leaves_reviews_out(): void {
		$this->wpdb->queue( 'get_var', '1' );
		$this->assertSame( 1, $this->reviews->comments_number( 3, 10 ), 'three comments, two of them reviews' );
		$sql = $this->wpdb->queries_matching( 'COUNT(*)' );
		$this->assertCount( 1, $sql );
		$this->assertStringEndsWith( "WHERE comment_post_ID = 10 AND comment_approved = '1' AND comment_type <> 'bfrc_review'", $sql[0] );
		$this->assertSame( 4, $this->reviews->comments_number( 4, 11 ), 'not a recipe: core\'s count' );
		$this->assertSame( 0, $this->reviews->comments_number( 0, 10 ), 'nothing to count: no query' );
		$this->assertSame( 5, $this->reviews->comments_number( 5, 999 ) );
		$this->assertCount( 1, $this->wpdb->queries_matching( 'COUNT(*)' ) );
	}

	public function test_the_rest_api_never_shows_a_reviews_avatar_hash_to_a_visitor(): void {
		$data     = [ 'id' => 5, 'author_name' => 'Sam', 'author_avatar_urls' => [ '96' => 'https://secure.gravatar.com/avatar/abc' ] ];
		$review   = new \WP_Comment( [ 'comment_ID' => 5, 'comment_type' => ReviewComments::TYPE ] );
		$response = $this->reviews->rest_prepare( new \WP_REST_Response( $data ), $review );
		$this->assertArrayNotHasKey( 'author_avatar_urls', $response->get_data(), 'the avatar address carries a hash of the e-mail address' );
		$this->assertSame( 'Sam', $response->get_data()['author_name'] );
		$comment = new \WP_Comment( [ 'comment_ID' => 6, 'comment_type' => 'comment' ] );
		$this->assertSame( $data, $this->reviews->rest_prepare( new \WP_REST_Response( $data ), $comment )->get_data(), 'an ordinary comment is core\'s business' );
		$GLOBALS['bfrc_test_caps']['moderate_comments'] = true;
		$this->assertSame( $data, $this->reviews->rest_prepare( new \WP_REST_Response( $data ), $review )->get_data(), 'a moderator sees it' );
		unset( $GLOBALS['bfrc_test_caps']['moderate_comments'] );
		$this->assertSame( 'x', $this->reviews->rest_prepare( 'x', $review ) );
		$no_avatar = $this->reviews->rest_prepare( new \WP_REST_Response( [ 'id' => 5 ] ), $review );
		$this->assertSame( [ 'id' => 5 ], $no_avatar->get_data() );
	}
}
