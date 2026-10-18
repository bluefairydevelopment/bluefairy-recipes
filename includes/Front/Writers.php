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
 * order, and bounded: at most LIMIT writers are ever considered. Results
 * are memoised for the request, so the guard, the title and the view ask
 * once.
 *
 * The opt-out hides a writer from the plugin's own pages and from core's
 * users sitemap; core's author archive and REST users list are core's, and
 * list the author of any public post type.
 */
final class Writers {

	public const PER_PAGE  = 24;
	public const LIMIT     = 500;
	public const QUERY_VAR = 'bfrc_writer';
	public const LIST_VAR  = 'bfrc_writers';
	public const SEGMENT   = 'chef';
	/** WP_User_Query flag read by exclude_opted_out(). */
	public const NOT_OPTED_OUT = 'bfrc_not_opted_out';

	/**
	 * Memo of public_writer() per nicename, per request.
	 *
	 * @var array<string, \WP_User|null>
	 */
	private array $by_nicename = [];
	/**
	 * Every public writer, name order, once per request.
	 *
	 * @var array<int, array{user: \WP_User, count: int}>|null
	 */
	private ?array $all_listed = null;

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
		$feeds = '(feed|rdf|rss|rss2|atom)';
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
	 * display-name order, one page of PER_PAGE. Two bounded id-only user
	 * queries (the Writer role, and anyone who switched the listing on),
	 * one `count_many_users_posts()`, then only the page's writers are
	 * loaded as full `WP_User` objects (one `cache_users()`, then
	 * `get_userdata()` from the cache). Computed once per request.
	 *
	 * @param int $page 1-based page.
	 * @return array{writers: array<int, array{user: \WP_User, count: int}>, total: int, pages: int}
	 */
	public function listed( int $page = 1 ): array {
		$page  = max( 1, $page );
		$all   = $this->all_listed();
		$total = count( $all );
		$pages = (int) ceil( $total / self::PER_PAGE );
		$slice = array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		if ( [] !== $slice ) {
			cache_users( array_map( static fn( array $w ): int => $w['user']->ID, $slice ) );
			foreach ( $slice as $i => $w ) {
				$user = get_userdata( $w['user']->ID );
				if ( $user instanceof \WP_User ) {
					$slice[ $i ]['user'] = $user; // The id-only row becomes the real user: email, login, caps, every field a template may ask for.
				}
			}
		}
		return [
			'writers' => $slice,
			'total'   => $total,
			'pages'   => $pages,
		];
	}

	/**
	 * Every public writer with their count, in display-name order (ids and
	 * display names only until a page is sliced). Each query takes its first
	 * LIMIT users by display name before the recipe counts are checked, so a
	 * site with more than LIMIT Writer-role users (or opt-ins) can leave some
	 * writers with recipes off the listing; their own pages still work.
	 *
	 * @return array<int, array{user: \WP_User, count: int}>
	 */
	private function all_listed(): array {
		if ( null !== $this->all_listed ) {
			return $this->all_listed;
		}
		$by_role  = new \WP_User_Query(
			[
				'role__in'          => [ Capabilities::ROLE_WRITER ],
				'number'            => self::LIMIT,
				'orderby'           => 'display_name',
				'order'             => 'ASC',
				'fields'            => [ 'ID', 'display_name', 'user_nicename' ],
				'count_total'       => false,
				self::NOT_OPTED_OUT => true, // exclude_opted_out(): never a meta_query here (see that method).
			]
		);
		$opted_in = new \WP_User_Query(
			[
				'number'      => self::LIMIT,
				'orderby'     => 'display_name',
				'order'       => 'ASC',
				'fields'      => [ 'ID', 'display_name', 'user_nicename' ],
				'count_total' => false,
				'meta_key'    => WriterProfile::LISTED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded by `number`.
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			]
		);
		$users    = [];
		foreach ( array_merge( (array) $by_role->get_results(), (array) $opted_in->get_results() ) as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->ID ) ) {
				continue;
			}
			$user                = new \WP_User();
			$user->ID            = (int) $row->ID;
			$user->display_name  = (string) ( $row->display_name ?? '' );
			$user->user_nicename = (string) ( $row->user_nicename ?? '' );
			$users[ $user->ID ]  = $user;
		}
		if ( [] === $users ) {
			$this->all_listed = [];
			return [];
		}
		$counts  = count_many_users_posts( array_keys( $users ), PostType::NAME, true );
		$writers = [];
		foreach ( $users as $id => $user ) {
			$count = (int) ( $counts[ $id ] ?? 0 );
			if ( $count > 0 ) {
				$writers[] = [
					'user'  => $user,
					'count' => $count,
				];
			}
		}
		usort(
			$writers,
			static function ( array $a, array $b ): int {
				$by_name = strcasecmp( $a['user']->display_name, $b['user']->display_name );
				return 0 !== $by_name ? $by_name : $a['user']->ID <=> $b['user']->ID;
			}
		);
		$this->all_listed = $writers;
		return $writers;
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
		$this->all_listed  = null;
	}

	/**
	 * The service instance.
	 */
	public static function instance(): ?self {
		$service = Plugin::service( 'front.writers' );
		return $service instanceof self ? $service : null;
	}
}
