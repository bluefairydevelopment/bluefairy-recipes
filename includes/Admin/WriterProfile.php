<?php
/**
 * Recipe writer profile fields on the user edit screens.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Admin;

use BlueFairy\Recipes\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Avatar and header image (an existing image attachment the saving user
 * uploaded or may edit; anything else keeps the old value), an "about"
 * paragraph, a website and four social links (http/https only), and
 * "listed" (default true for the Writer role). Save: `edit_user` on the
 * target first, then the nonce. The section shows only for users who can
 * edit recipes; the image pickers only for a saver who can upload files.
 */
final class WriterProfile {

	public const NONCE_ACTION = 'bfrc_writer_profile';
	public const NONCE_FIELD  = 'bfrc_writer_profile_nonce';
	public const FIELD        = 'bfrc_writer';
	public const SCRIPT       = 'bfrc-writer-profile';
	public const ABOUT_MAX    = 5000;
	public const LINK_MAX     = 2048;

	public const AVATAR_ID = 'bfrc_avatar_id';
	public const HEADER_ID = 'bfrc_header_image_id';
	public const ABOUT     = 'bfrc_about';
	public const WEBSITE   = 'bfrc_website';
	public const YOUTUBE   = 'bfrc_youtube';
	public const FACEBOOK  = 'bfrc_facebook';
	public const INSTAGRAM = 'bfrc_instagram';
	public const SNAPCHAT  = 'bfrc_snapchat';
	public const LISTED    = 'bfrc_listed';

	/** Meta key => kind. The order is the form order. */
	public const FIELDS = [
		self::AVATAR_ID => 'attachment',
		self::HEADER_ID => 'attachment',
		self::ABOUT     => 'html',
		self::WEBSITE   => 'url',
		self::YOUTUBE   => 'url',
		self::FACEBOOK  => 'url',
		self::INSTAGRAM => 'url',
		self::SNAPCHAT  => 'url',
		self::LISTED    => 'bool',
	];

	/**
	 * Every user-meta key this plugin writes (uninstall.php lists the same).
	 *
	 * @return array<int, string>
	 */
	public static function keys(): array {
		return array_keys( self::FIELDS );
	}

	/**
	 * Hook everything.
	 */
	public function register_hooks(): void {
		add_action( 'show_user_profile', [ $this, 'render' ] );
		add_action( 'edit_user_profile', [ $this, 'render' ] );
		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Does this writer appear in the public listing? Absent meta falls back
	 * to the role default: Writers yes, everyone else no.
	 *
	 * @param int $user_id The user.
	 */
	public static function is_listed( int $user_id ): bool {
		$stored = get_user_meta( $user_id, self::LISTED, true );
		if ( '1' === $stored || '0' === $stored ) {
			return '1' === $stored;
		}
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User && in_array( Capabilities::ROLE_WRITER, $user->roles, true );
	}

	/**
	 * Does this user have a writer profile section at all?
	 *
	 * @param int|\WP_User $user The user.
	 */
	public static function has_profile( int|\WP_User $user ): bool {
		return user_can( $user, 'edit_bfrc_recipes' );
	}

	/**
	 * The fields, for users who can write recipes.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function render( \WP_User $user ): void {
		if ( ! self::has_profile( $user ) ) {
			return;
		}
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
		echo '<h2>' . esc_html__( 'Recipe writer profile', 'bluefairy-recipes' ) . '</h2>';
		echo '<table class="form-table bfrc-writer-profile" role="presentation">';
		if ( current_user_can( 'upload_files' ) ) {
			$this->image_row( self::AVATAR_ID, __( 'Profile photo', 'bluefairy-recipes' ), (int) get_user_meta( $user->ID, self::AVATAR_ID, true ) );
			$this->image_row( self::HEADER_ID, __( 'Header image', 'bluefairy-recipes' ), (int) get_user_meta( $user->ID, self::HEADER_ID, true ) );
		}
		echo '<tr><th><label for="bfrc-about">' . esc_html__( 'About', 'bluefairy-recipes' ) . '</label></th><td>';
		echo '<textarea id="bfrc-about" class="large-text" rows="5" maxlength="' . esc_attr( (string) self::ABOUT_MAX ) . '" name="' . esc_attr( self::FIELD . '[' . self::ABOUT . ']' ) . '">' . esc_textarea( (string) get_user_meta( $user->ID, self::ABOUT, true ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Shown on your writer page. Basic formatting is kept.', 'bluefairy-recipes' ) . '</p></td></tr>';
		foreach ( self::url_labels() as $key => $label ) {
			echo '<tr><th><label for="bfrc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="text" inputmode="url" id="bfrc-' . esc_attr( $key ) . '" class="regular-text" maxlength="' . esc_attr( (string) self::LINK_MAX ) . '" name="' . esc_attr( self::FIELD . '[' . $key . ']' ) . '" value="' . esc_attr( (string) get_user_meta( $user->ID, $key, true ) ) . '" placeholder="https://"></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Writer listing', 'bluefairy-recipes' ) . '</th><td><label>';
		echo '<input type="checkbox" name="' . esc_attr( self::FIELD . '[' . self::LISTED . ']' ) . '" value="1"' . checked( self::is_listed( $user->ID ), true, false ) . '> ';
		echo esc_html__( 'List this writer on the public writers page', 'bluefairy-recipes' ) . '</label></td></tr>';
		echo '</table>';
	}

	/**
	 * Field labels for the link fields, in form order.
	 *
	 * @return array<string, string>
	 */
	public static function url_labels(): array {
		return [
			self::WEBSITE   => __( 'Website', 'bluefairy-recipes' ),
			self::YOUTUBE   => __( 'YouTube', 'bluefairy-recipes' ),
			self::FACEBOOK  => __( 'Facebook', 'bluefairy-recipes' ),
			self::INSTAGRAM => __( 'Instagram', 'bluefairy-recipes' ),
			self::SNAPCHAT  => __( 'Snapchat', 'bluefairy-recipes' ),
		];
	}

	/**
	 * The media picker, only where it will be used: profile.php and
	 * user-edit.php, for a user who has a writer profile, when the viewer may
	 * upload files. (`wp_enqueue_media()` loads the whole media stack and
	 * scans the library's months, so it is not loaded for everyone.)
	 *
	 * @param string $hook The admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$target = get_current_user_id();
		if ( 'user-edit.php' === $hook && isset( $_GET['user_id'] ) && is_scalar( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which profile is open.
			$target = absint( wp_unslash( $_GET['user_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		}
		if ( $target <= 0 || ! self::has_profile( $target ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( self::SCRIPT, BFRC_URL . 'assets/admin/writer-profile.js', [ 'media-editor' ], BFRC_VERSION, true );
		wp_localize_script(
			self::SCRIPT,
			'bfrcWriterProfile',
			[
				'i18n' => [
					'choose' => __( 'Choose image', 'bluefairy-recipes' ),
					'use'    => __( 'Use this image', 'bluefairy-recipes' ),
				],
			]
		);
	}

	/**
	 * `personal_options_update` / `edit_user_profile_update`.
	 *
	 * @param int $user_id The user being saved.
	 */
	public function save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! self::has_profile( $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return; // The section was not on the form.
		}
		$nonce = $_POST[ self::NONCE_FIELD ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- this IS the nonce; type-checked, unslashed and verified on the next line.
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), self::NONCE_ACTION ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised by kind below.
		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? (array) wp_unslash( $_POST[ self::FIELD ] ) : [];
		foreach ( self::FIELDS as $key => $kind ) {
			$value = $raw[ $key ] ?? null;
			switch ( $kind ) {
				case 'attachment':
					if ( ! array_key_exists( $key, $raw ) || ! is_string( $value ) ) {
						break; // The picker was not on this form (or junk was posted): keep the old value.
					}
					$value = trim( $value );
					if ( '0' === $value || '' === $value ) {
						delete_user_meta( $user_id, $key );
					} elseif ( ctype_digit( $value ) && self::usable_image( (int) $value ) ) {
						update_user_meta( $user_id, $key, (int) $value );
					}
					// Anything else: the old value stays.
					break;
				case 'html':
					$text = is_scalar( $value ) ? trim( (string) $value ) : '';
					update_user_meta( $user_id, $key, wp_slash( wp_kses_post( mb_substr( $text, 0, self::ABOUT_MAX ) ) ) );
					break;
				case 'url':
					update_user_meta( $user_id, $key, wp_slash( self::sanitize_link( $value ) ) );
					break;
				case 'bool':
					$ticked = is_scalar( $value ) && '1' === (string) $value;
					update_user_meta( $user_id, $key, $ticked ? '1' : '0' );
					break;
			}
		}
	}

