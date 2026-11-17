<?php
/**
 * WOO-01 to WOO-03 and WOO-05 to WOO-09.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

// Read-only scan of WooCommerce's own tables: direct SELECT queries, read once per scan.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * WooCommerce checks (read-only).
 */
class Inochi_Pulso_Checks_WooCommerce extends Inochi_Pulso_Checks_Base {

	const CODES = array( 'WOO-01', 'WOO-02', 'WOO-03', 'WOO-05', 'WOO-06', 'WOO-07', 'WOO-08', 'WOO-09' );

	/**
	 * Text variants chosen by the checks (code => key).
	 *
	 * @var array
	 */
	private static $variants = array();

	/**
	 * Runs the step.
	 */
	public static function run() {
		if ( ! Inochi_Pulso_Util::has_woocommerce() ) {
			$findings = array();
			foreach ( self::CODES as $code ) {
				$findings[] = self::auto( $code, __( 'No WooCommerce', 'inochi-pulso' ), 'N.A.', __( 'WooCommerce is not active.', 'inochi-pulso' ) );
			}
			return array(
				'findings' => $findings,
				'payload'  => array(),
			);
		}
		$findings       = array();
		self::$variants = array();
		foreach ( array( 'version_templates', 'hpos', 'pages', 'webhooks', 'stuck_orders', 'failed_actions', 'late_actions', 'sessions' ) as $m ) {
			try {
				$findings[] = self::$m();
			} catch ( Throwable $e ) {
				$findings[] = self::partial( self::CODES[ count( $findings ) ], __( 'Error while checking', 'inochi-pulso' ), Inochi_Pulso_Util::redact( $e->getMessage() ) );
			}
		}
		return array(
			'findings' => $findings,
			'payload'  => array( 'variants' => self::$variants ),
		);
	}

	/**
	 * Action Scheduler table.
	 */
	private static function as_table() {
		global $wpdb;
		return $wpdb->prefix . 'actionscheduler_actions';
	}

	/**
	 * Does the table exist?
	 *
	 * @param string $table Table.
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * WOO-01: version and overridden templates.
	 */
	private static function version_templates() {
		$version = defined( 'WC_VERSION' ) ? WC_VERSION : '?';
		$updates = get_site_transient( 'update_plugins' );
		$new     = '';
		if ( is_object( $updates ) && ! empty( $updates->response ) ) {
			foreach ( $updates->response as $file => $item ) {
				if ( 'woocommerce' === Inochi_Pulso_Util::plugin_slug( $file ) ) {
					$new = (string) ( $item->new_version ?? '' );
				}
			}
		}
		$behind = 0;
		if ( $new ) {
			$behind = (int) explode( '.', $new )[0] - (int) explode( '.', $version )[0];
		}

		// Theme overrides, read from the files (not from the WooCommerce status report,
		// which contacts WordPress.org and stores a transient).
		$outdated = array();
		$total    = 0;
		foreach ( self::template_overrides() as $o ) {
			++$total;
			if ( '' === $o['version'] ) {
				$outdated[] = $o['file'] . ' (' . __( 'no version', 'inochi-pulso' ) . ')';
			} elseif ( '' !== $o['core'] && version_compare( $o['version'], $o['core'], '<' ) ) {
				$outdated[] = $o['file'] . ' (' . $o['version'] . ' < ' . $o['core'] . ')';
			}
		}
		$state = $behind >= 2 ? 'Fallo' : ( $outdated ? 'Aviso' : 'OK' );
		/* translators: %s: version. */
		$value = 'WooCommerce ' . $version . ( $new ? ' (' . sprintf( __( '%s available', 'inochi-pulso' ), $new ) . ')' : '' );
		/* translators: 1: outdated templates, 2: overridden templates. */
		$value .= ' · ' . sprintf( __( '%1$d of %2$d overridden templates outdated', 'inochi-pulso' ), count( $outdated ), $total );
		$ev     = $value . ( $outdated ? ":\n" . implode( "\n", $outdated ) : '.' ) . ( $new ? '' : "\n" . __( 'Available version according to the last WordPress update check; no data if there is no pending update.', 'inochi-pulso' ) );
		return self::auto( 'WOO-01', $value, $state, $ev );
	}

