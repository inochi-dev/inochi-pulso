<?php
/**
 * SEG-01, SEG-04 (parcial), SEG-11 e inventario de plugins.
 *
 * Usa los datos de actualizaciones que WordPress ya guarda tras su última
 * comprobación: Pulso no consulta WordPress.org.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones de seguridad sin red.
 */
class Inochi_Pulso_Checks_Security extends Inochi_Pulso_Checks_Base {

	/**
	 * Ejecuta el paso.
	 */
	public static function run() {
		$plugins = Inochi_Pulso_Util::plugins();
		$updates = get_site_transient( 'update_plugins' );
		$rows    = array();
		$not_org = array();
		foreach ( $plugins as $slug => $p ) {
			$file   = $p['file'];
			$item   = null;
			$update = '';
			if ( is_object( $updates ) && isset( $updates->response[ $file ] ) ) {
				$item   = $updates->response[ $file ];
				$update = (string) ( $item->new_version ?? '' );
			} elseif ( is_object( $updates ) && isset( $updates->no_update[ $file ] ) ) {
				$item = $updates->no_update[ $file ];
			}
			$org = $item && isset( $item->id ) && 0 === strpos( (string) $item->id, 'w.org/' );
			if ( ! $org ) {
				$not_org[] = $slug;
			}
			$rows[] = array(
				'slug'    => $slug,
				'active'  => $p['active'] ? 'Sí' : 'No',
				'version' => $p['version'],
				'update'  => $update,
				'origin'  => $org ? 'WordPress.org' : '',
				'note'    => $org ? '' : ( is_object( $updates ) && ! empty( $updates->checked ) ? 'No figura en WordPress.org: premium, a medida o cerrado (SEG-03/04)' : '' ),
			);
		}

		return array(
			'findings' => array(
				self::pending_updates( $plugins, $updates ),
				self::outside_repo( $not_org, $updates ),
				self::admins(),
			),
			'payload'  => array(
				'plugins' => $rows,
				'themes'  => self::themes(),
			),
		);
	}

	/**
	 * Tema activo y, si existe, su tema padre: slug y versión.
	 */
	private static function themes() {
		$out    = array();
		$theme  = wp_get_theme();
		$out[]  = array(
			'slug'    => $theme->get_stylesheet(),
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'role'    => 'activo',
		);
		$parent = $theme->parent();
		if ( $parent ) {
			$out[] = array(
				'slug'    => $parent->get_stylesheet(),
				'name'    => $parent->get( 'Name' ),
				'version' => $parent->get( 'Version' ),
				'role'    => 'padre',
			);
		}
		return $out;
	}

	/**
	 * ¿Cambia la versión mayor? $segments = 1 compara x, 2 compara x.y.
	 */
	private static function is_major( $from, $to, $segments = 1 ) {
		$a = array_slice( explode( '.', (string) $from ), 0, $segments );
		$b = array_slice( explode( '.', (string) $to ), 0, $segments );
		return $a !== $b;
	}

