<?php
/**
 * Recipes → Settings.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Admin;

use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A submenu of the Recipes menu with four tabs (General, Listing, Writers,
 * Data) and the seams the Pro add-on hooks: `bfrc_settings_tabs` (its own
 * tabs, each with a render callback), `bfrc_admin_menu_items` (its own
 * submenus), `bfrc_settings_page_footer` (the upsell, and whatever a tab
 * wants below the form), `bfrc_settings_saved( $clean, $raw )`.
 *
 * Saving: the form posts the current tab's fields to `admin-post.php`
 * (`bfrc_save_settings`); the handler checks the capability first, then
 * the nonce, then reads exactly the tab's keys (an absent checkbox is
 * unticked) and writes them through `Settings::patch()`, the only sanitiser,
 * so the other tabs' values stay as stored. Every handler ends in a redirect
 * back to the tab with `bfrc_notice=<code>`, so a refresh cannot replay the
 * POST.
 */
final class SettingsPage {

	public const SLUG          = 'bfrc-settings';
	public const ACTION_SAVE   = 'bfrc_save_settings';
	public const NOTICE_VAR    = 'bfrc_notice';
	public const TAB_VAR       = 'tab';
	public const PARENT        = 'edit.php?post_type=' . PostType::NAME;
	public const SAMPLES_FORMS = [
		'install' => Samples::ACTION_INSTALL,
		'remove'  => Samples::ACTION_REMOVE,
	];

	/**
	 * Which settings keys each tab's form carries (checkboxes included).
	 *
	 * @var array<string, list<string>>
	 */
	public const TAB_KEYS = [
		'general' => [ 'enabled', 'rich_snippets', 'archive_slug', 'archive_title', 'taxonomies' ],
		'listing' => [ 'per_page', 'share_enabled', 'share_facebook', 'share_x', 'share_pinterest' ],
		'writers' => [ 'writers_listing', 'grant_core_roles' ],
		'data'    => [ 'delete_data_on_uninstall' ],
	];

	/**
	 * The checkbox keys (absent from the POST = false).
	 *
	 * @var list<string>
	 */
	private const CHECKBOXES = [ 'enabled', 'rich_snippets', 'share_enabled', 'share_facebook', 'share_x', 'share_pinterest', 'writers_listing', 'grant_core_roles', 'delete_data_on_uninstall' ];

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Taxonomies $taxonomies Filter types (the General tab lists them).
	 * @param Samples    $samples    The sample installer (the Data tab).
	 */
	public function __construct( private Settings $settings, private Taxonomies $taxonomies, private Samples $samples ) {}