	/**
	 * WooCommerce templates overridden by the active theme or its parent.
	 *
	 * @return array<int, array{file:string, version:string, core:string}>
	 */
	private static function template_overrides() {
		$base = trailingslashit( WC()->plugin_path() ) . 'templates/';
		if ( ! is_dir( $base ) ) {
			return array();
		}
		$dirs = array_unique(
			array(
				trailingslashit( get_stylesheet_directory() ) . 'woocommerce/',
				trailingslashit( get_stylesheet_directory() ),
				trailingslashit( get_template_directory() ) . 'woocommerce/',
				trailingslashit( get_template_directory() ),
			)
		);
		$out  = array();
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || 'php' !== strtolower( $f->getExtension() ) ) {
					continue;
				}
				$rel = substr( wp_normalize_path( $f->getPathname() ), strlen( wp_normalize_path( $base ) ) );
				foreach ( $dirs as $dir ) {
					if ( is_file( $dir . $rel ) ) {
						$out[] = array(
							'file'    => $rel,
							'version' => self::file_version( $dir . $rel ),
							'core'    => self::file_version( $f->getPathname() ),
						);
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			return $out;
		}
		return $out;
	}

	/**
	 * Version declared in a template header (@version x.y.z).
	 *
	 * @param string $path File.
	 */
	private static function file_version( $path ) {
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		$head = (string) fread( $fh, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return preg_match( '/@version\s+([0-9.]+)/i', $head, $m ) ? rtrim( $m[1], '.' ) : '';
	}

	/**
	 * WOO-02: HPOS.
	 */
	private static function hpos() {
		$on      = Inochi_Pulso_Checks_General::hpos_enabled();
		$sync    = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' );
		$pending = null;
		$class   = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
		if ( ( $on || $sync ) && function_exists( 'wc_get_container' ) && class_exists( $class ) ) {
			try {
				$ds = wc_get_container()->get( $class );
				if ( is_callable( array( $ds, 'get_current_orders_pending_sync_count' ) ) ) {
					$pending = (int) $ds->get_current_orders_pending_sync_count();
				}
			} catch ( Throwable $e ) {
				$pending = null;
			}
		}
		if ( $on && ! $sync ) {
			// HPOS is the source of truth and the old table is not kept: "pending" orders are normal.
			$value = __( 'HPOS active · sync turned off', 'inochi-pulso' );
			/* translators: %d: number of orders. */
			$ev = $value . ( null !== $pending ? '. ' . sprintf( __( 'WooCommerce counts %d different orders in the old table, which is not used with sync turned off: it is not a problem.', 'inochi-pulso' ), $pending ) : '.' );
			return self::auto( 'WOO-02', $value, 'OK', $ev );
		}
		$value  = 'HPOS ' . ( $on ? __( 'active', 'inochi-pulso' ) : __( 'turned off', 'inochi-pulso' ) );
		$value .= ' · ' . ( $sync ? __( 'sync active', 'inochi-pulso' ) : __( 'sync inactive', 'inochi-pulso' ) );
		/* translators: %d: number of orders pending sync. */
		$value .= null !== $pending ? ' · ' . sprintf( __( '%d pending', 'inochi-pulso' ), $pending ) : '';
		$state  = ( $on && ! $pending ) ? 'OK' : 'Aviso';
		if ( $on && $pending ) {
			self::$variants['WOO-02'] = 'pending_sync';
		}
		return self::auto( 'WOO-02', $value, $state, $value . '.' );
	}

	/**
	 * WOO-03: WooCommerce pages.
	 */
	private static function pages() {
		$pages   = array(
			'shop'      => __( 'Shop', 'inochi-pulso' ),
			'cart'      => __( 'Cart', 'inochi-pulso' ),
			'checkout'  => __( 'Checkout', 'inochi-pulso' ),
			'myaccount' => __( 'My account', 'inochi-pulso' ),
		);
		$missing = array();
		$lines   = array();
		foreach ( $pages as $key => $label ) {
			$id      = wc_get_page_id( $key );
			$status  = $id > 0 ? get_post_status( $id ) : false;
			$lines[] = $label . ': ' . ( $id > 0 ? 'ID ' . $id . ' (' . ( $status ? $status : __( 'does not exist', 'inochi-pulso' ) ) . ')' : __( 'not assigned', 'inochi-pulso' ) );
			if ( 'publish' !== $status ) {
				$missing[] = $label;
			}
		}
		$active_gw = 0;
		foreach ( WC()->payment_gateways()->payment_gateways() as $gw ) {
			$active_gw += 'yes' === $gw->enabled ? 1 : 0;
		}
		$sales = array( __( 'Cart', 'inochi-pulso' ), __( 'Checkout', 'inochi-pulso' ) );
		if ( $missing && ! $active_gw && ! array_diff( $missing, $sales ) ) {
			// No cart, no checkout and no payment gateway: the store is used as a catalogue.
			$lines[] = __( 'No payment gateway is active: the store seems to work as a catalogue. If it should sell online, assign and publish these pages.', 'inochi-pulso' );
			/* translators: %s: list of pages. */
			return self::auto( 'WOO-03', sprintf( __( 'Catalogue without %s', 'inochi-pulso' ), implode( ', ', $missing ) ), 'Aviso', implode( "\n", $lines ) );
		}
		if ( $missing ) {
			/* translators: %s: list of pages. */
			return self::auto( 'WOO-03', sprintf( __( 'Missing or not published: %s', 'inochi-pulso' ), implode( ', ', $missing ) ), 'Fallo', implode( "\n", $lines ) );
		}
		return self::auto( 'WOO-03', __( 'The 4 pages assigned and published', 'inochi-pulso' ), 'OK', implode( "\n", $lines ) );
	}

	/**
	 * WOO-05: failing webhooks (only the destination domain is kept).
	 */
	private static function webhooks() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_webhooks';
		if ( ! self::table_exists( $table ) ) {
			return self::auto( 'WOO-05', __( 'No webhooks', 'inochi-pulso' ), 'OK', __( 'The webhooks table does not exist.', 'inochi-pulso' ) );
		}
		$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT webhook_id, name, status, failure_count, delivery_url FROM %i', $table ), ARRAY_A );
		$h        = array( 'id', __( 'name', 'inochi-pulso' ), __( 'status', 'inochi-pulso' ), __( 'failures', 'inochi-pulso' ), __( 'destination', 'inochi-pulso' ) );
		$disabled = 0;
		$failing  = 0;
		$list     = array();
		foreach ( (array) $rows as $r ) {
			$fails = (int) $r['failure_count'];
			if ( 'disabled' === $r['status'] && $fails > 0 ) {
				++$disabled;
			} elseif ( $fails > 0 ) {
				++$failing;
			}
			$list[] = array(
				$h[0] => $r['webhook_id'],
				$h[1] => $r['name'],
				$h[2] => $r['status'],
				$h[3] => $fails,
				$h[4] => (string) wp_parse_url( $r['delivery_url'], PHP_URL_HOST ),
			);
		}
		$state = $disabled ? 'Fallo' : ( $failing ? 'Aviso' : 'OK' );
		/* translators: 1: webhooks, 2: disabled by failures, 3: with failures. */
		$value = sprintf( __( '%1$d webhooks · %2$d disabled by failures · %3$d with failures', 'inochi-pulso' ), count( $list ), $disabled, $failing );
		$ev    = $list ? Inochi_Pulso_Util::text_table( $list, $h ) . "\n" . __( 'Only the destination domain is kept.', 'inochi-pulso' ) : __( 'No webhooks.', 'inochi-pulso' );
		return self::auto( 'WOO-05', $value, $state, $ev );
	}