	/**
	 * SEG-01: actualizaciones pendientes según la última comprobación de WordPress.
	 */
	private static function pending_updates( array $plugins, $updates ) {
		global $wp_version;
		$core    = get_site_transient( 'update_core' );
		$themes  = get_site_transient( 'update_themes' );
		$checked = is_object( $updates ) && ! empty( $updates->last_checked ) ? (int) $updates->last_checked : 0;
		$stale   = ! $checked || $checked < time() - 7 * DAY_IN_SECONDS;

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
			$lines[]  = sprintf( 'WordPress %s → %s%s', $wp_version, $core_new, $is_major ? ' (mayor)' : '' );
		}
		if ( is_object( $updates ) && ! empty( $updates->response ) ) {
			foreach ( $updates->response as $file => $item ) {
				$slug  = Inochi_Pulso_Util::plugin_slug( $file );
				$from  = $plugins[ $slug ]['version'] ?? '?';
				$to    = (string) ( $item->new_version ?? '?' );
				$is    = self::is_major( $from, $to, 1 );
				$major = $major || $is;
				++$count;
				$lines[] = sprintf( 'Plugin %s %s → %s%s', $slug, $from, $to, $is ? ' (mayor)' : '' );
			}
		}
		if ( is_object( $themes ) && ! empty( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $data ) {
				$theme = wp_get_theme( $stylesheet );
				$to    = (string) ( $data['new_version'] ?? '?' );
				$is    = self::is_major( $theme->get( 'Version' ), $to, 1 );
				$major = $major || $is;
				++$count;
				$lines[] = sprintf( 'Tema %s %s → %s%s', $stylesheet, $theme->get( 'Version' ), $to, $is ? ' (mayor)' : '' );
			}
		}
		$when  = $checked ? gmdate( 'Y-m-d H:i', $checked ) . ' UTC' : 'nunca';
		$ev    = 'Según la última comprobación de actualizaciones de WordPress (' . $when . "):\n" . ( $lines ? implode( "\n", $lines ) : 'Sin actualizaciones pendientes.' );
		$value = $count ? sprintf( '%d actualizaciones pendientes%s', $count, $major ? ', alguna mayor' : '' ) : 'Todo al día';
		if ( $stale ) {
			return self::partial( 'SEG-01', $value . ' (dato antiguo)', $ev . "\nLa comprobación tiene más de 7 días o no existe: revisa en Escritorio → Actualizaciones." );
		}
		$state = ! $count ? 'OK' : ( $major ? 'Fallo' : 'Aviso' );
		return self::auto( 'SEG-01', $value, $state, $ev . "\nLa antigüedad (> 90 días) de cada actualización se revisa a mano." );
	}

	/**
	 * SEG-04 (parcial): plugins que no figuran en WordPress.org.
	 */
	private static function outside_repo( array $not_org, $updates ) {
		if ( ! is_object( $updates ) || empty( $updates->checked ) ) {
			return self::partial( 'SEG-04', 'Sin datos de WordPress.org', 'WordPress no ha comprobado actualizaciones todavía: no se puede saber qué plugins vienen de WordPress.org.' );
		}
		$value = $not_org ? sprintf( '%d plugins fuera de WordPress.org: %s', count( $not_org ), implode( ', ', array_slice( $not_org, 0, 8 ) ) . ( count( $not_org ) > 8 ? '…' : '' ) ) : 'Todos los plugins vienen de WordPress.org';
		$ev    = $not_org ? "No figuran en los datos de WordPress.org (premium, a medida o cerrados):\n" . implode( "\n", $not_org ) . "\nPara cada uno: origen, licencia y actualizaciones. Si estaba en WordPress.org y ya no, está cerrado (SEG-03)." : 'Todos figuran en los datos de actualizaciones de WordPress.org. El tema activo se revisa a mano.';
		return self::partial( 'SEG-04', $value, $ev );
	}

	/**
	 * SEG-11: cuentas de administrador (solo recuentos).
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
		$value     = sprintf( '%d administradores · %s · 2FA por plugin: %s', $n, $has_admin ? 'existe la cuenta «admin»' : 'sin cuenta «admin»', $twofa ? implode( ', ', $twofa ) : 'no detectado' );
		$ev        = $value . ".\nNo se guardan nombres. Cuentas sin dueño conocido y 2FA real de cada administrador: revisar a mano. El segundo factor puede estar fuera de WordPress (p. ej. Cloudflare Access).";
		if ( $has_admin ) {
			return self::partial( 'SEG-11', $value, $ev, 'Fallo' );
		}
		if ( ! $twofa ) {
			// El umbral marca Aviso sin 2FA; si el segundo factor está fuera de WordPress, se cambia a OK indicando dónde.
			return self::partial( 'SEG-11', $value, $ev . "\nSin segundo factor detectado: estado sugerido Aviso.", 'Aviso' );
		}
		return self::partial( 'SEG-11', $value, $ev );
	}
}
