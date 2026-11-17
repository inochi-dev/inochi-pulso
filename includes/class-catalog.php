<?php
/**
 * Checks of version 1.0, scoring and priority.
 *
 * State, area and severity keys are internal identifiers (stored with each result);
 * the labels shown to the user come from the *_label() methods.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalog and scoring.
 */
class Inochi_Pulso_Catalog {

	const PENALTY      = array(
		'Crítica' => 40,
		'Alta'    => 15,
		'Media'   => 7,
		'Baja'    => 3,
	);
	const WARN_FACTOR  = 0.5;
	const AREAS        = array(
		'Rendimiento'   => array(
			'prefix' => 'REN',
			'weight' => 40,
		),
		'Seguridad'     => array(
			'prefix' => 'SEG',
			'weight' => 30,
		),
		'WooCommerce'   => array(
			'prefix' => 'WOO',
			'weight' => 20,
		),
		'Mantenimiento' => array(
			'prefix' => 'MAN',
			'weight' => 10,
		),
	);
	const STATES       = array( 'OK', 'Aviso', 'Fallo', 'N.A.', 'Sin datos' );
	const NO_DATA      = 'Sin datos';
	const SCORE_K      = 60; // Area score = 100 × K / (K + penalties): each problem takes away less and it never reaches 0.
	const SEVERITY_ORD = array(
		'Crítica' => 4,
		'Alta'    => 3,
		'Media'   => 2,
		'Baja'    => 1,
	);
	const PHASES       = array( 'urgente', 'estabilizacion', 'mejora' );

	/**
	 * Checks: code => area, severity, name.
	 */
	private static function data() {
		return array(
			'REN-01' => array( 'Rendimiento', 'Alta', __( 'Server response time (TTFB)', 'inochi-pulso' ) ),
			'REN-05' => array( 'Rendimiento', 'Alta', __( 'Page cache', 'inochi-pulso' ) ),
			'REN-06' => array( 'Rendimiento', 'Media', __( 'Persistent object cache', 'inochi-pulso' ) ),
			'REN-07' => array( 'Rendimiento', 'Alta', __( 'Total autoload size', 'inochi-pulso' ) ),
			'REN-08' => array( 'Rendimiento', 'Media', __( 'Autoload from inactive or deleted plugins', 'inochi-pulso' ) ),
			'REN-09' => array( 'Rendimiento', 'Media', __( 'Largest tables (logs, sessions, leftovers)', 'inochi-pulso' ) ),
			'REN-10' => array( 'Rendimiento', 'Baja', __( 'Expired transients', 'inochi-pulso' ) ),
			'REN-11' => array( 'Rendimiento', 'Baja', __( 'Post revisions', 'inochi-pulso' ) ),
			'REN-12' => array( 'Rendimiento', 'Media', __( 'Cron: late and orphaned events', 'inochi-pulso' ) ),
			'REN-14' => array( 'Rendimiento', 'Media', __( 'PHP settings (memory_limit, OPcache)', 'inochi-pulso' ) ),
			'REN-16' => array( 'Rendimiento', 'Baja', __( 'Number of active plugins', 'inochi-pulso' ) ),
			'SEG-01' => array( 'Seguridad', 'Alta', __( 'Pending updates', 'inochi-pulso' ) ),
			'SEG-03' => array( 'Seguridad', 'Alta', __( 'Plugins abandoned or closed on WordPress.org', 'inochi-pulso' ) ),
			'SEG-04' => array( 'Seguridad', 'Alta', __( 'Plugins from outside WordPress.org', 'inochi-pulso' ) ),
			'SEG-06' => array( 'Seguridad', 'Alta', __( 'Supported PHP version', 'inochi-pulso' ) ),
			'SEG-07' => array( 'Seguridad', 'Alta', __( 'HTTPS (redirect, certificate, HSTS)', 'inochi-pulso' ) ),
			'SEG-08' => array( 'Seguridad', 'Baja', __( 'Security headers', 'inochi-pulso' ) ),
			'SEG-09' => array( 'Seguridad', 'Alta', __( 'Exposed debugging (errors on screen, debug.log)', 'inochi-pulso' ) ),
			'SEG-10' => array( 'Seguridad', 'Media', __( 'Dashboard file editor', 'inochi-pulso' ) ),
			'SEG-11' => array( 'Seguridad', 'Alta', __( 'Administrator accounts', 'inochi-pulso' ) ),
			'SEG-12' => array( 'Seguridad', 'Baja', __( 'User enumeration', 'inochi-pulso' ) ),
			'SEG-13' => array( 'Seguridad', 'Baja', __( 'XML-RPC', 'inochi-pulso' ) ),
			'SEG-14' => array( 'Seguridad', 'Alta', __( 'File permissions', 'inochi-pulso' ) ),
			'SEG-15' => array( 'Seguridad', 'Crítica', __( 'Leftover files reachable from the web (.sql, .zip, info.php)', 'inochi-pulso' ) ),
			'WOO-01' => array( 'WooCommerce', 'Media', __( 'WooCommerce version and overridden templates', 'inochi-pulso' ) ),
			'WOO-02' => array( 'WooCommerce', 'Baja', __( 'HPOS and order sync', 'inochi-pulso' ) ),
			'WOO-03' => array( 'WooCommerce', 'Alta', __( 'WooCommerce pages assigned', 'inochi-pulso' ) ),
			'WOO-05' => array( 'WooCommerce', 'Alta', __( 'Failing webhooks', 'inochi-pulso' ) ),
			'WOO-06' => array( 'WooCommerce', 'Media', __( 'Stuck orders (processing or on hold > 7 days)', 'inochi-pulso' ) ),
			'WOO-07' => array( 'WooCommerce', 'Alta', __( 'Failed scheduled actions (30 days)', 'inochi-pulso' ) ),
			'WOO-08' => array( 'WooCommerce', 'Alta', __( 'Scheduled actions late > 1 h', 'inochi-pulso' ) ),
			'WOO-09' => array( 'WooCommerce', 'Baja', __( 'Expired sessions', 'inochi-pulso' ) ),
			'MAN-06' => array( 'Mantenimiento', 'Baja', __( 'Automatic updates', 'inochi-pulso' ) ),
			'MAN-07' => array( 'Mantenimiento', 'Alta', __( 'Recurring PHP errors (7 days)', 'inochi-pulso' ) ),
		);
	}

