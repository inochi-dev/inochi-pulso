<?php
/**
 * Pulso screen under Tools: scan, read the report, export and settings.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Inochi_Pulso_Admin {

	const SLUG = 'inochi-pulso';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'html', 'csv', 'json', 'delete', 'settings' ) as $a ) {
			add_action( 'admin_post_inochi_pulso_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( INOCHI_PULSO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu under Tools.
	 */
	public static function menu() {
		add_management_page( 'Inochi Pulso', 'Inochi Pulso', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * "Open" link in the plugins list.
	 *
	 * @param array $links Links.
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'inochi-pulso' ) . '</a>' );
		return $links;
	}

	/**
	 * Screen URL with parameters.
	 *
	 * @param array $args Parameters.
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * admin-post action URL with nonce.
	 *
	 * @param string $action  Action.
	 * @param int    $scan_id Scan.
	 */
	public static function action_url( $action, $scan_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=inochi_pulso_' . $action . '&scan=' . (int) $scan_id ), 'inochi_pulso_' . $action . '_' . (int) $scan_id );
	}

	/**
	 * CSS and JS only on the Pulso screen.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'inochi-pulso', INOCHI_PULSO_URL . 'assets/admin.css', array(), INOCHI_PULSO_VERSION );
		wp_enqueue_script( 'inochi-pulso', INOCHI_PULSO_URL . 'assets/admin.js', array(), INOCHI_PULSO_VERSION, true );
		wp_localize_script(
			'inochi-pulso',
			'InochiPulso',
			array(
				'root'  => esc_url_raw( rest_url( Inochi_Pulso_REST::NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'starting' => __( 'Starting the scan…', 'inochi-pulso' ),
					/* translators: 1: step number, 2: total steps, 3: step name. */
					'step'     => __( 'Step %1$d of %2$d: %3$s', 'inochi-pulso' ),
					'stepErr'  => __( 'Error in this step (the scan goes on):', 'inochi-pulso' ),
					'finish'   => __( 'Writing the report…', 'inochi-pulso' ),
					'failed'   => __( 'The scan could not continue:', 'inochi-pulso' ),
					'leave'    => __( 'The scan is still running. If you leave, it will stop.', 'inochi-pulso' ),
				),
			)
		);
	}

	/**
	 * Checks the capability.
	 */
	public static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to use Inochi Pulso.', 'inochi-pulso' ), 403 );
		}
	}

	/**
	 * Redirects with a notice.
	 *
	 * @param array  $args   URL parameters.
	 * @param string $notice Text.
	 * @param string $type   success|error.
	 */
	public static function back( array $args, $notice, $type = 'success' ) {
		set_transient( 'inochi_pulso_notice_' . get_current_user_id(), array( $type, $notice ), 60 );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Sends a file and stops.
	 *
	 * @param string $content_type Type.
	 * @param string $filename     Name.
	 * @param string $body         Content.
	 * @param bool   $inline       Open in the browser instead of downloading.
	 */
	public static function download( $content_type, $filename, $body, $inline = false ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download: the HTML report is escaped when built; CSV and JSON are data files.
		exit;
	}

	/**
	 * Export data of the current request (with nonce and capability).
	 *
	 * @param string $action Action.
	 */
	private static function export_data( $action ) {
		self::guard();
		$id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.
		check_admin_referer( 'inochi_pulso_' . $action . '_' . $id );
		$d = Inochi_Pulso_Export::data( $id );
		if ( ! $d ) {
			wp_die( esc_html__( 'Scan not found.', 'inochi-pulso' ), 404 );
		}
		return $d;
	}

	// ------------------------------------------------------------------ Actions.

	/**
	 * Printable HTML report (opens in another tab).
	 */
	public static function handle_html() {
		$d = self::export_data( 'html' );
		self::download( 'text/html; charset=utf-8', Inochi_Pulso_Export::filename( $d, 'html' ), Inochi_Pulso_Export::html( $d ), true );
	}

	/**
	 * CSV.
	 */
	public static function handle_csv() {
		$d = self::export_data( 'csv' );
		self::download( 'text/csv; charset=utf-8', Inochi_Pulso_Export::filename( $d, 'csv' ), Inochi_Pulso_Export::csv( $d ) );
	}

	/**
	 * JSON.
	 */
	public static function handle_json() {
		$d = self::export_data( 'json' );
		self::download( 'application/json; charset=utf-8', Inochi_Pulso_Export::filename( $d, 'json' ), Inochi_Pulso_Export::json( $d ) );
	}

	/**
	 * Deletes a scan.
	 */
	public static function handle_delete() {
		self::guard();
		$id = isset( $_POST['scan'] ) ? absint( $_POST['scan'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
		check_admin_referer( 'inochi_pulso_delete_' . $id );
		Inochi_Pulso_DB::delete_scan( $id );
		self::back( array(), __( 'Scan deleted.', 'inochi-pulso' ) );
	}

	/**
	 * Saves the settings.
	 */
	public static function handle_settings() {
		self::guard();
		check_admin_referer( 'inochi_pulso_settings' );
		Inochi_Pulso_Settings::save( array( 'wporg_consent' => ! empty( $_POST['wporg_consent'] ) ) );
		self::back( array( 'tab' => 'settings' ), __( 'Settings saved.', 'inochi-pulso' ) );
	}

	// ------------------------------------------------------------------ Screens.

	/**
	 * Tags allowed in the HTML built by Inochi_Pulso_Export (already escaped).
	 */
	private static function allowed_html() {
		$c = array( 'class' => true );
		return array(
			'table'   => $c,
			'thead'   => array(),
			'tbody'   => array(),
			'tr'      => $c,
			'th'      => array(
				'class'   => true,
				'colspan' => true,
			),
			'td'      => $c,
			'details' => array(),
			'summary' => array(),
			'ul'      => $c,
			'li'      => array(),
			'strong'  => array(),
			'code'    => array(),
			'p'       => $c,
			'span'    => $c,
			'div'     => $c,
			'h3'      => array(),
			'pre'     => array(),
		);
	}

	/**
	 * Main screen.
	 */
	public static function render() {
		self::guard();
		Inochi_Pulso_DB::maybe_upgrade();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$scan_id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0;
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		// phpcs:enable

		echo '<div class="wrap inochi-pulso">';
		echo '<h1 class="ip-title"><span class="ip-mark" aria-hidden="true"><svg viewBox="0 0 120 60" width="40" height="20"><path d="M6 34 H36 L44 12 L56 52 L66 24 L72 34 H92" fill="none" stroke="currentColor" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="106" cy="34" r="8" fill="currentColor"/></svg></span>Inochi Pulso</h1>';

		$tabs = array(
			''         => __( 'Scans', 'inochi-pulso' ),
			'settings' => __( 'Settings', 'inochi-pulso' ),
		);
		echo '<nav class="nav-tab-wrapper ip-tabs">';
		foreach ( $tabs as $k => $label ) {
			$active = ( $k === $tab && ! $scan_id ) || ( '' === $k && $scan_id );
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $k ? array( 'tab' => $k ) : array() ) ), $active ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		$notice = get_transient( 'inochi_pulso_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'inochi_pulso_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( 'error' === $notice[0] ? 'error' : 'success' ), esc_html( $notice[1] ) );
		}

		if ( $scan_id ) {
			self::render_detail( $scan_id );
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/**
	 * New scan box.
	 */
	private static function render_scan_box() {
		?>
		<div class="ip-card ip-start">
			<h2><?php esc_html_e( 'New scan', 'inochi-pulso' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pulso reads this site without changing anything and tells you what to fix first, and how much each plugin costs. It takes 1 to 3 minutes: keep this tab open while it runs.', 'inochi-pulso' ); ?></p>
			<p class="description"><?php esc_html_e( 'It makes about 40 requests to this same site (home page, cart, leftover files…) to measure times and see what is reachable from outside. They can show up in the server statistics or the firewall log.', 'inochi-pulso' ); ?></p>
			<p class="description">
				<?php if ( Inochi_Pulso_Settings::wporg_allowed() ) : ?>
					<?php esc_html_e( 'WordPress.org lookup: on (the folder name of each installed plugin is sent to api.wordpress.org).', 'inochi-pulso' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'WordPress.org lookup: off. Pulso does not contact any external service.', 'inochi-pulso' ); ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Turn it on in Settings to detect closed or abandoned plugins.', 'inochi-pulso' ); ?></a>
				<?php endif; ?>
			</p>
			<p><button type="button" class="button button-primary button-hero" id="ip-start"><?php esc_html_e( 'Start scan', 'inochi-pulso' ); ?></button></p>
			<div id="ip-progress" class="ip-progress" hidden>
				<div class="ip-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span style="width:0"></span></div>
				<p id="ip-status" aria-live="polite"></p>
				<ul id="ip-log" class="ip-log"></ul>
			</div>
		</div>
		<?php
	}

	/**
	 * List of scans.
	 */
	private static function render_list() {
		self::render_scan_box();
		$scans = Inochi_Pulso_DB::list_scans( 100 );
		if ( ! $scans ) {
			echo '<p>' . esc_html__( 'No scans yet.', 'inochi-pulso' ) . '</p>';
			return;
		}
		echo '<h2>' . esc_html__( 'Scans of this site', 'inochi-pulso' ) . '</h2>';
		echo '<table class="widefat striped ip-table"><thead><tr><th>#</th><th>' . esc_html__( 'Date (UTC)', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Health', 'inochi-pulso' ) . '</th><th>' . esc_html_x( 'Fail', 'check state', 'inochi-pulso' ) . '</th><th>' . esc_html_x( 'Warning', 'check state', 'inochi-pulso' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $scans as $s ) {
			$id   = (int) $s['id'];
			$done = 'done' === $s['status'];
			$d    = $done ? Inochi_Pulso_Export::data( $id ) : null;
			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td><strong>%3$s</strong></td><td>%4$d</td><td>%5$d</td><td class="ip-actions"><a class="button button-primary" href="%6$s">%7$s</a></td></tr>',
				(int) $id,
				esc_html( $s['created_at'] ),
				esc_html( ( $d && null !== $d['score']['global'] ) ? (string) $d['score']['global'] : ( $done ? '—' : __( 'unfinished', 'inochi-pulso' ) ) ),
				(int) ( $d ? $d['score']['fails'] : 0 ),
				(int) ( $d ? $d['score']['warns'] : 0 ),
				esc_url( self::url( array( 'scan' => $id ) ) ),
				esc_html__( 'View', 'inochi-pulso' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Report of a scan.
	 *
	 * @param int $id Scan.
	 */
	private static function render_detail( $id ) {
		$d = Inochi_Pulso_Export::data( $id );
		printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( '← Scans', 'inochi-pulso' ) );
		if ( ! $d ) {
			echo '<p>' . esc_html__( 'Scan not found.', 'inochi-pulso' ) . '</p>';
			return;
		}
		$allowed = self::allowed_html();
		$score   = $d['score'];
		?>
		<div class="ip-card">
			<?php /* translators: 1: scan number, 2: date. */ ?>
			<h2><?php echo esc_html( sprintf( __( 'Scan #%1$d · %2$s UTC', 'inochi-pulso' ), $id, $d['scan']['created_at'] ) ); ?></h2>
			<?php if ( 'done' !== $d['scan']['status'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'This scan did not finish: some checks are missing. Run a new scan and keep the tab open until the end.', 'inochi-pulso' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $d['errors'] as $step => $err ) : ?>
				<?php /* translators: 1: step key, 2: error. */ ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( __( 'Step "%1$s" failed: %2$s', 'inochi-pulso' ), $step, $err ) ); ?></p></div>
			<?php endforeach; ?>
			<p class="ip-score"><strong><?php echo esc_html( null === $score['global'] ? '—' : $score['global'] . ' / 100' ); ?></strong> <?php esc_html_e( 'site health', 'inochi-pulso' ); ?></p>
			<?php echo wp_kses( Inochi_Pulso_Export::score_table( $d, 'widefat ip-table' ), $allowed ); ?>
			<p class="description"><?php esc_html_e( 'What could not be checked shows as "No data" and does not count in the score: Pulso does not make up results.', 'inochi-pulso' ); ?></p>
			<p>
				<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( self::action_url( 'html', $id ) ); ?>"><?php esc_html_e( 'Printable report', 'inochi-pulso' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'csv', $id ) ); ?>">CSV</a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'json', $id ) ); ?>">JSON</a>
			</p>
		</div>

		<h2><?php esc_html_e( 'Cost per plugin', 'inochi-pulso' ); ?></h2>
		<?php echo wp_kses( Inochi_Pulso_Export::cost_table( $d, 'widefat striped ip-table ip-cost' ), $allowed ); ?>
		<?php
		foreach ( Inochi_Pulso_Catalog::PHASES as $phase ) {
			if ( empty( $d['priorities'][ $phase ] ) ) {
				continue;
			}
			/* translators: %s: priority (Urgent, Stabilisation, Improvement). */
			echo '<h2>' . esc_html( sprintf( __( 'Priority: %s', 'inochi-pulso' ), Inochi_Pulso_Catalog::phase_label( $phase ) ) ) . '</h2>';
			foreach ( $d['priorities'][ $phase ] as $p ) {
				echo wp_kses( Inochi_Pulso_Export::priority( $p, 'h3', 'ip-card ip-priority' ), $allowed );
			}
		}

		echo '<h2>' . esc_html__( 'All checks', 'inochi-pulso' ) . '</h2>';
		echo wp_kses( Inochi_Pulso_Export::findings_table( $d['rows'], 'widefat ip-table ip-findings' ), $allowed );
		?>
		<p class="ip-cta"><?php esc_html_e( 'Want an engineer to review it?', 'inochi-pulso' ); ?> <a href="<?php echo esc_url( Inochi_Pulso_Export::inochi_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Inochi audit', 'inochi-pulso' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ip-delete" data-confirm="<?php esc_attr_e( 'Delete this scan? This cannot be undone.', 'inochi-pulso' ); ?>">
			<input type="hidden" name="action" value="inochi_pulso_delete">
			<input type="hidden" name="scan" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'inochi_pulso_delete_' . $id ); ?>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete this scan', 'inochi-pulso' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Settings: consent to contact WordPress.org.
	 */
	private static function render_settings() {
		$s = Inochi_Pulso_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ip-card ip-settings">
			<input type="hidden" name="action" value="inochi_pulso_settings">
			<?php wp_nonce_field( 'inochi_pulso_settings' ); ?>
			<h2><?php esc_html_e( 'External services', 'inochi-pulso' ); ?></h2>
			<p><?php esc_html_e( 'By default Pulso does not contact any external service: the whole scan runs on this server.', 'inochi-pulso' ); ?></p>
			<p><label><input type="checkbox" name="wporg_consent" value="1" <?php checked( true === $s['wporg_consent'] ); ?>> <strong><?php esc_html_e( 'Check plugins on WordPress.org', 'inochi-pulso' ); ?></strong></label></p>
			<p class="description">
				<?php esc_html_e( 'To detect plugins that are closed or have not been updated for more than 2 years, Pulso sends the WordPress.org plugins API (api.wordpress.org) the folder name (slug) of each installed plugin, premium and custom ones included. Nothing else is sent.', 'inochi-pulso' ); ?>
				<a href="https://wordpress.org/about/privacy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'WordPress.org privacy policy', 'inochi-pulso' ); ?></a>
			</p>
			<?php if ( $s['wporg_consent'] && $s['wporg_consent_at'] ) : ?>
				<?php /* translators: %s: date. */ ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Turned on on %s UTC.', 'inochi-pulso' ), $s['wporg_consent_at'] ) ); ?></p>
			<?php endif; ?>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'inochi-pulso' ); ?></button></p>
		</form>
		<?php
	}
}
