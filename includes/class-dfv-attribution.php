<?php
/**
 * Attribution - the SEO/Ads payoff.
 *
 * The front-end script (assets/js/dfv-attribution.js) keeps a first-party
 * cookie with first-touch + last-touch UTMs, the landing page, and the
 * referrer. Because Divi submits its Contact Form as a normal POST, that
 * cookie rides along and this class reads it at capture time via the
 * `dfv_capture_attribution` filter.
 *
 * Model (setting `attribution_model`):
 *   - 'last'  : the indexed utm_* columns carry the last touch.
 *   - 'first' : the columns carry the first touch.
 *   - 'both'  : the columns carry the LAST touch (what the backend shows by
 *               default) and the first touch is preserved inside the fields
 *               JSON as `_first_touch` - visible in the submission detail,
 *               no schema change needed.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Attribution
 */
class DFV_Attribution {

	const COOKIE = 'dfv_attr';

	/**
	 * Cookie-key => column-suffix map (compact keys keep the cookie small).
	 *
	 * @var array
	 */
	protected static $utm_map = array(
		's' => 'utm_source',
		'm' => 'utm_medium',
		'c' => 'utm_campaign',
		't' => 'utm_term',
		'n' => 'utm_content',
	);

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'dfv_capture_attribution', array( __CLASS__, 'attach' ), 10, 2 );
	}

	/**
	 * Enqueue the front-end capture script with its config.
	 */
	public static function enqueue() {
		wp_enqueue_script(
			'dfv-attribution',
			DFV_PLUGIN_URL . 'assets/js/dfv-attribution.js',
			array(),
			DFV_VERSION,
			true
		);

		$ga4_id = (string) DFV_Settings::get( 'ga4_measurement_id', '' );

		wp_localize_script(
			'dfv-attribution',
			'dfvConfig',
			array(
				'cookieDays' => (int) DFV_Settings::get( 'attribution_cookie_days', 90 ),
				'honeypot'   => (bool) DFV_Settings::get( 'honeypot_enabled', true ),
				'ga4'        => array(
					'enabled'       => (bool) DFV_Settings::get( 'ga4_enabled', true ),
					'measurementId' => $ga4_id,
				),
			)
		);

		// Load gtag.js ourselves ONLY when a Measurement ID is configured (the
		// site has no GTM container of its own). Same rule as our other IH
		// lead sources.
		if ( DFV_Settings::get( 'ga4_enabled' ) && '' !== $ga4_id ) {
			wp_enqueue_script(
				'dfv-gtag',
				'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ga4_id ),
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external, evergreen.
				false
			);
			wp_add_inline_script(
				'dfv-gtag',
				'window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag("js", new Date());gtag("config", ' . wp_json_encode( $ga4_id ) . ');'
			);
		}
	}

	/**
	 * Supply attribution columns to the capture core (dfv_capture_attribution).
	 *
	 * @param array $attribution Existing attribution (usually empty).
	 * @param array $submission  The normalised submission (unused here).
	 * @return array
	 */
	public static function attach( $attribution, $submission ) {
		$cookie = self::read_cookie();
		if ( empty( $cookie ) ) {
			return $attribution;
		}

		$model = (string) DFV_Settings::get( 'attribution_model', 'both' );

		$primary   = ( 'first' === $model ) ? 'f' : 'l'; // Columns carry last touch unless model=first.
		$touch     = isset( $cookie[ $primary ] ) && is_array( $cookie[ $primary ] ) ? $cookie[ $primary ] : array();
		$secondary = ( 'l' === $primary ) ? 'f' : 'l';

		foreach ( self::$utm_map as $short => $column ) {
			if ( ! empty( $touch[ $short ] ) && is_scalar( $touch[ $short ] ) ) {
				$attribution[ $column ] = substr( sanitize_text_field( (string) $touch[ $short ] ), 0, 190 );
			}
		}

		if ( ! empty( $cookie['lp'] ) && is_scalar( $cookie['lp'] ) ) {
			$attribution['landing_page'] = esc_url_raw( (string) $cookie['lp'] );
		}
		if ( ! empty( $cookie['r'] ) && is_scalar( $cookie['r'] ) ) {
			$attribution['referrer'] = esc_url_raw( (string) $cookie['r'] );
		}

		// Model 'both': preserve the other touch inside the fields JSON so the
		// detail view can show it. `_extra_fields` is merged into the record's
		// fields by the capture core (no schema change, core stays generic).
		if ( 'both' === $model && ! empty( $cookie[ $secondary ] ) && is_array( $cookie[ $secondary ] ) ) {
			$other = array();
			foreach ( self::$utm_map as $short => $column ) {
				if ( ! empty( $cookie[ $secondary ][ $short ] ) && is_scalar( $cookie[ $secondary ][ $short ] ) ) {
					$other[ $column ] = substr( sanitize_text_field( (string) $cookie[ $secondary ][ $short ] ), 0, 190 );
				}
			}
			if ( $other ) {
				$attribution['_extra_fields'] = array(
					( 'f' === $secondary ? '_first_touch' : '_last_touch' ) => wp_json_encode( $other ),
				);
			}
		}

		return $attribution;
	}

	/**
	 * Read + decode the attribution cookie.
	 *
	 * @return array
	 */
	protected static function read_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- first-party analytics cookie.
			return array();
		}
		$decoded = json_decode( wp_unslash( $_COOKIE[ self::COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values sanitised field-by-field in attach().
		return is_array( $decoded ) ? $decoded : array();
	}
}
