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
			$this->assertTrue( $args['revisions_enabled'], "{$key}: restoring a revision restores the field" );
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

	public function test_register_hooks_registers_on_init_after_the_post_type(): void {
		$m = new Meta();
		$m->register_hooks();
		$this->assertSame( 7, has_action( 'init', [ $m, 'register' ] ) );
	}

	public function test_can_edit_asks_for_edit_post_on_that_recipe_for_the_user_core_names(): void {
		$this->assertFalse( Meta::can_edit( true, Meta::STEPS, 41 ), 'core\'s default is ignored; the user must hold edit_post' );
		bfrc_test_grant( 'edit_post' );
		$this->assertTrue( Meta::can_edit( false, Meta::STEPS, 41 ), 'user 0 = the current user' );
		$GLOBALS['bfrc_test_roles']['tester'] = new \WP_Role( 'tester', [ 'edit_post' => true ] );
		bfrc_test_add_user( 9, [ 'roles' => [ 'tester' ] ] );
		bfrc_test_add_user( 10, [ 'roles' => [ 'subscriber' ] ] );
		$this->assertTrue( Meta::can_edit( false, Meta::STEPS, 41, 9 ) );
		$this->assertFalse( Meta::can_edit( true, Meta::STEPS, 41, 10 ), 'the named user, not the current one' );
	}

	public function test_servings_max_follows_min_up_and_min_follows_max_down_on_any_write_path(): void {
		( new Meta() )->register_hooks();
		bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
		bfrc_test_add_post( [ 'ID' => 42, 'post_type' => 'post' ] );
		update_post_meta( 41, Meta::SERVINGS_MIN, 2 );
		update_post_meta( 41, Meta::SERVINGS_MAX, 4 );
		update_post_meta( 41, Meta::SERVINGS_MIN, 6 );
		$this->assertSame( 6, get_post_meta( 41, Meta::SERVINGS_MAX, true ), 'max follows min up' );
		update_post_meta( 41, Meta::SERVINGS_MAX, 3 );
		$this->assertSame( 3, get_post_meta( 41, Meta::SERVINGS_MIN, true ), 'min follows max down' );
		update_post_meta( 41, Meta::SERVINGS_MAX, 0 );
		$this->assertSame( 3, get_post_meta( 41, Meta::SERVINGS_MIN, true ), 'max 0 means unset and changes nothing' );
		update_post_meta( 42, Meta::SERVINGS_MIN, 9 );
		update_post_meta( 42, Meta::SERVINGS_MAX, 1 );
		$this->assertSame( 9, get_post_meta( 42, Meta::SERVINGS_MIN, true ), 'other post types are not touched' );
		Meta::reconcile_servings( 1, 42, Meta::SERVINGS_MAX, 1 );
		$this->assertSame( 9, get_post_meta( 42, Meta::SERVINGS_MIN, true ), 'called directly for a non-recipe: still nothing' );
		Meta::reconcile_servings( 1, 41, Meta::FEATURED, true );
		$this->assertSame( 3, get_post_meta( 41, Meta::SERVINGS_MIN, true ), 'another key: nothing' );
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
				[ 'measure' => '', 'label' => 'salt', 'type' => 'plain' ],
				[ 'measure' => str_repeat( 'm', 64 ), 'label' => str_repeat( 'x', 255 ), 'type' => 'plain' ],
			],
			$rows,
			'unlabelled, non-array and array-labelled rows dropped; an unknown type (no add-on) becomes plain and its extra field is not kept'
		);
		$this->assertSame( [], Meta::sanitize_ingredients( 'nope' ) );
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
		$this->assertNull( Meta::sanitize_nutrition( [ 'sodium' => '1,200' ] )['sodium'], 'a comma followed by exactly three digits is ambiguous (thousands or decimal) and is refused rather than stored as 1.2' );
		$this->assertSame( 1.2, Meta::sanitize_nutrition( [ 'sodium' => '1.200' ] )['sodium'], 'a dot is always decimal' );
		$this->assertSame( 12.5, Meta::sanitize_nutrition( [ 'sodium' => '12,5' ] )['sodium'] );
		$this->assertNull( Meta::sanitize_nutrition( [ 'sodium' => '1,2,3' ] )['sodium'] );
		$this->assertNull( Meta::sanitize_nutrition( [ 'calories' => 200000 ] )['calories'], 'above the ceiling' );
		$this->assertSame( 0.0, Meta::sanitize_nutrition( [ 'calories' => '-0' ] )['calories'], 'never -0.0' );
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
