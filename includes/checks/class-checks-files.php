<?php
/**
 * SEG-14, SEG-15 y tamaño de uploads.
 *
 * Solo lee el sistema de archivos; no modifica nada.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones de archivos.
 */
class Inochi_Pulso_Checks_Files extends Inochi_Pulso_Checks_Base {

	const MAX_SECONDS = 20;
	const MAX_ENTRIES = 300000;

	/**
	 * Ejecuta el paso.
	 */
	public static function run() {
		Inochi_Pulso_Util::extend_time( 180 );
		// SEG-05 (PHP en uploads) no está en la 1.0: solo se usa el tamaño de uploads.
		list( , $uploads_size, $complete ) = self::scan_uploads();
		return array(
			'findings' => array(
				self::permissions(),
				self::leftovers(),
			),
			'payload'  => array(
				'gen_files' => array(
					'uploads_size'     => $uploads_size,
					'uploads_complete' => $complete,
				),
			),
		);
	}

	/**
	 * Cachés de plantillas compiladas que WPML guarda en uploads como PHP.
	 * Ruta exacta (relativa a uploads) => etiqueta.
	 */
	const KNOWN_CACHES = array(
		'#^/(cache/)?wpml/twig/[0-9a-f]{2}/[0-9a-f]{64}\.php$#' => 'WPML (plantillas Twig compiladas)',
	);

