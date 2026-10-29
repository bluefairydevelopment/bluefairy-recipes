<?php
/**
 * The public writer rules: who has a page, who is listed, and their URLs.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Front;

use BlueFairy\Recipes\Admin\WriterProfile;
use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Cache;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A public writer page exists only for a user who is listed
 * (`WriterProfile::is_listed()`: the Writer role by default, anyone else by
 * their own switch) AND has at least one published recipe. Everyone else's
 * URL is a 404. The listing at `{archive}/chef/` is paginated at 24, name
 * order, one page per query (LIMIT/OFFSET in SQL, whatever the number of
 * writers). Results are memoised for the request, so the guard, the title
 * and the view ask once, and cached across requests (see listed()).
 *
 * The opt-out hides a writer from the plugin's own pages and from core's
 * users sitemap; core's author archive and REST users list are core's, and
 * list the author of any public post type.
 */
final class Writers {

	public const PER_PAGE  = 24;
	public const QUERY_VAR = 'bfrc_writer';
	public const LIST_VAR  = 'bfrc_writers';
	public const SEGMENT   = 'chef';
	/** WP_User_Query flag read by exclude_opted_out(). */
	public const NOT_OPTED_OUT = 'bfrc_not_opted_out';
	/** Incrementor group of the cached listing: bumped when a user's role, listing switch, name or account changes. */
	public const LAST_CHANGED_GROUP = 'bfrc_writers';
	/** How long the cached listing lives at most (a role granted outside core's hooks shows within this). */
	public const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Memo of public_writer() per nicename, per request.
	 *
	 * @var array<string, \WP_User|null>
	 */
	private array $by_nicename = [];
	/**
	 * Pages of listed() already built this request, by page.
	 *
	 * @var array<int, array{writers: array<int, array{user: \WP_User, count: int}>, total: int, pages: int}>
	 */
	private array $pages = [];

	/**
	 * Wire the dependencies.
	 *
	 * @param Settings $settings The settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * The writer rules core must know about on every request: the opt-out
	 * applied to core's users sitemap (whether or not the plugin is enabled:
	 * the writer asked not to be listed), and the `NOT EXISTS` clause behind
	 * it and the listing.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_sitemaps_users_query_args', [ $this, 'sitemap_users_query' ] );
		add_action( 'pre_user_query', [ $this, 'exclude_opted_out' ] );
		// remove_user_from_blog: multisite Users > Remove deletes the capabilities meta directly, so no role hook fires.
		foreach ( [ 'set_user_role', 'add_user_role', 'remove_user_role', 'profile_update', 'user_register', 'deleted_user', 'remove_user_from_blog' ] as $hook ) {
			add_action( $hook, [ self::class, 'bump' ] );
		}
		foreach ( [ 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_user_meta' ], 10, 3 );
		}
	}

	/**
	 * Start a new generation of the cached listing.
	 */
	public static function bump(): void {
		wp_cache_set_last_changed( self::LAST_CHANGED_GROUP );
	}

	/**
	 * A user meta write: the listing switch changes who is listed.
	 *
	 * @param mixed  $meta_id  Meta id(s).
	 * @param mixed  $user_id  The user.
	 * @param string $meta_key The key written.
	 */
	public static function on_user_meta( mixed $meta_id, mixed $user_id, string $meta_key ): void {
		if ( WriterProfile::LISTED === $meta_key ) {
			self::bump();
		}
	}

	/**
	 * Are pretty permalinks on? Without them no rewrite rule runs, so the
	 * writer views exist only in their query-string form.
	 */
	public static function uses_permalinks(): bool {
		global $wp_rewrite;
		return ! ( $wp_rewrite instanceof \WP_Rewrite ) || $wp_rewrite->using_permalinks();
	}

	/**
	 * The feed formats core serves (`$wp_rewrite->feeds`: feed, rdf, rss,
	 * rss2, atom, plus any `add_feed()`); the writer routes and the feed
	 * redirect use the same list.
	 *
	 * @return list<string>
	 */
	public static function feed_formats(): array {
		global $wp_rewrite;
		$feeds = $wp_rewrite instanceof \WP_Rewrite && is_array( $wp_rewrite->feeds ) ? array_values( array_filter( $wp_rewrite->feeds, 'is_string' ) ) : [];
		return [] !== $feeds ? $feeds : [ 'feed', 'rdf', 'rss', 'rss2', 'atom' ];
	}

