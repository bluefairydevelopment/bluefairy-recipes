<?php
/**
 * The public partials: archive, filters, cards, pagination, writers, single.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Front;

use BlueFairy\Recipes\Admin\RecipeEditor;
use BlueFairy\Recipes\Admin\WriterProfile;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\PostType;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Plugin;
use BlueFairy\Recipes\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every public view is built here and returned as a string, so the block
 * templates, the classic templates, the blocks and the shortcodes all show
 * the same markup, and every partial can be rendered in a test without a
 * theme. Nothing here runs a query per card: a grid primes its thumbnails
 * and its authors once, asks `count_many_users_posts()` once, and reads one
 * `get_terms()` for every enabled taxonomy together.
 */
final class Renderer {

	/**
	 * True while single() renders a recipe (re-entrancy guard, see is_rendering_single()).
	 *
	 * @var bool
	 */
	private static bool $rendering_single = false;

	public const GRID_IMAGE  = 'medium_large';
	public const HERO_IMAGE  = 'large';
	public const TERMS_LIMIT = 200;

	/**
	 * Wire the dependencies.
	 *
	 * @param Settings   $settings   The settings service.
	 * @param Writers    $writers    The writer rules.
	 * @param Taxonomies $taxonomies The filter types.
	 */
	public function __construct( private Settings $settings, private Writers $writers, private Taxonomies $taxonomies ) {}

	/**
	 * The archive view for the main query: writers listing, a writer's page,
	 * a filter type's recipes, or the recipe archive with filters.
	 *
	 * @param \WP_Query $query The main query.
	 */
	public function archive( \WP_Query $query ): string {
		if ( (bool) $query->get( Writers::LIST_VAR ) ) {
			return $this->writers_list( max( 1, (int) $query->get( 'paged' ) ) );
		}
		if ( (bool) $query->get( Writers::QUERY_VAR ) ) {
			$writer = $this->writers->public_writer( (string) $query->get( 'author_name' ) );
			return $writer instanceof \WP_User ? $this->writer_page( $writer, $query ) : '';
		}
		$term  = $query->is_tax() ? $query->get_queried_object() : null;
		$title = $term instanceof \WP_Term ? $term->name : (string) $this->settings->get( 'archive_title' );
		$html  = '<div class="bfrc-archive">';
		$html .= '<header class="bfrc-archive__header"><h1 class="bfrc-archive__title">' . esc_html( $title ) . '</h1>';
		if ( $term instanceof \WP_Term && '' !== $term->description ) {
			$html .= '<div class="bfrc-archive__description">' . wp_kses_post( term_description( $term->term_id ) ) . '</div>';
		}
		if ( $this->writers->listing_enabled() ) {
			$html .= '<p class="bfrc-archive__writers-link"><a href="' . esc_url( $this->writers->listing_url() ) . '">' . esc_html__( 'Browse recipe writers', 'bluefairy-recipes' ) . '</a></p>';
		}
		$html .= '</header>';
		$html .= $this->filters( $query );
		$posts = is_array( $query->posts ) ? $query->posts : [];
		$html .= $this->grid( $posts );
		$html .= $this->pagination( $query );
		$html .= '</div>';
		return $html;
	}

