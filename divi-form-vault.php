<?php
/**
 * Plugin Name:       Divi Form Vault
 * Plugin URI:        https://www.innovativehub.com.my/
 * Description:       Save every Divi Contact Form submission as an attributed lead (UTM, page, device), with backend analytics and a GA4 generate_lead event.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Innovative Hub
 * Author URI:        https://www.innovativehub.com.my/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       divi-form-vault
 * Domain Path:       /languages
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'DFV_VERSION' ) ) {
	return;
}

define( 'DFV_VERSION', '1.1.0' );
define( 'DFV_PLUGIN_FILE', __FILE__ );
define( 'DFV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DFV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DFV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// The installer owns the table schema + its version; it is used by both the
// activation hook and the runtime upgrade check, so load it unconditionally.
require_once DFV_PLUGIN_DIR . 'includes/class-dfv-install.php';

/**
 * Updates - loaded first, independent of Divi, so a new GitHub Release is
 * offered (and auto-installed) even when Divi is missing.
 */
require_once DFV_PLUGIN_DIR . 'includes/class-dfv-updates.php';
DFV_Updates::init();

/**
 * Activation: create the submissions table and seed default options.
 *
 * Non-destructive - it only creates our own table and options; it never
 * touches Divi, the theme, or any other plugin's data.
 */
register_activation_hook(
	__FILE__,
	static function () {
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-activator.php';
		DFV_Activator::activate();
	}
);

/**
 * Deactivation: intentionally a near no-op.
 *
 * We never drop the table or delete data on deactivation - only a purge on
 * uninstall (opt-in) removes anything. See uninstall.php.
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-deactivator.php';
		DFV_Deactivator::deactivate();
	}
);

/**
 * Boot the plugin on `after_setup_theme` - deliberately NOT `plugins_loaded`.
 *
 * When Divi ships as the active theme (the common case), its builder constants
 * (ET_BUILDER_VERSION / ET_CORE_VERSION) and functions are only defined once
 * the theme loads, which happens after `plugins_loaded`. Booting at
 * `after_setup_theme` means the Divi dependency guard sees Divi whether it
 * arrives as a theme (incl. white-labelled / renamed) or as the Divi Builder
 * plugin. This still runs well before `init` / `admin_menu`, so every module we
 * register lands in time.
 */
add_action(
	'after_setup_theme',
	static function () {
		require_once DFV_PLUGIN_DIR . 'includes/class-dfv-plugin.php';
		DFV_Plugin::instance();
	}
);