	/**
	 * Core's pagination segment (`page` unless the site changed it).
	 */
	public static function pagination_base(): string {
		global $wp_rewrite;
		$base = $wp_rewrite instanceof \WP_Rewrite ? (string) $wp_rewrite->pagination_base : '';
		return '' !== $base ? $base : 'page';
	}

	/**
	 * The archive slug in force.
	 */
	public function archive_slug(): string {
		return (string) $this->settings->get( 'archive_slug' );
	}

	/**
	 * Is the public listing on, and the plugin enabled?
	 */
	public function listing_enabled(): bool {
		return (bool) $this->settings->get( 'enabled' ) && (bool) $this->settings->get( 'writers_listing' );
	}

	/**
	 * Add the writer routes before the post type's rules (`top`), so
	 * `{archive}/chef/...` never matches a recipe slug. Feeds of a writer's
	 * recipes are routed too, so core serves the author feed of the type.
	 */
	public function add_rewrite_rules(): void {
		if ( ! (bool) $this->settings->get( 'enabled' ) ) {
			return;
		}
		$base  = preg_quote( $this->archive_slug(), '#' ) . '/' . self::SEGMENT;
		$type  = 'post_type=' . PostType::NAME;
		$feeds = '(' . implode( '|', array_map( static fn( string $f ): string => preg_quote( $f, '#' ), self::feed_formats() ) ) . ')';
		$paged = preg_quote( self::pagination_base(), '#' ) . '/([0-9]{1,})/?$';
		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . $type . '&' . self::LIST_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . $base . '/' . $paged, 'index.php?' . $type . '&' . self::LIST_VAR . '=1&paged=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/feed/' . $feeds . '/?$', 'index.php?' . $type . '&author_name=$matches[1]&' . self::QUERY_VAR . '=1&feed=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/' . $feeds . '/?$', 'index.php?' . $type . '&author_name=$matches[1]&' . self::QUERY_VAR . '=1&feed=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/' . $paged, 'index.php?' . $type . '&author_name=$matches[1]&' . self::QUERY_VAR . '=1&paged=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/?$', 'index.php?' . $type . '&author_name=$matches[1]&' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Register the two query vars.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::LIST_VAR;
		return $vars;
	}

	/**
	 * Core's users sitemap lists authors of public post types; a writer who
	 * opted out of the listing is left out of it too, through the same
	 * `NOT EXISTS` clause as the listing (`exclude_opted_out()`), so another
	 * plugin's `meta_query` on the sitemap is left exactly as it was.
	 *
	 * @param array<string, mixed> $args The user query arguments.
	 * @return array<string, mixed>
	 */
	public function sitemap_users_query( array $args ): array {
		$args[ self::NOT_OPTED_OUT ] = true;
		return $args;
	}

