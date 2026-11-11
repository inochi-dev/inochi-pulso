<?php
/**
 * Rutas REST para el escaneo por pasos (solo administradores).
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * API REST interna.
 */
class Inochi_Pulso_REST {

	const NS = 'inochi-pulso/v1';

	/**
	 * Registra las rutas.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Solo administradores.
	 */
	public static function can() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Rutas.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/scans',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/scans/(?P<id>\d+)/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'step' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'args'                => array(
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'step' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( Inochi_Pulso_Scanner::steps() ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/scans/(?P<id>\d+)/finish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'finish' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Nuevo escaneo.
	 */
	public static function create() {
		$id = Inochi_Pulso_DB::create_scan();
		if ( ! $id ) {
			return new WP_Error( 'inochi_pulso_db', 'No se pudo crear el registro de la auditoría.', array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'id'    => $id,
				'steps' => Inochi_Pulso_Scanner::step_list(),
			)
		);
	}

	/**
	 * Ejecuta un paso.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public static function step( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$scan = Inochi_Pulso_DB::get_scan( $id );
		if ( ! $scan ) {
			return new WP_Error( 'inochi_pulso_not_found', 'Auditoría no encontrada.', array( 'status' => 404 ) );
		}
		if ( 'running' !== $scan['status'] ) {
			return new WP_Error( 'inochi_pulso_closed', 'Esta auditoría ya está cerrada.', array( 'status' => 409 ) );
		}
		return rest_ensure_response( Inochi_Pulso_Scanner::run_step( $id, (string) $request['step'] ) );
	}

	/**
	 * Cierra el escaneo.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public static function finish( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Inochi_Pulso_DB::get_scan( $id ) ) {
			return new WP_Error( 'inochi_pulso_not_found', 'Auditoría no encontrada.', array( 'status' => 404 ) );
		}
		Inochi_Pulso_Scanner::finish( $id );
		return rest_ensure_response(
			array(
				'ok'  => true,
				'url' => admin_url( 'tools.php?page=inochi-pulso&scan=' . $id ),
			)
		);
	}
}
