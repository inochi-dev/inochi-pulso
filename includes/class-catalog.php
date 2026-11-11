<?php
/**
 * Catálogo de comprobaciones de la versión 1.0, puntuación y prioridad.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo y puntuación.
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
	const SCORE_K      = 60; // Puntuación de área = 100 × K / (K + penalizaciones): cada problema resta menos y nunca llega a 0.
	const SEVERITY_ORD = array(
		'Crítica' => 4,
		'Alta'    => 3,
		'Media'   => 2,
		'Baja'    => 1,
	);

	const PHASES = array(
		'urgente'        => 'Urgente',
		'estabilizacion' => 'Estabilización',
		'mejora'         => 'Mejora',
	);

	/**
	 * Catálogo del plugin (data/checks.json).
	 */
	public static function map() {
		static $map = null;
		if ( null === $map ) {
			$map = json_decode( (string) file_get_contents( INOCHI_PULSO_DIR . 'data/checks.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local del plugin.
			$map = is_array( $map ) ? $map : array();
		}
		return $map;
	}

	/**
	 * Fase de un hallazgo: urgente (crítica con Fallo), estabilización (alta con Fallo) o mejora.
	 *
	 * @param string $code  Código.
	 * @param string $state Estado.
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
	 * Comprobaciones indexadas por código: code, area, name, threshold, severity.
	 */
	public static function all() {
		static $all = null;
		if ( null === $all ) {
			$all = array();
			foreach ( (array) ( self::map()['catalog'] ?? array() ) as $c ) {
				$all[ $c['code'] ] = $c;
			}
		}
		return $all;
	}

	/**
	 * Una comprobación.
	 */
	public static function get( $code ) {
		$all = self::all();
		return $all[ $code ] ?? null;
	}

	/**
	 * Penalización de una comprobación según su estado.
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
	 * Puntuación de un área a partir de la suma de penalizaciones.
	 *
	 * @param float $penalty Penalizaciones.
	 */
	public static function area_score( $penalty ) {
		return (int) round( 100 * self::SCORE_K / ( self::SCORE_K + max( 0, (float) $penalty ) ) );
	}

	/**
	 * Objetivo legible a partir del umbral («OK …» → «…»).
	 *
	 * @param string $code Código.
	 */
	public static function target( $code ) {
		$c = self::get( $code );
		if ( ! $c || ! preg_match( '/^OK\s+([^·]+)/u', (string) $c['threshold'], $m ) ) {
			return '';
		}
		$t = trim( $m[1] );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 ) : ucfirst( $t );
	}

	/**
	 * Puntuación por área y global.
	 *
	 * @param array $findings code => ['state' => …].
	 * @param bool  $is_woo   Hay WooCommerce.
	 * @return array{areas: array, global: int|null, pending: int, fails: int, warns: int, nodata: int}
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
				'pending' => 0,
				'nodata'  => 0,
				'score'   => null,
			);
		}
		foreach ( self::all() as $code => $c ) {
			$area  = $c['area'];
			$state = $findings[ $code ]['state'] ?? '';
			if ( ! isset( $areas[ $area ] ) || ! $areas[ $area ]['applies'] ) {
				continue;
			}
			if ( '' === $state ) {
				++$areas[ $area ]['pending'];
				continue;
			}
			if ( self::NO_DATA === $state ) {
				++$areas[ $area ]['nodata'];
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
			'pending' => 0,
			'fails'   => 0,
			'warns'   => 0,
			'nodata'  => 0,
		);
		foreach ( $areas as $name => $a ) {
			if ( ! $a['applies'] ) {
				continue;
			}
			$areas[ $name ]['score'] = self::area_score( $a['penalty'] );
			$sum_w                  += $a['weight'];
			$sum_s                  += $areas[ $name ]['score'] * $a['weight'];
			$totals['pending']      += $a['pending'];
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
	 * Orden sugerido de hallazgos: críticos con Fallo primero, después por penalización y esfuerzo.
	 *
	 * @param array $findings code => finding.
	 * @return string[] Códigos ordenados (solo Fallo y Aviso).
	 */
	public static function rank( array $findings ) {
		$items = array();
		foreach ( $findings as $code => $f ) {
			$c = self::get( $code );
			if ( ! $c || ! in_array( $f['state'] ?? '', array( 'Fallo', 'Aviso' ), true ) ) {
				continue;
			}
			$k       = Inochi_Pulso_Knowledge::finding( $code, $f['state'] );
			$items[] = array(
				'code'     => $code,
				'critical' => ( 'Crítica' === $c['severity'] && 'Fallo' === $f['state'] ) ? 1 : 0,
				'pen'      => self::penalty( $c['severity'], $f['state'] ),
				'hours'    => $k ? (float) $k['hours'] : 4,
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
