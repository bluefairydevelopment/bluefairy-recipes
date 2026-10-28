<?php
/**
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests\Unit;

use BlueFairy\Recipes\Admin\Samples;
use BlueFairy\Recipes\Admin\SettingsPage;
use BlueFairy\Recipes\Admin\WriterProfile;
use BlueFairy\Recipes\Capabilities;
use BlueFairy\Recipes\Content\Meta;
use BlueFairy\Recipes\Content\Taxonomies;
use BlueFairy\Recipes\Front\Blocks;
use BlueFairy\Recipes\Front\RecipeQuery;
use BlueFairy\Recipes\Front\Writers;
use BlueFairy\Recipes\Installer;
use BlueFairy\Recipes\Settings;
use BlueFairy\Recipes\Tests\TestCase;

/**
 * The documentation against the code. FEATURES.md, README.md and readme.txt
 * quote names, defaults, limits, roles and hooks; each quote is read back
 * here from the file and compared with what the code declares, so a change
 * on either side fails the build until the other follows. Every assertion
 * was seen red against an edited copy of the document.
 */
final class DocsTest extends TestCase {

	private string $features;
	private string $readme;
	private string $readme_txt;
	private string $changelog;
	/** @var array<string, string> */
	private array $header;

	protected function setUp(): void {
		parent::setUp();
		$this->features   = (string) file_get_contents( BFRC_DIR . 'FEATURES.md' );
		$this->readme     = (string) file_get_contents( BFRC_DIR . 'README.md' );
		$this->readme_txt = (string) file_get_contents( BFRC_DIR . 'readme.txt' );
		$this->changelog  = (string) file_get_contents( BFRC_DIR . 'CHANGELOG.md' );
		$entry            = (string) file_get_contents( BFRC_DIR . 'bluefairy-recipes.php' );
		$this->header     = [];
		foreach ( [ 'Version', 'Requires at least', 'Requires PHP', 'Text Domain', 'Plugin Name' ] as $field ) {
			$this->assertSame( 1, preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':\s*(.+)$/m', $entry, $m ), "$field in the plugin header" );
			$this->header[ $field ] = trim( $m[1] );
		}
	}

	/**
	 * The rows of the markdown table that follows a heading, as lists of cells.
	 *
	 * @return list<list<string>>
	 */
	private function table( string $doc, string $heading ): array {
		$start = strpos( $doc, "\n## $heading\n" );
		$this->assertNotFalse( $start, "section $heading" );
		$section = substr( $doc, $start + 1 );
		$end     = strpos( $section, "\n## ", 1 );
		$section = false === $end ? $section : substr( $section, 0, $end );
		$rows    = [];
		foreach ( explode( "\n", $section ) as $line ) {
			if ( str_starts_with( $line, '|' ) && ! str_starts_with( $line, '|---' ) ) {
				$cells  = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
				$rows[] = $cells;
			}
		}
		$this->assertGreaterThan( 1, count( $rows ), "a table under $heading" );
		return array_slice( $rows, 1 ); // Without the header row.
	}

	/** A document with its line breaks folded, so a sentence wrapped by the editor still matches. */
	private static function folded( string $doc ): string {
		return (string) preg_replace( '/\s+/', ' ', $doc );
	}

	public function test_the_settings_table_quotes_the_defaults_the_labels_and_the_bounds_of_the_code(): void {
		$rows = [];
		foreach ( $this->table( $this->features, 'Settings' ) as $cells ) {
			$rows[ $cells[0] ] = $cells[1];
		}
		$d        = Settings::DEFAULTS;
		$expected = [
			'Enabled'                      => $d['enabled'] ? 'On' : 'Off',
			'Rich snippets'                => $d['rich_snippets'] ? 'On' : 'Off',
			'Archive slug'                 => '`' . $d['archive_slug'] . '`',
			'Archive title'                => $d['archive_title'],
			'Recipes per page'             => (string) $d['per_page'],
			'Social share'                 => $d['share_enabled'] ? 'On' : 'Off',
			'Writer listing'               => $d['writers_listing'] ? 'On' : 'Off',
			'Grant core roles'             => $d['grant_core_roles'] ? 'On' : 'Off',
			'Delete all data on uninstall' => $d['delete_data_on_uninstall'] ? 'On' : 'Off',
			'Sample recipes'               => 'not installed',
		];
		$this->assertSame( $expected, array_intersect_key( $rows, $expected ), 'every default in the table is the code\'s default' );
		$this->assertSame( [], array_diff_key( $rows, $expected ), 'a row the test does not know is a setting the test must learn' );
		$covered = [ 'enabled', 'rich_snippets', 'archive_slug', 'archive_title', 'per_page', 'share_enabled', 'writers_listing', 'grant_core_roles', 'delete_data_on_uninstall' ];
		$this->assertStringContainsString( '| Social share | On | Facebook, X and Pinterest each have their own switch |', $this->features, 'the three network switches are covered by the Social share row' );
		$covered = array_merge( $covered, [ 'share_facebook', 'share_x', 'share_pinterest' ] );
		$this->assertSame( [], array_diff( array_keys( array_diff_key( $d, [ 'taxonomies' => 1 ] ) ), $covered ), 'every scalar setting has a row (the filter types have their own table)' );
		$this->assertSame( count( $covered ) - 3 + 1, count( $expected ), 'one row per setting, plus the samples row' );

		$settings = new Settings();
		$this->assertSame( [ 1, 100 ], [ $settings->patch( [ 'per_page' => 0 ] )['per_page'], $settings->patch( [ 'per_page' => 101 ] )['per_page'] ], 'the bounds the table quotes' );
		$this->assertStringContainsString( '| Recipes per page | 12 | 1–100 |', $this->features );

		// The labels: every setting in the table is a field on its tab, labelled as the table names it.
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$GLOBALS['bfrc_test_is_admin']      = true;
		Capabilities::add_roles();
		$page = new SettingsPage( $settings, new Taxonomies( $settings ), new Samples() );
		$html = '';
		foreach ( array_keys( SettingsPage::TAB_KEYS ) as $tab ) {
			ob_start();
			$page->render_form( $tab );
			$html .= (string) ob_get_clean();
		}
		foreach ( array_keys( $expected ) as $label ) {
			if ( 'Sample recipes' === $label ) {
				continue; // The samples box is a heading on the Data tab, not a field.
			}
			$this->assertStringContainsString( '>' . $label . '</label>', $html, "label $label on its tab" );
		}
	}

	public function test_the_filter_types_table_names_the_taxonomies_and_their_starter_terms(): void {
		$GLOBALS['bfrc_test_fired']['init'] = 1;
		$system                             = Taxonomies::system();
		$rows                               = $this->table( $this->features, 'Filter types (taxonomies)' );
		$this->assertCount( count( $system ), $rows );
		foreach ( $rows as $i => $cells ) {
			$code = array_keys( $system )[ $i ];
			$def  = $system[ $code ];
			$this->assertSame( $def['label'], $cells[0], "row $i label" );
			$this->assertSame( '`' . Taxonomies::taxonomy_name( $code ) . '`', $cells[1], "row $i taxonomy" );
			$this->assertSame( '`/recipes/' . str_replace( '_', '-', $code ) . '/…`', $cells[2], "row $i URL" );
			$this->assertSame( implode( ', ', $def['terms'] ), $cells[3], "row $i starter terms" );
		}
	}

	public function test_the_roles_table_names_the_roles_and_what_they_can_do(): void {
		$rows = $this->table( $this->features, 'Roles' );
		$this->assertSame( [ '`' . Capabilities::ROLE_WRITER . '`', '`' . Capabilities::ROLE_MANAGER . '`' ], array_map( static fn( array $r ): string => (string) preg_replace( '/^.* \(|\)$/', '', $r[0] ), $rows ), 'the two role slugs' );
		Capabilities::add_roles();
		$this->assertSame( [ 'Recipe Writer', 'Recipe Manager' ], [ $GLOBALS['bfrc_test_role_names'][ Capabilities::ROLE_WRITER ] ?? null, $GLOBALS['bfrc_test_role_names'][ Capabilities::ROLE_MANAGER ] ?? null ], 'the display names the table starts its rows with' );
		$this->assertStringStartsWith( 'Recipe Writer', $rows[0][0] );
		$this->assertStringStartsWith( 'Recipe Manager', $rows[1][0] );
		foreach ( [ 'create' => 'edit_bfrc_recipes', 'edit' => 'edit_bfrc_recipes', 'publish' => 'publish_bfrc_recipes', 'delete' => 'delete_bfrc_recipes', 'assign terms' => 'assign_bfrc_terms', 'upload files' => 'upload_files' ] as $phrase => $cap ) {
			$this->assertStringContainsString( $phrase, $rows[0][1], "writers: $phrase" );
			$this->assertContains( $cap, Capabilities::WRITER_CAPS, "writers hold $cap" );
		}
		$this->assertStringContainsString( 'for every recipe', $rows[1][1] );
		$this->assertContains( 'edit_others_bfrc_recipes', Capabilities::MANAGER_EXTRA_CAPS, 'managers reach every recipe' );
		$this->assertContains( 'manage_bfrc_terms', Capabilities::MANAGER_EXTRA_CAPS, 'the table says managers manage the taxonomies' );
		$this->assertContains( Capabilities::MANAGE, Capabilities::MANAGER_EXTRA_CAPS, 'and change settings' );
		$this->assertStringContainsString( 'change settings', $rows[1][1] );
	}

	public function test_every_hook_in_the_extending_table_fires_in_the_code_and_every_hook_in_the_code_is_documented(): void {
		$documented = [];
		foreach ( $this->table( $this->features, 'Extending' ) as $cells ) {
			$documented[ trim( $cells[0], '`' ) ] = $cells[1];
		}
		$in_code = [];
		$files   = array_merge( [ BFRC_DIR . 'bluefairy-recipes.php', BFRC_DIR . 'uninstall.php' ], glob( BFRC_DIR . 'includes/*.php' ) ?: [], glob( BFRC_DIR . 'includes/*/*.php' ) ?: [], glob( BFRC_DIR . 'templates/*.php' ) ?: [] );
		$this->assertGreaterThan( 20, count( $files ), 'the includes tree was found' );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			preg_match_all( '/\b(do_action|apply_filters|seam)(?:_ref_array|_deprecated)?\(\s*[\'"](bfrc_[a-z0-9_]+)[\'"]/', $source, $m, PREG_SET_ORDER );
			foreach ( $m as $hit ) {
				$in_code[ $hit[2] ] = 'apply_filters' === $hit[1] ? 'filter' : 'action'; // seam() wraps do_action().
			}
			$without_seam = (string) preg_replace( '/do_action\( \$hook, \$recipe_id \)/', '', $source );
			$this->assertSame( 0, preg_match( '/\b(?:do_action|apply_filters)(?:_ref_array|_deprecated)?\(\s*(?:[^\'"\s)]|"[^"]*\$)/', $without_seam ), basename( $file ) . ': a hook fired by a variable, computed or interpolated name would escape this sweep (seam() is the one allowed, and its callers are literal)' );
		}
		ksort( $in_code );
		ksort( $documented );
		$this->assertSame( $in_code, $documented, 'the Extending table and the code name the same hooks with the same kind, no more, no less' );
		$architecture = [];
		foreach ( $this->table( (string) file_get_contents( BFRC_DIR . 'docs/ARCHITECTURE.md' ), 'Extension seams (this list is mirrored in FEATURES.md "Extending")' ) as $cells ) {
			$architecture[ trim( $cells[0], '`' ) ] = str_starts_with( $cells[1], 'filter' ) ? 'filter' : 'action';
		}
		ksort( $architecture );
		$this->assertSame( $in_code, $architecture, 'docs/ARCHITECTURE.md\'s seams table is the same list, as it says it is' );
		$this->assertGreaterThan( 20, count( $in_code ), 'denominator: the seams found in the code' );
	}

	public function test_the_blocks_table_names_the_registered_blocks_and_the_shortcodes(): void {
		$titles = [];
		foreach ( array_keys( Blocks::BLOCKS ) as $dir ) {
			$json     = json_decode( (string) file_get_contents( BFRC_DIR . 'build/blocks/' . $dir . '/block.json' ), true );
			$titles[] = is_array( $json ) ? (string) $json['title'] : '';
		}
		$rows        = $this->table( $this->features, 'Blocks and shortcodes' );
		$table_names = array_map( static fn( array $r ): string => $r[0], $rows );
		foreach ( $titles as $title ) {
			$this->assertContains( $title, $table_names, "block $title has a row" );
		}
		$this->assertContains( 'Recipe Writers (template)', $table_names, 'the writers template row' );
		$this->assertCount( count( $titles ) + 1, $rows, 'no row for a block that does not exist' );
		$shortcodes = implode( ' ', array_map( static fn( array $r ): string => $r[1], $rows ) );
		foreach ( [ Blocks::SHORTCODE_RECIPES, Blocks::SHORTCODE_FEATURED, Blocks::SHORTCODE_WRITERS ] as $tag ) {
			$this->assertStringContainsString( '[' . $tag, $shortcodes, "shortcode $tag in the table" );
		}
	}

	public function test_the_limits_the_guide_quotes_are_the_constants(): void {
		$folded = self::folded( $this->features );
		foreach ( [
			'up to ' . Meta::MAX_ROWS . ' rows of measure (≤ ' . Meta::MEASURE_MAX . ' characters) and label (≤ ' . Meta::LABEL_MAX . ')',
			'up to ' . Meta::MAX_ROWS . ' numbered steps',
			'whole minutes, 0–' . Meta::MAX_MINUTES,
			'| Servings min, Servings max | 0–' . Meta::MAX_SERVINGS . ' |',
			'up to ' . WriterProfile::ABOUT_MAX . ' characters',
			'name order, ' . Writers::PER_PAGE . ' per page',
			'up to ' . RecipeQuery::SEARCH_MAX . ' characters), `page` (1–' . RecipeQuery::MAX_PAGE . '), `per_page` (1–' . RecipeQuery::MAX_PER_PAGE . '; ' . ( RecipeQuery::MAX_PER_PAGE + 1 ) . ' is refused)',
			'without a reload (' . Blocks::DEBOUNCE_MS . ' ms)',
		] as $quote ) {
			$this->assertStringContainsString( $quote, $folded, $quote );
		}
		$this->assertStringContainsString( 'recipe of ' . Meta::MAX_ROWS . ' ingredients and ' . Meta::MAX_ROWS . ' steps', self::folded( $this->readme ) );
		$this->assertStringContainsString( 'numbers up to ' . number_format( Meta::MAX_NUTRITION ) . ' with two decimals', $folded, 'the nutrition bound' );
		$this->assertStringContainsString( 'may be cached for ' . \BlueFairy\Recipes\Rest\Recipes::MAX_AGE . ' seconds', $folded, 'the REST cache age' );
		$this->assertStringContainsString( 'Stored in one option, `' . Settings::OPTION . '`', $folded, 'the option name' );
		$featured = json_decode( (string) file_get_contents( BFRC_DIR . 'build/blocks/featured-recipes/block.json' ), true );
		$this->assertStringContainsString( 'the newest ones, ' . $featured['attributes']['count']['minimum'] . ' to ' . $featured['attributes']['count']['maximum'] . ' cards', $folded, 'the Featured block\'s range' );
	}

	public function test_the_readmes_quote_the_plugin_header_and_name_the_release(): void {
		$txt = $this->readme_txt;
		foreach ( [ 'Requires at least', 'Requires PHP' ] as $field ) {
			$this->assertSame( 1, preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/m', $txt, $m ), $field );
			$this->assertSame( $this->header[ $field ], trim( $m[1] ), "$field in readme.txt equals the plugin header" );
		}
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(.+)$/m', $txt, $m ) );
		$this->assertSame( $this->header['Version'], trim( $m[1] ), 'Stable tag is the version' );
		$this->assertStringStartsWith( '=== ' . $this->header['Plugin Name'] . ' ===', $txt );
		$this->assertStringContainsString( "= {$this->header['Version']} =", $txt, 'the changelog names the version' );
		$this->assertStringContainsString( "## {$this->header['Version']}", $this->changelog );
		foreach ( [ '== Description ==', '== Installation ==', '== Frequently Asked Questions ==', '== External services ==', '== Development ==', '== Changelog ==', '== Upgrade Notice ==' ] as $section ) {
			$this->assertStringContainsString( "\n$section\n", $txt, $section );
		}
		$this->assertStringContainsString( 'https://github.com/bluefairydevelopment/bluefairy-recipes', $txt, 'the Development section links the source' );
		$this->assertStringContainsString( '- WordPress ' . $this->header['Requires at least'] . ' or newer', $this->readme );
		$this->assertStringContainsString( '- PHP ' . $this->header['Requires PHP'] . ' or newer', $this->readme );
		$this->assertStringContainsString( 'WordPress ' . $this->header['Requires at least'] . ' or newer (pretty permalinks recommended: with plain permalinks everything works, but the writer pages live at query-string addresses), PHP ' . $this->header['Requires PHP'] . ' or newer', $txt, 'one story with README.md\'s Plain permalinks note' );
		$this->assertStringContainsString( '**Plain permalinks** (no permalink structure) are not recommended', $this->readme );
		$this->assertStringNotContainsString( 'full history', $txt, 'the public repository is promised for source and tests, not its history' );
		$this->assertStringContainsString( "\n== Screenshots ==\n", $txt, 'the WordPress.org listing has screenshots (screenshot-1..5 in the SVN assets folder, in this order)' );
		$this->assertSame( 5, preg_match_all( '/^\d\. /m', (string) strstr( (string) strstr( $txt, '== Screenshots ==' ), '== Changelog ==', true ) ), 'one caption per image in docs/images' );
		[ $major, $minor ] = array_map( 'intval', explode( '.', $this->header['Requires PHP'] ) );
		$this->assertSame( 1, preg_match( '/^\s*phpVersion:\s*(\d+)/m', (string) file_get_contents( BFRC_DIR . 'phpstan.neon' ), $m ) );
		$this->assertSame( $major * 10000 + $minor * 100, (int) $m[1], 'phpstan analyses the code as the PHP version the header promises, so a newer-only type fails the gate' );
	}

	public function test_the_multisite_notes_describe_what_the_installer_does(): void {
		$this->assertStringContainsString( "add_action( 'wp_initialize_site', [ Installer::class, 'initialize_site' ], 20 );", (string) file_get_contents( BFRC_DIR . 'includes/Plugin.php' ), 'a site created later is provisioned' );
		$this->assertStringContainsString( 'network-activate the plugin or activate it on each site that uses it', self::folded( $this->readme ) );
		$this->assertStringContainsString( 'including one created after network activation', self::folded( $this->readme ) );
		$this->assertStringContainsString( 'network-activate it or activate it on each site that uses it', self::folded( $this->readme_txt ) );
		$this->assertStringContainsString( 'a site created later is set up on its own', self::folded( $this->features ) );
	}

	public function test_no_document_still_carries_a_placeholder(): void {
		foreach ( [ 'FEATURES.md' => $this->features, 'README.md' => $this->readme, 'readme.txt' => $this->readme_txt, 'CHANGELOG.md' => $this->changelog ] as $name => $doc ) {
			foreach ( [ 'later milestone', 'In development', 'TODO', 'TBD', 'lorem', 'single-site product', 'network activation is not supported', 'not a tested' ] as $placeholder ) {
				$this->assertStringNotContainsStringIgnoringCase( $placeholder, $doc, "$placeholder in $name" );
			}
		}
		$this->assertStringContainsString( "\n## Differences from the Magento extension\n", $this->features );
		$this->assertGreaterThan( 5, substr_count( substr( $this->features, (int) strpos( $this->features, '## Differences' ) ), "\n- " ), 'the differences list is written' );
	}

	public function test_the_shipped_changelog_dates_the_current_version_and_the_header_names_the_languages_folder(): void {
		$this->assertSame( 1, preg_match( '/^## ' . preg_quote( $this->header['Version'], '/' ) . ' — (\S+)$/m', $this->changelog, $m ), 'a heading for the current version' );
		// Between releases the heading holds the placeholder; bin/release-date.php --set dates it on the tag day and
		// --check (exit 1) stops a zip built before that. Never "unreleased", never a guessed date.
		$this->assertMatchesRegularExpression( '/^(\d{4}-\d{2}-\d{2}|RELEASE-DATE)$/', $m[1], 'a date, or the placeholder the release step replaces' );
		$entry = (string) file_get_contents( BFRC_DIR . 'bluefairy-recipes.php' );
		$this->assertMatchesRegularExpression( '/^ \* Domain Path:\s*\/languages$/m', $entry );
		$this->assertFileExists( BFRC_DIR . 'languages/bluefairy-recipes.pot' );
	}
}