	/**
	 * Checks indexed by code: code, area, name, severity.
	 */
	public static function all() {
		$all = array();
		foreach ( self::data() as $code => $c ) {
			$all[ $code ] = array(
				'code'     => $code,
				'area'     => $c[0],
				'severity' => $c[1],
				'name'     => $c[2],
			);
		}
		return $all;
	}

	/**
	 * One check.
	 *
	 * @param string $code Code.
	 */
	public static function get( $code ) {
		$all = self::all();
		return $all[ $code ] ?? null;
	}

	/**
	 * Label of a state.
	 *
	 * @param string $state State key.
	 */
	public static function state_label( $state ) {
		$labels = array(
			'OK'        => _x( 'OK', 'check state', 'inochi-pulso' ),
			'Aviso'     => _x( 'Warning', 'check state', 'inochi-pulso' ),
			'Fallo'     => _x( 'Fail', 'check state', 'inochi-pulso' ),
			'N.A.'      => _x( 'N/A', 'check state', 'inochi-pulso' ),
			'Sin datos' => _x( 'No data', 'check state', 'inochi-pulso' ),
		);
		return $labels[ $state ] ?? (string) $state;
	}

	/**
	 * Label of an area.
	 *
	 * @param string $area Area key.
	 */
	public static function area_label( $area ) {
		$labels = array(
			'Rendimiento'   => __( 'Performance', 'inochi-pulso' ),
			'Seguridad'     => __( 'Security', 'inochi-pulso' ),
			'WooCommerce'   => __( 'WooCommerce', 'inochi-pulso' ),
			'Mantenimiento' => __( 'Maintenance', 'inochi-pulso' ),
		);
		return $labels[ $area ] ?? (string) $area;
	}

	/**
	 * Label of a severity.
	 *
	 * @param string $severity Severity key.
	 */
	public static function severity_label( $severity ) {
		$labels = array(
			'Crítica' => __( 'Critical', 'inochi-pulso' ),
			'Alta'    => __( 'High', 'inochi-pulso' ),
			'Media'   => __( 'Medium', 'inochi-pulso' ),
			'Baja'    => __( 'Low', 'inochi-pulso' ),
		);
		return $labels[ $severity ] ?? (string) $severity;
	}

	/**
	 * Label of a priority phase.
	 *
	 * @param string $phase Phase key.
	 */
	public static function phase_label( $phase ) {
		$labels = array(
			'urgente'        => __( 'Urgent', 'inochi-pulso' ),
			'estabilizacion' => __( 'Stabilisation', 'inochi-pulso' ),
			'mejora'         => __( 'Improvement', 'inochi-pulso' ),
		);
		return $labels[ $phase ] ?? (string) $phase;
	}

