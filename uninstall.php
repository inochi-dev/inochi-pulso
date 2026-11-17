<?php
/**
 * Uninstall: drops the two tables and deletes Pulso's options.
 *
 * @package InochiPulso
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dropping the plugin's own tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}inochi_pulso_findings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}inochi_pulso_scans" );
// phpcs:enable

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cleaning the plugin's own transients.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_inochi_pulso_' ) . '%', $wpdb->esc_like( '_transient_timeout_inochi_pulso_' ) . '%' ) );

foreach ( array( 'inochi_pulso_db_version', 'inochi_pulso_settings' ) as $inochi_pulso_option ) {
	delete_option( $inochi_pulso_option );
}
