<?php
/**
 * The adapter contract - what a form source must implement.
 *
 * The capture core is builder-agnostic. Each form source (Divi today,
 * Elementor or a form plugin tomorrow) is one adapter implementing this
 * interface. An adapter's whole job is:
 *
 *   1. know its builder's submit hook(s) and attach to them, and
 *   2. normalise the builder-specific payload into the generic submission
 *      shape, then hand it to DFV_Capture::capture().
 *
 * The generic submission shape (all keys optional except `source`):
 *
 *   array(
 *     'source'     => 'divi',            // the adapter key
 *     'form_id'    => 'et_pb_contact_form_0-abc123',
 *     'form_name'  => 'Contact us',
 *     'page_id'    => 42,
 *     'page_url'   => 'https://site.com/contact/',
 *     'page_title' => 'Contact',
 *     'fields'     => array( 'name' => 'Jane', 'email' => 'jane@x.com', ... ),
 *   )
 *
 * Attribution, device, IP, spam flagging, and GA4 are the CORE's job - an
 * adapter never deals with them. Adding a new builder must never require a
 * core change; if it does, the core is wrong.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

interface DFV_Source_Interface {

	/**
	 * The adapter key stored in the `source` column (e.g. 'divi').
	 *
	 * @return string
	 */
	public function key();

	/**
	 * Attach the builder-specific submit hook(s). Called once at boot, only
	 * when the builder is present.
	 */
	public function register();
}
