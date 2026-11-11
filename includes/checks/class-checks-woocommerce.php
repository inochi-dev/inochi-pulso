<?php
/**
 * WOO-01 a WOO-10.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones de WooCommerce (solo lectura).
 */
class Inochi_Pulso_Checks_WooCommerce extends Inochi_Pulso_Checks_Base {

	const CODES = array( 'WOO-01', 'WOO-02', 'WOO-03', 'WOO-04', 'WOO-05', 'WOO-06', 'WOO-07', 'WOO-08', 'WOO-09' );

	/**
	 * Ejecuta el paso.
	 */
	public static function run() {
		if ( ! Inochi_Pulso_Util::has_woocommerce() ) {
			$findings = array();
			foreach ( self::CODES as $code ) {
				$findings[] = self::auto( $code, 'Sin WooCommerce', 'N.A.', 'WooCommerce no está activo.' );
			}
			return array(
				'findings' => $findings,
				'payload'  => array(),
			);
		}
		$findings = array();
		foreach ( array( 'version_templates', 'hpos', 'pages', 'gateways', 'webhooks', 'stuck_orders', 'failed_actions', 'late_actions', 'sessions' ) as $m ) {
			try {
				$findings[] = self::$m();
			} catch ( Throwable $e ) {
				$findings[] = self::partial( self::CODES[ count( $findings ) ], 'Error al comprobar', Inochi_Pulso_Util::redact( $e->getMessage() ) );
			}
		}
		return array(
			'findings' => $findings,
			'payload'  => array(),
		);
	}

	/**
	 * Tabla de Action Scheduler.
	 */
	private static function as_table() {
		global $wpdb;
		return $wpdb->prefix . 'actionscheduler_actions';
	}

	/**
	 * ¿Existe la tabla?
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * WOO-01: versión y plantillas sobrescritas.
	 */
	private static function version_templates() {
		$version = defined( 'WC_VERSION' ) ? WC_VERSION : '?';
		$updates = get_site_transient( 'update_plugins' );
		$new     = '';
		if ( is_object( $updates ) && ! empty( $updates->response ) ) {
			foreach ( $updates->response as $file => $item ) {
				if ( 'woocommerce' === Inochi_Pulso_Util::plugin_slug( $file ) ) {
					$new = (string) ( $item->new_version ?? '' );
				}
			}
		}
		$behind = 0;
		if ( $new ) {
			$behind = (int) explode( '.', $new )[0] - (int) explode( '.', $version )[0];
		}

		// Sobrescrituras del tema, leídas de los archivos (sin el informe de estado de
		// WooCommerce, que consulta WordPress.org y guarda un transient).
		$outdated = array();
		$total    = 0;
		foreach ( self::template_overrides() as $o ) {
			++$total;
			if ( '' === $o['version'] ) {
				$outdated[] = $o['file'] . ' (sin versión)';
			} elseif ( '' !== $o['core'] && version_compare( $o['version'], $o['core'], '<' ) ) {
				$outdated[] = $o['file'] . ' (' . $o['version'] . ' < ' . $o['core'] . ')';
			}
		}
		$state = $behind >= 2 ? 'Fallo' : ( $outdated ? 'Aviso' : 'OK' );
		$value = 'WooCommerce ' . $version . ( $new ? ' (disponible ' . $new . ')' : '' ) . ' · ' . count( $outdated ) . ' de ' . $total . ' plantillas sobrescritas obsoletas';
		$ev    = $value . ( $outdated ? ":\n" . implode( "\n", $outdated ) : '.' ) . ( $new ? '' : "\nVersión disponible según la última comprobación de WordPress; sin dato si no hay actualización pendiente." );
		return self::auto( 'WOO-01', $value, $state, $ev );
	}

