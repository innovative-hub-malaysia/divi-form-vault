<?php
/**
 * The submissions WP_List_Table.
 *
 * Pure presentation + bulk handling; every read goes through DFV_Store with
 * the shared filter set from DFV_Admin_List::current_filters().
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class DFV_List_Table
 */
class DFV_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'submitted' => __( 'Submitted', 'divi-form-vault' ),
			'lead'      => __( 'Lead', 'divi-form-vault' ),
			'form'      => __( 'Form', 'divi-form-vault' ),
			'page'      => __( 'Page', 'divi-form-vault' ),
			'campaign'  => __( 'Source / campaign', 'divi-form-vault' ),
			'device'    => __( 'Device', 'divi-form-vault' ),
			'state'     => __( 'Status', 'divi-form-vault' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'submitted' => array( 'submitted_at', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'spam'    => __( 'Mark spam', 'divi-form-vault' ),
			'genuine' => __( 'Mark genuine', 'divi-form-vault' ),
			'delete'  => __( 'Delete permanently', 'divi-form-vault' ),
		);
	}

	/**
	 * Run the selected bulk action. Called before prepare_items().
	 */
	public function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );
		if ( ! current_user_can( DFV_Admin_List::CAP ) ) {
			return;
		}

		$ids = isset( $_REQUEST['submission'] ) ? array_map( 'absint', (array) $_REQUEST['submission'] ) : array();
		foreach ( $ids as $id ) {
			if ( ! $id ) {
				continue;
			}
			if ( 'spam' === $action ) {
				DFV_Store::update_fields( $id, array( 'is_spam' => 1 ) );
			} elseif ( 'genuine' === $action ) {
				DFV_Store::update_fields( $id, array( 'is_spam' => 0 ) );
			} elseif ( 'delete' === $action ) {
				DFV_Store::delete( $id );
			}
		}
	}

	/**
	 * Filter controls above the table.
	 *
	 * @param string $which top | bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter state.
		$cur_form = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';
		$cur_src  = isset( $_GET['src'] ) ? sanitize_text_field( wp_unslash( $_GET['src'] ) ) : '';
		$cur_spam = isset( $_GET['spam'] ) ? sanitize_text_field( wp_unslash( $_GET['spam'] ) ) : '';
		$cur_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$cur_to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable

		echo '<div class="alignleft actions">';

		echo '<select name="form"><option value="">' . esc_html__( 'All forms', 'divi-form-vault' ) . '</option>';
		foreach ( DFV_Store::distinct( 'form_id' ) as $form ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $form ), selected( $cur_form, $form, false ), esc_html( $form ) );
		}
		echo '</select>';

		echo '<select name="src"><option value="">' . esc_html__( 'All sources', 'divi-form-vault' ) . '</option>';
		foreach ( DFV_Store::distinct( 'utm_source' ) as $src ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $src ), selected( $cur_src, $src, false ), esc_html( $src ) );
		}
		echo '</select>';

		echo '<select name="spam">';
		echo '<option value="">' . esc_html__( 'Genuine + spam', 'divi-form-vault' ) . '</option>';
		echo '<option value="0"' . selected( $cur_spam, '0', false ) . '>' . esc_html__( 'Genuine only', 'divi-form-vault' ) . '</option>';
		echo '<option value="1"' . selected( $cur_spam, '1', false ) . '>' . esc_html__( 'Spam only', 'divi-form-vault' ) . '</option>';
		echo '</select>';

		echo '<input type="date" name="from" value="' . esc_attr( $cur_from ) . '" aria-label="' . esc_attr__( 'From date', 'divi-form-vault' ) . '">';
		echo '<input type="date" name="to" value="' . esc_attr( $cur_to ) . '" aria-label="' . esc_attr__( 'To date', 'divi-form-vault' ) . '">';

		submit_button( __( 'Filter', 'divi-form-vault' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Load the page of rows.
	 */
	public function prepare_items() {
		$per_page = 25;
		$paged    = max( 1, $this->get_pagenum() );

		$args = DFV_Admin_List::current_filters();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'submitted_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$total = DFV_Store::count( $args );

		$args['orderby'] = $orderby;
		$args['order']   = $order;
		$args['number']  = $per_page;
		$args['offset']  = ( $paged - 1 ) * $per_page;

		$this->items = DFV_Store::query( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return '<input type="checkbox" name="submission[]" value="' . (int) $item['id'] . '">';
	}

	/**
	 * Submitted column: date + view link + row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_submitted( $item ) {
		$view = admin_url( 'admin.php?page=' . DFV_Admin_List::MENU_SLUG . '&view=' . (int) $item['id'] );
		$when = mysql2date( 'Y-m-d H:i', $item['submitted_at'] );

		$title = '<a href="' . esc_url( $view ) . '"><strong>' . esc_html( $when ) . '</strong></a>';
		if ( 'new' === $item['status'] && ! (int) $item['is_spam'] ) {
			$title .= ' <span class="dashicons dashicons-marker" style="color:#d63638;font-size:12px;vertical-align:middle" title="' . esc_attr__( 'New', 'divi-form-vault' ) . '"></span>';
		}

		$delete = wp_nonce_url(
			admin_url( 'admin-post.php?action=dfv_row_action&do=delete&id=' . (int) $item['id'] ),
			DFV_Admin_List::NONCE_ACT
		);

		$actions = array(
			'view'   => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'divi-form-vault' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Delete this submission permanently?', 'divi-form-vault' ) ) ) . ');" style="color:#b32d2e">' . esc_html__( 'Delete', 'divi-form-vault' ) . '</a>',
		);
		return $title . $this->row_actions( $actions );
	}

	/**
	 * Lead column: the human snippet (name / email / first field).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_lead( $item ) {
		$fields = (array) $item['fields'];
		$parts  = array();
		foreach ( $fields as $key => $value ) {
			if ( '_' === substr( (string) $key, 0, 1 ) || ! is_scalar( $value ) ) {
				continue;
			}
			$text = trim( (string) $value );
			if ( '' === $text ) {
				continue;
			}
			$parts[] = $text;
			if ( count( $parts ) >= 2 ) {
				break;
			}
		}
		$snippet = implode( ' - ', $parts );
		if ( strlen( $snippet ) > 90 ) {
			$snippet = substr( $snippet, 0, 87 ) . '...';
		}
		return esc_html( $snippet );
	}

	/**
	 * Form column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_form( $item ) {
		return esc_html( '' !== $item['form_name'] ? $item['form_name'] : $item['form_id'] );
	}

	/**
	 * Page column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_page( $item ) {
		$label = '' !== $item['page_title'] ? $item['page_title'] : $item['page_url'];
		if ( '' === $label ) {
			return '';
		}
		if ( '' !== $item['page_url'] ) {
			return '<a href="' . esc_url( $item['page_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
		}
		return esc_html( $label );
	}

	/**
	 * Source / campaign column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_campaign( $item ) {
		$bits = array_filter( array( $item['utm_source'], $item['utm_campaign'] ) );
		return esc_html( implode( ' / ', $bits ) );
	}

	/**
	 * Device column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_device( $item ) {
		return esc_html( $item['device'] );
	}

	/**
	 * Status column: spam chip beats status chip.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_state( $item ) {
		if ( (int) $item['is_spam'] ) {
			return '<span style="color:#b23b2e;font-weight:600">' . esc_html__( 'Spam', 'divi-form-vault' ) . '</span>';
		}
		$labels = array(
			'new'      => __( 'New', 'divi-form-vault' ),
			'read'     => __( 'Read', 'divi-form-vault' ),
			'archived' => __( 'Archived', 'divi-form-vault' ),
		);
		$status = isset( $labels[ $item['status'] ] ) ? $labels[ $item['status'] ] : $item['status'];
		$color  = 'new' === $item['status'] ? '#2271b1' : '#6b645c';
		return '<span style="color:' . esc_attr( $color ) . '">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) && is_scalar( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Empty-state text.
	 */
	public function no_items() {
		esc_html_e( 'No submissions yet. Once Divi is active, every Contact Form submission lands here automatically.', 'divi-form-vault' );
	}
}
