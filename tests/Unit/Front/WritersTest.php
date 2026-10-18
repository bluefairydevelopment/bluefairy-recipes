<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Front;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Front\Writers;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class WritersTest extends TestCase {

	private Settings $settings;
	private Writers $writers;

	protected function setUp(): void {
		parent::setUp();
		Capabilities::add_roles();
		$this->settings = new Settings();
		$this->writers  = new Writers( $this->settings );
		// Writers (role): Ann (2 recipes), Bob (none), Cal (opted out, 1 recipe). Opted-in editor Dee (1 recipe). Subscriber Eve (1 recipe, not listed).
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann', 'user_nicename' => 'ann' ] );
		bfrc_test_add_user( 6, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Bob', 'user_nicename' => 'bob' ] );
		bfrc_test_add_user( 7, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'cal', 'user_nicename' => 'cal' ] );
		bfrc_test_add_user( 8, [ 'roles' => [ 'editor' ], 'display_name' => 'Dee', 'user_nicename' => 'dee' ] );
		bfrc_test_add_user( 9, [ 'roles' => [ 'subscriber' ], 'display_name' => 'Eve', 'user_nicename' => 'eve' ] );
		update_user_meta( 7, 'bfrc_listed', '0' );
		update_user_meta( 8, 'bfrc_listed', '1' );
		$id = 100;
		foreach ( [ 5, 5, 7, 8, 9 ] as $author ) {
			bfrc_test_add_post( [ 'ID' => ++$id, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => $author ] );
		}
		bfrc_test_add_post( [ 'ID' => ++$id, 'post_type' => 'bfrc_recipe', 'post_status' => 'draft', 'post_author' => 6 ] );
		bfrc_test_add_post( [ 'ID' => ++$id, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 6 ] );
	}

	public function test_rewrite_rules_go_on_top_and_name_the_archive_slug(): void {
		$this->writers->add_rewrite_rules();
		$rules = $GLOBALS['bfrc_test_rewrite_rules'];
		$this->assertCount( 4, $rules );
		foreach ( $rules as $rule ) {
			$this->assertSame( 'top', $rule[2], 'before the post type\'s rules, so chef never matches a recipe slug' );
			$this->assertStringStartsWith( '^recipes/chef', $rule[0] );
			$this->assertStringContainsString( 'post_type=bfrc_recipe', $rule[1] );
		}
		$this->assertSame( 'index.php?post_type=bfrc_recipe&bfrc_writers=1', $rules[0][1] );
		$this->assertStringContainsString( 'author_name=$matches[1]&bfrc_writer=1', $rules[3][1] );
		$this->assertStringContainsString( 'paged=$matches[2]', $rules[2][1] );
		$GLOBALS['bfrc_test_rewrite_rules'] = [];
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		( new Writers( $this->settings ) )->add_rewrite_rules();
		$this->assertSame( [], $GLOBALS['bfrc_test_rewrite_rules'], 'disabled: no writer routes' );
	}

	public function test_query_vars_and_urls(): void {
		$this->assertSame( [ 'x', 'bfrc_writer', 'bfrc_writers' ], $this->writers->query_vars( [ 'x' ] ) );
		$this->assertSame( 'https://example.com/recipes/chef/', $this->writers->listing_url() );
		$this->assertSame( 'https://example.com/recipes/chef/ann/', $this->writers->url( get_userdata( 5 ) ) );
		$this->assertSame( 'recipes', $this->writers->archive_slug() );
	}

	public function test_listing_enabled_needs_the_plugin_and_the_setting(): void {
		$this->assertTrue( $this->writers->listing_enabled() );
		$this->settings->update( [ 'writers_listing' => false ] + $this->settings->all() );
		$this->assertFalse( $this->writers->listing_enabled() );
		$this->settings->update( [ 'writers_listing' => true, 'enabled' => false ] + $this->settings->all() );
		$this->assertFalse( $this->writers->listing_enabled() );
	}

	public function test_is_public_needs_listed_and_a_published_recipe(): void {
		$this->assertTrue( $this->writers->is_public( 5 ), 'Writer with recipes' );
		$this->assertFalse( $this->writers->is_public( 6 ), 'Writer with only a draft' );
		$this->assertFalse( $this->writers->is_public( 7 ), 'opted out' );
		$this->assertTrue( $this->writers->is_public( 8 ), 'editor who opted in, with a recipe' );
		$this->assertFalse( $this->writers->is_public( 9 ), 'a subscriber with a recipe is not listed by default' );
		$this->assertFalse( $this->writers->is_public( 0 ) );
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		$this->assertFalse( ( new Writers( $this->settings ) )->is_public( 5 ), 'disabled: nobody' );
	}

	public function test_public_writer_resolves_a_nicename_only_for_public_writers(): void {
		$this->assertSame( 5, $this->writers->public_writer( 'ann' )?->ID );
		$this->assertNull( $this->writers->public_writer( 'bob' ) );
		$this->assertNull( $this->writers->public_writer( 'cal' ) );
		$this->assertNull( $this->writers->public_writer( 'nobody' ) );
	}

	public function test_listed_merges_the_role_and_the_opt_ins_drops_zero_counts_sorts_by_name_and_counts_once(): void {
		$page = $this->writers->listed( 1 );
		$this->assertSame( [ 'Ann', 'Dee' ], array_map( static fn( array $w ): string => $w['user']->display_name, $page['writers'] ) );
		$this->assertSame( [ 2, 1 ], array_column( $page['writers'], 'count' ) );
		$this->assertSame( 2, $page['total'] );
		$this->assertSame( 1, $page['pages'] );
		$this->assertCount( 2, $GLOBALS['bfrc_test_user_queries'], 'one query for the role, one for the opt-ins' );
		$this->assertSame( Writers::LIMIT, $GLOBALS['bfrc_test_user_queries'][0]['number'], 'bounded' );
		$this->assertCount( 1, $GLOBALS['bfrc_test_count_calls'], 'one count_many_users_posts for everyone' );
		$this->assertTrue( $GLOBALS['bfrc_test_count_calls'][0][2], 'public (published) only' );
		$this->assertSame( [], $this->writers->listed( 2 )['writers'], 'past the last page' );
	}

	public function test_listed_paginates_at_24(): void {
		for ( $i = 1; $i <= 30; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'Writer %02d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		$one = $this->writers->listed( 1 );
		$two = $this->writers->listed( 2 );
		$this->assertCount( 24, $one['writers'] );
		$this->assertCount( 8, $two['writers'], '32 public writers in all' );
		$this->assertSame( 2, $two['pages'] );
		$this->assertSame( 'Ann', $one['writers'][0]['user']->display_name );
	}

	public function test_listed_is_empty_when_nobody_qualifies(): void {
		$GLOBALS['bfrc_test_users'] = [];
		$this->assertSame( [ 'writers' => [], 'total' => 0, 'pages' => 0 ], $this->writers->listed( 1 ) );
	}

	public function test_profile_reads_the_fields_and_keeps_only_non_empty_links(): void {
		update_user_meta( 5, 'bfrc_avatar_id', 77 );
		update_user_meta( 5, 'bfrc_about', 'I bake.' );
		update_user_meta( 5, 'bfrc_website', 'https://ann.test' );
		update_user_meta( 5, 'bfrc_snapchat', '' );
		$profile = Writers::profile( 5 );
		$this->assertSame( 77, $profile['avatar_id'] );
		$this->assertSame( 0, $profile['header_id'] );
		$this->assertSame( 'I bake.', $profile['about'] );
		$this->assertSame( [ 'bfrc_website' => 'https://ann.test' ], $profile['links'] );
	}

	public function test_instance_is_the_registered_service(): void {
		$this->assertNull( Writers::instance() );
		Plugin::set( 'front.writers', $this->writers );
		$this->assertSame( $this->writers, Writers::instance() );
	}
}
