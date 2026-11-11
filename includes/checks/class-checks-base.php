<?php
/**
 * Base de los grupos de comprobaciones.
 *
 * Cada paso devuelve:
 * - findings: lista de resultados (code, value, state, source, evidence).
 *   state vacío = Pulso no puede decidir el estado (se completa a mano).
 *   source: 'auto' (dato y estado) o 'partial' (dato sin estado definitivo).
 * - payload: datos para el informe (sitio, tiempos, autoload, plugins).
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Utilidades de resultado.
 */
abstract class Inochi_Pulso_Checks_Base {

	/**
	 * Resultado con estado decidido por Pulso.
	 */
	protected static function auto( $code, $value, $state, $evidence = '' ) {
		return array(
			'code'     => $code,
			'value'    => $value,
			'state'    => $state,
			'source'   => 'auto',
			'evidence' => Inochi_Pulso_Util::clip( $evidence ),
		);
	}

	/**
	 * Resultado con dato pero estado pendiente de la parte manual.
	 * Si se pasa $state, es un estado que ya se sabe con certeza (p. ej. un Fallo evidente).
	 */
	protected static function partial( $code, $value, $evidence = '', $state = '' ) {
		return array(
			'code'     => $code,
			'value'    => $value,
			'state'    => $state,
			'source'   => 'partial',
			'evidence' => Inochi_Pulso_Util::clip( $evidence ),
		);
	}
}