	/**
	 * WOO-06: orders stuck for more than 7 days.
	 */
	private static function stuck_orders() {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		if ( Inochi_Pulso_Checks_General::hpos_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders';
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status AS s, COUNT(*) AS n FROM %i WHERE type = 'shop_order' AND status IN ('wc-pending','wc-on-hold','wc-processing') AND date_created_gmt < %s GROUP BY status", $table, $since ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_status AS s, COUNT(*) AS n FROM %i WHERE post_type = 'shop_order' AND post_status IN ('wc-pending','wc-on-hold','wc-processing') AND post_date_gmt < %s GROUP BY post_status", $wpdb->posts, $since ), ARRAY_A );
		}
		$by = array(
			'wc-pending'    => 0,
			'wc-on-hold'    => 0,
			'wc-processing' => 0,
		);
		foreach ( (array) $rows as $r ) {
			$by[ $r['s'] ] = (int) $r['n'];
		}
		$stuck = $by['wc-processing'] + $by['wc-on-hold'];
		$state = 0 === $stuck ? 'OK' : ( $stuck <= 10 ? 'Aviso' : 'Fallo' );
		/* translators: 1: processing, 2: on hold, 3: pending payment. */
		$value = sprintf( __( '%1$d processing and %2$d on hold > 7 days · %3$d old pending payment', 'inochi-pulso' ), $by['wc-processing'], $by['wc-on-hold'], $by['wc-pending'] );
		$ev    = $value . ".\n" . __( 'Old "pending payment" orders are abandoned carts: they do not score. Check with the team whether old "processing" orders are normal in this business.', 'inochi-pulso' );
		return self::auto( 'WOO-06', $value, $state, $ev );
	}

	/**
	 * WOO-07: scheduled actions failed in 30 days.
	 */
	private static function failed_actions() {
		global $wpdb;
		$table = self::as_table();
		if ( ! self::table_exists( $table ) ) {
			return self::partial( 'WOO-07', __( 'No Action Scheduler table', 'inochi-pulso' ), __( 'The actionscheduler_actions table does not exist.', 'inochi-pulso' ) );
		}
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hook, COUNT(*) AS n, MAX(last_attempt_gmt) AS last FROM %i WHERE status = 'failed' AND last_attempt_gmt > %s GROUP BY hook ORDER BY n DESC LIMIT 15",
				$table,
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		$since    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed' AND last_attempt_gmt > %s", $table, $since ) );
		$critical = array();
		$h        = array( 'hook', __( 'failed', 'inochi-pulso' ), __( 'last', 'inochi-pulso' ) );
		$list     = array();
		foreach ( (array) $rows as $r ) {
			if ( preg_match( '/stock|inventor|sync|erp|payment|pago|refund|ship|envio|envío|subscription/i', $r['hook'] ) ) {
				$critical[] = $r['hook'];
			}
			$list[] = array(
				$h[0] => $r['hook'],
				$h[1] => $r['n'],
				$h[2] => $r['last'],
			);
		}
		if ( $total > 100 || $critical ) {
			$state = 'Fallo';
		} elseif ( $total >= 10 ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		/* translators: %s: number of failed actions. */
		$value = sprintf( __( '%s failed in 30 days', 'inochi-pulso' ), Inochi_Pulso_Util::num( $total ) );
		/* translators: %s: list of hooks. */
		$value .= $critical ? ' · ' . sprintf( __( 'affecting: %s', 'inochi-pulso' ), implode( ', ', array_slice( $critical, 0, 3 ) ) ) : '';
		$ev     = $list ? Inochi_Pulso_Util::text_table( $list, $h ) . "\n" . __( 'The reason for each failure: Tools → Scheduled Actions → Failed (Log column).', 'inochi-pulso' ) : __( 'No failed actions in 30 days.', 'inochi-pulso' );
		return self::auto( 'WOO-07', $value, $state, $ev );
	}

	/**
	 * WOO-08: pending actions more than 1 h late.
	 */
	private static function late_actions() {
		global $wpdb;
		$table = self::as_table();
		if ( ! self::table_exists( $table ) ) {
			return self::partial( 'WOO-08', __( 'No Action Scheduler table', 'inochi-pulso' ), __( 'The actionscheduler_actions table does not exist.', 'inochi-pulso' ) );
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hook, COUNT(*) AS n FROM %i WHERE status = 'pending' AND scheduled_date_gmt < %s GROUP BY hook ORDER BY n DESC LIMIT 15",
				$table,
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending' AND scheduled_date_gmt < %s", $table, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$h     = array( 'hook', __( 'late', 'inochi-pulso' ) );
		$list  = array();
		foreach ( (array) $rows as $r ) {
			$list[] = array(
				$h[0] => $r['hook'],
				$h[1] => $r['n'],
			);
		}
		$state = Inochi_Pulso_Util::grade( $total, 10, 100 );
		$ev    = $list ? Inochi_Pulso_Util::text_table( $list, $h ) : __( 'No late actions.', 'inochi-pulso' );
		/* translators: %s: number of late actions. */
		return self::auto( 'WOO-08', sprintf( __( '%s late > 1 h', 'inochi-pulso' ), Inochi_Pulso_Util::num( $total ) ), $state, $ev );
	}

	/**
	 * WOO-09: expired sessions.
	 */
	private static function sessions() {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		if ( ! self::table_exists( $table ) ) {
			return self::auto( 'WOO-09', __( 'No sessions table', 'inochi-pulso' ), 'OK', __( 'The woocommerce_sessions table does not exist.', 'inochi-pulso' ) );
		}
		$expired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_expiry < %d', $table, time() ) );
		$bytes   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT (data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) );
		if ( $expired > 100000 || $bytes > 200 * 1048576 ) {
			$state = 'Fallo';
		} elseif ( $expired >= 10000 ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		/* translators: 1: expired sessions, 2: table size. */
		$value = sprintf( __( '%1$s expired · table %2$s', 'inochi-pulso' ), Inochi_Pulso_Util::num( $expired ), Inochi_Pulso_Util::bytes( $bytes ) );
		return self::auto( 'WOO-09', $value, $state, $value . '.' );
	}
}
