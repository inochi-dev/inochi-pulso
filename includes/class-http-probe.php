<?php
/**
 * HTTP requests to the site itself (loopback) with time to first byte.
 *
 * Only the site's own domain is requested.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP probe.
 */
class Inochi_Pulso_Http_Probe {

	/**
	 * End of the time budget of the current step (microtime), 0 = no budget.
	 *
	 * @var float
	 */
	private static $deadline = 0;

	/**
	 * The site does not answer itself: this step does not insist.
	 *
	 * @var string
	 */
	private static $unreachable = '';

	/**
	 * Opens a time budget shared by the requests of a step.
	 *
	 * @param int $seconds Total seconds.
	 */
	public static function start_budget( $seconds ) {
		self::$deadline    = $seconds > 0 ? microtime( true ) + (float) $seconds : 0;
		self::$unreachable = '';
	}

	/**
	 * Is there budget left?
	 */
	public static function has_budget() {
		return '' === self::$unreachable && ( ! self::$deadline || microtime( true ) < self::$deadline );
	}

	/**
	 * Makes a request and returns code, headers, times and (optionally) body.
	 *
	 * @param string $url  Absolute URL of the site itself.
	 * @param array  $args method, body_limit (body bytes to keep), timeout, optional.
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
			return self::empty_result( __( 'Method not allowed: Pulso only makes GET and HEAD requests.', 'inochi-pulso' ) );
		}
		if ( ! self::is_own_url( $url ) ) {
			return self::empty_result( __( 'URL outside the site: not requested.', 'inochi-pulso' ) );
		}
		if ( '' !== self::$unreachable ) {
			/* translators: %s: error. */
			return self::empty_result( sprintf( __( 'No answer from the site itself: %s', 'inochi-pulso' ), self::$unreachable ) );
		}
		if ( self::$deadline ) {
			$left = self::$deadline - microtime( true );
			if ( $left < 1 ) {
				return self::empty_result( __( 'Step time is over.', 'inochi-pulso' ) );
			}
			$args['timeout'] = (int) max( 1, min( $args['timeout'], floor( $left ) ) );
		}
		$result = function_exists( 'curl_init' ) ? self::curl( $url, $args ) : self::wp_http( $url, $args );
		if ( ! $args['optional'] && 0 === $result['code'] && '' !== $result['error'] ) {
			// Connection failure or timeout: the rest of the step does not insist.
			self::$unreachable = $result['error'];
		}
		return $result;
	}

	/**
	 * Only URLs of the site itself are allowed (home or site URL).
	 *
	 * @param string $url URL.
	 */
	public static function is_own_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
		return $host && in_array( strtolower( $host ), array_map( 'strtolower', $own ), true );
	}

	/**
	 * Empty result.
	 *
	 * @param string $error Error.
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
	 * TLS verification for requests to the site itself (same filter core uses).
	 */
	private static function verify_ssl() {
		return (bool) apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
	}

	/**
	 * Request with cURL to get the time to first byte.
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments.
	 */
	private static function curl( $url, array $args ) {
		$headers = array();
		$body    = '';
		$limit   = (int) $args['body_limit'];
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close -- CURLINFO_STARTTRANSFER_TIME is needed and the WP HTTP API does not expose it.
		$ch   = curl_init( $url );
		$opts = array(
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => (int) $args['timeout'],
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_SSL_VERIFYPEER => self::verify_ssl(),
			CURLOPT_SSL_VERIFYHOST => self::verify_ssl() ? 2 : 0,
			CURLOPT_USERAGENT      => 'Inochi-Pulso/' . INOCHI_PULSO_VERSION . ' (read-only site scan)',
			CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$headers ) {
				$len = strlen( $line );
				if ( 0 === stripos( $line, 'HTTP/' ) ) {
					$headers = array(); // New response (for example after 100 Continue).
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
	 * Fallback without cURL: total time (not first byte).
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments.
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
				'user-agent'  => 'Inochi-Pulso/' . INOCHI_PULSO_VERSION . ' (read-only site scan)',
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