	/**
	 * An absolute http(s) URL with a host, or ''.
	 *
	 * A syntactic check only. `wp_http_validate_url()` is not used: it resolves
	 * the host with a DNS lookup on every save and refuses private and
	 * unresolvable hosts, which is right for a server-side fetch and wrong for
	 * a link a writer publishes.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function sanitize_link( mixed $value ): string {
		$url = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $url || mb_strlen( $url ) > self::LINK_MAX ) {
			return '';
		}
		$url   = esc_url_raw( $url, [ 'http', 'https' ] );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) || '' === trim( (string) ( $parts['host'] ?? '' ), '.' ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * An existing image attachment the current user uploaded or may edit
	 * (the Media Library's own rule; `read_post` would let anyone pick any
	 * unattached upload by guessing its id).
	 *
	 * @param int $id Attachment id.
	 */
	private static function usable_image( int $id ): bool {
		$attachment = get_post( $id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $id ) ) {
			return false;
		}
		return get_current_user_id() === (int) $attachment->post_author || current_user_can( 'edit_post', $id );
	}

	/**
	 * One image picker row.
	 *
	 * @param string $key   Meta key.
	 * @param string $label Row label.
	 * @param int    $id    Current attachment id.
	 */
	private function image_row( string $key, string $label, int $id ): void {
		echo '<tr><th>' . esc_html( $label ) . '</th><td class="bfrc-image-picker" data-bfrc-picker="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" class="bfrc-image-picker__id" name="' . esc_attr( self::FIELD . '[' . $key . ']' ) . '" value="' . esc_attr( (string) $id ) . '">';
		echo '<span class="bfrc-image-picker__preview">' . ( $id > 0 ? wp_get_attachment_image( $id, [ 96, 96 ] ) : '' ) . '</span> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds the tag.
		/* translators: %s: the image's purpose, e.g. "Profile photo" */
		echo '<button type="button" class="button bfrc-image-picker__choose" aria-label="' . esc_attr( sprintf( __( 'Choose %s', 'bluefairy-recipes' ), $label ) ) . '">' . esc_html__( 'Choose image', 'bluefairy-recipes' ) . '</button> ';
		/* translators: %s: the image's purpose, e.g. "Profile photo" */
		echo '<button type="button" class="button-link bfrc-image-picker__remove" aria-label="' . esc_attr( sprintf( __( 'Remove %s', 'bluefairy-recipes' ), $label ) ) . '"' . ( $id > 0 ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'bluefairy-recipes' ) . '</button>';
		echo '</td></tr>';
	}
}
