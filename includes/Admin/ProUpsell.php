<?php
/**
 * The Pro add-on's callout and plugins-row link.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Admin;

use BlueFairy\Recipes\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One "Get Pro" link in the plugins row and one callout at the foot of the
 * settings page, both gone the moment `bfrc_is_pro` says the add-on is
 * running (an advert for something the owner already has makes the product
 * look like it does not know its own state). Every feature named here is
 * what the add-on ships; nothing in the free plugin is limited to sell it.
 */
final class ProUpsell {

	/**
	 * The add-on's product page.
	 */
	public const PRODUCT_URL = 'https://wordpress.bluefairydevelopment.com/product/bluefairy-recipes-pro/';

	/**
	 * Hook registration.
	 */
	public function register_hooks(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( BFRC_FILE ), [ $this, 'action_links' ] );
		add_action( 'bfrc_settings_page_footer', [ $this, 'render' ] );
	}

	/**
	 * What the add-on adds.
	 *
	 * @return list<string>
	 */
	public function features(): array {
		return [
			__( 'Visitor reviews with star ratings, moderation and a star rating in the recipe\'s search markup', 'bluefairy-recipes' ),
			__( 'Recipe submissions from visitors, with photos, approval and admin notes', 'bluefairy-recipes' ),
			__( 'WooCommerce: add a recipe\'s ingredients to the cart, affiliate ingredient links, related recipes on product pages', 'bluefairy-recipes' ),
			__( 'Your own filter types beyond meal type, diet, season and cuisine', 'bluefairy-recipes' ),
		];
	}

	/**
	 * `plugin_action_links_{basename}`: a "Get Pro" link after Settings.
	 *
	 * @param mixed $links The row's links (core passes an array).
	 * @return array<int|string, string>
	 */
	public function action_links( mixed $links ): array {
		$links = is_array( $links ) ? $links : [];
		if ( Plugin::is_pro() ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( self::PRODUCT_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get Pro', 'bluefairy-recipes' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'bluefairy-recipes' ) . '</span></a>';
		return $links;
	}

	/**
	 * `bfrc_settings_page_footer`: the callout, unless Pro is active.
	 */
	public function render(): void {
		if ( Plugin::is_pro() ) {
			return;
		}
		echo '<div class="bfrc-upsell notice notice-info inline"><h2>' . esc_html__( 'BlueFairy Recipes Pro', 'bluefairy-recipes' ) . '</h2><ul>';
		foreach ( $this->features() as $feature ) {
			echo '<li>' . esc_html( $feature ) . '</li>';
		}
		echo '</ul><p><a class="button button-primary" href="' . esc_url( self::PRODUCT_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'See BlueFairy Recipes Pro', 'bluefairy-recipes' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'bluefairy-recipes' ) . '</span></a></p></div>';
	}
}
