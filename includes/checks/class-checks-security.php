<?php
/**
 * SEG-01, SEG-04, SEG-11 and the plugin list.
 *
 * Uses the update data WordPress already stores after its last check:
 * this step does not contact WordPress.org.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security checks without network.
 */
class Inochi_Pulso_Checks_Security extends Inochi_Pulso_Checks_Base {

	/**
	 * Runs the step.
	 */
	public static function run() {
		$plugins = Inochi_Pulso_Util::plugins();
		$updates = get_site_transient( 'update_plugins' );
		$rows    = array();
		$not_org = array();
		foreach ( $plugins as $slug => $p ) {
			$file = $p['file'];
			$item = null;
			if ( is_object( $updates ) && isset( $updates->response[ $file ] ) ) {
				$item = $updates->response[ $file ];
			} elseif ( is_object( $updates ) && isset( $updates->no_update[ $file ] ) ) {
				$item = $updates->no_update[ $file ];
			}
			$org = $item && isset( $item->id ) && 0 === strpos( (string) $item->id, 'w.org/' );
			if ( ! $org ) {
				$not_org[] = $slug;
			}
			$rows[] = array(
				'slug'    => $slug,
				'active'  => $p['active'],
				'version' => $p['version'],
			);
		}

		return array(
			'findings' => array(
				self::pending_updates( $plugins, $updates ),
				self::outside_repo( $not_org, $updates ),
				self::admins(),
			),
			'payload'  => array( 'plugins' => $rows ),
		);
	}

	/**
	 * Does the major version change? $segments = 1 compares x, 2 compares x.y.
	 *
	 * @param string $from     From version.
	 * @param string $to       To version.
	 * @param int    $segments Segments.
	 */
	private static function is_major( $from, $to, $segments = 1 ) {
		$a = array_slice( explode( '.', (string) $from ), 0, $segments );
		$b = array_slice( explode( '.', (string) $to ), 0, $segments );
		return $a !== $b;
	}

