<?php
/**
 * Peticiones HTTP al propio sitio (loopback) con medición del primer byte.
 *
 * Solo se llama al propio dominio: Pulso no usa servicios externos.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sonda HTTP.
 */
class Inochi_Pulso_Http_Probe {

	/**
	 * Fin del presupuesto de tiempo del paso actual (microtime), 0 = sin presupuesto.
	 *
	 * @var float
	 */
	private static $deadline = 0;

	/**
	 * El sitio no responde a sí mismo: no se insiste en este paso.
	 *
	 * @var string
	 */
	private static $unreachable = '';

	/**
	 * Abre un presupuesto de tiempo compartido por las peticiones de un paso.
	 *
	 * @param int $seconds Segundos totales.
	 */
	public static function start_budget( $seconds ) {
		self::$deadline    = $seconds > 0 ? microtime( true ) + (float) $seconds : 0;
		self::$unreachable = '';
	}

	/**
	 * ¿Queda presupuesto?
	 */
	public static function has_budget() {
		return '' === self::$unreachable && ( ! self::$deadline || microtime( true ) < self::$deadline );
	}

	/**
	 * Hace una petición y devuelve código, cabeceras, tiempos y (opcional) cuerpo.
	 *
	 * @param string $url    URL absoluta del propio sitio.
	 * @param array  $args   method, body_limit (bytes de cuerpo a conservar), timeout.
	 * @return array{code:int,headers:array,ttfb:float|null,total:float|null,body:string,error:string,via:string}
	 */
	public static function request( $url, array $args = array() ) {
		$args = array_merge(
			array(
				'method'     => 'GET',
				'body_limit' => 0,
				'timeout'    => 8,
				'optional'   => false,
			),
			$args
		);
		if ( ! in_array( $args['method'], array( 'GET', 'HEAD' ), true ) ) {
			return self::empty_result( 'Método no permitido: Pulso solo hace GET y HEAD.' );
		}
		if ( ! self::is_own_url( $url ) ) {
			return self::empty_result( 'URL ajena al sitio: no se consulta.' );
		}
		if ( '' !== self::$unreachable ) {
			return self::empty_result( 'Sin respuesta del propio sitio: ' . self::$unreachable );
		}
		if ( self::$deadline ) {
			$left = self::$deadline - microtime( true );
			if ( $left < 1 ) {
				return self::empty_result( 'Tiempo del paso agotado.' );
			}
			$args['timeout'] = (int) max( 1, min( $args['timeout'], floor( $left ) ) );
		}
		$result = function_exists( 'curl_init' ) ? self::curl( $url, $args ) : self::wp_http( $url, $args );
		if ( ! $args['optional'] && 0 === $result['code'] && '' !== $result['error'] ) {
			// Fallo de conexión o tiempo agotado: el resto del paso no insiste.
			self::$unreachable = $result['error'];
		}
		return $result;
	}

	/**
	 * Solo se permiten URL del propio sitio (home o site URL).
	 */
	public static function is_own_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
		return $host && in_array( strtolower( $host ), array_map( 'strtolower', $own ), true );
	}

	/**
	 * Resultado vacío.
	 */
	private static function empty_result( $error ) {
		return array(
			'code'    => 0,
			'headers' => array(),
			'ttfb'    => null,
			'total'   => null,
			'body'    => '',
			'error'   => $error,
			'via'     => '',
		);
	}

	/**
	 * Verificación TLS para peticiones al propio sitio (mismo filtro que usa el núcleo).
	 */
	private static function verify_ssl() {
		return (bool) apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del núcleo.
	}

	/**
	 * Petición con cURL para obtener el tiempo hasta el primer byte.
	 */
	private static function curl( $url, array $args ) {
		$headers = array();
		$body    = '';
		$limit   = (int) $args['body_limit'];
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close -- se necesita CURLINFO_STARTTRANSFER_TIME.
		$ch   = curl_init( $url );
		$opts = array(
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => (int) $args['timeout'],
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_SSL_VERIFYPEER => self::verify_ssl(),
			CURLOPT_SSL_VERIFYHOST => self::verify_ssl() ? 2 : 0,
			CURLOPT_USERAGENT      => 'Inochi-Pulso/' . INOCHI_PULSO_VERSION . ' (auditoría; solo lectura)',
			CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$headers ) {
				$len = strlen( $line );
				if ( 0 === stripos( $line, 'HTTP/' ) ) {
					$headers = array(); // Nueva respuesta (p. ej. tras 100 Continue).
				}
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$name = strtolower( trim( $parts[0] ) );
					$val  = trim( $parts[1] );
					$headers[ $name ] = isset( $headers[ $name ] ) ? $headers[ $name ] . ', ' . $val : $val;
				}
				return $len;
			},
			CURLOPT_WRITEFUNCTION  => static function ( $ch, $chunk ) use ( &$body, $limit ) {
				if ( $limit > 0 && strlen( $body ) < $limit ) {
					$body .= substr( $chunk, 0, $limit - strlen( $body ) );
				}
				return strlen( $chunk );
			},
		);
		if ( 'HEAD' === $args['method'] ) {
			$opts[ CURLOPT_NOBODY ] = true;
		}
		curl_setopt_array( $ch, $opts );
		curl_exec( $ch );
		$info  = curl_getinfo( $ch );
		$error = curl_error( $ch );
		curl_close( $ch );
		// phpcs:enable

		return array(
			'code'    => (int) ( $info['http_code'] ?? 0 ),
			'headers' => $headers,
			'ttfb'    => isset( $info['starttransfer_time'] ) && $info['starttransfer_time'] > 0 ? (float) $info['starttransfer_time'] : null,
			'total'   => isset( $info['total_time'] ) ? (float) $info['total_time'] : null,
			'body'    => $body,
			'error'   => (string) $error,
			'via'     => 'curl',
		);
	}

	/**
	 * Alternativa sin cURL: tiempo total (no primer byte).
	 */
	private static function wp_http( $url, array $args ) {
		$start    = microtime( true );
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $args['method'],
				'timeout'     => (int) $args['timeout'],
				'redirection' => 0,
				'sslverify'   => self::verify_ssl(),
				'user-agent'  => 'Inochi-Pulso/' . INOCHI_PULSO_VERSION . ' (auditoría; solo lectura)',
			)
		);
		$elapsed  = microtime( true ) - $start;
		if ( is_wp_error( $response ) ) {
			return self::empty_result( $response->get_error_message() );
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $k => $v ) {
			$headers[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : $v;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'ttfb'    => $elapsed,
			'total'   => $elapsed,
			'body'    => $args['body_limit'] > 0 ? substr( $body, 0, (int) $args['body_limit'] ) : '',
			'error'   => '',
			'via'     => 'wp_http',
		);
	}
}