	/**
	 * The filter form: one select per enabled taxonomy that has terms, a
	 * title keyword, submit and reset. GET to the current archive or term
	 * URL with `bfrc_filter[{code}]` fields (not the taxonomies' own query
	 * vars, which would turn the result into a term page). The terms of
	 * every enabled taxonomy come from one `get_terms()`.
	 *
	 * @param \WP_Query $query The main query (for the current choices).
	 */
	public function filters( \WP_Query $query ): string {
		$term   = $query->is_tax() ? $query->get_queried_object() : null;
		$action = $term instanceof \WP_Term ? get_term_link( $term ) : get_post_type_archive_link( PostType::NAME );
		$action = is_string( $action ) ? $action : (string) get_post_type_archive_link( PostType::NAME );
		$chosen = $this->current_filters();
		$search = $this->current_search();
		$html   = '<form class="bfrc-filters" method="get" action="' . esc_url( $action ) . '" data-bfrc-filters role="search" aria-label="' . esc_attr__( 'Filter recipes', 'bluefairy-recipes' ) . '" aria-describedby="bfrc-filters-hint">';
		$html  .= '<p id="bfrc-filters-hint" class="screen-reader-text bfrc-filters__hint">' . esc_html__( 'Changing a filter or pausing while typing updates the list; the Filter button applies it at once.', 'bluefairy-recipes' ) . '</p>';
		$terms  = $this->terms_by_taxonomy();
		foreach ( $this->taxonomies->enabled() as $code => $definition ) {
			$name = Taxonomies::taxonomy_name( $code );
			if ( $term instanceof \WP_Term && $term->taxonomy === $name ) {
				continue; // The URL already names this term.
			}
			$options = $terms[ $name ] ?? [];
			if ( [] === $options ) {
				continue;
			}
			$html .= '<label class="bfrc-filters__field"><span class="bfrc-filters__label">' . esc_html( $definition['singular'] ) . '</span>';
			$html .= '<select name="' . esc_attr( Templates::FILTER_VAR . '[' . $code . ']' ) . '">';
			$html .= '<option value="">' . esc_html( sprintf( /* translators: %s: filter type plural name */ __( 'All %s', 'bluefairy-recipes' ), $definition['label'] ) ) . '</option>';
			foreach ( $options as $option ) {
				$html .= '<option value="' . esc_attr( $option->slug ) . '"' . selected( $chosen[ $name ] ?? '', $option->slug, false ) . '>' . esc_html( $option->name ) . '</option>';
			}
			$html .= '</select></label>';
		}
		$html .= '<label class="bfrc-filters__field bfrc-filters__field--search"><span class="bfrc-filters__label">' . esc_html__( 'Search by title', 'bluefairy-recipes' ) . '</span>';
		$html .= '<input type="search" name="' . esc_attr( Templates::SEARCH_VAR ) . '" value="' . esc_attr( $search ) . '" maxlength="' . esc_attr( (string) Templates::SEARCH_MAX ) . '" placeholder="' . esc_attr__( 'Recipe title', 'bluefairy-recipes' ) . '"></label>';
		$html .= '<div class="bfrc-filters__actions"><button type="submit" class="bfrc-button">' . esc_html__( 'Filter', 'bluefairy-recipes' ) . '</button>';
		if ( [] !== $chosen || '' !== $search ) {
			$html .= ' <a class="bfrc-filters__reset" href="' . esc_url( $action ) . '">' . esc_html__( 'Reset', 'bluefairy-recipes' ) . '</a>';
		}
		$html .= '</div></form>';
		return $html;
	}

