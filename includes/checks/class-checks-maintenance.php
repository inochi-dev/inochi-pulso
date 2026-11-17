<?php
/**
 * MAN-01, MAN-06 and MAN-07.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance checks.
 */
class Inochi_Pulso_Checks_Maintenance extends Inochi_Pulso_Checks_Base {

	const LOG_TAIL = 5242880; // Last 5 MB of each log.

	/**
	 * Runs the step.
	 */
	public static function run() {
		return array(
			'findings' => array(
				self::backups(),
				self::updates_policy(),
				self::php_errors(),
			),
			'payload'  => array(),
		);
	}

	/**
	 * MAN-01: active backup plugins. Backups made by the hosting cannot be seen from here: no state.
	 */
	private static function backups() {
		$found = Inochi_Pulso_Util::active_matching( Inochi_Pulso_Util::known( 'backup' ) );
		/* translators: %s: list of plugins. */
		$value = $found ? sprintf( __( 'Backup plugin active: %s', 'inochi-pulso' ), implode( ', ', $found ) ) : __( 'No backup plugin active', 'inochi-pulso' );
		$ev    = $value . ".\n" . __( 'Backups made by the hosting cannot be seen from WordPress: check them too, with their frequency and destination.', 'inochi-pulso' );
		return self::partial( 'MAN-01', $value, $ev );
	}

