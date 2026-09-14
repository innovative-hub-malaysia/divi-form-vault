<?php
/**
 * One-click importer from the abandoned "Divi Contact Form DB" plugin.
 *
 * ========================= KNOWN SOURCES =================================
 * First deployment site (confirmed 2026-07-28): the premium "Divi Contact Form DB"
 * (divicontactformdb.com) storing a CPT `divi_cf_db` - handled by the
 * adaptive generic CPT mapper (read_cpt_rows).
 *
 * Also verified from source - wp.org "Contact Form DB Divi" v1.4.1 (slug
 * contact-form-db-divi, backend menu "Divi Form DB") stores:
 *
 *   - CPT `lwp_form_submission` (post_status publish, post_date = submitted)
 *   - meta `processed_fields_values`  : field_id => [ 'label', 'value' ]
 *   - meta `additional_details`      : page_id / page_name / page_url /
 *                                      date_submitted (site-local) /
 *                                      read_status / contact_form_id
 *   - meta `lwp_cfdb_contact_form_unique_id`, `lwp_cfdb_page_id`
 *
 * read_lwp_rows() maps that shape exactly. The generic table/CPT heuristics
 * below remain as fallbacks for other "form DB" variants; a different
 * variant can still be pinned via the `dfv_legacy_source` filter.
 * =========================================================================
 *
 * Non-negotiables (SPEC 5.1b):
 *   - Idempotent: every imported row carries a `_legacy_ref`; re-running
 *     skips refs that already exist. Never double-imports.
 *   - Read-only on the old data: never deletes or mutates it (reversible).
 *   - Old rows get NO attribution (the old plugin never captured it).
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Import
 */
class DFV_Import {

	/**
	 * Value stored in the `source` column for imported rows.
	 */
	const SOURCE_KEY = 'legacy';

	/**
	 * Option holding the import state (done flag + last-run counts).
	 */
	const STATE_OPTION = 'dfv_import_state';

	/**
	 * CPT slugs known/plausible for the old plugin family.
	 *   - `divi_cf_db`          : the premium "Divi Contact Form DB"
	 *                             (divicontactformdb.com) - CONFIRMED on the first
	 *                             deployment site. Mapped by
	 *                             the adaptive generic mapper below.
	 *   - `lwp_form_submission` : wp.org "Contact Form DB Divi" - schema
	 *                             verified from source, exact mapper.
	 * The rest are fallbacks for other variants of the same family.
	 *
	 * @var array
	 */
	protected static $cpt_candidates = array( 'divi_cf_db', 'lwp_form_submission', 'divi_cfdb', 'divi_contact_form', 'divi_contact_message', 'et_contact_message', 'dcf_submission' );

	/**
	 * Table name LIKE patterns (without the WP prefix) - fallbacks for a
	 * variant that used a custom table (neither known plugin does).
	 *
	 * @var array
	 */
	protected static $table_candidates = array( 'divi_contact%', 'dcfdb%', 'divi_cfdb%', 'cfdb%' );

	/**
	 * Current import state.
	 *
	 * @return array { done: bool, imported: int, skipped: int, last_run: string }
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'done'       => false,
				'imported'   => 0,
				'skipped'    => 0,
				'dup'        => 0,
				'unmappable' => 0,
				'failed'     => 0,
				'last_run'   => '',
				'diag'       => array(),
			)
		);
	}

	/**
	 * Detect the old plugin's data store.
	 *
	 * @return array|null Descriptor: { type: 'cpt'|'table', name: string, count: int }
	 */
	public static function detect() {
		/**
		 * Pin the legacy source once the real site has been inspected, e.g.:
		 *   add_filter( 'dfv_legacy_source', fn() => array( 'type' => 'table', 'name' => 'wp_divi_cfdb', 'count' => 0 ) );
		 * A pinned descriptor with count 0 is re-counted below.
		 */
		$pinned = apply_filters( 'dfv_legacy_source', null );
		if ( is_array( $pinned ) && ! empty( $pinned['type'] ) && ! empty( $pinned['name'] ) ) {
			$pinned['count'] = ! empty( $pinned['count'] ) ? (int) $pinned['count'] : self::count_source( $pinned );
			return $pinned;
		}

		global $wpdb;

		// Pattern 1: a CPT. Pick the candidate with the most rows.
		$best = null;
		foreach ( self::$cpt_candidates as $cpt ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $cpt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $count > 0 && ( null === $best || $count > $best['count'] ) ) {
				$best = array(
					'type'  => 'cpt',
					'name'  => $cpt,
					'count' => $count,
				);
			}
		}
		if ( $best ) {
			return $best;
		}

