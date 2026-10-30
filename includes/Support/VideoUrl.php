<?php
/**
 * Recipe video URLs: YouTube and Vimeo only, by id.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A video URL is accepted only when it names a YouTube or Vimeo video the
 * plugin can identify. What is stored is the canonical watch URL; what is
 * rendered is an iframe built from the id (and, for an unlisted Vimeo video,
 * its hash) alone, so no server-side fetch (no oEmbed) ever happens and
 * nothing but those tokens reaches the page.
 */
final class VideoUrl {

	public const YOUTUBE = 'youtube';
	public const VIMEO   = 'vimeo';

	private const YOUTUBE_HOSTS = [ 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com' ];
	private const VIMEO_HOSTS   = [ 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ];

	/**
	 * The provider, id and (Vimeo, unlisted) hash of a URL, or null when it is
	 * not a YouTube or Vimeo video.
	 *
	 * @param mixed $url Raw input.
	 * @return array{provider: string, id: string, hash: string}|null
	 */
	public static function parse( mixed $url ): ?array {
		if ( ! is_string( $url ) ) {
			return null;
		}
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		$path = (string) ( $parts['path'] ?? '' );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		if ( 'youtu.be' === $host ) {
			return self::youtube( ltrim( $path, '/' ) );
		}
		if ( in_array( $host, self::YOUTUBE_HOSTS, true ) ) {
			if ( isset( $query['v'] ) && is_string( $query['v'] ) ) {
				return self::youtube( $query['v'] );
			}
			if ( 1 === preg_match( '#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m ) ) {
				return self::youtube( $m[1] );
			}
			return null;
		}
		if ( in_array( $host, self::VIMEO_HOSTS, true ) ) {
			// A video id (6–12 digits) is accepted only in a video position: /ID, /video/ID,
			// /channels/x/ID, /groups/x/videos/ID, /album/N/video/ID. An album, showcase, user or
			// on-demand number is not a video. An unlisted video carries a hash as the following
			// segment (/ID/hash) or as ?h=hash.
			if ( 1 === preg_match( '#^/(?:video/|channels/[^/]+/|groups/[^/]+/videos/|album/\d+/video/)?(\d{6,12})(?:/([A-Za-z0-9]{6,32}))?/?$#', $path, $m ) ) {
				$hash = $m[2] ?? '';
				if ( '' === $hash && isset( $query['h'] ) && is_string( $query['h'] ) && 1 === preg_match( '/^[A-Za-z0-9]{6,32}$/D', $query['h'] ) ) {
					$hash = $query['h'];
				}
				return [
					'provider' => self::VIMEO,
					'id'       => $m[1],
					'hash'     => $hash,
				];
			}
		}
		return null;
	}

	/**
	 * The canonical URL to store, or '' when the input is not a supported video.
	 *
	 * @param mixed $url Raw input.
	 */
	public static function normalise( mixed $url ): string {
		$video = self::parse( $url );
		if ( null === $video ) {
			return '';
		}
		if ( self::YOUTUBE === $video['provider'] ) {
			return 'https://www.youtube.com/watch?v=' . $video['id'];
		}
		return 'https://vimeo.com/' . $video['id'] . ( '' !== $video['hash'] ? '/' . $video['hash'] : '' );
	}

	/**
	 * The iframe src for a stored URL, or '' when it does not parse. Built from
	 * the validated tokens only; YouTube through its no-cookie host, Vimeo
	 * with `dnt=1` (Vimeo's do-not-track flag: its player sets no tracking cookies).
	 *
	 * @param string $url A stored (normalised) URL.
	 */
	public static function embed_url( string $url ): string {
		$video = self::parse( $url );
		if ( null === $video ) {
			return '';
		}
		if ( self::YOUTUBE === $video['provider'] ) {
			return 'https://www.youtube-nocookie.com/embed/' . $video['id'];
		}
		return 'https://player.vimeo.com/video/' . $video['id'] . ( '' !== $video['hash'] ? '?h=' . $video['hash'] . '&dnt=1' : '?dnt=1' );
	}

	/**
	 * A YouTube id is exactly 11 characters of [A-Za-z0-9_-].
	 *
	 * @param string $candidate The id as found in the URL.
	 * @return array{provider: string, id: string, hash: string}|null
	 */
	private static function youtube( string $candidate ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{11}$/D', $candidate ) ) {
			return null;
		}
		return [
			'provider' => self::YOUTUBE,
			'id'       => $candidate,
			'hash'     => '',
		];
	}
}
