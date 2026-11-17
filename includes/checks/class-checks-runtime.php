<?php
/**
 * REN-06, REN-14, REN-16, SEG-06 and SEG-10: runtime settings.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * PHP and WordPress settings.
 */
class Inochi_Pulso_Checks_Runtime extends Inochi_Pulso_Checks_Base {

	/**
	 * End of security support of each PHP branch (php.net/supported-versions).
	 */
	const PHP_SUPPORT_END = array(
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	);

	/**
	 * Runs the step.
	 */
	public static function run() {
		return array(
			'findings' => array(
				self::object_cache(),
				self::php_config(),
				self::plugin_count(),
				self::php_version(),
				self::file_editor(),
			),
			'payload'  => array(),
		);
	}

	/**
	 * REN-06: persistent object cache.
	 */
	private static function object_cache() {
		$ext  = wp_using_ext_object_cache();
		$drop = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
		$type = '';
		if ( $drop ) {
			if ( ! function_exists( 'get_dropins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$dropins = get_dropins();
			$type    = $dropins['object-cache.php']['Name'] ?? 'object-cache.php';
		}
		if ( $ext ) {
			/* translators: %s: drop-in status. */
			$ev = sprintf( __( 'wp_using_ext_object_cache() = true; object-cache.php drop-in %s.', 'inochi-pulso' ), $drop ? __( 'present', 'inochi-pulso' ) : __( 'missing', 'inochi-pulso' ) );
			return self::auto( 'REN-06', __( 'Active', 'inochi-pulso' ) . ( $type ? ': ' . $type : '' ), 'OK', $ev );
		}
		$big    = false;
		$detail = '';
		if ( Inochi_Pulso_Util::has_woocommerce() ) {
			$products = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
			$per_day  = Inochi_Pulso_Checks_General::count_orders( 30 ) / 30;
			$big      = $products > 1000 || $per_day > 50;
			/* translators: 1: products, 2: orders per day. */
			$detail = ' ' . sprintf( __( 'Store with %1$s products and %2$s orders a day (30-day average).', 'inochi-pulso' ), Inochi_Pulso_Util::num( $products ), Inochi_Pulso_Util::num( $per_day, 1 ) );
		}
		$ev = 'wp_using_ext_object_cache() = false; ' . ( $drop ? __( 'there is an object-cache.php file but it is not active.', 'inochi-pulso' ) : __( 'no object-cache.php drop-in.', 'inochi-pulso' ) ) . $detail;
		return self::auto( 'REN-06', __( 'No persistent object cache', 'inochi-pulso' ), $big ? 'Fallo' : 'Aviso', $ev );
	}

	/**
	 * REN-14: memory_limit and OPcache (values of the PHP that serves the site).
	 */
	private static function php_config() {
		$mem_raw = (string) ini_get( 'memory_limit' );
		$mem     = Inochi_Pulso_Util::ini_bytes( $mem_raw );
		$opcache = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache.restrict_api can forbid it.
			$opcache = is_array( $status ) ? ! empty( $status['opcache_enabled'] ) : (bool) ini_get( 'opcache.enable' );
		}
		$woo     = Inochi_Pulso_Util::has_woocommerce();
		$mem_ok  = -1 === $mem || $mem >= 256 * 1048576;
		$mem_bad = -1 !== $mem && $mem < 128 * 1048576;
		if ( $mem_bad || ( ! $opcache && $woo ) ) {
			$state = 'Fallo';
		} elseif ( ! $mem_ok || ! $opcache ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		$op = $opcache ? __( 'active', 'inochi-pulso' ) : __( 'inactive', 'inochi-pulso' );
		/* translators: 1: memory_limit, 2: OPcache status. */
		$value = sprintf( __( 'memory_limit %1$s · OPcache %2$s', 'inochi-pulso' ), $mem_raw, $op );
		$ev    = sprintf(
			'memory_limit = %s · WP_MEMORY_LIMIT = %s · WP_MAX_MEMORY_LIMIT = %s · max_execution_time = %s · OPcache = %s.',
			$mem_raw,
			defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '-',
			defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '-',
			ini_get( 'max_execution_time' ),
			$op
		) . "\n" . __( 'Values of the PHP that serves the site (not of the command line).', 'inochi-pulso' );
		return self::auto( 'REN-14', $value, $state, $ev );
	}

	/**
	 * REN-16: number of active plugins.
	 */
	private static function plugin_count() {
		$plugins = Inochi_Pulso_Util::plugins();
		$active  = count( array_filter( wp_list_pluck( $plugins, 'active' ) ) );
		$mu      = function_exists( 'get_mu_plugins' ) ? count( get_mu_plugins() ) : 0;
		/* translators: 1: active plugins, 2: installed plugins, 3: must-use plugins. */
		$value = sprintf( __( '%1$d active of %2$d installed · %3$d must-use', 'inochi-pulso' ), $active, count( $plugins ), $mu );
		return self::auto( 'REN-16', $value, Inochi_Pulso_Util::grade( $active, 41, 60 ), $value . '. ' . __( 'Thresholds: up to 40 OK · 41–60 Warning · more than 60 Fail.', 'inochi-pulso' ) );
	}

	/**
	 * SEG-06: PHP version with security support.
	 */
	private static function php_version() {
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$eol    = self::PHP_SUPPORT_END[ $branch ] ?? null;
		if ( null === $eol ) {
			$state = version_compare( $branch, '7.4', '<' ) ? 'Fallo' : 'OK';
			$note  = __( 'Branch without a date in Pulso: check php.net/supported-versions.', 'inochi-pulso' );
		} else {
			$eol_ts = strtotime( $eol . ' 23:59:59 UTC' );
			$now    = time();
			if ( $eol_ts < $now ) {
				$state = 'Fallo';
			} elseif ( $eol_ts < $now + YEAR_IN_SECONDS ) {
				$state = 'Aviso';
			} else {
				$state = 'OK';
			}
			/* translators: 1: PHP branch, 2: date. */
			$note = sprintf( __( 'End of security support for PHP %1$s: %2$s (php.net/supported-versions).', 'inochi-pulso' ), $branch, $eol );
		}
		return self::auto( 'SEG-06', 'PHP ' . PHP_VERSION, $state, $note . ' ' . __( 'Warning if it ends in less than 12 months.', 'inochi-pulso' ) );
	}

	/**
	 * SEG-10: dashboard file editor.
	 */
	private static function file_editor() {
		$off = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		return self::auto(
			'SEG-10',
			$off ? __( 'Editor turned off', 'inochi-pulso' ) : __( 'File editor active', 'inochi-pulso' ),
			$off ? 'OK' : 'Aviso',
			'DISALLOW_FILE_EDIT = ' . ( defined( 'DISALLOW_FILE_EDIT' ) ? wp_json_encode( DISALLOW_FILE_EDIT ) : '—' ) . ' · DISALLOW_FILE_MODS = ' . ( defined( 'DISALLOW_FILE_MODS' ) ? wp_json_encode( DISALLOW_FILE_MODS ) : '—' ) . '.'
		);
	}
}
