<?php
/**
 * Uninstall handler.
 *
 * Runs only when the user deletes the plugin from wp-admin. Respects the
 * "Delete all data on uninstall" setting: by default we KEEP the submissions
 * table and options (safe, non-destructive - a lead history is valuable and
 * PDPA deletion is meant to be a deliberate act). Only when the admin has
 * explicitly opted in do we drop the table and remove options.
 *
 * @package DiviFormVault
 */

// Exit if not called by WordPress uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dfv_settings     = get_option( 'dfv_settings', array() );
$dfv_should_purge = is_array( $dfv_settings ) && ! empty( $dfv_settings['purge_on_uninstall'] );

if ( ! $dfv_should_purge ) {
	return;
}

// Drop our custom table.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-dfv-install.php';
DFV_Install::drop_table();

// Remove our options.
delete_option( 'dfv_settings' );
delete_option( 'dfv_version' );
delete_option( 'dfv_db_version' );
delete_option( 'dfv_install_error' );
delete_option( 'dfv_import_state' );
delete_option( 'dfv_onboarding_pending' );
delete_transient( 'dfv_import_result' );