	/**
	 * Plantillas de WooCommerce sobrescritas por el tema activo o su padre.
	 *
	 * @return array<int, array{file:string, version:string, core:string}>
	 */
	private static function template_overrides() {
		$base = trailingslashit( WC()->plugin_path() ) . 'templates/';
		if ( ! is_dir( $base ) ) {
			return array();
		}
		$dirs = array_unique(
			array(
				trailingslashit( get_stylesheet_directory() ) . 'woocommerce/',
				trailingslashit( get_stylesheet_directory() ),
				trailingslashit( get_template_directory() ) . 'woocommerce/',
				trailingslashit( get_template_directory() ),
			)
		);
		$out  = array();
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || 'php' !== strtolower( $f->getExtension() ) ) {
					continue;
				}
				$rel = substr( wp_normalize_path( $f->getPathname() ), strlen( wp_normalize_path( $base ) ) );
				foreach ( $dirs as $dir ) {
					if ( is_file( $dir . $rel ) ) {
						$out[] = array(
							'file'    => $rel,
							'version' => self::file_version( $dir . $rel ),
							'core'    => self::file_version( $f->getPathname() ),
						);
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			return $out;
		}
		return $out;
	}

	/**
	 * Versión declarada en la cabecera de una plantilla (@version x.y.z).
	 */
	private static function file_version( $path ) {
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return '';
		}
		$head = (string) fread( $fh, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return preg_match( '/@version\s+([0-9.]+)/i', $head, $m ) ? rtrim( $m[1], '.' ) : '';
	}

	/**
	 * WOO-02: HPOS.
	 */
	private static function hpos() {
		$on      = Inochi_Pulso_Checks_General::hpos_enabled();
		$sync    = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' );
		$pending = null;
		$class   = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
		if ( ( $on || $sync ) && function_exists( 'wc_get_container' ) && class_exists( $class ) ) {
			try {
				$ds = wc_get_container()->get( $class );
				if ( is_callable( array( $ds, 'get_current_orders_pending_sync_count' ) ) ) {
					$pending = (int) $ds->get_current_orders_pending_sync_count();
				}
			} catch ( Throwable $e ) {
				$pending = null;
			}
		}
		if ( $on && ! $sync ) {
			// HPOS es la fuente de verdad y la tabla antigua no se mantiene: los «pendientes» son normales.
			$value = 'HPOS activo · sincronización desactivada';
			$ev    = $value . ( null !== $pending ? '. WooCommerce cuenta ' . $pending . ' pedidos distintos en la tabla antigua, que con la sincronización desactivada no se usa: no es un problema.' : '.' );
			return self::auto( 'WOO-02', $value, 'OK', $ev );
		}
		$value = 'HPOS ' . ( $on ? 'activo' : 'desactivado' ) . ' · sincronización ' . ( $sync ? 'activa' : 'inactiva' ) . ( null !== $pending ? ' · ' . $pending . ' pendientes' : '' );
		$state = ( $on && ! $pending ) ? 'OK' : 'Aviso';
		return self::auto( 'WOO-02', $value, $state, $value . '.' );
	}

	/**
	 * WOO-03: páginas de WooCommerce.
	 */
	private static function pages() {
		$pages   = array(
			'shop'      => 'Tienda',
			'cart'      => 'Carrito',
			'checkout'  => 'Finalizar compra',
			'myaccount' => 'Mi cuenta',
		);
		$missing = array();
		$lines   = array();
		foreach ( $pages as $key => $label ) {
			$id      = wc_get_page_id( $key );
			$status  = $id > 0 ? get_post_status( $id ) : false;
			$lines[] = $label . ': ' . ( $id > 0 ? 'ID ' . $id . ' (' . ( $status ? $status : 'no existe' ) . ')' : 'sin asignar' );
			if ( 'publish' !== $status ) {
				$missing[] = $label;
			}
		}
		if ( $missing ) {
			return self::auto( 'WOO-03', 'Falta o no está publicada: ' . implode( ', ', $missing ), 'Fallo', implode( "\n", $lines ) );
		}
		return self::auto( 'WOO-03', 'Las 4 páginas asignadas y publicadas', 'OK', implode( "\n", $lines ) );
	}