		// Pattern 2: a custom table.
		foreach ( self::$table_candidates as $pattern ) {
			$like   = $wpdb->esc_like( $wpdb->prefix ) . $pattern;
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $tables as $table ) {
				// Never match our own table.
				if ( $table === DFV_Install::table_name() ) {
					continue;
				}
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from SHOW TABLES, backtick-quoted.
				if ( $count > 0 ) {
					return array(
						'type'  => 'table',
						'name'  => $table,
						'count' => $count,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Count rows in a descriptor's source.
	 *
	 * @param array $src Descriptor.
	 * @return int
	 */
	protected static function count_source( array $src ) {
		global $wpdb;
		if ( 'cpt' === $src['type'] ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $src['name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $src['name'] );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Run the import. Idempotent - re-running skips already-imported refs.
	 *
	 * @return array { imported: int, skipped: int, total: int, error: string }
	 */
	public static function run() {
		$src = self::detect();
		if ( ! $src ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'total'    => 0,
				'error'    => __( 'No old "Divi Contact Form DB" data was detected.', 'divi-form-vault' ),
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort on shared hosting.
		}

		// Refs already imported (idempotency set).
		$existing = array();
		foreach ( DFV_Store::query( array( 'source' => self::SOURCE_KEY ) ) as $row ) {
			if ( ! empty( $row['fields']['_legacy_ref'] ) ) {
				$existing[ (string) $row['fields']['_legacy_ref'] ] = true;
			}
		}

		$rows = ( 'cpt' === $src['type'] ) ? self::read_cpt_rows( $src['name'] ) : self::read_table_rows( $src['name'] );

		global $wpdb;

		$imported   = 0;
		$dup        = 0; // Already imported on a previous run.
		$unmappable = 0; // No readable fields could be harvested.
		$failed     = 0; // Fields harvested, but the DB insert failed.
		$diag       = array();
		foreach ( $rows as $legacy ) {
			$ref = $src['type'] . ':' . $src['name'] . ':' . $legacy['id'];
			if ( isset( $existing[ $ref ] ) ) {
				$dup++;
				continue;
			}
			if ( empty( $legacy['fields'] ) ) {
				// Could not map this row confidently - skip, never guess. But
				// SHOW why: the diagnostic makes the unknown schema visible in
				// the admin so the mapping can be pinned instead of re-guessed.
				$unmappable++;
				if ( count( $diag ) < 3 && 'cpt' === $src['type'] ) {
					$d           = self::diagnose_post( (int) $legacy['id'] );
					$d['reason'] = 'no readable fields';
					$diag[]      = $d;
				}
				continue;
			}

			$fields                = $legacy['fields'];
			$fields['_legacy_ref'] = $ref;

			$id = DFV_Store::insert(
				array(
					'source'       => self::SOURCE_KEY,
					'form_id'      => $legacy['form_id'],
					'form_name'    => $legacy['form_name'],
					'page_id'      => $legacy['page_id'],
					'page_url'     => $legacy['page_url'],
					'page_title'   => ! empty( $legacy['page_title'] ) ? $legacy['page_title'] : ( $legacy['page_id'] ? get_the_title( $legacy['page_id'] ) : '' ),
					'fields'       => $fields,
					'status'       => 'read', // Historical rows arrive read, not as new leads.
					'submitted_at' => $legacy['submitted_at'],
					// No attribution / device / IP: the old plugin never captured them.
				)
			);

			if ( false === $id ) {
				$failed++;
				if ( count( $diag ) < 3 ) {
					$diag[] = array(
						'id'     => (int) $legacy['id'],
						'reason' => 'insert failed: ' . ( $wpdb->last_error ? $wpdb->last_error : '(no DB error reported)' ),
					);
				}
			} else {
				$imported++;
			}
		}

		$state = array(
			'done'       => true,
			'imported'   => $imported,
			'skipped'    => $dup + $unmappable + $failed,
			'dup'        => $dup,
			'unmappable' => $unmappable,
			'failed'     => $failed,
			'last_run'   => current_time( 'mysql', true ),
			'diag'       => $diag,
		);
		update_option( self::STATE_OPTION, $state );

		return array(
			'imported'   => $imported,
			'skipped'    => $dup + $unmappable + $failed,
			'dup'        => $dup,
			'unmappable' => $unmappable,
			'failed'     => $failed,
			'total'      => count( $rows ),
			'error'      => '',
		);
	}

	/**
	 * Read "Contact Form DB Divi" (`lwp_form_submission`) rows - the VERIFIED
	 * shape, mapped exactly (see the header note).
	 *
	 * @return array[]
	 */
	protected static function read_lwp_rows() {
		$posts = get_posts(
			array(
				'post_type'        => 'lwp_form_submission',
				'post_status'      => array_keys( get_post_stati() ), // Explicit all-stati: 'any' skips exclude_from_search statuses.
				'numberposts'      => -1,
				'suppress_filters' => true,
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			$processed = get_post_meta( $post->ID, 'processed_fields_values', true );
			$details   = get_post_meta( $post->ID, 'additional_details', true );
			$details   = is_array( $details ) ? $details : array();

			// field_id => [label, value] - same shape the live Divi hook hands
			// us, so key by the label slug exactly like the adapter does.
			$fields = array();
			if ( is_array( $processed ) ) {
				foreach ( $processed as $field_id => $payload ) {
					$key   = is_string( $field_id ) ? $field_id : 'field_' . (string) $field_id;
					$value = null;
					if ( is_array( $payload ) && array_key_exists( 'value', $payload ) ) {
						$value = $payload['value'];
						if ( ! empty( $payload['label'] ) && is_scalar( $payload['label'] ) ) {
							$label_key = sanitize_title( (string) $payload['label'] );
							if ( '' !== $label_key ) {
								$key = isset( $fields[ $label_key ] ) ? $label_key . '_' . $field_id : $label_key;
							}
						}
					} elseif ( is_scalar( $payload ) ) {
						$value = $payload;
					}
					if ( null !== $value ) {
						$fields[ $key ] = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
					}
				}
			}

			$form_id = (string) get_post_meta( $post->ID, 'lwp_cfdb_contact_form_unique_id', true );
			if ( '' === $form_id || '00000000-0000-0000-0000-000000000000' === $form_id ) {
				$form_id = ! empty( $details['contact_form_id'] ) ? (string) $details['contact_form_id'] : '';
			}

			$page_id = absint( get_post_meta( $post->ID, 'lwp_cfdb_page_id', true ) );
			if ( ! $page_id && ! empty( $details['page_id'] ) ) {
				$page_id = absint( $details['page_id'] );
			}

			// date_submitted is site-local time; convert to GMT for our column.
			$submitted = '';
			if ( ! empty( $details['date_submitted'] ) ) {
				$submitted = get_gmt_from_date( (string) $details['date_submitted'] );
			}
			if ( ! $submitted ) {
				$submitted = ( $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ) ? $post->post_date_gmt : $post->post_date;
			}

			$rows[] = array(
				'id'           => $post->ID,
				'fields'       => $fields,
				'form_id'      => $form_id,
				'form_name'    => '',
				'page_id'      => $page_id,
				'page_url'     => ! empty( $details['page_url'] ) ? (string) $details['page_url'] : ( $page_id ? (string) get_permalink( $page_id ) : '' ),
				'submitted_at' => $submitted,
			);
		}
		return $rows;
	}

	/**
	 * Read the premium "Divi Contact Form DB" (`divi_cf_db`) rows - schema
	 * confirmed on the first deployment site 2026-07-28 (v2, via skip diagnostics):
	 *
	 * ONE wrapper meta `sb_divi_cfd` holds the whole record; inside it:
	 *   - data            : JSON LIST of { label, value } (nested string, may be slashed)
	 *   - extra           : JSON { submitted_on (page title), submitted_on_id (page id), ... }
	 *   - fields_original : field definitions - no lead value, skipped
	 *   - post            : raw POST dump incl. tokens - noise, skipped
	 * Siblings `sb_divi_cfd_read` (flag) / `sb_divi_cfd_email` (email copy,
	 * used as last-resort fallback). Submission time = post_date_gmt.
	 *
	 * @return array[]
	 */
	protected static function read_sb_rows() {
		$posts = get_posts(
			array(
				'post_type'        => 'divi_cf_db',
				'post_status'      => array_keys( get_post_stati() ),
				'numberposts'      => -1,
				'suppress_filters' => true,
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			// Unwrap the outer record (array | serialised | JSON | slashed).
			$wrap = self::to_array( get_post_meta( $post->ID, 'sb_divi_cfd', true ) );

			// The fields live in wrap.data - itself a nested JSON string.
			$fields = array();
			if ( isset( $wrap['data'] ) ) {
				$fields = is_array( $wrap['data'] )
					? self::decode_blob( (string) wp_json_encode( $wrap['data'] ) )
					: self::decode_blob( (string) $wrap['data'] );
			}

			// Last resort: never lose the lead entirely - the email sibling
			// meta at least identifies the person.
			if ( empty( $fields ) ) {
				$email = (string) get_post_meta( $post->ID, 'sb_divi_cfd_email', true );
				if ( '' !== $email ) {
					$fields['email'] = $email;
				}
			}

			// Page context from wrap.extra (also nested).
			$page_id    = 0;
			$page_title = '';
			$extra      = isset( $wrap['extra'] ) ? self::to_array( $wrap['extra'] ) : array();
			if ( $extra ) {
				$page_id    = ! empty( $extra['submitted_on_id'] ) ? absint( $extra['submitted_on_id'] ) : 0;
				$page_title = ! empty( $extra['submitted_on'] ) && is_scalar( $extra['submitted_on'] ) ? (string) $extra['submitted_on'] : '';
			}

			$rows[] = array(
				'id'           => $post->ID,
				'fields'       => $fields,
				'form_id'      => '',
				'form_name'    => '', // The old plugin stored no form identity; post_title is just a timestamp.
				'page_id'      => $page_id,
				'page_title'   => $page_title,
				'page_url'     => $page_id ? (string) get_permalink( $page_id ) : '',
				'submitted_at' => ( $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ) ? $post->post_date_gmt : $post->post_date,
			);
		}
		return $rows;
	}

	/**
	 * Normalise a maybe-anything stored value into an array.
	 * Accepts: array (returned as-is), serialised PHP, JSON, slashed JSON.
	 *
	 * @param mixed $value Stored value.
	 * @return array
	 */
	protected static function to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return array();
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return array();
		}
		if ( is_serialized( $value ) ) {
			$maybe = maybe_unserialize( $value );
			return is_array( $maybe ) ? $maybe : array();
		}
		$decoded = json_decode( $value, true );
		if ( null === $decoded ) {
			$decoded = json_decode( stripslashes( $value ), true );
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Read legacy CPT rows into the neutral legacy shape - the ADAPTIVE
	 * generic mapper (unknown variants).
	 *
	 * Strategy: harvest everything without knowing the exact schema.
	 *   - fields: decoded post_content blob (if any) + every meaningful
	 *     post-meta entry; serialised/JSON meta arrays are expanded, and
	 *     field_id => { label, value } wrappers (the shape Divi's own hook
	 *     produces, which DB plugins commonly store verbatim) are unwrapped
	 *     by decode_blob with label-aware keys.
	 *   - form / page / date: sniffed from meta-name patterns, with post
	 *     fields as fallbacks. Unmatched meta is never dropped - it simply
	 *     stays visible as a field, so no lead data is lost.
	 *
	 * @param string $cpt Post type.
	 * @return array[]
	 */
	protected static function read_cpt_rows( $cpt ) {
		if ( 'lwp_form_submission' === $cpt ) {
			return self::read_lwp_rows();
		}
		if ( 'divi_cf_db' === $cpt ) {
			return self::read_sb_rows();
		}
		$posts = get_posts(
			array(
				'post_type'        => $cpt,
				'post_status'      => array_keys( get_post_stati() ), // Explicit all-stati: 'any' skips exclude_from_search statuses.
				'numberposts'      => -1,
				'suppress_filters' => true,
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			$fields   = self::decode_blob( $post->post_content );
			$form_id  = '';
			$page_id  = 0;
			$page_url = '';
			$date     = '';

			$meta = get_post_meta( $post->ID );
			foreach ( (array) $meta as $key => $values ) {
				$raw = (string) reset( $values );
				if ( '' === $raw ) {
					continue;
				}
				$name = strtolower( ltrim( (string) $key, '_' ) );

				// Structural sniffing: form / page / date land in their own
				// columns instead of the fields map.
				if ( '' === $form_id && false !== strpos( $name, 'form' ) && ( false !== strpos( $name, 'id' ) || false !== strpos( $name, 'unique' ) ) ) {
					$form_id = $raw;
					continue;
				}
				if ( ! $page_id && ( false !== strpos( $name, 'page_id' ) || false !== strpos( $name, 'post_id' ) ) && is_numeric( $raw ) ) {
					$page_id = absint( $raw );
					continue;
				}
				if ( '' === $page_url && false !== strpos( $name, 'url' ) && 0 === strpos( $raw, 'http' ) ) {
					$page_url = $raw;
					continue;
				}
				if ( '' === $date && ( false !== strpos( $name, 'date' ) || false !== strpos( $name, 'submitted' ) ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw ) ) {
					$date = get_gmt_from_date( $raw ); // Plugin dates are site-local.
					continue;
				}

				// WP-internal noise that carries no lead value.
				if ( in_array( $name, array( 'edit_lock', 'edit_last', 'wp_old_slug', 'wp_old_date' ), true ) ) {
					continue;
				}

				// A serialised / JSON array meta = likely the fields payload.
				$decoded = self::decode_blob( $raw );
				if ( ! empty( $decoded ) ) {
					$fields = array_merge( $fields, $decoded );
					continue;
				}

				// Plain scalar meta becomes a field, keyed by the meta name.
				$fields[ sanitize_key( $name ) ] = $raw;
			}

			// Lossless fallback: some plugins store the submission as rendered
			// HTML/text in post_content (or excerpt) with no structured meta.
			// Never lose that - keep it whole as one readable field.
			if ( empty( $fields ) ) {
				$text = trim( wp_strip_all_tags( (string) $post->post_content ) );
				if ( '' === $text ) {
					$text = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
				}
				if ( '' !== $text ) {
					$fields['submission'] = $text;
				}
			}

			if ( '' === $date ) {
				$date = ( $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ) ? $post->post_date_gmt : $post->post_date;
			}
			if ( ! $page_id && $post->post_parent ) {
				$page_id = (int) $post->post_parent;
			}
			if ( '' === $page_url && $page_id ) {
				$page_url = (string) get_permalink( $page_id );
			}

			$rows[] = array(
				'id'           => $post->ID,
				'fields'       => $fields,
				'form_id'      => $form_id,
				'form_name'    => $post->post_title ? (string) $post->post_title : '',
				'page_id'      => $page_id,
				'page_url'     => $page_url,
				'submitted_at' => $date,
			);
		}
		return $rows;
	}

	/**
	 * Read legacy custom-table rows into the neutral legacy shape.
	 *
	 * Column heuristics (TO-VERIFY): id from the primary/auto column; date from
	 * the first datetime-ish column; fields from a serialised/JSON blob column,
	 * else from all remaining scalar columns; form/page from name-alike columns.
	 *
	 * @param string $table Table name (from SHOW TABLES).
	 * @return array[]
	 */
	protected static function read_table_rows( $table ) {
		global $wpdb;

		$table   = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table );
		$columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $columns ) {
			return array();
		}

		$id_col   = '';
		$date_col = '';
		$form_col = '';
		$page_col = '';
		foreach ( $columns as $col ) {
			$name = strtolower( $col['Field'] );
			$type = strtolower( (string) $col['Type'] );
			if ( '' === $id_col && ( 'auto_increment' === strtolower( (string) $col['Extra'] ) || 'pri' === strtolower( (string) $col['Key'] ) ) ) {
				$id_col = $col['Field'];
			}
			if ( '' === $date_col && ( false !== strpos( $type, 'datetime' ) || false !== strpos( $type, 'timestamp' ) || in_array( $name, array( 'date', 'created', 'created_at', 'submitted', 'submitted_at', 'date_added', 'time' ), true ) ) ) {
				$date_col = $col['Field'];
			}
			if ( '' === $form_col && in_array( $name, array( 'form_id', 'form', 'form_name', 'form_title' ), true ) ) {
				$form_col = $col['Field'];
			}
			if ( '' === $page_col && in_array( $name, array( 'post_id', 'page_id', 'page', 'post' ), true ) ) {
				$page_col = $col['Field'];
			}
		}

		if ( '' === $id_col ) {
			return array(); // No stable per-row ref => no idempotency => refuse.
		}

		$raw = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$rows = array();
		foreach ( (array) $raw as $r ) {
			$fields = array();

			// Prefer a serialised / JSON blob column.
			foreach ( $r as $col => $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$decoded = self::decode_blob( $value );
				if ( ! empty( $decoded ) ) {
					$fields = $decoded;
					break;
				}
			}

			// Else: every scalar column that is not structural becomes a field.
			if ( empty( $fields ) ) {
				$structural = array( $id_col, $date_col, $form_col, $page_col );
				foreach ( $r as $col => $value ) {
					if ( in_array( $col, $structural, true ) || ! is_scalar( $value ) || '' === (string) $value ) {
						continue;
					}
					$fields[ sanitize_key( $col ) ] = (string) $value;
				}
			}

			$rows[] = array(
				'id'           => $r[ $id_col ],
				'fields'       => $fields,
				'form_id'      => $form_col && isset( $r[ $form_col ] ) ? (string) $r[ $form_col ] : '',
				'form_name'    => '',
				'page_id'      => $page_col && isset( $r[ $page_col ] ) ? absint( $r[ $page_col ] ) : 0,
				'page_url'     => $page_col && ! empty( $r[ $page_col ] ) ? (string) get_permalink( absint( $r[ $page_col ] ) ) : '',
				'submitted_at' => $date_col && ! empty( $r[ $date_col ] ) ? (string) $r[ $date_col ] : '',
			);
		}
		return $rows;
	}

	/**
	 * What does an unmappable legacy post actually contain? Shown in the
	 * Legacy import section so the schema stops being a black box.
	 *
	 * @param int $post_id Legacy post id.
	 * @return array { id, status, title, meta_keys, content_len, content_head }
	 */
	protected static function diagnose_post( $post_id ) {
		$post = get_post( $post_id );
		$meta = get_post_meta( $post_id );
		$head = $post ? wp_strip_all_tags( (string) $post->post_content ) : '';
		return array(
			'id'           => $post_id,
			'status'       => $post ? (string) $post->post_status : '(gone)',
			'title'        => $post ? (string) $post->post_title : '',
			'meta_keys'    => array_keys( (array) $meta ),
			'content_len'  => $post ? strlen( (string) $post->post_content ) : 0,
			'content_head' => substr( trim( preg_replace( '/\s+/', ' ', $head ) ), 0, 160 ),
		);
	}

	/**
	 * Decode a serialised-PHP or JSON blob into a flat fields map, or array().
	 *
	 * @param string $blob Raw column / post_content value.
	 * @return array
	 */
	protected static function decode_blob( $blob ) {
		if ( ! is_string( $blob ) ) {
			return array();
		}
		$blob = trim( $blob );
		if ( '' === $blob ) {
			return array();
		}

		$data = null;
		if ( is_serialized( $blob ) ) {
			$data = maybe_unserialize( $blob );
		} elseif ( '{' === $blob[0] || '[' === $blob[0] ) {
			$data = json_decode( $blob, true );
			if ( null === $data ) {
				// Meta values are often stored SLASHED (the first-deploy lesson: the
				// premium plugin's JSON came back as \"label\" and failed to
				// parse, so the whole blob landed as one raw string field).
				$data = json_decode( stripslashes( $blob ), true );
			}
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		$fields = array();
		foreach ( $data as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				// One nesting level: the { label, value } wrapper Divi's hook
				// produces. Prefer the human label as the key (same as the
				// live adapter), fall back to the raw field id.
				if ( ! empty( $value['label'] ) && is_scalar( $value['label'] ) ) {
					$label_key = sanitize_title( (string) $value['label'] );
					if ( '' !== $label_key && ! isset( $fields[ $label_key ] ) ) {
						$key = $label_key;
					}
				}
				$value = isset( $value['value'] ) ? $value['value'] : wp_json_encode( $value );
			}
			if ( is_scalar( $value ) ) {
				$fields[ $key ] = (string) $value;
			}
		}
		return $fields;
	}

	/**
	 * Find an ACTIVE legacy plugin's file path (for deactivation).
	 *
	 * @return string|null plugin_basename, or null when none is active.
	 */
	public static function legacy_plugin_file() {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $file ) {
			$slug = strtolower( (string) $file );
			// 'contact-form-db-divi' = the verified wp.org plugin; the rest
			// cover name variants of the same family.
			if ( false !== strpos( $slug, 'contact-form-db-divi' ) || false !== strpos( $slug, 'divi-contact-form-db' ) || false !== strpos( $slug, 'divi-contact-db' ) || false !== strpos( $slug, 'divi-cfdb' ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Try to deactivate the legacy plugin. Never deletes it or its data.
	 *
	 * @return bool True when a legacy plugin was found and deactivated.
	 */
	public static function deactivate_legacy() {
		$file = self::legacy_plugin_file();
		if ( ! $file ) {
			return false;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( $file );
		return null === self::legacy_plugin_file();
	}
}
