<?php
/**
 * First-activation onboarding: offer the one-click import.
 *
 * Flow (SPEC 5.1b): on first activation, if old "Divi Contact Form DB" data is
 * detected, an admin notice offers a one-click Import. Running it imports the
 * history (idempotent), then tries to deactivate the old plugin; if that is
 * not possible a clear manual-deactivation notice is shown instead. The old
 * data is always left intact.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Onboarding
 */
class DFV_Onboarding {

	const PENDING_OPTION = 'dfv_onboarding_pending';
	const NONCE_ACT      = 'dfv_run_import';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice' ) );
		add_action( 'admin_post_dfv_run_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_dfv_dismiss_onboarding', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Render the onboarding / result notices.
	 */
	public static function maybe_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Import-result notice - a TRUE one-shot: the result lives in a
		// transient that is deleted on first render, so a page refresh (or a
		// bookmarked URL) can never resurrect it. Never driven by query args.
		$result = get_transient( 'dfv_import_result' );
		if ( is_array( $result ) ) {
			delete_transient( 'dfv_import_result' );

			$imported = isset( $result['imported'] ) ? (int) $result['imported'] : 0;
			$skipped  = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;

			echo '<div class="notice ' . ( $imported > 0 ? 'notice-success' : 'notice-warning' ) . ' is-dismissible"><p><strong>Divi Form Vault:</strong> ';
			printf(
				/* translators: 1: imported, 2: already imported, 3: unmappable, 4: failed. */
				esc_html__( 'Import finished - %1$d imported · %2$d already imported · %3$d unmappable · %4$d insert-failed.', 'divi-form-vault' ),
				$imported,
				isset( $result['dup'] ) ? (int) $result['dup'] : 0,
				isset( $result['unmappable'] ) ? (int) $result['unmappable'] : 0,
				isset( $result['failed'] ) ? (int) $result['failed'] : 0
			);
			if ( 0 === $imported && $skipped > 0 ) {
				echo ' ' . esc_html__( 'See "Why rows were skipped" under Settings > Data & Privacy > Legacy import.', 'divi-form-vault' );
			}
			if ( ! empty( $result['deactivated'] ) ) {
				echo ' ' . esc_html__( 'The old plugin has been deactivated. Its data was left untouched as a fallback.', 'divi-form-vault' );
			} elseif ( DFV_Import::legacy_plugin_file() ) {
				echo ' <strong>' . esc_html__( 'Please deactivate the old "Divi Contact Form DB" plugin manually (Plugins screen) - it could not be deactivated automatically.', 'divi-form-vault' ) . '</strong>';
			}
			echo '</p></div>';
			return;
		}

		// The standing onboarding offer.
		if ( ! get_option( self::PENDING_OPTION ) ) {
			return;
		}

		$state = DFV_Import::state();
		if ( ! empty( $state['done'] ) ) {
			delete_option( self::PENDING_OPTION );
			return;
		}

		$src = DFV_Import::detect();
		if ( ! $src ) {
			// Nothing detected - stay QUIET but keep the pending flag. Never
			// self-dismiss on a failed detection: if the old data exists in a
			// shape we learn about later (or the old plugin is activated
			// after us), the offer must still appear. Only a completed import
			// or an explicit "Not now" ends onboarding.
			return;
		}

		$import_url  = wp_nonce_url( admin_url( 'admin-post.php?action=dfv_run_import' ), self::NONCE_ACT );
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=dfv_dismiss_onboarding' ), self::NONCE_ACT );

		echo '<div class="notice notice-info"><p><strong>Divi Form Vault:</strong> ';
		printf(
			/* translators: 1: submissions count, 2: storage kind (table/cpt), 3: storage name. */
			esc_html__( 'Found %1$d submissions from the old "Divi Contact Form DB" plugin (%2$s: %3$s). Import them into the vault? The old data stays untouched and re-running never duplicates.', 'divi-form-vault' ),
			(int) $src['count'],
			esc_html( $src['type'] ),
			esc_html( $src['name'] )
		);
		echo '</p><p>';
		echo '<a href="' . esc_url( $import_url ) . '" class="button button-primary">' . esc_html__( 'Import now', 'divi-form-vault' ) . '</a> ';
		echo '<a href="' . esc_url( $dismiss_url ) . '" class="button">' . esc_html__( 'Not now', 'divi-form-vault' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Run the import, then try to deactivate the old plugin.
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the import.', 'divi-form-vault' ) );
		}
		check_admin_referer( self::NONCE_ACT );

		$result                = DFV_Import::run();
		$result['deactivated'] = DFV_Import::deactivate_legacy();

		delete_option( self::PENDING_OPTION );

		// One-shot result for the notice (consumed + deleted on first render).
		set_transient( 'dfv_import_result', $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . DFV_Admin_List::MENU_SLUG ) );
		exit;
	}

	/**
	 * Dismiss the onboarding offer. The import stays available from Settings >
	 * Advanced whenever legacy data is still detected.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'divi-form-vault' ) );
		}
		check_admin_referer( self::NONCE_ACT );
		delete_option( self::PENDING_OPTION );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