	/**
	 * WOO-04: pasarelas activas y modo pruebas (detección por ajustes habituales).
	 */
	private static function gateways() {
		$active = array();
		$test   = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
			if ( 'yes' !== $gw->enabled ) {
				continue;
			}
			$active[] = $id;
			$settings = (array) $gw->settings;
			foreach ( array( 'testmode', 'test_mode', 'sandbox', 'sandbox_mode', 'environment', 'mode', 'test', 'is_test_mode' ) as $k ) {
				if ( isset( $settings[ $k ] ) && in_array( strtolower( (string) $settings[ $k ] ), array( 'yes', '1', 'true', 'sandbox', 'test', 'testing' ), true ) ) {
					$test[] = $id;
					break;
				}
			}
		}
		$value = $active ? 'Activas: ' . implode( ', ', $active ) : 'Ninguna pasarela activa';
		$ev    = $value . '.' . ( $test ? "\nEn modo pruebas o sandbox: " . implode( ', ', $test ) . '.' : "\nNo se detecta modo pruebas en los ajustes habituales: confírmalo entrando en cada pasarela (WooCommerce → Ajustes → Pagos)." );
		if ( $test ) {
			return self::auto( 'WOO-04', $value . ' · en pruebas: ' . implode( ', ', $test ), 'Fallo', $ev );
		}
		return self::partial( 'WOO-04', $value, $ev );
	}

	/**
	 * WOO-05: webhooks con fallos (solo el dominio de destino).
	 */
	private static function webhooks() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_webhooks';
		if ( ! self::table_exists( $table ) ) {
			return self::auto( 'WOO-05', 'Sin webhooks', 'OK', 'No existe la tabla de webhooks.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
		$rows     = $wpdb->get_results( "SELECT webhook_id, name, status, failure_count, delivery_url FROM {$table}", ARRAY_A );
		$disabled = 0;
		$failing  = 0;
		$list     = array();
		foreach ( (array) $rows as $r ) {
			$fails = (int) $r['failure_count'];
			if ( 'disabled' === $r['status'] && $fails > 0 ) {
				++$disabled;
			} elseif ( $fails > 0 ) {
				++$failing;
			}
			$list[] = array(
				'id'      => $r['webhook_id'],
				'nombre'  => $r['name'],
				'estado'  => $r['status'],
				'fallos'  => $fails,
				'destino' => (string) wp_parse_url( $r['delivery_url'], PHP_URL_HOST ),
			);
		}
		$state = $disabled ? 'Fallo' : ( $failing ? 'Aviso' : 'OK' );
		$value = sprintf( '%d webhooks · %d desactivados por fallos · %d con fallos', count( $list ), $disabled, $failing );
		$ev    = $list ? Inochi_Pulso_Util::text_table( $list, array( 'id', 'nombre', 'estado', 'fallos', 'destino' ) ) . "\nSolo se guarda el dominio de destino." : 'Sin webhooks.';
		return self::auto( 'WOO-05', $value, $state, $ev );
	}

	/**
	 * WOO-06: pedidos atascados más de 7 días.
	 */
	private static function stuck_orders() {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		if ( Inochi_Pulso_Checks_General::hpos_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status AS s, COUNT(*) AS n FROM {$table} WHERE type = 'shop_order' AND status IN ('wc-pending','wc-on-hold','wc-processing') AND date_created_gmt < %s GROUP BY status", $since ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_status AS s, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status IN ('wc-pending','wc-on-hold','wc-processing') AND post_date_gmt < %s GROUP BY post_status", $since ), ARRAY_A );
		}
		$by = array(
			'wc-pending'    => 0,
			'wc-on-hold'    => 0,
			'wc-processing' => 0,
		);
		foreach ( (array) $rows as $r ) {
			$by[ $r['s'] ] = (int) $r['n'];
		}
		$stuck = $by['wc-processing'] + $by['wc-on-hold'];
		$state = 0 === $stuck ? 'OK' : ( $stuck <= 10 ? 'Aviso' : 'Fallo' );
		$value = sprintf( '%d procesando y %d en espera > 7 días · %d pendientes de pago antiguos', $by['wc-processing'], $by['wc-on-hold'], $by['wc-pending'] );
		$ev    = $value . ".\nLos «pendientes de pago» antiguos son carritos abandonados: no puntúan. Confirma con el cliente si los «procesando» antiguos son normales en su negocio.";
		return self::auto( 'WOO-06', $value, $state, $ev );
	}

	/**
	 * WOO-07: acciones programadas fallidas en 30 días.
	 */
	private static function failed_actions() {
		global $wpdb;
		$table = self::as_table();
		if ( ! self::table_exists( $table ) ) {
			return self::partial( 'WOO-07', 'Sin tabla de Action Scheduler', 'No existe la tabla actionscheduler_actions.' );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hook, COUNT(*) AS n, MAX(last_attempt_gmt) AS last FROM {$table} WHERE status = 'failed' AND last_attempt_gmt > %s GROUP BY hook ORDER BY n DESC LIMIT 15",
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		// phpcs:enable
		$since    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND last_attempt_gmt > %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
		$critical = array();
		$list     = array();
		foreach ( (array) $rows as $r ) {
			if ( preg_match( '/stock|inventor|sync|erp|payment|pago|refund|ship|envio|envío|subscription/i', $r['hook'] ) ) {
				$critical[] = $r['hook'];
			}
			$list[] = array(
				'hook'     => $r['hook'],
				'fallidas' => $r['n'],
				'última'   => $r['last'],
			);
		}
		if ( $total > 100 || $critical ) {
			$state = 'Fallo';
		} elseif ( $total >= 10 ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		$value = sprintf( '%s fallidas en 30 días', Inochi_Pulso_Util::num( $total ) ) . ( $critical ? ' · afectan a: ' . implode( ', ', array_slice( $critical, 0, 3 ) ) : '' );
		$ev    = $list ? Inochi_Pulso_Util::text_table( $list, array( 'hook', 'fallidas', 'última' ) ) . "\nEl motivo de cada fallo: WooCommerce → Estado → Acciones programadas → Fallidas (columna Registro)." : 'Sin acciones fallidas en 30 días.';
		return self::auto( 'WOO-07', $value, $state, $ev );
	}

	/**
	 * WOO-08: acciones pendientes con más de 1 h de retraso.
	 */
	private static function late_actions() {
		global $wpdb;
		$table = self::as_table();
		if ( ! self::table_exists( $table ) ) {
			return self::partial( 'WOO-08', 'Sin tabla de Action Scheduler', 'No existe la tabla actionscheduler_actions.' );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hook, COUNT(*) AS n FROM {$table} WHERE status = 'pending' AND scheduled_date_gmt < %s GROUP BY hook ORDER BY n DESC LIMIT 15",
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			),
			ARRAY_A
		);
		// phpcs:enable
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND scheduled_date_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
		$list  = array();
		foreach ( (array) $rows as $r ) {

			$list[] = array(
				'hook'      => $r['hook'],
				'atrasadas' => $r['n'],
			);
		}
		$state = Inochi_Pulso_Util::grade( $total, 10, 100 );
		$ev    = $list ? Inochi_Pulso_Util::text_table( $list, array( 'hook', 'atrasadas' ) ) : 'Sin acciones atrasadas.';
		return self::auto( 'WOO-08', Inochi_Pulso_Util::num( $total ) . ' atrasadas > 1 h', $state, $ev );
	}

	/**
	 * WOO-09: sesiones caducadas.
	 */
	private static function sessions() {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		if ( ! self::table_exists( $table ) ) {
			return self::auto( 'WOO-09', 'Sin tabla de sesiones', 'OK', 'No existe la tabla woocommerce_sessions.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
		$expired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_expiry < %d", time() ) );
		$bytes   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT (data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) );
		if ( $expired > 100000 || $bytes > 200 * 1048576 ) {
			$state = 'Fallo';
		} elseif ( $expired >= 10000 ) {
			$state = 'Aviso';
		} else {
			$state = 'OK';
		}
		$value = sprintf( '%s caducadas · tabla %s', Inochi_Pulso_Util::num( $expired ), Inochi_Pulso_Util::bytes( $bytes ) );
		return self::auto( 'WOO-09', $value, $state, $value . '.' );
	}
}
