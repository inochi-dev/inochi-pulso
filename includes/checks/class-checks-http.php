<?php
/**
 * Comprobaciones por HTTP contra el propio sitio (loopback):
 * REN-01 (TTFB), REN-05, SEG-07, SEG-08, SEG-09, SEG-12, SEG-13 y SEG-16.
 *
 * Las peticiones salen del propio servidor: miden el tiempo del servidor sin la
 * red del visitante, y pueden no pasar por la CDN si el servidor resuelve su
 * dominio en local. Por eso solo se afirma un estado cuando la señal es clara.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones HTTP.
 */
class Inochi_Pulso_Checks_Http extends Inochi_Pulso_Checks_Base {

	const LOOPBACK_NOTE = 'Medido desde el propio servidor (loopback): sin la latencia de red del visitante.';

	/**
	 * Paso REN-01: tiempo hasta el primer byte en 5 URL.
	 */
	public static function run_ttfb() {
		Inochi_Pulso_Util::extend_time( 180 );
		$urls   = self::sample_urls();
		$rows   = array();
		$is_woo = Inochi_Pulso_Util::has_woocommerce();
		$errors = array();
		foreach ( $urls as $label => $url ) {
			$row = array(
				'label'   => $label,
				'path'    => self::path_of( $url ),
				'visitor' => array(),
				'origin'  => array(),
				'cache'   => '',
				'code'    => 0,
			);
			for ( $i = 0; $i < 3 && Inochi_Pulso_Http_Probe::has_budget(); $i++ ) {
				$v                = Inochi_Pulso_Http_Probe::request( $url );
				$o                = Inochi_Pulso_Http_Probe::request( add_query_arg( 'inochi_pulso', wp_rand( 100000, 999999 ), $url ) );
				$row['visitor'][] = $v['ttfb'];
				$row['origin'][]  = $o['ttfb'];
				$row['code']      = $v['code'];
				if ( $v['error'] ) {
					$errors[] = $label . ': ' . $v['error'];
				}
				if ( $i > 0 ) {
					$row['cache'] = self::cache_signal( $v['headers'] ) ?: $row['cache'];
				}
			}
			$rows[] = $row;
		}

		// Estado: mediana «origen» de carrito y finalizar compra (sin tienda: las 5).
		$pool      = array();
		$redirects = array();
		foreach ( $rows as $idx => $row ) {
			if ( $row['code'] >= 300 && $row['code'] < 400 ) {
				$redirects[] = $row['label'];
				continue;
			}
			if ( $is_woo && $idx < 3 ) {
				continue;
			}
			$m = self::median( $row['origin'] );
			if ( null !== $m ) {
				$pool[] = $m;
			}
		}
		if ( ! $pool ) {
			foreach ( $rows as $row ) {
				$m = self::median( $row['origin'] );
				if ( null !== $m && ( $row['code'] < 300 || $row['code'] >= 400 ) ) {
					$pool[] = $m;
				}
			}
		}
		$table = array();
		foreach ( $rows as $row ) {
			$table[] = array(
				'página'    => $row['label'] . ' ' . $row['path'],
				'HTTP'      => $row['code'],
				'visitante' => self::fmt( self::median( $row['visitor'] ) ),
				'origen'    => self::fmt( self::median( $row['origin'] ) ),
				'caché'     => $row['cache'] ? $row['cache'] : '-',
			);
		}
		$ev = Inochi_Pulso_Util::text_table( $table, array( 'página', 'HTTP', 'visitante', 'origen', 'caché' ) ) . "\nMedianas en segundos de 3 peticiones. «Origen» añade ?inochi_pulso=aleatorio para saltar la caché.\n" . self::LOOPBACK_NOTE . ' Si queda cerca de un umbral, mide desde fuera con el comando de la guía.';
		if ( $redirects ) {
			$ev .= "\nNo cuentan para el estado por responder con una redirección (p. ej. finalizar compra con el carrito vacío): " . implode( ', ', $redirects ) . '.';
		}
		if ( $errors ) {
			$ev .= "\nErrores: " . implode( '; ', array_unique( $errors ) );
		}

		if ( ! $pool ) {
			$finding = self::partial( 'REN-01', 'No se pudo medir desde el servidor', $ev );
		} else {
			$avg     = array_sum( $pool ) / count( $pool );
			$basis   = 'desde el servidor, sin caché, ' . ( $is_woo ? ( $redirects ? 'carrito' : 'carrito y finalizar compra' ) : 'URL sin redirección' );
			$finding = self::auto( 'REN-01', sprintf( '%s s (%s)', Inochi_Pulso_Util::num( $avg, 2 ), $basis ), Inochi_Pulso_Util::grade( $avg, 0.6, 1.2 ), $ev );
		}

		return array(
			'findings' => array( $finding ),
			'payload'  => array( 'ttfb' => $rows ),
		);
	}

