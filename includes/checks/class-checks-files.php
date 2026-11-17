<?php
/**
 * SEG-14 and SEG-15.
 *
 * Only reads the file system; it changes nothing.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * File checks.
 */
class Inochi_Pulso_Checks_Files extends Inochi_Pulso_Checks_Base {

	const MAX_SECONDS = 20;
	const MAX_ENTRIES = 300000;

	/**
	 * Runs the step.
	 */
	public static function run() {
		Inochi_Pulso_Util::extend_time( 180 );
		return array(
			'findings' => array(
				self::permissions(),
				self::leftovers(),
			),
			'payload'  => array(),
		);
	}

	/**
	 * Path of wp-config.php (root or one level up).
	 */
	private static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return file_exists( $up ) ? $up : '';
	}

	/**
	 * SEG-14: permissions.
	 */
	private static function permissions() {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return self::partial( 'SEG-14', __( 'Windows server', 'inochi-pulso' ), __( 'Unix permissions do not apply here.', 'inochi-pulso' ) );
		}
		$cfg   = self::wp_config_path();
		$mode  = $cfg ? fileperms( $cfg ) & 0777 : 0;
		$lines = array( 'wp-config.php: ' . ( $cfg ? sprintf( '%o', $mode ) : __( 'not found', 'inochi-pulso' ) ) );

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
					$lines[] = __( 'Permission walk stopped at the time limit.', 'inochi-pulso' );
					break;
				}
				if ( $f->isLink() ) {
					continue;
				}
				if ( ( $f->getPerms() & 0002 ) && count( $writable ) < 30 ) {
					$rel        = '/' . ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( wp_normalize_path( ABSPATH ) ) ), '/' );
					$note       = '.tmb' === basename( $rel ) ? ' (' . __( 'thumbnails of an elFinder-like file manager: find out which plugin created it', 'inochi-pulso' ) . ')' : '';
					$writable[] = sprintf( '%o %s %s%s', $f->getPerms() & 0777, $f->isDir() ? __( 'folder', 'inochi-pulso' ) : __( 'file', 'inochi-pulso' ), $rel, $note );
				}
			}
		} catch ( Exception $e ) {
			$lines[] = __( 'The whole file tree could not be walked.', 'inochi-pulso' );
		}
		$lines[] = __( 'World-writable (up to 3 levels, paths from the web root):', 'inochi-pulso' ) . ' ' . ( $writable ? "\n" . implode( "\n", $writable ) : __( 'none.', 'inochi-pulso' ) );

		if ( $writable || ( $mode & 0002 ) ) {
			$state = 'Fallo';
		} elseif ( $cfg && ( $mode & 0004 ) ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		/* translators: 1: wp-config.php permissions, 2: number of paths. */
		$value = sprintf( __( 'wp-config.php %1$s · %2$d world-writable paths', 'inochi-pulso' ), $cfg ? sprintf( '%o', $mode ) : '?', count( $writable ) );
		return self::auto( 'SEG-14', $value, $state, implode( "\n", $lines ) );
	}

	/**
	 * SEG-15: forgotten backups, dumps and installers, and whether they can be downloaded.
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
				// Never requested: requesting them would run them (an installer.php writes to disk).
				$executable[] = $rel;
				$lines[]      = $rel . ' · ' . $size . ' · ' . __( 'runnable from the web (not requested, so it is not run)', 'inochi-pulso' );
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
			$lines[] = '.git/ → /.git/config HTTP ' . $res['code'];
			if ( 200 === $res['code'] ) {
				$exposed[] = '.git/config';
			} elseif ( 0 === $res['code'] || $res['code'] >= 500 ) {
				++$unknown;
			}
		}
		$n_left = count( $cands ) + ( $git ? 1 : 0 );
		if ( $exposed ) {
			/* translators: 1: number of files, 2: list. */
			return self::auto( 'SEG-15', sprintf( _n( '%1$d file downloadable from the web: %2$s', '%1$d files downloadable from the web: %2$s', count( $exposed ), 'inochi-pulso' ), count( $exposed ), implode( ', ', array_slice( $exposed, 0, 5 ) ) ), 'Fallo', implode( "\n", $lines ) );
		}
		if ( $executable ) {
			/* translators: 1: number of scripts, 2: list. */
			return self::auto( 'SEG-15', sprintf( __( '%1$d leftover scripts on the site: %2$s', 'inochi-pulso' ), count( $executable ), implode( ', ', array_slice( $executable, 0, 5 ) ) ), 'Fallo', implode( "\n", $lines ) . "\n" . __( 'An installer, Adminer or phpinfo in the root is usually reachable: delete it.', 'inochi-pulso' ) );
		}
		if ( $unknown ) {
			/* translators: %d: number of files. */
			return self::partial( 'SEG-15', sprintf( __( '%d leftover files, reachability not checked', 'inochi-pulso' ), $n_left ), implode( "\n", $lines ) . "\n" . __( 'The site did not answer: check from outside.', 'inochi-pulso' ) );
		}
		if ( $cands || $git ) {
			/* translators: %d: number of files. */
			return self::auto( 'SEG-15', sprintf( __( '%d leftover files, not reachable', 'inochi-pulso' ), $n_left ), 'Aviso', implode( "\n", $lines ) );
		}
		return self::auto( 'SEG-15', __( 'No leftover files', 'inochi-pulso' ), 'OK', __( 'Checked the root, its first-level folders and wp-content.', 'inochi-pulso' ) );
	}
}