	/**
	 * The terms of every enabled taxonomy, one query, keyed by taxonomy
	 * name, ≤ TERMS_LIMIT per taxonomy, name order. The query's budget is
	 * TERMS_LIMIT × taxonomies, ordered by name across all of them, so on a
	 * site where one taxonomy alone has more in-use terms than the whole
	 * budget, a later taxonomy's dropdown can come up short; the per-taxonomy
	 * cap is then the smaller limit anyway.
	 *
	 * @return array<string, array<int, \WP_Term>>
	 */
	public function terms_by_taxonomy(): array {
		$names = array_map( [ Taxonomies::class, 'taxonomy_name' ], array_keys( $this->taxonomies->enabled() ) );
		if ( [] === $names ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'   => $names,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => self::TERMS_LIMIT * count( $names ),
			]
		);
		$out   = [];
		if ( ! is_array( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && count( $out[ $term->taxonomy ] ?? [] ) < self::TERMS_LIMIT ) {
				$out[ $term->taxonomy ][] = $term;
			}
		}
		return $out;
	}

	/**
	 * The card grid, or a "no recipes" line. Thumbnails and authors are
	 * primed once for the whole grid.
	 *
	 * @param array<int, \WP_Post> $posts         The recipes.
	 * @param int                  $heading_level Card title level: 2 under an h1, 3 under a section's h2.
	 */
	public function grid( array $posts, int $heading_level = 2 ): string {
		$posts = array_values( array_filter( $posts, static fn( mixed $p ): bool => $p instanceof \WP_Post ) );
		if ( [] === $posts ) {
			return '<p class="bfrc-empty">' . esc_html( self::empty_text() ) . '</p>';
		}
		$this->prime_thumbnails( $posts );
		$links = $this->writer_links( $posts );
		$html  = '<ul class="bfrc-grid">';
		foreach ( $posts as $post ) {
			$html .= '<li class="bfrc-grid__item">' . $this->card( $post, $links[ (int) $post->post_author ] ?? null, $heading_level ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * The "no recipes" line's text (the view script prints the same line
	 * from the store's config).
	 */
	public static function empty_text(): string {
		return __( 'No recipes match. Try fewer filters.', 'bluefairy-recipes' );
	}

	/**
	 * One recipe card. A password-protected recipe shows core's protected
	 * title and its photo (as core's own loops do), and none of its fields:
	 * no badges and no excerpt (core would print "There is no excerpt
	 * because this is a protected post." in its place).
	 *
	 * @param \WP_Post    $post          The recipe.
	 * @param string|null $writer_url    The writer's public URL, or null when they have no page.
	 * @param int         $heading_level Title level (2..6).
	 * @param bool|null   $locked        Render as locked (true), unlocked (false), or as `post_password_required()` says (null).
	 */
	public function card( \WP_Post $post, ?string $writer_url = null, int $heading_level = 2, ?bool $locked = null ): string {
		$h         = 'h' . min( 6, max( 2, $heading_level ) ); // h2 under the archive's h1; h3 under a writer page's "Recipes by" h2.
		$data      = RecipeData::for_post( $post );
		$protected = $locked ?? post_password_required( $post ); // The REST endpoint passes true for any recipe with a password: its item never varies by cookie.
		$url       = get_permalink( $post );
		$title     = get_the_title( $post );
		$html      = '<article class="bfrc-card' . ( $data->featured ? ' bfrc-card--featured' : '' ) . ( $protected ? ' bfrc-card--protected' : '' ) . '">';
		$thumb     = get_the_post_thumbnail( $post, self::GRID_IMAGE, [ 'class' => 'bfrc-card__image' ] );
		$html     .= '<a class="bfrc-card__media" href="' . esc_url( (string) $url ) . '" tabindex="-1" aria-hidden="true">' . ( '' !== $thumb ? $thumb : '<span class="bfrc-card__placeholder"></span>' ) . '</a>';
		$html     .= '<div class="bfrc-card__body"><' . $h . ' class="bfrc-card__title"><a href="' . esc_url( (string) $url ) . '">' . esc_html( $title ) . '</a></' . $h . '>';
		if ( ! $protected ) {
			$html .= $this->badges( $data, false ); // As the recipe page: a locked recipe shows its title and photo, nothing of its contents.
		}
		$author = $this->author_line( $post, $writer_url );
		if ( '' !== $author ) {
			$html .= '<p class="bfrc-card__writer">' . $author . '</p>';
		}
		$text = ! $protected && has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		if ( '' !== $text ) {
			$html .= '<p class="bfrc-card__excerpt">' . esc_html( wp_trim_words( wp_strip_all_tags( $text ), 24, '…' ) ) . '</p>';
		}
		return $html . '</div></article>';
	}

	/**
	 * Did the writer write a body? Judged on the parsed block list: any block
	 * other than a paragraph, a spacer or a separator is a body (an image,
	 * a synced pattern, a Query Loop: dynamic blocks carry no static HTML, so
	 * their comment alone counts); a paragraph or classic (non-block) content
	 * is a body when text or a media element remains once tags and
	 * non-breaking spaces are gone. An empty paragraph block left behind by
	 * the editor is not.
	 *
	 * @param string $content Raw post content.
	 */
	public static function has_body( string $content ): bool {
		if ( '' === trim( $content ) ) {
			return false;
		}
		$blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : [];
		if ( [] === $blocks ) {
			return self::has_text_or_media( $content );
		}
		foreach ( $blocks as $block ) {
			if ( self::block_has_body( $block ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One parsed block: a child with a body, a dynamic block (a named block
	 * with no static HTML and no children: a synced pattern, a Query Loop's
	 * post title, Latest Posts), or static HTML with text or media. An empty
	 * paragraph, heading, list or group, a spacer or a separator is none of
	 * those.
	 *
	 * @param mixed $block A parse_blocks() entry.
	 */
	private static function block_has_body( mixed $block ): bool {
		if ( ! is_array( $block ) ) {
			return false;
		}
		$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
		foreach ( $children as $child ) {
			if ( self::block_has_body( $child ) ) {
				return true;
			}
		}
		$html = (string) ( $block['innerHTML'] ?? '' );
		$name = $block['blockName'] ?? null;
		if ( is_string( $name ) && [] === $children && '' === trim( $html ) ) {
			return self::block_is_dynamic( $name ); // Rendered on request, so the comment alone is the body; an empty static block (a Custom HTML block) is not.
		}
		return self::has_text_or_media( $html );
	}

	/**
	 * Does this block render on request? Core's registry answers for the
	 * blocks it knows; a block registered elsewhere (an add-on's, on
	 * another site) is taken as dynamic, the safer answer for a body.
	 *
	 * @param string $name Block name.
	 */
	private static function block_is_dynamic( string $name ): bool {
		if ( ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return true;
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		return ! $type instanceof \WP_Block_Type || $type->is_dynamic();
	}

	/**
	 * Does this HTML carry visible text or a media element?
	 *
	 * @param string $html A paragraph's or classic content's HTML.
	 */
	private static function has_text_or_media( string $html ): bool {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html ); // An HTML comment is not media, whatever it mentions.
		if ( 1 === preg_match( '/<(img|picture|video|audio|iframe|embed|object|svg)\b/i', $html ) ) {
			return true; // A figure counts through what it holds: an empty figure is not media.
		}
		$text      = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$collapsed = preg_replace( '/[\p{Z}\p{Cf}\s]+/u', '', $text ); // Every space, non-breaking or zero-width, numeric entity or literal.
		return '' !== ( $collapsed ?? $text ); // Invalid UTF-8 makes preg give up: the invalid byte is itself text to keep, never a reason to hide the body.
	}

	/**
	 * Is a recipe page being rendered right now? The view blocks return
	 * nothing while it is, so a recipe whose body carries the Single Recipe
	 * block (the editor lets a writer insert it anywhere) renders once instead
	 * of recursing through `the_content` until PHP runs out of stack.
	 */
	public static function is_rendering_single(): bool {
		return self::$rendering_single;
	}

	/**
	 * Core pagination for the current archive query.
	 *
	 * @param \WP_Query $query The main query.
	 */
	public function pagination( \WP_Query $query ): string {
		$pages = (int) $query->max_num_pages;
		if ( $pages < 2 ) {
			return '';
		}
		$links = paginate_links(
			[
				'total'     => $pages,
				'current'   => max( 1, (int) $query->get( 'paged' ) ),
				'prev_text' => __( '&laquo; Previous', 'bluefairy-recipes' ),
				'next_text' => __( 'Next &raquo;', 'bluefairy-recipes' ),
				'type'      => 'list',
			]
		);
		return is_string( $links ) && '' !== $links ? '<nav class="bfrc-pagination" aria-label="' . esc_attr__( 'Recipes pagination', 'bluefairy-recipes' ) . '">' . $links . '</nav>' : '';
	}

	/**
	 * The writers listing page.
	 *
	 * @param int         $page        1-based page.
	 * @param bool        $with_header With the page heading and the "All recipes" link (the listing page); false for a shortcode inside a page.
	 * @param string|null $base_url    Page links as `?bfrc_page=N` of this URL instead of the listing's `/page/N/`.
	 */
	public function writers_list( int $page, bool $with_header = true, ?string $base_url = null ): string {
		$listing = $this->writers->listed( $page );
		$html    = '<div class="bfrc-writers">';
		if ( $with_header ) {
			$html .= '<header class="bfrc-archive__header"><h1 class="bfrc-archive__title">' . esc_html__( 'Recipe writers', 'bluefairy-recipes' ) . '</h1>';
			$html .= '<p class="bfrc-archive__writers-link"><a href="' . esc_url( (string) get_post_type_archive_link( PostType::NAME ) ) . '">' . esc_html__( 'All recipes', 'bluefairy-recipes' ) . '</a></p></header>';
		}
		if ( [] === $listing['writers'] ) {
			return $html . '<p class="bfrc-empty">' . esc_html__( 'No recipe writers yet.', 'bluefairy-recipes' ) . '</p></div>';
		}
		$ids = array_map( static fn( array $w ): int => (int) $w['user']->ID, $listing['writers'] );
		$this->prime_profile_images( $ids );
		$html .= '<ul class="bfrc-writers__grid">';
		foreach ( $listing['writers'] as $entry ) {
			$user    = $entry['user'];
			$profile = Writers::profile( $user->ID );
			$url     = $this->writers->url( $user );
			$html   .= '<li class="bfrc-writer-card"><a class="bfrc-writer-card__avatar" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">' . $this->avatar( $user, $profile['avatar_id'], 160 ) . '</a>';
			$html   .= '<div class="bfrc-writer-card__body"><h2 class="bfrc-writer-card__name"><a href="' . esc_url( $url ) . '">' . esc_html( $user->display_name ) . '</a></h2>';
			/* translators: %s: number of recipes */
			$html .= '<p class="bfrc-writer-card__count">' . esc_html( sprintf( _n( '%s recipe', '%s recipes', $entry['count'], 'bluefairy-recipes' ), number_format_i18n( $entry['count'] ) ) ) . '</p>';
			if ( '' !== $profile['about'] ) {
				$html .= '<p class="bfrc-writer-card__about">' . esc_html( wp_trim_words( wp_strip_all_tags( $profile['about'] ), 30, '…' ) ) . '</p>';
			}
			$html .= '</div></li>';
		}
		$html .= '</ul>';
		if ( $listing['pages'] > 1 ) {
			// On the listing page the pages are core's `/page/N/`; on a page holding the shortcode they are `?bfrc_page=N` of that page.
			$links = paginate_links(
				[
					'base'      => null === $base_url ? trailingslashit( $this->writers->listing_url() ) . '%_%' : add_query_arg( RecipeQuery::PAGE_VAR, '%#%', $base_url ),
					'format'    => null === $base_url ? user_trailingslashit( Writers::pagination_base() . '/%#%' ) : '',
					'total'     => $listing['pages'],
					'current'   => $page,
					'prev_text' => __( '&laquo; Previous', 'bluefairy-recipes' ),
					'next_text' => __( 'Next &raquo;', 'bluefairy-recipes' ),
					'type'      => 'list',
				]
			);
			if ( is_string( $links ) && '' !== $links ) {
				$html .= '<nav class="bfrc-pagination" aria-label="' . esc_attr__( 'Writers pagination', 'bluefairy-recipes' ) . '">' . $links . '</nav>';
			}
		}
		return $html . '</div>';
	}

	/**
	 * A writer's page: header image, avatar, about, links, their recipes.
	 *
	 * @param \WP_User  $writer The writer.
	 * @param \WP_Query $query  The main query (their recipes).
	 */
	public function writer_page( \WP_User $writer, \WP_Query $query ): string {
		$profile = Writers::profile( $writer->ID );
		$this->prime_profile_images( [ $writer->ID ] );
		$html = '<div class="bfrc-writer">';
		if ( $profile['header_id'] > 0 ) {
			$header = wp_get_attachment_image(
				$profile['header_id'],
				self::HERO_IMAGE,
				false,
				[
					'class' => 'bfrc-writer__header-image',
					'alt'   => '',
				]
			);
			if ( '' !== $header ) {
				$html .= '<div class="bfrc-writer__header">' . $header . '</div>';
			}
		}
		$html .= '<header class="bfrc-writer__intro">' . $this->avatar( $writer, $profile['avatar_id'], 160 );
		$html .= '<div class="bfrc-writer__text"><h1 class="bfrc-writer__name">' . esc_html( $writer->display_name ) . '</h1>';
		if ( '' !== $profile['about'] ) {
			$html .= '<div class="bfrc-writer__about">' . wp_kses_post( wpautop( $profile['about'] ) ) . '</div>';
		}
		$html .= $this->social_links( $profile['links'] );
		$html .= '<p class="bfrc-archive__writers-link"><a href="' . esc_url( $this->writers->listing_url() ) . '">' . esc_html__( 'All recipe writers', 'bluefairy-recipes' ) . '</a></p>';
		$html .= '</div></header>';
		$html .= '<h2 class="bfrc-writer__recipes-title">' . esc_html( sprintf( /* translators: %s: writer's name */ __( 'Recipes by %s', 'bluefairy-recipes' ), $writer->display_name ) ) . '</h2>';
		$posts = is_array( $query->posts ) ? $query->posts : [];
		$html .= $this->grid( $posts, 3 );
		$html .= $this->pagination( $query );
		return $html . '</div>';
	}

	/**
	 * The single recipe. A password-protected recipe shows core's password
	 * form and nothing else until the visitor unlocks it.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function single( \WP_Post $post ): string {
		if ( self::$rendering_single ) {
			return ''; // Nested: the recipe's own body carries the view block.
		}
		if ( post_password_required( $post ) ) {
			return '<article class="bfrc-recipe bfrc-recipe--protected" id="bfrc-recipe-' . (int) $post->ID . '"><h1 class="bfrc-recipe__title">' . esc_html( get_the_title( $post ) ) . '</h1>' . get_the_password_form( $post ) . '</article>';
		}
		self::$rendering_single = true;
		try {
			return $this->single_body( $post );
		} finally {
			self::$rendering_single = false;
		}
	}

	/**
	 * The recipe page's markup (see single()).
	 *
	 * @param \WP_Post $post The recipe.
	 */
	private function single_body( \WP_Post $post ): string {
		$data   = RecipeData::for_post( $post );
		$links  = $this->writer_links( [ $post ] );
		$html   = '<article class="bfrc-recipe' . ( $data->featured ? ' bfrc-recipe--featured' : '' ) . '" id="bfrc-recipe-' . (int) $post->ID . '">';
		$html  .= '<header class="bfrc-recipe__header"><h1 class="bfrc-recipe__title">' . esc_html( get_the_title( $post ) ) . '</h1>';
		$author = $this->author_line( $post, $links[ (int) $post->post_author ] ?? null );
		$html  .= '<p class="bfrc-recipe__writer">' . ( '' !== $author ? $author . ' ' : '' ) . '<span class="bfrc-recipe__date">' . esc_html( get_the_date( '', $post ) ) . '</span></p>';
		$html  .= $this->badges( $data, true );
		$html  .= $this->term_badges( $post );
		$html  .= '</header>';
		$hero   = $this->hero( $post, $data );
		if ( '' !== $hero ) {
			$html .= '<div class="bfrc-recipe__hero">' . $hero . '</div>';
		}
		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		if ( '' !== $excerpt ) {
			$html .= '<p class="bfrc-recipe__summary">' . esc_html( wp_strip_all_tags( $excerpt ) ) . '</p>';
		}
		if ( self::has_body( $post->post_content ) ) {
			// Only when the writer wrote something: `the_content` filters from other plugins append their
			// own blocks (related products, share bars) and must not decorate an empty body. A body of
			// media alone (an image or gallery block) counts as written.
			$content = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, applied as core does.
			if ( '' !== trim( $content ) ) {
				$html .= '<div class="bfrc-recipe__content">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content output, as core prints it.
			}
		}
		$html .= $this->ingredients( $data );
		$html .= $this->steps( $data );
		$html .= $this->seam( 'bfrc_single_after_steps', $post->ID );
		$html .= $this->nutrition( $data );
		$html .= $this->seam( 'bfrc_single_after_nutrition', $post->ID );
		$html .= '<footer class="bfrc-recipe__footer">' . $this->share_buttons( $post ) . '<button type="button" class="bfrc-button bfrc-button--print" data-bfrc-print>' . esc_html__( 'Print recipe', 'bluefairy-recipes' ) . '</button>';
		$html .= $this->seam( 'bfrc_single_footer', $post->ID );
		return $html . '</footer></article>';
	}

	/**
	 * Video embed when the recipe shows a video, else the recipe photo; ''
	 * when there is neither.
	 *
	 * @param \WP_Post   $post The recipe.
	 * @param RecipeData $data Its fields.
	 */
	public function hero( \WP_Post $post, RecipeData $data ): string {
		$embed = $data->embed_url();
		if ( '' !== $embed ) {
			return '<div class="bfrc-recipe__video"><iframe src="' . esc_url( $embed ) . '" title="' . esc_attr( get_the_title( $post ) ) . '" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
		}
		$attr = [ 'class' => 'bfrc-recipe__image' ];
		$alt  = trim( (string) get_post_meta( (int) get_post_thumbnail_id( $post ), '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$attr['alt'] = get_the_title( $post ); // The hero is the recipe: without its own alt text, its name.
		}
		return get_the_post_thumbnail( $post, self::HERO_IMAGE, $attr );
	}

	/**
	 * Prep / cook / total / servings.
	 *
	 * @param RecipeData $data  The fields.
	 * @param bool       $total Include the total.
	 */
	public function badges( RecipeData $data, bool $total ): string {
		$items = [];
		if ( $data->prep_minutes > 0 ) {
			$items[] = [ __( 'Prep', 'bluefairy-recipes' ), RecipeData::format_minutes( $data->prep_minutes ) ];
		}
		if ( $data->cook_minutes > 0 ) {
			$items[] = [ __( 'Cook', 'bluefairy-recipes' ), RecipeData::format_minutes( $data->cook_minutes ) ];
		}
		if ( $total && $data->prep_minutes > 0 && $data->cook_minutes > 0 ) {
			$items[] = [ __( 'Total', 'bluefairy-recipes' ), RecipeData::format_minutes( $data->total_minutes() ) ];
		}
		$servings = $data->servings_text();
		if ( '' !== $servings ) {
			$items[] = [ __( 'Serves', 'bluefairy-recipes' ), $servings ];
		}
		if ( [] === $items ) {
			return '';
		}
		$html = '<dl class="bfrc-badges">';
		foreach ( $items as [ $label, $value ] ) {
			$html .= '<div class="bfrc-badge"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return $html . '</dl>';
	}

	/**
	 * Linked terms of every enabled taxonomy.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function term_badges( \WP_Post $post ): string {
		$html = '';
		foreach ( $this->taxonomies->enabled() as $code => $definition ) {
			$terms = get_the_terms( $post, Taxonomies::taxonomy_name( $code ) );
			if ( ! is_array( $terms ) || [] === $terms ) {
				continue;
			}
			$links = [];
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$link    = get_term_link( $term );
				$links[] = is_string( $link ) ? '<a href="' . esc_url( $link ) . '" rel="tag">' . esc_html( $term->name ) . '</a>' : esc_html( $term->name );
			}
			$html .= '<p class="bfrc-recipe__terms"><span class="bfrc-recipe__terms-label">' . esc_html( $definition['singular'] ) . ':</span> ' . implode( ', ', $links ) . '</p>';
		}
		return $html;
	}

	/**
	 * The ingredient list plus the add-on's actions seam (both only when
	 * there are ingredients).
	 *
	 * @param RecipeData $data The fields.
	 */
	public function ingredients( RecipeData $data ): string {
		$rows = $data->ingredients();
		if ( [] === $rows ) {
			return '';
		}
		$html = '<section class="bfrc-recipe__ingredients"><h2>' . esc_html__( 'Ingredients', 'bluefairy-recipes' ) . '</h2><ul class="bfrc-ingredients">';
		foreach ( $rows as $row ) {
			$html .= '<li class="bfrc-ingredient bfrc-ingredient--' . esc_attr( sanitize_html_class( $row['type'] ) ) . '">';
			if ( '' !== $row['measure'] ) {
				$html .= '<span class="bfrc-ingredient__measure">' . esc_html( $row['measure'] ) . '</span> ';
			}
			/**
			 * One ingredient row is rendered; filter its label markup (the
			 * add-on links its product and affiliate rows). The result is
			 * passed through `wp_kses_post()`, so links survive and scripts do not.
			 *
			 * @since 1.0.0
			 *
			 * @param string               $label_html Escaped label markup.
			 * @param array<string, mixed> $row        The row (measure, label, type, and the type's extra fields).
			 * @param int                  $recipe_id  The recipe.
			 */
			$label = (string) apply_filters( 'bfrc_ingredient_label_html', esc_html( $row['label'] ), $row, $data->id );
			$html .= '<span class="bfrc-ingredient__label">' . wp_kses_post( $label ) . '</span></li>';
		}
		$html .= '</ul>' . $this->seam( 'bfrc_render_ingredient_actions', $data->id );
		return $html . '</section>';
	}

	/**
	 * The numbered steps.
	 *
	 * @param RecipeData $data The fields.
	 */
	public function steps( RecipeData $data ): string {
		$rows = $data->steps();
		if ( [] === $rows ) {
			return '';
		}
		$html = '<section class="bfrc-recipe__steps"><h2>' . esc_html__( 'Method', 'bluefairy-recipes' ) . '</h2><ol class="bfrc-steps">';
		foreach ( $rows as $step ) {
			$html .= '<li class="bfrc-step">' . wp_kses_post( $step['text'] ) . '</li>';
		}
		return $html . '</ol></section>';
	}

	/**
	 * The nutrition facts table.
	 *
	 * @param RecipeData $data The fields.
	 */
	public function nutrition( RecipeData $data ): string {
		$present = $data->nutrition_present();
		if ( [] === $present ) {
			return '';
		}
		$labels = RecipeEditor::nutrition_labels();
		$html   = '<section class="bfrc-recipe__nutrition"><h2>' . esc_html__( 'Nutrition', 'bluefairy-recipes' ) . '</h2>';
		$html  .= '<p class="bfrc-nutrition__note">' . esc_html__( 'Per serving', 'bluefairy-recipes' ) . '</p><table class="bfrc-nutrition"><tbody>';
		foreach ( $present as $field => $value ) {
			$unit  = Meta::NUTRITION_FIELDS[ $field ];
			$html .= '<tr><th scope="row">' . esc_html( $labels[ $field ] ?? $field ) . '</th><td>' . esc_html( self::format_amount( $value ) . ' ' . $unit ) . '</td></tr>';
		}
		return $html . '</tbody></table></section>';
	}

	/**
	 * Share links per the settings; Pinterest gets the recipe photo as `media`.
	 *
	 * @param \WP_Post $post The recipe.
	 */
	public function share_buttons( \WP_Post $post ): string {
		if ( ! (bool) $this->settings->get( 'share_enabled' ) ) {
			return '';
		}
		$url       = (string) get_permalink( $post );
		$decoded   = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
		$collapsed = preg_replace( '/\s+/u', ' ', $decoded ); // "<Soup>" in a title is stripped as a tag; no double space left behind.
		$title     = trim( null === $collapsed ? $decoded : $collapsed ); // Invalid UTF-8 makes preg give up: keep the text rather than an empty share.
		$links     = [];
		if ( (bool) $this->settings->get( 'share_facebook' ) ) {
			$links['facebook'] = [ __( 'Share on Facebook', 'bluefairy-recipes' ), 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) ];
		}
		if ( (bool) $this->settings->get( 'share_x' ) ) {
			$links['x'] = [ __( 'Share on X', 'bluefairy-recipes' ), 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ) ];
		}
		if ( (bool) $this->settings->get( 'share_pinterest' ) ) {
			$media              = (string) get_the_post_thumbnail_url( $post, 'full' );
			$links['pinterest'] = [ __( 'Pin on Pinterest', 'bluefairy-recipes' ), 'https://pinterest.com/pin/create/button/?url=' . rawurlencode( $url ) . '&description=' . rawurlencode( $title ) . ( '' !== $media ? '&media=' . rawurlencode( $media ) : '' ) ];
		}
		if ( [] === $links ) {
			return '';
		}
		$html = '<nav class="bfrc-share" aria-label="' . esc_attr__( 'Share this recipe', 'bluefairy-recipes' ) . '">';
		foreach ( $links as $network => [ $label, $href ] ) {
			$html .= '<a class="bfrc-share__link bfrc-share__link--' . esc_attr( $network ) . '" href="' . esc_url( $href ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $label ) . self::new_tab_hint() . '</a>';
		}
		return $html . '</nav>';
	}

	/**
	 * The writers' public URLs for a set of posts, in one users query and one
	 * count query: author id => URL, or null for a writer without a page.
	 *
	 * @param array<int, \WP_Post> $posts The recipes.
	 * @return array<int, string|null>
	 */
	public function writer_links( array $posts ): array {
		$ids = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && (int) $post->post_author > 0 ) {
				$ids[ (int) $post->post_author ] = true;
			}
		}
		$ids = array_keys( $ids );
		if ( [] === $ids ) {
			return [];
		}
		cache_users( $ids );
		$enabled = (bool) $this->settings->get( 'enabled' );
		$counts  = $enabled ? count_many_users_posts( $ids, PostType::NAME, true ) : [];
		$out     = [];
		foreach ( $ids as $id ) {
			$user       = get_userdata( $id );
			$out[ $id ] = $enabled && $user instanceof \WP_User && ( (int) ( $counts[ $id ] ?? 0 ) ) > 0 && WriterProfile::is_listed( $id ) ? $this->writers->url( $user ) : null;
		}
		return $out;
	}

	/**
	 * "By Name", linked when the writer has a public page; '' when the
	 * author no longer exists.
	 *
	 * @param \WP_Post    $post       The recipe.
	 * @param string|null $writer_url The writer's URL or null.
	 */
	public function author_line( \WP_Post $post, ?string $writer_url ): string {
		$user = get_userdata( (int) $post->post_author );
		$name = $user instanceof \WP_User ? $user->display_name : '';
		if ( '' === $name ) {
			return '';
		}
		$linked = null !== $writer_url ? '<a href="' . esc_url( $writer_url ) . '" rel="author">' . esc_html( $name ) . '</a>' : '<span>' . esc_html( $name ) . '</span>';
		/* translators: %s: the writer's name (linked) */
		return sprintf( esc_html__( 'By %s', 'bluefairy-recipes' ), $linked );
	}

	/**
	 * "250" / "12.5": up to two decimals, trailing zeros dropped.
	 *
	 * @param float $value The amount.
	 */
	public static function format_amount( float $value ): string {
		$decimals = fmod( $value, 1.0 ) === 0.0 ? 0 : ( fmod( $value * 10, 1.0 ) === 0.0 ? 1 : 2 );
		return number_format_i18n( $value, $decimals );
	}

	/**
	 * Screen-reader text for a link that opens a new tab.
	 */
	public static function new_tab_hint(): string {
		return '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'bluefairy-recipes' ) . '</span>';
	}

	/**
	 * Prime the thumbnail attachments of a grid in one go (core primes them
	 * only inside the loop, and the view blocks render outside it).
	 *
	 * @param array<int, \WP_Post> $posts The recipes.
	 */
	public function prime_thumbnails( array $posts ): void {
		$ids = [];
		foreach ( $posts as $post ) {
			$thumb = (int) get_post_thumbnail_id( $post );
			if ( $thumb > 0 ) {
				$ids[ $thumb ] = true;
			}
		}
		if ( [] !== $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_keys( $ids ), false, true );
		}
	}

	/**
	 * The writer's chosen image, or their Gravatar.
	 *
	 * @param \WP_User $user      The writer.
	 * @param int      $avatar_id Attachment id, 0 for none.
	 * @param int      $size      Pixels.
	 */
	private function avatar( \WP_User $user, int $avatar_id, int $size ): string {
		if ( $avatar_id > 0 ) {
			$image = wp_get_attachment_image(
				$avatar_id,
				[ $size, $size ],
				false,
				[
					'class' => 'bfrc-avatar',
					'alt'   => '',
				]
			);
			if ( '' !== $image ) {
				return $image;
			}
		}
		return (string) get_avatar( $user->ID, $size, '', '', [ 'class' => 'bfrc-avatar' ] );
	}

	/**
	 * The writer's links as a list (http/https only, re-checked here).
	 *
	 * @param array<string, string> $links key => URL.
	 */
	private function social_links( array $links ): string {
		$labels = WriterProfile::url_labels();
		$items  = '';
		foreach ( $links as $key => $url ) {
			$href = esc_url( $url, [ 'http', 'https' ] );
			if ( '' === $href ) {
				continue;
			}
			$items .= '<li><a class="bfrc-writer__link bfrc-writer__link--' . esc_attr( sanitize_html_class( str_replace( 'bfrc_', '', $key ) ) ) . '" href="' . $href . '" rel="noopener nofollow me" target="_blank">' . esc_html( $labels[ $key ] ?? $key ) . self::new_tab_hint() . '</a></li>';
		}
		return '' === $items ? '' : '<ul class="bfrc-writer__links">' . $items . '</ul>';
	}

	/**
	 * Prime the attachment cache for the writers' images in one go.
	 *
	 * @param array<int, int> $user_ids The writers.
	 */
	private function prime_profile_images( array $user_ids ): void {
		$ids = [];
		foreach ( $user_ids as $user_id ) {
			foreach ( [ WriterProfile::AVATAR_ID, WriterProfile::HEADER_ID ] as $key ) {
				$id = (int) get_user_meta( $user_id, $key, true );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		if ( [] !== $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), false, true );
		}
	}

	/**
	 * Run an output seam and capture what the add-on printed.
	 *
	 * @param string $hook      The action.
	 * @param int    $recipe_id The recipe.
	 */
	private function seam( string $hook, int $recipe_id ): string {
		ob_start();
		do_action( $hook, $recipe_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- every caller passes a bfrc_ hook name.
		return (string) ob_get_clean();
	}

	/**
	 * The taxonomy choices in the request, from the Templates service.
	 *
	 * @return array<string, string>
	 */
	private function current_filters(): array {
		$templates = Plugin::service( 'front.templates' );
		return $templates instanceof Templates ? $templates->requested_filters() : [];
	}

	/**
	 * The keyword in the request, from the Templates service.
	 */
	private function current_search(): string {
		$templates = Plugin::service( 'front.templates' );
		return $templates instanceof Templates ? $templates->requested_search() : '';
	}
}
