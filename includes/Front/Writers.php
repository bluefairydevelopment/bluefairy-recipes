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
 * order, and bounded: at most LIMIT writers are ever considered.
 */
final class Writers {

	public const PER_PAGE  = 24;
	public const LIMIT     = 500;
	public const QUERY_VAR = 'bfrc_writer';
	public const LIST_VAR  = 'bfrc_writers';
	public const SEGMENT   = 'chef';

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
	 * `{archive}/chef/...` never matches a recipe slug.
	 */
	public function add_rewrite_rules(): void {
		if ( ! (bool) $this->settings->get( 'enabled' ) ) {
			return;
		}
		$base = preg_quote( $this->archive_slug(), '#' ) . '/' . self::SEGMENT;
		$type = 'post_type=' . PostType::NAME;
		add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . $type . '&' . self::LIST_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . $base . '/page/([0-9]{1,})/?$', 'index.php?' . $type . '&' . self::LIST_VAR . '=1&paged=$matches[1]', 'top' );
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
	 * display-name order, one page of PER_PAGE. Two bounded user queries
	 * (the Writer role, and anyone who switched the listing on), one
	 * `count_many_users_posts()`.
	 *
	 * @param int $page 1-based page.
	 * @return array{writers: array<int, array{user: \WP_User, count: int}>, total: int, pages: int}
	 */
	public function listed( int $page = 1 ): array {
		$page     = max( 1, $page );
		$by_role  = new \WP_User_Query(
			[
				'role__in'   => [ Capabilities::ROLE_WRITER ],
				'number'     => self::LIMIT,
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'fields'     => 'all',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the listing is bounded by `number` and cached by core per request.
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
				'number'     => self::LIMIT,
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'fields'     => 'all',
				'meta_key'   => WriterProfile::LISTED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded by `number`.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			]
		);
		$users    = [];
		foreach ( array_merge( (array) $by_role->get_results(), (array) $opted_in->get_results() ) as $user ) {
			if ( $user instanceof \WP_User ) {
				$users[ $user->ID ] = $user;
			}
		}
		if ( [] === $users ) {
			return [
				'writers' => [],
				'total'   => 0,
				'pages'   => 0,
			];
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
		$total = count( $writers );
		$pages = (int) ceil( $total / self::PER_PAGE );
		return [
			'writers' => array_slice( $writers, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total'   => $total,
			'pages'   => $pages,
		];
	}

	/**
	 * The writer for a nicename, when they have a public page.
	 *
	 * @param string $nicename The URL segment.
	 */
	public function public_writer( string $nicename ): ?\WP_User {
		$user = get_user_by( 'slug', $nicename );
		return $user instanceof \WP_User && $this->is_public( $user->ID ) ? $user : null;
	}

	/**
	 * The writer profile fields for display (ids and strings, unsanitised
	 * HTML already kses'd on save).
	 *
	 * @param int $user_id The writer.
	 * @return array{avatar_id: int, header_id: int, about: string, links: array<string, string>}
	 */
	public static function profile( int $user_id ): array {
		$links = [];
		foreach ( WriterProfile::url_labels() as $key => $label ) {
			$url = (string) get_user_meta( $user_id, $key, true );
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
	 * The service instance.
	 */
	public static function instance(): ?self {
		$service = Plugin::service( 'front.writers' );
		return $service instanceof self ? $service : null;
	}
}
