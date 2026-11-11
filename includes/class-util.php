<?php
/**
 * Utilidades comunes.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Funciones auxiliares sin estado.
 */
class Inochi_Pulso_Util {

	/**
	 * Crea una opción solo si no existe, de forma atómica (INSERT IGNORE). Sirve de bloqueo.
	 *
	 * @param string $name Nombre de la opción.
	 */
	public static function claim_option( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bloqueo atómico.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, (string) time() ) );
		$ok = 1 === (int) $wpdb->rows_affected;
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $ok;
	}

	/**
	 * Número con formato español.
	 *
	 * @param float $n        Número.
	 * @param int   $decimals Decimales.
	 */
	public static function num( $n, $decimals = 0 ) {
		return number_format( (float) $n, $decimals, ',', '.' );
	}

	/**
	 * Bytes legibles (KB, MB, GB) con formato español.
	 */
	public static function bytes( $bytes ) {
		$bytes = (float) $bytes;
		if ( $bytes >= 1073741824 ) {
			return self::num( $bytes / 1073741824, 2 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return self::num( $bytes / 1048576, 2 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return self::num( $bytes / 1024, 0 ) . ' KB';
		}
		return self::num( $bytes ) . ' B';
	}

	/**
	 * Estado por tramos: OK si < $warn, Aviso si <= $fail, Fallo si > $fail.
	 */
	public static function grade( $value, $warn, $fail ) {
		if ( $value < $warn ) {
			return 'OK';
		}
		return $value <= $fail ? 'Aviso' : 'Fallo';
	}

	/**
	 * Convierte un valor de php.ini (128M, 1G) a bytes. -1 = ilimitado.
	 */
	public static function ini_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( '-1' === $value ) {
			return -1;
		}
		$unit  = strtolower( substr( $value, -1 ) );
		$bytes = (float) $value;
		switch ( $unit ) {
			case 'g':
				$bytes *= 1024;
				// Sigue.
			case 'm':
				$bytes *= 1024;
				// Sigue.
			case 'k':
				$bytes *= 1024;
		}
		return (int) $bytes;
	}

	/**
	 * Recorta un texto de evidencia.
	 */
	public static function clip( $text, $max = 4000 ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
		// Cortar en el último salto de línea o espacio para no partir palabras.
		$nl = strrpos( $cut, "\n" );
		if ( false !== $nl && $nl > $max * 0.5 ) {
			$cut = substr( $cut, 0, $nl );
		} else {
			$sp = strrpos( $cut, ' ' );
			if ( false !== $sp && $sp > $max * 0.7 ) {
				$cut = substr( $cut, 0, $sp );
			}
		}
		$rest = substr_count( substr( $text, strlen( $cut ) ), "\n" );
		return rtrim( $cut ) . ( $rest > 0 ? sprintf( "\n[… y %d %s más]", $rest, 1 === $rest ? 'línea' : 'líneas' ) : ' […]' );
	}

	/**
	 * Recorta un texto a un máximo de líneas y caracteres, por líneas completas.
	 *
	 * @param string $text      Texto.
	 * @param int    $max_lines Líneas.
	 * @param int    $max_chars Caracteres.
	 * @param string $more      Sufijo con %d para las líneas omitidas.
	 */
	public static function clip_lines( $text, $max_lines, $max_chars, $more = '… y %d líneas más' ) {
		$lines = preg_split( '/\R/', trim( (string) $text ) );
		$out   = array();
		$len   = 0;
		foreach ( $lines as $line ) {
			$l = strlen( $line ) + 1;
			if ( count( $out ) >= $max_lines || ( $out && $len + $l > $max_chars ) ) {
				break;
			}
			$out[] = strlen( $line ) > $max_chars ? self::clip( $line, $max_chars ) : $line;
			$len  += $l;
		}
		$rest = count( $lines ) - count( $out );
		if ( $rest > 0 ) {
			$out[] = 1 === $rest ? str_replace( array( '%d líneas', '%d' ), array( '1 línea', '1' ), $more ) : sprintf( $more, $rest );
		}
		return implode( "\n", $out );
	}

	/**
	 * Texto corto en una línea, cortado por palabra.
	 */
	public static function short_text( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > strlen( $cut ) * 0.6 ) {
			$cut = substr( $cut, 0, $sp );
		}
		return rtrim( $cut, ' ,;·' ) . '…';
	}

	/**
	 * Limpia un texto antes de guardarlo o exportarlo: UTF-8 válido, sin emails
	 * y sin rutas absolutas del servidor.
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_convert_encoding' ) && ! wp_check_invalid_utf8( $text ) && '' !== $text ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = (string) wp_check_invalid_utf8( $text, true );
		$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text );
		// Raíz de WordPress y su carpeta padre, solo si esta tiene al menos dos niveles
		// (con ABSPATH = /app/ la carpeta padre sería «/» y rompería las URL).
		$roots  = array( wp_normalize_path( ABSPATH ) );
		$parent = trailingslashit( wp_normalize_path( dirname( ABSPATH ) ) );
		if ( substr_count( trim( $parent, '/' ), '/' ) >= 1 ) {
			$roots[] = $parent;
		}
		foreach ( $roots as $root ) {
			if ( substr_count( trim( $root, '/' ), '/' ) >= 1 ) {
				$text = str_replace( array( $root, untrailingslashit( $root ) ), array( '', '' ), $text );
			}
		}
		// Rutas absolutas habituales de servidor que queden (no toca URL: exige inicio o espacio delante).
		$text = (string) preg_replace( '#(^|[\s\'"(=])/(?:home|var|srv|usr|opt|tmp|data)/[^\s\'"()]+#m', '$1[ruta]', $text );
		return $text;
	}

	/**
	 * Tabla de texto simple para evidencias (filas de arrays).
	 *
	 * @param array $rows    Filas.
	 * @param array $columns Claves a mostrar.
	 */
	public static function text_table( array $rows, array $columns ) {
		$lines = array( implode( ' | ', $columns ) );
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $columns as $col ) {
				$cells[] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
			}
			$lines[] = implode( ' | ', $cells );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Slug del plugin a partir de su archivo principal (carpeta/archivo.php).
	 */
	public static function plugin_slug( $plugin_file ) {
		$dir = dirname( $plugin_file );
		return '.' === $dir ? basename( $plugin_file, '.php' ) : $dir;
	}

	/**
	 * Plugins instalados (sin Pulso): slug => array(file, name, version, active).
	 */
	public static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out  = array();
		$self = plugin_basename( INOCHI_PULSO_FILE );
		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $self || 'inochi-pulso' === self::plugin_slug( $file ) ) {
				continue; // Pulso no cuenta en la auditoría del sitio.
			}
			$slug         = self::plugin_slug( $file );
			$out[ $slug ] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ),
			);
		}
		return $out;
	}

	/**
	 * Slugs de plugins activos que coinciden con una lista (por prefijo de slug).
	 *
	 * @param array $known Slugs o prefijos conocidos.
	 */
	public static function active_matching( array $known ) {
		$found = array();
		foreach ( self::plugins() as $slug => $p ) {
			if ( ! $p['active'] ) {
				continue;
			}
			foreach ( $known as $k ) {
				if ( 0 === strpos( $slug, $k ) ) {
					$found[] = $slug;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Fecha de «ahora» en UTC (timestamp), aislada para pruebas.
	 */
	public static function now() {
		return time();
	}

	/**
	 * Hay WooCommerce activo.
	 */
	public static function has_woocommerce() {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Concede más tiempo a un paso pesado si el servidor lo permite.
	 */
	public static function extend_time( $seconds = 120 ) {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Plugins conocidos por categoría (prefijos de slug).
	 */
	public static function known( $group ) {
		$lists = array(
			'cache'    => array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze', 'cache-enabler', 'hummingbird-performance', 'wp-optimize', 'swift-performance', 'nitropack', 'flying-press', 'powered-cache', 'comet-cache', 'cloudflare' ),
			'security' => array( 'wordfence', 'better-wp-security', 'ithemes-security', 'solid-security', 'all-in-one-wp-security-and-firewall', 'sucuri-scanner', 'limit-login-attempts-reloaded', 'limit-login-attempts', 'loginizer', 'wp-cerber', 'shield-security', 'wp-defender', 'jetpack', 'login-lockdown', 'ninjafirewall', 'secupress' ),
			'login'    => array( 'wordfence', 'better-wp-security', 'ithemes-security', 'solid-security', 'all-in-one-wp-security-and-firewall', 'limit-login-attempts-reloaded', 'limit-login-attempts', 'loginizer', 'wp-cerber', 'shield-security', 'wp-defender', 'login-lockdown', 'secupress', 'jetpack' ),
			'twofa'    => array( 'two-factor', 'wp-2fa', 'wordfence', 'google-authenticator', 'miniorange-2-factor-authentication', 'better-wp-security', 'ithemes-security', 'solid-security', 'wp-defender', 'shield-security', 'all-in-one-wp-security-and-firewall', 'two-factor-authentication', 'secupress' ),
			'backup'   => array( 'updraftplus', 'backwpup', 'duplicator', 'all-in-one-wp-migration', 'wpvivid-backuprestore', 'blogvault-real-time-backup', 'backupbuddy', 'solid-backups', 'backup-backup', 'jetpack', 'xcloner-backup-and-restore', 'boldgrid-backup', 'backup', 'wp-time-capsule', 'mainwp-child', 'managewp' ),
			'smtp'     => array( 'wp-mail-smtp', 'post-smtp', 'fluent-smtp', 'easy-wp-smtp', 'smtp-mailer', 'wp-ses', 'mailgun', 'sendgrid', 'gmail-smtp', 'wp-smtp', 'mailpoet', 'brevo', 'sendinblue', 'site-mailer', 'suremails', 'elastic-email-sender', 'mailjet-for-wordpress', 'gosmtp', 'mailersend-official-smtp-integration', 'smtp2go', 'postmark-approved-wordpress-plugin', 'sparkpost', 'wp-mail-bank', 'offload-ses', 'newsletter-smtp', 'zeptomail', 'mailster' ),
			'uptime'   => array( 'jetpack', 'mainwp-child', 'managewp', 'worker', 'wp-umbrella' ),
		);
		return $lists[ $group ] ?? array();
	}
}