	/**
	 * Funciones y clases que una plantilla Twig compilada no llama.
	 */
	const DANGEROUS_CALLS = array( 'eval', 'assert', 'system', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'create_function', 'array_map', 'array_walk', 'addfunction', 'addfilter', 'addtest', 'addextension', 'setloader', 'addglobal', 'registerundefinedfunctioncallback', 'registerundefinedfiltercallback', 'array_reduce', 'usort', 'uasort', 'uksort', 'register_shutdown_function', 'register_tick_function', 'set_error_handler', 'set_exception_handler', 'iterator_apply', 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'hex2bin', 'convert_uudecode', 'unserialize', 'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'fputs', 'move_uploaded_file', 'copy', 'rename', 'unlink', 'chmod', 'touch', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'mail', 'putenv', 'getenv', 'ini_set', 'dl', 'extract', 'parse_str', 'preg_replace_callback', 'mb_ereg_replace', 'reflectionfunction', 'reflectionmethod', 'reflectionclass', 'splfileobject', 'pdo', 'mysqli', 'ziparchive', 'phar' );

	/**
	 * Textos que, como cadena, solo sirven de callback para ejecutar código.
	 */
	const DANGEROUS_STRINGS = array( 'eval', 'assert', 'system', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'create_function', 'call_user_func', 'call_user_func_array', 'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13', 'file_put_contents', 'move_uploaded_file', 'curl_exec', 'fsockopen', 'unserialize', 'putenv' );

	/**
	 * ¿Es una plantilla Twig compilada de WPML, sin nada que pueda ejecutar código arbitrario?
	 * Devuelve la etiqueta de la caché o '' (y entonces cuenta como PHP en uploads).
	 *
	 * Se analiza con el tokenizador de PHP: una sola clase __TwigTemplate_<hash> que extiende
	 * una plantilla Twig, y ninguna construcción capaz de ejecutar código o leer la petición.
	 *
	 * @param string $rel  Ruta relativa a uploads (empieza por /).
	 * @param string $path Ruta absoluta.
	 */
	public static function known_cache( $rel, $path ) {
		$label = '';
		foreach ( self::KNOWN_CACHES as $re => $l ) {
			if ( preg_match( $re, $rel ) ) {
				$label = $l;
				break;
			}
		}
		if ( '' === $label || ! function_exists( 'token_get_all' ) ) {
			return '';
		}
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size <= 0 || $size > 512000 ) {
			return '';
		}
		$code = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( 0 !== strpos( $code, '<?php' ) ) {
			return '';
		}
		try {
			$tokens = token_get_all( $code );
		} catch ( Throwable $e ) {
			return '';
		}
		$forbidden_tokens = array( T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO, T_DOLLAR_OPEN_CURLY_BRACES, T_CURLY_OPEN, T_HALT_COMPILER, T_GOTO );
		$sig              = array();
		$classes          = 0;
		$ok_class         = false;
		$opens            = 0;
		foreach ( $tokens as $i => $t ) {
			if ( is_array( $t ) ) {
				if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( T_OPEN_TAG === $t[0] ) {
					++$opens;
				}
				if ( in_array( $t[0], $forbidden_tokens, true ) ) {
					return '';
				}
				if ( T_VARIABLE === $t[0] && in_array( $t[1], array( '$GLOBALS', '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_ENV', '$_FILES', '$_SESSION', '$http_response_header' ), true ) ) {
					return ''; // Superglobales: una plantilla compilada no lee la petición.
				}
				$val = ltrim( strtolower( trim( (string) $t[1], "'\" " ) ), '\\' );
				if ( in_array( $t[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) && in_array( $val, self::DANGEROUS_CALLS, true ) ) {
					return '';
				}
				if ( in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && in_array( $val, self::DANGEROUS_STRINGS, true ) ) {
					return '';
				}
				$sig[] = array( $t[0], $t[1] );
			} else {
				if ( '`' === $t ) {
					return ''; // Comillas invertidas: ejecutan comandos.
				}
				$sig[] = array( 0, $t );
			}
		}
		if ( 1 !== $opens ) {
			return '';
		}
		$n        = count( $sig );
		$depth    = 0;
		$in_class = false;
		$closed   = false;
		$header   = array( T_OPEN_TAG, T_USE, T_NAMESPACE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_AS, T_NS_SEPARATOR, T_CLASS );
		for ( $k = 0; $k < $n; $k++ ) {
			list( $type, $text ) = $sig[ $k ];
			// Estructura: antes de la clase, solo «use» y «namespace»; después de cerrarla, nada.
			if ( $closed && T_CLOSE_TAG !== $type ) {
				return '';
			}
			if ( ! $in_class ) {
				if ( T_CLASS === $type ) {
					$in_class = true;
				} elseif ( ! in_array( $type, $header, true ) && ! ( 0 === $type && in_array( $text, array( ';', ',' ), true ) ) ) {
					return '';
				}
			} elseif ( 0 === $type && '{' === $text ) {
				++$depth;
			} elseif ( 0 === $type && '}' === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					$closed = true;
				}
			}
			// call_user_func(_array) solo como lo compila Twig: con el callable de una función, filtro o test registrado.
			if ( in_array( $type, array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) && in_array( ltrim( strtolower( $text ), '\\' ), array( 'call_user_func', 'call_user_func_array' ), true ) ) {
				$ok = '(' === ( $sig[ $k + 1 ][1] ?? '' ) && '$this' === ( $sig[ $k + 2 ][1] ?? '' ) && '->' === ( $sig[ $k + 3 ][1] ?? '' ) && 'env' === ( $sig[ $k + 4 ][1] ?? '' ) && '->' === ( $sig[ $k + 5 ][1] ?? '' ) && in_array( $sig[ $k + 6 ][1] ?? '', array( 'getFunction', 'getFilter', 'getTest' ), true );
				if ( ! $ok ) {
					return '';
				}
			}
			// Llamadas dinámicas: $f(…), 'cadena'(…), (…)(…), $a[…](…). Solo se admite «->getCallable()(…)» de Twig.
			if ( 0 === $type && '(' === $text && $k > 0 ) {
				list( $pt, $ptext ) = $sig[ $k - 1 ];
				$twig_callable      = ')' === $ptext && '(' === ( $sig[ $k - 2 ][1] ?? '' ) && 'getCallable' === ( $sig[ $k - 3 ][1] ?? '' );
				if ( ( T_VARIABLE === $pt || T_CONSTANT_ENCAPSED_STRING === $pt || ( 0 === $pt && in_array( $ptext, array( ')', ']', '}' ), true ) ) ) && ! $twig_callable ) {
					return '';
				}
			}
			if ( '$' === $text && 0 === $type ) {
				return ''; // Variables variables ($$x, ${…}).
			}
			if ( T_CLASS === $type ) {
				++$classes;
				$name = $sig[ $k + 1 ][1] ?? '';
				$ext  = ( $sig[ $k + 2 ][0] ?? 0 ) === T_EXTENDS ? (string) ( $sig[ $k + 3 ][1] ?? '' ) : '';
				if ( preg_match( '/^__TwigTemplate_[0-9a-f]{64}$/', (string) $name ) && preg_match( '/(^|\\\\)Twig\\\\Template$|^Twig_Template$|(^|\\\\)Template$/', $ext ) ) {
					$ok_class = true;
				}
			}
			if ( in_array( $type, array( T_FUNCTION, T_FN ), true ) && 0 === $classes ) {
				return ''; // Funciones fuera de la clase.
			}
		}
		return ( 1 === $classes && $ok_class ) ? $label : '';
	}

