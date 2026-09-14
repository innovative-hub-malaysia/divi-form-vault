<?php
/**
 * Fired during plugin activation.
 *
 * Non-destructive: it creates our own submissions table and seeds default
 * options. It touches nothing that belongs to Divi, the theme, or any other
 * plugin.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Activator
 */
class DFV_Activator {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-install.php';
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-settings.php';

		// Create / upgrade our custom table.
		DFV_Install::install();

		// Seed defaults only when absent - never overwrite a returning site's
		// saved config.
		DFV_Settings::maybe_seed_defaults();

		update_option( 'dfv_version', DFV_VERSION );

		// First-activation onboarding: offer the one-click import from the old
		// "Divi Contact Form DB" plugin. add_option never overwrites, so a
		// re-activation on a site that already dismissed/ran it stays quiet.
		add_option( 'dfv_onboarding_pending', 1 );
	}
}
