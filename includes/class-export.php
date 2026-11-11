<?php
/**
 * Exportaciones: JSON, CSV e informe HTML imprimible.
 *
 * Ninguna exportación incluye nombres de usuario ni emails: las evidencias ya se
 * guardan sin ellos y se vuelven a limpiar aquí con Inochi_Pulso_Util::redact().
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exportación de un escaneo.
 */
class Inochi_Pulso_Export {

	const UTM = 'utm_source=inochi-pulso&utm_medium=plugin&utm_campaign=informe';

	/**
	 * Estado mostrado: sin estado decidido cuenta como «Sin datos».
	 *
	 * @param array|null $f Resultado.
	 */
	public static function state( $f ) {
		$s = $f ? (string) $f['state'] : '';
		return '' === $s ? Inochi_Pulso_Catalog::NO_DATA : $s;
	}

	/**
	 * Datos comunes de un escaneo para pantalla y exportaciones.
	 *
	 * @param int $scan_id ID.
	 * @return array|null
	 */
	public static function data( $scan_id ) {
		$scan = Inochi_Pulso_DB::get_scan( $scan_id );
		if ( ! $scan ) {
			return null;
		}
		$findings = Inochi_Pulso_DB::get_findings( $scan_id );
		$payload  = (array) $scan['payload'];
		$is_woo   = ! empty( $payload['gen']['is_woo'] );
		$score    = Inochi_Pulso_Catalog::score( $findings, $is_woo );

		$rows = array();
		foreach ( Inochi_Pulso_Catalog::all() as $code => $c ) {
			$f      = $findings[ $code ] ?? null;
			$state  = self::state( $f );
			$rows[] = array(
				'code'     => $code,
				'area'     => $c['area'],
				'name'     => $c['name'],
				'severity' => $c['severity'],
				'state'    => $state,
				'value'    => $f ? Inochi_Pulso_Util::redact( $f['value'] ) : '',
				'evidence' => $f ? Inochi_Pulso_Util::redact( $f['evidence'] ) : '',
				'phase'    => in_array( $state, array( 'Fallo', 'Aviso' ), true ) ? Inochi_Pulso_Catalog::phase( $code, $state ) : '',
			);
		}

		$priorities = array();
		foreach ( Inochi_Pulso_Catalog::rank( $findings ) as $code ) {
			$state = self::state( $findings[ $code ] );
			$k     = Inochi_Pulso_Knowledge::finding( $code, $state, (string) $findings[ $code ]['value'] );
			if ( ! $k ) {
				continue;
			}
			$priorities[ Inochi_Pulso_Catalog::phase( $code, $state ) ][] = array(
				'code'     => $code,
				'state'    => $state,
				'title'    => $k['title'],
				'cost'     => $k['cost'],
				'proposal' => $k['proposal'],
				'verify'   => $k['verify'],
				'target'   => $k['target'],
				'value'    => Inochi_Pulso_Util::redact( $findings[ $code ]['value'] ),
			);
		}

		return array(
			'scan'       => $scan,
			'site'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'score'      => $score,
			'rows'       => $rows,
			'priorities' => $priorities,
			'plugins'    => self::plugin_cost( $payload ),
		);
	}

