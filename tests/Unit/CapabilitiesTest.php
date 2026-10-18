<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Tests\TestCase;

final class CapabilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'editor', 'author', 'subscriber' ] as $role ) {
			$GLOBALS['bfrc_test_roles'][ $role ] = new \WP_Role( $role, [ 'read' => true ] );
		}
	}

	public function test_all_caps_is_the_writer_set_plus_the_manager_extras_without_duplicates(): void {
		$all = Capabilities::all_caps();
		$this->assertSame( array_values( array_unique( $all ) ), $all );
		$this->assertCount( count( Capabilities::WRITER_CAPS ) + count( Capabilities::MANAGER_EXTRA_CAPS ), $all );
		$this->assertContains( Capabilities::MANAGE, $all );
		$this->assertContains( 'edit_others_bfrc_recipes', $all );
	}

	public function test_caps_for_each_role(): void {
		$this->assertSame( Capabilities::WRITER_CAPS, Capabilities::caps_for( Capabilities::ROLE_WRITER ) );
		$this->assertSame( Capabilities::all_caps(), Capabilities::caps_for( Capabilities::ROLE_MANAGER ) );
		$this->assertSame( [], Capabilities::caps_for( 'administrator' ) );
		$this->assertNotContains( 'edit_others_bfrc_recipes', Capabilities::WRITER_CAPS, 'a writer edits only their own recipes' );
		$this->assertNotContains( Capabilities::MANAGE, Capabilities::WRITER_CAPS );
	}

	public function test_core_grants_give_authors_the_writer_set_editors_everything_but_manage_and_admins_everything(): void {
		$grants = Capabilities::core_grants();
		$this->assertSame( [ 'author', 'editor', 'administrator' ], array_keys( $grants ) );
		$this->assertNotContains( 'read', $grants['author'], 'core caps the role already has are not ours' );
		$this->assertNotContains( 'upload_files', $grants['author'] );
		$this->assertContains( 'publish_bfrc_recipes', $grants['author'] );
		$this->assertNotContains( 'edit_others_bfrc_recipes', $grants['author'] );
		$this->assertContains( 'edit_others_bfrc_recipes', $grants['editor'] );
		$this->assertNotContains( Capabilities::MANAGE, $grants['editor'] );
		$this->assertContains( Capabilities::MANAGE, $grants['administrator'] );
	}

	public function test_add_roles_creates_both_roles_with_their_caps_and_gives_administrators_manage(): void {
		Capabilities::add_roles();
		$writer  = get_role( Capabilities::ROLE_WRITER );
		$manager = get_role( Capabilities::ROLE_MANAGER );
		$this->assertInstanceOf( \WP_Role::class, $writer );
		$this->assertInstanceOf( \WP_Role::class, $manager );
		$this->assertSame( 'Recipe Writer', $GLOBALS['bfrc_test_role_names'][ Capabilities::ROLE_WRITER ] );
		$this->assertSame( 'Recipe Manager', $GLOBALS['bfrc_test_role_names'][ Capabilities::ROLE_MANAGER ] );
		foreach ( Capabilities::WRITER_CAPS as $cap ) {
			$this->assertTrue( $writer->has_cap( $cap ), "writer: {$cap}" );
		}
		$this->assertFalse( $writer->has_cap( 'edit_others_bfrc_recipes' ) );
		foreach ( Capabilities::all_caps() as $cap ) {
			$this->assertTrue( $manager->has_cap( $cap ), "manager: {$cap}" );
		}
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_bfrc_recipes' ), 'add_roles() alone grants nothing to core roles' );
	}

	public function test_add_roles_tops_up_an_existing_role_that_lacks_a_cap(): void {
		$GLOBALS['bfrc_test_roles'][ Capabilities::ROLE_WRITER ] = new \WP_Role( Capabilities::ROLE_WRITER, [ 'read' => true ] );
		Capabilities::add_roles();
		$this->assertTrue( get_role( Capabilities::ROLE_WRITER )->has_cap( 'publish_bfrc_recipes' ), 'a role saved by an older version is completed, not replaced' );
	}

	public function test_remove_roles_moves_sole_role_users_to_subscriber_keeps_other_roles_and_removes_the_roles(): void {
		Capabilities::add_roles();
		bfrc_test_add_user( 5, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		bfrc_test_add_user( 6, [ 'roles' => [ 'editor', Capabilities::ROLE_WRITER ] ] );
		bfrc_test_add_user( 7, [ 'roles' => [ Capabilities::ROLE_MANAGER ] ] );
		bfrc_test_add_user( 8, [ 'roles' => [ 'author' ] ] );

		Capabilities::remove_roles();

		$this->assertSame( [ 'subscriber' ], ( new \WP_User( 5 ) )->roles, 'only role was writer: falls back to subscriber' );
		$this->assertSame( [ 'editor' ], ( new \WP_User( 6 ) )->roles, 'the other role stays, no subscriber added' );
		$this->assertSame( [ 'subscriber' ], ( new \WP_User( 7 ) )->roles );
		$this->assertSame( [ 'author' ], ( new \WP_User( 8 ) )->roles, 'untouched' );
		$this->assertNull( get_role( Capabilities::ROLE_WRITER ) );
		$this->assertNull( get_role( Capabilities::ROLE_MANAGER ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_remove_roles_pages_through_a_large_role_in_bounded_reads(): void {
		Capabilities::add_roles();
		for ( $i = 1; $i <= Capabilities::USERS_PER_PAGE + 3; $i++ ) {
			bfrc_test_add_user( 100 + $i, [ 'roles' => [ Capabilities::ROLE_WRITER ] ] );
		}
		Capabilities::remove_roles();
		$writer_calls = array_filter( $GLOBALS['bfrc_test_get_users_calls'], static fn( array $a ): bool => Capabilities::ROLE_WRITER === $a['role'] );
		$this->assertCount( 2, $writer_calls, 'a full page, then the short page of 3 that ends the loop' );
		foreach ( $writer_calls as $call ) {
			$this->assertSame( Capabilities::USERS_PER_PAGE, $call['number'] );
			$this->assertSame( 1, $call['paged'], 'always page 1: the previous pass removed the role from the users it saw' );
		}
		$this->assertSame( [ 'subscriber' ], ( new \WP_User( 100 + Capabilities::USERS_PER_PAGE + 3 ) )->roles, 'the last user of the second page was moved too' );
	}

	public function test_remove_roles_skips_a_role_that_does_not_exist(): void {
		Capabilities::remove_roles();
		$this->assertSame( [], $GLOBALS['bfrc_test_get_users_calls'] );
	}

	public function test_grant_and_revoke_core_roles(): void {
		Capabilities::add_roles();
		Capabilities::grant_core_roles();
		$this->assertTrue( get_role( 'author' )->has_cap( 'edit_bfrc_recipes' ) );
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_others_bfrc_recipes' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'edit_others_bfrc_recipes' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_bfrc_terms' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'edit_bfrc_recipes' ) );

		Capabilities::revoke_core_roles();
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_bfrc_recipes' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_others_bfrc_recipes' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'manage_bfrc_terms' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'MANAGE on administrators belongs to add_roles(), not the core grant' );
		$this->assertTrue( get_role( 'author' )->has_cap( 'read' ), 'core caps are never revoked' );
	}

	public function test_grant_core_roles_tolerates_a_missing_core_role(): void {
		unset( $GLOBALS['bfrc_test_roles']['author'] );
		Capabilities::grant_core_roles();
		Capabilities::revoke_core_roles();
		$this->assertNull( get_role( 'author' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'read' ) );
	}

	public function test_current_user_can_manage_reads_the_manage_capability(): void {
		$this->assertFalse( Capabilities::current_user_can_manage() );
		bfrc_test_grant( Capabilities::MANAGE );
		$this->assertTrue( Capabilities::current_user_can_manage() );
	}
}
