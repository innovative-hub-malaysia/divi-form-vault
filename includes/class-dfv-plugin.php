<?php
/**
 * Main plugin loader (singleton).
 *
 * Wires the always-on modules and enforces the Divi dependency guard. The
 * capture core (Stage 1+) is builder-agnostic, so the loader deliberately keeps
 * a clean split:
 *
 *   - Admin surface (settings, and later the submissions list + analytics) is
 *     always available so a site owner can read past leads and adjust config
 *     even if Divi is temporarily inactive.
 *   - The Divi capture adapter (Stage 1) will attach its hooks ONLY when Divi
 *     is active. If Divi is not present we show a single admin notice and no-op
 *     the capture side rather than firing errors.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Plugin
 */
final class DFV_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var DFV_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Get / create the instance.
	 *
	 * @return DFV_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Load dependencies and attach modules.
	 */
	protected function boot() {
		$this->includes();
		$this->load_textdomain();

		// Keep the table schema current on plugin update, without needing a
		// re-activation. Cheap: it only runs dbDelta when the stored version
		// differs from the code version.
		DFV_Install::maybe_upgrade();

		// Admin surface is always on (read past leads / adjust config even if
		// Divi is inactive).
		if ( is_admin() ) {
			DFV_Admin_List::init();
			DFV_Admin_Analytics::init();
			DFV_Settings_Page::init();
			DFV_Onboarding::init();
			add_action( 'admin_notices', array( __CLASS__, 'install_error_notice' ) );
		}

		// The Divi dependency guard. When Divi is missing we surface one clear
		// notice and stop before attaching any capture hooks - a safe no-op.
		if ( ! self::divi_is_active() ) {
			add_action( 'admin_notices', array( __CLASS__, 'divi_missing_notice' ) );
			return;
		}

		// --- Divi is active: attach the capture side. -----------------------.
		$divi = new DFV_Source_Divi();
		$divi->register();

		DFV_Attribution::init();
		DFV_Spam::init();
		DFV_GA4::init();
	}

	/**
	 * Require class files.
	 */
	protected function includes() {
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-settings.php';
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-store.php';
		require_once DFV_PLUGIN_DIR . 'includes/capture/interface-dfv-source.php';
		require_once DFV_PLUGIN_DIR . 'includes/capture/class-dfv-capture.php';
		require_once DFV_PLUGIN_DIR . 'includes/capture/class-dfv-source-divi.php';
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-attribution.php';
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-spam.php';
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-ga4.php';

		if ( is_admin() ) {
			require_once DFV_PLUGIN_DIR . 'includes/class-dfv-import.php';
			require_once DFV_PLUGIN_DIR . 'includes/class-dfv-onboarding.php';
			require_once DFV_PLUGIN_DIR . 'includes/admin/class-dfv-admin-list.php';
			require_once DFV_PLUGIN_DIR . 'includes/admin/class-dfv-admin-analytics.php';
			require_once DFV_PLUGIN_DIR . 'includes/admin/class-dfv-settings-page.php';
		}
	}

	/**
	 * Load translations. Text domain === plugin slug (locked).
	 */
	protected function load_textdomain() {
		load_plugin_textdomain(
			'divi-form-vault',
			false,
			dirname( DFV_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Is Divi (theme or the Divi Builder plugin) active?
	 *
	 * Divi's builder core defines these constants / functions once it loads,
	 * whether it arrives via the Divi or Extra theme or the standalone Divi
	 * Builder plugin. We check the presence of the builder, not a specific
	 * version. The exact contact-form submit hook is confirmed against the
	 * installed version in Stage 1 - this guard only answers "is Divi here".
	 *
	 * @return bool
	 */
	public static function divi_is_active() {
		if ( defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ) ) {
			return true;
		}

		if ( function_exists( 'et_setup_theme' ) || class_exists( 'ET_Builder_Element' ) ) {
			return true;
		}

		// Fallback: the active theme (or its parent) is Divi or Extra.
		$theme    = wp_get_theme();
		$names    = array( strtolower( (string) $theme->get( 'Name' ) ), strtolower( (string) $theme->get_template() ) );
		$divi_set = array( 'divi', 'extra' );

		return (bool) array_intersect( $names, $divi_set );
	}

	/**
	 * RED notice when the submissions table could not be created - a vault
	 * that cannot store is never allowed to be silent (the first-deploy lesson:
	 * dbDelta failed quietly and every capture/import insert died unseen).
	 */
	public static function install_error_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$error = get_option( DFV_Install::ERROR_OPTION );
		if ( empty( $error ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Divi Form Vault:</strong> ';
		printf(
			/* translators: %s: database error. */
			esc_html__( 'the submissions table could not be created, so NOTHING can be stored (capture and import both fail). Database said: %s - share this with the developer. The plugin retries on every page load until it succeeds.', 'divi-form-vault' ),
			'<code>' . esc_html( $error ) . '</code>'
		);
		echo '</p></div>';
	}

	/**
	 * Admin notice shown when Divi is not active.
	 *
	 * Non-blocking: the plugin still stores/serves any existing data; only the
	 * live capture is inert until Divi is present.
	 */
	public static function divi_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: plugin name. */
				__( '<strong>%s</strong> needs the Divi theme or the Divi Builder plugin to capture Contact Form submissions. It is loaded but the live capture is inactive until Divi is enabled. Existing leads and settings remain available.', 'divi-form-vault' ),
				'Divi Form Vault'
			)
		);
		echo '</p></div>';
	}
}
