<?php
/**
 * A recording, scriptable stand-in for $wpdb.
 *
 * @package BlueFairy\Recipes
 */

declare(strict_types=1);

namespace BlueFairy\Recipes\Tests;

// phpcs:disable WordPress.DB, WordPress.NamingConventions.ValidVariableName, Squiz.Commenting

/**
 * Every call is recorded in $calls (method, sql, args, table, data). Return
 * values come from a per-method FIFO queue (queue()); when the queue is empty
 * the method returns an empty/neutral value. prepare() performs WordPress's
 * placeholder substitution so tests can assert on the final SQL text.
 *
 * It never executes SQL. Tests that need real SQL semantics (the HitCounter
 * concurrency test) use SqliteWpdb from tests/stubs instead.
 */
class FakeWpdb {

	public string $prefix   = 'bfw_';
	public string $options  = 'bfw_options';
	public string $posts    = 'bfw_posts';
	public string $users    = 'bfw_users';
	public string $postmeta = 'bfw_postmeta';
	public string $terms    = 'bfw_terms';
	public string $term_taxonomy = 'bfw_term_taxonomy';
	public string $term_relationships = 'bfw_term_relationships';
	public string $termmeta = 'bfw_termmeta';
	public string $usermeta = 'bfw_usermeta';
	/** A statement repeated this often in one test is a spinning loop, not a plan. */
	public const MAX_REPEATS = 50;

	/** @var array<string, int> */
	private array $repeats = [];

	public int $insert_id   = 0;
	public int $rows_affected = 0;
	public string $last_error = '';
	public string $last_query = '';

	/** @var list<array<string, mixed>> */
	public array $calls = [];

	/** @var array<string, list<mixed>> */
	private array $queue = [];

	/** @var array<string, mixed> */
	private array $fallback = [];

	/**
	 * Unprefixed table names `SHOW TABLES LIKE` reports as absent. Every other
	 * table exists: the query answers with the table name, as MySQL does, and
	 * never consumes the get_var queue.
	 *
	 * @var list<string>
	 */
	private array $missing_tables = [];

	/**
	 * Rows get_row() answers by primary key, per unprefixed table: a
	 * `SELECT … FROM <prefix><table> WHERE id = N` is answered from here
	 * (recorded, the get_row queue untouched) when N is listed.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows_by_id = [];

	/**
	 * Answer get_row() lookups by id on $table from $rows (each with an `id`),
	 * as `find()` reads them — whatever order the code asks in.
	 *
	 * @param string                           $table Unprefixed table name.
	 * @param list<array<string, mixed>>       $rows  Rows.
	 */
	public function rows_by_id( string $table, array $rows ): void {
		foreach ( $rows as $row ) {
			$this->rows_by_id[ $table ][ (int) $row['id'] ] = $row;
		}
	}

	/**
	 * Make `SHOW TABLES LIKE` report these (unprefixed) tables as absent.
	 *
	 * @param list<string> $tables Unprefixed table names.
	 */
	public function missing_tables( array $tables ): void {
		$this->missing_tables = $tables;
	}

	/**
	 * Core's per-site table names this fake does not declare: `comments`
	 * follows the prefix (the Pro harness overrides this with its own).
	 */
	public function __get( string $name ): mixed {
		return 'comments' === $name ? $this->prefix . 'comments' : null;
	}

	/**
	 * Queue the next return value for a method (get_results, get_row,
	 * get_var, get_col, query, insert, update, delete, replace).
	 */
	public function queue( string $method, mixed $value ): void {
		$this->queue[ $method ][] = $value;
	}

	/**
	 * A standing return value for a method, used when its queue is empty.
	 */
	public function always( string $method, mixed $value ): void {
		$this->fallback[ $method ] = $value;
	}

	/**
	 * SQL of every recorded call whose text contains $needle.
	 *
	 * @return list<string>
	 */
	public function queries_matching( string $needle ): array {
		$out = [];
		foreach ( $this->calls as $call ) {
			if ( isset( $call['sql'] ) && str_contains( (string) $call['sql'], $needle ) ) {
				$out[] = (string) $call['sql'];
			}
		}
		return $out;
	}

