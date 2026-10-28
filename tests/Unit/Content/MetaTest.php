<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Content;

use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Tests\TestCase;

final class MetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1; // registration and activation run after init
	}

	public function test_register_registers_every_key_single_revisioned_with_a_rest_schema_a_sanitiser_and_the_auth_callback(): void {
		( new Meta() )->register();
		$registered = $GLOBALS['bfrc_test_registered_meta']['bfrc_recipe'] ?? [];
		$this->assertSame( Meta::keys(), array_keys( $registered ) );
		foreach ( $registered as $key => $args ) {
			$this->assertTrue( $args['single'], $key );
			if ( Meta::FEATURED === $key ) {
				$this->assertFalse( $args['revisions_enabled'], 'Featured is not revisioned: core\'s restore copies meta without the auth callback, so a writer could re-apply or wipe a curator\'s flag' );
			} else {
				$this->assertTrue( $args['revisions_enabled'], "{$key}: restoring a revision restores the field" );
			}
			$this->assertIsArray( $args['show_in_rest']['schema'], $key );
			$this->assertTrue( is_callable( $args['sanitize_callback'] ), $key );
			$this->assertSame( [ Meta::class, 'can_edit' ], $args['auth_callback'], $key );
			$this->assertArrayHasKey( 'default', $args, $key );
		}
		$this->assertSame( [ 'type' => 'string', 'enum' => [ 'image', 'video' ] ], $registered[ Meta::MEDIA_TYPE ]['show_in_rest']['schema'] );
		$this->assertSame( 6000, $registered[ Meta::PREP_MINUTES ]['show_in_rest']['schema']['maximum'] );
		$this->assertSame( [ 'type' => 'string' ], $registered[ Meta::VIDEO_URL ]['show_in_rest']['schema'], 'no format: uri, so a client can clear the video with an empty string' );
		$this->assertSame( 200, $registered[ Meta::INGREDIENTS ]['show_in_rest']['schema']['maxItems'] );
		$this->assertSame( 255, $registered[ Meta::INGREDIENTS ]['show_in_rest']['schema']['items']['properties']['label']['maxLength'] );
		$this->assertSame( 'integer', $registered[ Meta::SERVINGS_MAX ]['type'] );
		$this->assertSame( [ 'number', 'null' ], $registered[ Meta::NUTRITION ]['show_in_rest']['schema']['properties']['sodium']['type'] );
		$this->assertSame( Meta::empty_nutrition(), $registered[ Meta::NUTRITION ]['default'] );
	}

	public function test_defaults_are_the_registered_defaults(): void {
		( new Meta() )->register();
		$registered = $GLOBALS['bfrc_test_registered_meta']['bfrc_recipe'];
		$this->assertSame( Meta::keys(), array_keys( Meta::defaults() ) );
		foreach ( Meta::defaults() as $key => $fallback ) {
			$this->assertSame( $fallback, $registered[ $key ]['default'], "$key: defaults() drifted from register()" );
		}
	}

	public function test_a_password_protected_recipes_rest_item_shows_every_field_at_its_default_until_unlocked(): void {
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe', 'post_password' => 'hunter2' ] );
		$meta = [
			Meta::PREP_MINUTES => 20,
			Meta::INGREDIENTS  => [ [ 'measure' => '1', 'label' => 'secret spice', 'type' => 'plain' ] ],
			Meta::STEPS        => [ [ 'text' => 'Stir' ] ],
			Meta::NUTRITION    => [ 'calories' => 250.0 ] + Meta::empty_nutrition(),
			'other_plugin_key' => 'kept',
		];
		$make = static fn(): \WP_REST_Response => new \WP_REST_Response( [ 'id' => 41, 'title' => [ 'rendered' => 'Protected: Soup' ], 'meta' => $meta ] );
		$post = get_post( 41 );

		$locked_response = Meta::hide_protected_meta( $make(), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) );
		$this->assertSame( 'no-store', $locked_response->get_headers()['Cache-Control'], 'a shared cache must not serve one caller\'s locked or unlocked item to the next' );
		$locked          = $locked_response->get_data();
		$this->assertSame( 0, $locked['meta'][ Meta::PREP_MINUTES ] );
		$this->assertSame( [], $locked['meta'][ Meta::INGREDIENTS ] );
		$this->assertSame( [], $locked['meta'][ Meta::STEPS ] );
		$this->assertSame( Meta::empty_nutrition(), $locked['meta'][ Meta::NUTRITION ] );
		$this->assertSame( 'kept', $locked['meta']['other_plugin_key'], 'only our keys are blanked' );
		$this->assertSame( 'Protected: Soup', $locked['title']['rendered'], 'the rest of the item is core\'s business' );
		$this->assertStringNotContainsString( 'secret spice', wp_json_encode( $locked ) );

		$wrong = new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' );
		$wrong->set_param( 'password', 'hunter3' );
		$this->assertSame( [], Meta::hide_protected_meta( $make(), $post, $wrong )->get_data()['meta'][ Meta::INGREDIENTS ], 'a wrong password unlocks nothing' );
		$right = new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' );
		$right->set_param( 'password', 'hunter2' );
		$unlocked = Meta::hide_protected_meta( $make(), $post, $right );
		$this->assertSame( $meta, $unlocked->get_data()['meta'], 'core\'s own rule: the right ?password= reads the item' );
		$this->assertSame( 'no-store', $unlocked->get_headers()['Cache-Control'], 'the unlocked item even more so' );

		$GLOBALS['bfrc_test_caps']['edit_post'] = static fn( array $args ): bool => 41 === ( $args[0] ?? 0 );
		$this->assertSame( $meta, Meta::hide_protected_meta( $make(), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) )->get_data()['meta'], 'the recipe\'s editor sees it (the block editor needs it)' );
		$GLOBALS['bfrc_test_caps'] = [];

		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'bfrc_recipe' ] );
		// A collection: core keeps the items' data only, so the collection response gets the header at dispatch.
		$server     = new \WP_REST_Server();
		$collection = Meta::no_store_when_protected( new \WP_REST_Response( [ $locked, [ 'id' => 42 ] ] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe' ) );
		$this->assertSame( 'no-store', $collection->get_headers()['Cache-Control'], 'a collection that carried a locked recipe is not cached' );
		$still = Meta::no_store_when_protected( new \WP_REST_Response( [ [ 'id' => 42 ] ] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe' ) );
		$this->assertSame( 'no-store', $still->get_headers()['Cache-Control'], 'the flag outlives the after-callbacks hook: a cache deciding at rest_post_dispatch must still see it' );
		$this->assertTrue( Meta::bypass_rest_cache( false, new \WP_REST_Request() ), 'the gateway bypass answers from the same flag' );
		$this->assertNull( Meta::forget_protected( null, [], new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe' ) ), 'the next dispatch starts clean and the pre-callback value passes through' );
		$this->assertFalse( Meta::bypass_rest_cache( false, new \WP_REST_Request() ) );
		$this->assertTrue( Meta::bypass_rest_cache( true, new \WP_REST_Request() ), 'a bypass someone else decided stands' );
		$next = Meta::no_store_when_protected( new \WP_REST_Response( [ [ 'id' => 42 ] ] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe' ) );
		$this->assertArrayNotHasKey( 'Cache-Control', $next->get_headers(), 'the flag is per request: the next response is untouched' );
		Meta::hide_protected_meta( $make(), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) );
		$wrapped = Meta::no_store_when_protected( [ 'id' => 41, 'plain' => 'array' ], $server, new \WP_REST_Request( 'GET', '/bfrc/v1/x' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $wrapped, 'a route returning a plain array is wrapped as core would, so it can carry the header' );
		$this->assertSame( 'no-store', $wrapped->get_headers()['Cache-Control'] );
		Meta::forget_protected( null, [], new \WP_REST_Request() );
		$this->assertSame( [ 'id' => 42 ], Meta::no_store_when_protected( [ 'id' => 42 ], $server, new \WP_REST_Request( 'GET', '/bfrc/v1/x' ) ), 'nothing to mark: the array passes through untouched' );
		$err = new \WP_Error( 'x', 'y' );
		$this->assertSame( $err, Meta::no_store_when_protected( $err, $server, new \WP_REST_Request() ) );
		Meta::forget_protected( null, [], new \WP_REST_Request() );
		$bare = Meta::no_store_when_protected( new \WP_HTTP_Response( [ [ 'id' => 41, 'type' => 'post', 'subtype' => 'bfrc_recipe' ] ], 201, [ 'X-Bare' => '1' ] ), $server, new \WP_REST_Request( 'GET', '/bfrc/v1/x' ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $bare, 'a bare WP_HTTP_Response is read and wrapped as core does' );
		$this->assertSame( 'no-store', $bare->get_headers()['Cache-Control'], 'its rows name a locked recipe' );
		$this->assertSame( 201, $bare->get_status(), 'wrapping keeps the route\'s own status' );
		$this->assertSame( '1', $bare->get_headers()['X-Bare'], 'and its headers, as core does' );
		// The depth counter must balance on error responses too, or every later dispatch in the request keeps a stale flag.
		$cookies_before = $_COOKIE;
		$_COOKIE        = [];
		Meta::forget_protected( null, [], new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe' ) );
		Meta::no_store_when_protected( new \WP_Error( 'rest_post_invalid_id', 'gone' ), $server, new \WP_REST_Request( 'GET', '/wp/v2/media/9' ) );
		Meta::forget_protected( null, [], new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) );
		Meta::hide_protected_meta( $make(), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) );
		Meta::no_store_when_protected( new \WP_REST_Response( [] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) );
		Meta::forget_protected( null, [], new \WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertFalse( Meta::bypass_rest_cache( false, new \WP_REST_Request() ), 'after an error response the next outermost dispatch still starts clean' );
		$_COOKIE = $cookies_before;
		$this->assertSame( 'x', Meta::no_store_when_protected( 'x', $server, new \WP_REST_Request() ), 'not a response object: passed through' );
		$search = Meta::no_store_when_protected( new \WP_REST_Response( [ [ 'id' => 41, 'type' => 'post', 'subtype' => 'bfrc_recipe' ], [ 'id' => 42, 'type' => 'post', 'subtype' => 'bfrc_recipe' ] ] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/search' ) );
		$this->assertSame( 'no-store', $search->get_headers()['Cache-Control'], 'a search listing a locked recipe: its items are embedded after the headers go out, so the ids decide' );
		$this->assertTrue( Meta::bypass_rest_cache( false, new \WP_REST_Request() ), 'and the request-level bypass says so too, not only the header' );
		Meta::forget_protected( null, [], new \WP_REST_Request() );
		$open_search = Meta::no_store_when_protected( new \WP_REST_Response( [ [ 'id' => 42, 'type' => 'post', 'subtype' => 'bfrc_recipe' ], [ 'id' => 41, 'type' => 'post', 'subtype' => 'page' ] ] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/search' ) );
		$this->assertArrayNotHasKey( 'Cache-Control', $open_search->get_headers(), 'only a locked recipe counts; a page with the same id does not' );
		// A caller with an unlock cookie: whatever they read may be unlocked for them alone (a Query Loop in
		// another recipe renders the locked body), so none of their responses may be stored.
		$this->assertFalse( Meta::caller_has_unlock_cookie() );
		$_COOKIE['wp-postpass_abc123'] = 'hash';
		$this->assertTrue( Meta::caller_has_unlock_cookie() );
		$with_cookie = Meta::no_store_when_protected( new \WP_REST_Response( [ [ 'id' => 42 ] ] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe' ) );
		$this->assertSame( 'no-store', $with_cookie->get_headers()['Cache-Control'], 'an open recipe\'s item, but a cookie holder asked: not stored' );
		$this->assertTrue( Meta::bypass_rest_cache( false, new \WP_REST_Request() ), 'and the gateway bypasses at request level' );
		$_COOKIE = [ 'wordpress_test_cookie' => 'WP Cookie check', 123 => 'a numeric cookie name arrives as an int key' ];
		$this->assertFalse( Meta::caller_has_unlock_cookie(), 'other cookies are not unlock cookies, and an int key is not a TypeError' );
		$_COOKIE = [];
		$typeless = Meta::no_store_when_protected( new \WP_REST_Response( [ [ 'id' => 41 ] ] ), $server, new \WP_REST_Request( 'GET', '/bfrc/v1/x' ) );
		$this->assertArrayNotHasKey( 'Cache-Control', $typeless->get_headers(), 'a row without type/subtype is not a search result naming a recipe' );
		// A route that composes items through nested dispatches: the inner dispatch must not wipe what the outer prepared.
		Meta::forget_protected( null, [], new \WP_REST_Request( 'GET', '/bfrc/v1/outer' ) );
		Meta::hide_protected_meta( $make(), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) );
		Meta::forget_protected( null, [], new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/42' ) ); // nested
		Meta::no_store_when_protected( new \WP_REST_Response( [] ), $server, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/42' ) );
		$outer = Meta::no_store_when_protected( new \WP_REST_Response( [ 'composed' => true ] ), $server, new \WP_REST_Request( 'GET', '/bfrc/v1/outer' ) );
		$this->assertSame( 'no-store', $outer->get_headers()['Cache-Control'], 'the outer response remembers the locked recipe its nested dispatch prepared' );
		Meta::forget_protected( null, [], new \WP_REST_Request() );
		$this->assertFalse( Meta::bypass_rest_cache( false, new \WP_REST_Request() ), 'the next outermost dispatch starts clean' );
		$open = Meta::hide_protected_meta( $make(), get_post( 42 ), new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/42' ) );
		$this->assertSame( $meta, $open->get_data()['meta'], 'no password, nothing to hide' );
		$this->assertArrayNotHasKey( 'Cache-Control', $open->get_headers(), 'an open recipe caches as core decides' );
		$GLOBALS['bfrc_test_password_unlocked'][41] = true;
		$this->assertSame( $meta, Meta::hide_protected_meta( $make(), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) )->get_data()['meta'], 'the unlock cookie counts, as it does for content' );
		$GLOBALS['bfrc_test_password_unlocked'] = [];
		$other = Meta::hide_protected_meta( new \WP_REST_Response( [ 'id' => 41, 'meta' => [ 'other_plugin_key' => 'kept' ] ] ), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) )->get_data();
		$this->assertSame( [ 'other_plugin_key' => 'kept' ], $other['meta'], 'keys absent from the item (a _fields selection) are not added' );
		$bare = Meta::hide_protected_meta( new \WP_REST_Response( [ 'id' => 41 ] ), $post, new \WP_REST_Request( 'GET', '/wp/v2/bfrc_recipe/41' ) );
		$this->assertSame( [ 'id' => 41 ], $bare->get_data(), 'an item without meta (a context that omits it) passes through' );
	}

	public function test_register_hooks_registers_on_init_after_the_post_type(): void {
		$m = new Meta();
		$m->register_hooks();
		$this->assertSame( 7, has_action( 'init', [ $m, 'register' ] ) );
		$this->assertSame( 10, has_filter( 'rest_prepare_bfrc_recipe', [ Meta::class, 'hide_protected_meta' ] ), 'a locked recipe\'s REST item carries no field values' );
		$this->assertSame( 10, has_filter( 'rest_request_after_callbacks', [ Meta::class, 'no_store_when_protected' ] ), 'inside dispatch, before any rest_post_dispatch consumer (a REST cache stores there)' );
		$this->assertFalse( has_filter( 'rest_post_dispatch', [ Meta::class, 'no_store_when_protected' ] ), 'not on rest_post_dispatch: the API Gateway stores at that hook and priority before a later registrant runs' );
		$this->assertSame( 10, has_filter( 'rest_request_before_callbacks', [ Meta::class, 'forget_protected' ] ), 'the flag resets when the next dispatch starts, not after the callbacks' );
		$this->assertSame( 10, has_filter( 'bfag_cache_bypass', [ Meta::class, 'bypass_rest_cache' ] ), 'the gateway asks at request level too: a rule header cannot overwrite that' );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			$this->assertSame( 10, has_action( $hook, [ Meta::class, 'flush_featured_ids' ] ), $hook );
		}
	}

	public function test_featured_ids_is_one_indexed_lookup_cached_until_a_featured_value_changes(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->queue( 'get_col', [ '41', '7' ] );
		$this->assertSame( [ 41, 7 ], Meta::featured_ids() );
		$sql = $wpdb->queries_matching( "meta_value = '1'" );
		$this->assertCount( 1, $sql );
		$this->assertStringContainsString( "pm.meta_key = '_bfrc_featured'", $sql[0] );
		$this->assertStringContainsString( "p.post_type = 'bfrc_recipe'", $sql[0] );
		$this->assertStringNotContainsString( 'post_status', $sql[0], 'any status: the set is used to exclude and to rank, never to list' );
		$wpdb->queue( 'get_col', [ '99' ] );
		$this->assertSame( [ 41, 7 ], Meta::featured_ids(), 'cached: the second call runs no query' );
		$this->assertCount( 1, $wpdb->queries_matching( "meta_value = '1'" ) );
		Meta::flush_featured_ids( 1, 41, Meta::PREP_MINUTES );
		$this->assertSame( [ 41, 7 ], Meta::featured_ids(), 'another key changing keeps the cache' );
		Meta::flush_featured_ids( [ 1 ], 41, Meta::FEATURED );
		$this->assertSame( [ 99 ], Meta::featured_ids(), 'a featured change refreshes it' );
		$wpdb->queue( 'get_col', null );
		Meta::flush_featured_ids( 1, 41, Meta::FEATURED );
		$this->assertSame( [], Meta::featured_ids(), 'a failed query is an empty set' );
	}

	public function test_can_curate_is_edit_others_bfrc_recipes(): void {
		$this->assertFalse( Meta::can_curate() );
		bfrc_test_grant( 'edit_post' );
		$this->assertFalse( Meta::can_curate(), 'editing the recipe is not curating' );
		bfrc_test_grant( Meta::CURATE_CAP );
		$this->assertTrue( Meta::can_curate() );
	}

	public function test_can_edit_asks_for_edit_post_on_that_recipe_for_the_user_core_names(): void {
		$this->assertFalse( Meta::can_edit( true, Meta::STEPS, 41 ), 'core\'s default is ignored; the user must hold edit_post' );
		bfrc_test_grant( 'edit_post' );
		$this->assertTrue( Meta::can_edit( false, Meta::STEPS, 41 ), 'user 0 = the current user' );
		$GLOBALS['bfrc_test_roles']['tester'] = new \WP_Role( 'tester', [ 'edit_post' => true ] );
		bfrc_test_add_user( 9, [ 'roles' => [ 'tester' ] ] );
		bfrc_test_add_user( 10, [ 'roles' => [ 'subscriber' ] ] );
		$this->assertTrue( Meta::can_edit( false, Meta::STEPS, 41, 9 ) );
		$this->assertFalse( Meta::can_edit( false, Meta::FEATURED, 41, 9 ), 'Featured is curation: edit_post alone is not enough' );
		$this->assertFalse( Meta::can_edit( false, Meta::FEATURED, 41 ) );
		bfrc_test_grant( Meta::CURATE_CAP );
		$this->assertTrue( Meta::can_edit( false, Meta::FEATURED, 41 ), 'the current user with edit_others_bfrc_recipes may' );
		$this->assertFalse( Meta::can_edit( false, Meta::FEATURED, 41, 9 ), 'a named user is judged by their own roles, not the current user\'s grant' );
		$this->assertFalse( Meta::can_edit( true, Meta::STEPS, 41, 10 ), 'the named user, not the current one' );
	}

	public function test_servings_max_follows_min_up_and_min_follows_max_down_on_any_write_path(): void {
		( new Meta() )->register_hooks();
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'post' ] );
		update_post_meta( 41, Meta::SERVINGS_MIN, 2 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 4 );
		update_post_meta( 41, Meta::SERVINGS_MIN, 6 );
		$this->assertSame( '6', get_post_meta( 41, Meta::SERVINGS_MAX, true ), 'max follows min up' );
		update_post_meta( 41, Meta::SERVINGS_MAX, 3 );
		$this->assertSame( '3', get_post_meta( 41, Meta::SERVINGS_MIN, true ), 'min follows max down' );
		update_post_meta( 41, Meta::SERVINGS_MAX, 0 );
		$this->assertSame( '3', get_post_meta( 41, Meta::SERVINGS_MIN, true ), 'max 0 means unset and changes nothing' );
		add_post_meta( 42, Meta::SERVINGS_MIN, 9 );
		update_post_meta( 42, Meta::SERVINGS_MAX, 1 );
		$this->assertSame( '9', get_post_meta( 42, Meta::SERVINGS_MIN, true ), 'other post types are not touched' );
		Meta::reconcile_servings( 1, 42, Meta::SERVINGS_MAX, 1 );
		$this->assertSame( '9', get_post_meta( 42, Meta::SERVINGS_MIN, true ), 'called directly for a non-recipe: still nothing' );
		Meta::reconcile_servings( 1, 41, Meta::FEATURED, true );
		$this->assertSame( '3', get_post_meta( 41, Meta::SERVINGS_MIN, true ), 'another key: nothing' );
	}

	public function test_media_type_is_image_unless_exactly_video(): void {
		$this->assertSame( 'video', Meta::sanitize_media_type( 'video' ) );
		foreach ( [ 'image', 'VIDEO', 'audio', '', null, 1, true ] as $other ) {
			$this->assertSame( 'image', Meta::sanitize_media_type( $other ), var_export( $other, true ) );
		}
	}

	public function test_minutes_and_servings_are_whole_bounded_numbers_or_zero(): void {
		$this->assertSame( 0, Meta::sanitize_minutes( 0 ) );
		$this->assertSame( 90, Meta::sanitize_minutes( '90' ) );
		$this->assertSame( 90, Meta::sanitize_minutes( 90.9 ) );
		$this->assertSame( 1000, Meta::sanitize_minutes( '1e3' ), 'exponent notation is a number' );
		$this->assertSame( 6000, Meta::sanitize_minutes( 6000 ), 'the bound itself' );
		$this->assertSame( 6000, Meta::sanitize_minutes( 6001 ) );
		$this->assertSame( 0, Meta::sanitize_minutes( -5 ) );
		$this->assertSame( 6000, Meta::sanitize_minutes( 9.3e18 ), 'a float beyond int range is clamped, not cast (no warning)' );
		foreach ( [ 'abc', '', null, [ 1 ], INF, NAN, '1e1000' ] as $garbage ) {
			$this->assertSame( 0, Meta::sanitize_minutes( $garbage ), var_export( $garbage, true ) );
		}
		$this->assertSame( 999, Meta::sanitize_servings( 1000 ) );
		$this->assertSame( 4, Meta::sanitize_servings( '4' ) );
	}

	public function test_bool(): void {
		foreach ( [ true, 1, '1', 'on', 'true', 'yes', ' YES ' ] as $yes ) {
			$this->assertTrue( Meta::sanitize_bool( $yes ), var_export( $yes, true ) );
		}
		foreach ( [ false, 0, '0', 'off', '', null, [], 2.5 ] as $no ) {
			$this->assertFalse( Meta::sanitize_bool( $no ), var_export( $no, true ) );
		}
	}

	public function test_ingredients_keep_labelled_rows_in_order_with_plain_type_and_bounded_text(): void {
		$rows = Meta::sanitize_ingredients(
			[
				[ 'measure' => ' 2 cups ', 'label' => ' <b>flour</b> ', 'type' => 'plain' ],
				[ 'measure' => '', 'label' => 'salt', 'type' => 'internal', 'product_id' => 7 ],
				[ 'measure' => '1', 'label' => '' ],
				'not a row',
				[ 'label' => str_repeat( 'x', 300 ), 'measure' => str_repeat( 'm', 100 ) ],
				[ 'label' => [ 'array' ] ],
			]
		);
		$this->assertSame(
			[
				[ 'measure' => '2 cups', 'label' => 'flour', 'type' => 'plain' ],
				[ 'measure' => '', 'label' => 'salt', 'type' => 'internal', 'product_id' => 7 ],
				[ 'measure' => str_repeat( 'm', 64 ), 'label' => str_repeat( 'x', 255 ), 'type' => 'plain' ],
			],
			$rows,
			'unlabelled, non-array and array-labelled rows dropped; a row of an add-on that is off keeps its type and its field'
		);
		$this->assertSame( [], Meta::sanitize_ingredients( 'nope' ) );
	}

	public function test_a_row_whose_add_on_is_off_keeps_its_fields_as_plain_text_until_the_add_on_is_back(): void {
		$rows = Meta::sanitize_ingredients(
			[
				[ 'measure' => '1', 'label' => 'olive oil', 'type' => 'affiliate', 'affiliate_url' => 'https://shop.example/oil?a=1&b=2', 'note' => ' <b>ok</b> ', 'flag' => true, 'list' => [ 1 ], 'Bad Key' => 'x', 'label2' => str_repeat( 'y', 3000 ) ],
				[ 'label' => 'flour', 'type' => 'Bad Type', 'product_id' => 9 ],
				[ 'label' => 'many', 'type' => 'other' ] + array_combine( array_map( static fn( int $i ): string => 'f' . $i, range( 1, 12 ) ), range( 1, 12 ) ),
			]
		);
		$this->assertSame( [ 'measure' => '1', 'label' => 'olive oil', 'type' => 'affiliate', 'affiliate_url' => 'https://shop.example/oil?a=1&b=2', 'note' => 'ok', 'label2' => str_repeat( 'y', Meta::FOREIGN_VALUE_MAX ) ], $rows[0], 'saving with the add-on off loses nothing the add-on stored' );
		$this->assertSame( [ 'measure' => '', 'label' => 'flour', 'type' => 'plain' ], $rows[1], 'not a type name: plain, nothing kept' );
		$this->assertCount( 3 + Meta::FOREIGN_FIELDS_MAX, $rows[2], 'a bounded number of fields' );
		$this->assertCount( 200, Meta::sanitize_ingredients( array_fill( 0, 250, [ 'label' => 'x', 'type' => 'internal', 'product_id' => 1 ] ) ), 'and a bounded number of rows' );
	}

	public function test_ingredients_are_capped_at_200_rows(): void {
		$rows = array_fill( 0, 250, [ 'label' => 'x' ] );
		$this->assertCount( 200, Meta::sanitize_ingredients( $rows ) );
	}

	public function test_the_add_on_seams_add_types_and_their_sanitised_fields(): void {
		add_filter( 'bfrc_ingredient_types', static fn( array $t ): array => array_merge( $t, [ 'internal', 'affiliate', 'Bad Type', '' ] ) );
		add_filter(
			'bfrc_ingredient_row_fields',
			static function ( array $fields, string $type ): array {
				if ( 'internal' === $type ) {
					$fields['product_id'] = static fn( mixed $v ): int => max( 0, (int) $v );
					$fields['label']      = static fn( mixed $v ): string => 'hijack'; // the core fields cannot be overridden
					$fields['Bad Name']   = static fn( mixed $v ): string => 'x';
					$fields['nocall']     = 'not callable';
					$fields['obj']        = static fn( mixed $v ): object => new \stdClass(); // a non-scalar result is dropped
					$fields['list']       = static fn( mixed $v ): array => [ 1, 'a' ];   // a list of scalars is kept
				}
				return $fields;
			},
			10,
			2
		);
		$this->assertSame( [ 'plain', 'internal', 'affiliate' ], Meta::ingredient_types() );
		$this->assertSame( [ 'product_id', 'obj', 'list' ], array_keys( Meta::ingredient_row_fields( 'internal' ) ) );
		$this->assertSame( [], Meta::ingredient_row_fields( 'affiliate' ) );
		$rows = Meta::sanitize_ingredients(
			[
				[ 'label' => 'butter', 'type' => 'internal', 'product_id' => '12', 'affiliate_url' => 'https://x.test' ],
				[ 'label' => 'oil', 'type' => 'affiliate', 'product_id' => '12' ],
			]
		);
		$this->assertSame( [ 'measure' => '', 'label' => 'butter', 'type' => 'internal', 'product_id' => 12, 'list' => [ 1, 'a' ] ], $rows[0], 'only the type\'s own extra fields survive, and only scalar or scalar-list results' );
		$this->assertSame( [ 'measure' => '', 'label' => 'oil', 'type' => 'affiliate' ], $rows[1] );
	}

	public function test_steps_keep_post_safe_html_drop_empties_and_cap_at_200(): void {
		$steps = Meta::sanitize_steps( [ [ 'text' => ' Mix <em>well</em><script>x()</script> ' ], [ 'text' => '   ' ], 'Bake', [ 'text' => [ 'arr' ] ], null ] );
		$this->assertSame( [ [ 'text' => 'Mix <em>well</em>' ], [ 'text' => 'Bake' ] ], $steps, 'a bare string is a step too' );
		$this->assertCount( 200, Meta::sanitize_steps( array_fill( 0, 201, [ 'text' => 's' ] ) ) );
		$this->assertSame( [], Meta::sanitize_steps( 'nope' ) );
	}

	public function test_nutrition_has_every_field_as_a_non_negative_two_decimal_number_or_null(): void {
		$n = Meta::sanitize_nutrition( [ 'calories' => '250', 'fat' => '12,34', 'sugar' => -1, 'protein' => 'abc', 'sodium' => '', 'fiber' => 3.14159, 'bogus' => 9 ] );
		$this->assertSame( array_keys( Meta::NUTRITION_FIELDS ), array_keys( $n ) );
		$this->assertSame( 250.0, $n['calories'] );
		$this->assertSame( 12.34, $n['fat'], 'a decimal comma is accepted' );
		$this->assertSame( 1200.0, Meta::sanitize_nutrition( [ 'sodium' => '1,200' ] )['sodium'], 'where the decimal point is a dot (no $wp_locale: core\'s default), 1,200 is twelve hundred, not dropped' );
		$this->assertSame( [ 1200.5, 12345.0, 0.5, 12.5 ], array_values( array_slice( Meta::sanitize_nutrition( [ 'calories' => '1,200.5', 'fat' => '12,345', 'saturated_fat' => '0,500', 'carbohydrates' => '12,5' ] ), 0, 4 ) ), 'grouped thousands with a decimal; a lone comma that cannot be a group is a decimal comma' );
		$this->assertNull( Meta::sanitize_nutrition( [ 'sodium' => '1,20,0' ] )['sodium'], 'not a number in any language' );
		$GLOBALS['wp_locale'] = (object) [ 'number_format' => [ 'decimal_point' => ',', 'thousands_sep' => '.' ] ];
		try {
			$this->assertSame( [ 1.2, 1200.0, 1200.5, 12.5 ], array_values( array_slice( Meta::sanitize_nutrition( [ 'calories' => '1,200', 'fat' => '1.200', 'saturated_fat' => '1.200,5', 'carbohydrates' => '12.5' ] ), 0, 4 ) ), 'where the decimal point is a comma, 1,200 is one point two and 1.200 twelve hundred' );
			$this->assertSame( 0.5, Meta::sanitize_nutrition( [ 'sodium' => '0.500' ] )['sodium'], 'a leading zero before the dot can only be a decimal: never five hundred' );
			$this->assertSame( 0.5, Meta::sanitize_nutrition( [ 'sodium' => '0,5' ] )['sodium'] );
		} finally {
			unset( $GLOBALS['wp_locale'] );
		}
		$this->assertSame( 1.2, Meta::sanitize_nutrition( [ 'sodium' => '1.200' ] )['sodium'], 'a dot is always decimal' );
		$this->assertSame( 12.5, Meta::sanitize_nutrition( [ 'sodium' => '12,5' ] )['sodium'] );
		$this->assertNull( Meta::sanitize_nutrition( [ 'sodium' => '1,2,3' ] )['sodium'] );
		$this->assertSame( 0.5, Meta::sanitize_nutrition( [ 'sodium' => '0,500' ] )['sodium'], 'a leading zero before the comma can only be a decimal' );
		$this->assertSame( 1.23, Meta::sanitize_nutrition( [ 'sodium' => '1,2345' ] )['sodium'] );
		$this->assertNull( Meta::sanitize_nutrition( [ 'calories' => 200000 ] )['calories'], 'above the ceiling' );
		$this->assertSame( '0.0', var_export( Meta::sanitize_nutrition( [ 'calories' => '-0' ] )['calories'], true ), 'never -0.0 (=== cannot tell them apart; var_export can)' );
		$this->assertSame( 0.0, Meta::sanitize_nutrition( [ 'calories' => 0 ] )['calories'], 'an explicit zero is a value' );
		$this->assertNull( Meta::sanitize_nutrition( [ 'calories' => true ] )['calories'] );
		$this->assertSame( 3.14, $n['fiber'] );
		$this->assertNull( $n['sugar'], 'negative is not a quantity' );
		$this->assertNull( $n['protein'] );
		$this->assertNull( $n['sodium'] );
		$this->assertNull( $n['carbohydrates'], 'absent is null, never 0 (0 would render as a value)' );
		$this->assertArrayNotHasKey( 'bogus', $n );
		$this->assertSame( Meta::empty_nutrition(), Meta::sanitize_nutrition( 'garbage' ) );
		$this->assertNull( Meta::sanitize_nutrition( [ 'calories' => INF ] )['calories'] );
	}

	public function test_keys_lists_ten_underscore_prefixed_keys(): void {
		$this->assertCount( 10, Meta::keys() );
		foreach ( Meta::keys() as $key ) {
			$this->assertStringStartsWith( '_bfrc_', $key, 'hidden from the Custom Fields box' );
		}
	}
}
