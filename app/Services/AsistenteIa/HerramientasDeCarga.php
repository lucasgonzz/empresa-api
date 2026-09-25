<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\asistente_ia\AltaDeArticuloConFotoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConsultasDeCargaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EntradaDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\OpcionesDeCargaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FiltroDeArticulosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaBancosChequesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaComboIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCompraConFacturaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaDisenoPdfIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoArticuloIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaFotoSucursalIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaGastoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaGenericaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaImagenesArticulosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaImagenesCategoriasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaOfertaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPagoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPermisoEmpleadoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPresupuestoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaStockIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaAccionDePantallaIaHelper;
use App\Http\Controllers\Helpers\ofertas\ClientOfertaAltaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Illuminate\Support\Facades\Log;

/**
 * Las herramientas de carga del asistente de IA (misión asistente-ia-acciones, §3.3 del plan): cinco
 * lecturas nuevas y cinco propuestas que arman tarjetas para que la persona confirme. La misión
 * agente-ia-mano-derecha (16/9/2026) le sumó dos propuestas más: proponer_combo y proponer_oferta.
 * La misión asistente-masivas-imagenes-y-remito (19/9/2026) sumó siete al FINAL del array: las
 * imágenes de categorías y de artículos por filtro, el conteo por filtro, la actualización masiva y
 * los diseños de PDF. La misión cheques-endoso-y-bancos (21/9/2026) sumó dos más al final:
 * consultar y unificar los bancos de los cheques. La misión asistente-omnisciente (21/9/2026)
 * sumó cinco más después de esas: el ABM genérico (que_puedo_cargar, proponer_alta,
 * proponer_edicion, proponer_baja) y proponer_venta. La misión asistente-ventas-y-fotos (21/9/2026)
 * sumó proponer_foto_articulo después de esas. La misión asistente-capacidades-y-hilos (22/9/2026)
 * sumó cuatro más (los dos de stock, el presupuesto y el permiso de un empleado). La misión
 * asistente-mcp (22/9/2026) sumó las cuatro ACCIONES DE PANTALLA al final de todo:
 * que_acciones_de_pantalla_hay, consultar_por_pantalla, proponer_accion_de_pantalla y
 * proponer_borrado_por_pantalla, la herramienta genérica que llama a la misma ruta que llama la
 * pantalla (ver CatalogoDeAccionesDePantallaIaHelper). Van al final porque el orden es parte
 * del caché de prompt (ver build_tools()).
 *
 * 🔴 LAS DOS PUNTAS DE CADA HERRAMIENTA VIVEN EN ESTE ARCHIVO: la definición (definiciones(), lo que
 * Claude ve) y el despacho (el `case` de ejecutar(), lo que corre al llamarla). Es la misma regla que
 * el docblock de AsistenteIaService::build_tools(): una herramienta declarada en un solo lado es una
 * herramienta que la IA "tiene" y al usarla recibe "Tool desconocida". Se agregan juntas, y el test
 * 15_Acciones_service_y_job_Test lo verifica leyendo este archivo.
 *
 * AsistenteIaService las suma a la lista SOLO cuando el mensaje tiene `acciones_habilitadas` (la SPA
 * nueva manda `acciones: true`), y les delega el despacho con la misma condición. Sin el flag, el
 * asistente queda exactamente como antes, de solo lectura.
 *
 * Respuesta de toda herramienta: JSON. Una falta de datos, una ambigüedad o una carga no permitida
 * vuelven como `{"ok": false, "faltan", "opciones", "error"}` SIN is_error (RespuestaDeCargaIa); is_error
 * queda para las fallas técnicas.
 */
class HerramientasDeCarga
{
    /**
     * Los tipos de tarjeta que el modo "resuelto" auto-confirma tras proponerlos (misión
     * foto-sucursal-y-asistente-configurable, 17/9/2026).
     *
     * 🔴 SOLO CARGAS INOCUAS Y REVERSIBLES. La foto de una sucursal (se deshace desde el ABM), mandar
     * a buscar imágenes para categorías o artículos (una imagen se saca desde la ficha; la búsqueda
     * en sí no toca nada más) y cambiar las columnas de un diseño de PDF (se vuelve a cambiar desde
     * ABM > Impresión). Todo lo que mueve plata (gastos, pagos, combos, ofertas) NUNCA se
     * auto-confirma, ni siquiera en "resuelto" — siempre lo confirma la persona.
     *
     * 🔴 LA EXCEPCIÓN ES LA COMPRA CON FACTURA, Y ES DECISIÓN DE LUCAS (24/9/2026, misión
     * asistente-fotos-barras-y-compras): "se ejecuta directo, sin confirmar", incluida el alta del
     * proveedor si no existe. No rompe la regla de arriba porque esa compra NO mueve plata ni stock
     * todavía: nace "En proceso", vacía, con `update_stock = 0` y `update_prices = 0`, y lo único que
     * hace es colgarle la foto de la factura al escaneo. La confirmación real —qué artículos, a qué
     * precio, qué stock entra— es la revisión del escaneo desde Compras, que sigue siendo de la
     * persona. En "cauteloso" queda UNA tarjeta que cubre todo (proveedor nuevo incluido). En demo3
     * (conv 12) la compra pedida de una tardó tres mensajes en armarse.
     *
     * 🔴 LA ACTUALIZACIÓN MASIVA NO ESTÁ NI VA A ESTAR ACÁ. Reescribe precios, márgenes, stock o
     * proveedores de cientos de artículos de un saque; aunque se pueda revertir, la persona tiene
     * que ver cuántos alcanza y confirmar (decisión de Lucas, misión asistente-masivas-imagenes-y-
     * remito). Su `case` en ejecutar() tampoco pasa por quizas_auto_confirmar(): eso lo fijan los
     * tests 36 y 51, que leen el archivo; el test 26 cuida la lista, no el `case`. Lo mismo para
     * unificar los bancos de los cheques (misión
     * cheques-endoso-y-bancos): toca N cheques de un saque y decide a qué banco va cada texto.
     * Antes de sumar un tipo acá, tiene que cumplir las dos condiciones de arriba.
     *
     * 🔴 TAMPOCO ENTRAN LAS GENÉRICAS (alta, edicion, baja), NI LA VENTA (misión
     * asistente-omnisciente, 21/9/2026), NI LA FOTO DE UN ARTÍCULO (misión asistente-ventas-y-fotos,
     * 21/9/2026), aunque la foto de SUCURSAL sí esté acá arriba. No es una inconsistencia: la
     * sucursal se elige entre unas pocas y por su nombre completo —no puede errarle al destino— y su
     * foto no sale publicada en ningún lado; la del artículo se resuelve INFIRIENDO de un nombre que
     * el dueño puede decir inexacto, y si le erra, la foto equivocada se PUBLICA (dispara Tienda
     * Nube y Mercado Libre, y se replica en el catálogo de los comercios vinculados).
     *
     * ⚠️ DESDE EL 22/9/2026 ESTA LISTA ES LA DEL MODO "RESUELTO" Y NADA MÁS. El modo "directo"
     * (misión asistente-capacidades-y-hilos) tiene la suya, AUTO_CONFIRMABLES_DIRECTO, y ahí sí
     * entran las genéricas de alta y edición, la venta y la foto de un artículo — el dueño lo prendió
     * a conciencia desde la configuración. Lo que cambió con esa misión es que los `case` de esas
     * propuestas SÍ pasan por quizas_auto_confirmar(): quién se ejecuta lo decide el modo adentro de
     * la puerta, no la ausencia de la llamada. Los únicos `case` que siguen sin pasar por ahí son
     * los de NUNCA_AUTO_CONFIRMABLES —cinco desde el 22/9/2026: el permiso de un empleado y, con la
     * misión asistente-mcp del mismo día, el borrado por pantalla—, y
     * eso lo fijan los tests 36, 51 y 55 LEYENDO ESTE ARCHIVO como texto plano (el `switch` no es
     * introspectable de otra forma). El test 26 también cuida la masiva, pero solo por la
     * constante: no lee el archivo, así que no cubre la tercera guarda.
     *
     * @var array<int, string>
     */
    const AUTO_CONFIRMABLES = [
        AiMessageAction::TIPO_FOTO_SUCURSAL,
        AiMessageAction::TIPO_IMAGENES_CATEGORIAS,
        AiMessageAction::TIPO_IMAGENES_ARTICULOS,
        AiMessageAction::TIPO_DISENO_PDF,
        /* Al final, por la regla de siempre. Ver el 🔴 de la compra con factura en el docblock. */
        AiMessageAction::TIPO_COMPRA_CON_FACTURA,
    ];

    /**
     * Los tipos que el modo "directo" ejecuta en el acto, sin dejar tarjeta (misión
     * asistente-capacidades-y-hilos, 22/9/2026).
     *
     * 🔴 POR QUÉ EXISTE ESTA LISTA, Y POR QUÉ NO ES UNA FRASE EN EL CHAT. El 22/9/2026 Lucas le
     * pidió TRES VECES al agente, explícito, que dejara de pedirle confirmación, y el mensaje
     * siguiente del agente volvió a pedirle una. Un pago le costó 2 vueltas, una venta 4 y un alta
     * de proveedor 3 (y ni siquiera se creó). La decisión de Lucas fue prenderlo desde la
     * CONFIGURACIÓN del asistente, una vez y a conciencia: una frase suelta en la conversación la
     * puede escribir cualquier texto que devuelva una tool —la observación de un pedido, el chat de
     * un comprador de la tienda— y eso sería una puerta a ejecutar cargas sin que nadie las pida.
     *
     * Empieza con los cuatro de "resuelto" (en "directo" siguen valiendo, no se pierde ninguno) y
     * sigue con lo que suma este modo. Los agregados van al FINAL: es la misma regla del caché de
     * prompt que vale para las tools, y además deja que dos misiones en paralelo sumen tipos sin
     * pisarse.
     *
     * 🔴 LO QUE NO ESTÁ ACÁ NO ESTÁ POR ERROR, está por decisión (ver NUNCA_AUTO_CONFIRMABLES).
     *
     * @var array<int, string>
     */
    const AUTO_CONFIRMABLES_DIRECTO = [
        AiMessageAction::TIPO_FOTO_SUCURSAL,
        AiMessageAction::TIPO_IMAGENES_CATEGORIAS,
        AiMessageAction::TIPO_IMAGENES_ARTICULOS,
        AiMessageAction::TIPO_DISENO_PDF,
        AiMessageAction::TIPO_GASTO,
        AiMessageAction::TIPO_PAGO,
        AiMessageAction::TIPO_TAREA_NUEVA,
        AiMessageAction::TIPO_TAREA_EDITAR,
        AiMessageAction::TIPO_TAREA_COMPLETAR,
        AiMessageAction::TIPO_COMBO,
        AiMessageAction::TIPO_OFERTA,
        AiMessageAction::TIPO_COMPRA_CON_FACTURA,
        AiMessageAction::TIPO_FOTO_ARTICULO,
        AiMessageAction::TIPO_ALTA,
        AiMessageAction::TIPO_EDICION,
        AiMessageAction::TIPO_VENTA,
        /*
         * Las capacidades nuevas de la misión asistente-capacidades-y-hilos (22/9/2026), atrás de
         * lo que había. Las tres son reversibles con otra carga del mismo peso: mover stock entre
         * dos depósitos del mismo negocio no cambia el stock total, dejar el stock de un depósito
         * en un número se vuelve a cambiar igual, y un presupuesto no toca stock ni caja ni cuenta
         * corriente (eso pasa recién al confirmarlo desde la pantalla de Presupuestos).
         *
         * 🔴 TIPO_PERMISO_EMPLEADO NO ESTÁ ACÁ, y está en NUNCA_AUTO_CONFIRMABLES: ver ahí.
         */
        AiMessageAction::TIPO_MOVIMIENTO_STOCK,
        AiMessageAction::TIPO_STOCK_DEPOSITO,
        AiMessageAction::TIPO_PRESUPUESTO,
        /*
         * Las acciones de pantalla que NO borran (misión asistente-mcp, 22/9/2026), atrás de lo que
         * había. Con el dueño en "directo", lo que la pantalla hace por POST o PUT se hace en el acto
         * igual que el resto de las cargas de este modo: es lo que "literalmente todo lo que se
         * hace desde la interfaz" significa cuando el dueño ya pidió que no le pregunten. Lo que
         * pudiera ser masivo o irreversible por POST/PUT no entra al catálogo (ver
         * CatalogoDeAccionesDePantallaIaHelper::EXCLUIDAS), así que no llega hasta acá.
         *
         * 🔴 TIPO_BORRADO_PANTALLA NO ESTÁ ACÁ, y está en NUNCA_AUTO_CONFIRMABLES: ver ahí.
         */
        AiMessageAction::TIPO_ACCION_PANTALLA,
    ];

