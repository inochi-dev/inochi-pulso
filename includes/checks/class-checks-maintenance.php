<?php
/**
 * MAN-01 (parcial), MAN-06 (parcial) y MAN-07.
 *
 * MAN-02 a MAN-05 dependen del panel del alojamiento o del cliente: son manuales.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones de mantenimiento.
 */
class Inochi_Pulso_Checks_Maintenance extends Inochi_Pulso_Checks_Base {

	const LOG_TAIL = 5242880; // Últimos 5 MB de cada registro.

	/**
	 * Ejecuta el paso.
	 */
	public static function run() {
		return array(
			'findings' => array(
				self::backups(),
				self::updates_policy(),
				self::php_errors(),
			),
			'payload'  => array(),
		);
	}

	/**
	 * MAN-01 (parcial): plugins de copias activos.
	 */
	private static function backups() {
		$found = Inochi_Pulso_Util::active_matching( Inochi_Pulso_Util::known( 'backup' ) );
		$value = $found ? 'Plugin de copias activo: ' . implode( ', ', $found ) : 'Sin plugin de copias activo';
		$ev    = $value . ".\nRevisa también las copias de Plesk y del alojamiento, su frecuencia y su destino.";
		return self::partial( 'MAN-01', $value, $ev );
	}

	/**
	 * MAN-06 (parcial): actualizaciones automáticas.
	 */
	private static function updates_policy() {
		$auto_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		$plugins      = Inochi_Pulso_Util::plugins();
		$auto_active  = 0;
		foreach ( $plugins as $p ) {
			if ( $p['active'] && in_array( $p['file'], $auto_plugins, true ) ) {
				++$auto_active;
			}
		}
		$core = 'solo versiones menores (por defecto)';
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			$v    = WP_AUTO_UPDATE_CORE;
			$core = true === $v || 'true' === $v ? 'todas las versiones' : ( false === $v || 'false' === $v ? 'desactivadas' : ( 'minor' === $v ? 'solo versiones menores' : ( 'beta' === $v || 'rc' === $v || 'development' === $v ? 'versiones de desarrollo' : 'configuración no estándar' ) ) );
		}
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			$core = 'desactivadas (AUTOMATIC_UPDATER_DISABLED)';
		}
		$updates = get_site_transient( 'update_plugins' );
		$pending = is_object( $updates ) && ! empty( $updates->response ) ? count( (array) $updates->response ) : 0;
		$value   = sprintf( '%d plugins activos con actualización automática · núcleo: %s · %d plugins con actualización pendiente', $auto_active, $core, $pending );
		$ev      = $value . ".\nEl procedimiento (copia, prueba, revisión) se pregunta al cliente.";
		if ( $pending >= 10 ) {
			// Con tantas pendientes, en la práctica nadie está actualizando: Aviso salvo que el cliente demuestre lo contrario.
			return self::partial( 'MAN-06', $value, $ev . "\nCon {$pending} actualizaciones pendientes el estado sugerido es Aviso.", 'Aviso' );
		}
		return self::partial( 'MAN-06', $value, $ev );
	}

	/**
	 * Registros de errores legibles: ruta => etiqueta.
	 */
	private static function log_files() {
		$files = array();
		$ini   = (string) ini_get( 'error_log' );
		if ( $ini && @is_file( $ini ) && @is_readable( $ini ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
			$files[ $ini ] = 'error_log de PHP';
		}
		$log = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		$dbg = is_string( $log ) && ! in_array( $log, array( '', 'true', 'false', '1', '0' ), true ) ? $log : WP_CONTENT_DIR . '/debug.log';
		if ( @is_file( $dbg ) && @is_readable( $dbg ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$files[ $dbg ] = 'debug.log de WordPress';
		}
		foreach ( array( dirname( ABSPATH ) . '/logs/error_log', ABSPATH . 'error_log' ) as $p ) {
			if ( @is_file( $p ) && @is_readable( $p ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$files[ $p ] = 'registro del alojamiento';
			}
		}
		return $files;
	}

	/**
	 * Lee el final de un archivo.
	 */
	private static function tail( $path, $bytes ) {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$fh   = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		if ( $size > $bytes ) {
			fseek( $fh, $size - $bytes );
		}
		$data = (string) fread( $fh, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $data;
	}

	/**
	 * MAN-07: errores de PHP en los últimos 7 días.
	 */
	private static function php_errors() {
		$files = self::log_files();
		if ( ! $files ) {
			return self::partial( 'MAN-07', 'Sin acceso a registros de errores', 'No hay registros legibles desde WordPress. Revisa Plesk → Registros. Si tampoco tienes acceso, deja el estado en «Sin datos».', Inochi_Pulso_Catalog::NO_DATA );
		}
		$since    = time() - 7 * DAY_IN_SECONDS;
		$fatals   = array();
		$warnings = 0;
		$lines_ev = array();
		foreach ( $files as $path => $label ) {
			$data = self::tail( $path, self::LOG_TAIL );
			$n_f  = 0;
			$n_w  = 0;
			foreach ( preg_split( '/\R/', $data ) as $line ) {
				if ( '' === $line ) {
					continue;
				}
				// Fecha al inicio: [29-Oct-2026 21:00:00 UTC] o [Thu Oct 29 21:00:00.123 2026].
				if ( preg_match( '/^\[([^\]]+)\]/', $line, $m ) ) {
					$ts = strtotime( preg_replace( '/\.\d+/', '', $m[1] ) );
					if ( $ts && $ts < $since ) {
						continue;
					}
				}
				if ( false !== stripos( $line, 'PHP Fatal error' ) ) {
					++$n_f;
					$msg            = preg_replace( '/^.*PHP Fatal error:\s*/i', '', $line );
					$msg            = preg_replace( '/ in \/.*$/', '', $msg );
					$msg            = preg_replace( '#[\'"]?/[^\s\'"]+[\'"]?#', '[ruta]', (string) $msg );
					$key            = substr( trim( $msg ), 0, 140 );
					$fatals[ $key ] = ( $fatals[ $key ] ?? 0 ) + 1;
				} elseif ( false !== stripos( $line, 'PHP Warning' ) ) {
					++$n_w;
				}
			}
			$warnings  += $n_w;
			$lines_ev[] = sprintf( '%s (%s): %d fatales y %d warnings en 7 días.', $label, basename( $path ), $n_f, $n_w );
		}
		arsort( $fatals );
		$total = array_sum( $fatals );
		$top   = array();
		foreach ( array_slice( $fatals, 0, 8, true ) as $msg => $n ) {
			$top[] = $n . ' × ' . $msg;
		}
		$ev = implode( "\n", $lines_ev ) . ( $top ? "\nFatales más repetidos:\n" . implode( "\n", $top ) : '' ) . "\nSe leen los últimos 5 MB de cada registro; las rutas del servidor no se copian.";
		if ( $total > 0 ) {
			$state = 'Fallo';
		} elseif ( $warnings > 50 ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		$value  = sprintf( '%d errores fatales y %d warnings en 7 días', $total, $warnings );
		$server = array_diff( $files, array( 'debug.log de WordPress' ) );
		if ( ! $server && 'Fallo' !== $state ) {
			return self::partial( 'MAN-07', $value . ' (solo debug.log)', $ev . "\nSolo se pudo leer el debug.log: revisa también el registro del servidor en Plesk." );
		}
		return self::auto( 'MAN-07', $value, $state, $ev );
	}
}
