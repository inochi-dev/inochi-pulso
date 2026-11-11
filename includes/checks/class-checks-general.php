<?php
/**
 * GEN: datos generales del sitio (sin puntuación).
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Datos generales.
 */
class Inochi_Pulso_Checks_General extends Inochi_Pulso_Checks_Base {

	/**
	 * Ejecuta el paso.
	 */
	public static function run() {
		global $wpdb, $wp_version;

		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$themes = $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );
		if ( $parent ) {
			$themes .= ' (hijo de ' . $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) . ')';
		}

		$langs = array( get_locale() );
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_get_languages' ) ) {
			$wpml  = (array) apply_filters( 'wpml_active_languages', array(), array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de WPML.
			$langs = array_merge( array( 'WPML:' ), array_keys( $wpml ) );
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			$langs = array_merge( array( 'Polylang:' ), (array) pll_languages_list() );
		}

		$is_woo   = Inochi_Pulso_Util::has_woocommerce();
		$products = $is_woo ? (int) ( wp_count_posts( 'product' )->publish ?? 0 ) : 0;
		$orders   = $is_woo ? self::count_orders() : 0;
		$users    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$server = trim( $server . ' · PHP ' . PHP_SAPI );

		$sitio = array(
			'Dominio auditado'               => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'Fecha de la auditoría'          => gmdate( 'Y-m-d' ),
			'¿Tiene WooCommerce?'            => $is_woo ? 'Sí' : 'No',
			'Versión de WordPress'           => $wp_version,
			'Versión de PHP (web)'           => PHP_VERSION,
			'Versión de MySQL/MariaDB'       => (string) $wpdb->get_var( 'SELECT VERSION()' ),
			'Servidor web y alojamiento'     => $server . ' (alojamiento: completar a mano)',
			'Tema activo / tema padre'       => $themes,
			'Multisite e idiomas'            => ( is_multisite() ? 'Multisite' : 'Sitio único' ) . ' · ' . implode( ' ', array_filter( $langs ) ),
			'Productos / pedidos / usuarios' => $is_woo
				? sprintf( '%s productos · %s pedidos · %s usuarios', Inochi_Pulso_Util::num( $products ), Inochi_Pulso_Util::num( $orders ), Inochi_Pulso_Util::num( $users ) )
				: sprintf( 'Sin WooCommerce · %s usuarios', Inochi_Pulso_Util::num( $users ) ),
		);

		return array(
			'findings' => array(),
			'payload'  => array(
				'sitio' => $sitio,
				'gen'   => array(
					'is_woo'          => $is_woo,
					'home_url'        => home_url( '/' ),
					'locale'          => get_locale(),
					'products'        => $products,
					'orders'          => $orders,
					'orders_last_30d' => $is_woo ? self::count_orders( 30 ) : 0,
				),
			),
		);
	}

	/**
	 * Número de pedidos (HPOS o tabla de entradas). Si $days > 0, solo los recientes.
	 */
	public static function count_orders( $days = 0 ) {
		global $wpdb;
		$since = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '1970-01-01 00:00:00';
		if ( self::hpos_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla interna.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = 'shop_order' AND date_created_gmt >= %s", $since ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status NOT IN ('auto-draft','trash') AND post_date_gmt >= %s", $since ) );
	}

	/**
	 * HPOS activo.
	 */
	public static function hpos_enabled() {
		return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
	}
}
