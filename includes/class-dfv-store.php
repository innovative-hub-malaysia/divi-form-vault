<?php
/**
 * Submissions store - the ONLY layer that touches the table.
 *
 * Every insert, query, update, and delete against `dfv_submissions` goes
 * through here. Nothing else in the plugin builds SQL. This keeps sanitisation,
 * the JSON encode/decode of the `fields` column, and the binary encode/decode
 * of the `ip` column in one place, and lets the storage backend change later
 * without touching callers.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Store
 */
class DFV_Store {

	/**
	 * Writable columns mapped to their $wpdb format specifier. `id` is omitted
	 * (auto-increment). Anything not in this map is rejected on write.
	 *
	 * @return array column => format
	 */
	protected static function columns() {
		return array(
			'source'       => '%s',
			'form_id'      => '%s',
			'form_name'    => '%s',
			'page_id'      => '%d',
			'page_url'     => '%s',
			'page_title'   => '%s',
			'fields'       => '%s', // JSON-encoded here.
			'utm_source'   => '%s',
			'utm_medium'   => '%s',
			'utm_campaign' => '%s',
			'utm_term'     => '%s',
			'utm_content'  => '%s',
			'landing_page' => '%s',
			'referrer'     => '%s',
			'device'       => '%s',
			'user_agent'   => '%s',
			'ip'           => '%s', // Binary; packed here via inet_pton.
			'user_id'      => '%d',
			'is_spam'      => '%d',
			'status'       => '%s',
			'submitted_at' => '%s',
		);
	}

