<?php
/**
 * Divi Contact Form adapter (v1) - the only file that knows Divi's hooks.
 *
 * ====================== HOOK SIGNATURE - CONFIRMED =======================
 * Confirmed 2026-07-28 against the reference implementation (wp.org
 * "Contact Form DB Divi" v1.4.1, which rides the same hook and declares
 * Divi 4 + Divi 5 compatibility):
 *
 *   do_action( 'et_pb_contact_form_submit',
 *       $processed_fields_values, // array: field_id => [ 'label' => ..., 'value' => ... ]
 *       $et_contact_error,        // truthy when validation/captcha FAILED (fires either way)
 *       $contact_form_info        // array: contact_form_id / contact_form_unique_id ...
 *   );
 *
 * The adapter stays defensively written (arg-count tolerant, shape-tolerant)
 * so a future Divi drift degrades to a partial record, never a fatal. Final
 * sanity check = the first live submission on the target site (QA 2).
 * =========================================================================
 *
 * Divi submits its Contact Form as a normal POST back to the same page, so
 * cookies (our attribution) and $_POST (our honeypot) ride along natively.
 * The notification email remains entirely Divi's job; this adapter runs
 * regardless of whether that email succeeds - that is the resilience win.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Source_Divi
 */
class DFV_Source_Divi implements DFV_Source_Interface {

	/**
	 * Adapter key stored in the `source` column.
	 *
	 * @return string
	 */
	public function key() {
		return 'divi';
	}

	/**
	 * Attach the Divi submit hook. Late priority so Divi has fully processed
	 * the submission (and its own spam checks) before we read it.
	 */
	public function register() {
		add_action( 'et_pb_contact_form_submit', array( $this, 'on_submit' ), 20, 3 );
	}

	/**
	 * Handle a Divi Contact Form submission.
	 *
	 * @param mixed $processed_fields Field values (expected: field_id => [value,label]).
	 * @param mixed $contact_error    Expected bool: true when Divi rejected the submission.
	 * @param mixed $form_info        Expected array with contact_form_id / post_id etc.
	 */
	public function on_submit( $processed_fields = array(), $contact_error = null, $form_info = array() ) {
		// Divi fires this for failed validations too (TO-VERIFY) - a rejected
		// submission never reached the site owner, so we skip it. Truthy check
		// on purpose: whether Divi passes true or 1, we skip; if the installed
		// Divi fires only on success, this guard is simply never true.
		if ( $contact_error ) {
			return;
		}

		$fields = $this->normalise_fields( $processed_fields );

		// A submission with no readable fields is worthless noise - and the
		// most likely symptom of a signature drift. Store nothing rather than
		// an empty shell; the QA matrix checks this path on the real site.
		if ( empty( $fields ) ) {
			return;
		}

		$info    = is_array( $form_info ) ? $form_info : array();
		$post_id = isset( $info['post_id'] ) ? absint( $info['post_id'] ) : ( get_the_ID() ? (int) get_the_ID() : 0 );

		// Prefer the stable unique id (survives page edits); fall back to the
		// per-page index id.
		$form_id = '';
		foreach ( array( 'contact_form_unique_id', 'contact_form_id', 'contact_form_number' ) as $id_key ) {
			if ( ! empty( $info[ $id_key ] ) && is_scalar( $info[ $id_key ] ) ) {
				$form_id = (string) $info[ $id_key ];
				break;
			}
		}

		$form_name = '';
		foreach ( array( 'title', 'contact_form_title', 'form_title' ) as $name_key ) {
			if ( ! empty( $info[ $name_key ] ) && is_scalar( $info[ $name_key ] ) ) {
				$form_name = (string) $info[ $name_key ];
				break;
			}
		}

		// The submit URL: Divi posts back to the page itself, so the current
		// request URL is the page the form lives on.
		$page_url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw applied in the core.

		DFV_Capture::capture(
			array(
				'source'     => $this->key(),
				'form_id'    => $form_id,
				'form_name'  => $form_name,
				'page_id'    => $post_id,
				'page_url'   => $page_url,
				'page_title' => $post_id ? get_the_title( $post_id ) : '',
				'fields'     => $fields,
			)
		);
	}

	/**
	 * Normalise Divi's processed-fields structure into a flat key => value map.
	 *
	 * Tolerates three shapes (TO-VERIFY which one the installed Divi uses):
	 *   1. field_id => array( 'value' => ..., 'label' => ... )  (expected)
	 *   2. field_id => scalar value
	 *   3. anything else -> skipped
	 *
	 * When a label is present we key by a slug of the label (human-readable in
	 * the backend); the raw field id is kept as a suffix on collision.
	 *
	 * @param mixed $processed_fields Raw hook payload.
	 * @return array
	 */
	protected function normalise_fields( $processed_fields ) {
		if ( ! is_array( $processed_fields ) ) {
			return array();
		}

		$fields = array();
		foreach ( $processed_fields as $field_id => $payload ) {
			$key   = is_string( $field_id ) ? $field_id : 'field_' . (string) $field_id;
			$value = null;

			if ( is_array( $payload ) ) {
				if ( array_key_exists( 'value', $payload ) ) {
					$value = $payload['value'];
				}
				if ( ! empty( $payload['label'] ) && is_scalar( $payload['label'] ) ) {
					$label_key = sanitize_title( (string) $payload['label'] );
					if ( '' !== $label_key ) {
						$key = isset( $fields[ $label_key ] ) ? $label_key . '_' . $field_id : $label_key;
					}
				}
			} elseif ( is_scalar( $payload ) ) {
				$value = $payload;
			}

			if ( null === $value ) {
				continue;
			}

			$fields[ $key ] = is_array( $value ) ? array_map( 'strval', $value ) : (string) $value;
		}

		return $fields;
	}
}
