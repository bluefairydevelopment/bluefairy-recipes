<?php
/**
 * The WP_Comment shape (the Pro add-on's harness has the same class and the
 * comment API; the first one loaded wins).
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting

if ( ! class_exists( 'WP_Comment' ) ) {
	#[AllowDynamicProperties]
	final class WP_Comment {
		public string $comment_ID           = '0';
		public string $comment_post_ID      = '0';
		public string $comment_author       = '';
		public string $comment_author_email = '';
		public string $comment_author_url   = '';
		public string $comment_author_IP    = '';
		public string $comment_date         = '2026-10-26 12:00:00';
		public string $comment_date_gmt     = '2026-10-26 12:00:00';
		public string $comment_content      = '';
		public string $comment_karma        = '0';
		public string $comment_approved     = '1';
		public string $comment_agent        = '';
		public string $comment_type         = 'comment';
		public string $comment_parent       = '0';
		public string $user_id              = '0';

		/** As core: every column is a string. */
		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = (string) $value;
				}
			}
		}

		public function to_array(): array {
			return get_object_vars( $this );
		}
	}
}
