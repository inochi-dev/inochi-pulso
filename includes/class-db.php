<?php
/**
 * Pulso tables: scans (with payload) and results.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

// The data layer of the plugin's own two tables: direct queries are the point, and each scan is read once.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Data access.
 */
class Inochi_Pulso_DB {

	const OPTION_DB_VERSION = 'inochi_pulso_db_version';

	/**
	 * Scans table name.
	 */
	public static function scans_table() {
		global $wpdb;
		return $wpdb->prefix . 'inochi_pulso_scans';
	}

	/**
	 * Results table name.
	 */
	public static function findings_table() {
		global $wpdb;
		return $wpdb->prefix . 'inochi_pulso_findings';
	}

	/**
	 * Creates or updates the tables.
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
	 * Updates the tables when the schema version changes.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) !== INOCHI_PULSO_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates a scan and returns its ID.
	 *
	 * @param array $fields Fields.
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
	 * Returns a scan with its decoded payload, or null.
	 *
	 * @param int $scan_id ID.
	 */
	public static function get_scan( $scan_id ) {
		global $wpdb;
		$table = self::scans_table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $scan_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$payload        = json_decode( (string) $row['payload'], true );
		$row['payload'] = is_array( $payload ) ? $payload : array();
		return $row;
	}

	/**
	 * List of scans, newest first.
	 *
	 * @param int $limit Limit.
	 */
	public static function list_scans( $limit = 50 ) {
		global $wpdb;
		$scans = self::scans_table();
		$finds = self::findings_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.created_at, s.finished_at, s.status, s.plugin_version, s.site,
					SUM(CASE WHEN f.state = 'Fallo' THEN 1 ELSE 0 END) AS n_fail,
					SUM(CASE WHEN f.state = 'Aviso' THEN 1 ELSE 0 END) AS n_warn
				FROM %i s LEFT JOIN %i f ON f.scan_id = s.id
				GROUP BY s.id, s.created_at, s.finished_at, s.status, s.plugin_version, s.site ORDER BY s.id DESC LIMIT %d",
				$scans,
				$finds,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Merges data into the payload of a scan.
	 *
	 * @param int   $scan_id ID.
	 * @param array $data    First-level keys to replace.
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
	 * Updates status and step.
	 *
	 * @param int   $scan_id ID.
	 * @param array $fields  Fields.
	 */
	public static function update_scan( $scan_id, array $fields ) {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'status', 'current_step', 'finished_at', 'client', 'site' ) ) );
		if ( $allowed ) {
			$wpdb->update( self::scans_table(), $allowed, array( 'id' => $scan_id ) );
		}
	}

	/**
	 * Saves (replaces) the result of a check.
	 *
	 * @param int   $scan_id ID.
	 * @param array $result  code, value, state, source, evidence.
	 * @return bool Whether it was saved.
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
	 * Results of a scan indexed by code.
	 *
	 * @param int $scan_id ID.
	 */
	public static function get_findings( $scan_id ) {
		global $wpdb;
		$table = self::findings_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT code, value, state, source, evidence FROM %i WHERE scan_id = %d', $table, $scan_id ), ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['code'] ] = $row;
		}
		return $out;
	}

	/**
	 * Deletes a scan and its results.
	 *
	 * @param int $scan_id ID.
	 */
	public static function delete_scan( $scan_id ) {
		global $wpdb;
		$wpdb->delete( self::findings_table(), array( 'scan_id' => $scan_id ), array( '%d' ) );
		$wpdb->delete( self::scans_table(), array( 'id' => $scan_id ), array( '%d' ) );
	}
}
