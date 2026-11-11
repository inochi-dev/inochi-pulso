<?php
/**
 * REN-08: atribución del autoload por plugin y autoload huérfano.
 *
 * Busca el nombre de cada opción pesada en el código del núcleo, los plugins
 * (activos e inactivos), los mu-plugins y los temas. Sin coincidencias = de un
 * plugin ya borrado.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Atribución de autoload.
 */
class Inochi_Pulso_Checks_Autoload extends Inochi_Pulso_Checks_Base {

	const TOP          = 30;
	const MAX_BYTES    = 314572800; // 300 MB de código como máximo.
	const MAX_SECONDS  = 25;
	const GENERIC_PREF = array( 'wp_', 'the_', 'my_', 'site_', 'widget_', 'theme_', 'user_', 'db_', 'auto_', 'default_', 'page_', 'post_', 'use_', 'show_', 'can_', 'new_', 'old_', 'last_', 'cache_' );

	/**
	 * Opciones del núcleo que no aparecen literalmente en su código.
	 */
	const CORE_PREFIXES = array( 'widget_', 'theme_mods_', '_transient_', '_site_transient_' );

	/**
	 * Ejecuta el paso.
	 */
	public static function run() {
		global $wpdb;
		Inochi_Pulso_Util::extend_time( 120 );

		$in = Inochi_Pulso_Checks_Database::autoload_in();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in ya preparado.
		$options = $wpdb->get_results( $wpdb->prepare( "SELECT option_name AS name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ({$in}) ORDER BY bytes DESC LIMIT %d", self::TOP ), ARRAY_A );

		$needles = array();
		foreach ( (array) $options as $opt ) {
			$name = $opt['name'];
			if ( $name === $wpdb->prefix . 'user_roles' ) {
				continue; // Opción del núcleo con prefijo de tabla.
			}
			foreach ( self::CORE_PREFIXES as $cp ) {
				if ( 0 === strpos( $name, $cp ) ) {
					continue 2;
				}
			}
			$needles[ $name ] = self::prefix_of( $name );
		}

		list( $matches, $complete ) = self::search( $needles );
		$plugins                    = Inochi_Pulso_Util::plugins();
		$rows                       = array();
		$orphan                     = 0;

		foreach ( (array) $options as $opt ) {
			$name                        = $opt['name'];
			$bytes                       = (int) $opt['bytes'];
			list( $owner, $state, $how ) = self::attribute( $name, $matches, $plugins );
			if ( in_array( $state, array( 'Borrado', 'Inactivo' ), true ) ) {
				$orphan += $bytes;
			}
			$rows[] = array(
				'option' => $name,
				'bytes'  => $bytes,
				'owner'  => $owner,
				'state'  => $state,
				'note'   => $how,
			);
		}

		$kb    = $orphan / 1024;
		$state = Inochi_Pulso_Util::grade( $kb, 100, 500 );
		$value = sprintf( '%s KB huérfanos en las %d opciones más pesadas', Inochi_Pulso_Util::num( $kb ), count( $rows ) );

		$ev_rows = array();
		foreach ( array_slice( $rows, 0, 10 ) as $r ) {
			$ev_rows[] = array(
				'opción' => $r['option'],
				'bytes'  => Inochi_Pulso_Util::num( $r['bytes'] ),
				'de'     => $r['owner'],
				'estado' => $r['state'],
			);
		}
		$ev = "Atribución buscando el nombre de cada opción en el código instalado:\n" . Inochi_Pulso_Util::text_table( $ev_rows, array( 'opción', 'bytes', 'de', 'estado' ) );
		if ( ! $complete ) {
			$ev .= "\nBúsqueda incompleta por límite de tiempo o tamaño: revisa a mano las marcadas como «Borrado».";
		}

		$finding = $complete ? self::auto( 'REN-08', $value, $state, $ev ) : self::partial( 'REN-08', $value, $ev );

		return array(
			'findings' => array( $finding ),
			'payload'  => array( 'autoload' => $rows ),
		);
	}

	/**
	 * Prefijo útil para atribuir nombres construidos dinámicamente (stats_cache_daily → stats_).
	 */
	private static function prefix_of( $name ) {
		$pos = strpos( $name, '_', 3 );
		if ( false === $pos ) {
			return '';
		}
		$prefix = substr( $name, 0, $pos + 1 );
		return in_array( $prefix, self::GENERIC_PREF, true ) ? '' : $prefix;
	}