    /**
     * Los tipos que NO se auto-ejecutan en NINGÚN modo, ni siquiera en "directo" (decisión de
     * Lucas, misión asistente-capacidades-y-hilos).
     *
     * 🔴 `baja`: 31 de las 40 entidades del catálogo de escritura NO usan SoftDeletes. Borrar una
     * lista de precios deja a los clientes que la tenían colgados y no hay vuelta atrás — no es
     * "reversible con otra carga", es irreversible. La confirma siempre la persona.
     * 🔴 `actualizacion_masiva`: toca TODOS los artículos que cumplen un filtro de un saque. La
     * persona tiene que ver cuántos alcanza antes de que pase.
     * 🔴 `unificar_bancos_cheques`: toca N cheques de un saque y decide a qué banco va cada texto.
     * 🔴 `permiso_empleado` (22/9/2026): `EmployeeController::update()` REEMPLAZA la lista entera de
     * permisos (`sync([])` y después un attach por permiso) y reescribe la contraseña en cada
     * llamada (`password = bcrypt($request->visible_password)`). Una tarjeta mal armada deja a un
     * empleado sin ninguno de sus permisos o directamente sin poder entrar al sistema, y eso no lo
     * nota nadie hasta que esa persona llega a trabajar. No es reversible con otra carga: es
     * reversible con otra carga *si alguien se entera*. Por eso, aunque el dueño tenga el modo
     * directo prendido, esta tarjeta se confirma siempre, y su presentación dice con qué lista de
     * permisos queda el empleado, no solo cuál se toca.
     * 🔴 `borrado_pantalla` (misión asistente-mcp, 22/9/2026): un DELETE de cualquier pantalla del
     * sistema. Es la hermana de `baja` con menos información todavía: acá ni siquiera se conoce la
     * tabla como para decir si el modelo usa SoftDeletes o qué queda colgado. Lo que no se puede
     * deshacer lo confirma siempre la persona.
     *
     * No alcanza con que no estén en la lista de arriba: auto_confirmables_de() los saca igual, así
     * que sumar uno a AUTO_CONFIRMABLES_DIRECTO por distracción no lo vuelve auto-ejecutable. Y sus
     * `case` en ejecutar() tampoco pasan por quizas_auto_confirmar(), que es la tercera guarda. Las
     * dos primeras las fijan los tests 26, 36 y 51 por la constante; la tercera, solo el 36 y el
     * 51, que son los que leen este archivo como texto plano.
     *
     * @var array<int, string>
     */
    const NUNCA_AUTO_CONFIRMABLES = [
        AiMessageAction::TIPO_BAJA,
        AiMessageAction::TIPO_ACTUALIZACION_MASIVA,
        AiMessageAction::TIPO_UNIFICAR_BANCOS,
        AiMessageAction::TIPO_PERMISO_EMPLEADO,
        AiMessageAction::TIPO_BORRADO_PANTALLA,
    ];

    /**
     * Lo que se le dice al modelo cuando la auto-ejecución de una tarjeta lanzó: ver el 🔴 del
     * catch de quizas_auto_confirmar().
     */
    const NOTA_AUTO_EJECUCION_FALLIDA = 'No se pudo ejecutar en el acto por una falla del sistema, '
        . 'así que quedó como tarjeta para que la persona la confirme. Decile exactamente eso: NO '
        . 'digas que ya está cargado ni inventes otro motivo.';

