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
		// Writers (role): Ann (2 recipes), Bob (a draft only), Cal (opted out, 1 recipe). Opted-in editor Dee (1 recipe). Subscriber Eve (1 recipe, not listed).
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'Ann', 'user_nicename' => 'ann', 'user_email' => 'ann@example.test' ] );
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

	public function test_rewrite_rules_go_on_top_name_the_archive_slug_and_route_writer_feeds(): void {
		$this->writers->add_rewrite_rules();
		$rules = $GLOBALS['bfrc_test_rewrite_rules'];
		$this->assertCount( 6, $rules );
		foreach ( $rules as $rule ) {
			$this->assertSame( 'top', $rule[2], 'before the post type\'s rules, so chef never matches a recipe slug' );
			$this->assertStringStartsWith( '^recipes/chef', $rule[0] );
			$this->assertStringContainsString( 'post_type=bfrc_recipe', $rule[1] );
		}
		$this->assertSame( 'index.php?post_type=bfrc_recipe&bfrc_writers=1', $rules[0][1] );
		$this->assertStringContainsString( 'bfrc_writers=1&paged=$matches[1]', $rules[1][1] );
		$this->assertStringContainsString( '/feed/(feed|rdf|rss|rss2|atom)/?$', $rules[2][0] );
		$this->assertSame( [ 'feed', 'rdf', 'rss', 'rss2', 'atom' ], Writers::feed_formats() );
		$GLOBALS['wp_rewrite']->feeds[]     = 'json';
		$GLOBALS['wp_rewrite']->feeds[]     = 'a+b'; // add_feed() appends names unsanitised: quoted into the rule.
		$GLOBALS['wp_rewrite']->feeds[]     = 7;     // not a string: dropped.
		$GLOBALS['bfrc_test_rewrite_rules'] = [];
		$this->writers->add_rewrite_rules();
		$this->assertStringContainsString( '/feed/(feed|rdf|rss|rss2|atom|json|a\\+b)/?$', $GLOBALS['bfrc_test_rewrite_rules'][2][0], 'a format added with add_feed() is routed too, quoted for the pattern, so the feed redirect and the routes agree' );
		$this->assertSame( [ 'feed', 'rdf', 'rss', 'rss2', 'atom', 'json', 'a+b' ], Writers::feed_formats(), 'only strings' );
		unset( $GLOBALS['wp_rewrite'] );
		$this->assertSame( [ 'feed', 'rdf', 'rss', 'rss2', 'atom' ], Writers::feed_formats(), 'core\'s defaults before the rewrite object exists' );
		$this->assertStringContainsString( 'author_name=$matches[1]&bfrc_writer=1&feed=$matches[2]', $rules[2][1], 'a writer\'s feed is the author feed of the recipe type' );
		$this->assertStringContainsString( 'author_name=$matches[1]&bfrc_writer=1&feed=$matches[2]', $rules[3][1] );
		$this->assertStringContainsString( 'paged=$matches[2]', $rules[4][1] );
		$this->assertStringContainsString( 'author_name=$matches[1]&bfrc_writer=1', $rules[5][1] );
		$GLOBALS['bfrc_test_rewrite_rules'] = [];
		$this->settings->update( [ 'enabled' => false ] + $this->settings->all() );
		( new Writers( $this->settings ) )->add_rewrite_rules();
		$this->assertSame( [], $GLOBALS['bfrc_test_rewrite_rules'], 'disabled: no writer routes' );
	}

	public function test_register_hooks_wires_the_sitemap_opt_out_and_the_not_exists_clause(): void {
		$this->writers->register_hooks();
		$this->assertSame( 10, has_filter( 'wp_sitemaps_users_query_args', [ $this->writers, 'sitemap_users_query' ] ), 'opted-out writers leave core\'s users sitemap too, enabled or not' );
		$this->assertSame( 10, has_action( 'pre_user_query', [ $this->writers, 'exclude_opted_out' ] ), 'the NOT EXISTS clause behind the listing and the sitemap' );
		$this->assertTrue( Writers::uses_permalinks(), 'the harness has pretty permalinks on' );
		unset( $GLOBALS['wp_rewrite'] );
		$this->assertTrue( Writers::uses_permalinks(), 'before the rewrite object exists, assume them on' );
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

	public function test_public_writer_resolves_a_nicename_only_for_public_writers_and_remembers_the_answer(): void {
		$this->assertSame( 5, $this->writers->public_writer( 'ann' )?->ID );
		$this->assertNull( $this->writers->public_writer( 'bob' ) );
		$this->assertNull( $this->writers->public_writer( 'cal' ) );
		$this->assertNull( $this->writers->public_writer( 'nobody' ) );
		$before = count( $GLOBALS['bfrc_test_count_calls'] );
		$this->writers->public_writer( 'ann' );
		$this->writers->public_writer( 'ann' );
		$this->assertSame( $before, count( $GLOBALS['bfrc_test_count_calls'] ), 'memoised: the guard, the title and the view ask once' );
		$this->writers->reset();
		\BlueFairy\Recipes\Front\Writers::bump(); // core's user_register hook, which the stubs do not fire.
		$this->writers->public_writer( 'ann' );
		$this->assertSame( $before + 1, count( $GLOBALS['bfrc_test_count_calls'] ) );
	}

	public function test_listed_merges_the_role_and_the_opt_ins_drops_zero_counts_sorts_by_name_and_loads_only_the_page(): void {
		$page = $this->writers->listed( 1 );
		$this->assertSame( [ 'Ann', 'Dee' ], array_map( static fn( array $w ): string => $w['user']->display_name, $page['writers'] ) );
		$this->assertSame( [ 'ann', 'dee' ], array_map( static fn( array $w ): string => $w['user']->user_nicename, $page['writers'] ) );
		$this->assertSame( [ 2, 1 ], array_column( $page['writers'], 'count' ) );
		$this->assertSame( 2, $page['total'] );
		$this->assertSame( 1, $page['pages'] );
		$this->assertSame( [], $GLOBALS['bfrc_test_user_queries'], 'no WP_User_Query: one listing SQL pages in the database' );
		$sql = $this->wpdb->queries_matching( 'SELECT post_author AS id, COUNT(*) AS n' );
		$this->assertCount( 2, $sql, 'one COUNT and one page' );
		$this->assertStringStartsWith( 'SELECT COUNT(*) FROM', $sql[0] );
		$this->assertStringEndsWith( 'ORDER BY u.display_name ASC, u.ID ASC LIMIT 24 OFFSET 0', $sql[1], 'the page is cut in SQL, whatever the number of writers' );
		$this->assertStringContainsString( "c.meta_key = 'bfw_capabilities'", $sql[1], 'the Writer role of THIS site' );
		$this->assertStringContainsString( "lm.meta_key = 'bfrc_listed' ORDER BY lm.umeta_id LIMIT 1 ), '' ) <> '0'", $sql[1], 'a writer who switched the listing off is left out, by the first row as is_listed() reads it' );
		$this->assertStringContainsString( "c.meta_value LIKE '%\\\"bfrc\\\\_writer\\\"%'", $sql[1], 'the role matched as core\'s role__in does, so the listing agrees with WP_User::$roles' );
		[ $from, $args ] = Writers::listing_from();
		$this->assertSame( substr_count( $from, '%s' ), count( $args ), 'one value per placeholder' );
		$this->assertSame( 5, $page['writers'][0]['user']->ID, 'the string ID core returns for an array fields query is cast' );
		$this->assertSame( 'ann@example.test', $page['writers'][0]['user']->user_email, 'the page\'s writers are full user objects from the cache, not the id-only rows' );
		$this->assertSame( [], $GLOBALS['bfrc_test_count_calls'], 'the counts come with the page: no count_many_users_posts' );
		$this->assertStringContainsString( "post_status = 'publish' GROUP BY post_author", $sql[1], 'public (published) only: Bob\'s draft does not count' );
		$this->assertSame( [ [ 5, 8 ] ], $GLOBALS['bfrc_test_cached_users'], 'only the page\'s writers are loaded as objects' );
		$this->assertSame( [], $this->writers->listed( 2 )['writers'], 'past the last page' );
		$this->assertCount( 3, $this->wpdb->queries_matching( 'SELECT post_author AS id' ), 'page 1 computed once per request (page 2 is one COUNT, and no page query past the end)' );
		$this->assertSame( $page, $this->writers->listed( 0 ), 'page 0 is page 1' );
	}

	public function test_the_listing_is_cached_across_requests_and_rebuilt_when_writers_or_recipes_change(): void {
		$runs  = fn(): int => count( $this->wpdb->queries_matching( 'SELECT post_author AS id' ) );
		$first = $this->writers->listed( 1 );
		$this->assertSame( 2, $runs() );
		$next = new Writers( $this->settings );
		$this->assertSame( array_map( static fn( array $w ): int => $w['user']->ID, $first['writers'] ), array_map( static fn( array $w ): int => $w['user']->ID, $next->listed( 1 )['writers'] ) );
		$this->assertSame( 2, $runs(), 'a later request reads the cache: no listing query' );
		$this->assertSame( [ 2, 1 ], array_column( $next->listed( 1 )['writers'], 'count' ) );
		foreach ( [ static fn() => Writers::bump(), static fn() => Writers::on_user_meta( 1, 5, 'bfrc_listed' ), static fn() => \BlueFairy\Recipes\Content\Cache::bump() ] as $i => $change ) {
			$change();
			$before = $runs();
			( new Writers( $this->settings ) )->listed( 1 );
			$this->assertSame( $before + 2, $runs(), 'change #' . $i . ' starts a new key' );
		}
		$before = $runs();
		Writers::on_user_meta( 1, 5, 'some_other_meta' );
		( new Writers( $this->settings ) )->listed( 1 );
		$this->assertSame( $before, $runs(), 'unrelated user meta keeps the cache' );
	}

	public function test_register_hooks_bumps_the_listing_on_role_account_and_switch_changes(): void {
		$this->writers->register_hooks();
		foreach ( [ 'set_user_role', 'add_user_role', 'remove_user_role', 'profile_update', 'user_register', 'deleted_user' ] as $hook ) {
			$this->assertNotFalse( has_action( $hook, [ Writers::class, 'bump' ] ), $hook );
		}
		$before = wp_cache_get_last_changed( Writers::LAST_CHANGED_GROUP );
		
		do_action( 'remove_user_from_blog', 5, 1, 0 ); // Multisite Users > Remove: core deletes the capabilities meta directly, no role hook fires.
		$this->assertNotSame( $before, wp_cache_get_last_changed( Writers::LAST_CHANGED_GROUP ), 'a writer removed from the site leaves the cached listing at once' );
		foreach ( [ 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ] as $hook ) {
			$this->assertNotFalse( has_action( $hook, [ Writers::class, 'on_user_meta' ] ), $hook );
		}
	}

	public function test_listed_paginates_at_24_and_orders_names_case_insensitively_with_the_id_as_tiebreak(): void {
		for ( $i = 1; $i <= 30; $i++ ) {
			bfrc_test_add_user( 200 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'Writer %02d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 300 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 200 + $i ] );
		}
		bfrc_test_add_user( 299, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => 'ann', 'user_nicename' => 'ann2' ] );
		bfrc_test_add_post( [ 'ID' => 399, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 299 ] );
		// An opted-in editor named "ann" with a LOWER id than Ann: she comes from the second query, so the
		// merged input has her after Ann; only the id tiebreak puts her first.
		bfrc_test_add_user( 3, [ 'roles' => [ 'editor' ], 'display_name' => 'ann', 'user_nicename' => 'ann3' ] );
		update_user_meta( 3, 'bfrc_listed', '1' );
		bfrc_test_add_post( [ 'ID' => 398, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 3 ] );
		$one = $this->writers->listed( 1 );
		$two = $this->writers->listed( 2 );
		$this->assertCount( 24, $one['writers'] );
		$this->assertCount( 10, $two['writers'], '34 public writers in all' );
		$this->assertSame( 2, $two['pages'] );
		$this->assertSame( [ 3, 5, 299 ], array_map( static fn( array $w ): int => $w['user']->ID, array_slice( $one['writers'], 0, 3 ) ), 'ann (3), Ann (5), ann (299): same name case-insensitively, lower id first, whichever query found them' );
	}

	public function test_a_writer_deleted_since_the_page_was_cached_keeps_the_pages_shape(): void {
		$this->writers->listed( 1 ); // Cached.
		unset( $GLOBALS['bfrc_test_users'][5] ); // Ann's account is deleted outside core's hooks.
		$page = ( new Writers( $this->settings ) )->listed( 1 );
		$this->assertSame( [ 'Ann', 'Dee' ], array_map( static fn( array $w ): string => $w['user']->display_name, $page['writers'] ), 'an id-only row from the cache, not a gap or a fatal' );
		$this->assertSame( 5, $page['writers'][0]['user']->ID );
	}

	public function test_a_page_past_the_last_writer_is_not_cached(): void {
		$this->assertSame( [], $this->writers->listed( 999999 )['writers'] );
		$keys = array_keys( $GLOBALS['bfrc_test_cache'][ \BlueFairy\Recipes\Content\Cache::GROUP ] ?? [] );
		$this->assertSame( [], array_values( array_filter( $keys, static fn( string $k ): bool => str_starts_with( $k, 'writers:999999:' ) ) ), 'any page number a visitor types would otherwise leave a cache entry (review R-F-9)' );
	}

	public function test_a_site_with_more_than_five_hundred_writers_lists_every_one(): void {
		for ( $i = 1; $i <= 520; $i++ ) {
			bfrc_test_add_user( 1000 + $i, [ 'roles' => [ 'bfrc_writer' ], 'display_name' => sprintf( 'W%04d', $i ), 'user_nicename' => 'w' . $i ] );
			bfrc_test_add_post( [ 'ID' => 5000 + $i, 'post_type' => 'bfrc_recipe', 'post_status' => 'publish', 'post_author' => 1000 + $i ] );
		}
		$last = $this->writers->listed( 22 );
		$this->assertSame( 522, $last['total'], 'all 520 (and Ann and Dee): nobody past the 500th is dropped (WPMAP-228)' );
		$this->assertSame( 22, $last['pages'] );
		$this->assertSame( 'W0520', end( $last['writers'] )['user']->display_name, 'the last writer is on the last page' );
	}

	public function test_exclude_opted_out_adds_one_not_exists_clause_to_flagged_user_queries_only(): void {
		$flagged = new \WP_User_Query( [ 'number' => 3, Writers::NOT_OPTED_OUT => true ] );
		$this->writers->exclude_opted_out( $flagged );
		$this->assertSame( " WHERE 1=1 AND NOT EXISTS ( SELECT 1 FROM bfw_usermeta bfrc_lm WHERE bfrc_lm.user_id = bfw_users.ID AND bfrc_lm.meta_key = 'bfrc_listed' AND bfrc_lm.meta_value = '0' )", $flagged->query_where, 'one correlated subquery on the key (the users sitemap sets the flag)' );
		$plain = new \WP_User_Query( [ 'number' => 3 ] );
		$this->writers->exclude_opted_out( $plain );
		$this->assertSame( ' WHERE 1=1', $plain->query_where, 'no flag, no clause: other plugins\' user queries are not ours to filter' );
	}

	public function test_listed_is_empty_when_nobody_qualifies(): void {
		$GLOBALS['bfrc_test_users'] = [];
		$this->assertSame( [ 'writers' => [], 'total' => 0, 'pages' => 0 ], $this->writers->listed( 1 ) );
		$this->assertSame( [], $GLOBALS['bfrc_test_cached_users'] );
	}

	public function test_sitemap_users_query_leaves_out_writers_who_opted_out_without_touching_other_clauses(): void {
		$in   = [ 'number' => 2000, 'meta_query' => [ 'relation' => 'OR', [ 'key' => 'x', 'value' => 'y' ] ] ];
		$args = $this->writers->sitemap_users_query( $in );
		$this->assertSame( 2000, $args['number'] );
		$this->assertSame( $in['meta_query'], $args['meta_query'], 'another plugin\'s meta_query, relation included, is exactly as it was' );
		$this->assertTrue( $args[ Writers::NOT_OPTED_OUT ], 'the exclusion is the flag exclude_opted_out() reads' );
		$this->assertSame( [ Writers::NOT_OPTED_OUT => true ], $this->writers->sitemap_users_query( [] ) );
		add_action( 'pre_user_query', [ $this->writers, 'exclude_opted_out' ] );
		$ids = array_map( static fn( object $r ): int => (int) $r->ID, ( new \WP_User_Query( $this->writers->sitemap_users_query( [ 'fields' => [ 'ID' ] ] ) ) )->get_results() );
		$this->assertContains( 5, $ids, 'Ann is listed' );
		$this->assertNotContains( 7, $ids, 'Cal opted out: out of the sitemap' );
		$this->assertStringContainsString( 'NOT EXISTS', $GLOBALS['bfrc_test_user_query_where'][0] );
	}

	public function test_profile_reads_the_fields_and_keeps_only_http_links(): void {
		update_user_meta( 5, 'bfrc_avatar_id', 77 );
		update_user_meta( 5, 'bfrc_about', 'I bake.' );
		update_user_meta( 5, 'bfrc_website', 'https://ann.test' );
		update_user_meta( 5, 'bfrc_youtube', 'javascript:alert(1)' );
		update_user_meta( 5, 'bfrc_facebook', 'ftp://ann.test/x' );
		update_user_meta( 5, 'bfrc_snapchat', '' );
		$profile = Writers::profile( 5 );
		$this->assertSame( 77, $profile['avatar_id'] );
		$this->assertSame( 0, $profile['header_id'] );
		$this->assertSame( 'I bake.', $profile['about'] );
		$this->assertSame( [ 'bfrc_website' => 'https://ann.test' ], $profile['links'], 'a value that bypassed the profile sanitiser is dropped at render' );
	}

	public function test_instance_is_the_registered_service(): void {
		$this->assertNull( Writers::instance() );
		Plugin::set( 'front.writers', $this->writers );
		$this->assertSame( $this->writers, Writers::instance() );
	}
}