	/**
	 * Hook registration.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_' . self::ACTION_SAVE, [ $this, 'save' ] );
		add_action( 'admin_post_' . Samples::ACTION_INSTALL, [ $this, 'install_samples' ] );
		add_action( 'admin_post_' . Samples::ACTION_REMOVE, [ $this, 'remove_samples' ] );
	}

	/**
	 * `admin_menu`: the Settings submenu, then the add-on's items.
	 */
	public function register_menu(): void {
		add_submenu_page( self::PARENT, __( 'Recipe Settings', 'bluefairy-recipes' ), __( 'Settings', 'bluefairy-recipes' ), Capabilities::MANAGE, self::SLUG, [ $this, 'render' ] );
		/**
		 * Extra submenus under Recipes. Each item: `page_title`, `menu_title`,
		 * `capability`, `slug`, `callback`.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array{page_title: string, menu_title: string, capability: string, slug: string, callback: callable}> $items Default none.
		 */
		$items = apply_filters( 'bfrc_admin_menu_items', [] );
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			if ( is_array( $item ) && isset( $item['page_title'], $item['menu_title'], $item['capability'], $item['slug'], $item['callback'] ) && is_callable( $item['callback'] ) ) {
				add_submenu_page( self::PARENT, (string) $item['page_title'], (string) $item['menu_title'], (string) $item['capability'], sanitize_key( (string) $item['slug'] ), $item['callback'] );
			}
		}
	}

	/**
	 * The tabs: id => label and, for an add-on's tab, its render callback.
	 *
	 * @return array<string, array{label: string, render?: callable}>
	 */
	public function tabs(): array {
		$tabs = [
			'general' => [ 'label' => __( 'General', 'bluefairy-recipes' ) ],
			'listing' => [ 'label' => __( 'Listing', 'bluefairy-recipes' ) ],
			'writers' => [ 'label' => __( 'Writers', 'bluefairy-recipes' ) ],
			'data'    => [ 'label' => __( 'Data', 'bluefairy-recipes' ) ],
		];
		/**
		 * The settings tabs. An add-on adds `id => [ 'label' => …, 'render' => callable ]`;
		 * its callback prints the tab's content and handles its own saving.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{label: string, render?: callable}> $tabs The four base tabs.
		 */
		$filtered = apply_filters( 'bfrc_settings_tabs', $tabs );
		$out      = [];
		foreach ( is_array( $filtered ) ? $filtered : $tabs as $id => $tab ) {
			if ( is_string( $id ) && is_array( $tab ) && is_string( $tab['label'] ?? null ) ) {
				$out[ sanitize_key( $id ) ] = $tab;
			}
		}
		return [] === $out ? $tabs : $out;
	}

	/**
	 * The tab asked for, `general` when unknown.
	 */
	public function current_tab(): string {
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show; no action is taken.
		$tab = isset( $_GET[ self::TAB_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::TAB_VAR ] ) ) : 'general';
		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	/**
	 * The page's URL, optionally for a tab and with a notice.
	 *
	 * @param string $tab    Tab id ('' for the current one).
	 * @param string $notice Notice code ('' for none).
	 */
	public function url( string $tab = '', string $notice = '' ): string {
		$args = [
			'post_type' => PostType::NAME,
			'page'      => self::SLUG,
		];
		if ( '' !== $tab ) {
			$args[ self::TAB_VAR ] = $tab;
		}
		if ( '' !== $notice ) {
			$args[ self::NOTICE_VAR ] = $notice;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * The page.
	 */
	public function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage recipe settings.', 'bluefairy-recipes' ), '', [ 'response' => 403 ] );
		}
		$tabs    = $this->tabs();
		$current = $this->current_tab();
		echo '<div class="wrap bfrc-settings"><h1>' . esc_html__( 'Recipe Settings', 'bluefairy-recipes' ) . '</h1>';
		$this->render_notice();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings tabs', 'bluefairy-recipes' ) . '">';
		foreach ( $tabs as $id => $tab ) {
			echo '<a class="nav-tab' . ( $id === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->url( $id ) ) . '"' . ( $id === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $tab['label'] ) . '</a>';
		}
		echo '</nav>';
		if ( isset( $tabs[ $current ]['render'] ) && is_callable( $tabs[ $current ]['render'] ) ) {
			call_user_func( $tabs[ $current ]['render'] ); // An add-on's tab.
		} else {
			$this->render_form( $current );
		}
		/**
		 * The foot of the settings page, every tab (the upsell prints here).
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfrc_settings_page_footer' );
		echo '</div>';
	}

	/**
	 * The notice named in the URL, if any.
	 */
	public function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice code chosen by the redirect, mapped to fixed text.
		$code  = isset( $_GET[ self::NOTICE_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_VAR ] ) ) : '';
		$texts = [
			'saved'            => [ 'success', __( 'Settings saved.', 'bluefairy-recipes' ) ],
			'failed'           => [ 'error', __( 'The settings could not be saved. Try again.', 'bluefairy-recipes' ) ],
			'samples_added'    => [ 'success', __( 'Sample recipes installed: three recipes by a sample writer, as drafts you can publish.', 'bluefairy-recipes' ) ],
			'samples_removed'  => [ 'success', __( 'Sample recipes removed.', 'bluefairy-recipes' ) ],
			'samples_present'  => [ 'warning', __( 'The sample recipes are already installed.', 'bluefairy-recipes' ) ],
			'samples_failed'   => [ 'error', __( 'The sample recipes could not be installed. Nothing was kept.', 'bluefairy-recipes' ) ],
			'samples_no_users' => [ 'error', __( 'Installing the samples creates a sample writer, which needs the right to create users.', 'bluefairy-recipes' ) ],
		];
		if ( ! isset( $texts[ $code ] ) ) {
			return;
		}
		[ $kind, $text ] = $texts[ $code ];
		echo '<div class="notice notice-' . esc_attr( $kind ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * One tab's form.
	 *
	 * @param string $tab The tab id.
	 */
	public function render_form( string $tab ): void {
		$s = $this->settings->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '"><input type="hidden" name="bfrc_tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( self::ACTION_SAVE );
		echo '<table class="form-table" role="presentation"><tbody>';
		switch ( $tab ) {
			case 'general':
				$this->checkbox( 'enabled', __( 'Enabled', 'bluefairy-recipes' ), (bool) $s['enabled'], __( 'Off: recipes, the archive and the filter pages return 404, recipes leave search, the sitemap and the public REST API; the admin keeps working.', 'bluefairy-recipes' ) );
				$this->checkbox( 'rich_snippets', __( 'Rich snippets', 'bluefairy-recipes' ), (bool) $s['rich_snippets'], __( 'Recipe JSON-LD for Google on every recipe page with a photo.', 'bluefairy-recipes' ) );
				$this->text( 'archive_slug', __( 'Archive slug', 'bluefairy-recipes' ), (string) $s['archive_slug'], __( 'The address of the recipe archive, filter pages and writer pages. Changing it updates the site\'s rewrite rules on the next page load.', 'bluefairy-recipes' ) );
				$this->text( 'archive_title', __( 'Archive title', 'bluefairy-recipes' ), (string) $s['archive_title'], '' );
				$this->taxonomy_rows();
				break;
			case 'listing':
				$this->number( 'per_page', __( 'Recipes per page', 'bluefairy-recipes' ), (int) $s['per_page'], 1, 100, '' );
				$this->checkbox( 'share_enabled', __( 'Social share', 'bluefairy-recipes' ), (bool) $s['share_enabled'], __( 'Share buttons on recipe pages.', 'bluefairy-recipes' ) );
				$this->checkbox( 'share_facebook', __( 'Facebook', 'bluefairy-recipes' ), (bool) $s['share_facebook'], '' );
				$this->checkbox( 'share_x', __( 'X', 'bluefairy-recipes' ), (bool) $s['share_x'], '' );
				$this->checkbox( 'share_pinterest', __( 'Pinterest', 'bluefairy-recipes' ), (bool) $s['share_pinterest'], '' );
				break;
			case 'writers':
				$this->checkbox( 'writers_listing', __( 'Writer listing', 'bluefairy-recipes' ), (bool) $s['writers_listing'], __( 'The list of recipe writers at /recipes/chef/ (each writer can opt out on their profile).', 'bluefairy-recipes' ) );
				$this->checkbox( 'grant_core_roles', __( 'Grant core roles', 'bluefairy-recipes' ), (bool) $s['grant_core_roles'], __( 'Authors can write recipes and Editors can manage them. Turning it off takes those capabilities back; Administrators can always do everything.', 'bluefairy-recipes' ) );
				break;
			case 'data':
				$this->checkbox( 'delete_data_on_uninstall', __( 'Delete all data on uninstall', 'bluefairy-recipes' ), (bool) $s['delete_data_on_uninstall'], __( 'Off: deleting the plugin keeps your recipes, terms and writer profiles. On: everything the plugin created is removed.', 'bluefairy-recipes' ) );
				break;
		}
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'bluefairy-recipes' ) );
		echo '</form>';
		if ( 'data' === $tab ) {
			$this->render_samples();
		}
	}

	/**
	 * The Data tab's sample-recipes box: install once, remove when done.
	 */
	public function render_samples(): void {
		$installed = $this->samples->installed();
		echo '<h2>' . esc_html__( 'Sample recipes', 'bluefairy-recipes' ) . '</h2>';
		if ( [] === $installed ) {
			echo '<p>' . esc_html__( 'Install three sample recipes by a sample writer, with photos, to see the archive, the filters and a recipe page filled in. They are created as drafts; remove them from here when you are done.', 'bluefairy-recipes' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Samples::ACTION_INSTALL ) . '">';
			wp_nonce_field( Samples::ACTION_INSTALL );
			submit_button( __( 'Install sample recipes', 'bluefairy-recipes' ), 'secondary', 'submit', false );
			echo '</form>';
			return;
		}
		/* translators: 1: number of recipes, 2: the sample writer's login */
		echo '<p>' . esc_html( sprintf( __( 'The sample recipes are installed (%1$d recipes by the sample writer %2$s).', 'bluefairy-recipes' ), count( $installed['recipes'] ?? [] ), (string) ( $installed['writer_login'] ?? '' ) ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Samples::ACTION_REMOVE ) . '">';
		wp_nonce_field( Samples::ACTION_REMOVE );
		submit_button( __( 'Remove sample recipes', 'bluefairy-recipes' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * `admin_post_bfrc_save_settings`: capability, nonce, the tab's keys,
	 * `Settings::patch()`, `bfrc_settings_saved`, redirect.
	 */
	public function save(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage recipe settings.', 'bluefairy-recipes' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION_SAVE );
		$tab = isset( $_POST['bfrc_tab'] ) ? sanitize_key( wp_unslash( $_POST['bfrc_tab'] ) ) : 'general';
		$tab = isset( self::TAB_KEYS[ $tab ] ) ? $tab : 'general';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is typed and bounded by Settings::patch() (the sanitiser) below.
		$raw    = isset( $_POST['bfrc'] ) && is_array( $_POST['bfrc'] ) ? wp_unslash( $_POST['bfrc'] ) : [];
		$values = [];
		foreach ( self::TAB_KEYS[ $tab ] as $key ) {
			if ( in_array( $key, self::CHECKBOXES, true ) ) {
				$values[ $key ] = isset( $raw[ $key ] ); // An absent checkbox is unticked.
			} elseif ( 'taxonomies' === $key ) {
				$values[ $key ] = $this->taxonomy_values( is_array( $raw['taxonomies'] ?? null ) ? $raw['taxonomies'] : [] );
			} elseif ( array_key_exists( $key, $raw ) && is_scalar( $raw[ $key ] ) ) {
				$values[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
			}
		}
		$clean = $this->settings->patch( $values );
		/**
		 * The settings form was saved (through Settings::patch()).
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $clean What was stored (the whole normalised option).
		 * @param array<string, mixed> $raw   The tab's raw, unslashed POST values.
		 */
		do_action( 'bfrc_settings_saved', $clean, $raw );
		$this->redirect( $tab, $this->settings->last_update_succeeded() ? 'saved' : 'failed' );
	}

	/**
	 * `admin_post_bfrc_install_samples`.
	 */
	public function install_samples(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage recipe settings.', 'bluefairy-recipes' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( Samples::ACTION_INSTALL );
		if ( ! current_user_can( 'create_users' ) ) {
			$this->redirect( 'data', 'samples_no_users' );
		}
		$result = $this->samples->install();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'data', 'exists' === $result->get_error_code() ? 'samples_present' : 'samples_failed' );
		}
		$this->redirect( 'data', 'samples_added' );
	}

	/**
	 * `admin_post_bfrc_remove_samples`.
	 */
	public function remove_samples(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage recipe settings.', 'bluefairy-recipes' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( Samples::ACTION_REMOVE );
		$this->samples->remove();
		$this->redirect( 'data', 'samples_removed' );
	}

	/**
	 * The General tab's filter-type rows: enabled and sort order per code.
	 */
	private function taxonomy_rows(): void {
		echo '<tr><th scope="row">' . esc_html__( 'Filter types', 'bluefairy-recipes' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Filter types', 'bluefairy-recipes' ) . '</legend><table class="widefat striped bfrc-filter-types"><thead><tr><th>' . esc_html__( 'Filter type', 'bluefairy-recipes' ) . '</th><th>' . esc_html__( 'Enabled', 'bluefairy-recipes' ) . '</th><th>' . esc_html__( 'Sort order', 'bluefairy-recipes' ) . '</th></tr></thead><tbody>';
		foreach ( $this->taxonomies->definitions() as $code => $def ) {
			echo '<tr><td>' . esc_html( (string) $def['label'] ) . ' <code>' . esc_html( (string) $code ) . '</code></td>';
			echo '<td><input type="checkbox" name="bfrc[taxonomies][' . esc_attr( (string) $code ) . '][enabled]" value="1"' . checked( (bool) $def['enabled'], true, false ) . ' aria-label="' . esc_attr( sprintf( /* translators: %s: filter type name */ __( '%s enabled', 'bluefairy-recipes' ), (string) $def['label'] ) ) . '"></td>';
			echo '<td><input type="number" class="small-text" name="bfrc[taxonomies][' . esc_attr( (string) $code ) . '][sort_order]" value="' . esc_attr( (string) $def['sort_order'] ) . '" min="0" max="999" aria-label="' . esc_attr( sprintf( /* translators: %s: filter type name */ __( '%s sort order', 'bluefairy-recipes' ), (string) $def['label'] ) ) . '"></td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'A disabled filter type keeps its terms and leaves the filter form, the badges and the REST filters.', 'bluefairy-recipes' ) . '</p></fieldset></td></tr>';
	}

	/**
	 * The posted filter-type rows: every code the form showed, enabled
	 * when ticked, sort order as posted (the sanitiser bounds it).
	 *
	 * @param array<mixed> $raw The posted `bfrc[taxonomies]`.
	 * @return array<string, array{enabled: bool, sort_order: mixed}>
	 */
	private function taxonomy_values( array $raw ): array {
		$out = [];
		foreach ( array_keys( $this->taxonomies->definitions() ) as $code ) {
			$row          = is_array( $raw[ $code ] ?? null ) ? $raw[ $code ] : [];
			$out[ $code ] = [
				'enabled'    => isset( $row['enabled'] ),
				'sort_order' => is_scalar( $row['sort_order'] ?? null ) ? (string) $row['sort_order'] : '',
			];
		}
		return $out;
	}

	/**
	 * A checkbox row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Row label.
	 * @param bool   $checked     Current value.
	 * @param string $description Help text ('' for none).
	 */
	private function checkbox( string $key, string $label, bool $checked, string $description ): void {
		echo '<tr><th scope="row"><label for="bfrc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="checkbox" id="bfrc-' . esc_attr( $key ) . '" name="bfrc[' . esc_attr( $key ) . ']" value="1"' . checked( $checked, true, false ) . '>';
		if ( '' !== $description ) {
			echo ' <span class="description">' . esc_html( $description ) . '</span>';
		}
		echo '</td></tr>';
	}

	/**
	 * A text row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Row label.
	 * @param string $value       Current value.
	 * @param string $description Help text ('' for none).
	 */
	private function text( string $key, string $label, string $value, string $description ): void {
		echo '<tr><th scope="row"><label for="bfrc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="bfrc-' . esc_attr( $key ) . '" name="bfrc[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * A number row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Row label.
	 * @param int    $value       Current value.
	 * @param int    $min         Lowest allowed.
	 * @param int    $max         Highest allowed.
	 * @param string $description Help text ('' for none).
	 */
	private function number( string $key, string $label, int $value, int $min, int $max, string $description ): void {
		echo '<tr><th scope="row"><label for="bfrc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" class="small-text" id="bfrc-' . esc_attr( $key ) . '" name="bfrc[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '">';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Back to the page with a notice; never returns.
	 *
	 * @param string $tab    Tab id.
	 * @param string $notice Notice code.
	 */
	private function redirect( string $tab, string $notice ): never {
		wp_safe_redirect( $this->url( $tab, $notice ) );
		exit;
	}
}
