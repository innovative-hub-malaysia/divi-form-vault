<?php
/**
 * Backend submissions view - where the leads are read.
 *
 * Owns the top-level "Form Vault" menu. The list screen is a WP_List_Table
 * with filters (form / source / spam / date range) + search, bulk actions
 * (mark spam / genuine, delete), a per-row detail view (all fields + full
 * attribution), and a CSV export that respects the active filter.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Admin_List
 */
class DFV_Admin_List {

	const CAP       = 'manage_options';
	const MENU_SLUG = 'dfv-submissions';
	const NONCE_ACT = 'dfv_list_action';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_dfv_export_csv', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_dfv_row_action', array( __CLASS__, 'handle_row_action' ) );
	}

	/**
	 * Top-level menu: Form Vault -> Submissions (default screen).
	 */
	public static function add_menu() {
		$new   = DFV_Store::count( array( 'status' => 'new', 'is_spam' => 0 ) );
		$badge = $new > 0 ? ' <span class="awaiting-mod count-' . (int) $new . '"><span class="pending-count">' . (int) $new . '</span></span>' : '';

		add_menu_page(
			__( 'Form Vault', 'divi-form-vault' ),
			__( 'Form Vault', 'divi-form-vault' ) . $badge,
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-archive',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Submissions', 'divi-form-vault' ),
			__( 'Submissions', 'divi-form-vault' ),
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Shared: read the current filter set off the query string.
	 *
	 * @return array Store-query args (no paging).
	 */
	public static function current_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filtering.
		$args = array();
		if ( ! empty( $_GET['form'] ) ) {
			$args['form_id'] = sanitize_text_field( wp_unslash( $_GET['form'] ) );
		}
		if ( ! empty( $_GET['src'] ) ) {
			$args['utm_source'] = sanitize_text_field( wp_unslash( $_GET['src'] ) );
		}
		if ( isset( $_GET['spam'] ) && '' !== $_GET['spam'] ) {
			$args['is_spam'] = absint( $_GET['spam'] );
		}
		if ( ! empty( $_GET['pg'] ) ) {
			// Page filter - no dropdown; reached via Analytics "top pages" links.
			$args['page_id'] = absint( $_GET['pg'] );
		}
		if ( ! empty( $_GET['from'] ) ) {
			$args['date_from'] = sanitize_text_field( wp_unslash( $_GET['from'] ) );
		}
		if ( ! empty( $_GET['to'] ) ) {
			$args['date_to'] = sanitize_text_field( wp_unslash( $_GET['to'] ) );
		}
		if ( ! empty( $_GET['s'] ) ) {
			$args['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		// phpcs:enable
		return $args;
	}

	/**
	 * Route: detail view or the list table.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view submissions.', 'divi-form-vault' ) );
		}

		$view_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( $view_id ) {
			self::render_detail( $view_id );
			return;
		}
		self::render_list();
	}

	/**
	 * The list screen.
	 */
	protected static function render_list() {
		require_once DFV_PLUGIN_DIR . 'includes/admin/class-dfv-list-table.php';

		$table = new DFV_List_Table();
		$table->process_bulk_action();
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg( self::export_args(), admin_url( 'admin-post.php?action=dfv_export_csv' ) ),
			self::NONCE_ACT
		);

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Submissions', 'divi-form-vault' ) . '</h1> ';
		echo '<a href="' . esc_url( $export_url ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'divi-form-vault' ) . '</a>';
		echo '<hr class="wp-header-end">';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';
		$table->search_box( __( 'Search submissions', 'divi-form-vault' ), 'dfv' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Current filters as export-url args.
	 *
	 * @return array
	 */
	protected static function export_args() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$keep = array( 'form', 'src', 'spam', 'from', 'to', 's', 'pg' );
		$args = array();
		foreach ( $keep as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		return $args;
	}

	/**
	 * The single-submission detail screen. Viewing marks a new row as read.
	 *
	 * @param int $id Row id.
	 */
	protected static function render_detail( $id ) {
		$row = DFV_Store::get( $id );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Submission', 'divi-form-vault' ) . ' #' . (int) $id . '</h1> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '" class="page-title-action">' . esc_html__( 'Back to list', 'divi-form-vault' ) . '</a>';
		echo '<hr class="wp-header-end">';

		if ( ! $row ) {
			echo '<p>' . esc_html__( 'This submission no longer exists.', 'divi-form-vault' ) . '</p></div>';
			return;
		}

		if ( 'new' === $row['status'] ) {
			DFV_Store::update_fields( $id, array( 'status' => 'read' ) );
			$row['status'] = 'read';
		}

		$toggle_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=dfv_row_action&do=' . ( $row['is_spam'] ? 'genuine' : 'spam' ) . '&id=' . (int) $id ),
			self::NONCE_ACT
		);
		$delete_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=dfv_row_action&do=delete&id=' . (int) $id ),
			self::NONCE_ACT
		);

		echo '<p>';
		echo '<a href="' . esc_url( $toggle_url ) . '" class="button">' . ( $row['is_spam'] ? esc_html__( 'Mark genuine', 'divi-form-vault' ) : esc_html__( 'Mark spam', 'divi-form-vault' ) ) . '</a> ';
		echo '<a href="' . esc_url( $delete_url ) . '" class="button button-link-delete" onclick="return confirm(' . esc_js( wp_json_encode( __( 'Delete this submission permanently?', 'divi-form-vault' ) ) ) . ');">' . esc_html__( 'Delete', 'divi-form-vault' ) . '</a>';
		echo '</p>';

		// --- The form fields. ----------------------------------------------.
		echo '<h2>' . esc_html__( 'Form fields', 'divi-form-vault' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		$internal = array();
		foreach ( (array) $row['fields'] as $key => $value ) {
			if ( '_' === substr( (string) $key, 0, 1 ) ) {
				$internal[ $key ] = $value;
				continue;
			}
			$text  = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$label = ucwords( str_replace( array( '-', '_' ), ' ', (string) $key ) ); // Display-only: 'email-address' -> 'Email Address'.
			echo '<tr><th style="width:200px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $text ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// --- Context + attribution. ----------------------------------------.
		echo '<h2>' . esc_html__( 'Lead context', 'divi-form-vault' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		$meta = array(
			__( 'Submitted (GMT)', 'divi-form-vault' ) => $row['submitted_at'],
			__( 'Status', 'divi-form-vault' )          => $row['status'] . ( $row['is_spam'] ? ' / spam' : ' / genuine' ),
			__( 'Source', 'divi-form-vault' )          => $row['source'],
			__( 'Form', 'divi-form-vault' )            => trim( $row['form_name'] . ' ' . $row['form_id'] ),
			__( 'Page', 'divi-form-vault' )            => trim( $row['page_title'] . ' ' . $row['page_url'] ),
			__( 'UTM source / medium', 'divi-form-vault' )   => trim( $row['utm_source'] . ' / ' . $row['utm_medium'], ' /' ),
			__( 'UTM campaign', 'divi-form-vault' )    => $row['utm_campaign'],
			__( 'UTM term / content', 'divi-form-vault' )    => trim( $row['utm_term'] . ' / ' . $row['utm_content'], ' /' ),
			__( 'Landing page', 'divi-form-vault' )    => $row['landing_page'],
			__( 'Referrer', 'divi-form-vault' )        => $row['referrer'],
			__( 'Device', 'divi-form-vault' )          => $row['device'],
			__( 'IP', 'divi-form-vault' )              => $row['ip'],
			__( 'User agent', 'divi-form-vault' )      => $row['user_agent'],
		);
		foreach ( $meta as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			echo '<tr><th style="width:200px">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		// The secondary touch (model 'both') stored inside the fields JSON.
		foreach ( $internal as $key => $value ) {
			echo '<tr><th style="width:200px">' . esc_html( ltrim( (string) $key, '_' ) ) . '</th><td><code>' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Single-row actions from the detail screen (spam / genuine / delete).
	 */
	public static function handle_row_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'divi-form-vault' ) );
		}
		check_admin_referer( self::NONCE_ACT );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

		$back = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		if ( $id && 'spam' === $do ) {
			DFV_Store::update_fields( $id, array( 'is_spam' => 1 ) );
			$back = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=' . $id );
		} elseif ( $id && 'genuine' === $do ) {
			DFV_Store::update_fields( $id, array( 'is_spam' => 0 ) );
			$back = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=' . $id );
		} elseif ( $id && 'delete' === $do ) {
			DFV_Store::delete( $id );
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Stream the CSV export (respects the active filters).
	 */
	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'divi-form-vault' ) );
		}
		check_admin_referer( self::NONCE_ACT );

		$rows = DFV_Store::query( self::current_filters() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=form-vault-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array( 'id', 'submitted_at_gmt', 'status', 'is_spam', 'source', 'form_id', 'form_name', 'page_id', 'page_title', 'page_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer', 'device', 'ip', 'fields_json' )
		);
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
						$row['id'],
						$row['submitted_at'],
						$row['status'],
						$row['is_spam'],
						$row['source'],
						$row['form_id'],
						$row['form_name'],
						$row['page_id'],
						$row['page_title'],
						$row['page_url'],
						$row['utm_source'],
						$row['utm_medium'],
						$row['utm_campaign'],
						$row['utm_term'],
						$row['utm_content'],
						$row['landing_page'],
						$row['referrer'],
						$row['device'],
						$row['ip'],
						wp_json_encode( $row['fields'] ),
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming CSV.
		exit;
	}

	/**
	 * Neutralise CSV formula injection: submission values are visitor-supplied,
	 * and a leading = + - @ (or tab/CR) makes Excel/Sheets execute the cell as
	 * a formula. Prefix a single quote so the cell stays literal text.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	public static function csv_safe( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
