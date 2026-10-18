<?php
/**
 * Activation, deactivation and version upgrades.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No tables and no cron: the content lives in posts, terms, meta and users.
 * Activation creates the roles, grants the core roles (when the setting says
 * so), writes the default settings and version markers, and flags a rewrite
 * flush for the first `init` on which the post type exists.
 *
 * Deactivation flushes rewrite rules and nothing else: the roles stay,
 * because removing them would strip every writer of their role, and that is
 * data, not configuration. uninstall.php removes the roles.
 */
final class Installer {

	/**
	 * Options this plugin creates. uninstall.php holds a copy.
	 *
	 * @var list<string>
	 */
	public const OPTIONS = [
		'bfrc_settings',
		'bfrc_db_version',
		'bfrc_version',
	];

	/**
	 * Transients this plugin creates. uninstall.php holds a copy.
	 *
	 * @var list<string>
	 */
	public const TRANSIENTS = [
		Settings::FLUSH_TRANSIENT,
	];

	/**
	 * Activation hook. A network-wide activation provisions every site;
	 * otherwise only the current one. (Network activation is not a tested
	 * configuration in 1.0; the readme says so. It is handled rather than
	 * refused so a network that does it is not left half-installed.)
	 *
	 * @param bool $network_wide True when activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::each_site( $network_wide, [ self::class, 'provision_site' ] );
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide True when deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		self::each_site( $network_wide, [ self::class, 'unprovision_site' ] );
	}

	/**
	 * Everything one site needs. Idempotent: an existing `bfrc_settings` is
	 * never rewritten, so a re-activation keeps the administrator's choices.
	 */
	public static function provision_site(): void {
		Capabilities::add_roles();
		if ( ! get_option( Settings::OPTION ) ) {
			update_option( Settings::OPTION, Settings::DEFAULTS, true );
		}
		if ( ( new Settings() )->get( 'grant_core_roles' ) ) {
			Capabilities::grant_core_roles();
		}
		update_option( 'bfrc_version', BFRC_VERSION, true );
		update_option( 'bfrc_db_version', BFRC_DB_VERSION, true );
		set_transient( Settings::FLUSH_TRANSIENT, 1, DAY_IN_SECONDS );
	}

	/**
	 * Deactivation for one site: the rewrite rules this plugin added go,
	 * so `/recipes/…` stops resolving at once rather than 404-ing inside a
	 * plugin that is no longer there. Roles, settings and content stay.
	 */
	public static function unprovision_site(): void {
		delete_transient( Settings::FLUSH_TRANSIENT );
		flush_rewrite_rules( false );
	}

	/**
	 * Called on every boot. A site with no version at all (a sub-site the
	 * activation hook never ran on) is provisioned in full; a site whose
	 * version is behind gets its data steps and the roles re-asserted.
	 * `bfrc_version` (the plugin version, as distinct from the data version)
	 * is brought up to date whenever it differs, so a code-only release
	 * still leaves the right number behind.
	 */
	public static function maybe_upgrade(): void {
		$current = get_option( 'bfrc_db_version', '' );
		$missing = '' === $current || false === $current;
		if ( ! $missing && BFRC_VERSION !== (string) get_option( 'bfrc_version', '' ) ) {
			update_option( 'bfrc_version', BFRC_VERSION, true );
		}
		if ( ! $missing && version_compare( (string) $current, BFRC_DB_VERSION, '>=' ) ) {
			return;
		}
		if ( $missing ) {
			self::provision_site();
			return;
		}
		self::upgrade_data( (string) $current );
		Capabilities::add_roles();
		update_option( 'bfrc_db_version', BFRC_DB_VERSION, true );
	}

	/**
	 * One-off data steps between data versions, run before the new version
	 * is recorded. None yet: 1.0.0 is the first data version. Settings are
	 * NOT migrated here and provision_site() never rewrites an existing
	 * `bfrc_settings`, so a changed default reaches new installs only; a
	 * default existing sites must follow gets a version-gated step here that
	 * rewrites that one key where the stored value is still the old default.
	 *
	 * @param string $from The data version the site is upgrading from.
	 */
	public static function upgrade_data( string $from ): void {
		unset( $from );
	}

	/**
	 * Hooked late on `init`: flush rewrite rules once when the flag is set
	 * (activation, or a changed archive slug). Late, so the post type and
	 * taxonomies registered on `init` are part of the rules written.
	 */
	public static function maybe_flush_rewrite(): void {
		if ( ! get_transient( Settings::FLUSH_TRANSIENT ) ) {
			return;
		}
		delete_transient( Settings::FLUSH_TRANSIENT );
		flush_rewrite_rules( false );
	}

	/**
	 * Run $callback on every site of the network when $network_wide and
	 * this is a multisite install, else on the current site only.
	 *
	 * @param bool     $network_wide Whether the hook fired for the whole network.
	 * @param callable $callback     What to run per site.
	 */
	private static function each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();
			return;
		}
		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$callback();
			} finally {
				restore_current_blog(); // A callback that throws must not leave the request on another site.
			}
		}
	}
}
