<?php
/**
 * Central settings store.
 *
 * The whole plugin reads its config from ONE option (`dfv_settings`, an array).
 * Sane defaults are baked in so a brand-new client works out of the box and
 * nothing is client-specific in code - each client is just configuration.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Settings
 */
class DFV_Settings {

	const OPTION_KEY = 'dfv_settings';

	/**
	 * Cached settings for the request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Default settings. The single source of truth for shipped defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// --- Attribution ---------------------------------------------.
			// Store both first- and last-touch; the backend shows last-touch by
			// default (recommended model). Cookie lifetime bounds the session.
			'attribution_model'        => 'both', // last | first | both.
			'attribution_cookie_days'  => 90,

			// --- Spam ----------------------------------------------------.
			// (Divi's own captcha already blocks before our hook fires, so
			// there is no separate "Divi signal" setting - that protection is
			// implicit and free.)
			'honeypot_enabled'         => true,
			'spam_heuristics'          => true, // Simple server-side heuristics.

			// --- Analytics / GA4 -----------------------------------------.
			'ga4_enabled'              => true,
			'ga4_measurement_id'       => '', // Used only when no GTM dataLayer is present.

			// --- Privacy (PDPA) ------------------------------------------.
			// We store these by default because attribution/anti-spam use them.
			// Deletion is always manual (tools ship in a later stage); nothing
			// is ever auto-expired.
			'store_ip'                 => true,
			'store_user_agent'         => true,

			// --- Advanced ------------------------------------------------.
			'purge_on_uninstall'       => false, // Non-destructive default: keep data on delete.
		);
	}

	/**
	 * Get all settings (defaults merged with saved values).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION_KEY, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Persist a full settings array (already sanitised by the caller).
	 *
	 * @param array $settings Settings to save.
	 */
	public static function save( array $settings ) {
		update_option( self::OPTION_KEY, $settings );
		self::$cache = null;
	}

	/**
	 * Seed defaults only if the option does not exist yet.
	 */
	public static function maybe_seed_defaults() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}
}
