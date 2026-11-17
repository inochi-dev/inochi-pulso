<?php
/**
 * Shared helpers.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers.
 */
class Inochi_Pulso_Util {

	/**
	 * Number formatted for the site language.
	 *
	 * @param float $n        Number.
	 * @param int   $decimals Decimals.
	 */
	public static function num( $n, $decimals = 0 ) {
		return number_format_i18n( (float) $n, $decimals );
	}

	/**
	 * Readable bytes (KB, MB, GB) for the site language.
	 *
	 * @param float $bytes Bytes.
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
	 * State by thresholds: OK below $warn, Warning up to $fail, Fail above $fail.
	 *
	 * @param float $value Value.
	 * @param float $warn  Warning threshold.
	 * @param float $fail  Fail threshold.
	 */
	public static function grade( $value, $warn, $fail ) {
		if ( $value < $warn ) {
			return 'OK';
		}
		return $value <= $fail ? 'Aviso' : 'Fallo';
	}

	/**
	 * Converts a php.ini value (128M, 1G) to bytes. -1 = unlimited.
	 *
	 * @param string $value Value.
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
				// Falls through.
			case 'm':
				$bytes *= 1024;
				// Falls through.
			case 'k':
				$bytes *= 1024;
		}
		return (int) $bytes;
	}

	/**
	 * Shortens an evidence text at a line break or a space.
	 *
	 * @param string $text Text.
	 * @param int    $max  Bytes.
	 */
	public static function clip( $text, $max = 4000 ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
		$nl  = strrpos( $cut, "\n" );
		if ( false !== $nl && $nl > $max * 0.5 ) {
			$cut = substr( $cut, 0, $nl );
		} else {
			$sp = strrpos( $cut, ' ' );
			if ( false !== $sp && $sp > $max * 0.7 ) {
				$cut = substr( $cut, 0, $sp );
			}
		}
		$rest = substr_count( substr( $text, strlen( $cut ) ), "\n" );
		/* translators: %d: number of lines left out. */
		return rtrim( $cut ) . ( $rest > 0 ? "\n" . sprintf( _n( '[… and %d more line]', '[… and %d more lines]', $rest, 'inochi-pulso' ), $rest ) : ' […]' );
	}

	/**
	 * Cleans a text before saving or exporting it: valid UTF-8, no emails and no
	 * absolute server paths.
	 *
	 * @param string $text Text.
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_convert_encoding' ) && ! wp_check_invalid_utf8( $text ) && '' !== $text ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = (string) wp_check_invalid_utf8( $text, true );
		$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text );
		// WordPress root and its parent folder, only if the parent has at least two levels
		// (with ABSPATH = /app/ the parent would be "/" and would break URLs).
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
		// Usual absolute server paths still left (URLs are untouched: it needs a start or a space before).
		return (string) preg_replace( '#(^|[\s\'"(=])/(?:home|var|srv|usr|opt|tmp|data)/[^\s\'"()]+#m', '$1[path]', $text );
	}

	/**
	 * Plain text table for evidence (rows of arrays).
	 *
	 * @param array $rows    Rows.
	 * @param array $columns Keys to show (header labels).
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
	 * Plugin slug from its main file (folder/file.php).
	 *
	 * @param string $plugin_file Plugin file.
	 */
	public static function plugin_slug( $plugin_file ) {
		$dir = dirname( $plugin_file );
		return '.' === $dir ? basename( $plugin_file, '.php' ) : $dir;
	}

	/**
	 * Installed plugins (without Pulso): slug => array(file, name, version, active).
	 */
	public static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out  = array();
		$self = plugin_basename( INOCHI_PULSO_FILE );
		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $self || 'inochi-pulso' === self::plugin_slug( $file ) ) {
				continue; // Pulso does not count in the site scan.
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
	 * Active plugin slugs that match a list (by slug prefix).
	 *
	 * @param array $known Slugs or prefixes.
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
	 * WooCommerce is active.
	 */
	public static function has_woocommerce() {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Gives a heavy step more time if the server allows it.
	 *
	 * @param int $seconds Seconds.
	 */
	public static function extend_time( $seconds = 120 ) {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- a heavy read-only step may need more than the default 30 s.
		}
	}

	/**
	 * Known plugins by group (slug prefixes).
	 *
	 * @param string $group Group.
	 */
	public static function known( $group ) {
		$lists = array(
			'cache'    => array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze', 'cache-enabler', 'hummingbird-performance', 'wp-optimize', 'swift-performance', 'nitropack', 'flying-press', 'powered-cache', 'comet-cache', 'cloudflare' ),
			'login'    => array( 'wordfence', 'better-wp-security', 'ithemes-security', 'solid-security', 'all-in-one-wp-security-and-firewall', 'limit-login-attempts-reloaded', 'limit-login-attempts', 'loginizer', 'wp-cerber', 'shield-security', 'wp-defender', 'login-lockdown', 'secupress', 'jetpack' ),
			'twofa'    => array( 'two-factor', 'wp-2fa', 'wordfence', 'google-authenticator', 'miniorange-2-factor-authentication', 'better-wp-security', 'ithemes-security', 'solid-security', 'wp-defender', 'shield-security', 'all-in-one-wp-security-and-firewall', 'two-factor-authentication', 'secupress' ),
			'backup'   => array( 'updraftplus', 'backwpup', 'duplicator', 'all-in-one-wp-migration', 'wpvivid-backuprestore', 'blogvault-real-time-backup', 'backupbuddy', 'solid-backups', 'backup-backup', 'jetpack', 'xcloner-backup-and-restore', 'boldgrid-backup', 'backup', 'wp-time-capsule', 'mainwp-child', 'managewp' ),
			'firewall' => array( 'wordfence', 'ninjafirewall', 'all-in-one-wp-security-and-firewall', 'shield-security', 'wp-cerber', 'secupress', 'sucuri-scanner', 'better-wp-security', 'solid-security' ),
		);
		return $lists[ $group ] ?? array();
	}
}
