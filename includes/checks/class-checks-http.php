<?php
/**
 * HTTP checks against the site itself (loopback):
 * REN-01 (TTFB), REN-05, SEG-07, SEG-08, SEG-09, SEG-12 and SEG-13.
 *
 * Requests leave from the server itself: they measure server time without the
 * visitor's network, and they may skip the CDN if the server resolves its own
 * domain locally. That is why a state is only stated when the signal is clear.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP checks.
 */
class Inochi_Pulso_Checks_Http extends Inochi_Pulso_Checks_Base {

	/**
	 * Note added to the evidence of loopback measurements.
	 */
	private static function loopback_note() {
		return __( 'Measured from the server itself (loopback): without the visitor’s network latency.', 'inochi-pulso' );
	}

	/**
	 * REN-01 step: time to first byte on 5 URLs.
	 */
	public static function run_ttfb() {
		Inochi_Pulso_Util::extend_time( 180 );
		$urls   = self::sample_urls();
		$rows   = array();
		$is_woo = Inochi_Pulso_Util::has_woocommerce() && ( isset( $urls['cart'] ) || isset( $urls['checkout'] ) ); // A catalogue without cart or checkout is measured like any site.
		$errors = array();
		foreach ( $urls as $key => $url ) {
			$row = array(
				'label'   => self::url_label( $key ),
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
					$errors[] = $row['label'] . ': ' . $v['error'];
				}
				if ( $i > 0 ) {
					$row['cache'] = self::cache_signal( $v['headers'] ) ?: $row['cache'];
				}
			}
			$rows[] = $row;
		}

		// State: "origin" median of cart and checkout (without a store: all 5).
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
		$h     = array( __( 'page', 'inochi-pulso' ), 'HTTP', __( 'visitor', 'inochi-pulso' ), __( 'origin', 'inochi-pulso' ), __( 'cache', 'inochi-pulso' ) );
		$table = array();
		foreach ( $rows as $row ) {
			$table[] = array(
				$h[0] => $row['label'] . ' ' . $row['path'],
				$h[1] => $row['code'],
				$h[2] => self::fmt( self::median( $row['visitor'] ) ),
				$h[3] => self::fmt( self::median( $row['origin'] ) ),
				$h[4] => $row['cache'] ? $row['cache'] : '-',
			);
		}
		$ev = Inochi_Pulso_Util::text_table( $table, $h ) . "\n" . __( 'Medians in seconds of 3 requests. "Origin" adds ?inochi_pulso=random to skip the cache.', 'inochi-pulso' ) . "\n" . self::loopback_note() . ' ' . __( 'If it is close to a threshold, measure from outside too.', 'inochi-pulso' );
		if ( $redirects ) {
			/* translators: %s: list of pages. */
			$ev .= "\n" . sprintf( __( 'Not counted for the state because they answer with a redirect (for example, checkout with an empty cart): %s.', 'inochi-pulso' ), implode( ', ', $redirects ) );
		}
		if ( $errors ) {
			/* translators: %s: list of errors. */
			$ev .= "\n" . sprintf( __( 'Errors: %s', 'inochi-pulso' ), implode( '; ', array_unique( $errors ) ) );
		}

		if ( ! $pool ) {
			$finding = self::partial( 'REN-01', __( 'Could not be measured from the server', 'inochi-pulso' ), $ev );
		} else {
			$avg = array_sum( $pool ) / count( $pool );
			if ( ! $is_woo ) {
				$basis = __( 'from the server, without cache, URLs without redirect', 'inochi-pulso' );
			} elseif ( $redirects ) {
				$basis = __( 'from the server, without cache, cart', 'inochi-pulso' );
			} else {
				$basis = __( 'from the server, without cache, cart and checkout', 'inochi-pulso' );
			}
			$finding = self::auto( 'REN-01', sprintf( '%s s (%s)', Inochi_Pulso_Util::num( $avg, 2 ), $basis ), Inochi_Pulso_Util::grade( $avg, 0.6, 1.2 ), $ev );
		}

