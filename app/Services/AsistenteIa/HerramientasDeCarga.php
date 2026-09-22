<?php

namespace App\Services\AsistenteIa;

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
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
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
 * sumó proponer_foto_articulo después de esas. Van al final porque el orden es parte
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
     * ABM > Impresión). Todo lo que mueve plata (gastos, pagos, compras, combos, ofertas) NUNCA se
     * auto-confirma, ni siquiera en "resuelto" — siempre lo confirma la persona.
     *
     * 🔴 LA ACTUALIZACIÓN MASIVA NO ESTÁ NI VA A ESTAR ACÁ. Reescribe precios, márgenes, stock o
     * proveedores de cientos de artículos de un saque; aunque se pueda revertir, la persona tiene
     * que ver cuántos alcanza y confirmar (decisión de Lucas, misión asistente-masivas-imagenes-y-
     * remito). Su `case` en ejecutar() tampoco pasa por quizas_auto_confirmar(), y el test 26 fija
     * las dos cosas. Lo mismo para unificar los bancos de los cheques (misión
     * cheques-endoso-y-bancos): toca N cheques de un saque y decide a qué banco va cada texto.
     * Antes de sumar un tipo acá, tiene que cumplir las dos condiciones de arriba.
     *
     * 🔴 TAMPOCO ENTRAN LAS GENÉRICAS (alta, edicion, baja) NI LA VENTA (misión asistente-omnisciente,
     * 21/9/2026): crean, cambian o borran datos del negocio por el controller de la pantalla, y una
     * baja no se deshace. Sus `case` en ejecutar() tampoco pasan por quizas_auto_confirmar(), y el
     * test 36 fija las dos cosas.
     *
     * 🔴 Y TAMPOCO ENTRA LA FOTO DE UN ARTÍCULO (`TIPO_FOTO_ARTICULO`, misión asistente-ventas-y-fotos,
     * 21/9/2026), aunque la de SUCURSAL sí esté acá arriba. No es una inconsistencia, es la
     * diferencia que importa: la sucursal se elige entre unas pocas y por su nombre completo —no
     * puede errarle al destino—, y su foto no sale publicada en ningún lado. La del artículo se
     * resuelve INFIRIENDO de un nombre que el dueño puede decir inexacto, y si le erra, la foto
     * equivocada se PUBLICA: dispara Tienda Nube y Mercado Libre, y además se replica en el catálogo
     * de los comercios vinculados. Decisión explícita de Lucas del 21/9/2026: confirma siempre la
     * persona, esté en "resuelto" o no. Su `case` en ejecutar() tampoco pasa por
     * quizas_auto_confirmar().
     *
     * @var array<int, string>
     */
    const AUTO_CONFIRMABLES = [
        AiMessageAction::TIPO_FOTO_SUCURSAL,
        AiMessageAction::TIPO_IMAGENES_CATEGORIAS,
        AiMessageAction::TIPO_IMAGENES_ARTICULOS,
        AiMessageAction::TIPO_DISENO_PDF,
    ];

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
                'description'  => 'Arma la tarjeta de un pago de un cliente (cobro) o a un proveedor, sobre su cuenta corriente, para que la persona la confirme: NO registra nada. Con fecha futura arma una tarea para cobrar o pagar ese día. Los cheques, la tarjeta de crédito y los cobros en otra moneda que la de la cuenta se cargan desde la pantalla. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
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
                'description'  => 'Arma la tarjeta para CREAR un registro de una entidad de que_puedo_cargar (un proveedor, un cliente, una categoría, un artículo, una sucursal...) para que la persona la confirme: NO crea nada. Al confirmar se crea por la misma pantalla que usa la persona. Las claves de `datos` son los campos de que_puedo_cargar; una relación (categoría, proveedor, marca, localidad...) va por su NOMBRE, y si hay varias que encajan la respuesta trae "faltan" con las opciones. Un campo que la persona no dijo no lo inventes: si es obligatorio, preguntalo; si no, no lo mandes. NUNCA se crea sola, ni con la confianza en "resuelto". Para gastos, pagos, tareas, combos, ofertas, compras con factura y ventas está su propia herramienta; esta es para todo lo demás que se carga desde ABM, Clientes, Proveedores y Artículos. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
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
                'description'  => 'Arma la tarjeta para ponerle a un ARTÍCULO la última foto que la persona te mandó y todavía no se usó (la saco sola de esta conversación, no me la pases). El artículo va por su nombre o su código, como lo dijo la persona (o por su id si otra herramienta te lo devolvió); si el nombre encaja con varios, la respuesta trae "faltan" con los candidatos para que preguntes cuál. La foto se suma a las imágenes del artículo y se publica en la tienda online del negocio. 🔴 NUNCA se asigna sola, ni con la confianza en "resuelto": siempre queda una tarjeta para que la persona confirme, porque una foto en el artículo equivocado se publica. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
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
                'description'  => 'Arma la tarjeta para dar de alta la compra de un proveedor y cargarle la foto de la factura que la persona te mandó, para que el sistema la lea: NO registra nada. Las fotos las saco solas de las que te mandó en esta conversación y todavía no se usaron, así que no me las pases. Si ya hay una compra de ese proveedor vacía y reciente se usa esa, y si no se crea una nueva: la respuesta te dice cuál de las dos. Los artículos no se cargan acá, los revisa la persona desde Compras cuando la lectura termina. Si la respuesta trae "faltan", preguntá eso; si trae "error", contá ese motivo tal cual.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'proveedor'   => [
                            'type'        => 'string',
                            'description' => 'Nombre del proveedor de la factura, tal como lo dijo la persona. Nunca lo inventes: si no lo dijo, preguntalo.',
                        ],
                        'sucursal'    => [
                            'type'        => 'string',
                            'description' => 'Sucursal a la que entra la mercadería. Solo hace falta si el negocio tiene más de una.',
                        ],
                        'reemplaza_a' => self::esquema_de_reemplazo(),
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
         */
        if (in_array($tool_name, array_column(self::definiciones_de_whatsapp(), 'name'), true)) {

            if (!($assistant_message instanceof AiMessage) || !$assistant_message->es_de_whatsapp()) {

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

            case 'proponer_gasto':
                return self::resultado(PropuestaGastoIaHelper::proponer($contexto, $assistant_message, $input));

            case 'proponer_pago':
                return self::resultado(PropuestaPagoIaHelper::proponer($contexto, $assistant_message, $input));

            case 'proponer_tarea':
                return self::resultado(PropuestaTareaIaHelper::proponer_tarea($contexto, $assistant_message, $input));

            case 'proponer_cambios_en_tarea':
                return self::resultado(PropuestaTareaIaHelper::proponer_cambios($contexto, $assistant_message, $input));

            case 'proponer_marcar_tarea_hecha':
                return self::resultado(PropuestaTareaIaHelper::proponer_marcar_hecha($contexto, $assistant_message, $input));

            case 'proponer_combo':
                return self::resultado(PropuestaComboIaHelper::proponer($contexto, $assistant_message, $input));

            case 'proponer_oferta':
                return self::resultado(PropuestaOfertaIaHelper::proponer($contexto, $assistant_message, $input));

            case 'proponer_compra_con_factura':
                return self::resultado(PropuestaCompraConFacturaIaHelper::proponer($contexto, $assistant_message, $input));

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
             * Misión asistente-omnisciente (21/9/2026). 🔴 Las cuatro propuestas van SIN
             * quizas_auto_confirmar(), a propósito: crear, cambiar o borrar datos del negocio, y
             * vender, lo confirma siempre la persona (ver AUTO_CONFIRMABLES). La venta la implementa
             * el constructor C (contrato §4); acá solo se wirea por nombre y firma.
             */
            case 'que_puedo_cargar':
                return self::resultado(CatalogoDeEscrituraIaHelper::que_puedo_cargar(EntradaDeCargaIa::valor($input, 'entidad')));

            case 'proponer_alta':
                return self::resultado(PropuestaGenericaIaHelper::proponer_alta(
                    $contexto,
                    $assistant_message,
                    EntradaDeCargaIa::valor($input, 'entidad'),
                    self::objeto_como_array(EntradaDeCargaIa::valor($input, 'datos')),
                    EntradaDeCargaIa::valor($input, 'reemplaza_a')
                ));

            case 'proponer_edicion':
                return self::resultado(PropuestaGenericaIaHelper::proponer_edicion(
                    $contexto,
                    $assistant_message,
                    EntradaDeCargaIa::valor($input, 'entidad'),
                    EntradaDeCargaIa::valor($input, 'registro'),
                    self::objeto_como_array(EntradaDeCargaIa::valor($input, 'cambios')),
                    EntradaDeCargaIa::valor($input, 'reemplaza_a')
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
                return self::resultado(PropuestaVentaIaHelper::proponer($contexto, $assistant_message, $input, EntradaDeCargaIa::valor($input, 'reemplaza_a')));

            /*
             * Misión asistente-ventas-y-fotos (21/9/2026). 🔴 Va SIN la auto-confirmación del
             * agente, a propósito, aunque la foto de SUCURSAL de arriba sí pase por ahí: la del
             * artículo siempre deja tarjeta (ver el docblock de AUTO_CONFIRMABLES).
             *
             * El nombre de esa función no se escribe acá: `36_Guardas_de_las_cargas_genericas_Test`
             * lee ESTE archivo como texto y corta el bloque de cada `case` hasta el `case`
             * siguiente, así que un comentario puesto entre dos casos se le atribuye al de arriba
             * —`proponer_venta`— y lo da por auto-confirmable. Nombrarla en prosa dice lo mismo sin
             * romper esa lectura.
             */
            case 'proponer_foto_articulo':
                return self::resultado(PropuestaFotoArticuloIaHelper::proponer($contexto, $assistant_message, $input));

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
     * Si la propuesta recién creada es de un tipo auto-confirmable Y el dueño está en "resuelto", la
     * confirma en el acto y devuelve el resultado ejecutado; si no, devuelve la propuesta tal cual
     * (queda como una tarjeta más, para confirmar a mano). Misión foto-sucursal-y-asistente-configurable.
     *
     * 🔴 Es la única puerta a la auto-ejecución. Una respuesta negativa de proponer() (faltan datos,
     * sin permiso, sin foto) no tiene tarjeta_id y sale sin tocar nada. Y el catch es la red: si la
     * confirmación en el acto lanzara, la tarjeta ya quedó propuesta y la persona la puede confirmar
     * a mano — la carga nunca se pierde por auto-ejecutar.
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

        if (!in_array($tipo, self::AUTO_CONFIRMABLES, true)) {

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

        $owner = $contexto->owner;

        // Solo "resuelto" auto-ejecuta; "cauteloso" (y cualquier otro valor, o dueño nulo) deja la
        // tarjeta propuesta.
        if (is_null($owner) || (string) $owner->agente_confianza !== 'resuelto') {

            return $respuesta;
        }

        try {

            return ConfirmacionPorTextoIaHelper::confirmar_del_agente($conversation, (int) $respuesta['tarjeta_id']);

        } catch (\Throwable $e) {

            Log::warning('HerramientasDeCarga: no se pudo auto-confirmar una tarjeta en modo resuelto', [
                'ai_conversation_id' => (int) $conversation->id,
                'tarjeta_id'         => (int) $respuesta['tarjeta_id'],
                'error'              => $e->getMessage(),
            ]);

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
                        'description' => 'Caja a la que va. Si no la mandás se usa la caja por defecto; si no hay, la herramienta te pide preguntarla.',
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