	/**
	 * Paso de cabeceras y exposición.
	 */
	public static function run_headers() {
		Inochi_Pulso_Util::extend_time( 120 );
		$home  = home_url( '/' );
		$first = Inochi_Pulso_Http_Probe::request( $home, array( 'body_limit' => 3145728 ) );
		$again = Inochi_Pulso_Http_Probe::request( $home, array( 'body_limit' => 3145728 ) );
		$cdn   = self::through_cdn( $again['headers'] );

		return array(
			'findings' => array(
				self::page_cache( $first, $again, $cdn ),
				self::https( $again ),
				self::security_headers( $again, $cdn ),
				self::debug_exposure(),
				self::user_enumeration(),
				self::xmlrpc(),
				self::waf( $again, $cdn ),
			),
			'payload'  => array(
				'headers_home' => self::safe_headers( $again['headers'] ),
			),
		);
	}

	/**
	 * Cinco URL representativas: etiqueta => URL.
	 */
	public static function sample_urls() {
		$urls = array( 'Portada' => home_url( '/' ) );
		if ( Inochi_Pulso_Util::has_woocommerce() ) {
			$cat                        = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			$cat_url                    = ( ! is_wp_error( $cat ) && $cat ) ? get_term_link( $cat[0] ) : '';
			$urls['Categoría']          = ( $cat_url && ! is_wp_error( $cat_url ) ) ? $cat_url : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
			$prod                       = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$urls['Producto / entrada'] = $prod ? get_permalink( $prod[0] ) : home_url( '/' );
			$urls['Carrito']            = wc_get_cart_url();
			$urls['Finalizar compra']   = wc_get_checkout_url();
		} else {
			$cats                       = get_categories(
				array(
					'hide_empty' => true,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			$urls['Categoría']          = $cats ? get_category_link( $cats[0] ) : home_url( '/' );
			$post                       = get_posts(
				array(
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$urls['Producto / entrada'] = $post ? get_permalink( $post[0] ) : home_url( '/' );
			$page                       = get_posts(
				array(
					'post_type'      => 'page',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$urls['Carrito']            = $page ? get_permalink( $page[0] ) : home_url( '/' );
			$pages                      = get_posts(
				array(
					'post_type'      => 'page',
					'posts_per_page' => 2,
					'fields'         => 'ids',
				)
			);
			$urls['Finalizar compra']   = isset( $pages[1] ) ? get_permalink( $pages[1] ) : home_url( '/' );
		}
		return $urls;
	}

	/**
	 * Ruta relativa de una URL.
	 */
	private static function path_of( $url ) {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return ( '' === $path ? '/' : $path ) . ( $query ? '?' . $query : '' );
	}

	/**
	 * Mediana de valores no nulos.
	 */
	public static function median( array $values ) {
		$values = array_values( array_filter( $values, 'is_numeric' ) );
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		$n   = count( $values );
		$mid = intdiv( $n, 2 );
		return $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	/**
	 * Segundos con 2 decimales o «-».
	 */
	private static function fmt( $v ) {
		return null === $v ? '-' : Inochi_Pulso_Util::num( $v, 2 );
	}

	/**
	 * Señal de caché en cabeceras: devuelve p. ej. «HIT (cf-cache-status)» o ''.
	 */
	private static function cache_signal( array $h ) {
		foreach ( array( 'cf-cache-status', 'x-cache', 'x-litespeed-cache', 'x-proxy-cache', 'x-cache-status', 'x-nginx-cache', 'x-fastcgi-cache', 'x-sg-cache', 'x-kinsta-cache', 'x-cache-enabled' ) as $name ) {
			if ( isset( $h[ $name ] ) ) {
				$val = strtoupper( $h[ $name ] );
				if ( false !== strpos( $val, 'HIT' ) || 'TRUE' === $val ) {
					return 'HIT (' . $name . ')';
				}
				return $val . ' (' . $name . ')';
			}
		}
		if ( isset( $h['age'] ) && (int) $h['age'] > 0 ) {
			return 'HIT (age ' . (int) $h['age'] . ')';
		}
		return '';
	}

	/**
	 * La respuesta pasó por una CDN o proxy reconocible.
	 */
	private static function through_cdn( array $h ) {
		return isset( $h['cf-ray'] ) || isset( $h['x-sucuri-id'] ) || isset( $h['x-served-by'] ) || isset( $h['x-amz-cf-id'] ) || isset( $h['x-fastly-request-id'] ) || isset( $h['x-akamai-transformed'] ) || ( isset( $h['server'] ) && preg_match( '/cloudflare|sucuri|bunny|fastly|akamai/i', $h['server'] ) );
	}

	/**
	 * Cabeceras sin cookies ni identificadores, para la evidencia.
	 */
	private static function safe_headers( array $h ) {
		unset( $h['set-cookie'], $h['cf-ray'], $h['x-request-id'], $h['report-to'], $h['nel'] );
		return $h;
	}

	/**
	 * REN-05: caché de página.
	 */
	private static function page_cache( array $first, array $again, $cdn ) {
		$signal  = self::cache_signal( $again['headers'] );
		$markers = array(
			'WP Rocket'        => 'This website is like a Rocket',
			'WP Super Cache'   => 'WP-Super-Cache',
			'W3 Total Cache'   => 'W3 Total Cache',
			'WP Fastest Cache' => 'WP Fastest Cache',
			'LiteSpeed Cache'  => 'Page optimized by LiteSpeed Cache',
			'Cache Enabler'    => 'Cache Enabler',
			'Breeze'           => 'Cache served by breeze',
		);
		$marker  = '';
		foreach ( $markers as $name => $needle ) {
			if ( false !== stripos( $again['body'], $needle ) ) {
				$marker = $name;
				break;
			}
		}
		$plugins = Inochi_Pulso_Util::active_matching( Inochi_Pulso_Util::known( 'cache' ) );
		$ev      = 'Dos peticiones a la portada. Cabeceras de caché en la segunda: ' . ( $signal ? $signal : 'ninguna' ) . '. Marca de caché en el HTML: ' . ( $marker ? $marker : 'ninguna' ) . '. Plugins de caché activos: ' . ( $plugins ? implode( ', ', $plugins ) : 'ninguno' ) . '. Petición ' . ( $cdn ? 'a través de CDN' : 'sin señales de CDN' ) . ".\n" . self::LOOPBACK_NOTE;
		if ( 0 === strpos( $signal, 'HIT' ) || $marker ) {
			return self::auto( 'REN-05', 'Portada servida desde caché' . ( $signal ? ': ' . $signal : ' (' . $marker . ')' ), 'OK', $ev );
		}
		if ( $cdn && ! $plugins ) {
			return self::auto( 'REN-05', 'Sin caché de página: la CDN responde ' . ( $signal ? $signal : 'sin HIT' ), 'Fallo', $ev );
		}
		$value = $plugins ? 'Plugin de caché activo sin señal de HIT' : 'Sin señales de caché de página desde el servidor';
		return self::partial( 'REN-05', $value, $ev . ' Comprueba desde fuera con curl.' );
	}

	/**
	 * SEG-07: HTTPS, redirección, certificado y HSTS (el contenido mixto es manual).
	 */
	private static function https( array $again ) {
		$home  = home_url( '/' );
		$https = 'https' === wp_parse_url( $home, PHP_URL_SCHEME );
		$host  = (string) wp_parse_url( $home, PHP_URL_HOST );
		$lines = array( 'URL del sitio: ' . ( $https ? 'HTTPS' : 'HTTP' ) . '.' );
		$fail  = array();
		if ( ! $https ) {
			$fail[] = 'el sitio no usa HTTPS';
		}

		$redir       = Inochi_Pulso_Http_Probe::request(
			set_url_scheme( $home, 'http' ),
			array(
				'method'   => 'HEAD',
				'optional' => true, // Que http:// esté cerrado no significa que el sitio no responda.
			)
		);
		$loc         = $redir['headers']['location'] ?? '';
		$ok_redirect = in_array( $redir['code'], array( 301, 302, 307, 308 ), true ) && 0 === stripos( $loc, 'https://' );
		$lines[]     = 'http:// → código ' . $redir['code'] . ( $loc ? ' → ' . $loc : '' ) . ( $redir['error'] ? ' (' . $redir['error'] . ')' : '' ) . '.';
		if ( $https && 200 === $redir['code'] ) {
			$fail[] = 'http:// responde 200 sin redirigir';
		}

		$days = null;
		if ( $https && function_exists( 'openssl_x509_parse' ) ) {
			$ctx  = stream_context_create(
				array(
					'ssl' => array(
						'capture_peer_cert' => true,
						'verify_peer'       => false,
						'verify_peer_name'  => false,
						'SNI_enabled'       => true,
						'peer_name'         => $host,
					),
				)
			);
			$sock = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $sock ) {
				$params = stream_context_get_params( $sock );
				$cert   = $params['options']['ssl']['peer_certificate'] ?? null;
				$parsed = $cert ? openssl_x509_parse( $cert ) : false;
				if ( $parsed && isset( $parsed['validTo_time_t'] ) ) {
					$days    = (int) floor( ( $parsed['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
					$lines[] = 'Certificado válido hasta ' . gmdate( 'Y-m-d', $parsed['validTo_time_t'] ) . " ({$days} días)" . ( isset( $parsed['issuer']['O'] ) ? ', emitido por ' . $parsed['issuer']['O'] : '' ) . '.';
				}
				fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			} else {
				$lines[] = 'No se pudo leer el certificado: ' . $errstr;
			}
		}
		if ( null !== $days && $days < 0 ) {
			$fail[] = 'certificado caducado';
		}
		$hsts    = $again['headers']['strict-transport-security'] ?? '';
		$lines[] = 'HSTS: ' . ( $hsts ? $hsts : 'no' ) . '.';
		$lines[] = 'Contenido mixto: revisar a mano en la consola del navegador (portada y finalizar compra).';

		$value  = $https ? 'HTTPS' : 'Sin HTTPS';
		$value .= $ok_redirect ? ' · redirige' : ' · sin redirección confirmada';
		$value .= null !== $days ? ' · certificado ' . $days . ' días' : '';
		$value .= $hsts ? ' · HSTS' : ' · sin HSTS';
		if ( $fail ) {
			return self::auto( 'SEG-07', $value, 'Fallo', 'Fallo: ' . implode( ', ', $fail ) . ".\n" . implode( "\n", $lines ) );
		}
		if ( null !== $days && $days <= 15 ) {
			$lines[] = 'El certificado caduca en 15 días o menos: confirma que se renueva solo.';
		}
		return self::partial( 'SEG-07', $value, implode( "\n", $lines ) );
	}

	/**
	 * SEG-08: cabeceras de seguridad.
	 */
	private static function security_headers( array $again, $cdn ) {
		$h       = $again['headers'];
		$csp     = $h['content-security-policy'] ?? '';
		$present = array(
			'X-Content-Type-Options' => isset( $h['x-content-type-options'] ),
			'X-Frame-Options'        => isset( $h['x-frame-options'] ) || false !== stripos( $csp, 'frame-ancestors' ),
			'Referrer-Policy'        => isset( $h['referrer-policy'] ),
		);
		$n       = count( array_filter( $present ) );
		$state   = 3 === $n ? 'OK' : ( 0 === $n ? 'Fallo' : 'Aviso' );
		$lines   = array();
		foreach ( $present as $k => $v ) {
			$lines[] = $k . ': ' . ( $v ? 'sí' : 'no' );
		}
		foreach ( array( 'content-security-policy', 'permissions-policy', 'strict-transport-security' ) as $extra ) {
			$lines[] = $extra . ': ' . ( isset( $h[ $extra ] ) ? 'sí' : 'no' );
		}
		$ev = implode( "\n", $lines );
		if ( ! $cdn && $n < 3 ) {
			$ev .= "\nLa petición no pasó por una CDN: si el sitio usa una que añade cabeceras, comprueba desde fuera.";
			return self::partial( 'SEG-08', sprintf( '%d de 3 cabeceras básicas', $n ), $ev );
		}
		return self::auto( 'SEG-08', sprintf( '%d de 3 cabeceras básicas', $n ), $state, $ev );
	}

	/**
	 * SEG-09: depuración visible y debug.log descargable.
	 */
	private static function debug_exposure() {
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		// WP_DEBUG_DISPLAY vale true por defecto; null deja la decisión al php.ini del servidor.
		$display = $debug && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		$log     = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		$path    = '';
		if ( true === $log || 'true' === $log || '1' === $log ) {
			$path = WP_CONTENT_DIR . '/debug.log';
		} elseif ( is_string( $log ) && '' !== $log && 'false' !== $log && '0' !== $log ) {
			$path = $log;
		}
		$public = false;
		$code   = 0;
		$abs    = wp_normalize_path( ABSPATH );
		$norm   = $path ? wp_normalize_path( $path ) : '';
		if ( $norm && file_exists( $path ) && 0 === strpos( $norm, $abs ) ) {
			$url    = site_url( '/' . ltrim( substr( $norm, strlen( $abs ) ), '/' ) );
			$res    = Inochi_Pulso_Http_Probe::request( $url, array( 'method' => 'HEAD' ) );
			$code   = $res['code'];
			$public = 200 === $code;
		}
		// display_errors del php.ini: decide cuando WP_DEBUG está apagado o WP_DEBUG_DISPLAY es null.
		$ini_display = strtolower( (string) get_cfg_var( 'display_errors' ) );
		$ini_on      = in_array( $ini_display, array( '1', 'on', 'true', 'stdout', 'stderr' ), true );
		$depends_ini = ! $debug || ( defined( 'WP_DEBUG_DISPLAY' ) && null === WP_DEBUG_DISPLAY );
		$lines       = array(
			'WP_DEBUG = ' . ( $debug ? 'true' : 'false' ),
			'WP_DEBUG_DISPLAY = ' . ( defined( 'WP_DEBUG_DISPLAY' ) ? var_export( WP_DEBUG_DISPLAY, true ) : 'no definido' ) . ( defined( 'WP_DEBUG_DISPLAY' ) && null === WP_DEBUG_DISPLAY ? ' (decide display_errors del servidor: revisar)' : '' ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			'WP_DEBUG_LOG = ' . ( defined( 'WP_DEBUG_LOG' ) ? ( is_string( WP_DEBUG_LOG ) ? 'ruta propia (' . basename( WP_DEBUG_LOG ) . ')' : var_export( WP_DEBUG_LOG, true ) ) : 'no definido' ) . ( $norm ? ' · archivo ' . ( file_exists( $path ) ? 'existe' : 'no existe' ) : '' ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			'display_errors en php.ini = ' . ( '' === $ini_display ? 'no definido' : $ini_display ),
		);
		if ( $code ) {
			$lines[] = 'Petición al debug.log desde la web → HTTP ' . $code . '.';
		}
		$fails = array();
		if ( $display ) {
			$fails[] = 'los errores se muestran en pantalla';
		}
		if ( $public ) {
			$fails[] = 'debug.log descargable desde la web';
		}
		if ( $fails ) {
			return self::auto( 'SEG-09', ucfirst( implode( ' y ', $fails ) ), 'Fallo', implode( "\n", $lines ) );
		}
		if ( $depends_ini && $ini_on ) {
			return self::partial( 'SEG-09', 'Errores posiblemente visibles (display_errors activo en el servidor)', implode( "\n", $lines ) . "\nWordPress deja la decisión al servidor y su php.ini muestra errores: compruébalo provocando una página con aviso o en la configuración PHP de Plesk." );
		}
		if ( $norm && 0 !== strpos( $norm, $abs ) ) {
			$lines[] = 'El debug.log está fuera de la raíz web: no se puede descargar.';
		}
		return self::auto( 'SEG-09', 'Depuración no expuesta', 'OK', implode( "\n", $lines ) );
	}

	/**
	 * La petición no obtuvo una respuesta útil (sin conexión, tiempo agotado o error 5xx).
	 */
	private static function failed( array $res ) {
		return 0 === $res['code'] || $res['code'] >= 500;
	}

	/**
	 * SEG-12: enumeración de usuarios (solo se cuenta, no se guardan nombres).
	 */
	private static function user_enumeration() {
		$rest  = Inochi_Pulso_Http_Probe::request( rest_url( 'wp/v2/users' ), array( 'body_limit' => 65536 ) );
		$users = 0;
		if ( 200 === $rest['code'] ) {
			$data  = json_decode( $rest['body'], true );
			$users = is_array( $data ) ? count(
				array_filter(
					$data,
					static function ( $u ) {
						return is_array( $u ) && isset( $u['slug'] );
					}
				)
			) : 0;
		}
		$author    = Inochi_Pulso_Http_Probe::request( add_query_arg( 'author', 1, home_url( '/' ) ), array( 'method' => 'HEAD' ) );
		$loc       = $author['headers']['location'] ?? '';
		$by_author = in_array( $author['code'], array( 301, 302 ), true ) && false !== strpos( $loc, '/author/' );
		$ev        = sprintf( "REST /wp/v2/users sin sesión → HTTP %d, %d usuarios visibles.\n?author=1 → HTTP %d%s.\nNo se guardan nombres de usuario.", $rest['code'], $users, $author['code'], $by_author ? ', redirige a un archivo de autor (expone el nombre)' : '' );
		$broken    = self::failed( $rest ) || self::failed( $author );
		if ( $broken && ! $users && ! $by_author ) {
			return self::partial( 'SEG-12', 'No se pudo comprobar desde el servidor', $ev . "\nEl propio sitio no respondió bien a la petición: comprueba desde fuera." );
		}
		if ( $users > 0 || $by_author ) {
			$ways = array();
			if ( $users > 0 ) {
				$ways[] = 'API REST';
			}
			if ( $by_author ) {
				$ways[] = '?author=';
			}
			return self::auto( 'SEG-12', 'Nombres expuestos por ' . implode( ' y ', $ways ), 'Aviso', $ev );
		}
		return self::auto( 'SEG-12', 'Sin enumeración de usuarios', 'OK', $ev );
	}

	/**
	 * SEG-13: XML-RPC.
	 */
	private static function xmlrpc() {
		// GET: un XML-RPC abierto responde 405 con su mensaje propio; no se envía nada por POST.
		$res  = Inochi_Pulso_Http_Probe::request( site_url( '/xmlrpc.php' ), array( 'body_limit' => 2048 ) );
		$jp   = Inochi_Pulso_Util::active_matching( array( 'jetpack' ) );
		$open = false !== stripos( $res['body'], 'XML-RPC server accepts POST requests only' );
		$ev   = 'GET /xmlrpc.php → HTTP ' . $res['code'] . ( $open ? ' con la respuesta de XML-RPC activo' : '' ) . '. Jetpack ' . ( $jp ? 'activo (necesita XML-RPC)' : 'no activo' ) . '.';
		if ( self::failed( $res ) ) {
			return self::partial( 'SEG-13', 'No se pudo comprobar desde el servidor', $ev . ' Comprueba desde fuera.' );
		}
		if ( ! $open ) {
			return self::auto( 'SEG-13', 'XML-RPC bloqueado (HTTP ' . $res['code'] . ')', 'OK', $ev );
		}
		if ( $jp ) {
			return self::partial( 'SEG-13', 'XML-RPC abierto (lo usa Jetpack)', $ev . ' Comprueba que está protegido.' );
		}
		return self::auto( 'SEG-13', 'XML-RPC abierto sin uso detectado', 'Aviso', $ev );
	}

	/**
	 * SEG-16: WAF y protección del acceso.
	 */
	private static function waf( array $again, $cdn ) {
		$h   = $again['headers'];
		$waf = array();
		if ( isset( $h['cf-ray'] ) || ( isset( $h['server'] ) && false !== stripos( $h['server'], 'cloudflare' ) ) ) {
			$waf[] = 'Cloudflare';
		}
		if ( isset( $h['x-sucuri-id'] ) || ( isset( $h['server'] ) && false !== stripos( $h['server'], 'sucuri' ) ) ) {
			$waf[] = 'Sucuri';
		}
		$fw_plugins = Inochi_Pulso_Util::active_matching( array( 'wordfence', 'ninjafirewall', 'all-in-one-wp-security-and-firewall', 'shield-security', 'wp-cerber', 'secupress', 'sucuri-scanner', 'better-wp-security', 'solid-security' ) );
		$waf        = array_merge( $waf, $fw_plugins );

		$login      = Inochi_Pulso_Http_Probe::request( wp_login_url(), array( 'method' => 'HEAD' ) );
		$loc        = $login['headers']['location'] ?? '';
		$access     = false !== stripos( $loc, 'cloudflareaccess.com' );
		$login_prot = Inochi_Pulso_Util::active_matching( Inochi_Pulso_Util::known( 'login' ) );
		if ( $access ) {
			$login_prot[] = 'Cloudflare Access';
		}
		$ev    = 'WAF: ' . ( $waf ? implode( ', ', $waf ) : 'no detectado' ) . ".\nProtección del acceso: " . ( $login_prot ? implode( ', ', $login_prot ) : 'no detectada' ) . ".\nwp-login.php → HTTP " . $login['code'] . ( $loc ? ' → ' . wp_parse_url( $loc, PHP_URL_HOST ) : '' ) . ".\n" . self::LOOPBACK_NOTE;
		$value = 'WAF: ' . ( $waf ? implode( ', ', array_unique( $waf ) ) : 'no detectado' ) . ' · acceso: ' . ( $login_prot ? implode( ', ', array_unique( $login_prot ) ) : 'sin protección detectada' );
		if ( $waf && $login_prot ) {
			return self::auto( 'SEG-16', $value, 'OK', $ev );
		}
		return self::partial( 'SEG-16', $value, $ev . ' Una CDN o reglas del servidor pueden proteger sin dejar señal aquí: confírmalo.' );
	}
}
