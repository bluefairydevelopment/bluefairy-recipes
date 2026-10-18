<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_are_returned_when_option_is_absent(): void {
		$settings = new Settings();
		$this->assertSame( Settings::DEFAULTS, $settings->all() );
		$this->assertSame( 12, $settings->get( 'per_page' ) );
		$this->assertSame( 'recipes', $settings->get( 'archive_slug' ) );
	}

	public function test_stored_values_are_merged_over_defaults(): void {
		update_option( 'bfrc_settings', [ 'per_page' => 24 ] );
		$settings = new Settings();
		$this->assertSame( 24, $settings->get( 'per_page' ) );
		$this->assertSame( 'Recipes', $settings->get( 'archive_title' ) );
	}

	public function test_corrupt_option_falls_back_to_defaults(): void {
		update_option( 'bfrc_settings', 'not an array' );
		$this->assertSame( Settings::DEFAULTS, ( new Settings() )->all() );
	}

	public function test_get_unknown_key_returns_null(): void {
		$this->assertNull( ( new Settings() )->get( 'nope' ) );
	}

	public function test_update_sanitises_stores_and_refreshes_the_cache(): void {
		$settings = new Settings();
		$stored   = $settings->update(
			[
				'per_page'  => '999',
				'enabled'   => 'on',
				'bogus'     => 1,
				'taxonomies' => [ 'diet' => [ 'enabled' => '0' ] ],
			]
		);
		$this->assertSame( 100, $stored['per_page'] );
		$this->assertTrue( $stored['enabled'] );
		$this->assertArrayNotHasKey( 'bogus', $stored );
		$this->assertSame( $stored, get_option( 'bfrc_settings' ) );
		$this->assertSame( 100, $settings->get( 'per_page' ), 'per-request cache refreshed after update' );
		$this->assertFalse( $settings->get( 'taxonomies' )['diet']['enabled'] );
		$this->assertTrue( $settings->last_update_succeeded() );
	}

	public function test_a_refused_write_is_reported(): void {
		$GLOBALS['bfrc_test_option_write_fails'][] = 'bfrc_settings';
		$settings = new Settings();
		$settings->update( [ 'per_page' => 5 ] );
		$this->assertFalse( $settings->last_update_succeeded() );
		$this->assertFalse( get_option( Settings::FLUSH_OPTION ), 'no flush flagged for a write that did not happen' );
	}

	public function test_an_unchanged_value_counts_as_stored(): void {
		$settings = new Settings();
		$clean    = $settings->update( [ 'per_page' => 12 ] );
		$this->assertTrue( $settings->last_update_succeeded() );
		$again = $settings->update( $clean );
		$this->assertSame( $clean, $again );
		$this->assertTrue( $settings->last_update_succeeded(), 'update_option() returns false for an unchanged value; that is not a failure' );
	}

	public function test_changing_the_archive_slug_flags_a_rewrite_flush_and_keeping_it_does_not(): void {
		$settings = new Settings();
		$settings->update( [ 'archive_slug' => 'recipes' ] );
		$this->assertFalse( get_option( Settings::FLUSH_OPTION ), 'same slug: no flush' );
		$settings->update( [ 'archive_slug' => 'cookbook' ] );
		$this->assertSame( '1', get_option( Settings::FLUSH_OPTION ), 'changed slug: flush flagged' );
	}

	public function test_sanitize_clamps_per_page_at_both_bounds(): void {
		$s = new Settings();
		$this->assertSame( 1, $s->sanitize( [ 'per_page' => 0 ] )['per_page'] );
		$this->assertSame( 1, $s->sanitize( [ 'per_page' => -7 ] )['per_page'] );
		$this->assertSame( 100, $s->sanitize( [ 'per_page' => 101 ] )['per_page'] );
		$this->assertSame( 100, $s->sanitize( [ 'per_page' => 100 ] )['per_page'], 'the bound itself is allowed' );
		$this->assertSame( 37, $s->sanitize( [ 'per_page' => '37' ] )['per_page'] );
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

	public function test_sanitize_slug_normalises_and_never_returns_empty(): void {
		$this->assertSame( 'our-recipes', Settings::sanitize_slug( ' Our Recipes! ' ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '' ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( '!!!' ) );
		$this->assertSame( 'recipes', Settings::sanitize_slug( 42 ) );
		$this->assertSame( 64, strlen( Settings::sanitize_slug( str_repeat( 'a', 70 ) ) ), 'cut to 64' );
		$this->assertSame( 'abc', Settings::sanitize_slug( 'abc-' . str_repeat( '-', 70 ) ), 'a trailing dash left by the cut is trimmed' );
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
				'diet'      => [
					'enabled'    => 'off',
					'sort_order' => '5000',
				],
				'occasion'  => [
					'enabled'    => '1',
					'sort_order' => '-3',
				],
				'holiday'   => 'not an array',
				'Bad Code'  => [ 'enabled' => true ],
				'a'         => [ 'enabled' => true ],
				str_repeat( 'x', 28 ) => [ 'enabled' => true ],
				7           => [ 'enabled' => true ],
			]
		);
		$this->assertSame( [ 'meal_type', 'diet', 'season', 'cuisine', 'occasion', 'holiday' ], array_keys( $clean ), 'system codes first, then the valid custom ones in input order' );
		$this->assertSame( [ 'enabled' => false, 'sort_order' => 999 ], $clean['diet'] );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 10 ], $clean['meal_type'], 'a missing system code takes its default' );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 0 ], $clean['occasion'] );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 100 ], $clean['holiday'], 'a non-array entry for a valid code takes the custom defaults' );
		$this->assertSame( [], Settings::sanitize_taxonomies( 'garbage' )['meal_type'] === [ 'enabled' => true, 'sort_order' => 10 ] ? [] : [ 'wrong' ] );
	}

	public function test_taxonomy_code_pattern_bounds(): void {
		$this->assertSame( 1, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'ab' ), '2 characters: the shortest allowed' );
		$this->assertSame( 1, preg_match( Settings::TAXONOMY_CODE_PATTERN, str_repeat( 'a', 27 ) ), '27 characters: bfrc_ + 27 = 32' );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'a' ) );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, str_repeat( 'a', 28 ) ) );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, '1abc' ), 'must start with a letter' );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'Abc' ), 'lower case only' );
		$this->assertSame( 0, preg_match( Settings::TAXONOMY_CODE_PATTERN, 'a-b' ), 'no dashes' );
	}

	public function test_system_taxonomies_match_the_defaults_map(): void {
		$this->assertSame( Settings::SYSTEM_TAXONOMIES, array_keys( Settings::DEFAULTS['taxonomies'] ) );
	}
}
