<?php
/**
 * The recipes list table: columns, filters, sorting and bulk featured.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Admin;

use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds photo, times, servings and featured columns to the Recipes list,
 * a filter per enabled taxonomy plus a featured filter, sorting by featured,
 * and two bulk actions (Mark featured / Unmark featured). Core verifies the
 * bulk nonce before our handler runs; we check `edit_post` on every id.
 */
final class ListTable {

	public const ACTION_FEATURE   = 'bfrc_feature';
	public const ACTION_UNFEATURE = 'bfrc_unfeature';
	public const FILTER_FEATURED  = 'bfrc_featured';
	public const NOTICE_ARG       = 'bfrc_featured_changed';
	public const SCREEN           = 'edit-' . PostType::NAME;

	/**
	 * Hook everything.
	 */
	public function register_hooks(): void {
		add_filter( 'manage_' . PostType::NAME . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . PostType::NAME . '_posts_custom_column', [ $this, 'column' ], 10, 2 );
		add_filter( 'manage_' . self::SCREEN . '_sortable_columns', [ $this, 'sortable_columns' ] );
		add_action( 'restrict_manage_posts', [ $this, 'filters' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'filter_query' ] );
		add_filter( 'bulk_actions-' . self::SCREEN, [ $this, 'bulk_actions' ] );
		add_filter( 'handle_bulk_actions-' . self::SCREEN, [ $this, 'handle_bulk' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	/**
	 * Photo after the checkbox; times, servings and featured before the date.
	 *
	 * @param array<string, string> $columns Core's columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['bfrc_times']    = __( 'Time', 'bluefairy-recipes' );
				$out['bfrc_servings'] = __( 'Servings', 'bluefairy-recipes' );
				$out['bfrc_featured'] = __( 'Featured', 'bluefairy-recipes' );
			}
			$out[ $key ] = $label;
			if ( 'cb' === $key ) {
				$out['bfrc_photo'] = __( 'Photo', 'bluefairy-recipes' );
			}
		}
		if ( ! isset( $out['bfrc_featured'] ) ) {
			$out['bfrc_times']    = __( 'Time', 'bluefairy-recipes' );
			$out['bfrc_servings'] = __( 'Servings', 'bluefairy-recipes' );
			$out['bfrc_featured'] = __( 'Featured', 'bluefairy-recipes' );
		}
		return $out;
	}

	/**
	 * One cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id The recipe.
	 */
	public function column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bfrc_photo':
				$thumb = get_post_thumbnail_id( $post_id );
				if ( $thumb ) {
					echo wp_get_attachment_image( $thumb, [ 48, 48 ], false, [ 'class' => 'bfrc-list-photo' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds the tag.
				} else {
					echo '<span aria-hidden="true">&mdash;</span>';
				}
				break;
			case 'bfrc_times':
				$prep = Meta::sanitize_minutes( get_post_meta( $post_id, Meta::PREP_MINUTES, true ) );
				$cook = Meta::sanitize_minutes( get_post_meta( $post_id, Meta::COOK_MINUTES, true ) );
				if ( 0 === $prep && 0 === $cook ) {
					echo '<span aria-hidden="true">&mdash;</span>';
					break;
				}
				/* translators: 1: prep minutes, 2: cook minutes */
				echo esc_html( sprintf( __( 'Prep %1$d / Cook %2$d min', 'bluefairy-recipes' ), $prep, $cook ) );
				break;
			case 'bfrc_servings':
				$min = Meta::sanitize_servings( get_post_meta( $post_id, Meta::SERVINGS_MIN, true ) );
				$max = Meta::sanitize_servings( get_post_meta( $post_id, Meta::SERVINGS_MAX, true ) );
				if ( 0 === $min && 0 === $max ) {
					echo '<span aria-hidden="true">&mdash;</span>';
				} elseif ( $max > $min ) {
					echo esc_html( $min . '–' . $max );
				} else {
					echo esc_html( (string) max( $min, $max ) );
				}
				break;
			case 'bfrc_featured':
				if ( Meta::sanitize_bool( get_post_meta( $post_id, Meta::FEATURED, true ) ) ) {
					echo '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Featured', 'bluefairy-recipes' ) . '</span>';
				} else {
					echo '<span aria-hidden="true">&mdash;</span>';
				}
				break;
		}
	}

	/**
	 * Featured sorts by meta.
	 *
	 * @param array<string, mixed> $columns Sortable columns.
	 * @return array<string, mixed>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['bfrc_featured'] = 'bfrc_featured';
		return $columns;
	}

	/**
	 * A dropdown per enabled taxonomy and a featured filter, above the table.
	 *
	 * @param string $post_type The screen's post type.
	 * @param string $which     'top' or 'bottom'.
	 */
	public function filters( string $post_type, string $which = 'top' ): void {
		if ( PostType::NAME !== $post_type || 'top' !== $which ) {
			return;
		}
		$service    = Plugin::service( 'content.taxonomies' );
		$taxonomies = $service instanceof Taxonomies ? $service->enabled() : [];
		foreach ( $taxonomies as $code => $definition ) {
			$name     = Taxonomies::taxonomy_name( $code );
			$selected = isset( $_GET[ $name ] ) ? sanitize_title( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			wp_dropdown_categories(
				[
					'taxonomy'        => $name,
					'name'            => $name,
					'value_field'     => 'slug',
					'selected'        => $selected,
					'hide_empty'      => true,
					'hierarchical'    => true,
					'show_option_all' => sprintf( /* translators: %s: taxonomy name */ __( 'All %s', 'bluefairy-recipes' ), $definition['label'] ),
				]
			);
		}
		$featured = $this->featured_filter_value();
		echo '<select name="' . esc_attr( self::FILTER_FEATURED ) . '">';
		echo '<option value=""' . selected( $featured, '', false ) . '>' . esc_html__( 'Featured and not', 'bluefairy-recipes' ) . '</option>';
		echo '<option value="1"' . selected( $featured, '1', false ) . '>' . esc_html__( 'Featured only', 'bluefairy-recipes' ) . '</option>';
		echo '<option value="0"' . selected( $featured, '0', false ) . '>' . esc_html__( 'Not featured', 'bluefairy-recipes' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Apply the featured filter and the featured sort to the list query only.
	 *
	 * @param \WP_Query $query The query.
	 */
	public function filter_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostType::NAME !== $query->get( 'post_type' ) ) {
			return;
		}
		$featured = $this->featured_filter_value();
		if ( '1' === $featured ) {
			$query->set(
				'meta_query',
				[
					[
						'key'   => Meta::FEATURED,
						'value' => '1',
					],
				]
			);
		} elseif ( '0' === $featured ) {
			$query->set(
				'meta_query',
				[
					'relation' => 'OR',
					[
						'key'     => Meta::FEATURED,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => Meta::FEATURED,
						'value'   => '1',
						'compare' => '!=',
					],
				]
			);
		}
		if ( 'bfrc_featured' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', Meta::FEATURED );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Two bulk actions.
	 *
	 * @param array<string, string> $actions Core's actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		$actions[ self::ACTION_FEATURE ]   = __( 'Mark featured', 'bluefairy-recipes' );
		$actions[ self::ACTION_UNFEATURE ] = __( 'Unmark featured', 'bluefairy-recipes' );
		return $actions;
	}

	/**
	 * Flip featured on every recipe the user may edit; report the count.
	 *
	 * @param string     $redirect Where core will send the browser.
	 * @param string     $action   The chosen action.
	 * @param array<int> $ids      Selected post ids.
	 */
	public function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( ! in_array( $action, [ self::ACTION_FEATURE, self::ACTION_UNFEATURE ], true ) ) {
			return $redirect;
		}
		$value   = self::ACTION_FEATURE === $action;
		$changed = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || get_post_type( $id ) !== PostType::NAME || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			update_post_meta( $id, Meta::FEATURED, $value );
			++$changed;
		}
		return add_query_arg( self::NOTICE_ARG, $changed, remove_query_arg( self::NOTICE_ARG, $redirect ) );
	}

	/**
	 * After a bulk action: how many recipes changed.
	 */
	public function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || self::SCREEN !== $screen->id || ! isset( $_GET[ self::NOTICE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count in the redirect, nothing is written.
			return;
		}
		$count = absint( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		echo '<div class="notice notice-success is-dismissible"><p>';
		/* translators: %s: number of recipes */
		echo esc_html( sprintf( _n( '%s recipe updated.', '%s recipes updated.', $count, 'bluefairy-recipes' ), number_format_i18n( $count ) ) );
		echo '</p></div>';
	}

	/**
	 * '', '1' or '0' from the request.
	 */
	private function featured_filter_value(): string {
		$raw = isset( $_GET[ self::FILTER_FEATURED ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_FEATURED ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		return in_array( $raw, [ '1', '0' ], true ) ? $raw : '';
	}
}
