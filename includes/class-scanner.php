<?php
/**
 * Escaneo por pasos encadenados (cada paso, una petición REST). Sin WP-Cron.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orquestador de pasos.
 */
class Inochi_Pulso_Scanner {

	/**
	 * Pasos en orden: clave => [etiqueta, callable].
	 */
	public static function steps() {
		return array(
			'general'     => array( 'Datos generales', array( 'Inochi_Pulso_Checks_General', 'run' ) ),
			'database'    => array( 'Base de datos', array( 'Inochi_Pulso_Checks_Database', 'run' ) ),
			'autoload'    => array( 'Autoload por plugin', array( 'Inochi_Pulso_Checks_Autoload', 'run' ) ),
			'runtime'     => array( 'PHP, cron y configuración', array( 'Inochi_Pulso_Checks_Runtime', 'run' ) ),
			'ttfb'        => array( 'Tiempos de respuesta', array( 'Inochi_Pulso_Checks_Http', 'run_ttfb' ) ),
			'headers'     => array( 'Caché, HTTPS y exposición', array( 'Inochi_Pulso_Checks_Http', 'run_headers' ) ),
			'security'    => array( 'Plugins, actualizaciones y cuentas', array( 'Inochi_Pulso_Checks_Security', 'run' ) ),
			'files'       => array( 'Archivos y permisos', array( 'Inochi_Pulso_Checks_Files', 'run' ) ),
			'woocommerce' => array( 'WooCommerce', array( 'Inochi_Pulso_Checks_WooCommerce', 'run' ) ),
			'maintenance' => array( 'Mantenimiento y registros', array( 'Inochi_Pulso_Checks_Maintenance', 'run' ) ),
			'wporg'       => array( 'Plugins en WordPress.org (si lo has activado)', array( 'Inochi_Pulso_Checks_WPOrg', 'run' ) ),
		);
	}

	/**
	 * Lista pública de pasos para la pantalla.
	 */
	public static function step_list() {
		$out = array();
		foreach ( self::steps() as $key => $def ) {
			$out[] = array(
				'key'   => $key,
				'label' => $def[0],
			);
		}
		return $out;
	}

	/**
	 * Ejecuta un paso y guarda sus resultados. Un error en un paso no detiene el escaneo.
	 *
	 * @param int    $scan_id ID del escaneo.
	 * @param string $step    Clave del paso.
	 * @return array{ok:bool,error:string,count:int,again:bool,note:string}
	 */
	public static function run_step( $scan_id, $step ) {
		$steps = self::steps();
		if ( ! isset( $steps[ $step ] ) ) {
			return array(
				'ok'    => false,
				'error' => 'Paso desconocido.',
				'count' => 0,
			);
		}
		Inochi_Pulso_DB::update_scan( $scan_id, array( 'current_step' => $step ) );
		$error  = '';
		$count  = 0;
		$failed = array();
		$again  = false;
		$note   = '';
		ob_start(); // Avisos de PHP impresos por otros plugins no deben romper la respuesta JSON.
		try {
			Inochi_Pulso_Http_Probe::start_budget( 'ttfb' === $step ? 40 : 25 );
			$before = Inochi_Pulso_DB::get_scan( $scan_id );
			$result = call_user_func( $steps[ $step ][1], (array) ( $before['payload'] ?? array() ) );
			foreach ( (array) ( $result['findings'] ?? array() ) as $finding ) {
				if ( ! empty( $finding['code'] ) ) {
					if ( Inochi_Pulso_DB::save_finding( $scan_id, $finding ) ) {
						++$count;
					} else {
						$failed[] = $finding['code'];
					}
				}
			}
			if ( $failed ) {
				$error = 'No se pudieron guardar: ' . implode( ', ', $failed );
			}
			$again   = ! empty( $result['again'] );
			$note    = (string) ( $result['note'] ?? '' );
			$payload = (array) ( $result['payload'] ?? array() );
			if ( $payload ) {
				$scan = Inochi_Pulso_DB::get_scan( $scan_id );
				foreach ( $payload as $k => $v ) {
					if ( is_array( $v ) && isset( $scan['payload'][ $k ] ) && is_array( $scan['payload'][ $k ] ) && ! wp_is_numeric_array( $v ) ) {
						$payload[ $k ] = array_merge( $scan['payload'][ $k ], $v );
					}
				}
				Inochi_Pulso_DB::merge_payload( $scan_id, $payload );
			}
		} catch ( Throwable $e ) {
			$error = $e->getMessage();
		}
		ob_end_clean();
		Inochi_Pulso_Http_Probe::start_budget( 0 );
		if ( '' !== $error ) {
			$scan          = Inochi_Pulso_DB::get_scan( $scan_id );
			$errs          = (array) ( $scan['payload']['errors'] ?? array() );
			$errs[ $step ] = Inochi_Pulso_Util::clip( Inochi_Pulso_Util::redact( $error ), 500 );
			Inochi_Pulso_DB::merge_payload( $scan_id, array( 'errors' => $errs ) );
		}
		return array(
			'ok'    => '' === $error,
			'error' => $error,
			'count' => $count,
			'again' => $again && '' === $error,
			'note'  => $note,
		);
	}

	/**
	 * Cierra el escaneo.
	 */
	public static function finish( $scan_id ) {
		Inochi_Pulso_DB::update_scan(
			$scan_id,
			array(
				'status'       => 'done',
				'current_step' => '',
				'finished_at'  => current_time( 'mysql', true ),
			)
		);
	}
}
