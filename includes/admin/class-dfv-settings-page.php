<?php
/**
 * Settings (wp-admin) - three tabs, sectioned.
 *
 * Restructured 2026-07-28: the old six thin tabs + a standalone Privacy
 * page collapsed into TWO tabs so every screen has real content:
 *
 *   1. Settings       - how capture behaves: status strip, Attribution,
 *                       Spam protection, GA4 tracking, Updates. One form,
 *                       one save.
 *   2. Data & Privacy - the data itself: what is stored (IP/UA), CSV export,
 *                       manual delete tools (PDPA - never automatic), the
 *                       legacy import, and uninstall behaviour.
 *   3. Guide          - (1.2.0) a read-only FAQ for whoever runs the site
 *                       after us: what the plugin does, the screens, the
 *                       states, and the usual "why is X not happening".
 *                       No form. Linked from the plugin row on the Plugins
 *                       screen so it is findable without a handover call.
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
		// Priority 20: after the update checker has appended "Check for updates".
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 20, 2 );
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
	 * Add a "FAQ" link to the plugin row meta (the "Version | By | View
	 * details | Check for updates" line), leading to the Guide tab.
	 *
	 * @param array  $links Existing meta links.
	 * @param string $file  Plugin basename the row is for.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( DFV_PLUGIN_BASENAME !== $file ) {
			return $links;
		}
		$url     = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=guide' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'FAQ', 'divi-form-vault' ) . '</a>';
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
			'guide'   => __( 'Guide', 'divi-form-vault' ),
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
		} elseif ( 'guide' === $cur ) {
			self::render_guide_tab();
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

		// --- Updates. ------------------------------------------------------.
		self::divider();
		echo '<h2>' . esc_html__( 'Updates', 'divi-form-vault' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'New versions are published as GitHub Releases and offered on the Plugins screen like any other plugin.', 'divi-form-vault' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		if ( defined( 'DFV_DISABLE_AUTO_UPDATE' ) ) {
			// The constant is an ops override; show the state, do not offer a switch that would not work.
			echo '<tr><th scope="row">' . esc_html__( 'Automatic updates', 'divi-form-vault' ) . '</th><td>';
			echo '<p>' . esc_html( DFV_DISABLE_AUTO_UPDATE ? __( 'Disabled by DFV_DISABLE_AUTO_UPDATE in wp-config.php. Remove that line to control it here.', 'divi-form-vault' ) : __( 'Forced on by DFV_DISABLE_AUTO_UPDATE in wp-config.php. Remove that line to control it here.', 'divi-form-vault' ) ) . '</p>';
			echo '</td></tr>';
		} else {
			self::checkbox_row( 'auto_update', __( 'Automatic updates', 'divi-form-vault' ), __( 'Install new versions automatically (recommended). When off, updates are still offered on the Plugins screen for you to install by hand.', 'divi-form-vault' ), $s['auto_update'] );
		}
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

	// =====================================================================
	// Tab 3: Guide (FAQ)
	// =====================================================================

	/**
	 * A read-only FAQ. Written for the person who inherits the site, not for
	 * us: plain language, one answer per question, no form. Keep it accurate
	 * to the code - a wrong guide costs more than no guide.
	 */
	protected static function render_guide_tab() {
		$subs      = admin_url( 'admin.php?page=' . DFV_Admin_List::MENU_SLUG );
		$analytics = admin_url( 'admin.php?page=' . DFV_Admin_Analytics::MENU_SLUG );
		$settings  = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$data      = $settings . '&tab=data';

		$sections = array(
			array(
				'title' => __( 'What this plugin does', 'divi-form-vault' ),
				'items' => array(
					array(
						__( 'Why is it installed?', 'divi-form-vault' ),
						__( 'Divi\'s Contact Form module only emails a submission - it keeps no copy. Form Vault records every submission in this site\'s own database as a lead, together with where it came from (UTM source / medium / campaign, landing page, referrer, the page it was sent from, and device). Emails still go out exactly as before.', 'divi-form-vault' ),
					),
					array(
						__( 'Do I need to build or connect a form?', 'divi-form-vault' ),
						__( 'No. Every Divi Contact Form on the site is captured automatically the moment Divi is active. The plugin never changes the form, its validation, or its notification email.', 'divi-form-vault' ),
					),
					array(
						__( 'Where do I find things?', 'divi-form-vault' ),
						sprintf(
							/* translators: 1: Submissions link, 2: Analytics link, 3: Settings link. */
							__( 'Everything is under the Form Vault menu on the left: %1$s (the leads), %2$s (what is working), and %3$s (this page). The number badge on the menu is how many leads are still unread.', 'divi-form-vault' ),
							'<a href="' . esc_url( $subs ) . '">' . esc_html__( 'Submissions', 'divi-form-vault' ) . '</a>',
							'<a href="' . esc_url( $analytics ) . '">' . esc_html__( 'Analytics', 'divi-form-vault' ) . '</a>',
							'<a href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'divi-form-vault' ) . '</a>'
						),
					),
				),
			),
			array(
				'title' => __( 'Working the leads', 'divi-form-vault' ),
				'items' => array(
					array(
						__( 'What do New, Read and Spam mean?', 'divi-form-vault' ),
						__( 'New = nobody has opened it yet. Read = someone opened the detail view, or it was marked as read. Spam = flagged as a suspect submission; it stays visible under the Spam filter and is left out of every lead count. Nothing is ever hidden or deleted automatically.', 'divi-form-vault' ),
					),
					array(
						__( 'How do I mark many leads at once?', 'divi-form-vault' ),
						__( 'Tick the boxes in the Submissions list, choose Mark as read / Mark as unread / Mark spam / Mark genuine / Delete permanently from the Bulk actions menu, and click Apply. Delete cannot be undone.', 'divi-form-vault' ),
					),
					array(
						__( 'A real enquiry was flagged as spam. What now?', 'divi-form-vault' ),
						__( 'Open it (or tick it in the list) and choose Mark genuine. It rejoins the lead figures immediately. Spam flags are a hint, never a block - the sender always received the normal confirmation.', 'divi-form-vault' ),
					),
					array(
						__( 'How do I find a particular lead?', 'divi-form-vault' ),
						__( 'Use the search box above the list (it searches every submitted field) or narrow the list by form, campaign source, spam state, and date range with the filter bar. The date range follows the site\'s timezone setting.', 'divi-form-vault' ),
					),
					array(
						__( 'How do I get the leads into Excel or a CRM?', 'divi-form-vault' ),
						sprintf(
							/* translators: %s: Data & Privacy link. */
							__( 'Export CSV at the top of the Submissions list exports exactly what the current filter shows. To export everything regardless of filter, use Export on the %s tab.', 'divi-form-vault' ),
							'<a href="' . esc_url( $data ) . '">' . esc_html__( 'Data & Privacy', 'divi-form-vault' ) . '</a>'
						),
					),
				),
			),
			array(
				'title' => __( 'Reading the Analytics page', 'divi-form-vault' ),
				'items' => array(
					array(
						__( 'What is "Needs attention"?', 'divi-form-vault' ),
						__( 'Genuine leads that are still unread after 3 days. It is the first thing on the page because an unanswered lead is the most expensive thing this plugin can show you. Opening a lead clears it from the list.', 'divi-form-vault' ),
					),
					array(
						__( 'What do the tiles and bars count?', 'divi-form-vault' ),
						__( 'Genuine (non-spam) leads only. "Last 30 days" is compared with the 30 days before it. The page, source, campaign, form and device breakdowns are all-time. Hover a bar in the Lead flow chart to see that day\'s number.', 'divi-form-vault' ),
					),
					array(
						__( 'Why is the campaign breakdown empty?', 'divi-form-vault' ),
						__( 'A lead only carries a campaign when the visitor arrived through a link with UTM parameters (utm_source, utm_medium, utm_campaign). Add them to every ad, email and social link and the breakdown fills in by itself. Direct visits and plain organic clicks have no UTM and show as untagged.', 'divi-form-vault' ),
					),
				),
			),
			array(
				'title' => __( 'Settings explained', 'divi-form-vault' ),
				'items' => array(
					array(
						__( 'Attribution model and window', 'divi-form-vault' ),
						__( 'A visitor\'s campaign is remembered in a cookie for the number of days in the attribution window. "Last touch" credits the most recent campaign before the submission, "First touch" the very first one; the default stores both and shows last touch. Change this only if your reporting follows a specific model.', 'divi-form-vault' ),
					),
					array(
						__( 'Spam protection', 'divi-form-vault' ),
						__( 'Honeypot = a hidden field that only bots fill in. Heuristics = a few high-confidence tells such as link stuffing and gibberish. Both flag rather than block, so a mistake costs one click (Mark genuine), never a lost enquiry. Divi\'s own captcha still runs first, unchanged.', 'divi-form-vault' ),
					),
					array(
						__( 'GA4 tracking', 'divi-form-vault' ),
						__( 'Each genuine lead fires a generate_lead event so it can be a conversion in Google Analytics and Google Ads. If the site runs Google Tag Manager, leave the Measurement ID blank - the event rides the existing dataLayer. Fill in a G- ID only on a site with no tag manager at all.', 'divi-form-vault' ),
					),
					array(
						__( 'Automatic updates', 'divi-form-vault' ),
						__( 'New versions are published as GitHub Releases and installed on their own within about 12 hours; this is on by default so every site stays current. Turn it off under Settings > Updates if this site needs updates tested first - new versions are then still offered on the Plugins screen for you to install by hand. Use the Check for updates link on the Plugins screen to look for a new version right now. The Enable / Disable auto-updates link WordPress shows on the Plugins screen does not control this plugin; the setting here does.', 'divi-form-vault' ),
					),
				),
			),
			array(
				'title' => __( 'Data and privacy', 'divi-form-vault' ),
				'items' => array(
					array(
						__( 'What personal data is stored?', 'divi-form-vault' ),
						sprintf(
							/* translators: %s: Data & Privacy link. */
							__( 'Whatever the visitor typed into the form, plus their IP address and browser user agent (both can be switched off on the %s tab; they are used for spam detection and the device breakdown). Nothing is sent anywhere except the optional GA4 event.', 'divi-form-vault' ),
							'<a href="' . esc_url( $data ) . '">' . esc_html__( 'Data & Privacy', 'divi-form-vault' ) . '</a>'
						),
					),
					array(
						__( 'Someone asked us to delete their data (PDPA).', 'divi-form-vault' ),
						__( 'Find their submissions in the list (search by name or email), tick them, and Delete permanently. To clear a whole period, use Delete range on the Data & Privacy tab. The plugin never deletes anything on its own - retention is your decision.', 'divi-form-vault' ),
					),
					array(
						__( 'What happens if the plugin is deleted?', 'divi-form-vault' ),
						__( 'By default the leads stay in the database so nothing is lost by accident. Tick "Delete all data on uninstall" on the Data & Privacy tab first if you really want a clean removal.', 'divi-form-vault' ),
					),
				),
			),
			array(
				'title' => __( 'Troubleshooting', 'divi-form-vault' ),
				'items' => array(
					array(
						__( 'A submission is not showing up.', 'divi-form-vault' ),
						__( 'Check, in order: (1) the Settings tab shows "Divi is active" - capture pauses while Divi is off; (2) the Spam filter in the Submissions list - it may be flagged; (3) that the form really is a Divi Contact Form module, not a third-party form plugin; (4) whether a caching or security plugin is blocking the submission before Divi sees it, in which case the notification email will not arrive either.', 'divi-form-vault' ),
					),
					array(
						__( 'The dates look wrong.', 'divi-form-vault' ),
						__( 'Every date follows Settings > General > Timezone in WordPress. If leads appear a few hours off, that setting is wrong for the site, not the plugin.', 'divi-form-vault' ),
					),
					array(
						__( 'Where is the old "Divi Contact Form DB" data?', 'divi-form-vault' ),
						__( 'If that plugin was on the site before, a one-click import was offered on first activation and is still available under Legacy import on the Data & Privacy tab while the old table exists.', 'divi-form-vault' ),
					),
					array(
						__( 'Who do I contact?', 'divi-form-vault' ),
						__( 'The plugin is built and maintained by Innovative Hub. The "View details" link on the Plugins screen shows the current version and its release notes.', 'divi-form-vault' ),
					),
				),
			),
		);

		echo '<style>
			.dfv-guide{max-width:820px}
			.dfv-guide h2{font-size:15px;margin:26px 0 6px}
			.dfv-guide details{border-bottom:1px solid #dcdcde;padding:9px 0}
			.dfv-guide summary{cursor:pointer;font-weight:600;color:#1d2327}
			.dfv-guide summary:hover{color:#2271b1}
			.dfv-guide details p{margin:8px 0 2px;color:#3c434a;line-height:1.55}
		</style>';
		echo '<div class="dfv-guide">';
		echo '<p class="description" style="margin-top:14px">' . esc_html__( 'A short guide for whoever runs this site. Click a question to open it.', 'divi-form-vault' ) . '</p>';
		$allowed = array( 'a' => array( 'href' => array() ) );
		foreach ( $sections as $section ) {
			echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
			foreach ( $section['items'] as $item ) {
				echo '<details><summary>' . esc_html( $item[0] ) . '</summary><p>' . wp_kses( $item[1], $allowed ) . '</p></details>';
			}
		}
		echo '</div>';
	}

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
			if ( ! defined( 'DFV_DISABLE_AUTO_UPDATE' ) ) {
				// Under the constant the checkbox is not rendered; keep the stored value untouched.
				$settings['auto_update'] = ! empty( $_POST['auto_update'] );
			}
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
