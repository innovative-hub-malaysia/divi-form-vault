<?php
/**
 * Fired during plugin deactivation.
 *
 * Deactivating is safe and reversible: we never drop the submissions table or
 * delete any data here. Because the capture side is only ever a set of runtime
 * hooks, not loading them is enough to stop capturing. Data removal happens
 * only on uninstall, and only when the admin has opted in (see uninstall.php).
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Deactivator
 */
class DFV_Deactivator {

	/**
	 * Run on deactivation. Intentionally a no-op today; kept as the single,
	 * obvious home for any future cleanup that must stay non-destructive.
	 */
	public static function deactivate() {
		// No data is removed on deactivation by design.
	}
}