	/**
	 * MAN-06: automatic updates. The procedure cannot be seen from here: a state only with many pending updates.
	 */
	private static function updates_policy() {
		$auto_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		$plugins      = Inochi_Pulso_Util::plugins();
		$auto_active  = 0;
		foreach ( $plugins as $p ) {
			if ( $p['active'] && in_array( $p['file'], $auto_plugins, true ) ) {
				++$auto_active;
			}
		}
		$core = __( 'minor versions only (default)', 'inochi-pulso' );
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			$v = WP_AUTO_UPDATE_CORE;
			if ( true === $v || 'true' === $v ) {
				$core = __( 'all versions', 'inochi-pulso' );
			} elseif ( false === $v || 'false' === $v ) {
				$core = __( 'turned off', 'inochi-pulso' );
			} elseif ( 'minor' === $v ) {
				$core = __( 'minor versions only', 'inochi-pulso' );
			} elseif ( in_array( $v, array( 'beta', 'rc', 'development' ), true ) ) {
				$core = __( 'development versions', 'inochi-pulso' );
			} else {
				$core = __( 'non-standard setting', 'inochi-pulso' );
			}
		}
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			$core = __( 'turned off', 'inochi-pulso' ) . ' (AUTOMATIC_UPDATER_DISABLED)';
		}
		$updates = get_site_transient( 'update_plugins' );
		$pending = is_object( $updates ) && ! empty( $updates->response ) ? count( (array) $updates->response ) : 0;
		/* translators: 1: plugins with auto-updates, 2: core setting, 3: plugins with a pending update. */
		$value = sprintf( __( '%1$d active plugins with automatic updates · core: %2$s · %3$d plugins with a pending update', 'inochi-pulso' ), $auto_active, $core, $pending );
		$ev    = $value . ".\n" . __( 'Whether there is an update procedure (backup, test, check) cannot be seen from here.', 'inochi-pulso' );
		if ( $pending >= 10 ) {
			// With so many pending updates, in practice nobody is updating.
			return self::auto( 'MAN-06', $value, 'Aviso', $ev );
		}
		return self::partial( 'MAN-06', $value, $ev );
	}

	/**
	 * Readable error logs: path => label.
	 */
	private static function log_files() {
		$files = array();
		$ini   = (string) ini_get( 'error_log' );
		if ( $ini && @is_file( $ini ) && @is_readable( $ini ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir can forbid it.
			$files[ $ini ] = 'php';
		}
		$log = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		$dbg = is_string( $log ) && ! in_array( $log, array( '', 'true', 'false', '1', '0' ), true ) ? $log : WP_CONTENT_DIR . '/debug.log';
		if ( @is_file( $dbg ) && @is_readable( $dbg ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$files[ $dbg ] = 'debug';
		}
		foreach ( array( dirname( ABSPATH ) . '/logs/error_log', ABSPATH . 'error_log' ) as $p ) {
			if ( @is_file( $p ) && @is_readable( $p ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$files[ $p ] = 'hosting';
			}
		}
		return $files;
	}

	/**
	 * Label of a log.
	 *
	 * @param string $kind Kind.
	 */
	private static function log_label( $kind ) {
		$labels = array(
			'php'     => __( 'PHP error_log', 'inochi-pulso' ),
			'debug'   => __( 'WordPress debug.log', 'inochi-pulso' ),
			'hosting' => __( 'hosting log', 'inochi-pulso' ),
		);
		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Reads the end of a file.
	 *
	 * @param string $path  File.
	 * @param int    $bytes Bytes.
	 */
	private static function tail( $path, $bytes ) {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$fh   = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		if ( $size > $bytes ) {
			fseek( $fh, $size - $bytes );
		}
		$data = (string) fread( $fh, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $data;
	}

	/**
	 * MAN-07: PHP errors in the last 7 days.
	 */
	private static function php_errors() {
		$files = self::log_files();
		if ( ! $files ) {
			return self::partial( 'MAN-07', __( 'No access to error logs', 'inochi-pulso' ), __( 'There are no logs WordPress can read. Look at the logs in the hosting panel.', 'inochi-pulso' ), Inochi_Pulso_Catalog::NO_DATA );
		}
		$since    = time() - 7 * DAY_IN_SECONDS;
		$fatals   = array();
		$warnings = 0;
		$lines_ev = array();
		foreach ( $files as $path => $label ) {
			$data = self::tail( $path, self::LOG_TAIL );
			$n_f  = 0;
			$n_w  = 0;
			foreach ( preg_split( '/\R/', $data ) as $line ) {
				if ( '' === $line ) {
					continue;
				}
				// Date at the start: [29-Oct-2026 21:00:00 UTC] or [Thu Oct 29 21:00:00.123 2026].
				if ( preg_match( '/^\[([^\]]+)\]/', $line, $m ) ) {
					$ts = strtotime( preg_replace( '/\.\d+/', '', $m[1] ) );
					if ( $ts && $ts < $since ) {
						continue;
					}
				}
				if ( false !== stripos( $line, 'PHP Fatal error' ) ) {
					++$n_f;
					$msg            = preg_replace( '/^.*PHP Fatal error:\s*/i', '', $line );
					$msg            = preg_replace( '/ in \/.*$/', '', $msg );
					$msg            = preg_replace( '#[\'"]?/[^\s\'"]+[\'"]?#', '[path]', (string) $msg );
					$key            = substr( trim( $msg ), 0, 140 );
					$fatals[ $key ] = ( $fatals[ $key ] ?? 0 ) + 1;
				} elseif ( false !== stripos( $line, 'PHP Warning' ) ) {
					++$n_w;
				}
			}
			$warnings += $n_w;
			/* translators: 1: log, 2: file name, 3: fatal errors, 4: warnings. */
			$lines_ev[] = sprintf( __( '%1$s (%2$s): %3$d fatal errors and %4$d warnings in 7 days.', 'inochi-pulso' ), self::log_label( $label ), basename( $path ), $n_f, $n_w );
		}
		arsort( $fatals );
		$total = array_sum( $fatals );
		$top   = array();
		foreach ( array_slice( $fatals, 0, 8, true ) as $msg => $n ) {
			$top[] = $n . ' × ' . $msg;
		}
		$ev = implode( "\n", $lines_ev ) . ( $top ? "\n" . __( 'Most repeated fatal errors:', 'inochi-pulso' ) . "\n" . implode( "\n", $top ) : '' ) . "\n" . __( 'The last 5 MB of each log are read; server paths are not copied.', 'inochi-pulso' );
		if ( $total > 0 ) {
			$state = 'Fallo';
		} elseif ( $warnings > 50 ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		/* translators: 1: fatal errors, 2: warnings. */
		$value  = sprintf( __( '%1$d fatal errors and %2$d warnings in 7 days', 'inochi-pulso' ), $total, $warnings );
		$server = array_diff( $files, array( 'debug' ) );
		if ( ! $server && 'Fallo' !== $state ) {
			return self::partial( 'MAN-07', $value . ' (' . __( 'debug.log only', 'inochi-pulso' ) . ')', $ev . "\n" . __( 'Only debug.log could be read: check the server log in the hosting panel too.', 'inochi-pulso' ) );
		}
		return self::auto( 'MAN-07', $value, $state, $ev );
	}
}
