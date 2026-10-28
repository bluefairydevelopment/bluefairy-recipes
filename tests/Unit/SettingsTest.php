<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_are_returned_when_option_is_absent(): void {
		$settings = new Settings();
		$this->assertSame( Settings::DEFAULTS, $settings->all() );
		$this->assertSame( 12, $settings->get( 'per_page' ) );
		$this->assertSame( 'recipes', $settings->get( 'archive_slug' ) );
	}

	public function test_stored_values_are_merged_over_defaults_and_normalised_on_read(): void {
		update_option( 'bfrc_settings', [ 'per_page' => '24', 'grant_core_roles' => 'false', 'taxonomies' => [ 'diet' => [ 'enabled' => 0 ] ] ] );
		$settings = new Settings();
		$this->assertSame( 24, $settings->get( 'per_page' ), 'a raw string is read as the int it should be' );
		$this->assertFalse( $settings->get( 'grant_core_roles' ), '"false" written around the class reads as false, not as a truthy string' );
		$this->assertSame( 'Recipes', $settings->get( 'archive_title' ) );
		$this->assertSame( [ 'meal_type', 'diet', 'season', 'cuisine' ], array_keys( $settings->get( 'taxonomies' ) ), 'a stored map missing system codes still has them' );
		$this->assertFalse( $settings->get( 'taxonomies' )['diet']['enabled'] );
	}

	public function test_corrupt_option_falls_back_to_defaults(): void {
		update_option( 'bfrc_settings', 'not an array' );
		$this->assertSame( Settings::DEFAULTS, ( new Settings() )->all() );
	}

	public function test_get_unknown_key_returns_null(): void {
		$this->assertNull( ( new Settings() )->get( 'nope' ) );
	}

	public function test_update_has_form_semantics_sanitises_stores_and_refreshes_the_cache(): void {
		$settings = new Settings();
		$stored   = $settings->update(
			[
				'per_page'   => '999',
				'enabled'    => 'on',
				'bogus'      => 1,
				'taxonomies' => [ 'diet' => [ 'enabled' => '0' ] ],
			]
		);
		$this->assertSame( 100, $stored['per_page'] );
		$this->assertTrue( $stored['enabled'] );
		$this->assertFalse( $stored['grant_core_roles'], 'form semantics: an absent checkbox is unticked' );
		$this->assertArrayNotHasKey( 'bogus', $stored );
		$this->assertSame( $stored, get_option( 'bfrc_settings' ) );
		$this->assertSame( 100, $settings->get( 'per_page' ), 'per-request cache refreshed after update' );
		$this->assertFalse( $settings->get( 'taxonomies' )['diet']['enabled'] );
		$this->assertTrue( $settings->last_update_succeeded() );
	}

	public function test_patch_changes_only_the_given_keys_and_keeps_pro_taxonomy_codes(): void {
		$settings = new Settings();
		$settings->update( array_merge( Settings::DEFAULTS, [ 'taxonomies' => Settings::DEFAULTS['taxonomies'] + [ 'occasion' => [ 'enabled' => true, 'sort_order' => 50 ] ] ] ) );
		$stored = $settings->patch( [ 'per_page' => 5 ] );
		$this->assertSame( 5, $stored['per_page'] );
		$this->assertTrue( $stored['enabled'], 'merge semantics: an absent bool keeps its stored value' );
		$this->assertTrue( $stored['grant_core_roles'] );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 50 ], $stored['taxonomies']['occasion'], 'the add-on\'s filter type survives a one-key change' );
		$this->assertSame( $stored, get_option( 'bfrc_settings' ) );
		$this->assertSame( 5, $settings->get( 'per_page' ) );
	}

	public function test_remove_taxonomy_switch_drops_one_code_and_keeps_everything_else(): void {
		$settings = new Settings();
		$settings->patch( [ 'archive_title' => 'Kept', 'taxonomies' => [ 'occasion' => [ 'enabled' => true, 'sort_order' => 7 ], 'course' => [ 'enabled' => false, 'sort_order' => 3 ] ] ] );
		update_option( Settings::FLUSH_OPTION, 0 );
		$this->assertTrue( $settings->remove_taxonomy_switch( 'occasion' ) );
		$taxonomies = $settings->get( 'taxonomies' );
		$this->assertArrayNotHasKey( 'occasion', $taxonomies );
		$this->assertSame( [ 'enabled' => false, 'sort_order' => 3 ], $taxonomies['course'], 'another add-on code is kept' );
		$this->assertSame( 'Kept', $settings->get( 'archive_title' ), 'every other setting is kept' );
		$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ), 'its archive rule goes: flush flagged' );
		$this->assertTrue( $settings->remove_taxonomy_switch( 'occasion' ), 'already gone' );
		$this->assertFalse( $settings->remove_taxonomy_switch( 'meal_type' ), 'a system code is never removed' );
		$this->assertArrayHasKey( 'meal_type', $settings->get( 'taxonomies' ) );
		$GLOBALS['bfrc_test_option_write_fails'][] = Settings::OPTION;
		$this->assertFalse( $settings->remove_taxonomy_switch( 'course' ), 'a refused write says so' );
		$GLOBALS['bfrc_test_option_write_fails'] = [];
		$this->assertSame( 999, Settings::SORT_ORDER_MAX );
	}

	public function test_patch_merges_one_taxonomy_entry_without_touching_the_others(): void {
		$settings = new Settings();
		$settings->update( array_merge( Settings::DEFAULTS, [ 'taxonomies' => array_merge( Settings::DEFAULTS['taxonomies'], [ 'meal_type' => [ 'enabled' => false, 'sort_order' => 5 ], 'diet' => [ 'enabled' => true, 'sort_order' => 7 ], 'occasion' => [ 'enabled' => true, 'sort_order' => 50 ] ] ) ] ) );
		$stored = $settings->patch( [ 'taxonomies' => [ 'diet' => [ 'enabled' => false ] ] ] );
		$this->assertSame( [ 'enabled' => false, 'sort_order' => 7 ], $stored['taxonomies']['diet'], 'the patched switch changed; its non-default sort order kept (a shallow merge would refill the default 20)' );
		$this->assertSame( [ 'enabled' => false, 'sort_order' => 5 ], $stored['taxonomies']['meal_type'], 'a sibling system code keeps its stored values' );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 50 ], $stored['taxonomies']['occasion'], 'the add-on\'s code survives a one-entry patch' );
	}

	public function test_a_refused_write_is_reported_and_implies_nothing(): void {
		$GLOBALS['bfrc_test_option_write_fails'][] = 'bfrc_settings';
		$settings = new Settings();
		$settings->update( [ 'per_page' => 5, 'archive_slug' => 'cookbook' ] );
		$this->assertFalse( $settings->last_update_succeeded() );
		$this->assertFalse( get_option( Settings::FLUSH_OPTION ), 'no flush flagged for a write that did not happen' );
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_bfrc_recipes' ), 'no grant change for a write that did not happen' );
	}

	public function test_an_unchanged_value_counts_as_stored(): void {
		$settings = new Settings();
		$clean    = $settings->update( Settings::DEFAULTS );
		$this->assertTrue( $settings->last_update_succeeded() );
		$again = $settings->update( $clean );
		$this->assertSame( $clean, $again );
		$this->assertTrue( $settings->last_update_succeeded(), 'update_option() returns false for an unchanged value; that is not a failure' );
	}

	public function test_changing_the_archive_slug_flags_a_rewrite_flush_and_keeping_it_does_not(): void {
		$settings = new Settings();
		$settings->patch( [ 'archive_slug' => 'recipes' ] );
		$this->assertFalse( get_option( Settings::FLUSH_OPTION ), 'same slug: no flush' );
		$settings->patch( [ 'archive_slug' => 'cookbook' ] );
		$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ), 'changed slug: flush flagged' );
		update_option( Settings::FLUSH_OPTION, 0 );
		$settings->patch( [ 'enabled' => false ] );
		$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ), 'the Enabled switch adds or removes every rule' );
		update_option( Settings::FLUSH_OPTION, 0 );
		$settings->patch( [ 'taxonomies' => [ 'diet' => [ 'enabled' => false ] ] ] );
		$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ), 'a taxonomy switch adds or removes its archive rule' );
		update_option( Settings::FLUSH_OPTION, 0 );
		$settings->patch( [ 'per_page' => 7 ] );
		$this->assertSame( '0', get_option( Settings::FLUSH_OPTION ), 'other settings do not touch the rules' );
	}

	public function test_flipping_grant_core_roles_grants_and_revokes_the_core_roles_capabilities(): void {
		Capabilities::add_roles();
		$settings = new Settings();
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_bfrc_recipes' ) );
		$settings->patch( [ 'grant_core_roles' => true ] );
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_bfrc_recipes' ), 'default is already true: no change, no grant (activation grants)' );
		$settings->patch( [ 'grant_core_roles' => false ] );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_others_bfrc_recipes' ) );
		Capabilities::grant_core_roles();
		$settings->patch( [ 'grant_core_roles' => false ] );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'edit_others_bfrc_recipes' ), 'false → false: nothing revoked' );
		$settings->patch( [ 'grant_core_roles' => true ] );
		$settings->patch( [ 'grant_core_roles' => false ] );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_others_bfrc_recipes' ), 'true → false revokes' );
		$this->assertFalse( get_role( 'author' )->has_cap( 'publish_bfrc_recipes' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'MANAGE on administrators is not part of the grant' );
		$settings->patch( [ 'grant_core_roles' => true ] );
		$this->assertTrue( get_role( 'author' )->has_cap( 'publish_bfrc_recipes' ), 'false → true grants' );
	}

	public function test_sanitize_clamps_per_page_at_both_bounds_and_a_non_number_takes_the_default(): void {
		$s = new Settings();
		$this->assertSame( 1, $s->sanitize( [ 'per_page' => 0 ] )['per_page'] );
		$this->assertSame( 1, $s->sanitize( [ 'per_page' => -7 ] )['per_page'] );
		$this->assertSame( 100, $s->sanitize( [ 'per_page' => 101 ] )['per_page'] );
		$this->assertSame( 100, $s->sanitize( [ 'per_page' => 100 ] )['per_page'], 'the bound itself is allowed' );
		$this->assertSame( 37, $s->sanitize( [ 'per_page' => '37' ] )['per_page'] );
		$this->assertSame( 12, $s->sanitize( [ 'per_page' => 12.9 ] )['per_page'] );
		foreach ( [ '', 'abc', null, true, [ 3 ], '1e1000', INF, NAN ] as $garbage ) {
			$this->assertSame( 12, $s->sanitize( [ 'per_page' => $garbage ] )['per_page'], var_export( $garbage, true ) . ' is not a number: the default, not 1' );
		}
	}

	public function test_missing_checkbox_means_false_but_missing_field_keeps_default(): void {
		$clean = ( new Settings() )->sanitize( [] );
		$this->assertFalse( $clean['enabled'], 'an absent checkbox is unticked' );
		$this->assertFalse( $clean['grant_core_roles'] );
		$this->assertSame( 12, $clean['per_page'] );
		$this->assertSame( 'recipes', $clean['archive_slug'] );
		$this->assertSame( Settings::DEFAULTS['taxonomies'], $clean['taxonomies'] );
	}

	public function test_to_bool_accepts_checkbox_values_only(): void {
		foreach ( [ true, 1, '1', 'on', 'true', 'yes', ' ON ' ] as $yes ) {
			$this->assertTrue( Settings::to_bool( $yes ), var_export( $yes, true ) );
		}
		foreach ( [ false, 0, '0', 'off', 'false', 'no', '', null, [], 2.5 ] as $no ) {
			$this->assertFalse( Settings::to_bool( $no ), var_export( $no, true ) );
		}
		$this->assertTrue( Settings::to_bool( 2 ), 'any non-zero int is true, as (bool) casts' );
	}

	public function test_slug_clash_names_a_top_level_page_at_the_address_and_nothing_else(): void {
		$this->assertNull( Settings::slug_clash( '' ) );
		$this->assertNull( Settings::slug_clash( 'recipes' ), 'no Page' );
		bfrc_test_add_post( [ 'ID' => 80, 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'recipes', 'post_parent' => 0 ] );
		$this->assertNull( Settings::slug_clash( 'recipes' ), 'a blog post lives under its own permalink structure, not at /recipes/' );
		bfrc_test_add_post( [ 'ID' => 81, 'post_type' => 'page', 'post_status' => 'private', 'post_name' => 'recipes', 'post_parent' => 0 ] );
		$this->assertSame( 81, Settings::slug_clash( 'recipes' )?->ID, 'any status but trash holds the address' );
	}

	public function test_sanitize_slug_normalises_and_never_returns_empty(): void {
		$this->assertSame( 'our-recipes', Settings::sanitize_slug( ' Our Recipes! ' ) );
		$this->assertSame( 'my_recipes', Settings::sanitize_slug( 'my_recipes' ), 'core keeps underscores; so do we' );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '' ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '!!!' ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( 42 ) );
		$this->assertSame( str_repeat( 'a', 64 ), Settings::sanitize_slug( str_repeat( 'a', 70 ) ), 'cut to 64' );
		$this->assertSame( str_repeat( 'a', 63 ), Settings::sanitize_slug( str_repeat( 'a', 63 ) . '-b' ), 'a dash left dangling by the cut is trimmed' );
	}

	public function test_sanitize_slug_terminates_and_refuses_a_typed_invalid_octet_before_a_multibyte_tail(): void {
		// Reproduced against real core in review: the previous cure looped for ever on this input.
		$started = microtime( true );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '%ff' . 'aaaaa' . str_repeat( '日', 10 ) ), 'a slug that cannot decode to UTF-8 is refused, not stored' );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '%ffab' . str_repeat( '世', 20 ) ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( str_repeat( 'a', 58 ) . '%e6%97' ), 'full octets that do not decode to a character are refused' );
		$this->assertLessThan( 1.0, microtime( true ) - $started, 'finite' );
	}

	public function test_sanitize_slug_refuses_a_lone_or_dangling_percent(): void {
		// Real core's sanitize_title() already drops a lone %, so this branch is defence in depth against a filtering plugin; the stub keeps the % to reach it.
		foreach ( [ '%', '%%%', '50%', 'a%zz', str_repeat( '%', 200 ) ] as $bad ) {
			$this->assertSame( 'recipes', Settings::sanitize_slug( $bad ), var_export( $bad, true ) );
		}
		$this->assertSame( '%e4%b8%ad', Settings::sanitize_slug( '%e4%b8%ad' ), 'a typed full octet sequence that decodes is kept' );
	}

	public function test_sanitize_slug_terminates_when_sanitize_title_keeps_raw_utf8(): void {
		$GLOBALS['bfrc_test_sanitize_title_raw'] = true; // a plugin's filter (the Cyrillic/unicode slug family) returns raw multibyte text
		$started = microtime( true );
		$slug    = Settings::sanitize_slug( 'a' . str_repeat( 'é', 60 ) );
		$this->assertLessThanOrEqual( 64, strlen( $slug ) );
		$this->assertTrue( mb_check_encoding( $slug, 'UTF-8' ), 'never cut inside a multibyte character: ' . $slug );
		$this->assertSame( 'a' . str_repeat( 'é', 31 ), $slug, '1 + 31 × 2 bytes = 63 bytes; a 32nd é would be 65' );
		$this->assertLessThan( 1.0, microtime( true ) - $started, 'finite' );
	}

	public function test_sanitize_slug_refuses_a_reserved_route_typed_as_octets(): void {
		$this->assertSame( 'recipes', Settings::sanitize_slug( '%66eed' ), 'decodes to feed' );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '%77p-admin' ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '%46eed' ), 'upper-case hex decodes to Feed: compared lower-cased' );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '%57p-admin' ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '%32%30%32%34' ), 'a year typed as octets' );
	}

	public function test_sanitize_slug_refuses_octets_that_decode_to_characters_a_slug_cannot_hold(): void {
		foreach ( [ 'a%2fb', '%2e%2e', '%00x', 'a%0ab', 'a%20b', 'a%3cb', 'a%5cb' ] as $bad ) {
			$this->assertSame( 'recipes', Settings::sanitize_slug( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_sanitize_slug_cuts_percent_encoded_slugs_on_a_whole_character(): void {
		$slug = Settings::sanitize_slug( str_repeat( 'ñ', 40 ) ); // each ñ encodes to %c3%b1 (6 bytes)
		$this->assertLessThanOrEqual( 64, strlen( $slug ) );
		$this->assertSame( 0, strlen( $slug ) % 6, 'whole characters only: ' . $slug );
		$this->assertSame( 60, strlen( $slug ), '10 whole characters fit in 64 bytes' );
		$this->assertTrue( mb_check_encoding( rawurldecode( $slug ), 'UTF-8' ) );
		$this->assertStringEndsNotWith( '%', $slug );
		$this->assertSame( '%e4%b8%ad%e6%96%87', Settings::sanitize_slug( '中文' ), 'short non-ASCII slugs are kept whole' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function reserved_slugs(): array {
		$cases = [];
		foreach ( Settings::RESERVED_SLUGS as $slug ) {
			$cases[ $slug ] = [ $slug ];
		}
		$cases['a year'] = [ '2024' ];
		return $cases;
	}

	/**
	 * @dataProvider reserved_slugs
	 */
	public function test_sanitize_slug_refuses_routes_wordpress_owns( string $slug ): void {
		$this->assertSame( 'recipes', Settings::sanitize_slug( $slug ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( strtoupper( $slug ) ) );
	}

	public function test_sanitize_slug_allows_names_near_the_reserved_ones(): void {
		$this->assertSame( 'feeds', Settings::sanitize_slug( 'feeds' ) );
		$this->assertSame( 'our-feed', Settings::sanitize_slug( 'our feed' ) );
		$this->assertSame( '20245', Settings::sanitize_slug( '20245' ) );
	}

	public function test_archive_title_is_text_bounded_and_never_empty(): void {
		$s = new Settings();
		$this->assertSame( 'Our Recipes', $s->sanitize( [ 'archive_title' => " Our <b>Recipes</b>\n" ] )['archive_title'] );
		$this->assertSame( 'Recipes', $s->sanitize( [ 'archive_title' => '' ] )['archive_title'] );
		$this->assertSame( 'Recipes', $s->sanitize( [ 'archive_title' => [ 'x' ] ] )['archive_title'] );
		$this->assertSame( 120, mb_strlen( $s->sanitize( [ 'archive_title' => str_repeat( 'é', 130 ) ] )['archive_title'] ) );
	}

	public function test_sanitize_taxonomies_keeps_system_codes_valid_custom_codes_and_drops_the_rest(): void {
		$clean = Settings::sanitize_taxonomies(
			[
				'diet'                => [
					'enabled'    => 'off',
					'sort_order' => '5000',
				],
				'occasion'            => [
					'enabled'    => '1',
					'sort_order' => '-3',
				],
				'holiday'             => 'not an array',
				'Bad Code'            => [ 'enabled' => true ],
				'a'                   => [ 'enabled' => true ],
				str_repeat( 'x', 28 ) => [ 'enabled' => true ],
				"newline\n"           => [ 'enabled' => true ],
				7                     => [ 'enabled' => true ],
				'texture'             => [ 'sort_order' => 'abc' ],
				'chef'                => [ 'enabled' => true ],
				'page'                => [ 'enabled' => true ],
			]
		);
		$this->assertSame( [ 'meal_type', 'diet', 'season', 'cuisine', 'occasion', 'holiday', 'texture' ], array_keys( $clean ), 'system codes first, then the valid custom ones in input order; route names (chef, page) refused as Taxonomies refuses them' );
		$this->assertSame( [ 'enabled' => false, 'sort_order' => 999 ], $clean['diet'] );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 10 ], $clean['meal_type'], 'a missing system code takes its default' );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 0 ], $clean['occasion'] );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 100 ], $clean['holiday'], 'a non-array entry for a valid code takes the custom defaults' );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 100 ], $clean['texture'], 'a non-numeric sort order takes the default, not 0' );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 10 ], Settings::sanitize_taxonomies( 'garbage' )['meal_type'] );
	}

	public function test_sanitize_taxonomies_caps_the_custom_codes(): void {
		$raw = [];
		for ( $i = 1; $i <= Settings::MAX_CUSTOM_TAXONOMIES + 5; $i++ ) {
			$raw[ 'custom_' . $i ] = [ 'enabled' => true ];
		}
		$clean = Settings::sanitize_taxonomies( $raw );
		$this->assertCount( 4 + Settings::MAX_CUSTOM_TAXONOMIES, $clean );
		$this->assertArrayHasKey( 'custom_' . Settings::MAX_CUSTOM_TAXONOMIES, $clean );
		$this->assertArrayNotHasKey( 'custom_' . ( Settings::MAX_CUSTOM_TAXONOMIES + 1 ), $clean );
	}

	public function test_taxonomy_code_pattern_bounds(): void {
		$this->assertSame( 1, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'ab' ), '2 characters: the shortest allowed' );
		$this->assertSame( 1, preg_match( Settings::TAXONOMY_CODE_PATTERN, str_repeat( 'a', 27 ) ), '27 characters: bfrc_ + 27 = 32' );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'a' ) );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, str_repeat( 'a', 28 ) ) );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, '1abc' ), 'must start with a letter' );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'Abc' ), 'lower case only' );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'a-b' ), 'no dashes' );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, "ab\n" ), 'a trailing newline is not a match (\z, not $)' );
	}

	public function test_system_taxonomies_match_the_defaults_map(): void {
		$this->assertSame( Settings::SYSTEM_TAXONOMIES, array_keys( Settings::DEFAULTS['taxonomies'] ) );
	}
}
