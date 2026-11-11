<?php
/**
 * Conocimiento por comprobación: cómo completarla a mano y plantilla del hallazgo.
 *
 * {num} se sustituye por la cantidad medida (si no la hay, se usa el nombre de la comprobación). Los textos del hallazgo están
 * escritos para el cliente (lenguaje de negocio); se editan en el asistente.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base de conocimiento.
 */
class Inochi_Pulso_Knowledge {

	/**
	 * Datos de todas las comprobaciones.
	 */
	private static function data() {
		return array(
			'REN-01' => array(
				'title'    => 'El servidor tarda {num} en empezar a responder',
				'cost'     => 'Cada página, también el carrito y el pago, empieza a cargar tarde. Es tiempo que el visitante espera mirando una pantalla en blanco y que Google tiene en cuenta.',
				'proposal' => array( 'Medir qué parte del tiempo es PHP, base de datos o red con las mismas 5 páginas.', 'Corregir lo que más pese (consultas, autoload, plugins lentos, caché).', 'Revisar si el alojamiento tiene recursos suficientes para el tráfico real.' ),
				'effort'   => 'Medio',
				'hours'    => 5,
				'verify'   => 'Tiempo de respuesta de las mismas 5 páginas antes y después.',
				'target'   => 'Menos de 0,6 s',
			),
			'REN-05' => array(
				'title'    => 'Sin caché de página: cada visita genera la página desde cero',
				'cost'     => 'El servidor repite el mismo trabajo en cada visita: páginas más lentas, picos de carga y peor posición en Google.',
				'proposal' => array( 'Activar caché de página (plugin o servidor) excluyendo carrito, pago y mi cuenta.', 'Configurar la purga al publicar o actualizar stock.' ),
				'effort'   => 'Medio',
				'hours'    => 3,
				'verify'   => 'Cabecera de caché en HIT y tiempo de respuesta de la portada antes y después.',
				'target'   => 'Páginas servidas desde caché (HIT)',
			),
			'REN-06' => array(
				'title'    => 'Sin caché de objetos: la base de datos repite las mismas consultas',
				'cost'     => 'Más consultas por página y más tiempo en el carrito y el pago, que no se pueden servir desde la caché de página.',
				'proposal' => array( 'Activar Redis o Memcached en el alojamiento.', 'Instalar el conector de caché de objetos y comprobar que no rompe el carrito.' ),
				'effort'   => 'Bajo',
				'hours'    => 2,
				'verify'   => 'Consultas por página y tiempo de respuesta del carrito antes y después.',
				'target'   => 'Caché de objetos activa',
			),
			'REN-07' => array(
				'title'    => '{num} de datos que WordPress carga en cada visita',
				'cost'     => 'Cada página, carrito y pago incluidos, tarda más en servirse. WordPress avisa a partir de 0,8 MB.',
				'proposal' => array( 'Copia de la base de datos antes de tocar nada.', 'Borrar las opciones de plugins ya desinstalados.', 'Quitar la carga automática a opciones que solo usa el escritorio.', 'Limpiar los datos temporales caducados.' ),
				'effort'   => 'Bajo',
				'hours'    => 2,
				'verify'   => 'Tamaño del autoload y tiempo de respuesta en las mismas 5 páginas.',
				'target'   => 'Menos de 0,8 MB',
			),
			'REN-08' => array(
				'title'    => '{num} de datos de plugins ya borrados que se cargan en cada visita',
				'cost'     => 'Datos de plugins que ya no se usan y que se siguen cargando en cada página.',
				'proposal' => array( 'Confirmar que el plugin dueño ya no se usa.', 'Copia de la base de datos y borrado de esas opciones.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Autoload huérfano igual a 0 en la siguiente revisión.',
				'target'   => 'Menos de 100 KB',
			),
			'REN-09' => array(
				'title'    => 'Tablas de registros o restos que inflan la base de datos',
				'cost'     => 'Copias de seguridad más lentas y pesadas, restauraciones más largas y consultas más lentas.',
				'proposal' => array( 'Identificar a qué plugin pertenece cada tabla grande.', 'Vaciar registros antiguos y borrar tablas de plugins desinstalados, con copia previa.', 'Limitar la retención de registros en adelante.' ),
				'effort'   => 'Bajo',
				'hours'    => 2,
				'verify'   => 'Tamaño de la base de datos y de la copia antes y después.',
				'target'   => 'Ninguna tabla de registros por encima de 100 MB',
			),
			'REN-10' => array(
				'title'    => 'Datos temporales caducados en la base de datos ({num})',
				'cost'     => 'Ocupan espacio y ralentizan la tabla de opciones, que se lee en cada visita.',
				'proposal' => array( 'Borrar los transients caducados.', 'Comprobar que el cron de limpieza funciona.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Número de transients caducados en la siguiente revisión.',
				'target'   => 'Menos de 500',
			),
			'REN-11' => array(
				'title'    => 'Revisiones de contenido guardadas sin límite ({num})',
				'cost'     => 'Base de datos más grande de lo necesario: copias más lentas y consultas más pesadas.',
				'proposal' => array( 'Limitar las revisiones (por ejemplo, a 10 por entrada).', 'Borrar las revisiones antiguas, con copia previa.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Número de revisiones y tamaño de la base de datos.',
				'target'   => 'Revisiones limitadas',
			),
			'REN-12' => array(
				'title'    => 'Tareas programadas atrasadas o sin dueño',
				'variants' => array(
					'/(^|· )0 atrasad/u' => array(
						'title'    => 'Tareas programadas de plugins que ya no existen',
						'cost'     => 'El sistema intenta ejecutar tareas que ya no hacen nada: trabajo inútil en cada ejecución y ruido que oculta los problemas reales.',
						'proposal' => array( 'Eliminar las tareas huérfanas, con copia previa.', 'Sustituir WP-Cron por una tarea del servidor cada 5 minutos.' ),
						'hours'    => 0.5,
					),
				),
				'cost'     => 'Tareas automáticas que no se ejecutan a tiempo: correos, copias, limpiezas o sincronizaciones que se retrasan sin que nadie lo vea.',
				'proposal' => array( 'Sustituir WP-Cron por una tarea del servidor cada 5 minutos.', 'Eliminar las tareas de plugins desinstalados.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Sin eventos atrasados más de 1 hora en la siguiente revisión.',
				'target'   => 'Ninguna tarea atrasada ni huérfana, con cron real del servidor',
			),
			'REN-14' => array(
				'title'    => 'Configuración de PHP por debajo de lo recomendado',
				'cost'     => 'Errores de memoria en procesos pesados (importaciones, pedidos grandes) y PHP más lento sin OPcache.',
				'proposal' => array( 'Subir memory_limit a 256M o más.', 'Activar OPcache en el alojamiento.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Valores en Salud del sitio → Información → Servidor.',
				'target'   => '256 MB o más y OPcache activo',
			),
			'REN-16' => array(
				'title'    => 'Demasiados plugins activos ({num}): muchas piezas que mantener',
				'cost'     => 'Cada plugin añade código, consultas y riesgo. A partir de 40 es difícil saber qué hace cada uno y cuál falla.',
				'proposal' => array( 'Inventario de para qué sirve cada plugin.', 'Quitar los que no se usan o duplican funciones.' ),
				'effort'   => 'Medio',
				'hours'    => 3,
				'verify'   => 'Número de plugins activos y tiempo de respuesta.',
				'target'   => '40 o menos, todos con una función clara',
			),
			'SEG-01' => array(
				'title'    => 'Actualizaciones pendientes en el núcleo, los plugins o el tema ({num})',
				'cost'     => 'Las versiones antiguas acumulan fallos y vulnerabilidades conocidas. Cuanto más tiempo pasa, más arriesgado es actualizar de golpe.',
				'proposal' => array( 'Copia completa y prueba en un entorno de pruebas.', 'Actualizar primero lo menor y después lo mayor, una cosa cada vez.', 'Revisar formularios, carrito y pago tras cada actualización.' ),
				'effort'   => 'Medio',
				'hours'    => 4,
				'verify'   => 'Escritorio → Actualizaciones sin pendientes.',
				'target'   => 'Todo actualizado',
			),
			'SEG-03' => array(
				'title'    => 'Plugins abandonados o retirados de WordPress.org',
				'cost'     => 'Nadie corrige sus fallos. Si aparece una vulnerabilidad no habrá parche, y suelen dejar de funcionar con nuevas versiones de WordPress.',
				'proposal' => array( 'Buscar una alternativa mantenida para cada uno.', 'Sustituirlos en un entorno de pruebas y migrar sus datos.' ),
				'effort'   => 'Medio',
				'hours'    => 4,
				'verify'   => 'Ningún plugin cerrado ni sin actualizar en más de 2 años.',
				'target'   => 'Ningún plugin cerrado ni abandonado',
			),
			'SEG-04' => array(
				'title'    => 'Plugins de pago o a medida sin actualizaciones garantizadas',
				'cost'     => 'Sin licencia activa no llegan los parches de seguridad. Un plugin de origen dudoso puede traer código malicioso.',
				'proposal' => array( 'Renovar las licencias de los plugins de pago en uso.', 'Sustituir cualquier copia de origen dudoso por la oficial.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Licencia activa y versión al día en cada plugin de pago.',
				'target'   => 'Todos con licencia activa y actualizaciones',
			),
			'SEG-06' => array(
				'title'    => 'La versión de PHP pierde o ha perdido el soporte de seguridad',
				'cost'     => 'Los fallos de seguridad de PHP dejan de corregirse. Los plugins nuevos dejan de ser compatibles.',
				'proposal' => array( 'Probar la web con la versión de PHP actual en un entorno de pruebas.', 'Cambiar la versión en Plesk y revisar errores.' ),
				'effort'   => 'Bajo',
				'hours'    => 2,
				'verify'   => 'Versión de PHP con soporte de seguridad y sin errores nuevos en el registro.',
				'target'   => 'PHP 8.3 o superior',
			),
			'SEG-07' => array(
				'title'    => 'Problemas en la conexión segura (HTTPS)',
				'cost'     => 'Avisos de «no seguro» en el navegador, formularios y pagos expuestos y pérdida de confianza.',
				'proposal' => array( 'Forzar HTTPS en todo el sitio.', 'Corregir los recursos que se cargan por http.', 'Activar HSTS cuando todo funcione por HTTPS.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Redirección, certificado y consola del navegador sin avisos.',
				'target'   => 'HTTPS completo, con HSTS y sin contenido mixto',
			),
			'SEG-08' => array(
				'title'    => 'Faltan cabeceras básicas de seguridad',
				'cost'     => 'La web queda más expuesta a ataques que la meten dentro de otras webs o que abusan del navegador del visitante.',
				'proposal' => array( 'Añadir X-Content-Type-Options, X-Frame-Options y Referrer-Policy en el servidor o en Cloudflare.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Cabeceras presentes en la respuesta de la portada.',
				'target'   => 'Las 3 cabeceras básicas',
			),
			'SEG-09' => array(
				'title'    => 'Información de errores expuesta al público',
				'cost'     => 'Los errores muestran rutas, versiones y datos internos que facilitan un ataque. El registro puede incluir datos de clientes.',
				'proposal' => array( 'Desactivar la visualización de errores en producción.', 'Mover el registro fuera de la carpeta pública o bloquear su acceso.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Sin errores en pantalla y debug.log no descargable.',
				'target'   => 'Errores ocultos y registro inaccesible',
			),
			'SEG-10' => array(
				'title'    => 'El escritorio permite editar el código de la web',
				'cost'     => 'Si alguien entra con una cuenta de administrador, puede inyectar código en segundos sin acceso al servidor.',
				'proposal' => array( 'Añadir DISALLOW_FILE_EDIT en wp-config.php.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.25,
				'verify'   => 'El editor de archivos no aparece en el escritorio.',
				'target'   => 'Editor desactivado',
			),
			'SEG-11' => array(
				'title'    => 'Cuentas de administrador sin protección suficiente',
				'cost'     => 'Una contraseña robada da control total de la web. Las cuentas sin dueño conocido son puertas que nadie vigila.',
				'proposal' => array( 'Eliminar o degradar las cuentas que no se usan.', 'Activar el segundo factor para todos los administradores.', 'Renombrar o eliminar la cuenta «admin».' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Número de administradores y segundo factor activo en todos.',
				'target'   => 'Máximo 3 administradores, todos con dueño y segundo factor',
			),
			'SEG-12' => array(
				'title'    => 'La web publica los nombres de usuario',
				'cost'     => 'Facilita los ataques de fuerza bruta: el atacante ya tiene la mitad de las credenciales.',
				'proposal' => array( 'Restringir el listado de usuarios de la API a usuarios conectados.', 'Bloquear la redirección de ?author=.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Las dos peticiones no devuelven nombres.',
				'target'   => 'Ningún nombre de usuario público',
			),
			'SEG-13' => array(
				'title'    => 'XML-RPC abierto sin que se use',
				'cost'     => 'Es una vía habitual de ataques de fuerza bruta masivos y de sobrecarga del servidor.',
				'proposal' => array( 'Bloquear xmlrpc.php en el servidor o en Cloudflare si nada lo necesita.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.25,
				'verify'   => 'xmlrpc.php responde 403.',
				'target'   => 'XML-RPC bloqueado o protegido',
			),
			'SEG-14' => array(
				'title'    => 'Permisos de archivos demasiado abiertos',
				'cost'     => 'Otros usuarios del servidor o un proceso comprometido pueden leer contraseñas o modificar archivos.',
				'proposal' => array( 'wp-config.php a 600 o 640.', 'Carpetas a 755 y archivos a 644.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Sin archivos escribibles por todos.',
				'target'   => 'wp-config.php en 600 o 640 y nada escribible por todos',
			),
			'SEG-15' => array(
				'title'    => 'Archivos sobrantes accesibles desde la web',
				'cost'     => 'Una copia de la base de datos descargable expone todos los datos de clientes y las contraseñas. Un instalador olvidado permite tomar la web.',
				'proposal' => array( 'Borrar los archivos sobrantes hoy mismo.', 'Revisar los registros de acceso por si alguien los descargó.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Los archivos ya no existen ni responden desde la web.',
				'target'   => 'Ningún archivo sobrante',
			),
			'SEG-16' => array(
				'title'    => 'Sin cortafuegos web ni límite de intentos de acceso',
				'cost'     => 'La web recibe sin filtro los ataques automáticos y los intentos de adivinar contraseñas.',
				'proposal' => array( 'Poner la web detrás de un WAF (por ejemplo, Cloudflare).', 'Limitar los intentos de acceso y proteger /wp-admin.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Cabeceras del WAF presentes y bloqueo tras varios intentos fallidos.',
				'target'   => 'WAF y límite de intentos de acceso',
			),
			'WOO-01' => array(
				'title'    => 'WooCommerce o sus plantillas desactualizadas',
				'cost'     => 'Las plantillas antiguas del tema pueden romper el carrito o el pago tras una actualización, o dejar sin corregir fallos ya resueltos.',
				'proposal' => array( 'Actualizar las plantillas sobrescritas del tema a la versión actual.', 'Actualizar WooCommerce en un entorno de pruebas y revisar el proceso de compra.' ),
				'effort'   => 'Medio',
				'hours'    => 3,
				'verify'   => 'Estado del sistema sin plantillas obsoletas.',
				'target'   => 'WooCommerce y plantillas al día',
			),
			'WOO-02' => array(
				'title'    => 'Los pedidos no usan el almacenamiento moderno de WooCommerce (HPOS)',
				'variants' => array(
					'/HPOS activo/u' => array(
						'title'    => 'Pedidos pendientes de sincronizar en WooCommerce',
						'cost'     => 'Las dos copias de los pedidos no coinciden: si hubiera que volver al almacenamiento antiguo, faltarían pedidos.',
						'proposal' => array( 'Dejar terminar la sincronización o desactivarla si ya no se necesita.' ),
						'hours'    => 0.5,
						'verify'   => 'Sin pedidos pendientes de sincronizar.',
					),
				),
				'cost'     => 'Consultas de pedidos más lentas a medida que crece la tienda y menos compatibilidad futura.',
				'proposal' => array( 'Comprobar la compatibilidad de los plugins.', 'Activar la sincronización, migrar y cambiar a HPOS.' ),
				'effort'   => 'Medio',
				'hours'    => 3,
				'verify'   => 'HPOS activo y sin pedidos pendientes de sincronizar.',
				'target'   => 'HPOS activo y sin pedidos pendientes',
			),
			'WOO-03' => array(
				'title'    => 'Falta una página básica de la tienda',
				'cost'     => 'Una página de carrito, pago o cuenta que falla impide comprar.',
				'proposal' => array( 'Asignar y publicar la página correcta en los ajustes de WooCommerce.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Las 4 páginas asignadas y un pedido de prueba completo.',
				'target'   => 'Las 4 páginas asignadas y publicadas',
			),
			'WOO-04' => array(
				'title'    => 'Pasarela de pago mal configurada',
				'cost'     => 'Pedidos que se dan por pagados sin cobrarse, o pasarelas activas que nadie usa y que amplían el riesgo.',
				'proposal' => array( 'Pasar a modo real o desactivar las pasarelas en pruebas.', 'Desactivar las pasarelas que no se usan.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Pedido real de prueba con cobro y devolución.',
				'target'   => 'Solo pasarelas en uso y en modo real',
			),
			'WOO-05' => array(
				'title'    => 'Avisos automáticos a otros sistemas que fallan',
				'cost'     => 'Pedidos que no llegan al ERP, al almacén o a la facturación sin que nadie lo note.',
				'proposal' => array( 'Revisar el destino de cada webhook con fallos.', 'Reactivarlo y reenviar los pedidos que no llegaron.' ),
				'effort'   => 'Medio',
				'hours'    => 2,
				'verify'   => 'Webhooks activos y sin fallos durante una semana.',
				'target'   => 'Webhooks sin fallos',
			),
			'WOO-06' => array(
				'title'    => 'Pedidos sin cerrar más de una semana',
				'cost'     => 'Pedidos que nadie termina de gestionar: clientes esperando una respuesta o una entrega, y cifras de ventas y stock que no cuadran.',
				'proposal' => array( 'Confirmar con el equipo si es su forma normal de trabajar.', 'Cerrar los pedidos ya resueltos.', 'Corregir el paso del proceso que los deja parados.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Sin pedidos procesando o en espera de más de 7 días.',
				'target'   => 'Ningún pedido sin cerrar más de 7 días',
			),
			'WOO-07' => array(
				'title'    => 'Procesos automáticos que fallan ({num} en 30 días)',
				'cost'     => 'Procesos automáticos que no terminan: stock desfasado, correos que no salen o sincronizaciones incompletas.',
				'proposal' => array( 'Leer el motivo del fallo de cada tipo de acción.', 'Corregir la causa y volver a lanzar las acciones pendientes.' ),
				'effort'   => 'Medio',
				'hours'    => 5,
				'verify'   => 'Acciones fallidas en los siguientes 30 días.',
				'target'   => 'Menos de 10 fallos al mes',
			),
			'WOO-08' => array(
				'title'    => 'Procesos automáticos atrasados ({num})',
				'cost'     => 'La cola de tareas no avanza: los procesos de la tienda se ejecutan tarde o no se ejecutan.',
				'proposal' => array( 'Comprobar que el cron del servidor funciona.', 'Desatascar la cola y vigilarla una semana.' ),
				'effort'   => 'Bajo',
				'hours'    => 2,
				'verify'   => 'Sin acciones con más de 1 hora de retraso.',
				'target'   => 'Menos de 10 acciones atrasadas',
			),
			'WOO-09' => array(
				'title'    => 'Sesiones de compra caducadas acumuladas ({num})',
				'cost'     => 'Tabla que crece sin control y ralentiza el carrito.',
				'proposal' => array( 'Limpiar las sesiones caducadas.', 'Comprobar que la limpieza automática funciona.' ),
				'effort'   => 'Bajo',
				'hours'    => 0.5,
				'verify'   => 'Sesiones caducadas por debajo de 10.000.',
				'target'   => 'Menos de 10.000',
			),
			'MAN-01' => array(
				'title'    => 'Copias de seguridad insuficientes',
				'cost'     => 'Ante un fallo, un ataque o un error humano no hay forma fiable de recuperar la web y sus pedidos.',
				'proposal' => array( 'Copia diaria automática de archivos y base de datos.', 'Guardarla fuera del servidor.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Registro de copias diarias en el destino externo.',
				'target'   => 'Copias diarias fuera del servidor',
			),
			'MAN-06' => array(
				'title'    => 'Actualizaciones sin procedimiento',
				'cost'     => 'Un plugin que se actualiza solo puede romper formularios o el pago sin que nadie lo revise.',
				'proposal' => array( 'Desactivar la actualización automática de los plugins críticos.', 'Procedimiento: copia, prueba, actualización y revisión.' ),
				'effort'   => 'Bajo',
				'hours'    => 1,
				'verify'   => 'Procedimiento documentado y aplicado en la siguiente actualización.',
				'target'   => 'Procedimiento de actualización documentado',
			),
			'MAN-07' => array(
				'title'    => 'Errores graves de PHP en los últimos 7 días ({num})',
				'cost'     => 'Páginas o procesos que fallan para algunos visitantes sin que nadie lo vea.',
				'proposal' => array( 'Identificar el plugin o la plantilla de cada error.', 'Corregirlo o sustituirlo.' ),
				'effort'   => 'Medio',
				'hours'    => 3,
				'verify'   => 'Registro de errores sin fatales durante 7 días.',
				'target'   => 'Ningún error fatal',
			),
		);
	}

	/**
	 * Instrucciones para completar una comprobación a mano.
	 */
	public static function how( $code ) {
		$d = self::data();
		return $d[ $code ]['how'] ?? '';
	}

	/**
	 * Leyenda de una comprobación: qué escribir en «Valor medido» y un ejemplo.
	 *
	 * @return array{write: string, example: string, target: string}
	 */
	public static function legend( $code ) {
		$d = self::data();
		return array(
			'write'   => $d[ $code ]['write'] ?? '',
			'example' => $d[ $code ]['example'] ?? '',
			'target'  => $d[ $code ]['target'] ?? '',
		);
	}

	/**
	 * Objetivo para el cliente.
	 */
	public static function target( $code ) {
		$d = self::data();
		return $d[ $code ]['target'] ?? Inochi_Pulso_Catalog::target( $code );
	}

	/**
	 * Comprobaciones cuyo valor manual tiene que dar contexto (herramienta, fecha, frecuencia…).
	 */
	const NEEDS_DETAIL = array( 'SEG-04', 'SEG-11', 'WOO-06', 'MAN-01', 'MAN-02', 'MAN-03', 'MAN-04', 'MAN-05', 'MAN-06' );

	/**
	 * ¿El valor indica que no hay datos (no demuestra un OK)?
	 *
	 * @param string $value Valor medido.
	 */
	public static function is_no_data_value( $value ) {
		$v = trim( (string) $value );
		return '' === $v || (bool) preg_match( '/^(sin acceso|sin registro|sin datos|no se pud|no hay acceso|no tengo acceso|desconocid|no disponible)|^(\?|—|-|n\/?d)$/iu', $v );
	}

	/**
	 * Plantilla del hallazgo.
	 *
	 * @param string $code  Código.
	 * @param string $state Estado (Fallo o Aviso).
	 * @param string $value Valor medido.
	 * @return array|null title, cost, proposal (texto con líneas), effort, hours, verify.
	 */
	public static function finding( $code, $state, $value = '' ) {
		$d = self::data();
		if ( ! isset( $d[ $code ] ) ) {
			return null;
		}
		$t = $d[ $code ];
		foreach ( (array) ( $t['variants'] ?? array() ) as $re => $over ) {
			if ( preg_match( $re, (string) $value ) ) {
				$t = array_merge( $t, $over );
				break;
			}
		}
		$num   = self::leading_number( $value );
		$title = $t['title'];
		if ( false !== strpos( $title, '{num}' ) ) {
			if ( '' === $num ) {
				$c     = Inochi_Pulso_Catalog::get( $code );
				$title = $c ? $c['name'] : $code;
			} else {
				$title = str_replace( '{num}', $num, $title );
			}
		}
		return array(
			'title'    => self::sentence( $title ),
			'cost'     => $t['cost'],
			'proposal' => implode( "\n", $t['proposal'] ),
			'effort'   => $t['effort'],
			'hours'    => $t['hours'],
			'verify'   => $t['verify'],
			'target'   => $t['target'] ?? '',
			'state'    => $state,
		);
	}

	/**
	 * Cantidad con su unidad al principio de un valor («1,70 s (…)» → «1,70 s»).
	 */
	public static function leading_number( $value ) {
		return preg_match( '/^\s*([0-9][0-9.,]*\s?(?:ms|s|MB|KB|GB|B|%)?)(?=\s|$|\))/u', (string) $value, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * Primera letra en mayúscula.
	 */
	private static function sentence( $text ) {
		if ( '' === $text ) {
			return $text;
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 ) : ucfirst( $text );
	}
}