	/**
	 * Coste por plugin a partir de lo que ha recogido el escaneo.
	 *
	 * @param array $payload Datos del escaneo.
	 * @return array slug => [autoload_bytes, autoload_options].
	 */
	public static function plugin_cost( array $payload ) {
		$out = array();
		foreach ( (array) ( $payload['autoload'] ?? array() ) as $r ) {
			$owner = (string) ( $r['owner'] ?? '' );
			if ( '' === $owner ) {
				$owner = '—';
			}
			if ( ! isset( $out[ $owner ] ) ) {
				$out[ $owner ] = array(
					'owner'            => $owner,
					'state'            => (string) ( $r['state'] ?? '' ),
					'autoload_bytes'   => 0,
					'autoload_options' => 0,
				);
			}
			$out[ $owner ]['autoload_bytes'] += (int) ( $r['bytes'] ?? 0 );
			++$out[ $owner ]['autoload_options'];
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['autoload_bytes'] <=> $a['autoload_bytes'];
			}
		);
		return array_values( $out );
	}

	/**
	 * Nombre de archivo de una exportación.
	 *
	 * @param array  $d   Datos.
	 * @param string $ext Extensión.
	 */
	public static function filename( array $d, $ext ) {
		return sanitize_file_name( 'pulso-' . $d['site'] . '-' . substr( (string) $d['scan']['created_at'], 0, 10 ) . '-' . (int) $d['scan']['id'] . '.' . $ext );
	}

	/**
	 * JSON.
	 *
	 * @param array $d Datos.
	 */
	public static function json( array $d ) {
		return (string) wp_json_encode(
			array(
				'generator'  => 'Inochi Pulso ' . INOCHI_PULSO_VERSION,
				'site'       => $d['site'],
				'scan_id'    => (int) $d['scan']['id'],
				'created_at' => $d['scan']['created_at'],
				'score'      => array(
					'global' => $d['score']['global'],
					'areas'  => wp_list_pluck( $d['score']['areas'], 'score' ),
				),
				'checks'     => $d['rows'],
				'plugins'    => $d['plugins'],
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * CSV (separado por punto y coma, con BOM para Excel en español).
	 *
	 * @param array $d Datos.
	 */
	public static function csv( array $d ) {
		$h = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flujo en memoria.
		fwrite( $h, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $h, array( 'Código', 'Área', 'Comprobación', 'Severidad', 'Estado', 'Prioridad', 'Valor medido', 'Evidencia' ), ';' );
		foreach ( $d['rows'] as $r ) {
			fputcsv( $h, array( $r['code'], $r['area'], $r['name'], $r['severity'], $r['state'], $r['phase'] ? Inochi_Pulso_Catalog::PHASES[ $r['phase'] ] : '', self::cell( $r['value'] ), self::cell( $r['evidence'] ) ), ';' );
		}
		rewind( $h );
		$out = (string) stream_get_contents( $h );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * Evita que una hoja de cálculo interprete una celda como fórmula.
	 *
	 * @param string $v Valor.
	 */
	private static function cell( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/**
	 * Enlace a Inochi (único en el plugin), con UTM.
	 */
	public static function inochi_url() {
		return 'https://inochi.dev/auditoria-wordpress/?' . self::UTM;
	}

	/**
	 * Informe HTML imprimible (documento completo, estilos en línea, sin recursos externos).
	 *
	 * @param array $d Datos.
	 */
	public static function html( array $d ) {
		$e = static function ( $t ) {
			return esc_html( (string) $t );
		};
		ob_start();
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title><?php echo $e( 'Inochi Pulso · ' . $d['site'] . ' · ' . $d['scan']['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $e escapa. ?></title>
<style>
body{font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#0b1f33;max-width:960px;margin:24px auto;padding:0 16px}
h1{font-size:22px;margin:0 0 4px}h2{font-size:17px;margin:28px 0 8px;border-bottom:2px solid #0b1f33;padding-bottom:4px}h3{font-size:15px;margin:16px 0 4px}
table{border-collapse:collapse;width:100%;margin:8px 0}th,td{border:1px solid #ccd3da;padding:5px 7px;text-align:left;vertical-align:top}th{background:#eef1f4}
.s-OK{color:#0a7d3b}.s-Aviso{color:#9a5b00}.s-Fallo{color:#b3261e;font-weight:600}.muted{color:#5b6b7a}pre{white-space:pre-wrap;font-size:12px;margin:0}
.score{font-size:32px;font-weight:700}.no-print{margin:12px 0}@media print{.no-print{display:none}h2{break-after:avoid}tr{break-inside:avoid}}
</style>
</head>
<body>
<p class="no-print"><button type="button" onclick="window.print()">Imprimir o guardar como PDF</button></p>
<h1>Informe de Inochi Pulso</h1>
<p class="muted"><?php echo $e( $d['site'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> · <?php echo $e( $d['scan']['created_at'] . ' UTC' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> · Inochi Pulso <?php echo $e( INOCHI_PULSO_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

<h2>Salud del sitio</h2>
<p><span class="score"><?php echo $e( null === $d['score']['global'] ? '—' : $d['score']['global'] . ' / 100' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></p>
<table><tr><th>Área</th><th>Puntuación</th><th>Fallos</th><th>Avisos</th><th>Sin datos</th></tr>
		<?php foreach ( $d['score']['areas'] as $name => $a ) : ?>
			<?php
			if ( ! $a['applies'] ) {
				continue;
			}
			?>
<tr><td><?php echo $e( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo $e( $a['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo (int) $a['fails']; ?></td><td><?php echo (int) $a['warns']; ?></td><td><?php echo (int) ( $a['nodata'] + $a['pending'] ); ?></td></tr>
		<?php endforeach; ?>
</table>
<p class="muted">Lo que no se pudo comprobar aparece como «Sin datos» y no cuenta en la puntuación.</p>

		<?php foreach ( Inochi_Pulso_Catalog::PHASES as $phase => $label ) : ?>
			<?php
			if ( empty( $d['priorities'][ $phase ] ) ) {
				continue;
			}
			?>
<h2>Prioridad: <?php echo $e( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
			<?php foreach ( $d['priorities'][ $phase ] as $p ) : ?>
<h3><?php echo $e( $p['code'] . ' · ' . $p['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
<p><?php echo $e( $p['cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<p><strong>Hoy:</strong> <?php echo $e( $p['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $p['target'] ? ' · <strong>Objetivo:</strong> ' . $e( $p['target'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
<p><strong>Qué hacer:</strong></p><pre><?php echo $e( $p['proposal'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></pre>
<p class="muted"><strong>Cómo comprobarlo:</strong> <?php echo $e( $p['verify'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<?php if ( $d['plugins'] ) : ?>
<h2>Autoload por plugin</h2>
<table><tr><th>Plugin</th><th>Estado</th><th>Autoload</th><th>Opciones</th></tr>
			<?php foreach ( $d['plugins'] as $p ) : ?>
<tr><td><?php echo $e( $p['owner'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo $e( $p['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo $e( Inochi_Pulso_Util::bytes( $p['autoload_bytes'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo (int) $p['autoload_options']; ?></td></tr>
			<?php endforeach; ?>
</table>
		<?php endif; ?>

<h2>Todas las comprobaciones</h2>
<table><tr><th>Código</th><th>Comprobación</th><th>Valor medido</th><th>Estado</th></tr>
		<?php foreach ( $d['rows'] as $r ) : ?>
<tr><td><?php echo $e( $r['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo $e( $r['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo $e( $r['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="s-<?php echo esc_attr( $r['state'] ); ?>"><?php echo $e( $r['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
		<?php endforeach; ?>
</table>

</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