	/**
	 * Recorded calls of one method.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function calls_to( string $method ): array {
		return array_values( array_filter( $this->calls, static fn( array $c ): bool => $c['method'] === $method ) );
	}

	public function prepare( string $query, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( "'%s'", '%s', $query );
		$query = str_replace( '"%s"', '%s', $query );
		$query = preg_replace( '/(?<!%)%(?!%)(?:\d+\$)?s/', "'%s'", $query ) ?? $query;
		$query = preg_replace( '/(?<!%)%(?!%)(?:\d+\$)?i/', '`%s`', $query ) ?? $query;
		$escaped = array_map(
			static fn( mixed $a ): mixed => is_string( $a ) ? addslashes( $a ) : ( is_float( $a ) ? $a : (int) $a ),
			$args
		);
		return str_replace( '%%', '%', vsprintf( $query, $escaped ) );
	}

	/** As core: the prefix of the current site's tables (`{prefix}{id}_` on a sub-site). */
	public function get_blog_prefix( ?int $blog_id = null ): string {
		$id = $blog_id ?? get_current_blog_id();
		return $id > 1 ? $this->prefix . $id . '_' : $this->prefix;
	}

	/**
	 * Writers::listing_from()'s two statements, answered from the stubs'
	 * stores as MySQL would: published recipes grouped by author, joined to
	 * the users, listed by the switch or by the Writer role (the user's
	 * roles stand for the capabilities row), display name then id.
	 *
	 * @return list<object>|null Null when the statement is not the listing.
	 */
	private function writers_listing( string $query ): ?array {
		if ( 1 !== preg_match( "/FROM \\( SELECT post_author AS id, COUNT\\(\\*\\) AS n FROM \\S+posts WHERE post_type = '([^']+)' AND post_status = 'publish' GROUP BY post_author \\) r INNER JOIN \\S+users u ON u\\.ID = r\\.id WHERE .*meta_key = '([^']+)' AND lm\\.meta_value = '1'.* c\\.meta_value LIKE '%\\\\?\"([a-z\\\\_]+)\\\\?\"%'/s", $query, $m ) ) {
			return null;
		}
		$role   = stripslashes( str_replace( '\\_', '_', $m[3] ) );
		$counts = [];
		foreach ( $GLOBALS['bfrc_test_posts'] ?? [] as $post ) {
			if ( $post->post_type === $m[1] && 'publish' === $post->post_status ) {
				$counts[ (int) $post->post_author ] = ( $counts[ (int) $post->post_author ] ?? 0 ) + 1;
			}
		}
		$rows = [];
		foreach ( $counts as $id => $n ) {
			$user = $GLOBALS['bfrc_test_users'][ $id ] ?? null;
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$switch = (string) ( $GLOBALS['bfrc_test_user_meta'][ $id ][ $m[2] ] ?? '' );
			if ( '1' !== $switch && ( '0' === $switch || ! in_array( $role, (array) $user->roles, true ) ) ) {
				continue;
			}
			$rows[] = (object) [ 'ID' => (string) $id, 'display_name' => (string) $user->display_name, 'user_nicename' => (string) $user->user_nicename, 'n' => (string) $n ];
		}
		usort( $rows, static fn( object $a, object $b ): int => ( strcasecmp( $a->display_name, $b->display_name ) ?: ( (int) $a->ID <=> (int) $b->ID ) ) );
		return $rows;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function db_version(): string {
		return '8.0.36';
	}

	public function get_results( string $query, string $output = 'OBJECT' ): mixed {
		$listing = $this->writers_listing( $query );
		if ( null !== $listing && 1 === preg_match( '/LIMIT (\\d+) OFFSET (\\d+)$/', $query, $m ) ) {
			$this->calls[] = [ 'method' => 'get_results', 'sql' => $query ];
			return array_slice( $listing, (int) $m[2], (int) $m[1] );
		}
		return $this->record( 'get_results', $query, [] );
	}

	public function get_row( string $query, string $output = 'OBJECT', int $y = 0 ): mixed {
		if ( 1 === preg_match( '/FROM ' . preg_quote( $this->prefix, '/' ) . '([a-z_]+) WHERE id = (\d+)/', $query, $m ) && isset( $this->rows_by_id[ $m[1] ][ (int) $m[2] ] ) ) {
			$this->last_query = $query;
			$this->calls[]    = [ 'method' => 'get_row', 'sql' => $query ];
			return $this->rows_by_id[ $m[1] ][ (int) $m[2] ];
		}
		return $this->record( 'get_row', $query, null );
	}

	public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
		if ( str_starts_with( $query, 'SELECT COUNT(*) FROM ( SELECT post_author' ) ) {
			$listing = $this->writers_listing( $query );
			if ( null !== $listing ) {
				$this->calls[] = [ 'method' => 'get_var', 'sql' => $query ];
				return (string) count( $listing );
			}
		}
		if ( 1 === preg_match( "/^SHOW TABLES LIKE '([^']+)'$/", $query, $m ) ) {
			$this->last_query = $query;
			$this->calls[]    = [ 'method' => 'get_var', 'sql' => $query ];
			// prepare() addslashes the value and esc_like() escaped `_`; undo both.
			$table            = str_replace( [ '\\_', '\\%' ], [ '_', '%' ], stripslashes( $m[1] ) );
			$unprefixed       = str_starts_with( $table, $this->prefix ) ? substr( $table, strlen( $this->prefix ) ) : $table;
			return in_array( $unprefixed, $this->missing_tables, true ) ? null : $table;
		}
		return $this->record( 'get_var', $query, null );
	}

