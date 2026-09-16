<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\asistente_ia\ConsultasDeCargaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EntradaDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\OpcionesDeCargaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaComboIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaGastoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaOfertaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPagoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;

/**
 * Las herramientas de carga del asistente de IA (misión asistente-ia-acciones, §3.3 del plan): cinco
 * lecturas nuevas y cinco propuestas que arman tarjetas para que la persona confirme. La misión
 * agente-ia-mano-derecha (16/9/2026) le sumó dos propuestas más: proponer_combo y proponer_oferta.
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
     * Definiciones con su input_schema para la API de Anthropic.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definiciones(): array
    {
        return [
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
                            'description' => 'AAAA-MM-DD hasta la que vale la oferta. No puede ser anterior a hoy ni durar más de 180 días.',
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
        ];
    }

    /**
     * Nombres de todas las herramientas de carga.
     *
     * @return array<int, string>
     */
    public static function nombres(): array
    {
        return array_column(self::definiciones(), 'name');
    }

    /**
     * true si la herramienta es de este archivo.
     *
     * @param  string  $tool_name
     * @return bool
     */
    public static function maneja($tool_name): bool
    {
        return in_array((string) $tool_name, self::nombres(), true);
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
        }

        return [
            'content'  => 'Tool desconocida: '.$tool_name,
            'is_error' => true,
        ];
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
