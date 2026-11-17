<?php
/**
 * Scan in chained steps (one REST request per step). No WP-Cron.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Step runner.
 */
class Inochi_Pulso_Scanner {

	/**
	 * Steps in order: key => [label, callable].
	 */
	public static function steps() {
		return array(
			'general'     => array( __( 'General data', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_General', 'run' ) ),
			'database'    => array( __( 'Database', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Database', 'run' ) ),
			'cost'        => array( __( 'Cost per plugin', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Cost', 'run' ) ),
			'runtime'     => array( __( 'PHP and settings', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Runtime', 'run' ) ),
			'ttfb'        => array( __( 'Response times', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Http', 'run_ttfb' ) ),
			'headers'     => array( __( 'Cache, HTTPS and exposure', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Http', 'run_headers' ) ),
			'security'    => array( __( 'Plugins, updates and accounts', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Security', 'run' ) ),
			'files'       => array( __( 'Files and permissions', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Files', 'run' ) ),
			'woocommerce' => array( __( 'WooCommerce', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_WooCommerce', 'run' ) ),
			'maintenance' => array( __( 'Maintenance and logs', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_Maintenance', 'run' ) ),
			'wporg'       => array( __( 'Plugins on WordPress.org (if turned on)', 'inochi-pulso' ), array( 'Inochi_Pulso_Checks_WPOrg', 'run' ) ),
		);
	}

	/**
	 * Step keys (for the REST argument; no translation needed).
	 */
	public static function step_keys() {
		return array( 'general', 'database', 'cost', 'runtime', 'ttfb', 'headers', 'security', 'files', 'woocommerce', 'maintenance', 'wporg' );
	}

	/**
	 * Public list of steps for the screen.
	 */
	public static function step_list() {
		$out = array();
		foreach ( self::steps() as $key => $def ) {
			$out[] = array(
				'key'   => $key,
				'label' => $def[0],
			);
		}
		return $out;
	}

	/**
	 * Runs a step and saves its results. An error in a step does not stop the scan.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $step    Step key.
	 * @return array{ok:bool,error:string,count:int,again:bool,note:string}
	 */
	public static function run_step( $scan_id, $step ) {
		$steps = self::steps();
		if ( ! isset( $steps[ $step ] ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Unknown step.', 'inochi-pulso' ),
				'count' => 0,
			);
		}
		Inochi_Pulso_DB::update_scan( $scan_id, array( 'current_step' => $step ) );
		$error  = '';
		$count  = 0;
		$failed = array();
		$again  = false;
		$note   = '';
		ob_start(); // PHP notices printed by other plugins must not break the JSON response.
		try {
			Inochi_Pulso_Http_Probe::start_budget( 'ttfb' === $step ? 40 : 25 );
			$before = Inochi_Pulso_DB::get_scan( $scan_id );
			$result = call_user_func( $steps[ $step ][1], (array) ( $before['payload'] ?? array() ) );
			foreach ( (array) ( $result['findings'] ?? array() ) as $finding ) {
				if ( ! empty( $finding['code'] ) ) {
					if ( Inochi_Pulso_DB::save_finding( $scan_id, $finding ) ) {
						++$count;
					} else {
						$failed[] = $finding['code'];
					}
				}
			}
			if ( $failed ) {
				/* translators: %s: list of check codes. */
				$error = sprintf( __( 'Could not be saved: %s', 'inochi-pulso' ), implode( ', ', $failed ) );
			}
			$again   = ! empty( $result['again'] );
			$note    = (string) ( $result['note'] ?? '' );
			$payload = (array) ( $result['payload'] ?? array() );
			if ( $payload ) {
				$scan = Inochi_Pulso_DB::get_scan( $scan_id );
				foreach ( $payload as $k => $v ) {
					if ( is_array( $v ) && isset( $scan['payload'][ $k ] ) && is_array( $scan['payload'][ $k ] ) && ! wp_is_numeric_array( $v ) ) {
						$payload[ $k ] = array_merge( $scan['payload'][ $k ], $v );
					}
				}
				Inochi_Pulso_DB::merge_payload( $scan_id, $payload );
			}
		} catch ( Throwable $e ) {
			$error = $e->getMessage();
		}
		ob_end_clean();
		Inochi_Pulso_Http_Probe::start_budget( 0 );
		if ( '' !== $error ) {
			$scan          = Inochi_Pulso_DB::get_scan( $scan_id );
			$errs          = (array) ( $scan['payload']['errors'] ?? array() );
			$errs[ $step ] = Inochi_Pulso_Util::clip( Inochi_Pulso_Util::redact( $error ), 500 );
			Inochi_Pulso_DB::merge_payload( $scan_id, array( 'errors' => $errs ) );
		}
		return array(
			'ok'    => '' === $error,
			'error' => $error,
			'count' => $count,
			'again' => $again && '' === $error,
			'note'  => $note,
		);
	}

	/**
	 * Closes the scan.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function finish( $scan_id ) {
		Inochi_Pulso_DB::update_scan(
			$scan_id,
			array(
				'status'       => 'done',
				'current_step' => '',
				'finished_at'  => current_time( 'mysql', true ),
			)
		);
	}
}