	/**
	 * Insert a submission.
	 *
	 * @param array $data Raw submission data keyed by column. `fields` may be
	 *                    passed as an array (encoded here) or a JSON string;
	 *                    `ip` may be passed as a human IP string (packed here).
	 * @return int|false Inserted row id, or false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$columns = self::columns();
		$row     = array();
		$formats = array();

		// Normalise the two special columns before whitelisting.
		if ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$data['fields'] = wp_json_encode( $data['fields'] );
		}
		if ( isset( $data['ip'] ) && '' !== $data['ip'] && is_string( $data['ip'] ) ) {
			$packed      = @inet_pton( $data['ip'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid IP returns false, handled next line.
			$data['ip']  = ( false !== $packed ) ? $packed : null;
		}

		if ( empty( $data['submitted_at'] ) ) {
			$data['submitted_at'] = current_time( 'mysql', true );
		}
		if ( empty( $data['status'] ) ) {
			$data['status'] = 'new';
		}

		foreach ( $columns as $col => $format ) {
			if ( ! array_key_exists( $col, $data ) ) {
				continue;
			}
			$row[ $col ] = $data[ $col ];
			$formats[]   = $format;
		}

		if ( empty( $row ) ) {
			return false;
		}

		$ok = $wpdb->insert( DFV_Install::table_name(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ( false === $ok ) ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Get a single submission by id (decoded).
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = DFV_Install::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Query submissions.
	 *
	 * @param array $args {
	 *     Optional filters and paging.
	 *     @type string $source       Adapter key.
	 *     @type string $form_id      Form id.
	 *     @type int    $page_id      Page id.
	 *     @type int    $is_spam      0 or 1.
	 *     @type string $status       new|read|archived.
	 *     @type string $utm_source   UTM source.
	 *     @type string $utm_campaign UTM campaign.
	 *     @type string $date_from    Y-m-d (inclusive, GMT).
	 *     @type string $date_to      Y-m-d (inclusive, GMT).
	 *     @type string $search       LIKE match against fields / page_url / page_title.
	 *     @type string $orderby      Column to order by (whitelisted).
	 *     @type string $order        ASC|DESC.
	 *     @type int    $number       Rows per page (0 = no limit).
	 *     @type int    $offset       Offset.
	 * }
	 * @return array List of decoded rows.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = DFV_Install::table_name();

		list( $where_sql, $where_args ) = self::build_where( $args );

		$orderby = self::sanitize_orderby( isset( $args['orderby'] ) ? $args['orderby'] : 'submitted_at' );
		$order   = ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$limit_sql = '';
		$number    = isset( $args['number'] ) ? (int) $args['number'] : 0;
		if ( $number > 0 ) {
			$offset    = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $number, $offset );
		}

		$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order}{$limit_sql}";

		if ( ! empty( $where_args ) ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $rows ) {
			return array();
		}
		return array_map( array( __CLASS__, 'decode_row' ), $rows );
	}

	/**
	 * Count submissions matching the same filters as query().
	 *
	 * @param array $args Filters (see query()).
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;
		$table = DFV_Install::table_name();

		list( $where_sql, $where_args ) = self::build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( ! empty( $where_args ) ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Update whitelisted columns on a row.
	 *
	 * @param int   $id     Row id.
	 * @param array $fields column => value (only known columns are applied).
	 * @return bool
	 */
	public static function update_fields( $id, array $fields ) {
		global $wpdb;

		$columns = self::columns();
		$set     = array();
		$formats = array();

		if ( isset( $fields['fields'] ) && is_array( $fields['fields'] ) ) {
			$fields['fields'] = wp_json_encode( $fields['fields'] );
		}
		if ( isset( $fields['ip'] ) && '' !== $fields['ip'] && is_string( $fields['ip'] ) ) {
			$packed        = @inet_pton( $fields['ip'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$fields['ip']  = ( false !== $packed ) ? $packed : null;
		}

		foreach ( $columns as $col => $format ) {
			if ( ! array_key_exists( $col, $fields ) ) {
				continue;
			}
			$set[ $col ] = $fields[ $col ];
			$formats[]   = $format;
		}

		if ( empty( $set ) ) {
			return false;
		}

		$ok = $wpdb->update( DFV_Install::table_name(), $set, array( 'id' => (int) $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ( false !== $ok );
	}

	/**
	 * Delete a single submission by id. (PDPA delete tools build on this.)
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$ok = $wpdb->delete( DFV_Install::table_name(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ( false !== $ok );
	}

	/**
	 * Distinct non-empty values of a whitelisted column (filter dropdowns).
	 *
	 * @param string $column Column name.
	 * @return array
	 */
	public static function distinct( $column ) {
		global $wpdb;
		$allowed = array( 'source', 'form_id', 'form_name', 'utm_source', 'utm_campaign', 'device', 'status' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}
		$table = DFV_Install::table_name();
		return (array) $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} <> '' ORDER BY {$column} ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- column whitelisted above.
	}

	/**
	 * Delete every row matching the filter set (the PDPA delete tools).
	 *
	 * SAFETY: refuses an empty filter set - deleting everything must be the
	 * explicit `all => true` call, never an accident of empty inputs.
	 *
	 * @param array $args Filters (see query()), or array( 'all' => true ).
	 * @return int Number of rows deleted.
	 */
	public static function delete_by( array $args ) {
		global $wpdb;
		$table = DFV_Install::table_name();

		if ( ! empty( $args['all'] ) ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return $count;
		}

		list( $where_sql, $where_args ) = self::build_where( $args );
		if ( '' === $where_sql ) {
			return 0; // No filters => refuse.
		}

		$sql = "DELETE FROM {$table} {$where_sql}";
		if ( ! empty( $where_args ) ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$deleted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return ( false === $deleted ) ? 0 : (int) $deleted;
	}

	/**
	 * Grouped counts for the analytics dashboard: value => count, biggest
	 * first, over the shared filter set.
	 *
	 * @param string $column Whitelisted group column.
	 * @param array  $args   Filters (see query()).
	 * @param int    $limit  Max groups (0 = all).
	 * @return array value => count
	 */
	public static function group_count( $column, array $args = array(), $limit = 10 ) {
		global $wpdb;
		$allowed = array( 'source', 'form_id', 'form_name', 'page_title', 'page_id', 'utm_source', 'utm_medium', 'utm_campaign', 'device', 'status', 'is_spam' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}
		$table = DFV_Install::table_name();

		list( $where_sql, $where_args ) = self::build_where( $args );

		$sql = "SELECT {$column} AS v, COUNT(*) AS c FROM {$table} {$where_sql} GROUP BY {$column} ORDER BY c DESC";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		if ( ! empty( $where_args ) ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['v'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Per-day counts (GMT dates) over the shared filter set - the trend chart.
	 *
	 * @param array $args Filters (date_from/date_to bound the range).
	 * @return array 'Y-m-d' => count
	 */
	public static function daily_counts( array $args = array() ) {
		global $wpdb;
		$table = DFV_Install::table_name();

		list( $where_sql, $where_args ) = self::build_where( $args );

		$sql = "SELECT DATE(submitted_at) AS d, COUNT(*) AS c FROM {$table} {$where_sql} GROUP BY DATE(submitted_at) ORDER BY d ASC";
		if ( ! empty( $where_args ) ) {
			$sql = $wpdb->prepare( $sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['d'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Build a WHERE clause + prepare args from the shared filter set.
	 *
	 * @param array $args Filters.
	 * @return array [ string $where_sql, array $where_args ]
	 */
	protected static function build_where( array $args ) {
		$clauses = array();
		$values  = array();

		$eq = array(
			'source'       => '%s',
			'form_id'      => '%s',
			'page_id'      => '%d',
			'is_spam'      => '%d',
			'status'       => '%s',
			'utm_source'   => '%s',
			'utm_campaign' => '%s',
		);
		foreach ( $eq as $key => $fmt ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
				$clauses[] = "{$key} = {$fmt}";
				$values[]  = ( '%d' === $fmt ) ? (int) $args[ $key ] : (string) $args[ $key ];
			}
		}

		if ( ! empty( $args['date_from'] ) ) {
			$clauses[] = 'submitted_at >= %s';
			$values[]  = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$clauses[] = 'submitted_at <= %s';
			$values[]  = $args['date_to'] . ' 23:59:59';
		}

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;
			$like      = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$clauses[] = '(fields LIKE %s OR page_url LIKE %s OR page_title LIKE %s)';
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
		}

		$where_sql = $clauses ? ( 'WHERE ' . implode( ' AND ', $clauses ) ) : '';
		return array( $where_sql, $values );
	}

	/**
	 * Whitelist the orderby column (never interpolate raw user input into SQL).
	 *
	 * @param string $orderby Requested column.
	 * @return string
	 */
	protected static function sanitize_orderby( $orderby ) {
		$allowed = array( 'id', 'submitted_at', 'form_id', 'page_id', 'is_spam', 'status', 'utm_source', 'utm_campaign' );
		return in_array( $orderby, $allowed, true ) ? $orderby : 'submitted_at';
	}

	/**
	 * Decode a raw DB row for callers: JSON `fields` -> array, binary `ip` ->
	 * human string.
	 *
	 * @param array $row Raw row (ARRAY_A).
	 * @return array
	 */
	protected static function decode_row( array $row ) {
		if ( isset( $row['fields'] ) && is_string( $row['fields'] ) && '' !== $row['fields'] ) {
			$decoded       = json_decode( $row['fields'], true );
			$row['fields'] = ( null === $decoded ) ? array() : $decoded;
		} else {
			$row['fields'] = array();
		}

		if ( ! empty( $row['ip'] ) ) {
			$human       = @inet_ntop( $row['ip'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$row['ip']   = ( false !== $human ) ? $human : '';
		} else {
			$row['ip'] = '';
		}

		return $row;
	}
}
