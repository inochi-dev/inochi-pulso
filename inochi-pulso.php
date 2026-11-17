<?php
/**
 * Plugin Name:       Inochi Pulso – Autoload Cost & Site Audit
 * Plugin URI:        https://github.com/inochi-dev/inochi-pulso
 * Description:       Find which plugin is slowing your site: autoload, tables, cron and WooCommerce issues per plugin, in one read-only scan.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Inochi
 * Author URI:        https://inochi.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       inochi-pulso
 * Domain Path:       /languages
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

define( 'INOCHI_PULSO_VERSION', '1.0.0' );
define( 'INOCHI_PULSO_DB_VERSION', '2' );
define( 'INOCHI_PULSO_FILE', __FILE__ );
define( 'INOCHI_PULSO_DIR', plugin_dir_path( __FILE__ ) );
define( 'INOCHI_PULSO_URL', plugin_dir_url( __FILE__ ) );

require_once INOCHI_PULSO_DIR . 'includes/class-db.php';
require_once INOCHI_PULSO_DIR . 'includes/class-util.php';
require_once INOCHI_PULSO_DIR . 'includes/class-http-probe.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-base.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-general.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-database.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-cost.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-runtime.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-http.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-security.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-files.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-woocommerce.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-maintenance.php';
require_once INOCHI_PULSO_DIR . 'includes/checks/class-checks-wporg.php';
require_once INOCHI_PULSO_DIR . 'includes/class-settings.php';
require_once INOCHI_PULSO_DIR . 'includes/class-catalog.php';
require_once INOCHI_PULSO_DIR . 'includes/class-knowledge.php';
require_once INOCHI_PULSO_DIR . 'includes/class-scanner.php';
require_once INOCHI_PULSO_DIR . 'includes/class-export.php';
require_once INOCHI_PULSO_DIR . 'includes/class-rest.php';
require_once INOCHI_PULSO_DIR . 'includes/class-admin.php';
require_once INOCHI_PULSO_DIR . 'includes/class-site-health.php';

register_activation_hook( __FILE__, array( 'Inochi_Pulso_DB', 'install' ) );

add_action(
	'init',
	static function () {
		// Bundled translations (es_ES) until they are available on translate.wordpress.org.
		load_plugin_textdomain( 'inochi-pulso', false, dirname( plugin_basename( INOCHI_PULSO_FILE ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Inochi_Pulso_REST::init();
		Inochi_Pulso_Site_Health::init();
		if ( is_admin() ) {
			Inochi_Pulso_DB::maybe_upgrade(); // Only in the dashboard: no extra query on the public site.
			Inochi_Pulso_Admin::init();
		}
	}
);