	/**
	 * Directorios de código a revisar: ruta => dueño.
	 */
	private static function sources() {
		$src  = array(
			ABSPATH . WPINC      => 'core',
			ABSPATH . 'wp-admin' => 'core',
		);
		$self = basename( dirname( INOCHI_PULSO_FILE ) );
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( basename( $dir ) === $self ) {
				continue; // El código de Pulso nombra opciones ajenas.
			}
			$src[ $dir ] = 'plugin:' . basename( $dir );
		}
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*.php' ) as $file ) {
			$src[ $file ] = 'plugin:' . basename( $file, '.php' );
		}
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$src[ WPMU_PLUGIN_DIR ] = 'mu';
		}
		foreach ( (array) glob( get_theme_root() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$src[ $dir ] = 'theme:' . basename( $dir );
		}
		return $src;
	}

	/**
	 * Busca nombres y prefijos en los archivos PHP.
	 *
	 * @param array $needles nombre => prefijo.
	 * @return array{0: array, 1: bool} coincidencias [nombre => ['full' => dueños, 'prefix' => dueños]] y si la búsqueda terminó.
	 */
	private static function search( array $needles ) {
		$matches = array();
		foreach ( $needles as $name => $prefix ) {
			$matches[ $name ] = array(
				'full'   => array(),
				'prefix' => array(),
			);
		}
		if ( ! $needles ) {
			return array( $matches, true );
		}
		$start = microtime( true );
		$read  = 0;
		foreach ( self::sources() as $path => $owner ) {
			$files = is_file( $path ) ? array( $path ) : self::php_files( $path, $start + self::MAX_SECONDS );
			foreach ( $files as $file ) {
				if ( ( microtime( true ) - $start ) > self::MAX_SECONDS || $read > self::MAX_BYTES ) {
					return array( $matches, false );
				}
				$content = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lectura local.
				if ( false === $content ) {
					continue;
				}
				$read += strlen( $content );
				foreach ( $needles as $name => $prefix ) {
					if ( ! isset( $matches[ $name ]['full'][ $owner ] ) && false !== strpos( $content, $name ) ) {
						$matches[ $name ]['full'][ $owner ] = true;
					}
					if ( $prefix && ! isset( $matches[ $name ]['prefix'][ $owner ] ) && false !== strpos( $content, $prefix ) && self::builds_name( $content, $prefix ) ) {
						$matches[ $name ]['prefix'][ $owner ] = true;
					}
				}
			}
		}
		return array( $matches, ( microtime( true ) - $start ) <= self::MAX_SECONDS );
	}

	/**
	 * ¿El código construye nombres con ese prefijo? ('prefijo_' . $x o "prefijo_{$x}").
	 * Un prefijo suelto aparece en muchos plugins; solo cuenta si se concatena.
	 */
	private static function builds_name( $content, $prefix ) {
		$q = preg_quote( $prefix, '/' );
		return (bool) preg_match( '/[\'"]' . $q . '[\'"]\s*\.|"' . $q . '\{?\$/', $content );
	}

	/**
	 * Archivos .php de un directorio (recursivo).
	 */
	private static function php_files( $dir, $deadline ) {
		$skip = array( 'vendor', 'node_modules', 'tests', 'test', 'cache', '.git', 'assets', 'languages', 'images', 'img', 'css', 'js', 'fonts', 'dist', 'build' );
		try {
			$dirs = new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				static function ( $current ) use ( $skip, $deadline ) {
					if ( microtime( true ) > $deadline ) {
						return false; // Sin tiempo: se deja de recorrer.
					}
					if ( $current->isDir() ) {
						return ! $current->isLink() && ! in_array( strtolower( $current->getFilename() ), $skip, true );
					}
					return 'php' === strtolower( $current->getExtension() );
				}
			);
			foreach ( new RecursiveIteratorIterator( $dirs ) as $f ) {
				if ( $f->isFile() ) {
					yield $f->getPathname();
				}
			}
		} catch ( Exception $e ) {
			return;
		}
	}

	/**
	 * Decide a quién pertenece una opción.
	 *
	 * @return array{0:string,1:string,2:string} dueño, estado (Núcleo, Tema, Activo, Inactivo, Borrado), cómo se atribuyó.
	 */
	private static function attribute( $name, array $matches, array $plugins ) {
		global $wpdb;
		if ( $name === $wpdb->prefix . 'user_roles' ) {
			return array( 'WordPress', 'Núcleo', 'opción del núcleo' );
		}
		foreach ( self::CORE_PREFIXES as $cp ) {
			if ( 0 === strpos( $name, $cp ) ) {
				if ( 'theme_mods_' === $cp ) {
					return array( 'Tema ' . substr( $name, strlen( $cp ) ), 'Tema', 'ajustes del tema' );
				}
				return array( 'WordPress', 'Núcleo', 'prefijo del núcleo' );
			}
		}
		$m = $matches[ $name ] ?? array(
			'full'   => array(),
			'prefix' => array(),
		);
		foreach ( array( 'full', 'prefix' ) as $kind ) {
			$owners = array_keys( $m[ $kind ] );
			if ( ! $owners ) {
				continue;
			}
			$how = 'full' === $kind ? 'nombre en el código' : 'por prefijo construido en el código';
			if ( in_array( 'core', $owners, true ) && 'full' === $kind ) {
				return array( 'WordPress', 'Núcleo', $how );
			}
			// Preferencia: plugin activo, mu-plugin, tema, plugin inactivo.
			$best = null;
			foreach ( $owners as $o ) {
				if ( 0 === strpos( $o, 'plugin:' ) ) {
					$slug = substr( $o, 7 );
					if ( ! empty( $plugins[ $slug ]['active'] ) ) {
						return array( $plugins[ $slug ]['name'], 'Activo', $how );
					}
				}
			}
			foreach ( $owners as $o ) {
				if ( 'mu' === $o ) {
					return array( 'mu-plugins', 'Activo', $how );
				}
				if ( 0 === strpos( $o, 'theme:' ) ) {
					$best = array( 'Tema ' . substr( $o, 6 ), 'Tema', $how );
				}
			}
			if ( $best ) {
				return $best;
			}
			foreach ( $owners as $o ) {
				if ( 0 === strpos( $o, 'plugin:' ) ) {
					$slug = substr( $o, 7 );
					return array( $plugins[ $slug ]['name'] ?? $slug, 'Inactivo', $how );
				}
			}
			if ( in_array( 'core', $owners, true ) ) {
				return array( 'WordPress', 'Núcleo', $how );
			}
		}
		return array( 'Sin coincidencias en el código', 'Borrado', 'probablemente de un plugin desinstalado' );
	}
}
