<?php
/**
 * Classic-theme template for one recipe. Override by copying to
 * `{theme}/bluefairy-recipes/single-recipe.php`.
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
while ( have_posts() ) {
	the_post();
	$bfrc_post = get_post();
	if ( $bfrc_renderer instanceof \BlueFairy\Recipes\Front\Renderer && $bfrc_post instanceof WP_Post ) {
		echo $bfrc_renderer->single( $bfrc_post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes every value.
	}
}
echo '</main>';
get_footer();