	public function get_col( string $query, int $x = 0 ): mixed {
		return $this->record( 'get_col', $query, [] );
	}

	public function query( string $query ): mixed {
		$result              = $this->record( 'query', $query, 0 );
		$this->rows_affected = is_int( $result ) ? $result : 0;
		return $result;
	}

	public function insert( string $table, array $data, mixed $format = null ): int|false {
		$this->calls[] = [ 'method' => 'insert', 'table' => $table, 'data' => $data, 'format' => $format ];
		$queued        = $this->next( 'insert', 1 );
		if ( false !== $queued ) {
			++$this->insert_id;
		}
		return $queued;
	}

	public function replace( string $table, array $data, mixed $format = null ): int|false {
		$this->calls[] = [ 'method' => 'replace', 'table' => $table, 'data' => $data, 'format' => $format ];
		return $this->next( 'replace', 1 );
	}

	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
		$this->calls[] = [ 'method' => 'update', 'table' => $table, 'data' => $data, 'where' => $where ];
		return $this->next( 'update', 1 );
	}

	public function delete( string $table, array $where, mixed $where_format = null ): int|false {
		$this->calls[] = [ 'method' => 'delete', 'table' => $table, 'where' => $where ];
		return $this->next( 'delete', 1 );
	}

	public function remove_placeholder_escape( string $query ): string {
		return $query;
	}

	private function record( string $method, string $sql, mixed $default ): mixed {
		$this->last_query = $sql;
		$this->calls[]    = [ 'method' => $method, 'sql' => $sql ];
		$this->repeats[ $sql ] = ( $this->repeats[ $sql ] ?? 0 ) + 1;
		if ( $this->repeats[ $sql ] > self::MAX_REPEATS ) {
			throw new \RuntimeException( 'FakeWpdb: the same statement ran more than ' . self::MAX_REPEATS . ' times — a loop is spinning: ' . $sql ); // fail fast instead of exhausting memory
		}
		return $this->next( $method, $default );
	}

	private function next( string $method, mixed $default ): mixed {
		$value = ! empty( $this->queue[ $method ] ) ? array_shift( $this->queue[ $method ] ) : ( $this->fallback[ $method ] ?? $default );
		if ( $value instanceof \Closure ) {
			$value = $value(); // A queued closure runs when the call happens, so a test can make the database throw.
		}
		return $value;
	}
}