	/**
	 * Recorre uploads: tamaño total, archivos PHP y cachés conocidas.
	 *
	 * @return array{0: array, 1: float, 2: bool, 3: array}
	 */
	private static function scan_uploads() {
		$dir    = wp_get_upload_dir();
		$base   = $dir['basedir'];
		$found  = array();
		$caches = array();
		$size   = 0;
		$n      = 0;
		$start  = microtime( true );
		if ( ! is_dir( $base ) ) {
			return array( $found, 0, true, $caches );
		}
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ++$n > self::MAX_ENTRIES || ( microtime( true ) - $start ) > self::MAX_SECONDS ) {
					return array( $found, $size, false, $caches );
				}
				if ( ! $f->isFile() ) {
					continue;
				}
				$size += $f->getSize();
				$ext   = strtolower( $f->getExtension() );
				if ( in_array( $ext, array( 'php', 'phtml', 'phar', 'php5', 'php7', 'pht' ), true ) ) {
					if ( 'index.php' === $f->getFilename() && $f->getSize() < 200 ) {
						$c = (string) @file_get_contents( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						if ( false !== stripos( $c, 'silence' ) || '' === trim( str_replace( array( '<?php', '?>' ), '', $c ) ) ) {
							continue;
						}
					}
					$rel   = substr( wp_normalize_path( $f->getPathname() ), strlen( wp_normalize_path( $base ) ) );
					$cache = self::known_cache( $rel, $f->getPathname() );
					if ( '' !== $cache ) {
						$caches[ $cache ] = ( $caches[ $cache ] ?? 0 ) + 1;
						continue;
					}
					$found[] = $rel;
				}
			}
		} catch ( Exception $e ) {
			return array( $found, $size, false, $caches );
		}
		return array( $found, $size, true, $caches );
	}

	/**
	 * SEG-05 (parcial): PHP en uploads. Las sumas de verificación necesitan la API de WordPress.org.
	 */
	private static function php_in_uploads( array $found, $complete, array $caches = array() ) {
		$ev = 'Archivos ejecutables en uploads' . ( $complete ? '' : ' (recorrido incompleto por tiempo)' ) . ': ' . ( $found ? "\n" . implode( "\n", array_slice( $found, 0, 30 ) ) : 'ninguno.' );
		foreach ( $caches as $label => $n ) {
			$ev .= sprintf( "\nCaché de %s: %d archivos PHP con contenido revisado (no cuentan como problema).", $label, $n );
		}
		$ev .= "\nSumas de verificación del núcleo y los plugins: a mano con wp core verify-checksums y wp plugin verify-checksums --all (necesitan WordPress.org).";
		if ( $found ) {
			return self::partial( 'SEG-05', sprintf( '%d archivos PHP en uploads', count( $found ) ), $ev, 'Fallo' );
		}
		return self::partial( 'SEG-05', 'Sin PHP en uploads · checksums pendientes', $ev );
	}

	/**
	 * Ruta de wp-config.php (raíz o un nivel por encima).
	 */
	private static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return file_exists( $up ) ? $up : '';
	}

	/**
	 * SEG-14: permisos.
	 */
	private static function permissions() {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return self::partial( 'SEG-14', 'Servidor Windows', 'Los permisos Unix no aplican: revisar a mano.' );
		}
		$cfg   = self::wp_config_path();
		$mode  = $cfg ? fileperms( $cfg ) & 0777 : 0;
		$lines = array( 'wp-config.php: ' . ( $cfg ? sprintf( '%o', $mode ) : 'no encontrado' ) );

		$writable = array();
		$n        = 0;
		$start    = microtime( true );
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( ABSPATH, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$it->setMaxDepth( 3 );
			foreach ( $it as $f ) {
				if ( ++$n > self::MAX_ENTRIES || ( microtime( true ) - $start ) > self::MAX_SECONDS ) {
					$lines[] = 'Recorrido de permisos incompleto por tiempo.';
					break;
				}
				if ( $f->isLink() ) {
					continue;
				}
				if ( ( $f->getPerms() & 0002 ) && count( $writable ) < 30 ) {
					$rel        = '/' . ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( wp_normalize_path( ABSPATH ) ) ), '/' );
					$note       = '.tmb' === basename( $rel ) ? ' (miniaturas de un gestor de archivos tipo elFinder: identifica qué plugin la creó)' : '';
					$writable[] = sprintf( '%o %s %s%s', $f->getPerms() & 0777, $f->isDir() ? 'carpeta' : 'archivo', $rel, $note );
				}
			}
		} catch ( Exception $e ) {
			$lines[] = 'No se pudo recorrer todo el árbol de archivos.';
		}
		$lines[] = 'Escribibles por todos (hasta 3 niveles, rutas desde la raíz de la web): ' . ( $writable ? "\n" . implode( "\n", $writable ) : 'ninguno.' );

		if ( $writable || ( $mode & 0002 ) ) {
			$state = 'Fallo';
		} elseif ( $cfg && ( $mode & 0004 ) ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		$value = 'wp-config.php ' . ( $cfg ? sprintf( '%o', $mode ) : '?' ) . ' · ' . count( $writable ) . ' rutas escribibles por todos';
		return self::auto( 'SEG-14', $value, $state, implode( "\n", $lines ) );
	}

	/**
	 * SEG-15: copias, volcados e instaladores olvidados, y si se pueden descargar.
	 */
	private static function leftovers() {
		$pattern = '/\.(sql|sql\.gz|zip|tar|tar\.gz|tgz|gz|7z|rar|bak|old|orig|swp)$|(^|\/)(php)?info\.php$|(^|\/)adminer[^\/]*\.php$|(^|\/)wp-config\.php[^\/]+$|(^|\/)\.env$|(^|\/)phpinfo\.php$|(^|\/)installer\.php$/i';
		$root    = wp_normalize_path( ABSPATH );
		$cands   = array();
		$dirs    = array( $root );
		foreach ( (array) glob( $root . '*', GLOB_ONLYDIR ) as $d ) {
			$dirs[] = wp_normalize_path( $d ) . '/';
		}
		$dirs[] = wp_normalize_path( WP_CONTENT_DIR ) . '/';
		foreach ( array_unique( $dirs ) as $d ) {
			foreach ( (array) scandir( $d ) as $name ) {
				if ( '.' === $name || '..' === $name || ! is_file( $d . $name ) ) {
					continue;
				}
				$rel = substr( $d . $name, strlen( $root ) );
				if ( preg_match( $pattern, $rel ) ) {
					$cands[] = $rel;
				}
			}
		}
		$git        = is_dir( $root . '.git' );
		$lines      = array();
		$exposed    = array();
		$executable = array();
		$unknown    = 0;
		foreach ( array_slice( $cands, 0, 25 ) as $rel ) {
			$size = Inochi_Pulso_Util::bytes( (int) @filesize( $root . $rel ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( preg_match( '/\.(php\d?|phtml|phar)$/i', $rel ) ) {
				// Nunca se piden: pedirlos los ejecutaría (un installer.php escribe en disco).
				$executable[] = $rel;
				$lines[]      = $rel . ' · ' . $size . ' · ejecutable desde la web (no se ha pedido para no ejecutarlo)';
				continue;
			}
			$res     = Inochi_Pulso_Http_Probe::request( site_url( '/' . str_replace( '%2F', '/', rawurlencode( $rel ) ) ), array( 'method' => 'HEAD' ) );
			$lines[] = $rel . ' → HTTP ' . $res['code'] . ' · ' . $size;
			if ( 200 === $res['code'] ) {
				$exposed[] = $rel;
			} elseif ( 0 === $res['code'] || $res['code'] >= 500 ) {
				++$unknown;
			}
		}
		if ( $git ) {
			$res     = Inochi_Pulso_Http_Probe::request( site_url( '/.git/config' ), array( 'method' => 'HEAD' ) );
			$lines[] = '.git/ en la raíz → /.git/config HTTP ' . $res['code'];
			if ( 200 === $res['code'] ) {
				$exposed[] = '.git/config';
			} elseif ( 0 === $res['code'] || $res['code'] >= 500 ) {
				++$unknown;
			}
		}
		if ( $exposed ) {
			return self::auto( 'SEG-15', sprintf( '%d %s desde la web: %s', count( $exposed ), 1 === count( $exposed ) ? 'archivo descargable' : 'archivos descargables', implode( ', ', array_slice( $exposed, 0, 5 ) ) ), 'Fallo', implode( "\n", $lines ) );
		}
		if ( $executable ) {
			return self::partial( 'SEG-15', sprintf( '%d scripts sobrantes en la web: %s', count( $executable ), implode( ', ', array_slice( $executable, 0, 5 ) ) ), implode( "\n", $lines ) . "\nUn instalador, Adminer o phpinfo en la raíz suele ser accesible: confirma desde fuera sin abrirlo (o comprueba si hay reglas que lo bloqueen) y bórralo.", 'Fallo' );
		}
		if ( $unknown ) {
			return self::partial( 'SEG-15', sprintf( '%d archivos sobrantes, accesibilidad sin comprobar', count( $cands ) + ( $git ? 1 : 0 ) ), implode( "\n", $lines ) . "\nEl propio sitio no respondió: comprueba desde fuera." );
		}
		if ( $cands || $git ) {
			return self::auto( 'SEG-15', sprintf( '%d archivos sobrantes, no accesibles', count( $cands ) + ( $git ? 1 : 0 ) ), 'Aviso', implode( "\n", $lines ) );
		}
		return self::auto( 'SEG-15', 'Sin archivos sobrantes', 'OK', 'Revisada la raíz, sus subcarpetas de primer nivel y wp-content.' );
	}
}
