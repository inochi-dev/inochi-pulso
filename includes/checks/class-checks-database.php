<?php
/**
 * REN-07, REN-09, REN-10, REN-11 y tamaño de la base de datos.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones de base de datos (solo SELECT).
 */
class Inochi_Pulso_Checks_Database extends Inochi_Pulso_Checks_Base {

	/**
	 * Valores de autoload que WordPress carga (6.6+).
	 */
	public static function autoload_values() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return wp_autoload_values_to_autoload();
		}
		return array( 'yes', 'on', 'auto-on', 'auto' );
	}

	/**
	 * Placeholder SQL para la lista de valores de autoload.
	 */
	public static function autoload_in() {
		global $wpdb;
		$values = self::autoload_values();
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared -- placeholders generados.
		return $wpdb->prepare( implode( ',', array_fill( 0, count( $values ), '%s' ) ), $values );
	}

	/**
	 * Ejecuta el paso.
	 */
	public static function run() {
		$findings = array();
		$payload  = array();

		list( $f, $p ) = self::autoload_total();
		$findings[]    = $f;
		$payload       = array_merge( $payload, $p );

		list( $f, $p ) = self::tables();
		$findings[]    = $f;
		$payload       = array_merge( $payload, $p );

		$findings[] = self::transients();
		$findings[] = self::revisions();

		return array(
			'findings' => array_filter( $findings ),
			'payload'  => $payload,
		);
	}

	/**
	 * REN-07: tamaño total del autoload.
	 */
	private static function autoload_total() {
		global $wpdb;
		$in = self::autoload_in();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in ya preparado.
		$row   = $wpdb->get_row( "SELECT SUM(LENGTH(option_value)) AS bytes, COUNT(*) AS n FROM {$wpdb->options} WHERE autoload IN ({$in})", ARRAY_A );
		$bytes = (float) ( $row['bytes'] ?? 0 );
		$n     = (int) ( $row['n'] ?? 0 );
		$mb    = $bytes / 1048576;
		$state = Inochi_Pulso_Util::grade( $mb, 0.8, 2 );
		$value = sprintf( '%s MB (%s opciones)', Inochi_Pulso_Util::num( $mb, 2 ), Inochi_Pulso_Util::num( $n ) );
		$ev    = "SELECT SUM(LENGTH(option_value)), COUNT(*) FROM {$wpdb->options} WHERE autoload IN (" . implode( ',', self::autoload_values() ) . ")\n→ " . Inochi_Pulso_Util::num( $bytes ) . " bytes, {$n} opciones.";
		return array(
			self::auto( 'REN-07', $value, $state, $ev ),
			array(
				'rendimiento' => array(
					'autoload_mb' => round( $mb, 3 ),
					'autoload_n'  => $n,
				),
			),
		);
	}

	/**
	 * REN-09: tablas más grandes del sitio (solo las del prefijo de WordPress).
	 */
	private static function tables() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS t, (data_length + index_length) AS bytes, table_rows AS r FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s ORDER BY (data_length + index_length) DESC',
				$like
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array( self::partial( 'REN-09', 'Sin acceso a information_schema', 'La consulta a information_schema no devolvió datos (permisos del usuario de la base de datos).' ), array() );
		}
		$total = 0;
		foreach ( $rows as $row ) {
			$total += (float) $row['bytes'];
		}
		// Tablas de contenido: su tamaño no es un problema en sí.
		$content = array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_product_meta_lookup', 'wc_order_product_lookup', 'wc_order_stats', 'wc_customer_lookup' );
		$state   = 'OK';
		$worst   = '';
		foreach ( $rows as $row ) {
			$short = substr( $row['t'], strlen( $wpdb->prefix ) );
			if ( in_array( $short, $content, true ) ) {
				continue;
			}
			$mb    = (float) $row['bytes'] / 1048576;
			$share = $total > 0 ? (float) $row['bytes'] / $total : 0;
			if ( $mb > 500 || ( $share > 0.3 && $total > 200 * 1048576 ) ) {
				$state = 'Fallo';
				$worst = $row['t'];
				break;
			}
			if ( $mb >= 100 && 'OK' === $state ) {
				$state = 'Aviso';
				$worst = $row['t'];
			}
		}
		$top  = array_slice( $rows, 0, 15 );
		$list = array();
		foreach ( $top as $row ) {
			$list[] = array(
				'tabla' => $row['t'],
				'MB'    => Inochi_Pulso_Util::num( (float) $row['bytes'] / 1048576, 1 ),
				'filas' => Inochi_Pulso_Util::num( (float) $row['r'] ),
			);
		}
		$value = sprintf( 'BD %s · mayor: %s (%s)', Inochi_Pulso_Util::bytes( $total ), $top[0]['t'], Inochi_Pulso_Util::bytes( $top[0]['bytes'] ) );
		if ( $worst && $worst !== $top[0]['t'] ) {
			$value .= ' · a revisar: ' . $worst;
		}
		$ev = "Tablas con prefijo {$wpdb->prefix} (15 mayores; las de contenido no puntúan; la regla del 30 % solo se aplica si la BD supera 200 MB):\n" . Inochi_Pulso_Util::text_table( $list, array( 'tabla', 'MB', 'filas' ) );
		return array(
			self::auto( 'REN-09', $value, $state, $ev ),
			array( 'gen_db' => array( 'db_size' => $total ) ),
		);
	}

	/**
	 * REN-10: transients caducados.
	 */
	private static function transients() {
		global $wpdb;
		$n    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				time()
			)
		);
		$note = wp_using_ext_object_cache() ? ' Hay caché de objetos persistente: los transients nuevos no se guardan en la base de datos.' : '';
		return self::auto( 'REN-10', Inochi_Pulso_Util::num( $n ) . ' caducados', Inochi_Pulso_Util::grade( $n, 500, 5000 ), "Transients con timeout anterior a ahora en {$wpdb->options}: {$n}." . $note );
	}

	/**
	 * REN-11: revisiones.
	 */
	private static function revisions() {
		global $wpdb;
		$n       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$limit   = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : true;
		$limited = false === $limit || ( true !== $limit && is_numeric( $limit ) && (int) $limit >= 0 );
		if ( $n > 50000 ) {
			$state = 'Fallo';
		} elseif ( $n < 5000 || $limited ) {
			$state = 'OK';
		} else {
			$state = 'Aviso';
		}
		$limit_txt = $limited ? 'límite ' . (int) $limit : ( false === $limit ? 'revisiones desactivadas' : 'sin límite' );
		return self::auto( 'REN-11', Inochi_Pulso_Util::num( $n ) . ' revisiones · ' . $limit_txt, $state, "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision' → {$n}. WP_POST_REVISIONS: " . ( defined( 'WP_POST_REVISIONS' ) ? var_export( WP_POST_REVISIONS, true ) : 'no definido' ) . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}
}
