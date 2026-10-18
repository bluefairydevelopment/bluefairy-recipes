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
 * rendered is an iframe built from the id alone, so no server-side fetch
 * (no oEmbed) ever happens and nothing but the id reaches the page.
 */
final class VideoUrl {

	public const YOUTUBE = 'youtube';
	public const VIMEO   = 'vimeo';

	private const YOUTUBE_HOSTS = [ 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com' ];
	private const VIMEO_HOSTS   = [ 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ];

	/**
	 * The provider and id of a URL, or null when it is not a YouTube or Vimeo video.
	 *
	 * @param mixed $url Raw input.
	 * @return array{provider: string, id: string}|null
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
			if ( 1 === preg_match( '#^/(?:video/)?(\d{6,12})(?:/|$)#', $path, $m ) ) {
				return [
					'provider' => self::VIMEO,
					'id'       => $m[1],
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
		return self::YOUTUBE === $video['provider']
			? 'https://www.youtube.com/watch?v=' . $video['id']
			: 'https://vimeo.com/' . $video['id'];
	}

	/**
	 * The iframe src for a stored URL, or '' when it does not parse. Built from
	 * the validated id only; YouTube through its no-cookie host.
	 *
	 * @param string $url A stored (normalised) URL.
	 */
	public static function embed_url( string $url ): string {
		$video = self::parse( $url );
		if ( null === $video ) {
			return '';
		}
		return self::YOUTUBE === $video['provider']
			? 'https://www.youtube-nocookie.com/embed/' . $video['id']
			: 'https://player.vimeo.com/video/' . $video['id'];
	}

	/**
	 * A YouTube id is exactly 11 characters of [A-Za-z0-9_-].
	 *
	 * @param string $candidate The id as found in the URL.
	 * @return array{provider: string, id: string}|null
	 */
	private static function youtube( string $candidate ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{11}$/D', $candidate ) ) {
			return null;
		}
		return [
			'provider' => self::YOUTUBE,
			'id'       => $candidate,
		];
	}
}
