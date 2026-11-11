<?php
/**
 * SEG-03: plugins cerrados o sin actualizar en WordPress.org.
 *
 * Única llamada externa del plugin. Solo se ejecuta si el administrador lo ha
 * aceptado en Ajustes (desactivado por defecto). Envía a api.wordpress.org el
 * slug (nombre de carpeta) de cada plugin instalado y nada más.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consulta a la API de plugins de WordPress.org.
 */
class Inochi_Pulso_Checks_WPOrg extends Inochi_Pulso_Checks_Base {

	const API          = 'https://api.wordpress.org/plugins/info/1.2/';
	const STEP_SECONDS = 15;

	/**
	 * URL de la API (filtrable solo en pruebas).
	 */
	private static function endpoint() {
		if ( defined( 'INOCHI_PULSO_TESTING' ) && INOCHI_PULSO_TESTING ) {
			return (string) apply_filters( 'inochi_pulso_wporg_endpoint', self::API );
		}
		return self::API;
	}

	/**
	 * Paso del escaneo. Puede pedir más tramos («again») si hay muchos plugins.
	 *
	 * @param array $scan_payload Datos ya recogidos (necesita «plugins» del paso de seguridad).
	 */
	public static function run( $scan_payload = array() ) {
		if ( ! Inochi_Pulso_Settings::wporg_allowed() ) {
			return array(
				'findings' => array(
					self::auto( 'SEG-03', 'Consulta a WordPress.org desactivada', Inochi_Pulso_Catalog::NO_DATA, 'Para saber si algún plugin está cerrado o lleva más de 2 años sin actualizarse, activa «Consultar plugins en WordPress.org» en Ajustes y repite el escaneo. Hasta entonces esta comprobación no cuenta en la puntuación.' ),
				),
				'payload'  => array(),
			);
		}

		$plugins = (array) ( $scan_payload['plugins'] ?? array() );
		$cache   = (array) ( $scan_payload['wporg_cache'] ?? array() );
		$start   = microtime( true );
		$pending = 0;

		foreach ( $plugins as $p ) {
			$slug = sanitize_key( $p['slug'] ?? '' );
			if ( '' === $slug || isset( $cache[ $slug ] ) ) {
				continue;
			}
			if ( ( microtime( true ) - $start ) > self::STEP_SECONDS ) {
				++$pending;
				continue;
			}
			$cache[ $slug ] = self::lookup( $slug );
		}
		if ( $pending ) {
			return array(
				'findings' => array(),
				'payload'  => array( 'wporg_cache' => $cache ),
				'again'    => true,
				'note'     => sprintf( 'Consultados %d plugins, quedan %d…', count( $cache ), $pending ),
			);
		}
		return self::summarise( $plugins, $cache );
	}

	/**
	 * Estado de un plugin en WordPress.org.
	 *
	 * @param string $slug Slug.
	 */
	private static function lookup( $slug ) {
		$url = add_query_arg(
			array(
				'action'  => 'plugin_information',
				'request' => array(
					'slug'   => $slug,
					'fields' => array_fill_keys( array( 'sections', 'description', 'reviews', 'banners', 'icons', 'screenshots', 'versions', 'contributors', 'tags', 'ratings', 'donate_link', 'compatibility' ), 0 ),
				),
			),
			self::endpoint()
		);
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Inochi-Pulso/' . INOCHI_PULSO_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 'error' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array();
		if ( isset( $data['error'] ) ) {
			$closed = 'closed' === $data['error'] || ! empty( $data['closed'] );
			return array(
				'status' => $closed ? 'closed' : 'not_found',
				'date'   => sanitize_text_field( (string) ( $data['closed_date'] ?? '' ) ),
				'reason' => sanitize_text_field( (string) ( $data['reason_text'] ?? ( $data['reason'] ?? '' ) ) ),
			);
		}
		if ( 200 === $code && ! empty( $data['slug'] ) ) {
			return array(
				'status'       => 'ok',
				'last_updated' => sanitize_text_field( (string) ( $data['last_updated'] ?? '' ) ),
				'tested'       => sanitize_text_field( (string) ( $data['tested'] ?? '' ) ),
			);
		}
		return array( 'status' => 'error' );
	}

	/**
	 * Resultado final de SEG-03 y datos por plugin.
	 *
	 * @param array $plugins Plugins del escaneo.
	 * @param array $cache   slug => estado en WordPress.org.
	 */
	private static function summarise( array $plugins, array $cache ) {
		$closed = array();
		$old    = array();
		$rows   = array();
		$errors = 0;
		foreach ( $plugins as $p ) {
			$slug = sanitize_key( $p['slug'] ?? '' );
			$info = $cache[ $slug ] ?? array( 'status' => 'error' );
			if ( 'closed' === $info['status'] ) {
				$p['origin'] = 'Cerrado en WordPress.org';
				$p['note']   = 'Cerrado' . ( $info['date'] ? ' el ' . $info['date'] : '' ) . ( $info['reason'] ? ': ' . $info['reason'] : '' );
				$closed[]    = $slug;
			} elseif ( 'ok' === $info['status'] ) {
				$p['origin']       = 'WordPress.org';
				$ts                = strtotime( (string) $info['last_updated'] );
				$p['last_updated'] = $ts ? gmdate( 'Y-m-d', $ts ) : '';
				$p['tested']       = $info['tested'];
				if ( $ts && $ts < time() - 2 * YEAR_IN_SECONDS ) {
					$old[] = $slug . ' (' . gmdate( 'Y-m-d', $ts ) . ')';
				}
			} elseif ( 'error' === $info['status'] ) {
				++$errors;
			}
			$rows[] = $p;
		}

		$lines = array();
		foreach ( $closed as $s ) {
			$lines[] = 'Cerrado: ' . $s . ( empty( $cache[ $s ]['reason'] ) ? '' : ' — ' . $cache[ $s ]['reason'] );
		}
		foreach ( $old as $s ) {
			$lines[] = 'Sin actualizar en más de 2 años: ' . $s;
		}
		$lines[] = sprintf( 'Consultados en la API de plugins de WordPress.org: %d (con error: %d).', count( $cache ), $errors );

		if ( $closed ) {
			$state = 'Fallo';
		} elseif ( $old ) {
			$state = 'Aviso';
		} elseif ( $errors ) {
			$state = Inochi_Pulso_Catalog::NO_DATA; // Sin respuesta completa no se puede afirmar un OK.
		} else {
			$state = 'OK';
		}
		$value = $closed || $old ? sprintf( '%d cerrados y %d sin actualizar en más de 2 años', count( $closed ), count( $old ) ) : ( $errors ? 'WordPress.org no respondió para todos los plugins' : 'Ninguno cerrado ni abandonado' );

		return array(
			'findings' => array( self::auto( 'SEG-03', $value, $state, implode( "\n", $lines ) ) ),
			'payload'  => array( 'plugins' => $rows ),
		);
	}
}
