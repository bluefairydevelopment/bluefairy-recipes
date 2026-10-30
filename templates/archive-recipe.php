<?php
/**
 * Classic-theme template for every recipe list view: the archive with
 * filters, a filter type's recipes, a writer's recipes, or the writers
 * listing. Override by copying to `{theme}/bluefairy-recipes/archive-recipe.php`.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
echo '<main id="bfrc-main" class="bfrc-main">';
$bfrc_renderer = \BlueFairy\Recipes\Plugin::service( 'front.renderer' );
if ( $bfrc_renderer instanceof \BlueFairy\Recipes\Front\Renderer && $GLOBALS['wp_query'] instanceof WP_Query ) {
	echo $bfrc_renderer->archive( $GLOBALS['wp_query'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes every value.
}
echo '</main>';
get_footer();
