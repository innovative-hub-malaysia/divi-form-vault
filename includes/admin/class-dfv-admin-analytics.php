<?php
/**
 * Analytics - question-driven, actionable (redesigned 2026-07-28).
 *
 * Built around the four questions the site owner actually asks, in order:
 *
 *   1. "Are we getting leads, and is it trending up?"  -> KPI row w/ deltas
 *   2. "Is anything waiting on me?"                    -> Needs attention
 *   3. "Which pages / campaigns produce the leads?"    -> Where leads come from
 *   4. "When do they come / what kind?"                -> Flow + breakdowns
 *
 * Principles (same as the Quotes plugin's analytics): every number links to
 * the filtered Submissions list that proves it, deltas over raw counts,
 * one accent colour + semantic status colours, tabular numerals, inline-CSS
 * bars with direct labels, no chart libraries, all reads through the store
 * so figures always reconcile with the list.
 *
 * @package DiviFormVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class DFV_Admin_Analytics
 */
class DFV_Admin_Analytics {

	const CAP       = 'manage_options';
	const MENU_SLUG = 'dfv-analytics';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 15 );
	}

	/**
	 * Analytics submenu under Form Vault.
	 */
	public static function add_menu() {
		add_submenu_page(
			DFV_Admin_List::MENU_SLUG,
			__( 'Analytics', 'divi-form-vault' ),
			__( 'Analytics', 'divi-form-vault' ),
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the dashboard.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'divi-form-vault' ) );
		}

		$list_url = admin_url( 'admin.php?page=' . DFV_Admin_List::MENU_SLUG );
		$genuine  = array( 'is_spam' => 0 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Form Vault Analytics', 'divi-form-vault' ) . '</h1>';
		self::styles();
		echo '<div class="dfv-wrap">';

		// --- Empty state: nothing captured yet. ----------------------------.
		$all_time = DFV_Store::count( $genuine );
		$spam_all = DFV_Store::count( array( 'is_spam' => 1 ) );
		if ( 0 === $all_time && 0 === $spam_all ) {
			echo '<div class="dfv-card" style="max-width:640px;margin-top:16px"><p>' . esc_html__( 'No submissions captured yet. Once a Divi Contact Form is submitted (or the legacy import runs), this page fills in by itself.', 'divi-form-vault' ) . '</p></div></div></div>';
			return;
		}

		// --- Gather (all through the store; small indexed queries). --------.
		// Every day here is SITE-LOCAL (the WordPress timezone setting); the
		// store converts the range edges and buckets the trend the same way.
		$today  = self::local_day( 0 );
		$d30    = self::local_day( 29 );
		$d60    = self::local_day( 59 );
		$d30ago = self::local_day( 30 );

		$last30 = array_merge( $genuine, array( 'date_from' => $d30, 'date_to' => $today ) );

		$leads30 = DFV_Store::count( $last30 );
		$prev30  = DFV_Store::count( array_merge( $genuine, array( 'date_from' => $d60, 'date_to' => $d30ago ) ) );
		$unread  = DFV_Store::count( array( 'status' => 'new', 'is_spam' => 0 ) );
		$spam30  = DFV_Store::count( array( 'is_spam' => 1, 'date_from' => $d30, 'date_to' => $today ) );
		$daily   = DFV_Store::daily_counts( $last30 );

		// This week (last 7 days) vs the 7 before, from the same daily set.
		$week = 0;
		$prev_week = 0;
		for ( $i = 0; $i < 14; $i++ ) {
			$day   = self::local_day( $i );
			$count = isset( $daily[ $day ] ) ? $daily[ $day ] : 0;
			if ( $i < 7 ) {
				$week += $count;
			} else {
				$prev_week += $count;
			}
		}

		// Needs attention: unread genuine leads older than 3 days.
		$stale_cut  = self::local_day( 3 );
		$stale_args = array( 'status' => 'new', 'is_spam' => 0, 'date_to' => $stale_cut );
		$stale      = DFV_Store::query( array_merge( $stale_args, array( 'orderby' => 'submitted_at', 'order' => 'ASC', 'number' => 8 ) ) );
		$stale_n    = DFV_Store::count( $stale_args );

		// Where leads come from (all time - the durable answer).
		$top_pages = DFV_Store::group_count( 'page_id', $genuine, 8 );
		$by_source = DFV_Store::group_count( 'utm_source', $genuine, 8 );
		$by_camp   = DFV_Store::group_count( 'utm_campaign', $genuine, 8 );
		$by_form     = DFV_Store::group_count( 'form_name', $genuine, 6 );
		$by_form_col = 'form_name';
		if ( empty( $by_form ) || ( 1 === count( $by_form ) && isset( $by_form[''] ) ) ) {
			$by_form     = DFV_Store::group_count( 'form_id', $genuine, 6 );
			$by_form_col = 'form_id';
		}
		$by_device = DFV_Store::group_count( 'device', $genuine, 4 );

		// =====================================================================
		// 1. KPI row - "are we getting leads?"
		// =====================================================================
		$spam_rate = ( $spam30 + $leads30 ) > 0 ? round( $spam30 / ( $spam30 + $leads30 ) * 100 ) : 0;

		echo '<div class="dfv-tiles">';
		self::tile( number_format_i18n( $leads30 ), __( 'Leads - last 30 days', 'divi-form-vault' ), self::delta_text( $leads30 - $prev30, __( 'vs previous 30', 'divi-form-vault' ) ), $leads30 >= $prev30 );
		self::tile( number_format_i18n( $week ), __( 'This week', 'divi-form-vault' ), self::delta_text( $week - $prev_week, __( 'vs last week', 'divi-form-vault' ) ), $week >= $prev_week );
		self::tile( number_format_i18n( $unread ), __( 'Unread', 'divi-form-vault' ), $unread > 0 ? __( 'open the list', 'divi-form-vault' ) : __( 'all read', 'divi-form-vault' ), 0 === $unread, $list_url . '&spam=0' );
		self::tile( $spam_rate . '%', __( 'Spam rate - 30 days', 'divi-form-vault' ), sprintf( /* translators: %d: spam count. */ __( '%d flagged', 'divi-form-vault' ), $spam30 ), $spam_rate < 30, $list_url . '&spam=1' );
		self::tile( number_format_i18n( $all_time ), __( 'Leads - all time', 'divi-form-vault' ), '', true );
		echo '</div>';

		// =====================================================================
		// 2. Needs attention - "is anything waiting on me?"
		// =====================================================================
		if ( $stale_n > 0 ) {
			echo '<div class="dfv-card dfv-wide dfv-attn">';
			echo '<h2>' . esc_html( sprintf( /* translators: %d: count. */ __( 'Needs attention - %d unread leads older than 3 days', 'divi-form-vault' ), $stale_n ) ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Submitted', 'divi-form-vault' ) . '</th><th>' . esc_html__( 'Lead', 'divi-form-vault' ) . '</th><th>' . esc_html__( 'Page', 'divi-form-vault' ) . '</th></tr></thead><tbody>';
			foreach ( $stale as $row ) {
				$view = $list_url . '&view=' . (int) $row['id'];
				echo '<tr>';
				echo '<td style="white-space:nowrap"><a href="' . esc_url( $view ) . '"><strong>' . esc_html( DFV_Store::local_time( $row['submitted_at'], 'M j' ) ) . '</strong></a></td>';
				echo '<td>' . esc_html( self::lead_snippet( $row ) ) . '</td>';
				echo '<td>' . esc_html( '' !== $row['page_title'] ? $row['page_title'] : $row['page_url'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			if ( $stale_n > count( $stale ) ) {
				echo '<p><a href="' . esc_url( $list_url . '&spam=0' ) . '">' . esc_html__( 'See all in the Submissions list', 'divi-form-vault' ) . '</a></p>';
			}
			echo '</div>';
		} else {
			echo '<p class="dfv-allclear"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Nothing waiting - every lead older than 3 days has been read.', 'divi-form-vault' ) . '</p>';
		}

		// =====================================================================
		// 3. Where leads come from - the core question.
		// =====================================================================
		echo '<h2 class="dfv-h">' . esc_html__( 'Where leads come from', 'divi-form-vault' ) . ' <span class="dfv-sub">' . esc_html__( 'all time, genuine only', 'divi-form-vault' ) . '</span></h2>';
		echo '<div class="dfv-grid dfv-grid-3">';

		// Top converting pages (page_id-grouped so each row can link).
		echo '<div class="dfv-card">';
		echo '<h2>' . esc_html__( 'Top converting pages', 'divi-form-vault' ) . '</h2>';
		$pages = array();
		foreach ( $top_pages as $pid => $count ) {
			$pid   = (int) $pid;
			$label = $pid ? get_the_title( $pid ) : '';
			if ( '' === (string) $label ) {
				$label = $pid ? ( '#' . $pid ) : __( '(unknown page)', 'divi-form-vault' );
			}
			$pages[] = array(
				'label' => (string) $label,
				'count' => $count,
				'link'  => $pid ? ( $list_url . '&pg=' . $pid ) : '',
			);
		}
		self::bars( $pages, $all_time );
		echo '</div>';

		// Sources.
		echo '<div class="dfv-card">';
		echo '<h2>' . esc_html__( 'By source', 'divi-form-vault' ) . ' <span class="dfv-sub">utm_source</span></h2>';
		self::bars( self::rows_from( $by_source, $list_url, 'src' ), $all_time, __( 'No campaign-tagged leads yet - links with utm_source will show up here.', 'divi-form-vault' ) );
		echo '</div>';

		// Campaigns.
		echo '<div class="dfv-card">';
		echo '<h2>' . esc_html__( 'By campaign', 'divi-form-vault' ) . ' <span class="dfv-sub">utm_campaign</span></h2>';
		self::bars( self::rows_from( $by_camp, '', '' ), $all_time, __( 'No campaign-tagged leads yet.', 'divi-form-vault' ) );
		echo '</div>';

		echo '</div>';

		// =====================================================================
		// 4. Flow + breakdowns.
		// =====================================================================
		echo '<div class="dfv-card dfv-wide">';
		echo '<h2>' . esc_html__( 'Lead flow - last 30 days', 'divi-form-vault' ) . ' <span class="dfv-sub">' . esc_html( sprintf( /* translators: %d: leads. */ __( '%d genuine leads', 'divi-form-vault' ), $leads30 ) ) . '</span></h2>';
		if ( 0 === $leads30 ) {
			// Empty-data state: a row of zero-stubs reads as a broken chart.
			echo '<p class="dfv-sub" style="margin:18px 0 8px">' . esc_html__( 'No leads in the last 30 days. New submissions chart here day by day.', 'divi-form-vault' ) . '</p>';
		} else {
			$max = max( 1, $daily ? max( $daily ) : 1 );
			echo '<div class="dfv-trend">';
			for ( $i = 29; $i >= 0; $i-- ) {
				$day   = self::local_day( $i );
				$count = isset( $daily[ $day ] ) ? $daily[ $day ] : 0;
				$h     = max( 2, (int) round( $count / $max * 88 ) );
				echo '<span style="height:' . (int) $h . 'px"' . ( $count > 0 ? '' : ' class="dfv-zero"' ) . ' title="' . esc_attr( $day . ': ' . $count ) . '"></span>';
			}
			echo '</div>';
			echo '<div class="dfv-axis"><span>' . esc_html( self::local_day( 29, 'M j' ) ) . '</span><span>' . esc_html__( 'today', 'divi-form-vault' ) . '</span></div>';
		}
		echo '</div>';

		echo '<div class="dfv-grid dfv-grid-2">';
		echo '<div class="dfv-card">';
		echo '<h2>' . esc_html__( 'By form', 'divi-form-vault' ) . '</h2>';
		// Link only when grouped by form_id - the list's form filter matches
		// form_id, so linking form_name labels would filter to nothing.
		self::bars( self::rows_from( $by_form, 'form_id' === $by_form_col ? $list_url : '', 'form' ), $all_time, __( 'No form identity on these leads yet - live captures carry it.', 'divi-form-vault' ) );
		echo '</div>';
		echo '<div class="dfv-card">';
		echo '<h2>' . esc_html__( 'By device', 'divi-form-vault' ) . '</h2>';
		self::bars( self::rows_from( $by_device, '', '' ), $all_time );
		echo '</div>';
		echo '</div>';

		echo '<p class="dfv-foot">' . esc_html__( 'Genuine = not flagged as spam. Every figure reads the same store and filters as the Submissions list, so the two always reconcile - click any number to see its rows.', 'divi-form-vault' ) . '</p>';
		echo '</div></div>';
	}

	// =====================================================================
	// Pieces
	// =====================================================================

	/**
	 * A calendar day N days ago in the SITE timezone (the WordPress setting),
	 * as 'Y-m-d' by default. gmdate()/strtotime() ran on the server clock, so
	 * "today" flipped at 08:00 Kuala Lumpur and the trend bars were a day off.
	 *
	 * @param int    $days_ago 0 = today.
	 * @param string $format   PHP date format.
	 * @return string
	 */
	protected static function local_day( $days_ago, $format = 'Y-m-d' ) {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		return $now->modify( '-' . (int) $days_ago . ' days' )->format( $format );
	}

	/**
	 * Page styles (inline, no assets to enqueue for one screen).
	 */
	protected static function styles() {
		// One shared container width + CSS-grid rows: every row spans exactly
		// the same left/right edges, so nothing looks ragged. Rows collapse
		// gracefully on narrow screens via auto-fit minmax.
		echo '<style>
			.dfv-wrap{max-width:1160px}
			.dfv-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:16px 0}
			.dfv-tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px}
			.dfv-tile .n{font-size:28px;font-weight:600;line-height:1.2;font-variant-numeric:tabular-nums;color:#1d2327}
			.dfv-tile a{text-decoration:none}
			.dfv-tile a .n{color:#2271b1}
			.dfv-tile a:hover .n{text-decoration:underline}
			.dfv-tile .l{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin-top:2px}
			.dfv-tile .d{font-size:12px;margin-top:2px}
			.dfv-good{color:#2f7d5b}.dfv-warn{color:#b23b2e}
			.dfv-grid{display:grid;gap:16px;margin-bottom:16px;align-items:stretch}
			.dfv-grid-3{grid-template-columns:repeat(3,1fr)}
			.dfv-grid-2{grid-template-columns:repeat(2,1fr)}
			@media (max-width:1100px){.dfv-grid-3{grid-template-columns:1fr 1fr}}
			@media (max-width:782px){.dfv-grid-3,.dfv-grid-2{grid-template-columns:1fr}}
			.dfv-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;min-width:0}
			.dfv-wide{margin-bottom:16px}
			.dfv-card h2{margin:0 0 10px;font-size:14px}
			.dfv-h{margin:22px 0 10px;font-size:16px}
			.dfv-sub{color:#646970;font-weight:400;font-size:12px}
			.dfv-bar{display:flex;align-items:center;gap:8px;margin:5px 0;font-size:12px}
			.dfv-bar .t{flex:0 0 40%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.dfv-bar .b{background:#2271b1;height:14px;border-radius:3px;min-width:2px}
			.dfv-bar .c{font-variant-numeric:tabular-nums;color:#1d2327;white-space:nowrap;min-width:20px;text-align:right}
			.dfv-bar .pc{color:#646970;font-variant-numeric:tabular-nums;min-width:34px}
			.dfv-trend{display:flex;align-items:flex-end;gap:2px;height:90px;margin-top:8px;border-bottom:1px solid #e0e0e0;padding-bottom:1px}
			.dfv-trend span{flex:1;background:#2271b1;border-radius:2px 2px 0 0;min-height:2px}
			.dfv-trend span.dfv-zero{background:#e8e8e8}
			.dfv-axis{display:flex;justify-content:space-between;color:#8c8f94;font-size:11px;margin-top:4px;font-variant-numeric:tabular-nums}
			.dfv-attn{border-left:4px solid #b23b2e}
			.dfv-allclear{color:#2f7d5b;margin:6px 0 16px}
			.dfv-allclear .dashicons{vertical-align:middle}
			.dfv-foot{color:#646970;font-size:12px;margin-top:14px}
		</style>';
	}

	/**
	 * One KPI tile.
	 *
	 * @param string $number  Number text.
	 * @param string $label   Label.
	 * @param string $delta   Second line (delta / hint).
	 * @param bool   $is_good Whether the delta reads positive (green) or warning (red).
	 * @param string $link    Optional link on the number.
	 */
	protected static function tile( $number, $label, $delta, $is_good, $link = '' ) {
		echo '<div class="dfv-tile">';
		if ( $link ) {
			echo '<a href="' . esc_url( $link ) . '"><span class="n">' . esc_html( $number ) . '</span></a>';
		} else {
			echo '<span class="n">' . esc_html( $number ) . '</span>';
		}
		echo '<div class="l">' . esc_html( $label ) . '</div>';
		if ( '' !== $delta ) {
			echo '<div class="d ' . ( $is_good ? 'dfv-good' : 'dfv-warn' ) . '">' . esc_html( $delta ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * "+3 vs previous 30" delta line.
	 *
	 * @param int    $diff   Difference.
	 * @param string $suffix Comparison label.
	 * @return string
	 */
	protected static function delta_text( $diff, $suffix ) {
		return sprintf( '%+d %s', $diff, $suffix );
	}

	/**
	 * Map a group_count result to bar rows, optionally linking each value to
	 * the filtered list.
	 *
	 * @param array  $data     value => count.
	 * @param string $list_url Base list URL ('' = no links).
	 * @param string $param    Filter query param for the value.
	 * @return array[]
	 */
	protected static function rows_from( array $data, $list_url, $param ) {
		unset( $data[''] ); // Rank known values; unattributed rows are not a "source".
		$rows = array();
		foreach ( $data as $label => $count ) {
			$rows[] = array(
				'label' => (string) $label,
				'count' => (int) $count,
				'link'  => ( $list_url && $param ) ? ( $list_url . '&' . $param . '=' . rawurlencode( (string) $label ) ) : '',
			);
		}
		return $rows;
	}

	/**
	 * Ranked bars with count + share of all genuine leads.
	 *
	 * @param array  $rows  [{label, count, link}].
	 * @param int    $total Total for the share %.
	 * @param string $empty Empty-state line.
	 */
	protected static function bars( array $rows, $total, $empty = '' ) {
		if ( empty( $rows ) ) {
			echo '<p class="dfv-sub">' . esc_html( '' !== $empty ? $empty : __( 'No data yet.', 'divi-form-vault' ) ) . '</p>';
			return;
		}
		$max = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['count'] );
		}
		foreach ( $rows as $row ) {
			$w     = max( 2, (int) round( $row['count'] / max( 1, $max ) * 100 ) );
			$share = $total > 0 ? round( $row['count'] / $total * 100 ) : 0;
			echo '<div class="dfv-bar">';
			if ( ! empty( $row['link'] ) ) {
				echo '<a class="t" href="' . esc_url( $row['link'] ) . '" title="' . esc_attr( $row['label'] ) . '">' . esc_html( $row['label'] ) . '</a>';
			} else {
				echo '<span class="t" title="' . esc_attr( $row['label'] ) . '">' . esc_html( $row['label'] ) . '</span>';
			}
			echo '<span class="b" style="width:' . (int) $w . '%"></span>';
			echo '<span class="c">' . (int) $row['count'] . '</span> <span class="pc">' . (int) $share . '%</span>';
			echo '</div>';
		}
	}

	/**
	 * First one-two human field values of a row.
	 *
	 * @param array $row Decoded row.
	 * @return string
	 */
	protected static function lead_snippet( $row ) {
		$parts = array();
		foreach ( (array) $row['fields'] as $key => $value ) {
			if ( '_' === substr( (string) $key, 0, 1 ) || ! is_scalar( $value ) ) {
				continue;
			}
			$text = trim( (string) $value );
			if ( '' === $text ) {
				continue;
			}
			$parts[] = $text;
			if ( count( $parts ) >= 2 ) {
				break;
			}
		}
		$snippet = implode( ' - ', $parts );
		return strlen( $snippet ) > 70 ? substr( $snippet, 0, 67 ) . '...' : $snippet;
	}
}
