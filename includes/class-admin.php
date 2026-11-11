<?php
/**
 * Pantalla de Pulso en Herramientas: escanear, ver el informe, exportar y ajustes.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Administración.
 */
class Inochi_Pulso_Admin {

	const SLUG = 'inochi-pulso';

	/**
	 * Ganchos.
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
	 * Menú en Herramientas.
	 */
	public static function menu() {
		add_management_page( 'Inochi Pulso', 'Inochi Pulso', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Enlace «Abrir» en la lista de plugins.
	 *
	 * @param array $links Enlaces.
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Abrir</a>' );
		return $links;
	}

	/**
	 * URL de la pantalla con parámetros.
	 *
	 * @param array $args Parámetros.
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * URL de una acción admin-post con nonce.
	 *
	 * @param string $action  Acción.
	 * @param int    $scan_id Escaneo.
	 */
	public static function action_url( $action, $scan_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=inochi_pulso_' . $action . '&scan=' . (int) $scan_id ), 'inochi_pulso_' . $action . '_' . (int) $scan_id );
	}

	/**
	 * CSS y JS solo en la pantalla de Pulso.
	 *
	 * @param string $hook Pantalla.
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
			)
		);
	}

	/**
	 * Comprueba permisos.
	 */
	public static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'No tienes permiso para usar Inochi Pulso.' ), 403 );
		}
	}

	/**
	 * Redirige con un aviso.
	 *
	 * @param array  $args   Parámetros de la URL.
	 * @param string $notice Texto.
	 * @param string $type   success|error.
	 */
	public static function back( array $args, $notice, $type = 'success' ) {
		set_transient( 'inochi_pulso_notice_' . get_current_user_id(), array( $type, $notice ), 60 );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Envía un archivo y termina.
	 *
	 * @param string $content_type Tipo.
	 * @param string $filename     Nombre.
	 * @param string $body         Contenido.
	 * @param bool   $inline       Abrir en el navegador en lugar de descargar.
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
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- documento ya escapado o archivo de datos.
		exit;
	}

	/**
	 * Datos de exportación de la petición actual (con nonce y permiso).
	 *
	 * @param string $action Acción.
	 */
	private static function export_data( $action ) {
		self::guard();
		$id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0;
		check_admin_referer( 'inochi_pulso_' . $action . '_' . $id );
		$d = Inochi_Pulso_Export::data( $id );
		if ( ! $d ) {
			wp_die( esc_html( 'Escaneo no encontrado.' ), 404 );
		}
		return $d;
	}

	// ------------------------------------------------------------------ Acciones.

	/**
	 * Informe HTML imprimible (se abre en otra pestaña).
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
	 * Borrado de un escaneo.
	 */
	public static function handle_delete() {
		self::guard();
		$id = isset( $_POST['scan'] ) ? absint( $_POST['scan'] ) : 0;
		check_admin_referer( 'inochi_pulso_delete_' . $id );
		Inochi_Pulso_DB::delete_scan( $id );
		self::back( array(), 'Escaneo borrado.' );
	}

	/**
	 * Guardar ajustes.
	 */
	public static function handle_settings() {
		self::guard();
		check_admin_referer( 'inochi_pulso_settings' );
		Inochi_Pulso_Settings::save( array( 'wporg_consent' => ! empty( $_POST['wporg_consent'] ) ) );
		self::back( array( 'tab' => 'settings' ), 'Ajustes guardados.' );
	}

	// ------------------------------------------------------------------ Pantallas.

	/**
	 * Pantalla principal.
	 */
	public static function render() {
		self::guard();
		Inochi_Pulso_DB::maybe_upgrade();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navegación de solo lectura.
		$scan_id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0;
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		// phpcs:enable

		echo '<div class="wrap inochi-pulso">';
		echo '<h1 class="ip-title"><span class="ip-mark" aria-hidden="true"><svg viewBox="0 0 120 60" width="40" height="20"><path d="M6 34 H36 L44 12 L56 52 L66 24 L72 34 H92" fill="none" stroke="currentColor" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="106" cy="34" r="8" fill="currentColor"/></svg></span>Inochi Pulso</h1>';

		$tabs = array(
			''         => 'Escaneos',
			'settings' => 'Ajustes',
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
	 * Bloque de nuevo escaneo.
	 */
	private static function render_scan_box() {
		$wporg = Inochi_Pulso_Settings::wporg_allowed();
		?>
		<div class="ip-card ip-start">
			<h2>Nuevo escaneo</h2>
			<p class="description">Pulso lee este sitio sin cambiar nada y te dice qué arreglar primero. Tarda entre 1 y 3 minutos: no cierres la pestaña mientras se ejecuta.</p>
			<p class="description">Hace unas 40 peticiones a este mismo sitio (portada, carrito, archivos sobrantes…) para medir tiempos y comprobar qué es accesible desde fuera. Pueden aparecer en las estadísticas del servidor o en el registro del cortafuegos.</p>
			<p class="description">
				<?php if ( $wporg ) : ?>
					Consulta a WordPress.org: <strong>activada</strong> (se envía el nombre de carpeta de cada plugin instalado a api.wordpress.org).
				<?php else : ?>
					Consulta a WordPress.org: <strong>desactivada</strong>. Pulso no se conecta a ningún servicio externo. Puedes activarla en <a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>">Ajustes</a> para detectar plugins cerrados o abandonados.
				<?php endif; ?>
			</p>
			<p><button type="button" class="button button-primary button-hero" id="ip-start">Iniciar escaneo</button></p>
			<div id="ip-progress" class="ip-progress" hidden>
				<div class="ip-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span style="width:0"></span></div>
				<p id="ip-status" aria-live="polite"></p>
				<ul id="ip-log" class="ip-log"></ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Lista de escaneos.
	 */
	private static function render_list() {
		self::render_scan_box();
		$scans = Inochi_Pulso_DB::list_scans( 100 );
		if ( ! $scans ) {
			echo '<p>Todavía no hay escaneos.</p>';
			return;
		}
		echo '<h2>Escaneos de este sitio</h2>';
		echo '<table class="widefat striped ip-table"><thead><tr><th>#</th><th>Fecha (UTC)</th><th>Salud</th><th>Fallos</th><th>Avisos</th><th></th></tr></thead><tbody>';
		foreach ( $scans as $s ) {
			$id = (int) $s['id'];
			$d  = Inochi_Pulso_Export::data( $id );
			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td><strong>%3$s</strong></td><td>%4$d</td><td>%5$d</td><td class="ip-actions"><a class="button button-primary" href="%6$s">Ver</a></td></tr>',
				(int) $id,
				esc_html( $s['created_at'] ),
				esc_html( ( 'done' === $s['status'] && $d && null !== $d['score']['global'] ) ? (string) $d['score']['global'] : '—' ),
				(int) ( $d ? $d['score']['fails'] : 0 ),
				(int) ( $d ? $d['score']['warns'] : 0 ),
				esc_url( self::url( array( 'scan' => $id ) ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Informe de un escaneo.
	 *
	 * @param int $id Escaneo.
	 */
	private static function render_detail( $id ) {
		$d = Inochi_Pulso_Export::data( $id );
		printf( '<p><a href="%s">← Escaneos</a></p>', esc_url( self::url() ) );
		if ( ! $d ) {
			echo '<p>Escaneo no encontrado.</p>';
			return;
		}
		$score = $d['score'];
		?>
		<div class="ip-card">
			<h2>Escaneo #<?php echo (int) $id; ?> · <?php echo esc_html( $d['scan']['created_at'] ); ?> UTC</h2>
			<p class="ip-score"><strong><?php echo esc_html( null === $score['global'] ? '—' : $score['global'] . ' / 100' ); ?></strong> salud global</p>
			<table class="widefat ip-table"><thead><tr><th>Área</th><th>Puntuación</th><th>Fallos</th><th>Avisos</th><th>Sin datos</th></tr></thead><tbody>
			<?php foreach ( $score['areas'] as $name => $a ) : ?>
				<?php
				if ( ! $a['applies'] ) {
					continue;
				}
				?>
				<tr><td><?php echo esc_html( $name ); ?></td><td><?php echo esc_html( (string) $a['score'] ); ?></td><td><?php echo (int) $a['fails']; ?></td><td><?php echo (int) $a['warns']; ?></td><td><?php echo (int) ( $a['nodata'] + $a['pending'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="description">Lo que no se pudo comprobar aparece como «Sin datos» y no cuenta en la puntuación: Pulso no inventa resultados.</p>
			<p>
				<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( self::action_url( 'html', $id ) ); ?>">Informe imprimible</a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'csv', $id ) ); ?>">CSV</a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'json', $id ) ); ?>">JSON</a>
			</p>
		</div>
		<?php
		foreach ( Inochi_Pulso_Catalog::PHASES as $phase => $label ) {
			if ( empty( $d['priorities'][ $phase ] ) ) {
				continue;
			}
			printf( '<h2>Prioridad: %s</h2>', esc_html( $label ) );
			foreach ( $d['priorities'][ $phase ] as $p ) {
				?>
				<div class="ip-card ip-priority">
					<h3><code><?php echo esc_html( $p['code'] ); ?></code> <?php echo esc_html( $p['title'] ); ?></h3>
					<p><?php echo esc_html( $p['cost'] ); ?></p>
					<p><strong>Hoy:</strong> <?php echo esc_html( $p['value'] ); ?><?php echo $p['target'] ? ' · <strong>Objetivo:</strong> ' . esc_html( $p['target'] ) : ''; ?></p>
					<p><strong>Qué hacer:</strong></p>
					<ul><?php foreach ( array_filter( explode( "\n", $p['proposal'] ) ) as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
					<p class="description"><strong>Cómo comprobarlo:</strong> <?php echo esc_html( $p['verify'] ); ?></p>
				</div>
				<?php
			}
		}

		if ( $d['plugins'] ) {
			echo '<h2>Autoload por plugin</h2><table class="widefat striped ip-table"><thead><tr><th>Plugin</th><th>Estado</th><th>Autoload</th><th>Opciones</th></tr></thead><tbody>';
			foreach ( $d['plugins'] as $p ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>', esc_html( $p['owner'] ), esc_html( $p['state'] ), esc_html( Inochi_Pulso_Util::bytes( $p['autoload_bytes'] ) ), (int) $p['autoload_options'] );
			}
			echo '</tbody></table>';
		}

		echo '<h2>Todas las comprobaciones</h2>';
		self::render_findings_table( $d['rows'] );
		?>
		<p class="ip-cta">¿Quieres que lo revise un ingeniero? <a href="<?php echo esc_url( Inochi_Pulso_Export::inochi_url() ); ?>" target="_blank" rel="noopener">Auditoría de Inochi</a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ip-delete" onsubmit="return confirm('¿Borrar este escaneo? No se puede deshacer.');">
			<input type="hidden" name="action" value="inochi_pulso_delete">
			<input type="hidden" name="scan" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'inochi_pulso_delete_' . $id ); ?>
			<button type="submit" class="button button-link-delete">Borrar este escaneo</button>
		</form>
		<?php
	}

	/**
	 * Tabla de resultados por área.
	 *
	 * @param array $rows Filas de Inochi_Pulso_Export::data().
	 */
	public static function render_findings_table( array $rows ) {
		echo '<table class="widefat ip-table ip-findings"><thead><tr><th>Código</th><th>Comprobación</th><th>Valor medido</th><th>Estado</th></tr></thead><tbody>';
		$area = '';
		foreach ( $rows as $r ) {
			if ( $r['area'] !== $area ) {
				$area = $r['area'];
				printf( '<tr class="ip-area"><th colspan="4">%s</th></tr>', esc_html( $area ) );
			}
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s%4$s</td><td><span class="%5$s">%6$s</span></td></tr>',
				esc_html( $r['code'] ),
				esc_html( $r['name'] ),
				esc_html( '' !== $r['value'] ? $r['value'] : '—' ),
				'' !== $r['evidence'] ? '<details><summary>Evidencia</summary><pre>' . esc_html( $r['evidence'] ) . '</pre></details>' : '',
				esc_attr( 'ip-state ip-' . sanitize_html_class( strtolower( str_replace( array( '.', ' ' ), array( '', '-' ), $r['state'] ) ) ) ),
				esc_html( $r['state'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Ajustes: consentimiento para consultar WordPress.org.
	 */
	private static function render_settings() {
		$s = Inochi_Pulso_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ip-card ip-settings">
			<input type="hidden" name="action" value="inochi_pulso_settings">
			<?php wp_nonce_field( 'inochi_pulso_settings' ); ?>
			<h2>Servicios externos</h2>
			<p>Por defecto, Pulso no se conecta a ningún servicio externo: todo el escaneo se hace en este servidor.</p>
			<p><label><input type="checkbox" name="wporg_consent" value="1" <?php checked( true === $s['wporg_consent'] ); ?>> <strong>Consultar plugins en WordPress.org</strong></label></p>
			<p class="description">Para detectar plugins cerrados o que llevan más de 2 años sin actualizarse, Pulso envía a la API de plugins de WordPress.org (api.wordpress.org) el nombre de carpeta (slug) de cada plugin instalado, incluidos los premium o hechos a medida. No se envía nada más. <a href="https://wordpress.org/about/privacy/" target="_blank" rel="noopener noreferrer">Política de privacidad de WordPress.org</a>.</p>
			<?php if ( $s['wporg_consent'] && $s['wporg_consent_at'] ) : ?>
				<p class="description">Activada el <?php echo esc_html( $s['wporg_consent_at'] ); ?> UTC.</p>
			<?php endif; ?>
			<p><button type="submit" class="button button-primary">Guardar ajustes</button></p>
		</form>
		<?php
	}
}
