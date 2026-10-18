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
		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . $type . '&' . self::LIST_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . $base . '/page/([0-9]{1,})/?$', 'index.php?' . $type . '&' . self::LIST_VAR . '=1&paged=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/feed/' . $feeds . '/?$', 'index.php?' . $type . '&author_name=$matches[1]&' . self::QUERY_VAR . '=1&feed=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/' . $feeds . '/?$', 'index.php?' . $type . '&author_name=$matches[1]&' . self::QUERY_VAR . '=1&feed=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/page/([0-9]{1,})/?$', 'index.php?' . $type . '&author_name=$matches[1]&' . self::QUERY_VAR . '=1&paged=$matches[2]', 'top' );
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
	 * opted out of the listing is left out of it too.
	 *
	 * @param array<string, mixed> $args The user query arguments.
	 * @return array<string, mixed>
	 */
	public function sitemap_users_query( array $args ): array {
		$existing           = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : [];
		$existing[]         = [
			'relation' => 'OR',
			[
				'key'     => WriterProfile::LISTED,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => WriterProfile::LISTED,
				'value'   => '0',
				'compare' => '!=',
			],
		];
		$args['meta_query'] = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- core's own sitemap query, already bounded by its page size.
		return $args;
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
	 * The listing URL.
	 */
	public function listing_url(): string {
		return home_url( user_trailingslashit( '/' . $this->archive_slug() . '/' . self::SEGMENT ) );
	}

	/**
	 * A writer's page URL.
	 *
	 * @param \WP_User $user The writer.
	 */
	public function url( \WP_User $user ): string {
		return home_url( user_trailingslashit( '/' . $this->archive_slug() . '/' . self::SEGMENT . '/' . rawurlencode( $user->user_nicename ) ) );
	}

	/**
	 * The public writers: listed, with at least one published recipe, in
	 * display-name order, one page of PER_PAGE. Two bounded id-only user
	 * queries (the Writer role, and anyone who switched the listing on),
	 * one `count_many_users_posts()`, then only the page's writers are
	 * loaded as objects. Computed once per request.
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
		}
		return [
			'writers' => $slice,
			'total'   => $total,
			'pages'   => $pages,
		];
	}

	/**
	 * Every public writer with their count, in display-name order (ids and
	 * display names only until a page is sliced).
	 *
	 * @return array<int, array{user: \WP_User, count: int}>
	 */
	private function all_listed(): array {
		if ( null !== $this->all_listed ) {
			return $this->all_listed;
		}
		$by_role  = new \WP_User_Query(
			[
				'role__in'    => [ Capabilities::ROLE_WRITER ],
				'number'      => self::LIMIT,
				'orderby'     => 'display_name',
				'order'       => 'ASC',
				'fields'      => [ 'ID', 'display_name', 'user_nicename' ],
				'count_total' => false,
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by `number`; the listing is one page of 24.
					'relation' => 'OR',
					[
						'key'     => WriterProfile::LISTED,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => WriterProfile::LISTED,
						'value'   => '0',
						'compare' => '!=',
					],
				],
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
