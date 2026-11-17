<?php
/**
 * General site data (not scored).
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * General data.
 */
class Inochi_Pulso_Checks_General extends Inochi_Pulso_Checks_Base {

	/**
	 * Runs the step.
	 */
	public static function run() {
		return array(
			'findings' => array(),
			'payload'  => array(
				'gen' => array(
					'is_woo' => Inochi_Pulso_Util::has_woocommerce(),
				),
			),
		);
	}

	/**
	 * Number of orders (HPOS or posts table). With $days > 0, only recent ones.
	 *
	 * @param int $days Days.
	 */
	public static function count_orders( $days = 0 ) {
		global $wpdb;
		$since = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '1970-01-01 00:00:00';
		if ( self::hpos_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'shop_order' AND date_created_gmt >= %s", $table, $since ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order' AND post_status NOT IN ('auto-draft','trash') AND post_date_gmt >= %s", $wpdb->posts, $since ) );
	}

	/**
	 * HPOS is active.
	 */
	public static function hpos_enabled() {
		return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
	}
}
