<?php
/**
 * Noise cleanup: background scan and delete of past bot, monitor, non-page, repeat and staff visits.
 *
 * @package Utm_Attribution_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans stored visits with the live classifiers and deletes the noise through Action Scheduler.
 * Visits linked to a conversion or referenced by any order are never deleted.
 */
class Utm_Attribution_Cleanup {

	const OPTION     = 'utm_attribution_cleanup';
	const LOCK       = 'utm_attribution_cleanup_lock';
	const CANCEL     = 'utm_attribution_cleanup_cancel';
	const HOOK       = 'utm_attribution_cleanup_batch';
	const GROUP      = 'utm-attribution';
	const NONCE      = 'utm_attribution_cleanup';
	const META       = 'utm_attribution_cleanup_notice_dismissed';
	const SCAN_BATCH = 5000;
	const DEL_BATCH  = 1000;
	const LOCK_STALE = 10 * MINUTE_IN_SECONDS;
	const SCAN_VALID = 30 * MINUTE_IN_SECONDS;
	const CATEGORIES = array( 'bot', 'monitor', 'non_page', 'repeat', 'staff' );

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run_batch' ) );

		if ( is_admin() ) {
			add_action( 'admin_post_utm_attribution_cleanup_start', array( $this, 'handle_start' ) );
			add_action( 'admin_post_utm_attribution_cleanup_cancel', array( $this, 'handle_cancel' ) );
			add_action( 'wp_ajax_utm_attribution_cleanup_status', array( $this, 'ajax_status' ) );
			add_action( 'wp_ajax_utm_attribution_cleanup_dismiss', array( $this, 'ajax_dismiss_notice' ) );
			add_action( 'admin_notices', array( $this, 'upgrade_notice' ) );
		}
	}

	/**
	 * @return string
	 */
	public static function capability() {
		return apply_filters( 'utm_attribution_user_capability', 'manage_options' );
	}

	/**
	 * Category labels, in display order.
	 *
	 * @return string[]
	 */
	public static function labels() {
		return array(
			'bot'      => __( 'Bots & crawlers', 'utm-attribution-for-woocommerce' ),
			'monitor'  => __( 'Uptime monitors', 'utm-attribution-for-woocommerce' ),
			'non_page' => __( 'Non-page URLs', 'utm-attribution-for-woocommerce' ),
			'repeat'   => __( 'Repeat visits without cookies', 'utm-attribution-for-woocommerce' ),
			'staff'    => __( 'Staff visits', 'utm-attribution-for-woocommerce' ),
		);
	}

	/* ---------------------------------------------------------------- State */

	private static function state() {
		// Batches run in cron while Cancel is a web request, so never trust a cached copy.
		wp_cache_delete( self::OPTION, 'options' );
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Whether this job is still the current, running one (not cancelled or replaced since the batch began).
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	private static function is_running( $job_id ) {
		$state = self::state();

		return isset( $state['job'] ) && $state['job']['id'] === $job_id && 'running' === $state['job']['status'] && ! self::is_cancelled( $job_id );
	}

	/**
	 * Cancel is recorded in its own option, which batches never write, so an in-flight batch cannot overwrite it.
	 *
	 * @param string $job_id Job ID.
	 * @return bool
	 */
	private static function is_cancelled( $job_id ) {
		wp_cache_delete( self::CANCEL, 'options' );

		return get_option( self::CANCEL ) === $job_id;
	}

	private static function flag_cancelled( $state ) {
		if ( isset( $state['job'] ) ) {
			update_option( self::CANCEL, $state['job']['id'], false );
		}
	}

	private static function save( $state ) {
		update_option( self::OPTION, $state, false );
	}

	private static function acquire_lock() {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}

		if ( (int) get_option( self::LOCK ) < time() - self::LOCK_STALE ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', false );
		}

		return false;
	}

	/**
	 * Stop pending batches and release the lock (deactivation, cancel).
	 */
	public static function unschedule() {
		$state = self::state();
		if ( isset( $state['job'] ) && 'running' === $state['job']['status'] ) {
			self::flag_cancelled( $state );
			$state['job']['status'] = 'cancelled';
			unset( $state['job']['anchors'], $state['job']['uas'] );
			self::save( $state );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		delete_option( self::LOCK );
	}

	/**
	 * The last scan when it can still be deleted: same user, fresh, same classifier rules.
	 *
	 * @return array|null
	 */
	public static function valid_scan() {
		$scan = isset( self::state()['last_scan'] ) ? self::state()['last_scan'] : null;

		if ( ! $scan || (int) $scan['user_id'] !== get_current_user_id() || $scan['finished_at'] < time() - self::SCAN_VALID || Utm_Attribution_Capture::rules_hash() !== $scan['settings_hash'] ) {
			return null;
		}

		return $scan;
	}

	/**
	 * Data for the Tools tab.
	 *
	 * @return array
	 */
	public static function view_data() {
		$state = self::state();
		$job   = isset( $state['job'] ) ? $state['job'] : null;

		return array(
			'job'      => $job && 'running' === $job['status'] && ! self::is_cancelled( $job['id'] ) ? $job : null,
			'scan'     => self::valid_scan(),
			'last_run' => isset( $state['last_run'] ) ? $state['last_run'] : null,
			'stale'    => isset( $state['last_scan'] ) && ! self::valid_scan(),
		);
	}

	/* ------------------------------------------------------------ Start/stop */

	public function handle_start() {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'utm-attribution-for-woocommerce' ), 403 );
		}

		$mode = isset( $_POST['mode'] ) && 'delete' === $_POST['mode'] ? 'delete' : 'scan';
		$from = '';
		$to   = '';

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			self::back( 'unavailable' );
		}

		if ( 'delete' === $mode ) {
			$scan = self::valid_scan();
			if ( ! $scan ) {
				self::back( 'rescan' );
			}
			if ( empty( $_POST['confirm'] ) ) {
				self::back( 'confirm' );
			}
			$from = $scan['from'];
			$to   = $scan['to'];
		} else {
			$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
			$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
				$from = '';
				$to   = '';
			} elseif ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
		}

		if ( ! self::acquire_lock() ) {
			self::back( 'locked' );
		}

		global $wpdb;
		$max_id = (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM {$wpdb->prefix}utm_attribution_visits" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		list( $start, $end ) = '' === $from ? array( '', '' ) : Utm_Attribution_Reports::utc_bounds( $from, $to );

		$state        = self::state();
		$state['job'] = array(
			'id'            => wp_generate_password( 12, false ),
			'mode'          => $mode,
			'status'        => 'running',
			'user_id'       => get_current_user_id(),
			'from'          => $from,
			'to'            => $to,
			'start'         => $start,
			'end'           => $end,
			'max_id'        => $max_id,
			'cursor'        => 0,
			'settings_hash' => Utm_Attribution_Capture::rules_hash(),
			'anchors'       => array(),
			'counts'        => array(),
			'uas'           => array(),
			'deleted'       => 0,
			'heartbeat'     => time(),
		);
		self::save( $state );

		as_enqueue_async_action( self::HOOK, array( 'job' => $state['job']['id'] ), self::GROUP );

		self::back( 'started' );
	}

	public function handle_cancel() {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'utm-attribution-for-woocommerce' ), 403 );
		}

		$state = self::state();
		self::flag_cancelled( $state );
		if ( isset( $state['job'] ) ) {
			$state['job']['status'] = 'cancelled';
			unset( $state['job']['anchors'], $state['job']['uas'] );
			self::save( $state );
		}
		self::unschedule();

		self::back( 'cancelled' );
	}

	private static function back( $notice ) {
		wp_safe_redirect( add_query_arg( 'utm_notice', $notice, admin_url( 'admin.php?page=utm-attribution-settings&tab=tools' ) ) );
		exit;
	}

	public function ajax_status() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		$job = isset( self::state()['job'] ) ? self::state()['job'] : array();

		wp_send_json_success(
			array(
				'status'  => isset( $job['status'] ) ? ( self::is_cancelled( $job['id'] ) ? 'cancelled' : $job['status'] ) : 'idle',
				'percent' => ! empty( $job['max_id'] ) ? min( 100, (int) floor( $job['cursor'] / $job['max_id'] * 100 ) ) : 100,
				'idle'    => isset( $job['heartbeat'] ) ? time() - $job['heartbeat'] : 0,
			)
		);
	}

	/* --------------------------------------------------------------- Engine */

	/**
	 * Action Scheduler callback: one keyset batch of the running job.
	 *
	 * @param string $job_id Job ID the batch was queued for.
	 */
	public function run_batch( $job_id ) {
		global $wpdb;

		$state = self::state();
		$job   = isset( $state['job'] ) ? $state['job'] : null;

		if ( ! $job || $job['id'] !== $job_id || 'running' !== $job['status'] ) {
			return;
		}

		// Cancelled (possibly overwritten by a batch that was already running), or WooCommerce gone: stop for good.
		if ( self::is_cancelled( $job_id ) || ! class_exists( 'WooCommerce' ) ) {
			$state['job']['status'] = 'cancelled';
			unset( $state['job']['anchors'], $state['job']['uas'] );
			self::save( $state );
			delete_option( self::LOCK );
			return;
		}

		$is_delete = 'delete' === $job['mode'];

		// The admin previewed one set of rules; a delete must not run on different ones.
		if ( $is_delete && Utm_Attribution_Capture::rules_hash() !== $job['settings_hash'] ) {
			$state['job']['status'] = 'cancelled';
			unset( $state['job']['anchors'], $state['job']['uas'] );
			self::save( $state );
			delete_option( self::LOCK );
			return;
		}

		$rows = self::fetch_rows( $job['cursor'], $job['max_id'], $job['start'], $job['end'], $is_delete ? self::DEL_BATCH : self::SCAN_BATCH );
		$ctx  = array(
			'anchors' => $job['anchors'],
			'users'   => array(),
		);

		$ids = array();
		foreach ( self::classify_rows( $rows, $ctx ) as $item ) {
			if ( '' === $item['category'] ) {
				continue;
			}

			$cat = $item['category'];
			if ( ! isset( $job['counts'][ $cat ] ) ) {
				$job['counts'][ $cat ] = array(
					'found'     => 0,
					'protected' => 0,
				);
			}
			++$job['counts'][ $cat ]['found'];

			if ( $item['protected'] ) {
				++$job['counts'][ $cat ]['protected'];
				continue;
			}

			$ids[] = (int) $item['row']['id'];

			if ( ! $is_delete && in_array( $cat, array( 'bot', 'monitor' ), true ) && ( isset( $job['uas'][ $item['row']['user_agent'] ] ) || count( $job['uas'] ) < 200 ) ) {
				$ua                = (string) $item['row']['user_agent'];
				$job['uas'][ $ua ] = ( isset( $job['uas'][ $ua ] ) ? $job['uas'][ $ua ] : 0 ) + 1;
			}
		}

		// Cancel may have landed while this batch was classifying; stop before deleting.
		if ( ! self::is_running( $job_id ) ) {
			return;
		}

		if ( $is_delete && $ids ) {
			// An order may have picked one of these visits up since classification.
			$ids             = array_diff( $ids, self::visit_ids_on_orders( $ids ) );
			$job['deleted'] += self::delete_visits( $ids, 'cleanup' );
		}

		$job['anchors']   = $ctx['anchors'];
		$job['heartbeat'] = time();

		if ( $rows ) {
			$job['cursor'] = (int) end( $rows )['id'];
		}

		// Re-read so a cancel (or a newer job) saved meanwhile is not overwritten.
		// A cancel landing after this check is still caught: its own flag is read again at the next batch's entry.
		if ( ! self::is_running( $job_id ) ) {
			return;
		}

		$state        = self::state();
		$state['job'] = $job;
		update_option( self::LOCK, time(), false );

		if ( count( $rows ) < ( $is_delete ? self::DEL_BATCH : self::SCAN_BATCH ) ) {
			$this->finish( $state );
			return;
		}

		self::save( $state );
		as_enqueue_async_action( self::HOOK, array( 'job' => $job_id ), self::GROUP );
	}

	private function finish( $state ) {
		$job = $state['job'];

		if ( 'delete' === $job['mode'] ) {
			$state['last_run'] = array(
				'finished_at' => time(),
				'user_id'     => $job['user_id'],
				'deleted'     => $job['deleted'],
				'protected'   => array_sum( array_column( $job['counts'], 'protected' ) ),
			);
			unset( $state['last_scan'] );
		} else {
			arsort( $job['uas'] );
			$state['last_scan'] = array(
				'finished_at'   => time(),
				'user_id'       => $job['user_id'],
				'from'          => $job['from'],
				'to'            => $job['to'],
				'settings_hash' => $job['settings_hash'],
				'counts'        => $job['counts'],
				'uas'           => array_slice( $job['uas'], 0, 10, true ),
			);
		}

		$job['status'] = 'done';
		unset( $job['anchors'], $job['uas'] );
		$state['job'] = $job;

		self::save( $state );
		delete_option( self::LOCK );
	}

	/**
	 * Next visits after the cursor, in id order.
	 *
	 * @param int    $cursor Last processed ID.
	 * @param int    $max_id Highest ID to consider.
	 * @param string $start  UTC lower bound or '' for none.
	 * @param string $end    UTC upper bound or ''.
	 * @param int    $limit  Batch size.
	 * @return array[]
	 */
	public static function fetch_rows( $cursor, $max_id, $start, $end, $limit ) {
		global $wpdb;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, utm_source, utm_medium, utm_campaign, landing_url, referrer, ip_hash, user_agent, user_id, visited_at
				FROM {$wpdb->prefix}utm_attribution_visits
				WHERE id > %d AND id <= %d AND visited_at BETWEEN %s AND %s
				ORDER BY id LIMIT %d",
				$cursor,
				$max_id,
				'' === $start ? '1000-01-01 00:00:00' : $start,
				'' === $start ? '9999-12-31 23:59:59' : $end,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Classify rows in id order. First match wins: staff, monitor/bot, non-page URL, repeat.
	 * $ctx carries the repeat anchors between batches.
	 *
	 * @param array[] $rows Rows from fetch_rows().
	 * @param array   $ctx  [ 'anchors' => key => timestamp, 'users' => cache ], updated in place.
	 * @return array[] [ 'row', 'category' ('' = keep), 'protected' ] per row.
	 */
	public static function classify_rows( array $rows, array &$ctx ) {
		global $wpdb;

		if ( ! $rows ) {
			return array();
		}

		$linked = array_flip(
			array_map(
				'intval',
				(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT visit_id FROM {$wpdb->prefix}utm_attribution_conversions WHERE visit_id BETWEEN %d AND %d",
						$rows[0]['id'],
						end( $rows )['id']
					)
				)
			)
		);

		$on_orders = array_flip( self::visit_ids_on_orders( array_column( $rows, 'id' ) ) );
		$repeat_on = utm_attribution_get_settings( 'merge_repeat_visits' ) && utm_attribution_get_settings( 'ip_hashing' );
		$out       = array();
		$ts        = 0;

		foreach ( $rows as $row ) {
			$ts  = (int) strtotime( $row['visited_at'] . ' UTC' );
			$key = null !== $row['ip_hash'] ? md5( $row['ip_hash'] . '|' . $row['user_agent'] . '|' . $row['utm_source'] . '|' . $row['utm_medium'] ) : '';
			$cat = '';
			$ua  = Utm_Attribution_Capture::classify_user_agent( $row['user_agent'] );

			if ( $row['user_id'] && self::staff( (int) $row['user_id'], $ctx ) ) {
				$cat = 'staff';
			} elseif ( '' !== $ua ) {
				$cat = $ua;
			} elseif ( Utm_Attribution_Capture::is_noise_path( (string) wp_parse_url( $row['landing_url'], PHP_URL_PATH ) ) ) {
				$cat = 'non_page';
			} elseif ( $repeat_on && '' !== $key && false === strpos( $row['landing_url'], 'utm_source=' ) && isset( $ctx['anchors'][ $key ] ) && $ts - $ctx['anchors'][ $key ] < Utm_Attribution_Capture::REPEAT_WINDOW ) {
				$cat = 'repeat';
			}

			$id        = (int) $row['id'];
			$protected = '' !== $cat && ( isset( $linked[ $id ] ) || isset( $on_orders[ $id ] ) );

			// A kept row (including a protected one) is what the next cookieless request is compared with.
			if ( ( '' === $cat || $protected ) && '' !== $key ) {
				$ctx['anchors'][ $key ] = $ts;
			}

			$out[] = array(
				'row'       => $row,
				'category'  => $cat,
				'protected' => $protected,
			);
		}

		$ctx['anchors'] = array_filter(
			$ctx['anchors'],
			function ( $anchor_ts ) use ( $ts ) {
				return $ts - $anchor_ts < Utm_Attribution_Capture::REPEAT_WINDOW;
			}
		);

		return $out;
	}

	private static function staff( $user_id, array &$ctx ) {
		if ( ! isset( $ctx['users'][ $user_id ] ) ) {
			$ctx['users'][ $user_id ] = Utm_Attribution_Capture::is_excluded_user( $user_id );
		}

		return $ctx['users'][ $user_id ];
	}

	/* ------------------------------------------------------------ Protection */

	/**
	 * Which of these visits are referenced by an order of any status. An order that has no conversion yet
	 * (pending, custom status, reopened) can still reach one later, and record_conversion() does not check
	 * that its visit exists. Read through wc_get_orders() + get_meta() so HPOS and legacy storage both work.
	 *
	 * @param int[] $ids Visit IDs.
	 * @return int[]
	 */
	public static function visit_ids_on_orders( array $ids ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$found    = array();
		$statuses = array_merge( array_keys( wc_get_order_statuses() ), array( 'checkout-draft', 'trash' ) );

		foreach ( array_chunk( array_values( array_filter( array_map( 'absint', $ids ) ) ), 1000 ) as $chunk ) {
			$orders = wc_get_orders(
				array(
					'status'     => $statuses,
					'limit'      => -1,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed lookup per 1000 visits.
						array(
							'key'     => '_utm_attribution_visit_id',
							'value'   => $chunk,
							'compare' => 'IN',
						),
					),
				)
			);
			foreach ( $orders as $order ) {
				$found[] = (int) $order->get_meta( '_utm_attribution_visit_id' );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Which of these visits must survive: linked to a conversion or referenced by an order.
	 *
	 * @param int[] $ids Visit IDs.
	 * @return int[]
	 */
	public static function get_protected_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$linked = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare(
				"SELECT visit_id FROM {$wpdb->prefix}utm_attribution_conversions WHERE visit_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$ids
			)
		);

		return array_values( array_unique( array_merge( array_map( 'intval', $linked ), array_intersect( $ids, self::visit_ids_on_orders( $ids ) ) ) ) );
	}

	/**
	 * Delete visits, never one that a conversion points at.
	 *
	 * @param int[]  $ids     Visit IDs.
	 * @param string $context 'cleanup' or 'bulk'.
	 * @return int Rows deleted.
	 */
	public static function delete_visits( array $ids, $context ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}utm_attribution_visits WHERE id IN ($in)
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}utm_attribution_conversions c WHERE c.visit_id = {$wpdb->prefix}utm_attribution_visits.id )",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( $deleted ) {
			/**
			 * Fires after visits are deleted.
			 *
			 * @param int[]  $ids     Visit IDs that were requested (some may have been skipped as linked to orders).
			 * @param string $context 'cleanup' for the Tools tab, 'bulk' for the Visits list action.
			 */
			do_action( 'utm_attribution_visits_deleted', $ids, $context );
		}

		return $deleted;
	}

	/* --------------------------------------------------------------- Notice */

	private static function notice_applies() {
		if ( ! current_user_can( self::capability() ) || get_user_meta( get_current_user_id(), self::META, true ) || ! empty( self::state()['last_run'] ) ) {
			return false;
		}

		global $wpdb;

		return (bool) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}utm_attribution_visits LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function upgrade_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ( 'plugins' !== $screen->id && false === strpos( $screen->id, 'utm-attribution' ) ) || ! class_exists( 'WooCommerce' ) || ! self::notice_applies() ) {
			return;
		}

		$tools = admin_url( 'admin.php?page=utm-attribution-settings&tab=tools' );
		$nonce = wp_create_nonce( self::NONCE );

		// WordPress's own "x" button hides the notice; this stores the dismissal for the user.
		wp_add_inline_script(
			'common',
			'jQuery(document).on("click","#utm-cleanup-notice .notice-dismiss",function(){jQuery.post(ajaxurl,{action:"utm_attribution_cleanup_dismiss",nonce:' . wp_json_encode( $nonce ) . '});});'
		);
		?>
		<div id="utm-cleanup-notice" class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: link to the Tools tab. */
					esc_html__( 'UTM Attribution now filters more bot traffic. Review and remove past bot visits in %s.', 'utm-attribution-for-woocommerce' ),
					'<a href="' . esc_url( $tools ) . '"><strong>' . esc_html__( 'Settings → Tools', 'utm-attribution-for-woocommerce' ) . '</strong></a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	public function ajax_dismiss_notice() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		update_user_meta( get_current_user_id(), self::META, 1 );
		wp_send_json_success();
	}
}

new Utm_Attribution_Cleanup();
