<?php
/**
 * The generic capture core.
 *
 * Source-agnostic: adapters hand it a normalised submission; it enriches the
 * record (attribution, device, IP, user), runs spam checks, writes through the
 * store, and announces the capture. Everything builder-specific lives in the
 * adapters; everything lead-specific lives here.
 *
 * Extension points (used internally by our own modules too, so a future
 * adapter or site-specific tweak composes the same way):
 *
 *   - filter `dfv_capture_submission` : mutate the normalised submission early.
 *   - filter `dfv_capture_attribution`: supply attribution columns (Stage 3).
 *   - filter `dfv_capture_is_spam`    : return true to flag the row as spam (Stage 4).
 *   - action `dfv_submission_captured`: fires with ($id, $row) after insert (GA4 rides this).
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Capture
 */
class DFV_Capture {

	/**
	 * Capture one normalised submission.
	 *
	 * Resilience rule: this runs independently of the builder's own email
	 * notification. Whatever the mailer does, if this method is reached the
	 * record is written.
	 *
	 * @param array $submission Generic submission shape (see the interface doc).
	 * @return int|false Inserted row id, or false when nothing was stored.
	 */
	public static function capture( array $submission ) {
		$submission = apply_filters( 'dfv_capture_submission', $submission );
		if ( empty( $submission ) || ! is_array( $submission ) || empty( $submission['source'] ) ) {
			return false;
		}

		$row = array(
			'source'     => sanitize_key( $submission['source'] ),
			'form_id'    => isset( $submission['form_id'] ) ? sanitize_text_field( (string) $submission['form_id'] ) : '',
			'form_name'  => isset( $submission['form_name'] ) ? sanitize_text_field( (string) $submission['form_name'] ) : '',
			'page_id'    => isset( $submission['page_id'] ) ? absint( $submission['page_id'] ) : 0,
			'page_url'   => isset( $submission['page_url'] ) ? esc_url_raw( (string) $submission['page_url'] ) : '',
			'page_title' => isset( $submission['page_title'] ) ? sanitize_text_field( (string) $submission['page_title'] ) : '',
			'fields'     => self::sanitize_fields( isset( $submission['fields'] ) ? $submission['fields'] : array() ),
			'user_id'    => get_current_user_id(),
		);

		// --- Attribution (Stage 3 supplies this via the filter). -----------.
		$attribution = apply_filters( 'dfv_capture_attribution', array(), $submission );
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $utm ) {
			if ( ! empty( $attribution[ $utm ] ) ) {
				$row[ $utm ] = sanitize_text_field( (string) $attribution[ $utm ] );
			}
		}
		if ( ! empty( $attribution['landing_page'] ) ) {
			$row['landing_page'] = esc_url_raw( (string) $attribution['landing_page'] );
		}
		if ( ! empty( $attribution['referrer'] ) ) {
			$row['referrer'] = esc_url_raw( (string) $attribution['referrer'] );
		}
		// An attribution supplier may hand back extra field entries to persist
		// inside the fields JSON (e.g. the secondary touch under model 'both').
		if ( ! empty( $attribution['_extra_fields'] ) && is_array( $attribution['_extra_fields'] ) ) {
			$row['fields'] = array_merge( $row['fields'], self::sanitize_fields( $attribution['_extra_fields'] ) );
		}

		// --- Device / UA / IP, honouring the privacy settings. -------------.
		$ua            = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$row['device'] = self::device_from_ua( $ua );
		if ( DFV_Settings::get( 'store_user_agent' ) ) {
			$row['user_agent'] = $ua;
		}
		if ( DFV_Settings::get( 'store_ip' ) ) {
			$ip = self::client_ip();
			if ( '' !== $ip ) {
				$row['ip'] = $ip; // Packed to binary by the store.
			}
		}

		// --- Spam (Stage 4 decides via the filter). -------------------------.
		$row['is_spam'] = apply_filters( 'dfv_capture_is_spam', false, $row, $submission ) ? 1 : 0;

		$id = DFV_Store::insert( $row );
		if ( false === $id ) {
			return false;
		}

		/**
		 * A submission was captured and stored.
		 *
		 * @param int   $id  The new row id.
		 * @param array $row The stored row (pre-decode shape).
		 */
		do_action( 'dfv_submission_captured', $id, $row );

		return $id;
	}

	/**
	 * Sanitise the arbitrary form-field map: flat key => scalar/array-of-scalar,
	 * keys slugged, values as text.
	 *
	 * Size caps (DoS guard - a bot can POST megabytes into a public form):
	 * at most 100 fields, each value truncated to 10000 chars. Both bounds sit
	 * far above any real contact form and are filterable for an edge case.
	 *
	 * @param mixed $fields Raw fields from the adapter.
	 * @return array
	 */
	protected static function sanitize_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$max_fields = (int) apply_filters( 'dfv_max_fields', 100 );
		$max_length = (int) apply_filters( 'dfv_max_field_length', 10000 );

		$clean = array();
		foreach ( $fields as $key => $value ) {
			if ( count( $clean ) >= $max_fields ) {
				break;
			}
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $key ] = array_map(
					static function ( $v ) use ( $max_length ) {
						return substr( sanitize_textarea_field( (string) $v ), 0, $max_length );
					},
					array_slice( $value, 0, 20 )
				);
			} else {
				$clean[ $key ] = substr( sanitize_textarea_field( (string) $value ), 0, $max_length );
			}
		}
		return $clean;
	}

	/**
	 * Coarse device class from the user agent: desktop / mobile / tablet.
	 * Deliberately simple - it feeds an analytics breakdown, not billing.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function device_from_ua( $ua ) {
		if ( '' === $ua ) {
			return '';
		}
		$ua_l = strtolower( $ua );
		if ( false !== strpos( $ua_l, 'ipad' ) || ( false !== strpos( $ua_l, 'tablet' ) ) || ( false !== strpos( $ua_l, 'android' ) && false === strpos( $ua_l, 'mobile' ) ) ) {
			return 'tablet';
		}
		if ( false !== strpos( $ua_l, 'mobi' ) || false !== strpos( $ua_l, 'iphone' ) || false !== strpos( $ua_l, 'android' ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Best-effort client IP. REMOTE_ADDR by default; a proxy/CDN site can
	 * correct it via the `dfv_client_ip` filter (we do not trust
	 * X-Forwarded-For blindly - it is client-controlled).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'dfv_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
