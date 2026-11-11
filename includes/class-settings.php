<?php
/**
 * Ajustes de Pulso: solo el consentimiento para consultar WordPress.org.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes de Pulso.
 */
class Inochi_Pulso_Settings {

	const OPTION = 'inochi_pulso_settings';

	/**
	 * Valores por defecto. La consulta a WordPress.org está desactivada hasta que el administrador la acepte.
	 */
	private static function defaults() {
		return array(
			'wporg_consent'    => false,
			'wporg_consent_at' => '',
		);
	}

	/**
	 * Todos los ajustes.
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() );
	}

	/**
	 * Un ajuste.
	 *
	 * @param string $key Clave.
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/**
	 * ¿El administrador ha aceptado consultar WordPress.org?
	 */
	public static function wporg_allowed() {
		return true === self::get( 'wporg_consent' );
	}

	/**
	 * Guarda los ajustes desde el formulario (ya verificados el nonce y el permiso).
	 *
	 * @param array $input Datos del formulario (sin barras).
	 */
	public static function save( array $input ) {
		$current = self::all();
		$on      = ! empty( $input['wporg_consent'] );
		$new     = array(
			'wporg_consent'    => $on,
			'wporg_consent_at' => $on ? ( $current['wporg_consent'] ? $current['wporg_consent_at'] : gmdate( 'Y-m-d H:i:s' ) ) : '',
		);
		update_option( self::OPTION, $new, false );
	}
}
