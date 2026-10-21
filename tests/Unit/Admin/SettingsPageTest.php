<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Admin;

use BlueFairy\Recipes\Admin\Samples;
use BlueFairy\Recipes\Admin\SettingsPage;
use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

final class SettingsPageTest extends TestCase {

	private Settings $settings;
	private SettingsPage $page;
	private Samples $samples;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$GLOBALS['bfrc_test_is_admin']      = true;
		Capabilities::add_roles();
		$this->settings = new Settings();
		$this->samples  = new Samples();
		$this->page     = new SettingsPage( $this->settings, new Taxonomies( $this->settings ), $this->samples );
		foreach ( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$GLOBALS['bfrc_test_taxonomies'][ $tax ] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		}
		bfrc_test_add_user( 1, [ 'roles' => [ 'administrator' ] ] );
		bfrc_test_add_user( 5, [ 'roles' => [ 'bfrc_writer' ] ] );
		wp_set_current_user( 1 );
		bfrc_test_grant( 'create_users' ); // The harness's administrator carries the plugin's caps only; the samples need core's create_users too.
		$_REQUEST['_wpnonce'] = 'nonce-bfrc_save_settings'; // The stub accepts nonce-<action>.
	}

	/** Render a tab and return the HTML. */
	private function render( string $tab = '' ): string {
		$_GET['tab'] = $tab;
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	public function test_register_hooks_and_menu_with_the_add_ons_items(): void {
		$p = $this->page;
		$p->register_hooks();
		$this->assertSame( 10, has_action( 'admin_menu', [ $p, 'register_menu' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_bfrc_save_settings', [ $p, 'save' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_bfrc_install_samples', [ $p, 'install_samples' ] ) );
		$this->assertSame( 10, has_action( 'admin_post_bfrc_remove_samples', [ $p, 'remove_samples' ] ) );
		add_filter( 'bfrc_admin_menu_items', static fn( array $items ): array => array_merge( $items, [ [ 'page_title' => 'Reviews', 'menu_title' => 'Reviews', 'capability' => 'moderate_comments', 'slug' => 'bfrcp-reviews', 'callback' => '__return_empty_array' ], [ 'slug' => 'broken' ] ] ) );
		$p->register_menu();
		$menus = array_values( array_filter( $GLOBALS['bfrc_test_admin_pages'] ?? [], static fn( array $m ): bool => isset( $m['parent_slug'] ) ) );
		$this->assertSame( [ 'bfrc-settings', 'bfrcp-reviews' ], array_column( $menus, 'menu_slug' ), 'Settings first, then the add-on\'s complete items; an incomplete one is skipped' );
		$this->assertSame( [ 'edit.php?post_type=bfrc_recipe', 'bfrc_manage_recipes' ], [ $menus[0]['parent_slug'], $menus[0]['capability'] ] );
	}

	public function test_tabs_come_from_the_filter_and_the_current_tab_falls_back(): void {
		$this->assertSame( [ 'general', 'listing', 'writers', 'data' ], array_keys( $this->page->tabs() ) );
		add_filter( 'bfrc_settings_tabs', static fn( array $tabs ): array => $tabs + [ 'licence' => [ 'label' => 'Licence', 'render' => static function (): void { echo '<p>pro tab</p>'; } ], 9 => [ 'label' => 'bad' ], 'nolabel' => [], 'norender' => [ 'label' => 'No callback' ] ] );
		$this->assertSame( [ 'general', 'listing', 'writers', 'data', 'licence' ], array_keys( $this->page->tabs() ), 'a non-string id, a tab without a label or an add-on tab without a render callback is dropped' );
		$_GET['tab'] = 'licence';
		$this->assertSame( 'licence', $this->page->current_tab() );
		$_GET['tab'] = 'nope';
		$this->assertSame( 'general', $this->page->current_tab() );
		unset( $_GET['tab'] );
		$this->assertSame( 'general', $this->page->current_tab() );
		$this->assertSame( 'https://example.com/wp-admin/edit.php?post_type=bfrc_recipe&page=bfrc-settings&tab=data&bfrc_notice=saved', $this->page->url( 'data', 'saved' ) );
		$this->assertStringContainsString( '<p>pro tab</p>', $this->render( 'licence' ), 'an add-on tab renders through its own callback' );
		add_filter( 'bfrc_settings_tabs', '__return_empty_array', 20 );
		$this->assertSame( [ 'general', 'listing', 'writers', 'data' ], array_keys( $this->page->tabs() ), 'a filter that empties the list leaves the base tabs' );
	}

	public function test_render_shows_the_tabs_the_tab_form_the_notice_and_the_footer_seam(): void {
		add_action( 'bfrc_settings_page_footer', static function (): void { echo '<i data-seam="footer"></i>'; } );
		$_GET['bfrc_notice'] = 'saved';
		$html                = $this->render( 'general' );
		$this->assertStringContainsString( '<h1>Recipe Settings</h1>', $html );
		$this->assertStringContainsString( '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>', $html );
		$this->assertStringContainsString( 'class="nav-tab nav-tab-active" href="https://example.com/wp-admin/edit.php?post_type=bfrc_recipe&#038;page=bfrc-settings&#038;tab=general" aria-current="page">General</a>', $html );
		$this->assertStringContainsString( 'href="https://example.com/wp-admin/edit.php?post_type=bfrc_recipe&#038;page=bfrc-settings&#038;tab=data">Data</a>', $html );
		$this->assertStringContainsString( '<form method="post" action="https://example.com/wp-admin/admin-post.php"><input type="hidden" name="action" value="bfrc_save_settings"><input type="hidden" name="bfrc_tab" value="general">', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( '<input type="checkbox" id="bfrc-enabled" name="bfrc[enabled]" value="1" checked="checked" aria-describedby="bfrc-enabled-description"> <span class="description" id="bfrc-enabled-description">', $html, 'the help text is announced with the checkbox' );
		$this->assertStringContainsString( 'name="bfrc[archive_slug]" value="recipes"', $html );
		$this->assertStringContainsString( 'name="bfrc[taxonomies][diet][enabled]" value="1" checked="checked"', $html );
		ob_start();
		$this->page->render_form( 'listing' );
		$this->assertStringContainsString( 'bfrc[per_page]', (string) ob_get_clean(), 'render_form prints one tab' );
		ob_start();
		$this->page->render_notice();
		$this->assertStringContainsString( 'Settings saved.', (string) ob_get_clean() );
		ob_start();
		$this->page->render_samples();
		$this->assertStringContainsString( 'Install sample recipes', (string) ob_get_clean() );
		$this->assertStringContainsString( 'name="bfrc[taxonomies][cuisine][sort_order]" value="40"', $html );
		$this->assertStringContainsString( '<i data-seam="footer"></i></div>', $html, 'the footer seam fires after the form' );
		$this->assertStringNotContainsString( 'bfrc[per_page]', $html, 'another tab\'s fields are not in this form' );
		$this->assertStringContainsString( 'name="bfrc[per_page]" value="12" min="1" max="100"', $this->render( 'listing' ) );
		$this->settings->update( [ 'archive_slug' => 'cookbook' ] + $this->settings->all() );
		$writers = $this->render( 'writers' );
		$this->assertStringContainsString( 'bfrc[writers_listing]', $writers );
		$this->assertStringContainsString( 'at /cookbook/chef/ (each', $writers, 'the help text names the site\'s own path, not /recipes/chef/' );
		$GLOBALS['wp_rewrite']->permalink_structure = '';
		$this->assertStringContainsString( 'at /?post_type=bfrc_recipe&amp;bfrc_writers=1 (each', $this->render( 'writers' ), 'plain permalinks: the query form, as Writers::listing_url() gives it' );
		$GLOBALS['wp_rewrite']->permalink_structure = '/%postname%/';
		$data = $this->render( 'data' );
		$this->assertStringContainsString( 'bfrc[delete_data_on_uninstall]', $data );
		$this->assertStringContainsString( 'value="bfrc_install_samples"', $data, 'nothing installed: the Install button' );
		$this->assertStringContainsString( 'id="bfrc-install-samples"', $data, 'its own id: the Save button on the same tab is #submit' );
		$this->assertSame( 1, substr_count( $data, 'id="submit"' ), 'one #submit on the page' );
		$this->assertStringNotContainsString( 'bfrc_remove_samples', $data );
		$_GET['bfrc_notice'] = 'nope';
		$this->assertStringNotContainsString( 'notice-', $this->render( 'general' ), 'an unknown notice code prints nothing' );
	}

	public function test_render_refuses_a_user_who_cannot_manage(): void {
		wp_set_current_user( 5 );
		$exit = bfrc_test_catch_exit( fn() => $this->page->render() );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );
	}

	public function test_save_writes_the_tabs_keys_only_with_form_semantics_fires_the_seam_and_redirects(): void {
		$seen = null;
		add_action( 'bfrc_settings_saved', static function ( array $clean, array $raw ) use ( &$seen ): void { $seen = [ $clean['per_page'], $raw['per_page'] ?? null ]; }, 10, 2 );
		$this->settings->update( [ 'share_enabled' => true, 'share_x' => true, 'writers_listing' => false ] + $this->settings->all() );
		$_POST = [ 'bfrc_tab' => 'listing', 'bfrc' => [ 'per_page' => '250', 'share_facebook' => '1', 'share_pinterest' => '0', 'extra' => 'ignored' ] ];
		$exit  = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertSame( [ 'redirect', 'https://example.com/wp-admin/edit.php?post_type=bfrc_recipe&page=bfrc-settings&tab=listing&bfrc_notice=saved_adjusted' ], [ $exit->kind, $exit->payload ], 'a clamped value is said, not hidden behind "saved"' );
		$all = ( new Settings() )->all();
		$this->assertSame( [ 100, false, true, false, false ], [ $all['per_page'], $all['share_enabled'], $all['share_facebook'], $all['share_x'], $all['share_pinterest'] ], 'bounded; an absent checkbox of this tab is unticked, and so is a posted 0' );
		$this->assertFalse( $all['writers_listing'], 'another tab\'s value is untouched' );
		$this->assertTrue( $all['enabled'] );
		$this->assertSame( [ 100, '250' ], $seen, 'the seam sees the stored value and the raw post' );
		$_POST = [ 'bfrc_tab' => 'listing', 'bfrc' => [ 'per_page' => '20' ] ];
		$exit  = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertStringEndsWith( 'tab=listing&bfrc_notice=saved', (string) $exit->payload, 'an in-range value is plainly saved' );
		$this->assertSame( 20, ( new Settings() )->all()['per_page'] );
		$_POST = [ 'bfrc_tab' => 'general', 'bfrc' => [ 'archive_slug' => 'feed', 'archive_title' => 'Recipes' ] ];
		$exit  = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertStringEndsWith( 'tab=general&bfrc_notice=saved_adjusted', (string) $exit->payload, 'a reserved slug is replaced and said' );
		$_POST = [ 'bfrc_tab' => 'nope', 'bfrc' => [ 'enabled' => '1' ] ];
		$exit  = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertStringEndsWith( 'tab=general&bfrc_notice=failed', (string) $exit->payload, 'an unknown tab saves nothing: it would have switched every General checkbox off' );
		$this->assertSame( 20, ( new Settings() )->all()['per_page'] );
		$this->assertSame( 'recipes', ( new Settings() )->all()['archive_slug'] );
		$_POST = [ 'bfrc_tab' => 'general', 'bfrc' => [ 'archive_slug' => 'cook book', 'archive_title' => ' Our Kitchen ', 'taxonomies' => [ 'diet' => [ 'sort_order' => '5' ], 'season' => [ 'enabled' => '1', 'sort_order' => '999' ] ] ] ];
		bfrc_test_catch_exit( fn() => $this->page->save() );
		$all = ( new Settings() )->all();
		$this->assertSame( [ false, false, 'cook-book', 'Our Kitchen' ], [ $all['enabled'], $all['rich_snippets'], $all['archive_slug'], $all['archive_title'] ] );
		$this->assertSame( [ 'enabled' => false, 'sort_order' => 5 ], $all['taxonomies']['diet'], 'unticked and re-sorted' );
		$this->assertSame( [ 'enabled' => true, 'sort_order' => 999 ], $all['taxonomies']['season'] );
		$this->assertSame( [ 'enabled' => false, 'sort_order' => 10 ], $all['taxonomies']['meal_type'], 'a row the form showed but did not tick' );
		$_POST = [ 'bfrc_tab' => 'bogus', 'bfrc' => [ 'enabled' => '1', 'per_page' => '7' ] ];
		$exit  = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertStringEndsWith( 'tab=general&bfrc_notice=failed', (string) $exit->payload, 'an unknown tab saves nothing' );
		$this->assertSame( [ 20, false ], [ ( new Settings() )->all()['per_page'], ( new Settings() )->all()['enabled'] ], 'neither tab touched' );
		$GLOBALS['bfrc_test_option_write_fails'] = [ 'bfrc_settings' ];
		$_POST                                   = [ 'bfrc_tab' => 'writers', 'bfrc' => [ 'writers_listing' => '1' ] ];
		$exit                                    = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertStringContainsString( 'bfrc_notice=failed', (string) $exit->payload );
	}

	public function test_save_checks_the_capability_then_the_nonce(): void {
		wp_set_current_user( 5 );
		$_POST = [ 'bfrc_tab' => 'general', 'bfrc' => [ 'enabled' => '1' ] ];
		$exit  = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );
		wp_set_current_user( 1 );
		$GLOBALS['bfrc_test_nonce_valid'] = false;
		$exit                             = bfrc_test_catch_exit( fn() => $this->page->save() );
		$this->assertSame( [ 'die', 'The link you followed has expired.' ], [ $exit->kind, $exit->payload ], 'the nonce failure is core\'s own die, after the capability check' );
		$this->assertSame( 12, ( new Settings() )->all()['per_page'], 'nothing written' );
	}

	public function test_samples_install_and_remove_through_the_handlers(): void {
		foreach ( [ 'bfrc_meal_type', 'bfrc_diet', 'bfrc_season', 'bfrc_cuisine' ] as $tax ) {
			$GLOBALS['bfrc_test_taxonomies'][ $tax ] = [ 'object_type' => [ 'bfrc_recipe' ], 'args' => [] ];
		}
		$_REQUEST['bfrc_install_nonce'] = 'nonce-bfrc_install_samples';
		$exit                 = bfrc_test_catch_exit( fn() => $this->page->install_samples() );
		$this->assertStringContainsString( 'tab=data&bfrc_notice=samples_added', (string) $exit->payload );
		$this->assertCount( 3, $this->samples->installed()['recipes'] );
		$data = $this->render( 'data' );
		$this->assertStringContainsString( 'The sample recipes are installed (3 recipes by the sample writer bfrc_sample_writer).', $data );
		$this->assertStringContainsString( 'value="bfrc_remove_samples"', $data );
		$this->assertStringContainsString( 'Remove deletes the sample recipes permanently (not to the trash), even if you edited or published them', $data, 'the destructive wording' );
		$this->assertSame( 1, substr_count( $data, 'name="_wpnonce"' ), 'the Remove form has its own nonce field name: one _wpnonce on the tab' );
		$this->assertStringContainsString( 'name="bfrc_remove_nonce"', $data );
		$this->assertStringNotContainsString( 'published on your site', $data, 'all drafts: no published warning' );
		$this->assertStringNotContainsString( 'value="bfrc_install_samples"', $data );
		$GLOBALS['bfrc_test_posts'][ $this->samples->installed()['recipes'][1] ]->post_status = 'publish';
		$this->assertStringContainsString( '<strong>1 of them is published on your site.</strong>', $this->render( 'data' ) );
		$exit = bfrc_test_catch_exit( fn() => $this->page->install_samples() );
		$this->assertStringContainsString( 'bfrc_notice=samples_present', (string) $exit->payload );
		$_REQUEST['bfrc_remove_nonce'] = 'nonce-bfrc_remove_samples';
		$exit                 = bfrc_test_catch_exit( fn() => $this->page->remove_samples() );
		$this->assertStringContainsString( 'bfrc_notice=samples_no_delete_users', (string) $exit->payload, 'removing deletes a user account: delete_users is the mirror of create_users' );
		$this->assertCount( 3, $this->samples->installed()['recipes'], 'nothing removed' );
		$data = $this->render( 'data' );
		$this->assertStringContainsString( '<button type="submit" name="submit" class="button-link button-link-delete" disabled="disabled" aria-describedby="bfrc-samples-remove-why">Remove sample recipes</button><p class="description" id="bfrc-samples-remove-why">', $data, 'the Remove control is disabled for them, with the reason, in core\'s red link style' );
		$this->assertFalse( SettingsPage::can_remove_writer() );
		$GLOBALS['bfrc_test_multisite'] = true;
		$this->assertFalse( SettingsPage::can_remove_writer(), 'on a network delete_users is super admins only' );
		bfrc_test_grant( 'remove_users' );
		$this->assertTrue( SettingsPage::can_remove_writer(), 'a site administrator removes the account from the site with remove_users' );
		$GLOBALS['bfrc_test_multisite'] = false;
		$this->assertFalse( SettingsPage::can_remove_writer() );
		bfrc_test_grant( 'delete_users' );
		$this->assertStringContainsString( '<button type="submit" name="submit" class="button-link button-link-delete">Remove sample recipes</button></form>', $this->render( 'data' ) );
		$exit = bfrc_test_catch_exit( fn() => $this->page->remove_samples() );
		$this->assertStringContainsString( 'bfrc_notice=samples_removed', (string) $exit->payload );
		$this->assertSame( [ [ 900, 1 ] ], $GLOBALS['bfrc_test_deleted_users_reassign'], 'the writer\'s other posts go to the remover' );
		bfrc_test_add_user( 7, [ 'roles' => [ 'editor' ], 'user_login' => 'bfrc_sample_writer', 'user_email' => 'ed@example.com' ] );
		$_REQUEST['bfrc_install_nonce'] = 'nonce-bfrc_install_samples';
		$exit                 = bfrc_test_catch_exit( fn() => $this->page->install_samples() );
		$this->assertStringContainsString( 'bfrc_notice=samples_writer_taken', (string) $exit->payload, 'someone else\'s account with the login: refused with its own notice' );
		$this->assertSame( [ 'editor' ], get_userdata( 7 )->roles );
		unset( $GLOBALS['bfrc_test_users'][7] );
		$_REQUEST['bfrc_install_nonce'] = 'nonce-bfrc_install_samples';
		$this->assertSame( [], $this->samples->installed() );
		$GLOBALS['bfrc_test_user_insert_fails'] = true;
		$exit                                   = bfrc_test_catch_exit( fn() => $this->page->install_samples() );
		$this->assertStringContainsString( 'bfrc_notice=samples_failed', (string) $exit->payload );
		$GLOBALS['bfrc_test_user_insert_fails'] = false;
		bfrc_test_add_user( 8, [ 'roles' => [ 'bfrc_recipe_manager' ] ] );
		wp_set_current_user( 8 );
		$GLOBALS['bfrc_test_caps']['create_users'] = false; // The harness grants caps globally: take core's create_users away for the manager.
		$exit = bfrc_test_catch_exit( fn() => $this->page->install_samples() );
		$this->assertStringContainsString( 'bfrc_notice=samples_no_users', (string) $exit->payload, 'a manager without create_users cannot create the sample writer' );
		$data = $this->render( 'data' );
		$this->assertStringContainsString( 'disabled="disabled" aria-describedby="bfrc-samples-why"', $data, 'the Install button is disabled for them, with the reason' );
		$this->assertStringContainsString( '<p class="description" id="bfrc-samples-why">Installing the samples creates a sample writer account, which needs the right to create users.</p>', $data );
		wp_set_current_user( 5 );
		$this->assertSame( 403, bfrc_test_catch_exit( fn() => $this->page->install_samples() )->status );
		$this->assertSame( 403, bfrc_test_catch_exit( fn() => $this->page->remove_samples() )->status );
		wp_set_current_user( 1 );
		$_REQUEST['_wpnonce'] = 'nonce-bfrc_remove_samples'; // The right value in the wrong field: the sample forms read their own field names.
		unset( $_REQUEST['bfrc_remove_nonce'] );
		$this->assertSame( 'The link you followed has expired.', bfrc_test_catch_exit( fn() => $this->page->remove_samples() )->payload, 'the remove nonce is its own field' );
		$data = $this->render( 'data' );
		$this->assertSame( 1, substr_count( $data, 'name="_wpnonce"' ), 'one _wpnonce (the settings form); the sample form has its own field, so no duplicate id' );
		$this->assertStringContainsString( 'name="bfrc_install_nonce"', $data );
	}

	public function test_the_plugins_row_gets_a_settings_link_before_the_upsells(): void {
		$this->page->register_hooks();
		( new \BlueFairy\Recipes\Admin\ProUpsell() )->register_hooks();
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( BFRC_FILE ), [ '<a href="d">Deactivate</a>' ] );
		$this->assertCount( 3, $links );
		$this->assertSame( '<a href="https://example.com/wp-admin/edit.php?post_type=bfrc_recipe&#038;page=bfrc-settings">Settings</a>', $links[0], 'Settings first, as core\'s own plugins do' );
		$this->assertStringContainsString( 'Get Pro', $links[2] );
		$this->assertSame( [ '<a href="https://example.com/wp-admin/edit.php?post_type=bfrc_recipe&#038;page=bfrc-settings">Settings</a>' ], $this->page->action_links( 'lost' ), 'a filter chain that lost the array still gets the link' );
	}

	public function test_every_setting_in_the_vocabulary_has_a_field_on_its_tab(): void {
		$seen = [];
		foreach ( SettingsPage::TAB_KEYS as $tab => $keys ) {
			ob_start();
			$this->page->render_form( $tab );
			$html = (string) ob_get_clean();
			foreach ( $keys as $key ) {
				$this->assertStringContainsString( 'name="bfrc[' . $key . ']', $html, "$key on the $tab tab" );
				$seen[] = $key;
			}
		}
		$this->assertSame( [], array_diff( array_keys( Settings::DEFAULTS ), $seen ), 'a setting with no field could never be changed from the screen' );
		$this->assertSame( [], array_diff( $seen, array_keys( Settings::DEFAULTS ) ), 'a field for a setting that does not exist is dropped on save' );
	}
}
