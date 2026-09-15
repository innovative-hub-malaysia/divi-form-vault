<?php
/**
 * Updates.
 *
 * This plugin is distributed by Innovative Hub from GitHub, NOT hosted on
 * wordpress.org. The bundled plugin-update-checker library reads the GitHub
 * Releases of the repository below and offers a new version through the
 * normal WordPress update flow (Plugins screen notice + one-click update). It
 * owns the update-transient entry and the "View details" popup for our slug
 * and excludes the plugin from the wp.org update request, so a same-named
 * wordpress.org plugin can never be offered in its place.
 *
 * Auto-updates are ON by default so every site picks up a Release on its own.
 * Opt out per site with the Updates setting (Settings tab), or hard-disable
 * with DFV_DISABLE_AUTO_UPDATE = true in wp-config.php (wins over the setting).
 *
 * Releasing: bump the Version header + DFV_VERSION, tag `vX.Y.Z`, and publish a
 * GitHub Release with the built zip attached (bin/build-release.sh does it).
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Class DFV_Updates
 */
class DFV_Updates {

	/**
	 * Public GitHub repository the updates are served from.
	 */
	const REPO_URL = 'https://github.com/innovative-hub-malaysia/divi-form-vault/';

	/**
	 * Hook registration (runs on every load, independent of Divi).
	 */
	public static function init() {
		require_once DFV_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker( self::REPO_URL, DFV_PLUGIN_FILE, 'divi-form-vault' );
		// Install the zip attached to the Release, never GitHub's source archive
		// (its top folder carries the tag name and would install as a new plugin).
		$checker->getVcsApi()->enableReleaseAssets();

		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
	}

	/**
	 * Auto-update this plugin unless the site opted out.
	 *
	 * Two opt-outs, in order: the wp-config constant (an ops override that no
	 * admin can flip back from the UI) wins when defined; otherwise the
	 * `auto_update` setting on the Settings tab (default on).
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		if ( ! is_object( $item ) || ! isset( $item->plugin ) || DFV_PLUGIN_BASENAME !== $item->plugin ) {
			return $update;
		}
		if ( defined( 'DFV_DISABLE_AUTO_UPDATE' ) ) {
			return ! DFV_DISABLE_AUTO_UPDATE;
		}
		// Read the option directly rather than through DFV_Settings: this class
		// loads before the plugin core, and a fallback of "true" when the core
		// is not booted would silently ignore a site that switched updates off.
		$saved = get_option( 'dfv_settings', array() );
		return ! ( is_array( $saved ) && array_key_exists( 'auto_update', $saved ) && ! $saved['auto_update'] );
	}
}
