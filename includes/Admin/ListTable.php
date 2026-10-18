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
 * Adds photo, time, servings and featured columns to the Recipes list, a
 * filter per enabled taxonomy plus a featured filter, sorting by featured,
 * and two bulk actions (Mark featured / Unmark featured) for users who may
 * curate. Core verifies the bulk nonce before our handler runs; we check the
 * curation capability and then `edit_post` on every id.
 */
final class ListTable {

	public const ACTION_FEATURE   = 'bfrc_feature';
	public const ACTION_UNFEATURE = 'bfrc_unfeature';
	public const FILTER_FEATURED  = 'bfrc_featured';
	public const NOTICE_ARG       = 'bfrc_featured_changed';
	public const SCREEN           = 'edit-' . PostType::NAME;
	public const SORT_CLAUSE      = 'bfrc_featured_sort';
	public const DROPDOWN_TERMS   = 500;

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
		add_filter( 'removable_query_args', [ $this, 'removable_query_args' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	/**
	 * Photo after the checkbox; time, servings and featured before the date.
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
				echo esc_html( self::times_text( Meta::sanitize_minutes( get_post_meta( $post_id, Meta::PREP_MINUTES, true ) ), Meta::sanitize_minutes( get_post_meta( $post_id, Meta::COOK_MINUTES, true ) ) ) );
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
					echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not featured', 'bluefairy-recipes' ) . '</span>';
				}
				break;
		}
	}

	/**
	 * The Time cell: prep only, cook only, both, or a dash.
	 *
	 * @param int $prep Prep minutes.
	 * @param int $cook Cook minutes.
	 */
	public static function times_text( int $prep, int $cook ): string {
		if ( 0 === $prep && 0 === $cook ) {
			return '—';
		}
		if ( 0 === $cook ) {
			/* translators: %d: prep minutes */
			return sprintf( __( 'Prep %d min', 'bluefairy-recipes' ), $prep );
		}
		if ( 0 === $prep ) {
			/* translators: %d: cook minutes */
			return sprintf( __( 'Cook %d min', 'bluefairy-recipes' ), $cook );
		}
		/* translators: 1: prep minutes, 2: cook minutes */
		return sprintf( __( 'Prep %1$d / Cook %2$d min', 'bluefairy-recipes' ), $prep, $cook );
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
	 * Also primes the thumbnail cache for the rows about to render (core's
	 * posts list does not).
	 *
	 * @param string $post_type The screen's post type.
	 * @param string $which     'top' or 'bottom'.
	 */
	public function filters( string $post_type, string $which = 'top' ): void {
		if ( PostType::NAME !== $post_type || 'top' !== $which ) {
			return;
		}
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
			update_post_thumbnail_cache( $GLOBALS['wp_query'] );
		}
		foreach ( $this->enabled_taxonomies() as $code => $definition ) {
			$name = Taxonomies::taxonomy_name( $code );
			echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( sprintf( /* translators: %s: filter type name */ __( 'Filter by %s', 'bluefairy-recipes' ), $definition['label'] ) ) . '</label>';
			wp_dropdown_categories(
				[
					'taxonomy'        => $name,
					'name'            => $name,
					'id'              => $name,
					'value_field'     => 'slug',
					'selected'        => self::requested_slug( $name ),
					'hide_empty'      => true,
					'hide_if_empty'   => true,
					'hierarchical'    => true,
					'number'          => self::DROPDOWN_TERMS,
					'show_option_all' => esc_html( sprintf( /* translators: %s: taxonomy name */ __( 'All %s', 'bluefairy-recipes' ), $definition['label'] ) ),
				]
			);
		}
		$featured = $this->featured_filter_value();
		echo '<label class="screen-reader-text" for="bfrc-featured-filter">' . esc_html__( 'Filter by featured', 'bluefairy-recipes' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER_FEATURED ) . '" id="bfrc-featured-filter">';
		echo '<option value=""' . selected( $featured, '', false ) . '>' . esc_html__( 'Featured and not', 'bluefairy-recipes' ) . '</option>';
		echo '<option value="1"' . selected( $featured, '1', false ) . '>' . esc_html__( 'Featured only', 'bluefairy-recipes' ) . '</option>';
		echo '<option value="0"' . selected( $featured, '0', false ) . '>' . esc_html__( 'Not featured', 'bluefairy-recipes' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Apply the featured filter, the featured sort and (while the plugin is
	 * disabled and the taxonomies have no query var) the taxonomy filters to
	 * the list query only.
	 *
	 * @param \WP_Query $query The query.
	 */
	public function filter_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostType::NAME !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : [];
		$featured   = $this->featured_filter_value();
		if ( '1' === $featured ) {
			$meta_query[] = [
				'key'   => Meta::FEATURED,
				'value' => '1',
			];
		} elseif ( '0' === $featured ) {
			// "Not featured" = no row, or a row that is not '1'. A NOT EXISTS / != pair costs
			// recipes × meta rows; excluding the (curated, small) featured set is one indexed query.
			$exclude = self::featured_ids();
			if ( [] !== $exclude ) {
				$current = $query->get( 'post__not_in' );
				$query->set( 'post__not_in', array_values( array_unique( array_merge( is_array( $current ) ? array_map( 'intval', $current ) : [], $exclude ) ) ) );
			}
		}
		if ( 'bfrc_featured' === $query->get( 'orderby' ) ) {
			// A named clause in an OR with NOT EXISTS: core turns the joins into LEFT JOINs, so
			// recipes that never had the key stay in the list (a plain `meta_key` INNER JOINs them away).
			$meta_query[] = [
				'relation'        => 'OR',
				self::SORT_CLAUSE => [
					'key'     => Meta::FEATURED,
					'compare' => 'EXISTS',
				],
				[
					'key'     => Meta::FEATURED,
					'compare' => 'NOT EXISTS',
				],
			];
			$order        = strtoupper( (string) $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
			$query->set(
				'orderby',
				[
					self::SORT_CLAUSE => $order,
					'date'            => 'DESC',
				]
			);
		}
		if ( [] !== $meta_query ) {
			$query->set( 'meta_query', $meta_query );
		}
		$tax_query = $query->get( 'tax_query' );
		$tax_query = is_array( $tax_query ) ? $tax_query : [];
		foreach ( array_keys( $this->enabled_taxonomies() ) as $code ) {
			$name     = Taxonomies::taxonomy_name( $code );
			$taxonomy = get_taxonomy( $name );
			$slug     = self::requested_slug( $name );
			if ( '' === $slug || ! is_object( $taxonomy ) || ! empty( $taxonomy->query_var ) ) {
				continue; // Core parses the query var itself while the taxonomy is public.
			}
			$tax_query[] = [
				'taxonomy' => $name,
				'field'    => 'slug',
				'terms'    => [ $slug ],
			];
		}
		if ( [] !== $tax_query ) {
			$query->set( 'tax_query', $tax_query );
		}
	}

	/**
	 * Two bulk actions, for curators only.
	 *
	 * @param array<string, string> $actions Core's actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		if ( RecipeEditor::can_curate() ) {
			$actions[ self::ACTION_FEATURE ]   = __( 'Mark featured', 'bluefairy-recipes' );
			$actions[ self::ACTION_UNFEATURE ] = __( 'Unmark featured', 'bluefairy-recipes' );
		}
		return $actions;
	}

	/**
	 * Flip featured on every recipe the curator may edit; report the count of
	 * recipes whose value changed.
	 *
	 * @param string     $redirect Where core will send the browser.
	 * @param string     $action   The chosen action.
	 * @param array<int> $ids      Selected post ids.
	 */
	public function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( ! in_array( $action, [ self::ACTION_FEATURE, self::ACTION_UNFEATURE ], true ) ) {
			return $redirect;
		}
		if ( ! RecipeEditor::can_curate() ) {
			return add_query_arg( self::NOTICE_ARG, 0, remove_query_arg( self::NOTICE_ARG, $redirect ) );
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		}
		$value   = self::ACTION_FEATURE === $action;
		$changed = 0;
		foreach ( $ids as $id ) {
			if ( get_post_type( $id ) !== PostType::NAME || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( Meta::sanitize_bool( get_post_meta( $id, Meta::FEATURED, true ) ) === $value && metadata_exists( 'post', $id, Meta::FEATURED ) ) {
				continue; // Already so.
			}
			update_post_meta( $id, Meta::FEATURED, $value );
			++$changed;
		}
		return add_query_arg( self::NOTICE_ARG, $changed, remove_query_arg( self::NOTICE_ARG, $redirect ) );
	}

	/**
	 * Core strips these from pagination, sort links and the address bar.
	 *
	 * @param array<int, string> $args Core's list.
	 * @return array<int, string>
	 */
	public function removable_query_args( array $args ): array {
		$args[] = self::NOTICE_ARG;
		return $args;
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
	 * Ids of every recipe marked featured (any status), for the "not
	 * featured" filter.
	 *
	 * @return array<int, int>
	 */
	public static function featured_ids(): array {
		$ids = get_posts(
			[
				'post_type'              => PostType::NAME,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => Meta::FEATURED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one indexed key lookup; the alternative is a NOT EXISTS / != pair over every meta row.
				'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			]
		);
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : [] ) );
	}

	/**
	 * The enabled taxonomy definitions, keyed by code.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function enabled_taxonomies(): array {
		$service = Plugin::service( 'content.taxonomies' );
		return $service instanceof Taxonomies ? $service->enabled() : [];
	}

	/**
	 * A requested term slug for a filter, or '' (an array or anything that is
	 * not a string is ignored: core's `sanitize_title()` fatals on arrays).
	 *
	 * @param string $name The query parameter.
	 */
	private static function requested_slug( string $name ): string {
		$raw = $_GET[ $name ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- read-only filter; type-checked and sanitised on the next line.
		return is_string( $raw ) ? sanitize_title( wp_unslash( $raw ) ) : '';
	}

	/**
	 * '', '1' or '0' from the request.
	 */
	private function featured_filter_value(): string {
		$raw = isset( $_GET[ self::FILTER_FEATURED ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_FEATURED ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		return in_array( $raw, [ '1', '0' ], true ) ? $raw : '';
	}
}