	/**
	 * Phase of a finding: urgent (critical with Fail), stabilisation (high with Fail) or improvement.
	 *
	 * @param string $code  Code.
	 * @param string $state State.
	 */
	public static function phase( $code, $state ) {
		$c = self::get( $code );
		if ( $c && 'Fallo' === $state && 'Crítica' === $c['severity'] ) {
			return 'urgente';
		}
		if ( $c && 'Fallo' === $state && 'Alta' === $c['severity'] ) {
			return 'estabilizacion';
		}
		return 'mejora';
	}

	/**
	 * Penalty of a check by state.
	 *
	 * @param string $severity Severity.
	 * @param string $state    State.
	 */
	public static function penalty( $severity, $state ) {
		$base = self::PENALTY[ $severity ] ?? 0;
		if ( 'Fallo' === $state ) {
			return $base;
		}
		if ( 'Aviso' === $state ) {
			return $base * self::WARN_FACTOR;
		}
		return 0;
	}

	/**
	 * Area score from the sum of penalties.
	 *
	 * @param float $penalty Penalties.
	 */
	public static function area_score( $penalty ) {
		return (int) round( 100 * self::SCORE_K / ( self::SCORE_K + max( 0, (float) $penalty ) ) );
	}

	/**
	 * Score per area and global.
	 *
	 * @param array $findings code => ['state' => …].
	 * @param bool  $is_woo   WooCommerce is active.
	 * @return array{areas: array, global: int|null, fails: int, warns: int, nodata: int}
	 */
	public static function score( array $findings, $is_woo ) {
		$areas = array();
		foreach ( self::AREAS as $name => $def ) {
			$areas[ $name ] = array(
				'weight'  => $def['weight'],
				'applies' => 'WooCommerce' !== $name || $is_woo,
				'penalty' => 0,
				'fails'   => 0,
				'warns'   => 0,
				'nodata'  => 0,
				'score'   => null,
			);
		}
		foreach ( self::all() as $code => $c ) {
			$area  = $c['area'];
			$state = (string) ( $findings[ $code ]['state'] ?? '' );
			if ( ! isset( $areas[ $area ] ) || ! $areas[ $area ]['applies'] ) {
				continue;
			}
			if ( '' === $state || self::NO_DATA === $state ) {
				++$areas[ $area ]['nodata']; // Without a decided state there is no data: it does not score.
				continue;
			}
			$areas[ $area ]['penalty'] += self::penalty( $c['severity'], $state );
			if ( 'Fallo' === $state ) {
				++$areas[ $area ]['fails'];
			} elseif ( 'Aviso' === $state ) {
				++$areas[ $area ]['warns'];
			}
		}
		$sum_w  = 0;
		$sum_s  = 0;
		$totals = array(
			'fails'  => 0,
			'warns'  => 0,
			'nodata' => 0,
		);
		foreach ( $areas as $name => $a ) {
			if ( ! $a['applies'] ) {
				continue;
			}
			$areas[ $name ]['score'] = self::area_score( $a['penalty'] );
			$sum_w                  += $a['weight'];
			$sum_s                  += $areas[ $name ]['score'] * $a['weight'];
			$totals['fails']        += $a['fails'];
			$totals['warns']        += $a['warns'];
			$totals['nodata']       += $a['nodata'];
		}
		return array_merge(
			array(
				'areas'  => $areas,
				'global' => $sum_w ? (int) round( $sum_s / $sum_w ) : null,
			),
			$totals
		);
	}

	/**
	 * Suggested order of findings: critical with Fail first, then by penalty and effort.
	 *
	 * @param array $findings code => finding.
	 * @return string[] Codes in order (only Fail and Warning).
	 */
	public static function rank( array $findings ) {
		$items = array();
		foreach ( $findings as $code => $f ) {
			$c = self::get( $code );
			if ( ! $c || ! in_array( $f['state'] ?? '', array( 'Fallo', 'Aviso' ), true ) ) {
				continue;
			}
			$items[] = array(
				'code'     => $code,
				'critical' => ( 'Crítica' === $c['severity'] && 'Fallo' === $f['state'] ) ? 1 : 0,
				'pen'      => self::penalty( $c['severity'], $f['state'] ),
				'hours'    => Inochi_Pulso_Knowledge::hours( $code ),
			);
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return array( $b['critical'], $b['pen'], $a['hours'] ) <=> array( $a['critical'], $a['pen'], $b['hours'] );
			}
		);
		return wp_list_pluck( $items, 'code' );
	}
}
