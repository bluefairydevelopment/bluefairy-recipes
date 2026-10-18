<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Admin;

use BlueFairy\Recipes\Admin\WriterProfile;
use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Tests\TestCase;

final class WriterProfileTest extends TestCase {

	private \WP_User $writer;

	protected function setUp(): void {
		parent::setUp();
		Capabilities::add_roles();
		$this->writer = bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ] ] );
		bfrc_test_add_user( 6, [ 'roles' => [ 'subscriber' ] ] );
		wp_set_current_user( 5 );
	}

	/**
	 * @param array<string, mixed> $fields The `bfrc_writer` fields.
	 */
	private function post_form( array $fields, bool $nonce = true ): void {
		$_POST = [
			WriterProfile::NONCE_FIELD => $nonce ? wp_create_nonce( WriterProfile::NONCE_ACTION ) : 'wrong',
			WriterProfile::FIELD       => $fields,
		];
		$GLOBALS['bfrc_test_nonce_valid'] = $nonce;
	}

	public function test_register_hooks(): void {
		$p = new WriterProfile();
		$p->register_hooks();
		foreach ( [ 'show_user_profile' => 'render', 'edit_user_profile' => 'render', 'personal_options_update' => 'save', 'edit_user_profile_update' => 'save', 'admin_enqueue_scripts' => 'enqueue' ] as $hook => $method ) {
			$this->assertSame( 10, has_action( $hook, [ $p, $method ] ), $hook );
		}
	}

	public function test_keys_match_the_uninstall_list_exactly(): void {
		$this->assertSame( 1, preg_match( '/\$bfrc_user_meta = \[(.*?)\];/s', (string) file_get_contents( BFRC_DIR . 'uninstall.php' ), $m ) );
		preg_match_all( "/'([a-z_]+)'/", $m[1], $keys );
		$this->assertSame( $keys[1], WriterProfile::keys() );
	}

	public function test_is_listed_defaults_by_role_until_the_meta_is_set(): void {
		$this->assertTrue( WriterProfile::is_listed( 5 ), 'a Writer is listed by default' );
		$this->assertFalse( WriterProfile::is_listed( 6 ), 'anyone else is not' );
		$this->assertFalse( WriterProfile::is_listed( 404 ), 'unknown user' );
		update_user_meta( 5, 'bfrc_listed', '0' );
		update_user_meta( 6, 'bfrc_listed', '1' );
		$this->assertFalse( WriterProfile::is_listed( 5 ) );
		$this->assertTrue( WriterProfile::is_listed( 6 ) );
		update_user_meta( 5, 'bfrc_listed', 'garbage' );
		$this->assertTrue( WriterProfile::is_listed( 5 ), 'an unreadable value falls back to the role default' );
	}

	public function test_render_shows_the_section_for_recipe_writers_only_with_stored_values_escaped(): void {
		$p = new WriterProfile();
		$this->assertSame( '', bfrc_test_render( fn() => $p->render( get_userdata( 6 ) ) ), 'a subscriber has no writer profile' );
		bfrc_test_add_attachment( 77 );
		update_user_meta( 5, 'bfrc_avatar_id', 77 );
		update_user_meta( 5, 'bfrc_about', 'I <b>bake</b> & cook' );
		update_user_meta( 5, 'bfrc_website', 'https://example.test/?a=1&b=2' );
		$html = bfrc_test_render( fn() => $p->render( $this->writer ) );
		$this->assertStringContainsString( 'name="bfrc_writer_profile_nonce"', $html );
		$this->assertStringContainsString( 'name="bfrc_writer[bfrc_avatar_id]" value="77"', $html );
		$this->assertStringContainsString( '<img src="https://example.test/uploads/77.jpg"', $html );
		$this->assertStringContainsString( 'name="bfrc_writer[bfrc_header_image_id]" value="0"', $html );
		$this->assertStringContainsString( 'bfrc-image-picker__remove" hidden>', $html, 'no header image: Remove hidden' );
		$this->assertStringContainsString( 'I &lt;b&gt;bake&lt;/b&gt; &amp; cook</textarea>', $html );
		$this->assertStringContainsString( 'value="https://example.test/?a=1&amp;b=2"', $html );
		$this->assertStringContainsString( 'name="bfrc_writer[bfrc_listed]" value="1" checked="checked"', $html, 'Writer role default' );
		foreach ( [ 'bfrc_youtube', 'bfrc_facebook', 'bfrc_instagram', 'bfrc_snapchat' ] as $key ) {
			$this->assertStringContainsString( 'name="bfrc_writer[' . $key . ']"', $html );
		}
	}

	public function test_url_labels_cover_every_url_field(): void {
		$urls = array_keys( array_filter( WriterProfile::FIELDS, static fn( string $kind ): bool => 'url' === $kind ) );
		$this->assertSame( $urls, array_keys( WriterProfile::url_labels() ) );
	}

	public function test_enqueue_on_the_two_profile_screens_only(): void {
		$p = new WriterProfile();
		$p->enqueue( 'post.php' );
		$this->assertSame( [], $GLOBALS['bfrc_test_enqueued'] );
		$this->assertFalse( $GLOBALS['bfrc_test_media_enqueued'] );
		$p->enqueue( 'user-edit.php' );
		$this->assertTrue( $GLOBALS['bfrc_test_media_enqueued'] );
		$this->assertSame( [ 'bfrc-writer-profile' ], $GLOBALS['bfrc_test_enqueued']['script'] );
		$this->assertArrayHasKey( 'bfrcWriterProfile', $GLOBALS['bfrc_test_enqueued']['localize']['bfrc-writer-profile'] );
	}

	public function test_save_writes_every_field_by_kind(): void {
		bfrc_test_grant( 'edit_user' );
		bfrc_test_grant( 'read_post' );
		bfrc_test_add_attachment( 77 );
		$this->post_form(
			[
				'bfrc_avatar_id'  => '77',
				'bfrc_header_image_id' => '0',
				'bfrc_about'      => ' Hi <script>x()</script><em>there</em> ',
				'bfrc_website'    => ' https://example.test/me ',
				'bfrc_youtube'    => 'javascript:alert(1)',
				'bfrc_facebook'   => 'not a url',
				'bfrc_instagram'  => 'ftp://example.test/x',
				'bfrc_snapchat'   => '',
				'bfrc_listed'     => '1',
			]
		);
		( new WriterProfile() )->save( 5 );
		$this->assertSame( 77, get_user_meta( 5, 'bfrc_avatar_id', true ) );
		$this->assertSame( '', get_user_meta( 5, 'bfrc_header_image_id', true ), '0 clears' );
		$this->assertSame( 'Hi <em>there</em>', get_user_meta( 5, 'bfrc_about', true ) );
		$this->assertSame( 'https://example.test/me', get_user_meta( 5, 'bfrc_website', true ) );
		foreach ( [ 'bfrc_youtube', 'bfrc_facebook', 'bfrc_instagram', 'bfrc_snapchat' ] as $key ) {
			$this->assertSame( '', get_user_meta( 5, $key, true ), "$key refused" );
		}
		$this->assertSame( '1', get_user_meta( 5, 'bfrc_listed', true ) );
		$this->post_form( [ 'bfrc_website' => 'https://example.test/me' ] );
		( new WriterProfile() )->save( 5 );
		$this->assertSame( '0', get_user_meta( 5, 'bfrc_listed', true ), 'an unticked box is stored as an explicit no' );
		$this->assertSame( 77, get_user_meta( 5, 'bfrc_avatar_id', true ), 'a missing attachment field keeps the old value' );
	}

	public function test_a_bogus_or_unreadable_attachment_keeps_the_old_value(): void {
		bfrc_test_grant( 'edit_user' );
		bfrc_test_add_attachment( 77 );
		bfrc_test_add_attachment( 78, 'application/pdf' );
		update_user_meta( 5, 'bfrc_avatar_id', 77 );
		$p = new WriterProfile();
		foreach ( [ '999' => 'no such attachment', '78' => 'not an image', '41' => 'not an attachment', '77' => 'image the user may not read' ] as $id => $why ) {
			bfrc_test_add_post( [ 'ID' => 41, 'post_type' => 'bfrc_recipe' ] );
			$this->post_form( [ 'bfrc_avatar_id' => (string) $id ] );
			$p->save( 5 );
			$this->assertSame( 77, get_user_meta( 5, 'bfrc_avatar_id', true ), $why );
		}
		bfrc_test_grant( 'read_post' );
		bfrc_test_add_attachment( 80 );
		$this->post_form( [ 'bfrc_avatar_id' => '80' ] );
		$p->save( 5 );
		$this->assertSame( 80, get_user_meta( 5, 'bfrc_avatar_id', true ), 'control: a readable image is stored' );
	}

	public function test_save_refuses_without_edit_user_or_with_a_bad_nonce_and_ignores_forms_without_the_section(): void {
		$p = new WriterProfile();
		$this->post_form( [ 'bfrc_website' => 'https://example.test/' ] );
		$p->save( 6 );
		$this->assertSame( '', get_user_meta( 6, 'bfrc_website', true ), 'no edit_user on another user: nothing written' );
		bfrc_test_grant( 'edit_user' );
		$this->post_form( [ 'bfrc_website' => 'https://example.test/' ], false );
		$p->save( 6 );
		$this->assertSame( '', get_user_meta( 6, 'bfrc_website', true ), 'bad nonce' );
		$_POST = [ WriterProfile::FIELD => [ 'bfrc_website' => 'https://example.test/' ] ];
		$p->save( 6 );
		$this->assertSame( '', get_user_meta( 6, 'bfrc_website', true ), 'no nonce field: the section was not on this form' );
		$this->post_form( [ 'bfrc_website' => 'https://example.test/' ] );
		$p->save( 6 );
		$this->assertSame( 'https://example.test/', get_user_meta( 6, 'bfrc_website', true ), 'control' );
	}

	public function test_sanitize_link(): void {
		$this->assertSame( 'https://example.test/a?b=1', WriterProfile::sanitize_link( ' https://example.test/a?b=1 ' ) );
		$this->assertSame( 'http://example.test', WriterProfile::sanitize_link( 'http://example.test' ) );
		$this->assertSame( 'https://example.test/jenny', WriterProfile::sanitize_link( 'https://example.test/jenny' ), 'an unresolvable host is still a valid link: no DNS lookup' );
		$this->assertSame( 'http://10.0.0.5/intranet', WriterProfile::sanitize_link( 'http://10.0.0.5/intranet' ), 'a private address is a link, not a fetch target' );
		$this->assertSame( 'HTTPS://Example.test', WriterProfile::sanitize_link( 'HTTPS://Example.test' ), 'scheme case is not a reason to refuse' );
		foreach ( [ 'javascript:alert(1)', 'ftp://x.test', '//x.test', 'example.test', 'https://', 'https:///path', 'https://./', '', null, [ 'a' ], 12 ] as $bad ) {
			$this->assertSame( '', WriterProfile::sanitize_link( $bad ), var_export( $bad, true ) );
		}
	}
}