	/**
	 * SEG-01: pending updates according to the last WordPress check.
	 *
	 * @param array  $plugins Installed plugins.
	 * @param object $updates update_plugins transient.
	 */
	private static function pending_updates( array $plugins, $updates ) {
		global $wp_version;
		$core    = get_site_transient( 'update_core' );
		$themes  = get_site_transient( 'update_themes' );
		$checked = is_object( $updates ) && ! empty( $updates->last_checked ) ? (int) $updates->last_checked : 0;
		$stale   = ! $checked || $checked < time() - 7 * DAY_IN_SECONDS;
		$major_s = ' (' . __( 'major', 'inochi-pulso' ) . ')';

		$lines = array();
		$major = false;
		$count = 0;

		$core_new = '';
		if ( is_object( $core ) && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response && version_compare( $u->current, $wp_version, '>' ) ) {
					$core_new = $u->current;
					break;
				}
			}
		}
		if ( $core_new ) {
			++$count;
			$is_major = self::is_major( $wp_version, $core_new, 2 );
			$major    = $major || $is_major;
			$lines[]  = sprintf( 'WordPress %s → %s%s', $wp_version, $core_new, $is_major ? $major_s : '' );
		}
		if ( is_object( $updates ) && ! empty( $updates->response ) ) {
			foreach ( $updates->response as $file => $item ) {
				$slug  = Inochi_Pulso_Util::plugin_slug( $file );
				$from  = $plugins[ $slug ]['version'] ?? '?';
				$to    = (string) ( $item->new_version ?? '?' );
				$is    = self::is_major( $from, $to, 1 );
				$major = $major || $is;
				++$count;
				/* translators: 1: plugin, 2: version, 3: new version. */
				$lines[] = sprintf( __( 'Plugin %1$s %2$s → %3$s', 'inochi-pulso' ), $slug, $from, $to ) . ( $is ? $major_s : '' );
			}
		}
		if ( is_object( $themes ) && ! empty( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $data ) {
				$theme = wp_get_theme( $stylesheet );
				$to    = (string) ( $data['new_version'] ?? '?' );
				$is    = self::is_major( $theme->get( 'Version' ), $to, 1 );
				$major = $major || $is;
				++$count;
				/* translators: 1: theme, 2: version, 3: new version. */
				$lines[] = sprintf( __( 'Theme %1$s %2$s → %3$s', 'inochi-pulso' ), $stylesheet, $theme->get( 'Version' ), $to ) . ( $is ? $major_s : '' );
			}
		}
		$when = $checked ? gmdate( 'Y-m-d H:i', $checked ) . ' UTC' : __( 'never', 'inochi-pulso' );
		/* translators: %s: date of the last update check. */
		$ev = sprintf( __( 'According to the last WordPress update check (%s):', 'inochi-pulso' ), $when ) . "\n" . ( $lines ? implode( "\n", $lines ) : __( 'No pending updates.', 'inochi-pulso' ) );
		if ( $count ) {
			/* translators: %d: number of pending updates. */
			$value = sprintf( _n( '%d pending update', '%d pending updates', $count, 'inochi-pulso' ), $count ) . ( $major ? ', ' . __( 'some major', 'inochi-pulso' ) : '' );
		} else {
			$value = __( 'Everything up to date', 'inochi-pulso' );
		}
		if ( $stale ) {
			return self::partial( 'SEG-01', $value . ' (' . __( 'old data', 'inochi-pulso' ) . ')', $ev . "\n" . __( 'The last check is more than 7 days old or does not exist: open Dashboard → Updates and scan again.', 'inochi-pulso' ) );
		}
		$state = ! $count ? 'OK' : ( $major ? 'Fallo' : 'Aviso' );
		return self::auto( 'SEG-01', $value, $state, $ev );
	}

	/**
	 * SEG-04: plugins not listed on WordPress.org. Without knowing their licence, there is no state.
	 *
	 * @param array  $not_org Slugs.
	 * @param object $updates update_plugins transient.
	 */
	private static function outside_repo( array $not_org, $updates ) {
		if ( ! is_object( $updates ) || empty( $updates->checked ) ) {
			return self::partial( 'SEG-04', __( 'No WordPress.org data', 'inochi-pulso' ), __( 'WordPress has not checked for updates yet: it is not possible to know which plugins come from WordPress.org.', 'inochi-pulso' ) );
		}
		if ( ! $not_org ) {
			return self::auto( 'SEG-04', __( 'All plugins come from WordPress.org', 'inochi-pulso' ), 'OK', __( 'All of them are in the WordPress.org update data.', 'inochi-pulso' ) );
		}
		/* translators: 1: number of plugins, 2: list. */
		$value = sprintf( __( '%1$d plugins from outside WordPress.org: %2$s', 'inochi-pulso' ), count( $not_org ), implode( ', ', array_slice( $not_org, 0, 8 ) ) . ( count( $not_org ) > 8 ? '…' : '' ) );
		$ev    = __( 'Not in the WordPress.org data (premium, custom or closed):', 'inochi-pulso' ) . "\n" . implode( "\n", $not_org ) . "\n" . __( 'For each one, check where it comes from and that it has a licence and updates.', 'inochi-pulso' );
		return self::partial( 'SEG-04', $value, $ev );
	}

	/**
	 * SEG-11: administrator accounts (counts only).
	 */
	private static function admins() {
		$admins    = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		$n         = count( $admins );
		$has_admin = (bool) get_user_by( 'login', 'admin' );
		$twofa     = Inochi_Pulso_Util::active_matching( Inochi_Pulso_Util::known( 'twofa' ) );
		$value     = sprintf(
			/* translators: 1: number of administrators, 2: "admin" account status, 3: 2FA plugins. */
			__( '%1$d administrators · %2$s · 2FA plugin: %3$s', 'inochi-pulso' ),
			$n,
			$has_admin ? __( 'the "admin" account exists', 'inochi-pulso' ) : __( 'no "admin" account', 'inochi-pulso' ),
			$twofa ? implode( ', ', $twofa ) : __( 'not detected', 'inochi-pulso' )
		);
		$ev = $value . ".\n" . __( 'No names are stored. Two-factor authentication can also be outside WordPress (for example, at the CDN).', 'inochi-pulso' );
		if ( $has_admin ) {
			return self::auto( 'SEG-11', $value, 'Fallo', $ev );
		}
		if ( ! $twofa ) {
			return self::auto( 'SEG-11', $value, 'Aviso', $ev );
		}
		return self::auto( 'SEG-11', $value, $n <= 3 ? 'OK' : 'Aviso', $ev );
	}
}