    /**
     * Definiciones con su input_schema para la API de Anthropic.
     *
     * Misión asistente-por-whatsapp: con `$con_whatsapp` se suman confirmar_carga_pendiente y
     * cancelar_carga_pendiente, que son el equivalente de los botones Confirmar y Cancelar de la
     * tarjeta. Sin el flag la lista es EXACTAMENTE la de antes de esa misión, que es lo que ve el
     * chat de la pantalla: ahí la decisión la toma la persona con el dedo y darle a la IA una
     * herramienta para confirmar sola lo que ella misma propuso sería sacarle el control a quien
     * tiene que darlo.
     *
     * @param  bool  $con_whatsapp
     * @return array<int, array<string, mixed>>
     */
    public static function definiciones($con_whatsapp = false): array
    {
        $definiciones = [
            [
                'name'         => 'consultar_opciones_de_carga',
                'description'  => 'Devuelve lo necesario para armar una carga sin suponer nada: hoy con su día de la semana, qué puede cargar la persona (gastos, tareas, pagos de clientes, pagos a proveedores), si la cuenta trabaja en dólares, los métodos de pago (cuáles se pueden usar y el motivo de los que no), las cajas que la persona puede usar, la caja por defecto de cada método y moneda, y las unidades de repetición de las tareas. Usala antes de proponer un gasto, un pago o marcar hecha una tarea con gasto.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'consultar_subcategorias_de_gasto',
                'description'  => 'Busca las subcategorías de gasto del negocio por el nombre de la subcategoría o de su categoría (en la pantalla de Gastos se llaman "Sub categoría"; nunca digas "concepto"). Devuelve el id que piden proponer_gasto y proponer_tarea. Si hay varias que encajan, preguntá cuál.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type'        => 'string',
                            'description' => 'Parte del nombre de la subcategoría o de la categoría. Cadena vacía trae las primeras.',
                        ],
                    ],
                    'required'   => ['busqueda'],
                ],
            ],
            [
                'name'         => 'consultar_proveedores',
                'description'  => 'Busca proveedores del negocio por nombre, con su teléfono y las cuentas corrientes que muestra la pantalla (saldo positivo = el negocio le debe). Devuelve el id que pide proponer_pago con tipo proveedor. Si hay dos parecidos, preguntá cuál.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type'        => 'string',
                            'description' => 'Nombre o parte del nombre del proveedor. Cadena vacía trae los primeros.',
                        ],
                    ],
                    'required'   => ['busqueda'],
                ],
            ],
            [
                'name'         => 'consultar_cuentas_corrientes',
                'description'  => 'Devuelve las cuentas corrientes de UN cliente o proveedor (una por moneda) con su saldo y cómo leerlo ("debe", "a favor", "le debés"). Primero conseguí el id con consultar_clientes o consultar_proveedores.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tipo' => [
                            'type' => 'string',
                            'enum' => ['cliente', 'proveedor'],
                        ],
                        'id'   => [
                            'type'        => 'integer',
                            'description' => 'Id del cliente o del proveedor.',
                        ],
                    ],
                    'required'   => ['tipo', 'id'],
                ],
            ],
            [
                'name'         => 'consultar_tareas',
                'description'  => 'Devuelve las tareas de la agenda: las vencidas sin hacer y las próximas dentro de los días pedidos, con su fecha (AAAA-MM-DD y con el día de la semana), si se repiten y si tienen gasto asociado. Filtrá por el texto de la tarea. Devuelve el tarea_id que piden proponer_cambios_en_tarea y proponer_marcar_tarea_hecha.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type'        => 'string',
                            'description' => 'Parte del texto de la tarea. Cadena vacía trae todas.',
                        ],
                        'dias'     => [
                            'type'        => 'integer',
                            'description' => 'Días hacia adelante para las próximas. Si no lo mandás se usan 30.',
                            'enum'        => [7, 30, 90],
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'proponer_gasto',
                'description'  => 'Arma la tarjeta de un gasto para que la persona la confirme: NO registra nada. Con fecha futura no arma un gasto sino una tarea en la agenda con el gasto asociado (la respuesta lo dice en convertido_desde): en ese caso NO mandes pagos ni preguntes cómo se paga, eso se pregunta el día que la tarea se marca como hecha. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'subcategoria_id' => [
                            'type'        => 'integer',
                            'description' => 'Id de consultar_subcategorias_de_gasto.',
                        ],
                        'monto'           => [
                            'type'        => 'number',
                            'description' => 'Monto total del gasto.',
                        ],
                        'fecha'           => [
                            'type'        => 'string',
                            'description' => 'AAAA-MM-DD. Sin fecha es hoy.',
                        ],
                        'moneda'          => [
                            'type' => 'string',
                            'enum' => ['pesos', 'dolares'],
                        ],
                        'pagos'           => self::esquema_de_pagos(),
                        'observaciones'   => [
                            'type' => 'string',
                        ],
                        'importe_iva'     => [
                            'type'        => 'number',
                            'description' => 'IVA del gasto, en pesos. Solo si la persona lo dice.',
                        ],
                        'reemplaza_a'     => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['subcategoria_id', 'monto'],
                ],
            ],
            [
                'name'         => 'proponer_pago',
                'description'  => 'Arma la tarjeta de un pago de un cliente (cobro) o a un proveedor, sobre su cuenta corriente, para que la persona la confirme: NO registra nada. Con fecha futura arma una tarea para cobrar o pagar ese día. Con CHEQUE se puede: mandá la fila de pago con su objeto `cheque` (número, banco y fecha de vencimiento), sin caja — un cheque no entra a ninguna caja al cargarse. Lo que sigue siendo de la pantalla es ENDOSAR un cheque que ya te dieron, la tarjeta de crédito y los cobros en otra moneda que la de la cuenta. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tipo'        => [
                            'type' => 'string',
                            'enum' => ['cliente', 'proveedor'],
                        ],
                        'id'          => [
                            'type'        => 'integer',
                            'description' => 'Id del cliente (consultar_clientes) o del proveedor (consultar_proveedores).',
                        ],
                        'monto'       => [
                            'type'        => 'number',
                            'description' => 'Monto total del pago, en la moneda de la cuenta.',
                        ],
                        'fecha'       => [
                            'type'        => 'string',
                            'description' => 'AAAA-MM-DD. Sin fecha es hoy.',
                        ],
                        'moneda'      => [
                            'type'        => 'string',
                            'enum'        => ['pesos', 'dolares'],
                            'description' => 'La cuenta corriente sobre la que entra. Solo hace falta si tiene cuenta en pesos y en dólares.',
                        ],
                        'pagos'       => self::esquema_de_pagos(),
                        'descripcion' => [
                            'type' => 'string',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['tipo', 'id', 'monto'],
                ],
            ],
            [
                'name'         => 'proponer_tarea',
                'description'  => 'Arma la tarjeta de una tarea nueva para la agenda, para que la persona la confirme: NO la guarda. Puede repetirse y tener un gasto asociado (subcategoría y monto estimado). Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'detalle'         => [
                            'type'        => 'string',
                            'description' => 'Qué hay que hacer.',
                        ],
                        'fecha'           => [
                            'type'        => 'string',
                            'description' => 'AAAA-MM-DD. Si se repite, es la primera fecha.',
                        ],
                        'repetir'         => self::esquema_de_repeticion(),
                        'subcategoria_id' => [
                            'type'        => 'integer',
                            'description' => 'Id de consultar_subcategorias_de_gasto, si la tarea es pagar algo.',
                        ],
                        'monto_estimado'  => [
                            'type'        => 'number',
                            'description' => 'Monto estimado del gasto. Sin monto queda "a definir al pagarlo".',
                        ],
                        'notas'           => [
                            'type' => 'string',
                        ],
                        'reemplaza_a'     => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['detalle', 'fecha'],
                ],
            ],
            [
                'name'         => 'proponer_cambios_en_tarea',
                'description'  => 'Arma la tarjeta con cambios en una tarea de la agenda (detalle, fecha, repetición, gasto asociado o notas), para que la persona la confirme: NO la modifica. Mandá solo lo que cambia. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tarea_id'              => [
                            'type'        => 'integer',
                            'description' => 'Id de consultar_tareas.',
                        ],
                        'detalle'               => [
                            'type' => 'string',
                        ],
                        'fecha'                 => [
                            'type'        => 'string',
                            'description' => 'AAAA-MM-DD. En una tarea que se repite es la primera fecha de la regla.',
                        ],
                        'repetir'               => self::esquema_de_repeticion(),
                        'quitar_repeticion'     => [
                            'type' => 'boolean',
                        ],
                        'subcategoria_id'       => [
                            'type' => 'integer',
                        ],
                        'quitar_gasto_asociado' => [
                            'type' => 'boolean',
                        ],
                        'monto_estimado'        => [
                            'type' => 'number',
                        ],
                        'notas'                 => [
                            'type' => 'string',
                        ],
                        'reemplaza_a'           => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['tarea_id'],
                ],
            ],
            [
                'name'         => 'proponer_marcar_tarea_hecha',
                'description'  => 'Arma la tarjeta para marcar como hecha una tarea de la agenda, para que la persona la confirme: NO la marca. Si la tarea tiene gasto asociado hay que decir cómo se pagó (o sin_gasto si no se registra el gasto); sin monto se usa el estimado de la tarea. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tarea_id'    => [
                            'type'        => 'integer',
                            'description' => 'Id de consultar_tareas.',
                        ],
                        'fecha'       => [
                            'type'        => 'string',
                            'description' => 'AAAA-MM-DD de la vez que se hizo. Obligatoria si la tarea se repite.',
                        ],
                        'sin_gasto'   => [
                            'type'        => 'boolean',
                            'description' => 'true para marcarla hecha sin registrar su gasto asociado.',
                        ],
                        'gasto'       => [
                            'type'       => 'object',
                            'properties' => [
                                'monto'         => [
                                    'type' => 'number',
                                ],
                                'pagos'         => self::esquema_de_pagos(),
                                'observaciones' => [
                                    'type' => 'string',
                                ],
                                'moneda'        => [
                                    'type' => 'string',
                                    'enum' => ['pesos', 'dolares'],
                                ],
                            ],
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['tarea_id'],
                ],
            ],
            [
                'name'         => 'proponer_combo',
                'description'  => 'Arma la tarjeta de un combo —varios artículos que se venden juntos con un precio propio— para que la persona la confirme: NO lo crea. Conseguí el id de cada artículo con consultar_stock_de_articulos. Las cantidades van en unidades enteras. Al venderse, el combo descuenta el stock de cada artículo que lo compone. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'nombre'      => [
                            'type'        => 'string',
                            'description' => 'Cómo se va a llamar el combo (es con lo que se lo busca en Vender).',
                        ],
                        'articulos'   => [
                            'type'        => 'array',
                            'description' => 'Los artículos que lo componen, una fila por artículo.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'articulo_id' => [
                                        'type'        => 'integer',
                                        'description' => 'Id de consultar_stock_de_articulos.',
                                    ],
                                    'cantidad'    => [
                                        'type'        => 'integer',
                                        'description' => 'Cuántas unidades de ese artículo lleva el combo.',
                                    ],
                                ],
                                'required'   => ['articulo_id', 'cantidad'],
                            ],
                        ],
                        'precio'      => [
                            'type'        => 'number',
                            'description' => 'Precio de venta del combo. Es independiente de la suma de los precios sueltos: ese es el sentido de la promoción.',
                        ],
                        'costo'       => [
                            'type'        => 'number',
                            'description' => 'Costo del combo. Solo si la persona lo dice.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['nombre', 'articulos', 'precio'],
                ],
            ],
            [
                'name'         => 'proponer_oferta',
                'description'  => 'Arma la tarjeta de una oferta para UN cliente sobre UN artículo, para que la persona la confirme: NO la activa. El descuento puede ser plano (porcentaje) o por tramos de cantidad comprada (tramos: cuanto más lleva, más descuento). Conseguí los ids con consultar_clientes y consultar_stock_de_articulos. La oferta se le muestra a ese cliente en la tienda online; desde acá NO se le manda ningún mail ni WhatsApp. Cada artículo tiene un descuento máximo según su costo y su precio de hoy: si te pasás, la herramienta te dice cuál es y NO se recorta solo. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'cliente_id'  => [
                            'type'        => 'integer',
                            'description' => 'Id de consultar_clientes.',
                        ],
                        'articulo_id' => [
                            'type'        => 'integer',
                            'description' => 'Id de consultar_stock_de_articulos.',
                        ],
                        'hasta'       => [
                            'type'        => 'string',
                            // 🔴 El tope sale de la constante y NO de un número escrito acá: es el
                            // mismo defecto que ya se pagó una vez en este módulo (el motor
                            // precargaba fechas que la activación después rechazaba con 422).
                            'description' => 'AAAA-MM-DD hasta la que vale la oferta. No puede ser anterior a hoy ni durar más de '.ClientOfertaAltaHelper::MAX_DIAS_VIGENCIA.' días.',
                        ],
                        'porcentaje'  => [
                            'type'        => 'integer',
                            'description' => 'Descuento plano, en porcentaje entero. Para un descuento por tramos no lo mandes: mandá tramos.',
                        ],
                        'tramos'      => [
                            'type'        => 'array',
                            'description' => 'Descuento por cantidad comprada. Tienen que arrancar en 1 unidad, ser contiguos (sin huecos) y el último NO lleva max: es el que vale de ahí en adelante.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'min'        => [
                                        'type'        => 'integer',
                                        'description' => 'Desde cuántas unidades vale este tramo.',
                                    ],
                                    'max'        => [
                                        'type'        => 'integer',
                                        'description' => 'Hasta cuántas unidades. En el último tramo no se manda.',
                                    ],
                                    'porcentaje' => [
                                        'type'        => 'integer',
                                        'description' => 'Descuento de este tramo, en porcentaje entero.',
                                    ],
                                ],
                                'required'   => ['min', 'porcentaje'],
                            ],
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['cliente_id', 'articulo_id', 'hasta'],
                ],
            ],
            [
                'name'         => 'proponer_foto_sucursal',
                'description'  => 'Asigna como foto de una sucursal la última foto que la persona te mandó y todavía no se usó (la saco sola de esta conversación, no me la pases). Solo la puede usar el dueño. Si el negocio tiene una sola sucursal no hace falta el nombre; si tiene varias, decime cuál. Con la confianza en "resuelto" la asigno en el acto y te aviso que quedó; con "cauteloso" queda una tarjeta para confirmar. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'sucursal'    => [
                            'type'        => 'string',
                            'description' => 'Nombre de la sucursal a la que va la foto. Solo hace falta si el negocio tiene más de una.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => [],
                ],
            ],
            /*
             * Misión asistente-masivas-imagenes-y-remito (19/9/2026). Siete herramientas, en este
             * orden, SIEMPRE al final: el orden del array es el prefijo del caché de prompt.
             */
            [
                'name'         => 'consultar_categorias_sin_imagen',
                'description'  => 'Devuelve cuántas categorías tiene el negocio, cuáles no tienen imagen (con cuántos artículos tiene cada una) y cuántas búsquedas de imágenes quedan disponibles hoy en la cuota diaria de Google. Usala antes de proponer_imagenes_para_categorias, y cuando te pregunten qué categorías están sin imagen. Si la persona ya te pidió que asignes las imágenes, después de consultar llamá a proponer_imagenes_para_categorias en la misma vuelta: no le preguntes si mandás la búsqueda.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'proponer_imagenes_para_categorias',
                'description'  => 'Manda a buscar en internet una imagen con fondo blanco (estilo tienda online) para cada categoría sin imagen, o solo para las categorías que nombres. Solo la puede usar el dueño. La búsqueda corre en segundo plano: la imagen que pasa la verificación se asigna sola; la dudosa vuelve a esta conversación como una tarjeta con la imagen para que la persona decida; la que no se encuentra se informa. Cada categoría usa hasta 2 búsquedas de la cuota diaria de Google. Con la confianza en "resuelto" la mando en el acto y avisá que la mandaste; con "cauteloso" queda una tarjeta para confirmar. Nunca digas que las imágenes ya están: cuando termine, vos mismo vas a escribir en esta conversación con el resultado. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'alcance'     => [
                            'type'        => 'string',
                            'enum'        => ['sin_imagen', 'todas'],
                            'description' => 'sin_imagen (default): solo las categorías que no tienen imagen. todas: también las que ya tienen, si la persona lo pide.',
                        ],
                        'categorias'  => [
                            'type'        => 'array',
                            'description' => 'Solo estas categorías, por su nombre. Si no lo mandás, van todas las del alcance.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'nombre'      => [
                                        'type'        => 'string',
                                        'description' => 'Nombre de la categoría, como lo dijo la persona.',
                                    ],
                                    'buscar_como' => [
                                        'type'        => 'string',
                                        'description' => 'Con qué texto buscar la imagen, si la persona pidió otro nombre ("buscala como artículos de bazar").',
                                    ],
                                ],
                                'required'   => ['nombre'],
                            ],
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'contar_articulos_por_filtro',
                'description'  => 'Cuenta cuántos artículos activos cumplen un filtro (todos los filtros a la vez) y devuelve los primeros nombres como muestra. Usala SIEMPRE antes de proponer_actualizacion_masiva o proponer_imagenes_para_articulos, para decirle a la persona cuántos artículos alcanza. Sin filtros cuenta el catálogo entero. "Los primeros N artículos" son los N más viejos por fecha de alta: orden primeros_creados con limite N (nunca los últimos). Los proveedores, categorías, subcategorías y marcas van por su NOMBRE, no por id: si hay varios que encajan, la respuesta trae "faltan" con las opciones y preguntás cuál. Si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'filtros'         => self::esquema_de_filtros(),
                        'solo_sin_imagen' => [
                            'type'        => 'boolean',
                            'description' => 'true para contar solo los que no tienen imagen. Si no lo mandás, se cuentan todos.',
                        ],
                        'orden'           => self::esquema_de_orden(),
                        'limite'          => self::esquema_de_limite(),
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'proponer_imagenes_para_articulos',
                'description'  => 'Manda a buscar en internet una imagen para cada artículo que cumpla el filtro (los mismos filtros que contar_articulos_por_filtro). Por defecto saltea los que ya tienen imagen (solo_sin_imagen true); mandalo en false solo si la persona dice que también los que ya tienen. La búsqueda corre en segundo plano y le aparece a la persona en el sistema cuando termina; cada artículo usa hasta 2 búsquedas de la cuota diaria de Google y lo que no entra hoy queda sin procesar. "Los primeros N artículos" son los N más viejos por fecha de alta: orden primeros_creados con limite N. Con la confianza en "resuelto" la mando en el acto y avisá que la mandaste; con "cauteloso" queda una tarjeta para confirmar. Nunca digas que las imágenes ya están. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'filtros'         => self::esquema_de_filtros(),
                        'solo_sin_imagen' => [
                            'type'        => 'boolean',
                            'description' => 'true (default) saltea los artículos que ya tienen imagen. false los incluye, y a esos se les AGREGA una imagen más (no se reemplaza la que tienen), igual que el botón del listado.',
                        ],
                        'orden'           => self::esquema_de_orden(),
                        'limite'          => self::esquema_de_limite(),
                        'reemplaza_a'     => self::esquema_de_reemplazo(),
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'proponer_actualizacion_masiva',
                'description'  => 'Arma la tarjeta de una actualización masiva de artículos —un cambio sobre TODOS los artículos que cumplen un filtro— para que la persona la confirme: NO aplica nada. Antes llamá a contar_articulos_por_filtro y explicale a la persona en una línea cuántos artículos alcanza y qué va a cambiar. SIEMPRE queda tarjeta, aunque la confianza esté en "resuelto": nunca se aplica sola. Cuando la persona confirma, corre en segundo plano, recalcula el precio final de cada artículo y queda en el historial de actualizaciones masivas (se puede revertir desde ahí): nunca digas que ya se aplicó. Necesita al menos un filtro (no se actualiza el catálogo entero sin filtrar) y alcanza hasta 3000 artículos. Proveedor, categoría, subcategoría, marca, IVA y unidad de medida van por su NOMBRE. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'filtros'     => self::esquema_de_filtros(),
                        'cambios'     => [
                            'type'        => 'array',
                            'description' => 'Los cambios que se aplican a cada artículo alcanzado, uno por fila.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'campo'     => [
                                        'type'        => 'string',
                                        'enum'        => [
                                            'margen_de_ganancia', 'precio_manual', 'costo', 'stock', 'precio_promocional', 'margen_de_ganancia_blanco',
                                            'proveedor', 'categoria', 'sub_categoria', 'marca',
                                            'iva', 'unidad_de_medida',
                                            'en_tienda', 'destacado', 'en_oferta', 'precio_pausado', 'es_insumo', 'aplica_margen_del_proveedor', 'aplicar_iva', 'costo_en_dolares', 'disponible_tienda_nube',
                                        ],
                                    ],
                                    'operacion' => [
                                        'type'        => 'string',
                                        'enum'        => ['setear', 'subir_porcentaje', 'bajar_porcentaje', 'asignar', 'activar', 'desactivar'],
                                        'description' => 'Numéricos (margen_de_ganancia, precio_manual, costo, stock, precio_promocional, margen_de_ganancia_blanco): setear un valor, o subir_porcentaje / bajar_porcentaje con el porcentaje en valor. Proveedor, categoría, subcategoría, marca, IVA y unidad de medida: asignar, con el NOMBRE en valor (el IVA por su porcentaje: "21"). Los de sí/no (en_tienda, destacado, en_oferta, precio_pausado, es_insumo, aplica_margen_del_proveedor, aplicar_iva, costo_en_dolares, disponible_tienda_nube): activar o desactivar, sin valor.',
                                    ],
                                    'valor'     => [
                                        'type'        => ['number', 'string'],
                                        'description' => 'El valor a setear, el porcentaje a subir o bajar, o el nombre a asignar. No va con activar ni desactivar.',
                                    ],
                                    'redondear' => [
                                        'type'        => 'boolean',
                                        'description' => 'Solo con subir_porcentaje o bajar_porcentaje: true redondea el resultado a entero.',
                                    ],
                                ],
                                'required'   => ['campo', 'operacion'],
                            ],
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['filtros', 'cambios'],
                ],
            ],
            [
                'name'         => 'consultar_disenos_de_pdf',
                'description'  => 'Devuelve los diseños de PDF del negocio (con los que se imprimen remitos, facturas, presupuestos y el catálogo de artículos): de cada uno su nombre, tipo, si es el predeterminado, la hoja, el ancho disponible, la suma de anchos, y las columnas visibles en orden con su ancho en mm; y aparte las columnas que se pueden agregar, con su ancho por defecto. Usala antes de proponer_cambio_en_diseno_pdf, y cuando te pregunten qué columnas tiene un remito o un PDF.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tipo' => [
                            'type'        => 'string',
                            'enum'        => ['venta', 'articulos'],
                            'description' => 'venta: los diseños de comprobantes (remitos, facturas, presupuestos). articulos: los del catálogo de artículos. Sin tipo trae todos.',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'proponer_cambio_en_diseno_pdf',
                'description'  => 'Cambia las columnas de un diseño de PDF: agrega columnas en una posición, saca columnas o cambia anchos. Solo la puede usar el dueño. Conseguí el diseno_id y los nombres de columna con consultar_disenos_de_pdf. Si la persona no dijo DÓNDE va la columna nueva (al final, al principio, antes o después de cuál), preguntale antes de llamar. Cuando los anchos no entran, la herramienta acomoda sola (achica la columna que ajusta texto, como el nombre del artículo) y te dice qué achicó: contáselo a la persona. Con la confianza en "resuelto" aplico el cambio en el acto y avisá que quedó; con "cauteloso" queda una tarjeta para confirmar. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'diseno_id'   => [
                            'type'        => 'integer',
                            'description' => 'Id del diseño, de consultar_disenos_de_pdf.',
                        ],
                        'agregar'     => [
                            'type'        => 'array',
                            'description' => 'Columnas a agregar, con su posición.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'columna'               => [
                                        'type'        => 'string',
                                        'description' => 'Nombre de la columna, de las disponibles que devolvió consultar_disenos_de_pdf.',
                                    ],
                                    'posicion'              => [
                                        'type' => 'string',
                                        'enum' => ['al_final', 'al_principio', 'despues_de', 'antes_de'],
                                    ],
                                    'columna_de_referencia' => [
                                        'type'        => 'string',
                                        'description' => 'Obligatoria con despues_de y antes_de: la columna al lado de la cual va.',
                                    ],
                                    'ancho_mm'              => [
                                        'type'        => 'number',
                                        'description' => 'Ancho en milímetros. Si no lo mandás se usa el ancho por defecto de la columna.',
                                    ],
                                ],
                                'required'   => ['columna', 'posicion'],
                            ],
                        ],
                        'quitar'      => [
                            'type'        => 'array',
                            'description' => 'Nombres de las columnas a sacar del diseño.',
                            'items'       => [
                                'type' => 'string',
                            ],
                        ],
                        'anchos'      => [
                            'type'        => 'array',
                            'description' => 'Columnas a las que se les fija un ancho nuevo.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'columna'  => [
                                        'type' => 'string',
                                    ],
                                    'ancho_mm' => [
                                        'type' => 'number',
                                    ],
                                ],
                                'required'   => ['columna', 'ancho_mm'],
                            ],
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['diseno_id'],
                ],
            ],
            /*
             * Misión cheques-endoso-y-bancos (21/9/2026): el catálogo de bancos de cheques arranca
             * vacío y los cheques viejos tienen el banco como texto libre. Estas dos lo unifican.
             */
            [
                'name'         => 'consultar_bancos_de_cheques',
                'description'  => 'Devuelve los bancos de cheques que el negocio ya tiene en su catálogo (con cuántos cheques tiene cada uno) y los textos distintos que los cheques tienen escritos como banco y todavía no están unificados a ningún banco del catálogo, con cuántos cheques tiene cada texto. Usala antes de proponer_unificar_bancos_de_cheques, y cuando te pregunten qué bancos hay o cuántos cheques faltan unificar.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'proponer_unificar_bancos_de_cheques',
                'description'  => 'Unifica los bancos de los cheques: por cada banco, un nombre prolijo y la lista de textos (tal cual los devolvió consultar_bancos_de_cheques) que son ese banco. Al confirmar, crea en el catálogo los bancos que no existan (si ya hay uno con ese nombre lo reusa) y les asigna ese banco a todos los cheques de esos textos; el texto escrito en cada cheque no se borra. Agrupá vos los textos que claramente son el mismo banco ("Bco Nacion", "banco nación" y "BNA" son Banco Nación) y usá el nombre oficial y prolijo; si un texto es ambiguo, preguntá antes. Un texto va a un solo banco. SIEMPRE queda tarjeta para confirmar, nunca se aplica sola, esté como esté tu confianza. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'grupos'      => [
                            'type'        => 'array',
                            'description' => 'Un elemento por banco.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'banco'  => [
                                        'type'        => 'string',
                                        'description' => 'Nombre prolijo del banco, como va a quedar en el catálogo (ej. "Banco Nación").',
                                    ],
                                    'textos' => [
                                        'type'        => 'array',
                                        'description' => 'Los textos de consultar_bancos_de_cheques que son este banco, tal cual vinieron.',
                                        'items'       => [
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                                'required'   => ['banco', 'textos'],
                            ],
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['grupos'],
                ],
            ],
            /*
             * Misión asistente-omnisciente (21/9/2026). Cinco herramientas, en este orden, SIEMPRE
             * al final: el ABM genérico (catálogo, alta, edición, baja) y la venta. El catálogo de
             * entidades y campos va bajo demanda en que_puedo_cargar y NO en el enum del esquema:
             * el bloque de definiciones viaja entero en cada vuelta y es el prefijo del caché.
             */
            [
                'name'         => 'que_puedo_cargar',
                'description'  => 'Devuelve qué entidades del sistema podés crear, editar o borrar con proponer_alta, proponer_edicion y proponer_baja (las de ABM, Clientes, Proveedores y Artículos: categorías, subcategorías, marcas, listas de precio, descuentos, sucursales, vendedores, clientes, proveedores, artículos y más), y con una entidad, sus campos: nombre, tipo, si es obligatorio, en qué operaciones se acepta y cómo se ubica un registro. Llamala ANTES de proponer, y usá sus claves tal cual: un campo que no está acá no existe, no lo inventes. NO es para gastos, pagos, tareas, combos, ofertas, compras con factura ni ventas nuevas: esas tienen su propia herramienta.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entidad' => [
                            'type'        => 'string',
                            'description' => 'La entidad (como la devuelve la lista: provider, client, article, category...). Sin entidad trae la lista de todas.',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'proponer_alta',
                'description'  => 'Arma la tarjeta para CREAR un registro de una entidad de que_puedo_cargar (un proveedor, un cliente, una categoría, un artículo, una sucursal...) para que la persona la confirme: NO crea nada. Al confirmar se crea por la misma pantalla que usa la persona. Las claves de `datos` son los campos de que_puedo_cargar; una relación (categoría, proveedor, marca, localidad...) va por su NOMBRE, y si hay varias que encajan la respuesta trae "faltan" con las opciones. Un campo que la persona no dijo no lo inventes: si es obligatorio, preguntalo; si no, no lo mandes. NUNCA se crea sola, ni con la confianza en "resuelto". Para gastos, pagos, tareas, combos, ofertas, compras con factura y ventas está su propia herramienta; esta es para todo lo demás que se carga desde ABM, Clientes, Proveedores y Artículos. Un ARTÍCULO se da de alta con su foto y su descripción en ESTA MISMA tarjeta (con_foto_de_la_conversacion, imagen_id, descripcion), nunca en dos. 🔴 Para un artículo: con el costo y el margen el precio de venta sale solo, así que NO pidas el precio de venta si ya tenés costo y margen; "con esta foto" o "con la foto que te mandé" es con_foto_de_la_conversacion; buscar_producto_por_codigo_de_barras va sólo si la persona pide buscarlo o no te dio el nombre del producto. Al corregir una tarjeta con reemplaza_a, no vuelvas a mandar imagen_id ni descripcion si no cambian: se heredan de la tarjeta anterior. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entidad'     => [
                            'type'        => 'string',
                            'description' => 'La entidad, como la devuelve que_puedo_cargar.',
                        ],
                        'datos'       => [
                            'type'                 => 'object',
                            'description'          => 'Los campos del registro nuevo: las claves son los campos de que_puedo_cargar para esa entidad y los valores lo que dijo la persona (texto, número, si/no, AAAA-MM-DD, o el nombre de la relación).',
                            'additionalProperties' => true,
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                        /*
                         * Misión asistente-fotos-barras-y-compras (24/9/2026): los tres extras del alta
                         * de un artículo, AL FINAL de las propiedades por la regla del prefijo del
                         * caché. No van al controller: los ejecuta AltaDeArticuloConFotoIaHelper
                         * después del alta.
                         */
                        'con_foto_de_la_conversacion' => [
                            'type'        => 'boolean',
                            'description' => 'Solo para entidad article: true para que el artículo nazca con la foto que la persona te mandó en esta conversación (la saco sola, no me la pases). Con reemplaza_a NO lo mandes salvo que la persona pida cambiar la foto: la tarjeta nueva hereda la foto de la anterior.',
                        ],
                        'imagen_id'   => [
                            'type'        => 'integer',
                            'description' => 'Solo para entidad article: el imagen_id de una foto que te devolvió otra herramienta (la búsqueda por código de barras). Si lo mandás, no hace falta con_foto_de_la_conversacion. Con reemplaza_a NO lo mandes: se hereda.',
                        ],
                        'descripcion' => [
                            'type'        => 'string',
                            'description' => 'Solo para entidad article: la descripción del producto para la ficha y la tienda online, en español. Con reemplaza_a NO la mandes salvo que la persona pida cambiarla: se hereda entera de la tarjeta anterior (si la mandás, reemplaza a la heredada).',
                        ],
                    ],
                    'required'   => ['entidad', 'datos'],
                ],
            ],
            [
                'name'         => 'proponer_edicion',
                'description'  => 'Arma la tarjeta para CAMBIAR campos de un registro existente de una entidad de que_puedo_cargar, para que la persona la confirme: NO cambia nada. Primero se ubica el registro por su nombre (o por el id si otra herramienta lo devolvió; las ventas y los gastos, por su número): si hay varios que encajan, la respuesta trae "faltan" con las opciones y preguntás cuál. Mandá en `cambios` SOLO lo que cambia; la tarjeta muestra cada campo como "antes → después". Si nada cambia, la respuesta lo dice. NUNCA se aplica sola, ni con la confianza en "resuelto". Para tareas está proponer_cambios_en_tarea; para el resto de lo que se edita desde ABM, Clientes, Proveedores, Artículos y Gastos, esta. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entidad'     => [
                            'type'        => 'string',
                            'description' => 'La entidad, como la devuelve que_puedo_cargar.',
                        ],
                        'registro'    => [
                            'type'        => ['string', 'integer'],
                            'description' => 'El nombre del registro tal como lo dijo la persona, o su id si otra herramienta lo devolvió. Para ventas y gastos, su número.',
                        ],
                        'cambios'     => [
                            'type'                 => 'object',
                            'description'          => 'Solo los campos que cambian: las claves son los campos de que_puedo_cargar y los valores, los nuevos.',
                            'additionalProperties' => true,
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['entidad', 'registro', 'cambios'],
                ],
            ],
            [
                'name'         => 'proponer_baja',
                'description'  => 'Arma la tarjeta para BORRAR un registro de una entidad de que_puedo_cargar (o anular una venta, o borrar un gasto o una tarea), para que la persona la confirme: NO borra nada. Se ubica igual que en proponer_edicion. La tarjeta dice qué se borra y qué pasa con lo que dependía de eso; contáselo a la persona. NUNCA se borra sola, ni con la confianza en "resuelto". Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entidad'     => [
                            'type'        => 'string',
                            'description' => 'La entidad, como la devuelve que_puedo_cargar.',
                        ],
                        'registro'    => [
                            'type'        => ['string', 'integer'],
                            'description' => 'El nombre del registro, o su id si otra herramienta lo devolvió. Para ventas y gastos, su número.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['entidad', 'registro'],
                ],
            ],
            [
                'name'         => 'proponer_venta',
                'description'  => 'Arma la tarjeta de una VENTA por el mismo camino que la pantalla de Vender, para que la persona la confirme: NO vende nada. Cada artículo va por su nombre o código (o por su id si otra herramienta lo devolvió) con su cantidad; el precio sale de la lista de precios del cliente (o de la que pidan), salvo que la persona dicte otro. Sin cliente es una venta al contado; con cobro contado hay que decir el método de pago (y la caja si hace falta); a cuenta corriente necesita un cliente con cuenta. La tarjeta muestra los renglones, el cliente, el cobro, el descuento y el total, y avisa si descuenta stock. NUNCA se vende sola, ni con la confianza en "resuelto". Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'items'                => [
                            'type'        => 'array',
                            'description' => 'Los renglones de la venta, uno por artículo.',
                            'minItems'    => 1,
                            'maxItems'    => 50,
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'articulo'        => [
                                        'type'        => 'string',
                                        'description' => 'Nombre o código del artículo, como lo dijo la persona. Si mandás articulo_id no hace falta.',
                                    ],
                                    'articulo_id'     => [
                                        'type'        => 'integer',
                                        'description' => 'Id del artículo, solo si otra herramienta lo devolvió.',
                                    ],
                                    'cantidad'        => [
                                        'type'        => 'number',
                                        'description' => 'Cuántas unidades. Mayor a 0.',
                                    ],
                                    'precio_unitario' => [
                                        'type'        => 'number',
                                        'description' => 'Solo si la persona dictó un precio distinto al de la lista. Mayor a 0.',
                                    ],
                                ],
                                'required'   => ['cantidad'],
                            ],
                        ],
                        'cliente'              => [
                            'type'        => ['string', 'integer'],
                            'description' => 'Nombre del cliente (o su id si otra herramienta lo devolvió). Sin cliente es una venta al contado.',
                        ],
                        'cobro'                => [
                            'type'        => 'string',
                            'enum'        => ['contado', 'cuenta_corriente'],
                            'description' => 'Cómo se cobra. Sin cliente solo puede ser contado.',
                        ],
                        'metodo_de_pago'       => [
                            'type'        => 'string',
                            'description' => 'Nombre del método de pago, como en la pantalla de Vender (Efectivo, Transferencia...). Obligatorio con cobro contado.',
                        ],
                        'caja'                 => [
                            'type'        => 'string',
                            'description' => 'Nombre de la caja a la que entra la plata. Si no lo mandás se usa la caja por defecto del método.',
                        ],
                        'lista_de_precios'     => [
                            'type'        => 'string',
                            'description' => 'Nombre de la lista de precios, solo si la persona pidió una distinta a la del cliente.',
                        ],
                        'tipo_de_venta'        => [
                            'type'        => 'string',
                            'description' => 'Nombre del tipo de venta, solo si el negocio tiene varios cargados (con varios y sin decirlo, la respuesta trae "faltan" con los nombres para que preguntes cuál).',
                        ],
                        'descuento_porcentaje' => [
                            'type'        => 'number',
                            'description' => 'Descuento sobre el total, en porcentaje (0 a 100).',
                        ],
                        'observaciones'        => [
                            'type' => 'string',
                        ],
                        'sucursal'             => [
                            'type'        => 'string',
                            'description' => 'Nombre de la sucursal. Solo hace falta si el negocio tiene más de una.',
                        ],
                        'fecha_entrega'        => [
                            'type'        => 'string',
                            'description' => 'AAAA-MM-DD, solo si la persona dijo una fecha de entrega.',
                        ],
                        'reemplaza_a'          => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['items'],
                ],
            ],
            /*
             * Misión asistente-ventas-y-fotos (21/9/2026), al FINAL de lo que había: el orden del
             * array es el prefijo del caché de prompt de Anthropic y lo nuevo nunca se intercala.
             */
            [
                'name'         => 'proponer_foto_articulo',
                'description'  => 'Arma la tarjeta para ponerle a un ARTÍCULO QUE YA EXISTE la última foto que la persona te mandó y todavía no se usó (la saco sola de esta conversación, no me la pases). Para un artículo NUEVO no va esta: proponer_alta de article con con_foto_de_la_conversacion lo crea con la foto en una sola tarjeta. El artículo va por su nombre o su código, como lo dijo la persona (o por su id si otra herramienta te lo devolvió); si el nombre encaja con varios, la respuesta trae "faltan" con los candidatos para que preguntes cuál. La foto se suma a las imágenes del artículo y se publica en la tienda online del negocio. 🔴 NUNCA se asigna sola, ni con la confianza en "resuelto": siempre queda una tarjeta para que la persona confirme, porque una foto en el artículo equivocado se publica. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'articulo'    => [
                            'type'        => 'string',
                            'description' => 'Nombre o código del artículo, como lo dijo la persona. Si mandás articulo_id no hace falta.',
                        ],
                        'articulo_id' => [
                            'type'        => 'integer',
                            'description' => 'Id del artículo, solo si otra herramienta lo devolvió.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => [],
                ],
            ],
            /*
             * Misión asistente-capacidades-y-hilos (22/9/2026), al FINAL de lo que había: el orden
             * del array es el prefijo del caché de prompt de Anthropic y lo nuevo nunca se
             * intercala. Son las cuatro capacidades que el agente contestó que no podía hacer el
             * 22/9 en demo3 (mensajes #24, #42, #56 y #44) y que la pantalla sí hace.
             */
            [
                'name'         => 'proponer_movimiento_de_stock',
                'description'  => 'Arma la tarjeta para MOVER stock de un artículo de un depósito (o sucursal) a otro, por el mismo camino que el modal "Movimiento de depósitos" del Listado. La cantidad va SIEMPRE en positivo y el sistema la resta del origen y la suma al destino: no mandes números negativos ni la llames dos veces. El artículo va por su nombre o código (o por su id si otra herramienta lo devolvió) y los depósitos por su nombre, como los devuelve consultar_stock_por_deposito. 🔴 Sirve para MOVER, no para cargar: si el artículo todavía no tiene stock en el depósito de origen, la respuesta te lo dice y ahí va proponer_stock_en_deposito. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'articulo'      => [
                            'type'        => 'string',
                            'description' => 'Nombre o código del artículo, como lo dijo la persona. Si mandás articulo_id no hace falta.',
                        ],
                        'articulo_id'   => [
                            'type'        => 'integer',
                            'description' => 'Id del artículo, solo si otra herramienta lo devolvió.',
                        ],
                        'cantidad'      => [
                            'type'        => 'number',
                            'description' => 'Cuántas unidades se mueven. Siempre mayor a 0.',
                        ],
                        'desde'         => [
                            'type'        => 'string',
                            'description' => 'Nombre del depósito o sucursal de donde SALE el stock.',
                        ],
                        'hacia'         => [
                            'type'        => 'string',
                            'description' => 'Nombre del depósito o sucursal al que ENTRA el stock.',
                        ],
                        'observaciones' => [
                            'type' => 'string',
                        ],
                        'reemplaza_a'   => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['cantidad', 'desde', 'hacia'],
                ],
            ],
            [
                'name'         => 'proponer_stock_en_deposito',
                'description'  => 'Arma la tarjeta para dejar el stock de un artículo en UN depósito puntual, por el mismo camino que la edición de stock por sucursal del Listado. 🔴 El `modo` es obligatorio de entender bien: "sumar" le agrega esa cantidad a lo que ya hay, "restar" se la saca, y "fijar" lo deja exactamente en ese número. "Sumale 10 a Florida" es modo sumar con cantidad 10, NO fijar 10. Es la única forma de ABRIRLE un depósito a un artículo que todavía no tiene stock ahí. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'articulo'    => [
                            'type'        => 'string',
                            'description' => 'Nombre o código del artículo, como lo dijo la persona. Si mandás articulo_id no hace falta.',
                        ],
                        'articulo_id' => [
                            'type'        => 'integer',
                            'description' => 'Id del artículo, solo si otra herramienta lo devolvió.',
                        ],
                        'deposito'    => [
                            'type'        => 'string',
                            'description' => 'Nombre del depósito o sucursal, como los devuelve consultar_stock_por_deposito.',
                        ],
                        'cantidad'    => [
                            'type'        => 'number',
                            'description' => 'Cuántas unidades. Con modo sumar o restar es cuánto se mueve; con modo fijar es en cuánto queda.',
                        ],
                        'modo'        => [
                            'type'        => 'string',
                            'enum'        => ['sumar', 'restar', 'fijar'],
                            'description' => 'Qué hacer con la cantidad. Si no lo mandás se toma "fijar".',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['deposito', 'cantidad', 'modo'],
                ],
            ],
            [
                'name'         => 'proponer_presupuesto',
                'description'  => 'Arma la tarjeta de un PRESUPUESTO por el mismo camino que el "guardar como presupuesto" de la pantalla de Vender: NO vende nada y NO descuenta stock. Cada artículo va por su nombre o código (o por su id si otra herramienta lo devolvió) con su cantidad; el precio sale de la lista de precios del cliente (o de la que pidan), salvo que la persona dicte otro. 🔴 El cliente es obligatorio: un presupuesto es para alguien, y de ahí sale la lista de precios. Un presupuesto no toca stock, ni caja, ni cuenta corriente: eso pasa recién cuando la persona lo confirma desde la pantalla de Presupuestos, y eso NO lo hacés vos. Después de crearlo podés pasar el link del PDF con consultar_link_de_pdf. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'items'                => [
                            'type'        => 'array',
                            'description' => 'Los renglones del presupuesto, uno por artículo.',
                            'minItems'    => 1,
                            'maxItems'    => 50,
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'articulo'        => [
                                        'type'        => 'string',
                                        'description' => 'Nombre o código del artículo, como lo dijo la persona. Si mandás articulo_id no hace falta.',
                                    ],
                                    'articulo_id'     => [
                                        'type'        => 'integer',
                                        'description' => 'Id del artículo, solo si otra herramienta lo devolvió.',
                                    ],
                                    'cantidad'        => [
                                        'type'        => 'number',
                                        'description' => 'Cuántas unidades. Mayor a 0.',
                                    ],
                                    'precio_unitario' => [
                                        'type'        => 'number',
                                        'description' => 'Solo si la persona dictó un precio distinto al de la lista. Mayor a 0.',
                                    ],
                                ],
                                'required'   => ['cantidad'],
                            ],
                        ],
                        'cliente'              => [
                            'type'        => ['string', 'integer'],
                            'description' => 'Nombre del cliente (o su id si otra herramienta lo devolvió). Es obligatorio.',
                        ],
                        'lista_de_precios'     => [
                            'type'        => 'string',
                            'description' => 'Nombre de la lista de precios, solo si la persona pidió una distinta a la del cliente.',
                        ],
                        'descuento_porcentaje' => [
                            'type'        => 'number',
                            'description' => 'Descuento sobre el total, en porcentaje (0 a 100).',
                        ],
                        'observaciones'        => [
                            'type' => 'string',
                        ],
                        'sucursal'             => [
                            'type'        => 'string',
                            'description' => 'Nombre de la sucursal. Solo hace falta si el negocio tiene más de una.',
                        ],
                        'reemplaza_a'          => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['items', 'cliente'],
                ],
            ],
            [
                'name'         => 'proponer_permiso_de_empleado',
                'description'  => 'Arma la tarjeta para DARLE o SACARLE un permiso a un empleado, por el mismo camino que la pantalla de Empleados. El empleado va por su nombre y el permiso por su nombre como lo muestra la pantalla ("Listar ventas") o por su código ("sale.index"). 🔴 SIEMPRE deja tarjeta para confirmar, aunque el dueño tenga el modo directo prendido y aunque te pidan que lo hagas sin preguntar: la pantalla reemplaza la lista entera de permisos y un cambio mal hecho deja a alguien sin poder trabajar, y nadie se entera hasta que llega. La tarjeta muestra CON QUÉ PERMISOS QUEDA el empleado: cuando la respuesta vuelva, contá eso. Solo la puede usar el dueño o un administrador. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'empleado'    => [
                            'type'        => 'string',
                            'description' => 'Nombre del empleado, como lo dijo la persona.',
                        ],
                        'permiso'     => [
                            'type'        => 'string',
                            'description' => 'Nombre del permiso como lo muestra la pantalla de Empleados, o su código. "Ver las ventas" es "Listar ventas" (sale.index).',
                        ],
                        'accion'      => [
                            'type'        => 'string',
                            'enum'        => ['dar', 'sacar'],
                            'description' => 'Si se le da el permiso o se le saca.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['empleado', 'permiso', 'accion'],
                ],
            ],
            /*
             * Misión asistente-mcp (22/9/2026), al FINAL de lo que había: las ACCIONES DE PANTALLA.
             * "Literalmente todo lo que se hace desde la interfaz" es la misma ruta y el mismo
             * controller que llama la pantalla, con las mismas reglas de confirmación que el resto
             * de las cargas. El catálogo (qué rutas entran y cuáles no, con motivo) vive en
             * CatalogoDeAccionesDePantallaIaHelper y se consulta bajo demanda con la primera de las
             * cuatro, NO en el enum del esquema: el bloque de definiciones viaja entero en cada
             * vuelta y es el prefijo del caché.
             */
            [
                'name'         => 'que_acciones_de_pantalla_hay',
                'description'  => 'Devuelve el catálogo de ACCIONES DE PANTALLA: todo lo que se hace desde las pantallas del sistema y no tiene herramienta propia (abrir o cerrar una caja, confirmar o anular un presupuesto, editar una venta, facturar, marcar un pedido, cambiar una preferencia...). Cada acción trae el método (GET lee; POST y PUT hacen; DELETE borra), la `ruta` con sus {parámetros}, el módulo, las `claves` que el controller lee del cuerpo (best-effort: puede leer más o menos de lo que dice) y la extensión que exige, si alguna. Devuelve de a 40 por página: filtrá con `buscar` (una o más palabras, sin importar acentos; las rutas están en inglés: caja, budget, sale, article, client, provider, order...) y con `metodo`. 🔴 La `ruta` que devuelve, con sus {param} TAL CUAL, es la que hay que pasarle a consultar_por_pantalla, proponer_accion_de_pantalla y proponer_borrado_por_pantalla. Nunca la uses para lo que ya tiene herramienta propia (gastos, pagos, tareas, ventas nuevas, presupuestos nuevos, el ABM genérico...): esas rutas no están en el catálogo.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'buscar' => [
                            'type'        => 'string',
                            'description' => 'Una o más palabras que tienen que estar en la ruta, el módulo o la acción ("caja", "cerrar caja", "budget confirmar"). Sin buscar trae todas, paginadas.',
                        ],
                        'metodo' => [
                            'type'        => 'string',
                            'enum'        => ['GET', 'POST', 'PUT', 'DELETE'],
                            'description' => 'Solo las acciones de este método. Sin metodo trae todos.',
                        ],
                        'pagina' => [
                            'type'        => 'integer',
                            'description' => 'Qué página de 40 traer. Si no la mandás, la primera.',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'         => 'consultar_por_pantalla',
                'description'  => 'Lee lo que una pantalla del sistema ve, llamando a la misma ruta GET que llama la pantalla (una de que_acciones_de_pantalla_hay). Se ejecuta en el acto y no deja tarjeta: es de LECTURA. Pasá la `ruta` tal cual (con sus {param}), los valores de los {param} en `parametros` y los filtros que la pantalla manda en la query (fechas, per_page, búsqueda) en `consulta`. Devuelve el JSON de la pantalla; si es muy largo viene recortado (`recortado: true`): pedí menos con `consulta` (per_page, un rango de fechas) o usá una ruta más específica. Para los datos del negocio que ya tienen herramienta propia (stock, clientes, ventas, cuentas corrientes, tareas) usá esa herramienta, que viene resumida y es más barata.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'ruta'       => [
                            'type'        => 'string',
                            'description' => 'La ruta GET como la devuelve que_acciones_de_pantalla_hay, con sus {param} tal cual (ej. "api/caja/{id}/liquidaciones-pendientes").',
                        ],
                        'parametros' => [
                            'type'                 => 'object',
                            'description'          => 'Los valores de los {param} de la ruta, por nombre: {"id": 12}.',
                            'additionalProperties' => true,
                        ],
                        'consulta'   => [
                            'type'                 => 'object',
                            'description'          => 'Los filtros de la pantalla, como viajan en la query string: {"per_page": 50, "from_date": "2026-09-01"}.',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required'   => ['ruta'],
                ],
            ],
            [
                'name'         => 'proponer_accion_de_pantalla',
                'description'  => 'Arma la tarjeta para HACER algo por la misma ruta POST o PUT que usa una pantalla del sistema (una de que_acciones_de_pantalla_hay), para que la persona la confirme: NO hace nada por sí sola. Al confirmar corre el mismo controller que la pantalla, autenticado como la persona; lo que la pantalla rechazaría (validación, permisos, un registro que no existe) vuelve como `error`. Pasá la `ruta` tal cual (con sus {param}), los valores de los {param} en `parametros`, lo que la pantalla manda en el formulario en `cuerpo` (las `claves` del catálogo son una guía) y una `descripcion` de UNA línea que diga qué hace esta acción: es el título que la persona lee en la tarjeta, así que tiene que ser fiel y concreta ("Abrir la caja Efectivo", "Confirmar el presupuesto N° 12"). Con la confianza en "directo" se ejecuta en el acto. Para BORRAR está proponer_borrado_por_pantalla, y para leer, consultar_por_pantalla. Nunca la uses para lo que ya tiene herramienta propia. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'metodo'      => [
                            'type' => 'string',
                            'enum' => ['POST', 'PUT'],
                        ],
                        'ruta'        => [
                            'type'        => 'string',
                            'description' => 'La ruta como la devuelve que_acciones_de_pantalla_hay, con sus {param} tal cual (ej. "api/cerrar-caja/{caja_id}").',
                        ],
                        'parametros'  => [
                            'type'                 => 'object',
                            'description'          => 'Los valores de los {param} de la ruta, por nombre: {"caja_id": 12}.',
                            'additionalProperties' => true,
                        ],
                        'cuerpo'      => [
                            'type'                 => 'object',
                            'description'          => 'Lo que la pantalla manda en el cuerpo del request, con las claves que lee el controller. Vacío si la ruta no lleva cuerpo.',
                            'additionalProperties' => true,
                        ],
                        'descripcion' => [
                            'type'        => 'string',
                            'description' => 'Qué hace esta acción, en una línea y en español, tal como lo va a leer la persona en la tarjeta.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['metodo', 'ruta', 'descripcion'],
                ],
            ],
            [
                'name'         => 'proponer_borrado_por_pantalla',
                'description'  => 'Arma la tarjeta para BORRAR un registro por la misma ruta DELETE que usa una pantalla del sistema (una de que_acciones_de_pantalla_hay), para que la persona la confirme: NO borra nada. 🔴 SIEMPRE deja tarjeta, en los tres modos de confianza y aunque te pidan que lo hagas sin preguntar: si el sistema no manda ese registro a la papelera, no se deshace. Pasá la `ruta` tal cual (con sus {param}), los valores de los {param} en `parametros` y una `descripcion` de UNA línea que diga QUÉ se borra (es el título de la tarjeta). Para lo que está en que_puedo_cargar usá proponer_baja, que además dice qué queda colgado. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'ruta'        => [
                            'type'        => 'string',
                            'description' => 'La ruta DELETE como la devuelve que_acciones_de_pantalla_hay, con sus {param} tal cual (ej. "api/caja/{caja}").',
                        ],
                        'parametros'  => [
                            'type'                 => 'object',
                            'description'          => 'Los valores de los {param} de la ruta, por nombre: {"caja": 12}.',
                            'additionalProperties' => true,
                        ],
                        'descripcion' => [
                            'type'        => 'string',
                            'description' => 'Qué se borra, en una línea y en español, tal como lo va a leer la persona en la tarjeta.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                    ],
                    'required'   => ['ruta', 'descripcion'],
                ],
            ],
        ];

        if ($con_whatsapp) {

            foreach (self::definiciones_de_whatsapp() as $definicion) {

                $definiciones[] = $definicion;
            }
        }

        return $definiciones;
    }

    /**
     * Las herramientas que solo existen en el canal de WhatsApp (misión asistente-por-whatsapp).
     *
     * - confirmar_carga_pendiente / cancelar_carga_pendiente: el equivalente de los botones
     *   Confirmar y Cancelar, porque en WhatsApp no hay tarjeta que tocar.
     * - proponer_compra_con_factura: va acá y no en la lista común porque SU MATERIA PRIMA ES
     *   EXCLUSIVA DE ESTE CANAL. Las filas de `ai_message_imagenes` las escribe únicamente
     *   AdminSync\AsistenteController: el panel del chat no tiene forma de adjuntar una foto, así
     *   que declarada en el sistema la herramienta solo podría contestar "no tengo ninguna foto".
     *   Y para la foto que el dueño ya tiene en la mano estando frente a la pantalla, el camino es
     *   el escaneo de facturas, que además se la muestra.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function definiciones_de_whatsapp(): array
    {
        return [
            [
                'name'         => 'proponer_compra_con_factura',
                'description'  => 'Da de alta la compra de un proveedor y le carga la foto de la factura que la persona te mandó, para que el sistema la escanee. 🔴 El proveedor es EL QUE DIJO LA PERSONA, nunca el emisor que leés en la factura: si la factura dice otra razón social, no lo cuestiones ni lo preguntes. Recién si la persona no nombró ningún proveedor, usá el emisor de la factura. Llamala en la misma vuelta, sin preguntar nada antes: no transcribas montos, fechas ni renglones de la factura (los lee el escaneo) y no preguntes la sucursal (si no la dijo, uso la suya). Las fotos las saco solas de las que te mandó y todavía no se usaron: no me las pases. Si el proveedor no existe, lo doy de alta yo en la misma carga (no uses proponer_alta antes); si no lo nombró la persona, queda una tarjeta para que lo confirme. Con la confianza en "resuelto" se hace en el acto y la respuesta te trae el resultado; en "cauteloso" queda una sola confirmación que cubre todo. Los artículos no se cargan acá: el escaneo corre en segundo plano y cuando termina el aviso le aparece a la persona EN EL SISTEMA (no por WhatsApp); los revisa desde Compras. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'proveedor'   => [
                            'type'        => 'string',
                            'description' => 'El proveedor que nombró la persona, tal como lo dijo. Sólo si no nombró ninguno, el emisor que leés en la factura (su nombre o razón social).',
                        ],
                        'sucursal'    => [
                            'type'        => 'string',
                            'description' => 'Sucursal a la que entra la mercadería, sólo si la persona la nombró. Nunca la preguntes.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
                        /*
                         * Correcciones del 24/9/2026, al FINAL de las propiedades por la regla del
                         * prefijo del caché: el CUIT reconoce al proveedor antes que el nombre, salvo
                         * que la persona haya nombrado a otro (ProveedorDeLaFacturaIaHelper::resolver).
                         */
                        'cuit'        => [
                            'type'        => 'string',
                            'description' => 'Opcional: el CUIT del proveedor, si la persona lo dijo o si no nombró ningún proveedor y lo leés en la factura. Sólo los dígitos.',
                        ],
                    ],
                    'required'   => ['proveedor'],
                ],
            ],
            [
                'name'         => 'confirmar_carga_pendiente',
                'description'  => 'Registra de verdad una carga que propusiste en un mensaje ANTERIOR y que la persona ya te dijo que sí. Es el equivalente del botón Confirmar de la pantalla. 🔴 No la podés llamar en el mismo mensaje en el que proponés: tiene que haber una respuesta de la persona en el medio, y si lo intentás te la rechaza. Si la respuesta trae "error", contá ese motivo tal cual y no digas que quedó cargado.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tarjeta_id' => [
                            'type'        => 'integer',
                            'description' => 'El tarjeta_id que te devolvió la herramienta proponer_ correspondiente.',
                        ],
                    ],
                    'required'   => ['tarjeta_id'],
                ],
            ],
            [
                'name'         => 'cancelar_carga_pendiente',
                'description'  => 'Da de baja una carga que propusiste en un mensaje ANTERIOR y que la persona te dijo que no. Es el equivalente del botón Cancelar de la pantalla. No registra nada ni deshace nada ya registrado.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'tarjeta_id' => [
                            'type'        => 'integer',
                            'description' => 'El tarjeta_id que te devolvió la herramienta proponer_ correspondiente.',
                        ],
                    ],
                    'required'   => ['tarjeta_id'],
                ],
            ],
        ];
    }

    /**
     * Nombres de las herramientas de carga.
     *
     * Sin argumento devuelve las que ve el chat de la pantalla; con `true`, también las del canal
     * de WhatsApp. maneja() usa la lista completa: una herramienta que se despacha tiene que estar
     * ahí aunque no se declare en todos los canales.
     *
     * @param  bool  $con_whatsapp
     * @return array<int, string>
     */
    public static function nombres($con_whatsapp = false): array
    {
        return array_column(self::definiciones($con_whatsapp), 'name');
    }

    /**
     * true si la herramienta es de este archivo.
     *
     * @param  string  $tool_name
     * @return bool
     */
    public static function maneja($tool_name): bool
    {
        return in_array((string) $tool_name, self::nombres(true), true);
    }

    /**
     * Ejecuta una herramienta de carga y arma su contenido para el tool_result.
     *
     * Las propuestas necesitan el mensaje del assistant que se está generando (la tarjeta cuelga de
     * él): sin mensaje vuelven con is_error, porque no hay dónde crear la tarjeta.
     *
     * @param  string  $tool_name
     * @param  array  $input
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @return array  ['content' => string, 'is_error' => bool]
     */
    public static function ejecutar($tool_name, array $input, AiConversation $conversation, $assistant_message): array
    {
        $tool_name = (string) $tool_name;

        if (strpos($tool_name, 'proponer_') === 0 && !($assistant_message instanceof AiMessage)) {

            return [
                'content'  => 'Error al ejecutar '.$tool_name.': una propuesta necesita el mensaje que se está generando.',
                'is_error' => true,
            ];
        }

        /*
         * Misión asistente-por-whatsapp: las herramientas del canal solo existen en WhatsApp.
         * maneja() las reconoce siempre (si no, execute_tool_calls no las despacharía nunca), así
         * que el corte por canal va acá: en el sistema la confirmación es el botón —una IA que
         * pueda confirmar sola lo que propuso le saca la decisión a la persona— y las fotos no
         * existen. Ver el docblock de definiciones_de_whatsapp().
         *
         * Misión asistente-mcp (22/9/2026): el canal MCP tampoco tiene botones, así que confirmar_ y
         * cancelar_carga_pendiente también se despachan para él — la pregunta pasa a ser
         * confirma_por_texto() (WhatsApp o MCP). proponer_compra_con_factura sigue en esta lista y
         * pasa la guarda para MCP, pero el servidor MCP no la declara: sin fotos de WhatsApp solo
         * podría contestar "no tengo ninguna foto".
         */
        if (in_array($tool_name, array_column(self::definiciones_de_whatsapp(), 'name'), true)) {

            if (!($assistant_message instanceof AiMessage) || !$assistant_message->confirma_por_texto()) {

                return [
                    'content'  => 'Tool desconocida: '.$tool_name,
                    'is_error' => true,
                ];
            }
        }

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        switch ($tool_name) {

            case 'consultar_opciones_de_carga':
                return self::resultado(OpcionesDeCargaIaHelper::opciones_de_carga($contexto));

            case 'consultar_subcategorias_de_gasto':
                return self::resultado(OpcionesDeCargaIaHelper::subcategorias_de_gasto($contexto->owner_id, EntradaDeCargaIa::texto($input, 'busqueda')));

            case 'consultar_proveedores':
                return self::resultado(ConsultasDeCargaIaHelper::proveedores($contexto, EntradaDeCargaIa::texto($input, 'busqueda')));

            case 'consultar_cuentas_corrientes':
                return self::resultado(ConsultasDeCargaIaHelper::cuentas_corrientes($contexto, EntradaDeCargaIa::texto($input, 'tipo'), (int) EntradaDeCargaIa::valor($input, 'id')));

            case 'consultar_tareas':
                $dias = EntradaDeCargaIa::valor($input, 'dias');
                return self::resultado(ConsultasDeCargaIaHelper::tareas($contexto, EntradaDeCargaIa::texto($input, 'busqueda'), is_null($dias) ? 30 : (int) $dias));

            /*
             * Misión asistente-capacidades-y-hilos (22/9/2026): las ocho que mueven plata, agenda o
             * catálogo pasan por la puerta única. Quién se ejecuta solo lo decide el MODO del dueño
             * dentro de quizas_auto_confirmar(): con "cauteloso" y "resuelto" queda la tarjeta, como
             * hasta hoy; solo con "directo" se ejecutan en el acto.
             */
            case 'proponer_gasto':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaGastoIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            case 'proponer_pago':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaPagoIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            case 'proponer_tarea':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaTareaIaHelper::proponer_tarea($contexto, $assistant_message, $input)
                ));

            case 'proponer_cambios_en_tarea':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaTareaIaHelper::proponer_cambios($contexto, $assistant_message, $input)
                ));

            case 'proponer_marcar_tarea_hecha':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaTareaIaHelper::proponer_marcar_hecha($contexto, $assistant_message, $input)
                ));

            case 'proponer_combo':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaComboIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            case 'proponer_oferta':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaOfertaIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            case 'proponer_compra_con_factura':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaCompraConFacturaIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            case 'proponer_foto_sucursal':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaFotoSucursalIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            /*
             * Misión asistente-masivas-imagenes-y-remito. Las de categorías y de diseño de PDF las
             * implementa el constructor B (contrato §6); acá solo se wirean por nombre y firma.
             */
            case 'consultar_categorias_sin_imagen':
                return self::resultado(PropuestaImagenesCategoriasIaHelper::consultar($contexto));

            case 'proponer_imagenes_para_categorias':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaImagenesCategoriasIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            case 'contar_articulos_por_filtro':
                return self::resultado(FiltroDeArticulosIaHelper::contar_para_la_ia($contexto->owner_id, $input));

            case 'proponer_imagenes_para_articulos':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaImagenesArticulosIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            // 🔴 SIN quizas_auto_confirmar(), a propósito: la masiva SIEMPRE deja tarjeta (ver AUTO_CONFIRMABLES).
            case 'proponer_actualizacion_masiva':
                return self::resultado(PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant_message, $input));

            case 'consultar_disenos_de_pdf':
                return self::resultado(PropuestaDisenoPdfIaHelper::consultar($contexto, EntradaDeCargaIa::valor($input, 'tipo')));

            case 'proponer_cambio_en_diseno_pdf':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            // Misión cheques-endoso-y-bancos (21/9/2026).
            case 'consultar_bancos_de_cheques':
                return self::resultado(PropuestaBancosChequesIaHelper::consultar($contexto));

            // 🔴 SIN quizas_auto_confirmar(), a propósito: unificar bancos SIEMPRE deja tarjeta (ver AUTO_CONFIRMABLES).
            case 'proponer_unificar_bancos_de_cheques':
                return self::resultado(PropuestaBancosChequesIaHelper::proponer($contexto, $assistant_message, $input));


            /*
             * Misión asistente-omnisciente (21/9/2026), corrida a tres modos por la misión
             * asistente-capacidades-y-hilos (22/9/2026): el alta, la edición y la venta pasan por la
             * puerta única y solo se ejecutan solas con el dueño en "directo". 🔴 La BAJA no: va SIN
             * la auto-confirmación del agente, en ningún modo, porque 31 de las 40 entidades no
             * tienen SoftDeletes y un borrado no se deshace (ver NUNCA_AUTO_CONFIRMABLES).
             */
            case 'que_puedo_cargar':
                return self::resultado(CatalogoDeEscrituraIaHelper::que_puedo_cargar(EntradaDeCargaIa::valor($input, 'entidad')));

            case 'proponer_alta':
                /*
                 * Los extras del alta de un artículo (foto y descripción) se separan de `datos` acá:
                 * no son campos de la pantalla de artículos y validar_campos() los rechazaría. Misión
                 * asistente-fotos-barras-y-compras (24/9/2026), ver AltaDeArticuloConFotoIaHelper.
                 */
                list($datos_del_alta, $extras_del_alta) = AltaDeArticuloConFotoIaHelper::separar(
                    $input,
                    self::objeto_como_array(EntradaDeCargaIa::valor($input, 'datos'))
                );

                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaGenericaIaHelper::proponer_alta(
                        $contexto,
                        $assistant_message,
                        EntradaDeCargaIa::valor($input, 'entidad'),
                        $datos_del_alta,
                        EntradaDeCargaIa::valor($input, 'reemplaza_a'),
                        $extras_del_alta
                    )
                ));

            case 'proponer_edicion':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaGenericaIaHelper::proponer_edicion(
                        $contexto,
                        $assistant_message,
                        EntradaDeCargaIa::valor($input, 'entidad'),
                        EntradaDeCargaIa::valor($input, 'registro'),
                        self::objeto_como_array(EntradaDeCargaIa::valor($input, 'cambios')),
                        EntradaDeCargaIa::valor($input, 'reemplaza_a')
                    )
                ));

            case 'proponer_baja':
                return self::resultado(PropuestaGenericaIaHelper::proponer_baja(
                    $contexto,
                    $assistant_message,
                    EntradaDeCargaIa::valor($input, 'entidad'),
                    EntradaDeCargaIa::valor($input, 'registro'),
                    EntradaDeCargaIa::valor($input, 'reemplaza_a')
                ));

            case 'proponer_venta':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaVentaIaHelper::proponer($contexto, $assistant_message, $input, EntradaDeCargaIa::valor($input, 'reemplaza_a'))
                ));

            /*
             * Misión asistente-ventas-y-fotos (21/9/2026), revisada el 22/9 por la misión
             * asistente-capacidades-y-hilos: la foto de un artículo SIGUE sin auto-confirmarse en
             * "resuelto" (se publica en la tienda, dispara Tienda Nube y Mercado Libre y se replica
             * en los comercios vinculados: su tipo no está en AUTO_CONFIRMABLES), pero en "directo"
             * sí, porque ahí el dueño ya pidió a conciencia que las cargas se hagan solas. Las dos
             * cosas las decide el modo adentro de la puerta única.
             */
            case 'proponer_foto_articulo':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            /*
             * Misión asistente-capacidades-y-hilos (22/9/2026). Las dos de stock pasan por la
             * puerta única: con el dueño en "directo" se hacen en el acto, y en los otros dos modos
             * dejan tarjeta. Mover stock entre dos depósitos del mismo negocio no cambia el stock
             * total ni toca plata, y dejarlo en un número se deshace con otra carga igual de barata.
             */
            case 'proponer_movimiento_de_stock':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaStockIaHelper::proponer_movimiento($contexto, $assistant_message, $input)
                ));

            case 'proponer_stock_en_deposito':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaStockIaHelper::proponer_stock_en_deposito($contexto, $assistant_message, $input)
                ));

            /*
             * El presupuesto también pasa por la puerta: no toca stock, ni caja, ni cuenta
             * corriente (eso pasa recién al confirmarlo desde la pantalla de Presupuestos), así que
             * uno de más se borra. Ver PropuestaPresupuestoIaHelper.
             */
            case 'proponer_presupuesto':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaPresupuestoIaHelper::proponer($contexto, $assistant_message, $input)
                ));

            // 🔴 SIN quizas_auto_confirmar(), a propósito: los permisos de un empleado SIEMPRE dejan
            // tarjeta, en todos los modos (ver NUNCA_AUTO_CONFIRMABLES).
            case 'proponer_permiso_de_empleado':
                return self::resultado(PropuestaPermisoEmpleadoIaHelper::proponer($contexto, $assistant_message, $input));

            /*
             * Misión asistente-mcp (22/9/2026): las acciones de pantalla. Las dos primeras son de
             * lectura y contestan en el acto; la de POST/PUT pasa por la puerta única, como el
             * resto de lo que hace la pantalla; la de DELETE no (ver más abajo).
             */
            case 'que_acciones_de_pantalla_hay':
                $pagina = EntradaDeCargaIa::valor($input, 'pagina');
                return self::resultado(CatalogoDeAccionesDePantallaIaHelper::lista(
                    EntradaDeCargaIa::texto($input, 'buscar'),
                    EntradaDeCargaIa::texto($input, 'metodo'),
                    is_null($pagina) ? 1 : (int) $pagina
                ));

            case 'consultar_por_pantalla':
                return self::resultado(PropuestaAccionDePantallaIaHelper::consultar(
                    $contexto,
                    EntradaDeCargaIa::texto($input, 'ruta'),
                    self::objeto_como_array(EntradaDeCargaIa::valor($input, 'parametros')),
                    self::objeto_como_array(EntradaDeCargaIa::valor($input, 'consulta'))
                ));

            case 'proponer_accion_de_pantalla':
                return self::resultado(self::quizas_auto_confirmar(
                    $contexto,
                    $conversation,
                    $assistant_message,
                    PropuestaAccionDePantallaIaHelper::proponer(
                        $contexto,
                        $assistant_message,
                        EntradaDeCargaIa::texto($input, 'metodo'),
                        EntradaDeCargaIa::texto($input, 'ruta'),
                        self::objeto_como_array(EntradaDeCargaIa::valor($input, 'parametros')),
                        self::objeto_como_array(EntradaDeCargaIa::valor($input, 'cuerpo')),
                        EntradaDeCargaIa::texto($input, 'descripcion'),
                        EntradaDeCargaIa::valor($input, 'reemplaza_a')
                    )
                ));

            // 🔴 SIN quizas_auto_confirmar(), a propósito: un borrado por pantalla SIEMPRE deja
            // tarjeta, en todos los modos (ver NUNCA_AUTO_CONFIRMABLES): no se conoce ni la tabla.
            case 'proponer_borrado_por_pantalla':
                return self::resultado(PropuestaAccionDePantallaIaHelper::proponer_borrado(
                    $contexto,
                    $assistant_message,
                    EntradaDeCargaIa::texto($input, 'ruta'),
                    self::objeto_como_array(EntradaDeCargaIa::valor($input, 'parametros')),
                    EntradaDeCargaIa::texto($input, 'descripcion'),
                    EntradaDeCargaIa::valor($input, 'reemplaza_a')
                ));

            case 'confirmar_carga_pendiente':
                return self::resultado(ConfirmacionPorTextoIaHelper::confirmar($conversation, $assistant_message, EntradaDeCargaIa::valor($input, 'tarjeta_id')));

            case 'cancelar_carga_pendiente':
                return self::resultado(ConfirmacionPorTextoIaHelper::cancelar($conversation, $assistant_message, EntradaDeCargaIa::valor($input, 'tarjeta_id')));
        }

        return [
            'content'  => 'Tool desconocida: '.$tool_name,
            'is_error' => true,
        ];
    }

    /**
     * `datos` y `cambios` de las genéricas llegan como objeto JSON (array asociativo); cualquier
     * otra cosa —un string, una lista, nada— se trata como "sin campos" y la propuesta contesta
     * con lo que falta.
     *
     * @param  mixed  $valor
     * @return array
     */
    protected static function objeto_como_array($valor): array
    {
        if ($valor instanceof \stdClass) {

            $valor = (array) $valor;
        }

        if (!is_array($valor)) {

            return [];
        }

        // Una lista ([["campo", "valor"]]) no es un objeto: se descarta entera.
        foreach (array_keys($valor) as $clave) {

            if (!is_string($clave)) {

                return [];
            }
        }

        return $valor;
    }

    /**
     * Contenido JSON del tool_result. El fallback `?: '[]'` es el mismo que el resto de las tools:
     * con UTF-8 inválido en la base json_encode devuelve false y un content false rompería el request
     * siguiente del loop.
     *
     * @param  mixed  $datos
     * @return array
     */
    protected static function resultado($datos): array
    {
        return [
            'content'  => json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_error' => false,
        ];
    }

    /**
     * Los tipos que se auto-ejecutan con ESE modo de confianza, ya filtrados por los que no se
     * auto-ejecutan nunca (misión asistente-capacidades-y-hilos, 22/9/2026).
     *
     * Un modo que no se reconoce —"cauteloso", una columna vacía o un valor viejo— devuelve la lista
     * vacía: sin modo legible no se ejecuta nada solo, que es exactamente lo que hacía el `!==
     * 'resuelto'` de antes de esta misión.
     *
     * @param  string  $confianza
     * @return array<int, string>
     */
    public static function auto_confirmables_de($confianza): array
    {
        $confianza = (string) $confianza;

        if ($confianza === ConfianzaDelAgenteIaHelper::DIRECTO) {

            $lista = self::AUTO_CONFIRMABLES_DIRECTO;

        } elseif ($confianza === ConfianzaDelAgenteIaHelper::RESUELTO) {

            $lista = self::AUTO_CONFIRMABLES;

        } else {

            return [];
        }

        // 🔴 La segunda guarda de NUNCA_AUTO_CONFIRMABLES: ver el docblock de esa constante.
        return array_values(array_diff($lista, self::NUNCA_AUTO_CONFIRMABLES));
    }

    /**
     * true si la herramienta es una CARGA: una que propone una tarjeta o la que confirma una
     * pendiente (misión asistente-capacidades-y-hilos, 22/9/2026).
     *
     * La usa el loop de AsistenteIaService para saber que el turno "tocó" una carga y tiene que
     * seguir con el modelo Profundo. Va por el prefijo `proponer_` a propósito: una capacidad nueva
     * que respete el nombre de la casa queda cubierta sin tocar este método. Las consultas
     * (`consultar_*`, `contar_*`, `que_puedo_cargar`) y la cancelación NO son cargas: una consulta
     * de solo lectura nunca tiene por qué encarecerse.
     *
     * @param  string  $tool_name
     * @return bool
     */
    public static function es_de_carga($tool_name): bool
    {
        $tool_name = (string) $tool_name;

        if (strpos($tool_name, 'proponer_') === 0) {

            return true;
        }

        return $tool_name === 'confirmar_carga_pendiente';
    }

    /**
     * Si la propuesta recién creada es de un tipo que el MODO DE CONFIANZA del dueño auto-ejecuta,
     * la confirma en el acto y devuelve el resultado ejecutado; si no, devuelve la propuesta tal
     * cual (queda como una tarjeta más, para confirmar a mano). Misión
     * foto-sucursal-y-asistente-configurable, corrida a tres modos el 22/9/2026.
     *
     * 🔴 Es la única puerta a la auto-ejecución. Una respuesta negativa de proponer() (faltan datos,
     * sin permiso, sin foto) no tiene tarjeta_id y sale sin tocar nada. Y el catch es la red: si la
     * confirmación en el acto lanzara, la tarjeta ya quedó propuesta y la persona la puede confirmar
     * a mano — la carga nunca se pierde por auto-ejecutar.
     *
     * 🔴 Y EL CATCH LE AVISA AL MODELO QUE NO SE EJECUTÓ. Antes devolvía la propuesta muda, y en
     * "resuelto" eso era casi inofensivo. En "directo" no: el prompt le dijo al modelo que sus
     * cargas se hacen solas, así que una propuesta muda es justo lo que lo lleva a escribir "listo,
     * ya lo cargué" sobre algo que quedó sin hacer — el defecto más grave del 22/9 en demo3 (cuatro
     * anuncios de "quedó registrado" contra cero ventas, cero compras y cero proveedores). La nota
     * dice qué pasó de verdad y le prohíbe inventar el motivo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  mixed  $respuesta  Lo que devolvió la herramienta proponer_.
     * @return mixed
     */
    protected static function quizas_auto_confirmar(ContextoDeCargaIa $contexto, AiConversation $conversation, $assistant_message, $respuesta)
    {
        if (!is_array($respuesta) || empty($respuesta['ok']) || empty($respuesta['tarjeta_id'])) {

            return $respuesta;
        }

        $tipo = isset($respuesta['tipo']) ? (string) $respuesta['tipo'] : '';

        $owner = $contexto->owner;

        if (!in_array($tipo, self::auto_confirmables_de(ConfianzaDelAgenteIaHelper::guardada($owner)), true)) {

            return $respuesta;
        }

        /*
         * Una propuesta de un tipo auto-confirmable puede pedir igual la confirmación de la persona
         * cuando ESTA tanda no es inocua: hoy, imágenes de categorías que van a REEMPLAZAR imágenes ya
         * cargadas (PropuestaImagenesCategoriasIaHelper marca `requiere_confirmacion`). El tipo dice
         * "en general se puede"; la propuesta dice "esta vez no". Se respeta la propuesta.
         */
        if (!empty($respuesta['requiere_confirmacion'])) {

            return $respuesta;
        }

        /*
         * 🔴 NI SI LA MISMA CARGA YA SE CONFIRMÓ HACE UN INSTANTE (misión
         * asistente-capacidades-y-hilos, 22/9/2026). `confirmada_parecida` es la defensa contra el
         * doble registro de AccionesIaHelper: hay una tarjeta CONFIRMADA con la misma clave,
         * resuelta después del mensaje que disparó esta respuesta. Con la confirmación a mano eso
         * era un aviso —la persona miraba y decidía—, pero en "directo" auto-ejecutar encima es,
         * lisa y llanamente, cargar el mismo gasto o la misma venta dos veces. Se deja la tarjeta
         * con su aviso y decide la persona, que es lo que esa guarda vino a garantizar.
         */
        if (!empty($respuesta['confirmada_parecida'])) {

            return $respuesta;
        }

        try {

            return ConfirmacionPorTextoIaHelper::confirmar_del_agente($conversation, (int) $respuesta['tarjeta_id']);

        } catch (\Throwable $e) {

            Log::warning('HerramientasDeCarga: no se pudo auto-confirmar una tarjeta', [
                'ai_conversation_id' => (int) $conversation->id,
                'tarjeta_id'         => (int) $respuesta['tarjeta_id'],
                'confianza'          => ConfianzaDelAgenteIaHelper::guardada($owner),
                'error'              => $e->getMessage(),
            ]);

            $respuesta['nota'] = self::NOTA_AUTO_EJECUCION_FALLIDA;

            return $respuesta;
        }
    }

    /**
     * Esquema de las filas de pago, compartido por las tres propuestas que mueven plata.
     *
     * @return array
     */
    protected static function esquema_de_pagos(): array
    {
        return [
            'type'        => 'array',
            'description' => 'Cómo se pagó. Una fila por método de pago.',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'metodo_de_pago_id' => [
                        'type'        => 'integer',
                        'description' => 'Id de consultar_opciones_de_carga (solo los que se pueden usar).',
                    ],
                    'monto'             => [
                        'type'        => 'number',
                        'description' => 'Solo si hay más de una fila: los montos tienen que sumar el total.',
                    ],
                    'caja_id'           => [
                        'type'        => 'integer',
                        'description' => 'Caja a la que va. Si no la mandás se usa la caja por defecto; si no hay, la herramienta te pide preguntarla. 🔴 Con un cheque NO se manda: un cheque no entra a ninguna caja al cargarse.',
                    ],
                    /*
                     * Misión asistente-capacidades-y-hilos (22/9/2026): el cheque. Va adentro de la
                     * fila y no como una herramienta aparte porque ES una fila del mismo array de
                     * métodos de pago del mismo endpoint — el camino de ejecución ya lo soportaba
                     * (`ChequeHelper::crear_cheque()`), lo que faltaba era poder describirlo.
                     */
                    'cheque'            => [
                        'type'        => 'object',
                        'description' => 'Solo cuando el método de pago es un cheque. Sin esto, el cheque se guardaría sin número, sin banco y sin fecha, y nadie podría reconocerlo después en la pantalla de Cheques.',
                        'properties'  => [
                            'numero'        => [
                                'type'        => 'string',
                                'description' => 'Número del cheque, tal como está impreso.',
                            ],
                            'banco'         => [
                                'type'        => 'string',
                                'description' => 'Banco del cheque, como lo dijo la persona ("Banco Nación").',
                            ],
                            'fecha_pago'    => [
                                'type'        => 'string',
                                'description' => 'AAAA-MM-DD. Es el VENCIMIENTO: la fecha a partir de la cual se puede cobrar.',
                            ],
                            'fecha_emision' => [
                                'type'        => 'string',
                                'description' => 'AAAA-MM-DD. Si no la dicen, es hoy.',
                            ],
                            'es_echeq'      => [
                                'type'        => 'boolean',
                                'description' => 'true si es un e-cheq (electrónico).',
                            ],
                            'notes'         => [
                                'type' => 'string',
                            ],
                        ],
                        'required'    => ['numero', 'banco', 'fecha_pago'],
                    ],
                ],
                'required'   => ['metodo_de_pago_id'],
            ],
        ];
    }

    /**
     * Esquema de la repetición de una tarea.
     *
     * @return array
     */
    protected static function esquema_de_repeticion(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'cada'   => [
                    'type'        => 'integer',
                    'description' => 'Cada cuántas unidades se repite (1 = todas).',
                ],
                'unidad' => [
                    'type' => 'string',
                    'enum' => ['dia', 'semana', 'mes', 'año'],
                ],
                'hasta'  => [
                    'type'        => 'string',
                    'description' => 'AAAA-MM-DD de la última vez, si tiene fin.',
                ],
            ],
            'required'   => ['cada', 'unidad'],
        ];
    }

    /**
     * Esquema de `filtros`, compartido por contar_articulos_por_filtro, proponer_imagenes_para_articulos
     * y proponer_actualizacion_masiva (misión asistente-masivas-imagenes-y-remito). Los campos son
     * los de FiltroDeArticulosIaHelper::CAMPOS: el enum se arma desde ahí para que un campo nuevo no
     * quede declarado en un lado y no en el otro. La lista es una constante, así que el orden del
     * enum es estable entre llamadas (caché de prompt).
     *
     * @return array
     */
    protected static function esquema_de_filtros(): array
    {
        return [
            'type'        => 'array',
            'description' => 'Los filtros que tienen que cumplir los artículos, TODOS a la vez. Por campo: proveedor, categoria, sub_categoria, marca → igual (valor = el NOMBRE), en_blanco, no_en_blanco. nombre, codigo_de_barras, codigo_de_proveedor, sku, plu, descripcion, titulo_seo → contiene, igual, en_blanco, no_en_blanco. costo, precio_final, precio_manual, margen_de_ganancia, stock, stock_minimo, precio_promocional, margen_de_ganancia_blanco, precio_final_blanco, costo_real, medida, unidades_individuales → igual, mayor, menor, en_blanco, no_en_blanco. precio_actualizado, stock_actualizado, fecha_de_alta, fecha_de_modificacion → desde, hasta (los dos inclusivos), igual, mayor, menor, en_blanco, no_en_blanco, con el valor como AAAA-MM-DD ("el precio no se actualizó desde junio" es precio_actualizado hasta 2026-06-01). en_tienda, destacado, en_oferta, precio_pausado, es_insumo, aplica_margen_del_proveedor, aplicar_iva, costo_en_dolares, disponible_tienda_nube, en_mercado_libre, omitir_en_lista_pdf → igual con valor si o no. imagen → en_blanco (sin imagen) o no_en_blanco (con imagen). Lista vacía = sin filtro.',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'campo'    => [
                        'type' => 'string',
                        'enum' => array_keys(FiltroDeArticulosIaHelper::CAMPOS),
                    ],
                    'operador' => [
                        'type' => 'string',
                        'enum' => ['igual', 'contiene', 'mayor', 'menor', 'desde', 'hasta', 'en_blanco', 'no_en_blanco'],
                    ],
                    'valor'    => [
                        'type'        => ['string', 'number'],
                        'description' => 'El nombre, el texto, el número, la fecha AAAA-MM-DD o si/no. No va con en_blanco ni no_en_blanco.',
                    ],
                ],
                'required'   => ['campo', 'operador'],
            ],
        ];
    }

    /**
     * Esquema de `orden` (misión asistente-masivas-imagenes-y-remito).
     *
     * @return array
     */
    protected static function esquema_de_orden(): array
    {
        return [
            'type'        => 'string',
            'enum'        => [FiltroDeArticulosIaHelper::ORDEN_PRIMEROS_CREADOS, FiltroDeArticulosIaHelper::ORDEN_ULTIMOS_CREADOS],
            'description' => 'primeros_creados (default): del más viejo al más nuevo por fecha de alta ("los primeros N del inventario"). ultimos_creados: del más nuevo al más viejo.',
        ];
    }

    /**
     * Esquema de `limite` (misión asistente-masivas-imagenes-y-remito).
     *
     * @return array
     */
    protected static function esquema_de_limite(): array
    {
        return [
            'type'        => 'integer',
            'description' => 'Cuántos artículos como máximo, en el orden pedido ("los primeros 100" = orden primeros_creados, limite 100). Sin límite van todos los que cumplen el filtro.',
        ];
    }

    /**
     * Esquema de `reemplaza_a`, común a todas las propuestas.
     *
     * @return array
     */
    protected static function esquema_de_reemplazo(): array
    {
        return [
            'type'        => 'integer',
            'description' => 'tarjeta_id de una tarjeta anterior que esta corrige, si la corrección cambia la subcategoría, la cuenta o la tarea.',
        ];
    }
}
