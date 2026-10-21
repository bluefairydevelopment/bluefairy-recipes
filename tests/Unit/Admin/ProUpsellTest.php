<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit\Admin;

use BlueFairy\Recipes\Admin\ProUpsell;
use BlueFairy\Recipes\Tests\TestCase;

final class ProUpsellTest extends TestCase {

	public function test_the_link_and_the_callout_show_until_the_add_on_says_it_is_running(): void {
		$upsell = new ProUpsell();
		$upsell->register_hooks();
		$this->assertSame( 10, has_filter( 'plugin_action_links_' . plugin_basename( BFRC_FILE ), [ $upsell, 'action_links' ] ) );
		$this->assertSame( 10, has_action( 'bfrc_settings_page_footer', [ $upsell, 'render' ] ) );
		$links = $upsell->action_links( [ '<a href="x">Settings</a>' ] );
		$this->assertCount( 2, $links );
		$this->assertStringContainsString( ProUpsell::PRODUCT_URL, $links[1] );
		$this->assertStringContainsString( 'Get Pro', $links[1] );
		$this->assertCount( 1, $upsell->action_links( 'not an array' ), 'a filter chain that lost the array still gets the link' );
		ob_start();
		$upsell->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<div class="bfrc-upsell notice notice-info inline"><h2>BlueFairy Recipes Pro</h2>', $html );
		$this->assertCount( 4, $upsell->features(), 'reviews, submissions, WooCommerce, custom filter types: what Pro ships' );
		foreach ( $upsell->features() as $feature ) {
			$this->assertStringContainsString( esc_html( $feature ), $html );
		}
		add_filter( 'bfrc_is_pro', '__return_true' );
		$this->assertSame( [ 'a' ], $upsell->action_links( [ 'a' ] ), 'hidden when Pro is running' );
		ob_start();
		$upsell->render();
		$this->assertSame( '', ob_get_clean() );
	}
}
