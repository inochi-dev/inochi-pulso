<?php
/**
 * REN-06, REN-12, REN-14, REN-16, SEG-06 y SEG-10: configuración en ejecución.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones de configuración de PHP y WordPress.
 */
class Inochi_Pulso_Checks_Runtime extends Inochi_Pulso_Checks_Base {

	/**
	 * Fin del soporte de seguridad de cada rama de PHP (php.net/supported-versions).
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
	 * Ejecuta el paso.
	 */
	public static function run() {
		return array(
			'findings' => array(
				self::object_cache(),
				self::cron(),
				self::php_config(),
				self::plugin_count(),
				self::php_version(),
				self::file_editor(),
			),
			'payload'  => array(),
		);
	}

	/**
	 * REN-06: caché de objetos persistente.
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
			return self::auto( 'REN-06', 'Activa' . ( $type ? ': ' . $type : '' ), 'OK', 'wp_using_ext_object_cache() = true; drop-in object-cache.php ' . ( $drop ? 'presente' : 'ausente' ) . '.' );
		}
		$woo    = Inochi_Pulso_Util::has_woocommerce();
		$big    = false;
		$detail = '';
		if ( $woo ) {
			$products = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
			$per_day  = Inochi_Pulso_Checks_General::count_orders( 30 ) / 30;
			$big      = $products > 1000 || $per_day > 50;
			$detail   = sprintf( ' Tienda con %s productos y %s pedidos/día (media 30 días).', Inochi_Pulso_Util::num( $products ), Inochi_Pulso_Util::num( $per_day, 1 ) );
		}
		$state = $big ? 'Fallo' : 'Aviso';
		$ev    = 'wp_using_ext_object_cache() = false' . ( $drop ? '; hay object-cache.php pero no está activo' : '; sin drop-in object-cache.php' ) . '.' . $detail;
		return self::auto( 'REN-06', 'No hay caché de objetos persistente', $state, $ev );
	}

	/**
	 * REN-12: eventos de cron atrasados y huérfanos.
	 */
	private static function cron() {
		$crons   = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
		$now     = time();
		$late    = array();
		$orphans = array();
		$total   = 0;
		foreach ( $crons as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				$total += count( (array) $events );
				if ( (int) $ts < $now - HOUR_IN_SECONDS ) {
					$late[ $hook ] = max( $late[ $hook ] ?? 0, $now - (int) $ts );
				}
				if ( ! has_action( $hook ) ) {
					$orphans[ $hook ] = true;
				}
			}
		}
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( $late ) {
			$state = 'Fallo';
		} elseif ( $orphans ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		$value = sprintf( '%d eventos · %d atrasados > 1 h · %d huérfanos · WP-Cron %s', $total, count( $late ), count( $orphans ), $disabled ? 'desactivado (cron del sistema)' : 'activo' );
		$lines = array();
		foreach ( $late as $hook => $secs ) {
			$lines[] = sprintf( 'Atrasado: %s (%s h)', $hook, Inochi_Pulso_Util::num( $secs / 3600, 1 ) );
		}
		foreach ( array_keys( $orphans ) as $hook ) {
			$lines[] = 'Huérfano (ningún código activo lo atiende): ' . $hook;
		}
		if ( $disabled ) {
			$lines[] = 'DISABLE_WP_CRON = true: comprueba en Plesk que existe la tarea que llama a wp-cron.php.';
		}
		$lines[] = 'Un hook huérfano puede ser falso positivo si su plugin solo lo registra en el escritorio o durante el cron; confírmalo con grep.';
		return self::auto( 'REN-12', $value, $state, implode( "\n", $lines ) );
	}

	/**
	 * REN-14: memory_limit y OPcache (valores del PHP que sirve la web).
	 */
	private static function php_config() {
		$mem_raw = (string) ini_get( 'memory_limit' );
		$mem     = Inochi_Pulso_Util::ini_bytes( $mem_raw );
		$opcache = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache.restrict_api puede impedirlo.
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
		$value = sprintf( 'memory_limit %s · OPcache %s', $mem_raw, $opcache ? 'activo' : 'inactivo' );
		$ev    = sprintf(
			"memory_limit = %s · WP_MEMORY_LIMIT = %s · WP_MAX_MEMORY_LIMIT = %s · max_execution_time = %s · OPcache = %s.\nValores del PHP que atiende la web (no de la línea de comandos).",
			$mem_raw,
			defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '-',
			defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '-',
			ini_get( 'max_execution_time' ),
			$opcache ? 'activo' : 'inactivo'
		);
		return self::auto( 'REN-14', $value, $state, $ev );
	}

	/**
	 * REN-16: número de plugins activos.
	 */
	private static function plugin_count() {
		$plugins = Inochi_Pulso_Util::plugins();
		$active  = count( array_filter( wp_list_pluck( $plugins, 'active' ) ) );
		$mu      = function_exists( 'get_mu_plugins' ) ? count( get_mu_plugins() ) : 0;
		$value   = sprintf( '%d activos de %d instalados · %d mu-plugins', $active, count( $plugins ), $mu );
		return self::auto( 'REN-16', $value, Inochi_Pulso_Util::grade( $active, 41, 60 ), $value . '. Umbral: ≤ 40 OK · 41–60 Aviso · > 60 Fallo.' );
	}

	/**
	 * SEG-06: versión de PHP con soporte de seguridad.
	 */
	private static function php_version() {
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$eol    = self::PHP_SUPPORT_END[ $branch ] ?? null;
		if ( null === $eol ) {
			$state = version_compare( $branch, '7.4', '<' ) ? 'Fallo' : 'OK';
			$note  = 'Rama sin fecha registrada en Pulso: confirma en php.net/supported-versions.';
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
			$note = 'Fin del soporte de seguridad de PHP ' . $branch . ': ' . $eol . ' (php.net/supported-versions).';
		}
		return self::auto( 'SEG-06', 'PHP ' . PHP_VERSION, $state, $note . ' Aviso si termina en menos de 12 meses.' );
	}

	/**
	 * SEG-10: editor de archivos del escritorio.
	 */
	private static function file_editor() {
		$off = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		return self::auto(
			'SEG-10',
			$off ? 'Editor desactivado' : 'Editor de archivos activo',
			$off ? 'OK' : 'Aviso',
			'DISALLOW_FILE_EDIT = ' . ( defined( 'DISALLOW_FILE_EDIT' ) ? var_export( DISALLOW_FILE_EDIT, true ) : 'no definido' ) . ' · DISALLOW_FILE_MODS = ' . ( defined( 'DISALLOW_FILE_MODS' ) ? var_export( DISALLOW_FILE_MODS, true ) : 'no definido' ) . '.' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);
	}
}
