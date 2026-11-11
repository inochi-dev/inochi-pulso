<?php
/**
 * Tablas de Pulso: escaneos (con payload) y hallazgos.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Acceso a datos.
 */
class Inochi_Pulso_DB {

	const OPTION_DB_VERSION = 'inochi_pulso_db_version';

	/**
	 * Nombre de la tabla de escaneos.
	 */
	public static function scans_table() {
		global $wpdb;
		return $wpdb->prefix . 'inochi_pulso_scans';
	}

	/**
	 * Nombre de la tabla de hallazgos.
	 */
	public static function findings_table() {
		global $wpdb;
		return $wpdb->prefix . 'inochi_pulso_findings';
	}

	/**
	 * Crea o actualiza las tablas.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$scans   = self::scans_table();
		$finds   = self::findings_table();

		dbDelta(
			"CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			finished_at datetime NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			current_step varchar(40) NOT NULL DEFAULT '',
			plugin_version varchar(20) NOT NULL DEFAULT '',
			origin varchar(10) NOT NULL DEFAULT 'local',
			site varchar(190) NOT NULL DEFAULT '',
			client varchar(190) NOT NULL DEFAULT '',
			payload longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$finds} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			code varchar(10) NOT NULL,
			value text NULL,
			state varchar(10) NOT NULL DEFAULT '',
			source varchar(10) NOT NULL DEFAULT 'auto',
			evidence longtext NULL,
			PRIMARY KEY  (id),
			KEY scan_code (scan_id,code)
			) {$charset};"
		);

		update_option( self::OPTION_DB_VERSION, INOCHI_PULSO_DB_VERSION, false );
	}

	/**
	 * Actualiza las tablas si cambia la versión de esquema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) !== INOCHI_PULSO_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Crea un escaneo y devuelve su ID.
	 */
	public static function create_scan( array $fields = array() ) {
		global $wpdb;
		$row = array_merge(
			array(
				'created_at'     => current_time( 'mysql', true ),
				'status'         => 'running',
				'plugin_version' => INOCHI_PULSO_VERSION,
				'origin'         => 'local',
				'site'           => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'client'         => '',
				'payload'        => wp_json_encode( new stdClass() ),
			),
			array_intersect_key( $fields, array_flip( array( 'created_at', 'finished_at', 'status', 'plugin_version', 'origin', 'site', 'client', 'payload' ) ) )
		);
		$wpdb->insert( self::scans_table(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Devuelve un escaneo con su payload decodificado, o null.
	 *
	 * @param int $scan_id ID.
	 */
	public static function get_scan( $scan_id ) {
		global $wpdb;
		$table = self::scans_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$payload        = json_decode( (string) $row['payload'], true );
		$row['payload'] = is_array( $payload ) ? $payload : array();
		return $row;
	}

	/**
	 * Lista de escaneos, más recientes primero.
	 */
	public static function list_scans( $limit = 50 ) {
		global $wpdb;
		$scans = self::scans_table();
		$finds = self::findings_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla internos.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.created_at, s.finished_at, s.status, s.plugin_version, s.origin, s.site, s.client,
					SUM(CASE WHEN f.source = 'auto' THEN 1 ELSE 0 END) AS n_auto,
					SUM(CASE WHEN f.source = 'partial' THEN 1 ELSE 0 END) AS n_partial,
					SUM(CASE WHEN f.state <> '' THEN 1 ELSE 0 END) AS n_done,
					SUM(CASE WHEN f.state = 'Fallo' THEN 1 ELSE 0 END) AS n_fail,
					SUM(CASE WHEN f.state = 'Aviso' THEN 1 ELSE 0 END) AS n_warn
				FROM {$scans} s LEFT JOIN {$finds} f ON f.scan_id = s.id
				GROUP BY s.id ORDER BY s.id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
	}

	/**
	 * Mezcla datos en el payload de un escaneo.
	 *
	 * @param int   $scan_id ID.
	 * @param array $data    Claves de primer nivel a sustituir.
	 */
	public static function merge_payload( $scan_id, array $data ) {
		global $wpdb;
		$scan = self::get_scan( $scan_id );
		if ( ! $scan ) {
			return;
		}
		$payload = array_merge( $scan['payload'], $data );
		$wpdb->update(
			self::scans_table(),
			array( 'payload' => wp_json_encode( $payload ) ),
			array( 'id' => $scan_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Actualiza estado y paso.
	 */
	public static function update_scan( $scan_id, array $fields ) {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'status', 'current_step', 'finished_at', 'client', 'site' ) ) );
		if ( $allowed ) {
			$wpdb->update( self::scans_table(), $allowed, array( 'id' => $scan_id ) );
		}
	}

	/**
	 * Guarda (sustituye) el resultado de una comprobación.
	 *
	 * @param int   $scan_id ID.
	 * @param array $result  code, value, state, source, evidence.
	 * @return bool Si se guardó.
	 */
	public static function save_finding( $scan_id, array $result ) {
		global $wpdb;
		$table = self::findings_table();
		$wpdb->delete(
			$table,
			array(
				'scan_id' => $scan_id,
				'code'    => $result['code'],
			),
			array( '%d', '%s' )
		);
		$ok = $wpdb->insert(
			$table,
			array(
				'scan_id'  => $scan_id,
				'code'     => $result['code'],
				'value'    => Inochi_Pulso_Util::redact( $result['value'] ?? '' ),
				'state'    => (string) ( $result['state'] ?? '' ),
				'source'   => (string) ( $result['source'] ?? 'auto' ),
				'evidence' => Inochi_Pulso_Util::clip( Inochi_Pulso_Util::redact( $result['evidence'] ?? '' ) ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return false !== $ok;
	}

	/**
	 * Hallazgos de un escaneo indexados por código.
	 */
	public static function get_findings( $scan_id ) {
		global $wpdb;
		$table = self::findings_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT code, value, state, source, evidence FROM {$table} WHERE scan_id = %d", $scan_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['code'] ] = $row;
		}
		return $out;
	}

	/**
	 * Borra un escaneo y sus hallazgos.
	 */
	public static function delete_scan( $scan_id ) {
		global $wpdb;
		$wpdb->delete( self::findings_table(), array( 'scan_id' => $scan_id ), array( '%d' ) );
		$wpdb->delete( self::scans_table(), array( 'id' => $scan_id ), array( '%d' ) );
	}
}
