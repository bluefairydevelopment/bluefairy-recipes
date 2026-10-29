<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Content;

use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class TaxonomiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1; // registration and activation run after init
	}

	private function taxonomies(): Taxonomies {
		return new Taxonomies( new Settings() );
	}

	public function test_definitions_are_the_four_system_types_in_sort_order_by_default(): void {
		$defs = $this->taxonomies()->definitions();
		$this->assertSame( Settings::SYSTEM_TAXONOMIES, array_keys( $defs ) );
		$this->assertSame( [ 'code' => 'diet', 'label' => 'Diets', 'singular' => 'Diet', 'sort_order' => 20, 'enabled' => true, 'system' => true, 'terms' => [ 'Vegetarian', 'Vegan', 'Gluten-Free', 'Dairy-Free' ] ], $defs['diet'] );
	}

	public function test_the_filter_adds_valid_custom_types_and_cannot_touch_system_ones(): void {
		add_filter(
			'bfrc_filter_taxonomies',
			static function ( array $defs ): array {
				$defs['occasion']            = [ 'label' => 'Occasions', 'singular' => 'Occasion', 'sort_order' => 15 ];
				$defs['diet']                = [ 'label' => 'Hijacked' ];
				$defs['Bad-Code']            = [ 'label' => 'x' ];
				$defs[ str_repeat( 'a', 28 ) ] = [ 'label' => 'too long' ];
				$defs['nolabel']             = [ 'sort_order' => 1 ];
				$defs['notarray']            = 'x';
				$defs['texture']             = [ 'label' => ' <b>Textures</b> ' ];
				return $defs;
			}
		);
		$defs = $this->taxonomies()->definitions();
		$this->assertSame( [ 'meal_type', 'occasion', 'diet', 'season', 'cuisine', 'texture' ], array_keys( $defs ), 'sorted by sort_order then code; texture defaults to 100' );
		$this->assertSame( 'Diets', $defs['diet']['label'], 'a system code cannot be redefined' );
		$this->assertFalse( $defs['occasion']['system'] );
		$this->assertSame( [], $defs['occasion']['terms'], 'custom types seed no terms' );
		$this->assertSame( 'Textures', $defs['texture']['label'], 'labels are plain text' );
		$this->assertSame( 'Textures', $defs['texture']['singular'], 'singular falls back to the label' );
	}

	public function test_settings_switch_and_reorder_definitions(): void {
		update_option( 'bfrc_settings', [ 'taxonomies' => [ 'diet' => [ 'enabled' => false, 'sort_order' => 1 ], 'cuisine' => [ 'enabled' => true, 'sort_order' => 1 ] ] ] );
		$defs = $this->taxonomies()->definitions();
		$this->assertSame( [ 'cuisine', 'diet', 'meal_type', 'season' ], array_keys( $defs ), 'equal sort orders fall back to the code' );
		$this->assertFalse( $defs['diet']['enabled'] );
		$this->assertSame( [ 'cuisine', 'meal_type', 'season' ], array_keys( $this->taxonomies()->enabled() ) );
	}

	public function test_register_names_every_definition_bfrc_code_against_the_recipe_post_type_with_our_capabilities(): void {
		$this->taxonomies()->register();
		$this->assertSame( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ], array_keys( $GLOBALS['bfrc_test_taxonomies'] ) );
		$reg = $GLOBALS['bfrc_test_taxonomies']['bfrc_meal_type'];
		$this->assertSame( [ 'bfrc_recipe' ], $reg['object_type'] );
		$this->assertSame( [ 'bfrc_recipe' ], $reg['object_type'] );
		$this->assertSame( Taxonomies::CAPABILITIES, $reg['args']['capabilities'] );
		$this->assertTrue( $reg['args']['public'] );
		$this->assertTrue( $reg['args']['show_in_rest'] );
		$this->assertTrue( $reg['args']['show_admin_column'] );
		$this->assertFalse( $reg['args']['hierarchical'] );
		$this->assertSame( [ 'slug' => 'recipes/meal-type', 'with_front' => false ], $reg['args']['rewrite'], 'the archive slug leads and the code is dashed' );
		$this->assertSame( 'bfrc_meal_type', $reg['args']['query_var'] );
		$this->assertSame( 'Meal Types', $reg['args']['labels']['name'] );
	}

	public function test_register_follows_the_archive_slug_setting(): void {
		update_option( 'bfrc_settings', [ 'archive_slug' => 'cookbook' ] );
		$this->taxonomies()->register();
		$this->assertSame( 'cookbook/diet', $GLOBALS['bfrc_test_taxonomies']['bfrc_diet']['args']['rewrite']['slug'] );
	}

	public function test_a_disabled_taxonomy_or_a_disabled_plugin_registers_privately_but_keeps_the_admin(): void {
		update_option( 'bfrc_settings', [ 'taxonomies' => [ 'diet' => [ 'enabled' => false ] ] ] );
		$this->taxonomies()->register();
		$diet = $GLOBALS['bfrc_test_taxonomies']['bfrc_diet']['args'];
		$this->assertFalse( $diet['public'] );
		$this->assertFalse( $diet['publicly_queryable'] );
		$this->assertFalse( $diet['rewrite'], 'no archive URL' );
		$this->assertFalse( $diet['query_var'] );
		$this->assertTrue( $diet['show_ui'], 'terms are data: the admin keeps managing them' );
		$this->assertTrue( $GLOBALS['bfrc_test_taxonomies']['bfrc_season']['args']['public'] );

		update_option( 'bfrc_settings', [ 'enabled' => false ] );
		$this->taxonomies()->register();
		foreach ( Settings::SYSTEM_TAXONOMIES as $code ) {
			$this->assertFalse( $GLOBALS['bfrc_test_taxonomies'][ 'bfrc_' . $code ]['args']['public'], "{$code}: plugin disabled" );
		}
	}

	public function test_a_custom_code_at_the_length_limit_still_fits_the_32_character_taxonomy_name(): void {
		$code = str_repeat( 'a', 27 );
		add_filter( 'bfrc_filter_taxonomies', static fn( array $d ): array => $d + [ $code => [ 'label' => 'Long' ] ] );
		$this->taxonomies()->register();
		$this->assertArrayHasKey( 'bfrc_' . $code, $GLOBALS['bfrc_test_taxonomies'], 'register_taxonomy() accepted the 32-character name' );
		$this->assertSame( 32, strlen( 'bfrc_' . $code ) );
	}

	public function test_reserved_route_names_system_codes_array_labels_and_codes_beyond_the_cap_are_refused(): void {
		add_filter(
			'bfrc_filter_taxonomies',
			static function ( array $defs ): array {
				foreach ( [ 'chef', 'submit', 'page', 'feed', 'wp_admin', 'rss2', 'constructor' ] as $route ) {
					$defs[ $route ] = [ 'label' => 'Route' ];
				}
				$defs['arrlabel'] = [ 'label' => [ 'x' ] ];
				$defs['objlabel'] = [ 'label' => new \stdClass() ];
				for ( $i = 1; $i <= Settings::MAX_CUSTOM_TAXONOMIES + 5; $i++ ) {
					$defs[ 'custom_' . $i ] = [ 'label' => 'C' . $i ];
				}
				return $defs;
			}
		);
		$defs = $this->taxonomies()->definitions();
		$this->assertContains( 'constructor', Taxonomies::reserved_codes(), 'the one Object.prototype name the code pattern admits: state.fixed.constructor would always be truthy in the page script' );
		foreach ( [ 'chef', 'submit', 'page', 'feed', 'wp_admin', 'rss2', 'arrlabel', 'objlabel', 'constructor' ] as $code ) {
			$this->assertArrayNotHasKey( $code, $defs, $code );
		}
		$this->assertCount( 4 + Settings::MAX_CUSTOM_TAXONOMIES, $defs, 'four system plus the cap' );
		$this->assertArrayHasKey( 'custom_' . Settings::MAX_CUSTOM_TAXONOMIES, $defs );
		$this->assertArrayNotHasKey( 'custom_' . ( Settings::MAX_CUSTOM_TAXONOMIES + 1 ), $defs );
		$this->assertContains( 'meal_type', Taxonomies::reserved_codes() );
		$this->assertContains( 'wp-admin', Taxonomies::reserved_codes() );
	}

	public function test_reserved_codes_include_the_archives_own_arguments_and_wordpress_public_query_vars(): void {
		$reserved = Taxonomies::reserved_codes();
		foreach ( [ 'per_page', 'paged', 'post_type', 'author_name', 'orderby', 'order', 'search', 'name', 'taxonomy', 'term', 'cpage', 'preview', 'attachment', 'page_id', 'category_name' ] as $code ) {
			$this->assertContains( $code, $reserved, "{$code}: a WordPress public query var or the list's own argument (plan 4.2)" );
		}
		$this->assertSame( Taxonomies::PUBLIC_QUERY_VARS, array_values( array_unique( Taxonomies::PUBLIC_QUERY_VARS ) ), 'no duplicates' );
	}

	public function test_flush_drops_the_cached_definitions_so_a_later_filter_or_setting_counts(): void {
		$t = $this->taxonomies();
		$this->assertCount( 4, $t->definitions() );
		add_filter( 'bfrc_filter_taxonomies', static fn( array $d ): array => $d + [ 'occasion' => [ 'label' => 'Occasions' ] ] );
		$this->assertCount( 4, $t->definitions(), 'cached' );
		$t->flush();
		$this->assertCount( 5, $t->definitions() );
	}

	public function test_before_init_register_adds_no_rewrite_rules_because_core_has_no_rewrite_object_yet(): void {
		$GLOBALS['bfrc_test_fired']['init'] = 0;
		$this->taxonomies()->register(); // the stub throws, as core would, if a rewrite array reached register_taxonomy here
		$this->assertFalse( $GLOBALS['bfrc_test_taxonomies']['bfrc_diet']['args']['rewrite'] );
		$this->assertSame( 'Diets', $GLOBALS['bfrc_test_taxonomies']['bfrc_diet']['args']['labels']['name'], 'English source strings before init: no early text-domain load' );
	}

	public function test_rest_registration_does_not_depend_on_the_switch_or_the_user(): void {
		update_option( 'bfrc_settings', [ 'enabled' => false ] );
		$this->taxonomies()->register();
		$this->assertTrue( $GLOBALS['bfrc_test_taxonomies']['bfrc_diet']['args']['show_in_rest'], 'always registered; reads are gated at dispatch (no current_user_can at init, which would fix the user before other authenticators run)' );
	}

	public function test_register_after_init_replaces_english_labels_cached_before_init(): void {
		$GLOBALS['bfrc_test_fired']['init'] = 0;
		$t = $this->taxonomies();
		$this->assertSame( 'Meal Types', $t->definitions()['meal_type']['label'], 'pre-init: English source' );
		add_filter( 'gettext', static fn( string $tr, string $orig, string $domain ): string => 'bluefairy-recipes' === $domain && 'Meal Types' === $orig ? 'Repas' : $tr, 10, 3 );
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$t->register();
		$this->assertSame( 'Repas', $GLOBALS['bfrc_test_taxonomies']['bfrc_meal_type']['args']['labels']['name'], 'register() after init dropped the pre-init cache' );
	}

	public function test_register_after_init_drops_a_cache_filled_before_a_late_filter(): void {
		$t = $this->taxonomies();
		$this->assertCount( 4, $t->definitions() );
		add_filter( 'bfrc_filter_taxonomies', static fn( array $d ): array => $d + [ 'occasion' => [ 'label' => 'Occasions' ] ] );
		$t->register();
		$this->assertArrayHasKey( 'bfrc_occasion', $GLOBALS['bfrc_test_taxonomies'], 'register() re-reads the definitions after init' );
	}

	public function test_custom_definitions_are_capped_in_filter_order(): void {
		add_filter(
			'bfrc_filter_taxonomies',
			static function ( array $d ): array {
				for ( $i = 1; $i <= Settings::MAX_CUSTOM_TAXONOMIES + 1; $i++ ) {
					$d[ 'c' . $i ] = [ 'label' => 'C' ];
				}
				return $d;
			}
		);
		$defs = $this->taxonomies()->definitions();
		$this->assertArrayHasKey( 'c' . Settings::MAX_CUSTOM_TAXONOMIES, $defs );
		$this->assertArrayNotHasKey( 'c' . ( Settings::MAX_CUSTOM_TAXONOMIES + 1 ), $defs );
	}

	public function test_system_strings_pass_through_translation_after_init(): void {
		add_filter( 'gettext', static fn( string $t, string $orig, string $domain ): string => 'bluefairy-recipes' === $domain && 'Meal Types' === $orig ? 'Repas' : $t, 10, 3 );
		$this->assertSame( 'Repas', $this->taxonomies()->definitions()['meal_type']['label'] );
		$this->assertSame( 'Meal Types', Taxonomies::SYSTEM['meal_type']['label'], 'the constant stays the English source' );
	}

	public function test_taxonomy_name(): void {
		$this->assertSame( 'bfrc_meal_type', Taxonomies::taxonomy_name( 'meal_type' ) );
	}

	public function test_register_hooks_registers_on_init_before_the_post_type(): void {
		$t = $this->taxonomies();
		$t->register_hooks();
		$this->assertSame( 5, has_action( 'init', [ $t, 'register' ] ) );
	}

	public function test_a_user_who_may_only_assign_terms_cannot_create_one_by_typing_its_name(): void {
		$t = $this->taxonomies();
		$t->register_hooks();
		$this->assertSame( 10, has_filter( 'pre_insert_term', [ $t, 'guard_new_term' ] ) );
		\BlueFairy\Recipes\Capabilities::add_roles();
		bfrc_test_add_user( 71, [ 'roles' => [ \BlueFairy\Recipes\Capabilities::ROLE_WRITER ] ] );
		wp_set_current_user( 71 ); // The classic tag box or Quick Edit passes an unknown name on to wp_insert_term().
		$refused = $t->guard_new_term( 'Buy cheap pills', 'bfrc_diet' );
		$this->assertInstanceOf( \WP_Error::class, $refused, 'a Writer holds assign_bfrc_terms only' );
		$this->assertSame( 'bfrc_term_not_allowed', $refused->get_error_code() );
		$this->assertSame( 'Anything', $t->guard_new_term( 'Anything', 'category' ), 'not a recipe taxonomy: untouched' );
		bfrc_test_add_user( 72, [ 'roles' => [ \BlueFairy\Recipes\Capabilities::ROLE_MANAGER ] ] );
		wp_set_current_user( 72 );
		$this->assertSame( 'Vegan', $t->guard_new_term( 'Vegan', 'bfrc_diet' ), 'a Recipe Manager may' );
		wp_set_current_user( 0 );
		$this->assertSame( 'Vegan', $t->guard_new_term( 'Vegan', 'bfrc_diet' ), 'no user (activation from WP-CLI seeding the defaults): allowed' );
	}

	public function test_the_starter_terms_are_seeded_even_for_a_user_whose_new_caps_are_not_loaded_yet(): void {
		$t = $this->taxonomies();
		$t->register_hooks();
		do_action( 'init' );
		bfrc_test_add_user( 71, [ 'roles' => [ 'subscriber' ] ] ); // The activating admin's allcaps predate add_roles() in the same request: core sees no edit_bfrc_terms yet.
		wp_set_current_user( 71 );
		$this->assertFalse( current_user_can( Taxonomies::CAPABILITIES['edit_terms'] ), 'precondition: the guard would refuse this user' );
		$inserted = $t->ensure_default_terms();
		$this->assertGreaterThan( 0, array_sum( $inserted ), 'the plugin\'s own seeding is not a user creating terms (review R-F-1)' );
		$this->assertInstanceOf( \WP_Error::class, $t->guard_new_term( 'Spam', 'bfrc_diet' ), 'outside the seeding the guard still refuses' );
		wp_set_current_user( 0 );
	}

	public function test_ensure_default_terms_seeds_only_empty_system_taxonomies_once(): void {
		$t = $this->taxonomies();
		$t->register();
		wp_insert_term( 'Keto', 'bfrc_diet' );
		$inserted = $t->ensure_default_terms();
		$this->assertSame( [ 'meal_type' => 5, 'season' => 4, 'cuisine' => 5 ], $inserted, 'diet already had a term, so it was left alone' );
		$this->assertSame( [ 'Keto' ], get_terms( [ 'taxonomy' => 'bfrc_diet', 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [ 'Breakfast', 'Dessert', 'Dinner', 'Lunch', 'Snack' ], get_terms( [ 'taxonomy' => 'bfrc_meal_type', 'fields' => 'names', 'hide_empty' => false ] ) );
		$this->assertSame( [], $t->ensure_default_terms(), 'a second run seeds nothing' );
	}

	public function test_ensure_default_terms_skips_custom_types_and_tolerates_an_unregistered_taxonomy(): void {
		add_filter( 'bfrc_filter_taxonomies', static fn( array $d ): array => $d + [ 'occasion' => [ 'label' => 'Occasions' ] ] );
		$t = $this->taxonomies();
		$this->assertSame( [], $t->ensure_default_terms(), 'nothing registered yet: wp_count_terms() errors are skipped, not fatal' );
		$t->register();
		$this->assertArrayNotHasKey( 'occasion', $t->ensure_default_terms() );
	}
}
