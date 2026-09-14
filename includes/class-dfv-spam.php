<?php
/**
 * Spam flagging - keep the lead numbers honest.
 *
 * Flags, never blocks: a spam-flagged submission is still stored (visible and
 * filterable in the backend, re-flaggable by the admin) but excluded from the
 * genuine-lead figures and from the GA4 event. We never interfere with Divi's
 * own submission handling.
 *
 * Signals, in order of certainty:
 *   1. HONEYPOT - the front-end script injects a hidden `dfv_hp_website`
 *      input into every Divi Contact Form (client-side only; Divi's markup is
 *      untouched on the server). Humans never see it; a bot that blind-fills
 *      the form does. Non-empty => spam.
 *   2. DIVI'S OWN SIGNAL - Divi validates its basic captcha / spam services
 *      BEFORE the submit hook fires, so a captcha-failing submission never
 *      reaches us at all (the adapter also skips error-flagged fires). That
 *      protection is implicit; the toggle exists so a future Divi version
 *      that exposes an explicit result can be honoured here. TO-VERIFY on
 *      the installed Divi.
 *   3. HEURISTICS - cheap tells (link stuffing, bbcode, no letters) for the
 *      bots that pass 1 and 2.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Spam
 */
class DFV_Spam {

	const HONEYPOT_FIELD = 'dfv_hp_website';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_filter( 'dfv_capture_is_spam', array( __CLASS__, 'judge' ), 10, 3 );
	}

	/**
	 * Decide whether a submission is spam (dfv_capture_is_spam).
	 *
	 * @param bool  $is_spam    Current verdict.
	 * @param array $row        The row about to be stored.
	 * @param array $submission The normalised submission.
	 * @return bool
	 */
	public static function judge( $is_spam, $row, $submission ) {
		if ( $is_spam ) {
			return true;
		}

		// 1. Honeypot.
		if ( DFV_Settings::get( 'honeypot_enabled' ) && self::honeypot_filled() ) {
			return true;
		}

		// 2. Divi's own signal: implicit - captcha-failing submissions never
		// reach the hook at all, so there is nothing to read (and no setting).

		// 3. Heuristics.
		if ( DFV_Settings::get( 'spam_heuristics' ) && self::looks_spammy( isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Was the injected honeypot field filled?
	 *
	 * Divi posts the form back with all inputs, ours included. A human never
	 * sees the field; any value in it means a bot.
	 *
	 * @return bool
	 */
	protected static function honeypot_filled() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading a bot tell on Divi's own POST; no state change here.
		return isset( $_POST[ self::HONEYPOT_FIELD ] ) && '' !== trim( (string) wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) );
	}

	/**
	 * Cheap content heuristics over the combined field text.
	 *
	 * Deliberately conservative: a false "genuine" costs a moment of reading;
	 * a false "spam" hides a real lead. Only high-confidence tells flag.
	 *
	 * @param array $fields Field map.
	 * @return bool
	 */
	protected static function looks_spammy( array $fields ) {
		$text = '';
		foreach ( $fields as $key => $value ) {
			if ( '_' === substr( (string) $key, 0, 1 ) ) {
				continue; // Internal keys (_first_touch etc).
			}
			$text .= ' ' . ( is_array( $value ) ? implode( ' ', $value ) : (string) $value );
		}
		$text = trim( $text );
		if ( '' === $text ) {
			return false;
		}

		// Link stuffing: 3+ URLs across the submission.
		if ( preg_match_all( '#https?://#i', $text ) >= 3 ) {
			return true;
		}

		// BBCode links: only bots post [url=...] into a plain contact form.
		if ( false !== stripos( $text, '[url=' ) || false !== stripos( $text, '[link=' ) ) {
			return true;
		}

		// No letters at all in a non-trivial payload (pure symbol/number blast).
		if ( strlen( $text ) > 40 && ! preg_match( '/\p{L}/u', $text ) ) {
			return true;
		}

		return (bool) apply_filters( 'dfv_spam_heuristics_verdict', false, $text, $fields );
	}
}