	/**
	 * `pre_user_query`: a user query flagged `bfrc_not_opted_out` leaves out
	 * everyone whose listing switch is stored as `'0'`, with one correlated
	 * `NOT EXISTS` on the key. The same rule as a `meta_query` of
	 * "absent OR != '0'", which core builds as two usermeta joins that are
	 * not keyed on `meta_key`, so every user fans out to all their meta rows
	 * (seconds on a site with tens of thousands of users).
	 *
	 * @param \WP_User_Query $query The query being prepared.
	 */
	public function exclude_opted_out( \WP_User_Query $query ): void {
		if ( ! (bool) $query->get( self::NOT_OPTED_OUT ) ) {
			return;
		}
		global $wpdb;
		$query->query_where .= $wpdb->prepare(
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} bfrc_lm WHERE bfrc_lm.user_id = {$wpdb->users}.ID AND bfrc_lm.meta_key = %s AND bfrc_lm.meta_value = '0' )",
			WriterProfile::LISTED
		);
	}

	/**
	 * Does this user have a public writer page?
	 *
	 * @param int $user_id The user.
	 */
	public function is_public( int $user_id ): bool {
		if ( $user_id <= 0 || ! (bool) $this->settings->get( 'enabled' ) || ! WriterProfile::is_listed( $user_id ) ) {
			return false;
		}
		$counts = count_many_users_posts( [ $user_id ], PostType::NAME, true );
		return ( (int) ( $counts[ $user_id ] ?? 0 ) ) > 0;
	}

	/**
	 * The listing URL (`{archive}/chef/`; the query-string form under plain
	 * permalinks, as core's `get_post_type_archive_link()` does).
	 */
	public function listing_url(): string {
		if ( ! self::uses_permalinks() ) {
			return add_query_arg(
				[
					'post_type'    => PostType::NAME,
					self::LIST_VAR => '1',
				],
				home_url( '/' )
			);
		}
		return home_url( user_trailingslashit( '/' . $this->archive_slug() . '/' . self::SEGMENT ) );
	}

	/**
	 * A writer's page URL.
	 *
	 * @param \WP_User $user The writer.
	 */
	public function url( \WP_User $user ): string {
		if ( ! self::uses_permalinks() ) {
			return add_query_arg(
				[
					'post_type'     => PostType::NAME,
					'author_name'   => rawurlencode( $user->user_nicename ),
					self::QUERY_VAR => '1',
				],
				home_url( '/' )
			);
		}
		return home_url( user_trailingslashit( '/' . $this->archive_slug() . '/' . self::SEGMENT . '/' . rawurlencode( $user->user_nicename ) ) );
	}

	/**
	 * The public writers: listed, with at least one published recipe, in
	 * display-name order (case-insensitive, then id), one page of PER_PAGE.
	 * One query for the page (LIMIT/OFFSET in SQL, so a site with any number
	 * of writers lists them all) and one COUNT, both starting from the
	 * published recipes grouped by author; then only the page's writers are
	 * loaded as full `WP_User` objects (one `cache_users()`). The page is
	 * memoised for the request and cached across requests under both
	 * incrementors: a recipe published or unpublished changes the counts, a
	 * role or switch changes who is in.
	 *
	 * @param int $page 1-based page.
	 * @return array{writers: array<int, array{user: \WP_User, count: int}>, total: int, pages: int}
	 */
	public function listed( int $page = 1 ): array {
		$page = max( 1, $page );
		if ( isset( $this->pages[ $page ] ) ) {
			return $this->pages[ $page ];
		}
		$key  = 'writers:' . $page . ':' . wp_cache_get_last_changed( self::LAST_CHANGED_GROUP ) . ':' . Cache::last_changed();
		$rows = wp_cache_get( $key, Cache::GROUP );
		if ( ! is_array( $rows ) || ! isset( $rows['total'], $rows['rows'] ) ) {
			$rows = $this->query_page( $page );
			if ( 1 === $page || [] !== $rows['rows'] ) { // A page past the last writer is not kept: any number a visitor types would be.
				wp_cache_set( $key, $rows, Cache::GROUP, self::CACHE_TTL );
			}
		}
		$total   = (int) $rows['total'];
		$writers = [];
		$ids     = array_map( static fn( array $r ): int => (int) $r[0], (array) $rows['rows'] );
		if ( [] !== $ids ) {
			cache_users( $ids );
		}
		foreach ( (array) $rows['rows'] as $r ) {
			$user = get_userdata( (int) $r[0] );
			if ( ! $user instanceof \WP_User ) { // Deleted since the page was cached: an id-only row keeps the page's shape.
				$user                = new \WP_User();
				$user->ID            = (int) $r[0];
				$user->display_name  = (string) $r[1];
				$user->user_nicename = (string) $r[2];
			}
			$writers[] = [
				'user'  => $user,
				'count' => (int) $r[3],
			];
		}
		$this->pages[ $page ] = [
			'writers' => $writers,
			'total'   => $total,
			'pages'   => (int) ceil( $total / self::PER_PAGE ),
		];
		return $this->pages[ $page ];
	}

	/**
	 * One page of public writers from the database, and how many there are.
	 *
	 * @param int $page 1-based page.
	 * @return array{total: int, rows: list<array{0: int, 1: string, 2: string, 3: int}>}
	 */
	private function query_page( int $page ): array {
		global $wpdb;
		$role = '%' . $wpdb->esc_like( '"' . Capabilities::ROLE_WRITER . '"' ) . '%'; // As core's role__in matches it (WP_User::$roles keeps a role whatever its stored value).
		$caps = $wpdb->get_blog_prefix() . 'capabilities';
		$key  = WriterProfile::LISTED;
		$type = PostType::NAME;
		// The same FROM/WHERE twice, written out so every value is a placeholder: published recipes by
		// author, joined to the users; listed when the FIRST bfrc_listed row (as get_user_meta( …, true )
		// reads it) is '1', or is not '0' and the user holds the Writer role on this site.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one page and one count, cached by listed().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT post_author AS id, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' GROUP BY post_author ) r INNER JOIN {$wpdb->users} u ON u.ID = r.id WHERE ( COALESCE( ( SELECT lm.meta_value FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = %s ORDER BY lm.umeta_id LIMIT 1 ), '' ) = '1' OR ( COALESCE( ( SELECT lm.meta_value FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = %s ORDER BY lm.umeta_id LIMIT 1 ), '' ) <> '0' AND EXISTS ( SELECT 1 FROM {$wpdb->usermeta} c WHERE c.user_id = u.ID AND c.meta_key = %s AND c.meta_value LIKE %s ) ) )",
				$type,
				$key,
				$key,
				$caps,
				$role
			)
		);
		$found = $total > ( $page - 1 ) * self::PER_PAGE ? $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.display_name, u.user_nicename, r.n FROM ( SELECT post_author AS id, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' GROUP BY post_author ) r INNER JOIN {$wpdb->users} u ON u.ID = r.id WHERE ( COALESCE( ( SELECT lm.meta_value FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = %s ORDER BY lm.umeta_id LIMIT 1 ), '' ) = '1' OR ( COALESCE( ( SELECT lm.meta_value FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = %s ORDER BY lm.umeta_id LIMIT 1 ), '' ) <> '0' AND EXISTS ( SELECT 1 FROM {$wpdb->usermeta} c WHERE c.user_id = u.ID AND c.meta_key = %s AND c.meta_value LIKE %s ) ) ) ORDER BY u.display_name ASC, u.ID ASC LIMIT %d OFFSET %d",
				$type,
				$key,
				$key,
				$caps,
				$role,
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			)
		) : [];
		// phpcs:enable
		$rows = [];
		foreach ( is_array( $found ) ? $found : [] as $row ) {
			if ( is_object( $row ) && isset( $row->ID ) ) {
				$rows[] = [ (int) $row->ID, (string) ( $row->display_name ?? '' ), (string) ( $row->user_nicename ?? '' ), (int) ( $row->n ?? 0 ) ];
			}
		}
		return [
			'total' => $total,
			'rows'  => $rows,
		];
	}


	/**
	 * The writer for a nicename, when they have a public page (memoised).
	 *
	 * @param string $nicename The URL segment.
	 */
	public function public_writer( string $nicename ): ?\WP_User {
		if ( ! array_key_exists( $nicename, $this->by_nicename ) ) {
			$user                           = get_user_by( 'slug', $nicename );
			$this->by_nicename[ $nicename ] = $user instanceof \WP_User && $this->is_public( $user->ID ) ? $user : null;
		}
		return $this->by_nicename[ $nicename ];
	}

	/**
	 * The writer profile fields for display (ids and strings, HTML already
	 * kses'd on save; links re-checked for http/https at render).
	 *
	 * @param int $user_id The writer.
	 * @return array{avatar_id: int, header_id: int, about: string, links: array<string, string>}
	 */
	public static function profile( int $user_id ): array {
		$links = [];
		foreach ( WriterProfile::url_labels() as $key => $label ) {
			$url = esc_url_raw( (string) get_user_meta( $user_id, $key, true ), [ 'http', 'https' ] );
			if ( '' !== $url ) {
				$links[ $key ] = $url;
			}
		}
		return [
			'avatar_id' => (int) get_user_meta( $user_id, WriterProfile::AVATAR_ID, true ),
			'header_id' => (int) get_user_meta( $user_id, WriterProfile::HEADER_ID, true ),
			'about'     => (string) get_user_meta( $user_id, WriterProfile::ABOUT, true ),
			'links'     => $links,
		];
	}

	/**
	 * Forget the request memos (tests, and long-running processes).
	 */
	public function reset(): void {
		$this->by_nicename = [];
		$this->pages       = [];
	}

	/**
	 * The service instance.
	 */
	public static function instance(): ?self {
		$service = Plugin::service( 'front.writers' );
		return $service instanceof self ? $service : null;
	}
}