		return array(
			'findings' => array( $finding ),
			'payload'  => array(),
		);
	}

	/**
	 * Headers and exposure step.
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
			),
			'payload'  => array(),
		);
	}

	/**
	 * Label of a sample URL.
	 *
	 * @param string $key Key.
	 */
	private static function url_label( $key ) {
		$labels = array(
			'home'     => __( 'Home', 'inochi-pulso' ),
			'category' => __( 'Category', 'inochi-pulso' ),
			'single'   => __( 'Product / post', 'inochi-pulso' ),
			'cart'     => __( 'Cart', 'inochi-pulso' ),
			'checkout' => __( 'Checkout', 'inochi-pulso' ),
			'page1'    => __( 'Page', 'inochi-pulso' ),
			'page2'    => __( 'Page', 'inochi-pulso' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/**
	 * Five representative URLs: key => URL.
	 */
	public static function sample_urls() {
		$urls = array( 'home' => home_url( '/' ) );
		if ( Inochi_Pulso_Util::has_woocommerce() ) {
			$cat              = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			$cat_url          = ( ! is_wp_error( $cat ) && $cat ) ? get_term_link( $cat[0] ) : '';
			$urls['category'] = ( $cat_url && ! is_wp_error( $cat_url ) ) ? $cat_url : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
			$prod             = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$urls['single']   = $prod ? get_permalink( $prod[0] ) : home_url( '/' );
			// Only pages that exist: without them WooCommerce returns the home page URL.
			if ( wc_get_page_id( 'cart' ) > 0 ) {
				$urls['cart'] = wc_get_cart_url();
			}
			if ( wc_get_page_id( 'checkout' ) > 0 ) {
				$urls['checkout'] = wc_get_checkout_url();
			}
		} else {
			$cats             = get_categories(
				array(
					'hide_empty' => true,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			$urls['category'] = $cats ? get_category_link( $cats[0] ) : home_url( '/' );
			$post             = get_posts(
				array(
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$urls['single']   = $post ? get_permalink( $post[0] ) : home_url( '/' );
			$page             = get_posts(
				array(
					'post_type'      => 'page',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			$urls['page1']    = $page ? get_permalink( $page[0] ) : home_url( '/' );
			$pages            = get_posts(
				array(
					'post_type'      => 'page',
					'posts_per_page' => 2,
					'fields'         => 'ids',
				)
			);
			$urls['page2']    = isset( $pages[1] ) ? get_permalink( $pages[1] ) : home_url( '/' );
		}
		return $urls;
	}

	/**
	 * Relative path of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function path_of( $url ) {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return ( '' === $path ? '/' : $path ) . ( $query ? '?' . $query : '' );
	}

	/**
	 * Median of the numeric values.
	 *
	 * @param array $values Values.
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
	 * Seconds with 2 decimals or "-".
	 *
	 * @param float|null $v Value.
	 */
	private static function fmt( $v ) {
		return null === $v ? '-' : Inochi_Pulso_Util::num( $v, 2 );
	}

	/**
	 * Cache signal in the headers, for example "HIT (cf-cache-status)", or ''.
	 *
	 * @param array $h Headers.
	 */
	private static function cache_signal( array $h ) {
		// A HIT in any layer wins: behind a CDN that does not cache HTML (DYNAMIC), the
		// hosting's own page cache (Kinsta, SiteGround, nginx…) still answers HIT.
		$first = '';
		foreach ( array( 'cf-cache-status', 'x-cache', 'x-litespeed-cache', 'x-proxy-cache', 'x-cache-status', 'x-nginx-cache', 'x-fastcgi-cache', 'x-sg-cache', 'x-kinsta-cache', 'x-cache-enabled' ) as $name ) {
			if ( isset( $h[ $name ] ) ) {
				$val = strtoupper( $h[ $name ] );
				if ( false !== strpos( $val, 'HIT' ) || 'TRUE' === $val ) {
					return 'HIT (' . $name . ')';
				}
				$first = $first ? $first : $val . ' (' . $name . ')';
			}
		}
		if ( $first ) {
			return $first;
		}
		if ( isset( $h['age'] ) && (int) $h['age'] > 0 ) {
			return 'HIT (age ' . (int) $h['age'] . ')';
		}
		return '';
	}

	/**
	 * The response went through a recognisable CDN or proxy.
	 *
	 * @param array $h Headers.
	 */
	private static function through_cdn( array $h ) {
		return isset( $h['cf-ray'] ) || isset( $h['x-sucuri-id'] ) || isset( $h['x-served-by'] ) || isset( $h['x-amz-cf-id'] ) || isset( $h['x-fastly-request-id'] ) || isset( $h['x-akamai-transformed'] ) || ( isset( $h['server'] ) && preg_match( '/cloudflare|sucuri|bunny|fastly|akamai/i', $h['server'] ) );
	}

	/**
	 * REN-05: page cache.
	 *
	 * @param array $first First response.
	 * @param array $again Second response.
	 * @param bool  $cdn   Through a CDN.
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
		$none    = __( 'none', 'inochi-pulso' );
		$ev      = sprintf(
			/* translators: 1: cache headers, 2: cache marker, 3: cache plugins, 4: CDN status. */
			__( 'Two requests to the home page. Cache headers in the second one: %1$s. Cache marker in the HTML: %2$s. Active cache plugins: %3$s. Request %4$s.', 'inochi-pulso' ),
			$signal ? $signal : $none,
			$marker ? $marker : $none,
			$plugins ? implode( ', ', $plugins ) : $none,
			$cdn ? __( 'through a CDN', 'inochi-pulso' ) : __( 'without CDN signals', 'inochi-pulso' )
		) . "\n" . self::loopback_note();
		if ( 0 === strpos( $signal, 'HIT' ) || $marker ) {
			return self::auto( 'REN-05', __( 'Home page served from cache', 'inochi-pulso' ) . ( $signal ? ': ' . $signal : ' (' . $marker . ')' ), 'OK', $ev );
		}
		if ( $cdn && ! $plugins ) {
			/* translators: %s: cache header value. */
			return self::auto( 'REN-05', sprintf( __( 'No page cache: the CDN answers %s', 'inochi-pulso' ), $signal ? $signal : __( 'without HIT', 'inochi-pulso' ) ), 'Fallo', $ev );
		}
		$value = $plugins ? __( 'Cache plugin active without a HIT signal', 'inochi-pulso' ) : __( 'No page cache signals from the server', 'inochi-pulso' );
		return self::partial( 'REN-05', $value, $ev . ' ' . __( 'Check from outside the server, for example with curl -I.', 'inochi-pulso' ) );
	}

	/**
	 * SEG-07: HTTPS, redirect, certificate and HSTS (mixed content is not checked).
	 *
	 * @param array $again Home page response.
	 */
	private static function https( array $again ) {
		$home  = home_url( '/' );
		$https = 'https' === wp_parse_url( $home, PHP_URL_SCHEME );
		$host  = (string) wp_parse_url( $home, PHP_URL_HOST );
		/* translators: %s: HTTPS or HTTP. */
		$lines = array( sprintf( __( 'Site URL: %s.', 'inochi-pulso' ), $https ? 'HTTPS' : 'HTTP' ) );
		$fail  = array();
		if ( ! $https ) {
			$fail[] = __( 'the site does not use HTTPS', 'inochi-pulso' );
		}

		$redir       = Inochi_Pulso_Http_Probe::request(
			set_url_scheme( $home, 'http' ),
			array(
				'method'   => 'HEAD',
				'optional' => true, // A closed http:// does not mean the site does not answer.
			)
		);
		$loc         = $redir['headers']['location'] ?? '';
		$ok_redirect = in_array( $redir['code'], array( 301, 302, 307, 308 ), true ) && 0 === stripos( $loc, 'https://' );
		$lines[]     = 'http:// → HTTP ' . $redir['code'] . ( $loc ? ' → ' . $loc : '' ) . ( $redir['error'] ? ' (' . $redir['error'] . ')' : '' ) . '.';
		if ( $https && 200 === $redir['code'] ) {
			$fail[] = __( 'http:// answers 200 without redirecting', 'inochi-pulso' );
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
					$days = (int) floor( ( $parsed['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
					/* translators: 1: date, 2: days left. */
					$cert_line = sprintf( __( 'Certificate valid until %1$s (%2$d days)', 'inochi-pulso' ), gmdate( 'Y-m-d', $parsed['validTo_time_t'] ), $days );
					/* translators: %s: certificate issuer. */
					$lines[] = $cert_line . ( isset( $parsed['issuer']['O'] ) ? ', ' . sprintf( __( 'issued by %s', 'inochi-pulso' ), $parsed['issuer']['O'] ) : '' ) . '.';
				}
				fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			} else {
				/* translators: %s: error. */
				$lines[] = sprintf( __( 'Could not read the certificate: %s', 'inochi-pulso' ), $errstr );
			}
		}
		if ( null !== $days && $days < 0 ) {
			$fail[] = __( 'expired certificate', 'inochi-pulso' );
		}
		$hsts    = $again['headers']['strict-transport-security'] ?? '';
		$lines[] = 'HSTS: ' . ( $hsts ? $hsts : __( 'no', 'inochi-pulso' ) ) . '.';
		$lines[] = __( 'Mixed content is not checked: look at the browser console on the home page and the checkout.', 'inochi-pulso' );

		$value  = $https ? 'HTTPS' : __( 'No HTTPS', 'inochi-pulso' );
		$value .= ' · ' . ( $ok_redirect ? __( 'redirects', 'inochi-pulso' ) : __( 'redirect not confirmed', 'inochi-pulso' ) );
		/* translators: %d: days until the certificate expires. */
		$value .= null !== $days ? ' · ' . sprintf( __( 'certificate %d days', 'inochi-pulso' ), $days ) : '';
		$value .= ' · ' . ( $hsts ? 'HSTS' : ( preg_match( '/\.(?:dev|app|page|new|day|foo|zip|mov|bank|insurance)$/i', $host ) ? __( 'HSTS preload by domain', 'inochi-pulso' ) : __( 'no HSTS', 'inochi-pulso' ) ) );
		if ( $fail ) {
			/* translators: %s: list of problems. */
			return self::auto( 'SEG-07', $value, 'Fallo', sprintf( __( 'Fail: %s.', 'inochi-pulso' ), implode( ', ', $fail ) ) . "\n" . implode( "\n", $lines ) );
		}
		$preload = (bool) preg_match( '/\.(?:dev|app|page|new|day|foo|zip|mov|bank|insurance)$/i', $host ); // TLDs on the browsers' HSTS preload list.
		if ( ! $hsts && $preload ) {
			$lines[] = __( 'Without the HSTS header, but the domain ending is on the browsers’ HSTS preload list: browsers only open it over HTTPS.', 'inochi-pulso' );
		}
		$warn = array();
		if ( ! $ok_redirect ) {
			$warn[] = __( 'the redirect from http:// could not be confirmed', 'inochi-pulso' );
		}
		if ( null === $days ) {
			$warn[] = __( 'the certificate could not be read', 'inochi-pulso' );
		} elseif ( $days <= 15 ) {
			$warn[] = __( 'the certificate expires in 15 days or less: make sure it renews automatically', 'inochi-pulso' );
		}
		if ( ! $hsts && ! $preload ) {
			$warn[] = __( 'no HSTS', 'inochi-pulso' );
		}
		if ( $warn ) {
			/* translators: %s: list of problems. */
			return self::auto( 'SEG-07', $value, 'Aviso', sprintf( __( 'Warning: %s.', 'inochi-pulso' ), implode( ', ', $warn ) ) . "\n" . implode( "\n", $lines ) );
		}
		return self::auto( 'SEG-07', $value, 'OK', implode( "\n", $lines ) );
	}

	/**
	 * SEG-08: security headers.
	 *
	 * @param array $again Home page response.
	 * @param bool  $cdn   Through a CDN.
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
			$lines[] = $k . ': ' . ( $v ? __( 'yes', 'inochi-pulso' ) : __( 'no', 'inochi-pulso' ) );
		}
		foreach ( array( 'content-security-policy', 'permissions-policy', 'strict-transport-security' ) as $extra ) {
			$lines[] = $extra . ': ' . ( isset( $h[ $extra ] ) ? __( 'yes', 'inochi-pulso' ) : __( 'no', 'inochi-pulso' ) );
		}
		$ev = implode( "\n", $lines );
		/* translators: %d: number of headers present. */
		$value = sprintf( __( '%d of 3 basic headers', 'inochi-pulso' ), $n );
		if ( ! $cdn && $n < 3 ) {
			$ev .= "\n" . __( 'The request did not go through a CDN: if the site uses one that adds headers, check from outside.', 'inochi-pulso' );
			return self::partial( 'SEG-08', $value, $ev );
		}
		return self::auto( 'SEG-08', $value, $state, $ev );
	}

	/**
	 * SEG-09: visible debugging and downloadable debug.log.
	 */
	private static function debug_exposure() {
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		// WP_DEBUG_DISPLAY is true by default; null leaves the decision to the server's php.ini.
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
		// php.ini display_errors decides when WP_DEBUG is off or WP_DEBUG_DISPLAY is null.
		$ini_display = strtolower( (string) get_cfg_var( 'display_errors' ) );
		$ini_on      = in_array( $ini_display, array( '1', 'on', 'true', 'stdout', 'stderr' ), true );
		$depends_ini = ! $debug || ( defined( 'WP_DEBUG_DISPLAY' ) && null === WP_DEBUG_DISPLAY );
		$undef       = __( 'not defined', 'inochi-pulso' );
		$log_desc    = $undef;
		if ( defined( 'WP_DEBUG_LOG' ) ) {
			/* translators: %s: file name. */
			$log_desc = is_string( WP_DEBUG_LOG ) ? sprintf( __( 'custom path (%s)', 'inochi-pulso' ), basename( WP_DEBUG_LOG ) ) : wp_json_encode( WP_DEBUG_LOG );
		}
		$lines = array(
			'WP_DEBUG = ' . ( $debug ? 'true' : 'false' ),
			'WP_DEBUG_DISPLAY = ' . ( defined( 'WP_DEBUG_DISPLAY' ) ? wp_json_encode( WP_DEBUG_DISPLAY ) : $undef ) . ( defined( 'WP_DEBUG_DISPLAY' ) && null === WP_DEBUG_DISPLAY ? ' (' . __( 'the server’s display_errors decides', 'inochi-pulso' ) . ')' : '' ),
			'WP_DEBUG_LOG = ' . $log_desc . ( $norm ? ' · ' . ( file_exists( $path ) ? __( 'file exists', 'inochi-pulso' ) : __( 'file does not exist', 'inochi-pulso' ) ) : '' ),
			'php.ini display_errors = ' . ( '' === $ini_display ? $undef : $ini_display ),
		);
		if ( $code ) {
			/* translators: %d: HTTP status code. */
			$lines[] = sprintf( __( 'Request to debug.log from the web → HTTP %d.', 'inochi-pulso' ), $code );
		}
		if ( $display && $public ) {
			$value = __( 'Errors shown on screen and debug.log downloadable from the web', 'inochi-pulso' );
		} elseif ( $display ) {
			$value = __( 'Errors shown on screen', 'inochi-pulso' );
		} elseif ( $public ) {
			$value = __( 'debug.log downloadable from the web', 'inochi-pulso' );
		} else {
			$value = '';
		}
		if ( '' !== $value ) {
			return self::auto( 'SEG-09', $value, 'Fallo', implode( "\n", $lines ) );
		}
		if ( $depends_ini && $ini_on ) {
			return self::partial( 'SEG-09', __( 'Errors possibly visible (display_errors on in the server)', 'inochi-pulso' ), implode( "\n", $lines ) . "\n" . __( 'WordPress leaves the decision to the server and its php.ini shows errors: check the PHP settings in the hosting panel.', 'inochi-pulso' ) );
		}
		if ( $norm && 0 !== strpos( $norm, $abs ) ) {
			$lines[] = __( 'debug.log is outside the web root: it cannot be downloaded.', 'inochi-pulso' );
		}
		return self::auto( 'SEG-09', __( 'Debugging not exposed', 'inochi-pulso' ), 'OK', implode( "\n", $lines ) );
	}

	/**
	 * The request got no useful answer (no connection, timeout or 5xx error).
	 *
	 * @param array $res Response.
	 */
	private static function failed( array $res ) {
		return 0 === $res['code'] || $res['code'] >= 500;
	}

	/**
	 * SEG-12: user enumeration (only counted; names are not stored).
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
		/* translators: 1: HTTP status code, 2: number of visible users. */
		$ev     = sprintf( __( 'REST /wp/v2/users without a session → HTTP %1$d, %2$d users visible.', 'inochi-pulso' ), $rest['code'], $users ) . "\n";
		$ev    .= '?author=1 → HTTP ' . $author['code'] . ( $by_author ? ', ' . __( 'redirects to an author archive (exposes the name)', 'inochi-pulso' ) : '' ) . ".\n";
		$ev    .= __( 'Usernames are not stored.', 'inochi-pulso' );
		$broken = self::failed( $rest ) || self::failed( $author );
		if ( $broken && ! $users && ! $by_author ) {
			return self::partial( 'SEG-12', __( 'Could not be checked from the server', 'inochi-pulso' ), $ev . "\n" . __( 'The site did not answer the request properly: check from outside.', 'inochi-pulso' ) );
		}
		if ( $users > 0 && $by_author ) {
			return self::auto( 'SEG-12', __( 'Names exposed by the REST API and ?author=', 'inochi-pulso' ), 'Aviso', $ev );
		}
		if ( $users > 0 ) {
			return self::auto( 'SEG-12', __( 'Names exposed by the REST API', 'inochi-pulso' ), 'Aviso', $ev );
		}
		if ( $by_author ) {
			return self::auto( 'SEG-12', __( 'Names exposed by ?author=', 'inochi-pulso' ), 'Aviso', $ev );
		}
		return self::auto( 'SEG-12', __( 'No user enumeration', 'inochi-pulso' ), 'OK', $ev );
	}

	/**
	 * SEG-13: XML-RPC.
	 */
	private static function xmlrpc() {
		// GET: an open XML-RPC answers 405 with its own message; nothing is sent by POST.
		$res  = Inochi_Pulso_Http_Probe::request( site_url( '/xmlrpc.php' ), array( 'body_limit' => 2048 ) );
		$jp   = Inochi_Pulso_Util::active_matching( array( 'jetpack' ) );
		$open = false !== stripos( $res['body'], 'XML-RPC server accepts POST requests only' );
		$ev   = 'GET /xmlrpc.php → HTTP ' . $res['code'] . ( $open ? ' ' . __( 'with the answer of an active XML-RPC', 'inochi-pulso' ) : '' ) . '. Jetpack ' . ( $jp ? __( 'active (needs XML-RPC)', 'inochi-pulso' ) : __( 'not active', 'inochi-pulso' ) ) . '.';
		if ( self::failed( $res ) ) {
			return self::partial( 'SEG-13', __( 'Could not be checked from the server', 'inochi-pulso' ), $ev . ' ' . __( 'Check from outside.', 'inochi-pulso' ) );
		}
		if ( ! $open ) {
			/* translators: %d: HTTP status code. */
			return self::auto( 'SEG-13', sprintf( __( 'XML-RPC blocked (HTTP %d)', 'inochi-pulso' ), $res['code'] ), 'OK', $ev );
		}
		if ( $jp ) {
			return self::partial( 'SEG-13', __( 'XML-RPC open (used by Jetpack)', 'inochi-pulso' ), $ev . ' ' . __( 'Check that it is protected.', 'inochi-pulso' ) );
		}
		return self::auto( 'SEG-13', __( 'XML-RPC open with no use detected', 'inochi-pulso' ), 'Aviso', $ev );
	}


}
