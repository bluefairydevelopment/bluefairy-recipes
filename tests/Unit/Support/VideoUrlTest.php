<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Support;

use BlueFairy\Recipes\Support\VideoUrl;
use BlueFairy\Recipes\Tests\TestCase;

final class VideoUrlTest extends TestCase {

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function accepted(): array {
		return [
			'youtube watch'     => [ 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ],
			'youtu.be'          => [ 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ],
			'youtube embed'     => [ 'http://youtube.com/embed/dQw4w9WgXcQ?rel=0', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ],
			'youtube shorts'    => [ 'https://m.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ],
			'youtube nocookie'  => [ 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ],
			'vimeo'             => [ 'https://vimeo.com/123456789', 'https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789' ],
			'vimeo player'      => [ 'https://player.vimeo.com/video/123456789?h=abc', 'https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789' ],
			'vimeo with suffix' => [ '  https://vimeo.com/123456789/abcdef  ', 'https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789' ],
		];
	}

	/**
	 * @dataProvider accepted
	 */
	public function test_accepted_urls_normalise_to_the_canonical_form_and_embed_by_id( string $input, string $stored, string $embed ): void {
		$this->assertSame( $stored, VideoUrl::normalise( $input ) );
		$this->assertSame( $embed, VideoUrl::embed_url( $stored ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function refused(): array {
		return [
			'other host'          => [ 'https://example.com/watch?v=dQw4w9WgXcQ' ],
			'lookalike host'      => [ 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ' ],
			'javascript'          => [ 'javascript:alert(1)' ],
			'ftp'                 => [ 'ftp://www.youtube.com/watch?v=dQw4w9WgXcQ' ],
			'short id'            => [ 'https://www.youtube.com/watch?v=dQw4w9Wg' ],
			'long id'             => [ 'https://www.youtube.com/watch?v=dQw4w9WgXcQ1' ],
			'bad id chars'        => [ 'https://youtu.be/dQw4w9Wg"cQ' ],
			'id with newline'     => [ "https://youtu.be/dQw4w9WgXc\n" ],
			'youtube channel'     => [ 'https://www.youtube.com/@someone' ],
			'vimeo too short'     => [ 'https://vimeo.com/123' ],
			'vimeo letters'       => [ 'https://vimeo.com/abc123' ],
			'empty'               => [ '' ],
			'not a string'        => [ 42 ],
			'null'                => [ null ],
			'array'               => [ [ 'https://youtu.be/dQw4w9WgXcQ' ] ],
			'no scheme'           => [ 'www.youtube.com/watch?v=dQw4w9WgXcQ' ],
		];
	}

	/**
	 * @dataProvider refused
	 */
	public function test_anything_else_is_refused( mixed $input ): void {
		$this->assertNull( VideoUrl::parse( $input ) );
		$this->assertSame( '', VideoUrl::normalise( $input ) );
		$this->assertSame( '', VideoUrl::embed_url( is_string( $input ) ? $input : '' ) );
	}

	public function test_parse_reports_the_provider_and_id(): void {
		$this->assertSame( [ 'provider' => 'youtube', 'id' => 'dQw4w9WgXcQ' ], VideoUrl::parse( 'https://youtu.be/dQw4w9WgXcQ' ) );
		$this->assertSame( [ 'provider' => 'vimeo', 'id' => '123456789' ], VideoUrl::parse( 'https://vimeo.com/123456789' ) );
	}
}
