<?php
/**
 * Timezone behaviour of DFV_Store, run without WordPress.
 *
 *   php tests/timezone-test.php            (exit 0 = all pass)
 *
 * WordPress core is shimmed the way WP 5.3+ actually behaves - in particular
 * get_gmt_from_date() NEVER fails (it returns the Unix epoch for garbage),
 * which is exactly why the store validates the day itself before calling it.
 * The zone under test is switchable so the daylight-saving cases run too.
 *
 * @package DiviFormVault
 */

define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['dfv_test_tz'] = 'Asia/Kuala_Lumpur';

function wp_timezone() { return new DateTimeZone( $GLOBALS['dfv_test_tz'] ); }
function get_gmt_from_date( $s, $f = 'Y-m-d H:i:s' ) {
	$d = date_create_immutable( $s, wp_timezone() );
	return $d ? $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( $f ) : gmdate( $f, 0 );
}
function get_date_from_gmt( $s, $f = 'Y-m-d H:i:s' ) {
	$d = date_create_immutable( $s, new DateTimeZone( 'UTC' ) );
	return $d ? $d->setTimezone( wp_timezone() )->format( $f ) : gmdate( $f, 0 );
}
class DFV_Install { public static function table_name() { return 'wp_dfv_submissions'; } }
class WPDB {
	public $last_sql = '';
	public $col_rows = array();
	public function prepare( $sql, $args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
	public function get_results( $sql ) { $this->last_sql = $sql; return array(); }
	public function get_col( $sql ) { $this->last_sql = $sql; return $this->col_rows; }
	public function esc_like( $s ) { return $s; }
}
$wpdb = new WPDB();
require dirname( __DIR__ ) . '/includes/class-dfv-store.php';

$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
$where = new ReflectionMethod( 'DFV_Store', 'build_where' );

// --- Kuala Lumpur (+08:00, no DST) ------------------------------------------
ok( DFV_Store::local_day_edge_to_gmt( '2026-09-15', '00:00:00' ) === '2026-09-14 16:00:00', 'KL from-edge -> GMT' );
ok( DFV_Store::local_day_edge_to_gmt( '2026-09-15', '23:59:59' ) === '2026-09-15 15:59:59', 'KL to-edge -> GMT' );
list( , $vals ) = $where->invoke( null, array( 'date_from' => '2026-09-15', 'date_to' => '2026-09-15' ) );
ok( $vals === array( '2026-09-14 16:00:00', '2026-09-15 15:59:59' ), 'build_where binds GMT edges: ' . json_encode( $vals ) );

ok( DFV_Store::local_time( '2026-09-15 15:30:00', 'Y-m-d H:i' ) === '2026-09-15 23:30', 'display -> site time' );
ok( DFV_Store::local_time( '2026-09-15 17:00:00', 'Y-m-d H:i' ) === '2026-09-16 01:00', 'display rolls to next local day' );

// Invalid days must NEVER become a wider bound (the privacy-delete path).
ok( DFV_Store::local_day_edge_to_gmt( '2026-13-01', '00:00:00' ) === '2026-13-01 00:00:00', 'month 13 returned raw, not epoch' );
ok( DFV_Store::local_day_edge_to_gmt( '2026-02-30', '23:59:59' ) === '2026-02-30 23:59:59', 'Feb 30 returned raw, not rolled to March' );
ok( DFV_Store::local_day_edge_to_gmt( 'garbage', '00:00:00' ) === 'garbage 00:00:00', 'garbage returned raw' );
ok( DFV_Store::local_day_edge_to_gmt( '2024-02-29', '00:00:00' ) === '2024-02-28 16:00:00', 'leap day accepted' );

// Unparseable stored values render verbatim, never as the epoch or "now".
ok( DFV_Store::local_time( 'bad', 'Y-m-d' ) === 'bad', 'garbage stored value returned raw' );
ok( DFV_Store::local_time( '', 'Y-m-d' ) === '', 'empty stored value returned raw' );
ok( DFV_Store::local_time( '0000-00-00 00:00:00', 'Y-m-d' ) === '0000-00-00 00:00:00', 'zero date returned raw' );

// Bucketing only - the WPDB stub does not apply the WHERE, so the fixture is
// exactly the rows a real query would return for local 09-14 .. 09-16.
$wpdb->col_rows = array( '2026-09-13 16:00:00', '2026-09-14 15:59:59', '2026-09-14 16:00:00', '2026-09-15 10:00:00', '2026-09-15 15:59:59', '2026-09-15 16:00:00' );
$daily = DFV_Store::daily_counts( array( 'date_from' => '2026-09-14', 'date_to' => '2026-09-16' ) );
ok( $daily === array( '2026-09-14' => 2, '2026-09-15' => 3, '2026-09-16' => 1 ), 'KL buckets by local day: ' . json_encode( $daily ) );
ok( DFV_Store::daily_counts( array( 'is_spam' => 0 ) ) === array(), 'unbounded daily_counts refuses (empty), never a full-column read' );

// Manual "UTC+5:30" setting: wp_timezone() returns a fixed-offset zone.
$GLOBALS['dfv_test_tz'] = '+05:30';
ok( DFV_Store::local_day_edge_to_gmt( '2026-09-15', '00:00:00' ) === '2026-09-14 18:30:00', 'manual +05:30 from-edge -> GMT' );
ok( DFV_Store::local_time( '2026-09-15 18:30:00', 'Y-m-d H:i' ) === '2026-09-16 00:00', 'manual +05:30 display' );
$GLOBALS['dfv_test_tz'] = 'Asia/Kuala_Lumpur';
ok( strpos( $wpdb->last_sql, 'SELECT submitted_at FROM wp_dfv_submissions WHERE submitted_at >=' ) === 0, 'daily_counts selects only the bounded column' );

// --- New York across the 2026-11-01 fall-back (EDT -04:00 -> EST -05:00) ----
$GLOBALS['dfv_test_tz'] = 'America/New_York';
ok( DFV_Store::local_day_edge_to_gmt( '2026-10-31', '00:00:00' ) === '2026-10-31 04:00:00', 'NY from-edge before switch (EDT)' );
ok( DFV_Store::local_day_edge_to_gmt( '2026-11-02', '23:59:59' ) === '2026-11-03 04:59:59', 'NY to-edge after switch (EST)' );
$wpdb->col_rows = array( '2026-10-31 04:00:00', '2026-11-01 04:30:00', '2026-11-02 04:30:00', '2026-11-03 04:59:59', '2026-11-03 05:00:00' );
$daily = DFV_Store::daily_counts( array( 'date_from' => '2026-10-31', 'date_to' => '2026-11-02' ) );
ok( $daily === array( '2026-10-31' => 1, '2026-11-01' => 2, '2026-11-02' => 1, '2026-11-03' => 1 ), 'NY buckets exact across DST: ' . json_encode( $daily ) );
// The 11-01 pair: 04:30Z on 11-01 is 00:30 EDT, and 04:30Z on 11-02 is 23:30 EST the day before.
ok( DFV_Store::local_time( '2026-11-02 04:30:00', 'Y-m-d H:i' ) === '2026-11-01 23:30', 'NY 04:30Z after fall-back is 23:30 the day before' );

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit( $fail ? 1 : 0 );
