<?php
/**
 * Base of the check groups.
 *
 * Each step returns:
 * - findings: list of results (code, value, state, source, evidence).
 *   An empty state means Pulso cannot decide it: it is shown as "No data" and does not score.
 * - payload: data for the report (cost per plugin, plugins, tables…).
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Result helpers.
 */
abstract class Inochi_Pulso_Checks_Base {

	/**
	 * Result with a state decided by Pulso.
	 *
	 * @param string $code     Code.
	 * @param string $value    Measured value.
	 * @param string $state    State key.
	 * @param string $evidence Evidence.
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
	 * Result with data but without a certain state ("No data"), unless one is given.
	 *
	 * @param string $code     Code.
	 * @param string $value    Measured value.
	 * @param string $evidence Evidence.
	 * @param string $state    State known for sure (for example, an obvious Fail).
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
