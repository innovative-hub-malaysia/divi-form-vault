<?php
/**
 * Settings (wp-admin) - two tabs, sectioned.
 *
 * Restructured 2026-07-28: the old six thin tabs + a standalone Privacy
 * page collapsed into TWO tabs so every screen has real content:
 *
 *   1. Settings       - how capture behaves: status strip, Attribution,
 *                       Spam protection, GA4 tracking. One form, one save.
 *   2. Data & Privacy - the data itself: what is stored (IP/UA), CSV export,
 *                       manual delete tools (PDPA - never automatic), the
 *                       legacy import, and uninstall behaviour.
 *
 * Option keys are unchanged (no migration). New concerns should join an
 * existing tab as a section first; only promote a new tab when a section
 * clearly outgrows this structure.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Settings_Page
 */
class DFV_Settings_Page {

	const CAP        = 'manage_options';
	const MENU_SLUG  = 'dfv-settings';
	const NONCE_ACT  = 'dfv_save_settings';
	const NONCE_NAME = 'dfv_settings_nonce';

	/**
	 * Nonce action for the data tools (delete). Kept as its own action so a
	 * settings-save nonce can never authorise a deletion.
	 */
	const NONCE_DATA = 'dfv_privacy_action';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_post_dfv_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_dfv_privacy_delete', array( __CLASS__, 'handle_delete' ) );
		add_filter( 'plugin_action_links_' . DFV_PLUGIN_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Register Settings under the Form Vault top-level menu (owned by the
	 * submissions screen). Late priority so it lands last in the submenu.
	 */
	public static function add_menu() {
		add_submenu_page(
			DFV_Admin_List::MENU_SLUG,
			__( 'Settings', 'divi-form-vault' ),
			__( 'Settings', 'divi-form-vault' ),
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url      = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'divi-form-vault' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * The tabs.
	 *
	 * @return array slug => label
	 */
	protected static function tabs() {
		return array(
			'general' => __( 'Settings', 'divi-form-vault' ),
			'data'    => __( 'Data & Privacy', 'divi-form-vault' ),
		);
	}

	/**
	 * Resolve the current tab from the query string.
	 *
	 * @return string
	 */
	protected static function current_tab() {
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab routing only.
		return isset( $tabs[ $tab ] ) ? $tab : 'general';
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'divi-form-vault' ) );
		}

		$tabs = self::tabs();
		$cur  = self::current_tab();
		$s    = DFV_Settings::all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Form Vault Settings', 'divi-form-vault' ) . '</h1>';

		if ( isset( $_GET['dfv_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'divi-form-vault' ) . '</p></div>';
		}
		if ( isset( $_GET['dfv_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counter.
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %d: deleted rows. */
				esc_html__( '%d submissions permanently deleted.', 'divi-form-vault' ),
				absint( $_GET['dfv_deleted'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
			echo '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug );
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				( $slug === $cur ) ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( 'data' === $cur ) {
			self::render_data_tab( $s );
		} else {
			self::render_general_tab( $s );
		}

		echo '</div>';
	}

	// =====================================================================
	// Tab 1: Settings (behaviour)
	// =====================================================================

	/**
	 * Status strip + Attribution + Spam + GA4, one form.
	 *
	 * @param array $s Settings.
	 */
	protected static function render_general_tab( $s ) {
		// --- Status strip. -------------------------------------------------.
		$divi = DFV_Plugin::divi_is_active();
		echo '<p style="margin:14px 0 4px">';
		if ( $divi ) {
			echo '<span class="dashicons dashicons-yes-alt" style="color:#2f7d5b"></span> ' . esc_html__( 'Divi is active - every Contact Form submission is being captured automatically. There is no form to build or connect.', 'divi-form-vault' );
		} else {
			echo '<span class="dashicons dashicons-warning" style="color:#b23b2e"></span> ' . esc_html__( 'Divi is not active - live capture is paused until Divi is enabled. Existing leads and settings remain available.', 'divi-form-vault' );
		}
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="dfv_save_settings">';
		echo '<input type="hidden" name="tab" value="general">';
		wp_nonce_field( self::NONCE_ACT, self::NONCE_NAME );

		// --- Attribution. --------------------------------------------------.
		self::divider();
		echo '<h2>' . esc_html__( 'Attribution', 'divi-form-vault' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each lead records which campaign and page produced it - the reason this plugin exists.', 'divi-form-vault' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="dfv_attribution_model">' . esc_html__( 'Attribution model', 'divi-form-vault' ) . '</label></th><td>';
		$models = array(
			'last'  => __( 'Last touch', 'divi-form-vault' ),
			'first' => __( 'First touch', 'divi-form-vault' ),
			'both'  => __( 'Store both (show last touch)', 'divi-form-vault' ),
		);
		echo '<select name="attribution_model" id="dfv_attribution_model">';
		foreach ( $models as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $s['attribution_model'], $val, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Recommended: store both and show last touch by default.', 'divi-form-vault' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="dfv_attribution_cookie_days">' . esc_html__( 'Attribution window (days)', 'divi-form-vault' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="730" name="attribution_cookie_days" id="dfv_attribution_cookie_days" value="' . esc_attr( $s['attribution_cookie_days'] ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'How long a visitor keeps their campaign attribution before it expires.', 'divi-form-vault' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		// --- Spam protection. ----------------------------------------------.
		self::divider();
		echo '<h2>' . esc_html__( 'Spam protection', 'divi-form-vault' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Suspect submissions are flagged, never blocked - they stay visible under the Spam filter and are excluded from lead figures.', 'divi-form-vault' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( 'honeypot_enabled', __( 'Honeypot', 'divi-form-vault' ), __( 'Flag bot submissions using a hidden honeypot field.', 'divi-form-vault' ), $s['honeypot_enabled'] );
		self::checkbox_row( 'spam_heuristics', __( 'Heuristics', 'divi-form-vault' ), __( 'Flag high-confidence tells (link stuffing, bbcode, gibberish).', 'divi-form-vault' ), $s['spam_heuristics'] );
		echo '</tbody></table>';

		// --- GA4 tracking. -------------------------------------------------.
		self::divider();
		echo '<h2>' . esc_html__( 'GA4 tracking', 'divi-form-vault' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each genuine lead fires a GA4 generate_lead event carrying its page, form, campaign, and device.', 'divi-form-vault' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( 'ga4_enabled', __( 'generate_lead event', 'divi-form-vault' ), __( 'Fire the event on each genuine (non-spam) submission.', 'divi-form-vault' ), $s['ga4_enabled'] );
		echo '<tr><th scope="row"><label for="dfv_ga4_measurement_id">' . esc_html__( 'Measurement ID', 'divi-form-vault' ) . '</label></th><td>';
		echo '<input type="text" name="ga4_measurement_id" id="dfv_ga4_measurement_id" value="' . esc_attr( $s['ga4_measurement_id'] ) . '" class="regular-text" placeholder="G-XXXXXXXXXX">';
		echo '<p class="description">' . esc_html__( 'Leave blank when the site already runs GTM - events ride the existing dataLayer. Set an ID only for a site with no tag manager.', 'divi-form-vault' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'divi-form-vault' ) );
		echo '</form>';
	}

	// =====================================================================
	// Tab 2: Data & Privacy
	// =====================================================================

	/**
	 * What is stored + export + manual delete tools + legacy import +
	 * uninstall behaviour.
	 *
	 * @param array $s Settings.
	 */
	protected static function render_data_tab( $s ) {
		// --- Storage settings (one form incl. uninstall behaviour). --------.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="dfv_save_settings">';
		echo '<input type="hidden" name="tab" value="data">';
		wp_nonce_field( self::NONCE_ACT, self::NONCE_NAME );

		echo '<h2>' . esc_html__( 'What is stored', 'divi-form-vault' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Form fields and attribution are always stored. These two are optional and PDPA-sensitive.', 'divi-form-vault' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( 'store_ip', __( 'IP address', 'divi-form-vault' ), __( 'Used by spam heuristics. Purgeable with the delete tools below.', 'divi-form-vault' ), $s['store_ip'] );
		self::checkbox_row( 'store_user_agent', __( 'User agent', 'divi-form-vault' ), __( 'Used to derive the device (desktop / mobile / tablet).', 'divi-form-vault' ), $s['store_user_agent'] );
		echo '</tbody></table>';

		self::divider();
		echo '<h2>' . esc_html__( 'Uninstall', 'divi-form-vault' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( 'purge_on_uninstall', __( 'Delete all data on uninstall', 'divi-form-vault' ), __( 'Off by default - your leads survive deleting the plugin. Turn on only if removal must purge everything.', 'divi-form-vault' ), $s['purge_on_uninstall'] );
		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'divi-form-vault' ) );
		echo '</form>';

		// --- Export. -------------------------------------------------------.
		self::divider();
		echo '<h2>' . esc_html__( 'Export', 'divi-form-vault' ) . '</h2>';
		$export_all = wp_nonce_url( admin_url( 'admin-post.php?action=dfv_export_csv' ), DFV_Admin_List::NONCE_ACT );
		echo '<p><a href="' . esc_url( $export_all ) . '" class="button button-primary">' . esc_html__( 'Export ALL submissions (CSV)', 'divi-form-vault' ) . '</a> ';
		echo esc_html__( 'For a filtered export, filter the Submissions list and use its Export CSV button.', 'divi-form-vault' ) . '</p>';

		// --- Delete (bulk by date range - the only delete that belongs in
		// Settings; single rows and per-email cleanups are done on the
		// Submissions list via its filters, row Delete, and bulk actions). ---.
		self::divider();
		echo '<h2>' . esc_html__( 'Delete', 'divi-form-vault' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Deletion is always manual, immediate, and permanent - nothing is ever auto-deleted or scheduled. Export first if you need a record. To delete single submissions (or by email), filter the Submissions list and delete there.', 'divi-form-vault' ) . '</p>';

		$action_url = admin_url( 'admin-post.php' );
		$confirm    = 'return confirm(' . esc_js( wp_json_encode( __( 'Permanently delete the matching submissions? This cannot be undone.', 'divi-form-vault' ) ) ) . ');';
		$card       = 'margin:12px 0;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;max-width:640px';

		echo '<form method="post" action="' . esc_url( $action_url ) . '" onsubmit="' . esc_attr( $confirm ) . '" style="' . esc_attr( $card ) . '">';
		wp_nonce_field( self::NONCE_DATA );
		echo '<input type="hidden" name="action" value="dfv_privacy_delete"><input type="hidden" name="mode" value="range">';
		echo '<strong>' . esc_html__( 'By date range (submitted between, inclusive)', 'divi-form-vault' ) . '</strong><br>';
		echo '<input type="date" name="from" required> - <input type="date" name="to" required> ';
		submit_button( __( 'Delete range', 'divi-form-vault' ), 'delete', '', false );
		echo '</form>';

		// --- Legacy import (only while old data is detectable). -------------.
		if ( class_exists( 'DFV_Import' ) ) {
			$src = DFV_Import::detect();
			if ( $src ) {
				self::divider();
				echo '<h2>' . esc_html__( 'Legacy import', 'divi-form-vault' ) . '</h2>';
				$state = DFV_Import::state();
				$url   = wp_nonce_url( admin_url( 'admin-post.php?action=dfv_run_import' ), DFV_Onboarding::NONCE_ACT );
				printf(
					/* translators: 1: submissions count, 2: storage kind, 3: storage name. */
					'<p>' . esc_html__( 'Old "Divi Contact Form DB" data detected: %1$d submissions (%2$s: %3$s).', 'divi-form-vault' ) . '</p>',
					(int) $src['count'],
					esc_html( $src['type'] ),
					esc_html( $src['name'] )
				);
				if ( ! empty( $state['done'] ) ) {
					printf(
						/* translators: 1: imported, 2: already imported, 3: unmappable, 4: failed. */
						'<p>' . esc_html__( 'Last run: %1$d imported · %2$d already imported · %3$d unmappable · %4$d insert-failed. Re-running never duplicates.', 'divi-form-vault' ) . '</p>',
						(int) $state['imported'],
						(int) $state['dup'],
						(int) $state['unmappable'],
						(int) $state['failed']
					);
				}
				// Skip diagnostics: show what the unmappable rows actually
				// contain, so the schema can be mapped instead of re-guessed.
				if ( ! empty( $state['diag'] ) && is_array( $state['diag'] ) ) {
					echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;max-width:760px;font-family:Consolas,monospace;font-size:12px">';
					echo '<p style="margin:0 0 6px;font-family:inherit"><strong>' . esc_html__( 'Why rows were skipped (share this with the developer):', 'divi-form-vault' ) . '</strong></p>';
					foreach ( $state['diag'] as $d ) {
						if ( ! is_array( $d ) ) {
							continue;
						}
						echo '<p style="margin:4px 0;font-family:inherit">';
						if ( isset( $d['meta_keys'] ) || isset( $d['content_len'] ) ) {
							echo esc_html( sprintf(
								'#%d [%s] %s | title: %s | meta: %s | content: %d chars%s',
								isset( $d['id'] ) ? (int) $d['id'] : 0,
								isset( $d['status'] ) ? (string) $d['status'] : '',
								isset( $d['reason'] ) ? (string) $d['reason'] : '',
								isset( $d['title'] ) && '' !== $d['title'] ? (string) $d['title'] : '(none)',
								! empty( $d['meta_keys'] ) ? implode( ', ', array_map( 'strval', (array) $d['meta_keys'] ) ) : '(none)',
								isset( $d['content_len'] ) ? (int) $d['content_len'] : 0,
								! empty( $d['content_head'] ) ? ( ' | head: ' . $d['content_head'] ) : ''
							) );
						} else {
							echo esc_html( sprintf(
								'#%d %s',
								isset( $d['id'] ) ? (int) $d['id'] : 0,
								isset( $d['reason'] ) ? (string) $d['reason'] : ''
							) );
						}
						echo '</p>';
					}
					echo '</div>';
				}
				echo '<p><a href="' . esc_url( $url ) . '" class="button">' . esc_html__( 'Run import', 'divi-form-vault' ) . '</a></p>';
			}
		}
	}

	// =====================================================================
	// Shared bits + handlers
	// =====================================================================

	/**
	 * The uniform section divider for the Data & Privacy tab.
	 */
	protected static function divider() {
		echo '<hr style="margin:24px 0">';
	}

	/**
	 * A checkbox settings row.
	 *
	 * @param string $name  Field name / setting key.
	 * @param string $label Row label.
	 * @param string $desc  Description under the checkbox.
	 * @param bool   $on    Current value.
	 */
	protected static function checkbox_row( $name, $label, $desc, $on ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $on, true, false ) . '> ' . esc_html( $desc ) . '</label>';
		echo '</td></tr>';
	}

	/**
	 * Persist the submitted tab, merged into the existing settings.
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'divi-form-vault' ) );
		}
		check_admin_referer( self::NONCE_ACT, self::NONCE_NAME );

		$tab      = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$settings = DFV_Settings::all();

		if ( 'data' === $tab ) {
			$settings['store_ip']           = ! empty( $_POST['store_ip'] );
			$settings['store_user_agent']   = ! empty( $_POST['store_user_agent'] );
			$settings['purge_on_uninstall'] = ! empty( $_POST['purge_on_uninstall'] );
		} else {
			$model                               = isset( $_POST['attribution_model'] ) ? sanitize_key( wp_unslash( $_POST['attribution_model'] ) ) : 'both';
			$settings['attribution_model']       = in_array( $model, array( 'last', 'first', 'both' ), true ) ? $model : 'both';
			$days                                = isset( $_POST['attribution_cookie_days'] ) ? absint( $_POST['attribution_cookie_days'] ) : 90;
			$settings['attribution_cookie_days'] = max( 1, min( 730, $days ) );
			$settings['honeypot_enabled']        = ! empty( $_POST['honeypot_enabled'] );
			$settings['spam_heuristics']         = ! empty( $_POST['spam_heuristics'] );
			$settings['ga4_enabled']             = ! empty( $_POST['ga4_enabled'] );
			$settings['ga4_measurement_id']      = isset( $_POST['ga4_measurement_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_measurement_id'] ) ) : '';
		}

		DFV_Settings::save( $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::MENU_SLUG,
					'tab'       => $tab,
					'dfv_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Run a delete tool (Data & Privacy tab).
	 */
	public static function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to delete data.', 'divi-form-vault' ) );
		}
		check_admin_referer( self::NONCE_DATA );

		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$deleted = 0;

		// Range-only by design: single rows and per-email cleanups live on the
		// Submissions list (filter -> row Delete / bulk delete).
		if ( 'range' === $mode && ! empty( $_POST['from'] ) && ! empty( $_POST['to'] ) ) {
			$deleted = DFV_Store::delete_by(
				array(
					'date_from' => sanitize_text_field( wp_unslash( $_POST['from'] ) ),
					'date_to'   => sanitize_text_field( wp_unslash( $_POST['to'] ) ),
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'tab'         => 'data',
					'dfv_deleted' => (int) $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
