<?php
/**
 * Table installer + schema versioning.
 *
 * Owns the custom `{$wpdb->prefix}dfv_submissions` table. A custom table (not a
 * CPT) is used on purpose: lead volume can be high and the analytics view needs
 * indexed aggregation by page / form / UTM / date.
 *
 * The schema version is stored in an option so future column changes can run
 * through dbDelta on upgrade with no re-activation required.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Install
 */
class DFV_Install {

	/**
	 * Bump this whenever the schema below changes. maybe_upgrade() compares it
	 * against the stored value and re-runs dbDelta when they differ.
	 * 1.0.1: shared-host-proof schema (no zero-date default, single-column
	 * indexes) + create verification; the bump makes every existing install
	 * re-run install() once, which self-heals a site where 1.0.0 failed.
	 */
	const DB_VERSION = '1.0.1';

	/**
	 * Option that stores the installed schema version.
	 */
	const DB_VERSION_OPTION = 'dfv_db_version';

	/**
	 * Option holding the last table-creation DB error ('' when healthy).
	 * Rendered as a red admin notice so a broken vault is never silent.
	 */
	const ERROR_OPTION = 'dfv_install_error';

	/**
	 * Unqualified table name (without the WP prefix).
	 */
	const TABLE = 'dfv_submissions';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the table (idempotent via dbDelta) and record the schema version.
	 * Safe to call on every activation.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces before PRIMARY KEY, one
		// definition per line, KEY names unique. Keep this formatting intact.
		//
		// Shared-host hardening (the first-deploy lesson, 2026-07-28 - the original
		// schema failed to create SILENTLY and every insert then died):
		//   - no zero-date default (strict-mode MySQL rejects the whole CREATE;
		//     inserts always supply submitted_at anyway).
		//   - single-column indexes only: a composite of two VARCHAR(191)
		//     utf8mb4 columns (1528 bytes) blows the 767-byte index limit on
		//     older MySQL/MariaDB and kills the CREATE.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(32) NOT NULL DEFAULT '',
			form_id VARCHAR(191) NOT NULL DEFAULT '',
			form_name VARCHAR(191) NOT NULL DEFAULT '',
			page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			page_url TEXT NULL,
			page_title TEXT NULL,
			fields LONGTEXT NULL,
			utm_source VARCHAR(191) NOT NULL DEFAULT '',
			utm_medium VARCHAR(191) NOT NULL DEFAULT '',
			utm_campaign VARCHAR(191) NOT NULL DEFAULT '',
			utm_term VARCHAR(191) NOT NULL DEFAULT '',
			utm_content VARCHAR(191) NOT NULL DEFAULT '',
			landing_page TEXT NULL,
			referrer TEXT NULL,
			device VARCHAR(32) NOT NULL DEFAULT '',
			user_agent TEXT NULL,
			ip VARBINARY(16) NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_spam TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			submitted_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY submitted_at (submitted_at),
			KEY form_id (form_id),
			KEY page_id (page_id),
			KEY is_spam (is_spam),
			KEY status (status),
			KEY utm_source (utm_source),
			KEY utm_campaign (utm_campaign)
		) {$charset_collate};";

		dbDelta( $sql );

		// dbDelta fails SILENTLY - never trust it. Verify the table actually
		// exists; if not, run a direct CREATE to capture the real DB error,
		// and only stamp the schema version once the table is confirmed (so
		// maybe_upgrade() keeps retrying instead of assuming success).
		if ( self::table_exists() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
			delete_option( self::ERROR_OPTION );
			return;
		}

		$wpdb->last_error = '';
		$direct_sql       = str_replace( 'CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql );
		$wpdb->query( $direct_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- DDL, schema constant.

		if ( self::table_exists() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
			delete_option( self::ERROR_OPTION );
			return;
		}

		update_option( self::ERROR_OPTION, $wpdb->last_error ? $wpdb->last_error : 'unknown - table missing after CREATE with no DB error reported' );
	}

	/**
	 * Does the submissions table exist right now?
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Run the installer when the stored schema version is behind the code. Cheap
	 * to call on every load - it does nothing unless a version bump is pending.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Drop the table. Called only from the opt-in uninstall purge - never on
	 * deactivation.
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::table_name();
		// Table name is built from the WP prefix + a constant; it cannot be
		// parameterised in DDL. It is not user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
