<?php
/**
 * GA4 forwarding - one unified `generate_lead` schema across IH lead sources.
 *
 * On a captured GENUINE submission we hand an event payload to the front end,
 * which pushes GA4 `generate_lead` (see assets/js/dfv-attribution.js). Param
 * names are kept identical to the Request-a-Quote plugin (`lead_source`,
 * `method`, + our attribution params) so GA4 reporting reads all IH lead
 * sources uniformly as one Key Event.
 *
 * Handoff mechanics - two channels, both one-shot:
 *   1. SAME-PAGE (primary): Divi processes its Contact Form mid-render, so by
 *      capture time HTTP headers are usually already sent and setcookie()
 *      would be lost. Instead we attach an inline `window.dfvLeadEvent`
 *      variable to our footer script - the post-submit page itself pushes the
 *      event. No reload double-fire: a JS var dies with the page.
 *   2. COOKIE (fallback): when headers are NOT yet sent (redirect flows, a
 *      future AJAX-submitting Divi), a short-lived `dfv_lead_evt` cookie
 *      carries the payload to the next page view, where the script reads it,
 *      fires, and deletes it.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_GA4
 */
class DFV_GA4 {

	const EVENT_COOKIE = 'dfv_lead_evt';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'dfv_submission_captured', array( __CLASS__, 'on_captured' ), 10, 2 );
	}

	/**
	 * Hand the event payload to the front end after a genuine capture.
	 *
	 * @param int   $id  Row id.
	 * @param array $row The stored row.
	 */
	public static function on_captured( $id, $row ) {
		if ( ! DFV_Settings::get( 'ga4_enabled' ) ) {
			return;
		}
		if ( ! empty( $row['is_spam'] ) ) {
			return; // Spam never converts.
		}

		$params = self::build_params( $row );

		// Channel 1: same-page inline var (survives the mid-render capture).
		wp_add_inline_script(
			'dfv-attribution',
			'window.dfvLeadEvent = ' . wp_json_encode( $params ) . ';',
			'before'
		);

		// Channel 2: cookie fallback when headers are still open.
		if ( ! headers_sent() ) {
			setcookie(
				self::EVENT_COOKIE,
				wp_json_encode( $params ),
				array(
					'expires'  => time() + 300,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => false, // The front-end script must read it.
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Build the generate_lead params from a stored row.
	 *
	 * @param array $row The stored row.
	 * @return array
	 */
	public static function build_params( $row ) {
		$params = array(
			'lead_source' => 'divi_form_vault',
			'method'      => 'contact_form',
		);

		if ( ! empty( $row['form_id'] ) ) {
			$params['form_id'] = (string) $row['form_id'];
		}
		if ( ! empty( $row['form_name'] ) ) {
			$params['form_name'] = (string) $row['form_name'];
		}
		if ( ! empty( $row['page_url'] ) ) {
			$path = wp_parse_url( (string) $row['page_url'], PHP_URL_PATH );
			if ( $path ) {
				$params['page_path'] = (string) $path;
			}
		}
		if ( ! empty( $row['page_title'] ) ) {
			$params['page_title'] = (string) $row['page_title'];
		}
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'device' ) as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				$params[ $key ] = (string) $row[ $key ];
			}
		}

		/**
		 * Adjust the generate_lead params before they are handed to the page.
		 *
		 * @param array $params Event params.
		 * @param array $row    The stored row.
		 */
		return (array) apply_filters( 'dfv_ga4_params', $params, $row );
	}
}
