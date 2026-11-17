<?php
/**
 * Site Health tests: the result of the last Pulso scan and its cost per plugin.
 *
 * They read the last finished scan; they do not scan again (a scan takes minutes).
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site Health integration.
 */
class Inochi_Pulso_Site_Health {

	const MAX_AGE_DAYS = 30;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
	}

	/**
	 * Registers the tests.
	 *
	 * @param array $tests Tests.
	 */
	public static function tests( $tests ) {
		$tests['direct']['inochi_pulso_scan'] = array(
			'label' => __( 'Inochi Pulso scan', 'inochi-pulso' ),
			'test'  => array( __CLASS__, 'test_scan' ),
		);
		$tests['direct']['inochi_pulso_cost'] = array(
			'label' => __( 'Inochi Pulso cost per plugin', 'inochi-pulso' ),
			'test'  => array( __CLASS__, 'test_cost' ),
		);
		return $tests;
	}

	/**
	 * Data of the last finished scan, or null.
	 */
	private static function last_scan() {
		foreach ( Inochi_Pulso_DB::list_scans( 20 ) as $s ) {
			if ( 'done' === $s['status'] ) {
				return Inochi_Pulso_Export::data( (int) $s['id'] );
			}
		}
		return null;
	}

	/**
	 * Link to the Pulso screen.
	 *
	 * @param string $text Link text.
	 */
	private static function action( $text ) {
		return sprintf( '<p><a href="%s">%s</a></p>', esc_url( Inochi_Pulso_Admin::url() ), esc_html( $text ) );
	}

	/**
	 * Common result fields.
	 *
	 * @param string $test   Test key.
	 * @param string $status good, recommended or critical.
	 * @param string $label  Label.
	 * @param string $desc   Description (HTML, escaped).
	 * @param string $action Actions (HTML, escaped).
	 */
	private static function result( $test, $status, $label, $desc, $action ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'inochi-pulso' ),
				'color' => 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' ),
			),
			'description' => $desc,
			'actions'     => $action,
			'test'        => $test,
		);
	}

	/**
	 * Is the scan older than the maximum age?
	 *
	 * @param array $d Scan data.
	 */
	private static function is_old( array $d ) {
		$ts = strtotime( (string) $d['scan']['created_at'] . ' UTC' );
		return ! $ts || $ts < time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Test: result of the last scan.
	 */
	public static function test_scan() {
		$d = self::last_scan();
		if ( ! $d ) {
			return self::result( 'inochi_pulso_scan', 'recommended', __( 'Inochi Pulso has not scanned this site yet', 'inochi-pulso' ), '<p>' . esc_html__( 'A scan shows what to fix first and how much each plugin costs. It is read-only and takes 1 to 3 minutes.', 'inochi-pulso' ) . '</p>', self::action( __( 'Run a scan', 'inochi-pulso' ) ) );
		}
		$urgent = count( (array) ( $d['priorities']['urgente'] ?? array() ) );
		$stab   = count( (array) ( $d['priorities']['estabilizacion'] ?? array() ) );
		$date   = substr( (string) $d['scan']['created_at'], 0, 10 );
		/* translators: 1: date, 2: health score. */
		$desc = '<p>' . esc_html( sprintf( __( 'Last scan: %1$s. Site health: %2$s / 100.', 'inochi-pulso' ), $date, null === $d['score']['global'] ? '—' : $d['score']['global'] ) ) . '</p>';
		$open = self::action( __( 'Open the report', 'inochi-pulso' ) );
		if ( $urgent ) {
			/* translators: %d: number of urgent findings. */
			$label = sprintf( _n( 'Inochi Pulso found %d urgent problem', 'Inochi Pulso found %d urgent problems', $urgent, 'inochi-pulso' ), $urgent );
			return self::result( 'inochi_pulso_scan', 'critical', $label, $desc, $open );
		}
		if ( self::is_old( $d ) ) {
			/* translators: %d: days. */
			return self::result( 'inochi_pulso_scan', 'recommended', sprintf( __( 'The last Inochi Pulso scan is more than %d days old', 'inochi-pulso' ), self::MAX_AGE_DAYS ), $desc, self::action( __( 'Run a new scan', 'inochi-pulso' ) ) );
		}
		if ( $stab ) {
			/* translators: %d: number of findings. */
			$label = sprintf( _n( 'Inochi Pulso found %d problem to stabilise', 'Inochi Pulso found %d problems to stabilise', $stab, 'inochi-pulso' ), $stab );
			return self::result( 'inochi_pulso_scan', 'recommended', $label, $desc, $open );
		}
		return self::result( 'inochi_pulso_scan', 'good', __( 'Inochi Pulso found no urgent problems', 'inochi-pulso' ), $desc, $open );
	}

	/**
	 * Test: plugins with the highest cost in the last scan.
	 */
	public static function test_cost() {
		$d = self::last_scan();
		if ( ! $d || ! $d['cost'] ) {
			return self::result( 'inochi_pulso_cost', 'good', __( 'No cost per plugin data yet', 'inochi-pulso' ), '<p>' . esc_html__( 'Run an Inochi Pulso scan to see the autoload, tables, cron events and scheduled actions of each plugin.', 'inochi-pulso' ) . '</p>', self::action( __( 'Run a scan', 'inochi-pulso' ) ) );
		}
		$items   = array();
		$orphans = 0;
		foreach ( array_slice( Inochi_Pulso_Export::cost_rows( $d ), 0, 3 ) as $c ) {
			$items[] = '<li><strong>' . esc_html( $c['label'] ) . '</strong> (' . esc_html( $c['status'] ) . '): ' . esc_html__( 'autoload', 'inochi-pulso' ) . ' ' . esc_html( $c['autoload'] ) . ' · ' . esc_html__( 'tables', 'inochi-pulso' ) . ' ' . esc_html( $c['tables'] ) . '</li>';
		}
		foreach ( $d['cost'] as $r ) {
			if ( 'leftovers' === $r['kind'] || in_array( $r['status'], array( 'inactive', 'deleted' ), true ) ) {
				$orphans += (int) $r['autoload_bytes'];
			}
		}
		$desc = '<p>' . esc_html__( 'Highest cost in the last scan:', 'inochi-pulso' ) . '</p><ul>' . implode( '', $items ) . '</ul>';
		if ( $orphans > 100 * 1024 ) {
			/* translators: %s: size. */
			return self::result( 'inochi_pulso_cost', 'recommended', sprintf( __( 'Inactive or deleted plugins still load %s on every page', 'inochi-pulso' ), Inochi_Pulso_Util::bytes( $orphans ) ), $desc, self::action( __( 'See the cost per plugin', 'inochi-pulso' ) ) );
		}
		return self::result( 'inochi_pulso_cost', 'good', __( 'No significant autoload from inactive or deleted plugins', 'inochi-pulso' ), $desc, self::action( __( 'See the cost per plugin', 'inochi-pulso' ) ) );
	}
}
