<?php
/**
 * Pulso settings: only the consent to contact WordPress.org.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulso settings.
 */
class Inochi_Pulso_Settings {

	const OPTION = 'inochi_pulso_settings';

	/**
	 * Defaults. The WordPress.org lookup is off until an administrator accepts it.
	 */
	private static function defaults() {
		return array(
			'wporg_consent'    => false,
			'wporg_consent_at' => '',
		);
	}

	/**
	 * All settings.
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/**
	 * Has an administrator accepted the WordPress.org lookup?
	 */
	public static function wporg_allowed() {
		return true === self::get( 'wporg_consent' );
	}

	/**
	 * Saves the settings from the form (nonce and capability already checked).
	 *
	 * @param array $input Form data (unslashed).
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
