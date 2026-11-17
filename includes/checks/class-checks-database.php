<?php
/**
 * REN-07, REN-09, REN-10 and REN-11. Also lists every table for the cost per plugin.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database checks (SELECT only).
 */
class Inochi_Pulso_Checks_Database extends Inochi_Pulso_Checks_Base {

	/**
	 * Autoload values that WordPress loads (6.6+).
	 */
	public static function autoload_values() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return wp_autoload_values_to_autoload();
		}
		return array( 'yes', 'on', 'auto-on', 'auto' );
	}

	/**
	 * Placeholders for the autoload values ("%s,%s,…"), to use with autoload_values() in prepare().
	 */
	public static function autoload_placeholders() {
		return implode( ',', array_fill( 0, count( self::autoload_values() ), '%s' ) );
	}

	/**
	 * Runs the step.
	 */
	public static function run() {
		list( $tables_finding, $tables ) = self::tables();
		return array(
			'findings' => array( self::autoload_total(), $tables_finding, self::transients(), self::revisions() ),
			'payload'  => array( 'tables' => $tables ),
		);
	}

	/**
	 * REN-07: total autoload size.
	 */
	private static function autoload_total() {
		global $wpdb;
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(LENGTH(option_value)) AS bytes, COUNT(*) AS n FROM %i WHERE autoload IN (' . self::autoload_placeholders() . ')', array_merge( array( $wpdb->options ), self::autoload_values() ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only scan; generated %s placeholders, one per autoload value.
		$bytes = (float) ( $row['bytes'] ?? 0 );
		$n     = (int) ( $row['n'] ?? 0 );
		$mb    = $bytes / 1048576;
		/* translators: 1: size in MB, 2: number of options. */
		$value = sprintf( __( '%1$s MB (%2$s options)', 'inochi-pulso' ), Inochi_Pulso_Util::num( $mb, 2 ), Inochi_Pulso_Util::num( $n ) );
		$ev    = "SELECT SUM(LENGTH(option_value)), COUNT(*) FROM {$wpdb->options} WHERE autoload IN (" . implode( ',', self::autoload_values() ) . ")\n→ " . sprintf(
			/* translators: 1: bytes, 2: number of options. */
			__( '%1$s bytes, %2$s options.', 'inochi-pulso' ),
			Inochi_Pulso_Util::num( $bytes ),
			Inochi_Pulso_Util::num( $n )
		);
		return self::auto( 'REN-07', $value, Inochi_Pulso_Util::grade( $mb, 0.8, 2 ), $ev );
	}

	/**
	 * REN-09: largest tables of the site (only those with the WordPress prefix).
	 *
	 * @return array{0: array, 1: array} finding and every table (name, bytes, rows).
	 */
	private static function tables() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS t, (data_length + index_length) AS bytes, table_rows AS r FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s ORDER BY (data_length + index_length) DESC',
				$like
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array( self::partial( 'REN-09', __( 'No access to information_schema', 'inochi-pulso' ), __( 'The information_schema query returned nothing (permissions of the database user).', 'inochi-pulso' ) ), array() );
		}
		$total  = 0;
		$tables = array();
		foreach ( $rows as $row ) {
			$total   += (float) $row['bytes'];
			$tables[] = array(
				'name'  => (string) $row['t'],
				'bytes' => (int) $row['bytes'],
				'rows'  => (int) $row['r'],
			);
		}
		// Content tables: their size is not a problem in itself.
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
		$h    = array( __( 'table', 'inochi-pulso' ), 'MB', __( 'rows', 'inochi-pulso' ) );
		$list = array();
		foreach ( $top as $row ) {
			$list[] = array(
				$h[0] => $row['t'],
				$h[1] => Inochi_Pulso_Util::num( (float) $row['bytes'] / 1048576, 1 ),
				$h[2] => Inochi_Pulso_Util::num( (float) $row['r'] ),
			);
		}
		/* translators: 1: database size, 2: largest table, 3: its size. */
		$value = sprintf( __( 'Database %1$s · largest: %2$s (%3$s)', 'inochi-pulso' ), Inochi_Pulso_Util::bytes( $total ), $top[0]['t'], Inochi_Pulso_Util::bytes( $top[0]['bytes'] ) );
		if ( $worst && $worst !== $top[0]['t'] ) {
			/* translators: %s: table name. */
			$value .= ' · ' . sprintf( __( 'to review: %s', 'inochi-pulso' ), $worst );
		}
		/* translators: %s: table prefix. */
		$ev = sprintf( __( 'Tables with the %s prefix (15 largest; content tables do not score; the 30 %% rule only applies above 200 MB):', 'inochi-pulso' ), $wpdb->prefix ) . "\n" . Inochi_Pulso_Util::text_table( $list, $h );
		return array( self::auto( 'REN-09', $value, $state, $ev ), $tables );
	}

	/**
	 * REN-10: expired transients.
	 */
	private static function transients() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				time()
			)
		);
		/* translators: 1: options table, 2: number. */
		$ev = sprintf( __( 'Transients with a timeout before now in %1$s: %2$s.', 'inochi-pulso' ), $wpdb->options, Inochi_Pulso_Util::num( $n ) );
		if ( wp_using_ext_object_cache() ) {
			$ev .= ' ' . __( 'There is a persistent object cache: new transients are not stored in the database.', 'inochi-pulso' );
		}
		/* translators: %s: number of expired transients. */
		return self::auto( 'REN-10', sprintf( __( '%s expired', 'inochi-pulso' ), Inochi_Pulso_Util::num( $n ) ), Inochi_Pulso_Util::grade( $n, 500, 5000 ), $ev );
	}

	/**
	 * REN-11: revisions.
	 */
	private static function revisions() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
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
		if ( false === $limit ) {
			$limit_txt = __( 'revisions turned off', 'inochi-pulso' );
		} elseif ( $limited ) {
			/* translators: %d: revisions limit. */
			$limit_txt = sprintf( __( 'limit %d', 'inochi-pulso' ), (int) $limit );
		} else {
			$limit_txt = __( 'no limit', 'inochi-pulso' );
		}
		/* translators: 1: number of revisions, 2: limit description. */
		$value = sprintf( __( '%1$s revisions · %2$s', 'inochi-pulso' ), Inochi_Pulso_Util::num( $n ), $limit_txt );
		$ev    = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision' → {$n}. WP_POST_REVISIONS: " . ( defined( 'WP_POST_REVISIONS' ) ? wp_json_encode( WP_POST_REVISIONS ) : '—' ) . '.';
		return self::auto( 'REN-11', $value, $state, $ev );
	}
}
