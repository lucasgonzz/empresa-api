<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LA CONSULTA GENÉRICA DEL ASISTENTE, Y EL CATÁLOGO QUE DICE QUÉ SE PUEDE CONSULTAR.
 *
 * Las tools de intención (ConsultasSistemaIaHelper) cubren las preguntas que el dueño hace
 * siempre. Esto cubre el resto: "listame los cheques que vencen este mes", "qué presupuestos tengo
 * abiertos", "los artículos de la categoría Bulonería ordenados por precio". Se apoya en
 * `SearchController::search()` con `$return_raw_models`, que es el mismo buscador que usa el
 * listado de cada módulo de la pantalla.
 *
 * 🔴 EL CATÁLOGO VA BAJO DEMANDA Y NO EN EL PROMPT. El bloque de definiciones de tools viaja
 * ENTERO en cada vuelta del loop del asistente. Si el catálogo viviera en el system prompt, cada
 * entidad nueva engordaría todas las llamadas de todas las conversaciones y nos acercaría al corte
 * por tiempo — que es justo lo que el bloque A vino a resolver. Acá el modelo pide el catálogo
 * cuando lo necesita y paga ese costo una sola vez, en la conversación donde hace falta.
 *
 * 🔴 WHITELIST EXPLÍCITA, DECLARADA EN CÓDIGO. `consultar_datos` NO acepta un `model_name`
 * arbitrario. En `app/Models/` hay más de trescientos modelos y varios no tienen columna `user_id`:
 * para esos, `SearchController::query_base_del_modelo()` deja la query SIN scope (a propósito, son
 * catálogos del comercio entero) y la whitelist es lo único que decide qué puede ver el asistente.
 * Un `model_name` libre sobre esos trescientos es una superficie que nadie revisó.
 *
 * Los tres requisitos que tiene que cumplir una entidad para entrar, y los tres se verificaron uno
 * por uno contra el esquema:
 *
 *   1. columna `user_id` — sin ella el buscador no scopea, y la entidad queda afuera aunque tenga
 *      sentido para el dueño. Es lo que dejó afuera a `movimiento_caja`, que ESTÁ en
 *      GlobalSearchDefaultsHelper::DEFAULTS: `movimiento_cajas` no tiene `user_id` (cuelga de su
 *      caja). Se llega igual a esa información por `caja`.
 *   2. `scopeWithAll()` en el modelo — `SearchController::search()` lo llama sin preguntar
 *      (`$models->withAll()`), así que un modelo sin ese scope es una excepción, no una lista
 *      vacía. Es lo que dejó afuera a `current_acount`, que además ya tiene su propia consulta
 *      (ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle).
 *   3. columna `created_at` — el buscador ordena por ella siempre.
 *
 * 🔴 LAS COLUMNAS QUE SALEN TAMBIÉN VAN DECLARADAS. El buscador trae el modelo entero con
 * `withAll()`, y eso para `article` son ochenta y pico de columnas más sus relaciones — incluida
 * `embedding`, que es un vector. Mandarle eso a Claude es gastar el presupuesto de tiempo en datos
 * que nadie pidió. Cada entidad declara qué campos devuelve, y esa misma declaración es la que
 * dice qué se puede filtrar y ordenar: una sola tabla, no dos que se desincronizan.
 *
 * ⚠️ Un filtro que no se entiende NO se ignora: se contesta con el motivo y la lista de campos
 * válidos. Un filtro descartado en silencio devuelve la tabla entera, y eso llega al comerciante
 * como una respuesta.
 */
class CatalogoDeDatosIaHelper
{
    /**
     * LA WHITELIST. entidad => declaración.
     *
     * Arranca por las diez de GlobalSearchDefaultsHelper::DEFAULTS (menos `movimiento_caja`, que no
     * tiene user_id) y suma lo que el dueño pregunta seguido: gastos, tareas y vencimientos,
     * presupuestos, cheques, combos y el árbol de rubros.
     *
     * Cada campo declara su `tipo` con el vocabulario de ColumnFiltersHelper, que es el que decide
     * qué operadores sabe aplicar:
     *
     *   text      → contiene, igual, vacio, no_vacio
     *   number    → igual, mayor, menor, vacio, no_vacio
     *   date      → igual, mayor, menor, vacio, no_vacio
     *   search    → igual (contra el id de la relación), vacio, no_vacio
     *   checkbox  → igual (1 o 0)
     *
     * @var array
     */
    const ENTIDADES = [
        'article' => [
            'etiqueta'    => 'articulos del catalogo',
            'descripcion' => 'Los articulos del comercio. El buscador devuelve SOLO los activos (status = active): los pausados y los borrados no aparecen.',
            'campos'      => [
                'name'          => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'bar_code'      => ['tipo' => 'text',   'etiqueta' => 'codigo de barras'],
                'provider_code' => ['tipo' => 'text',   'etiqueta' => 'codigo del proveedor'],
                'final_price'   => ['tipo' => 'number', 'etiqueta' => 'precio final de venta'],
                'cost'          => ['tipo' => 'number', 'etiqueta' => 'costo'],
                'stock'         => ['tipo' => 'number', 'etiqueta' => 'stock total'],
                'stock_min'     => ['tipo' => 'number', 'etiqueta' => 'stock minimo'],
                'category_id'   => ['tipo' => 'search', 'etiqueta' => 'rubro'],
                'sub_category_id' => ['tipo' => 'search', 'etiqueta' => 'sub rubro'],
                'brand_id'      => ['tipo' => 'search', 'etiqueta' => 'marca'],
                'provider_id'   => ['tipo' => 'search', 'etiqueta' => 'proveedor titular'],
                'online'        => ['tipo' => 'checkbox', 'etiqueta' => 'publicado en la tienda'],
                'created_at'    => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [
                'rubro'     => ['tabla' => 'categories',     'columna_id' => 'category_id',     'campo' => 'name'],
                'sub_rubro' => ['tabla' => 'sub_categories', 'columna_id' => 'sub_category_id', 'campo' => 'name'],
                'marca'     => ['tabla' => 'brands',         'columna_id' => 'brand_id',        'campo' => 'name'],
                'proveedor' => ['tabla' => 'providers',      'columna_id' => 'provider_id',     'campo' => 'name'],
            ],
        ],

        'client' => [
            'etiqueta'    => 'clientes',
            'descripcion' => 'Los clientes del comercio. El saldo de cuenta corriente NO sale de aca: para la deuda va consultar_clientes o la consulta de cuenta corriente, que leen credit_accounts.',
            'campos'      => [
                'name'         => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'razon_social' => ['tipo' => 'text',   'etiqueta' => 'razon social'],
                'cuit'         => ['tipo' => 'text',   'etiqueta' => 'CUIT'],
                'phone'        => ['tipo' => 'text',   'etiqueta' => 'telefono'],
                'email'        => ['tipo' => 'text',   'etiqueta' => 'email'],
                'address'      => ['tipo' => 'text',   'etiqueta' => 'domicilio'],
                'seller_id'    => ['tipo' => 'search', 'etiqueta' => 'vendedor asignado'],
                'created_at'   => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [
                'vendedor' => ['tabla' => 'sellers', 'columna_id' => 'seller_id', 'campo' => 'name'],
            ],
        ],

        'provider' => [
            'etiqueta'    => 'proveedores',
            'descripcion' => 'Los proveedores del comercio.',
            'campos'      => [
                'name'         => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'razon_social' => ['tipo' => 'text',   'etiqueta' => 'razon social'],
                'cuit'         => ['tipo' => 'text',   'etiqueta' => 'CUIT'],
                'phone'        => ['tipo' => 'text',   'etiqueta' => 'telefono'],
                'email'        => ['tipo' => 'text',   'etiqueta' => 'email'],
                'created_at'   => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [],
        ],

        'sale' => [
            'etiqueta'    => 'ventas',
            'descripcion' => 'Las ventas del comercio (el ERP). OJO: incluye las ventas contenedoras de consolidacion AFIP, que agrupan otras ventas ya contadas — para totales de unidades vendidas va consultar_quien_compro_un_articulo, que las descarta.',
            'campos'      => [
                'num'           => ['tipo' => 'number', 'etiqueta' => 'numero de venta'],
                'total'         => ['tipo' => 'number', 'etiqueta' => 'total'],
                'client_id'     => ['tipo' => 'search', 'etiqueta' => 'cliente'],
                'employee_id'   => ['tipo' => 'search', 'etiqueta' => 'empleado que la cargo'],
                'observations'  => ['tipo' => 'text',   'etiqueta' => 'observaciones'],
                'terminada'     => ['tipo' => 'checkbox', 'etiqueta' => 'terminada'],
                'created_at'    => ['tipo' => 'date',   'etiqueta' => 'fecha'],
            ],
            'relaciones'  => [
                'cliente' => ['tabla' => 'clients', 'columna_id' => 'client_id', 'campo' => 'name'],
            ],
        ],

        'provider_order' => [
            'etiqueta'    => 'compras a proveedores',
            'descripcion' => 'Las compras que el comercio le hizo a sus proveedores. Estados: solo hay dos, "En proceso" y "Recibido".',
            'campos'      => [
                'num'                 => ['tipo' => 'number', 'etiqueta' => 'numero de compra'],
                'total'               => ['tipo' => 'number', 'etiqueta' => 'total'],
                'numero_comprobante'  => ['tipo' => 'text',   'etiqueta' => 'numero de comprobante'],
                'provider_id'         => ['tipo' => 'search', 'etiqueta' => 'proveedor'],
                'provider_order_status_id' => ['tipo' => 'search', 'etiqueta' => 'estado'],
                'created_at'          => ['tipo' => 'date',   'etiqueta' => 'fecha de carga'],
                'fecha_emision_comprobante' => ['tipo' => 'date', 'etiqueta' => 'fecha del comprobante'],
            ],
            'relaciones'  => [
                'proveedor' => ['tabla' => 'providers',              'columna_id' => 'provider_id',              'campo' => 'name'],
                'estado'    => ['tabla' => 'provider_order_statuses', 'columna_id' => 'provider_order_status_id', 'campo' => 'name'],
            ],
        ],

        'expense' => [
            'etiqueta'    => 'gastos',
            'descripcion' => 'Los gastos cargados por el comercio.',
            'campos'      => [
                'num'                => ['tipo' => 'number', 'etiqueta' => 'numero'],
                'amount'             => ['tipo' => 'number', 'etiqueta' => 'importe'],
                'observations'       => ['tipo' => 'text',   'etiqueta' => 'observaciones'],
                'expense_concept_id' => ['tipo' => 'search', 'etiqueta' => 'concepto'],
                'caja_id'            => ['tipo' => 'search', 'etiqueta' => 'caja'],
                'created_at'         => ['tipo' => 'date',   'etiqueta' => 'fecha'],
            ],
            'relaciones'  => [
                'concepto' => ['tabla' => 'expense_concepts', 'columna_id' => 'expense_concept_id', 'campo' => 'name'],
                'caja'     => ['tabla' => 'cajas',            'columna_id' => 'caja_id',            'campo' => 'name'],
            ],
        ],

        'pending' => [
            'etiqueta'    => 'tareas y vencimientos',
            'descripcion' => 'Las tareas y vencimientos de la agenda. `completado` en 1 es la que ya se hizo.',
            'campos'      => [
                'detalle'            => ['tipo' => 'text',   'etiqueta' => 'detalle'],
                'notas'              => ['tipo' => 'textarea', 'etiqueta' => 'notas'],
                'fecha_realizacion'  => ['tipo' => 'date',   'etiqueta' => 'fecha en que vence'],
                'completado'         => ['tipo' => 'checkbox', 'etiqueta' => 'ya se hizo'],
                'es_recurrente'      => ['tipo' => 'checkbox', 'etiqueta' => 'es recurrente'],
                'expense_amount'     => ['tipo' => 'number', 'etiqueta' => 'importe previsto'],
                'expense_concept_id' => ['tipo' => 'search', 'etiqueta' => 'concepto de gasto'],
                'created_at'         => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [
                'concepto' => ['tabla' => 'expense_concepts', 'columna_id' => 'expense_concept_id', 'campo' => 'name'],
            ],
        ],

        'budget' => [
            'etiqueta'    => 'presupuestos',
            'descripcion' => 'Los presupuestos hechos a clientes.',
            'campos'      => [
                'num'          => ['tipo' => 'number', 'etiqueta' => 'numero'],
                'total'        => ['tipo' => 'number', 'etiqueta' => 'total'],
                'status'       => ['tipo' => 'text',   'etiqueta' => 'estado'],
                'observations' => ['tipo' => 'text',   'etiqueta' => 'observaciones'],
                'client_id'    => ['tipo' => 'search', 'etiqueta' => 'cliente'],
                'created_at'   => ['tipo' => 'date',   'etiqueta' => 'fecha'],
                'finish_at'    => ['tipo' => 'date',   'etiqueta' => 'vence'],
            ],
            'relaciones'  => [
                'cliente' => ['tabla' => 'clients', 'columna_id' => 'client_id', 'campo' => 'name'],
            ],
        ],

        'cheque' => [
            'etiqueta'    => 'cheques',
            'descripcion' => 'Los cheques recibidos y entregados. `tipo` distingue uno de otro. El banco esta dos veces: `banco` es el texto escrito en cada cheque (puede variar: "Bco Nacion", "banco nación") y `cheque_banco_id` es el banco unificado del catalogo de bancos; para "los cheques del Banco Nación" filtra por cheque_banco_id, que es el que el negocio unifico, y usa `banco` solo para los cheques que todavia no tienen banco unificado.',
            'campos'      => [
                'numero'          => ['tipo' => 'text',   'etiqueta' => 'numero'],
                'banco'           => ['tipo' => 'text',   'etiqueta' => 'banco (texto escrito en el cheque)'],
                'cheque_banco_id' => ['tipo' => 'search', 'etiqueta' => 'banco unificado (del catalogo de bancos)'],
                'amount'          => ['tipo' => 'number', 'etiqueta' => 'importe'],
                'tipo'            => ['tipo' => 'text',   'etiqueta' => 'tipo'],
                'fecha_pago'      => ['tipo' => 'date',   'etiqueta' => 'fecha de pago'],
                'fecha_emision'   => ['tipo' => 'date',   'etiqueta' => 'fecha de emision'],
                'estado_manual'   => ['tipo' => 'text',   'etiqueta' => 'estado'],
                'client_id'       => ['tipo' => 'search', 'etiqueta' => 'cliente'],
                'provider_id'     => ['tipo' => 'search', 'etiqueta' => 'proveedor'],
                'created_at'      => ['tipo' => 'date',   'etiqueta' => 'fecha de carga'],
            ],
            'relaciones'  => [
                'cliente'         => ['tabla' => 'clients',       'columna_id' => 'client_id',       'campo' => 'name'],
                'proveedor'       => ['tabla' => 'providers',     'columna_id' => 'provider_id',     'campo' => 'name'],
                // Misión cheques-endoso-y-bancos (21/9/2026): el banco del catálogo, por nombre.
                'banco_unificado' => ['tabla' => 'cheque_bancos', 'columna_id' => 'cheque_banco_id', 'campo' => 'name'],
            ],
        ],

        'caja' => [
            'etiqueta'    => 'cajas',
            'descripcion' => 'Las cajas del comercio. `saldo` es el acumulado de la caja, no el de un dia.',
            'campos'      => [
                'name'       => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'saldo'      => ['tipo' => 'number', 'etiqueta' => 'saldo'],
                'abierta'    => ['tipo' => 'checkbox', 'etiqueta' => 'esta abierta'],
                'address_id' => ['tipo' => 'search', 'etiqueta' => 'sucursal'],
                'created_at' => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [
                'sucursal' => ['tabla' => 'addresses', 'columna_id' => 'address_id', 'campo' => 'street'],
            ],
        ],

        'order' => [
            'etiqueta'    => 'pedidos de la tienda online',
            'descripcion' => 'Los pedidos que entraron por el ecommerce. Un pedido entra al ERP como venta recien cuando el comerciante lo confirma.',
            'campos'      => [
                'num'             => ['tipo' => 'number', 'etiqueta' => 'numero'],
                'total'           => ['tipo' => 'number', 'etiqueta' => 'total'],
                'status'          => ['tipo' => 'text',   'etiqueta' => 'estado'],
                'buyer_id'        => ['tipo' => 'search', 'etiqueta' => 'comprador'],
                'order_status_id' => ['tipo' => 'search', 'etiqueta' => 'estado del pedido'],
                'created_at'      => ['tipo' => 'date',   'etiqueta' => 'fecha'],
            ],
            'relaciones'  => [
                'comprador' => ['tabla' => 'buyers',        'columna_id' => 'buyer_id',        'campo' => 'name'],
                'estado'    => ['tabla' => 'order_statuses', 'columna_id' => 'order_status_id', 'campo' => 'name'],
            ],
        ],

        'buyer' => [
            'etiqueta'    => 'compradores de la tienda online',
            'descripcion' => 'Las cuentas de la tienda online. `comercio_city_client_id` es el cliente del ERP con el que esta vinculada, si lo esta.',
            'campos'      => [
                'name'                    => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'surname'                 => ['tipo' => 'text',   'etiqueta' => 'apellido'],
                'email'                   => ['tipo' => 'text',   'etiqueta' => 'email'],
                'phone'                   => ['tipo' => 'text',   'etiqueta' => 'telefono'],
                'comercio_city_client_id' => ['tipo' => 'search', 'etiqueta' => 'cliente del ERP vinculado'],
                'created_at'              => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [
                'cliente_del_erp' => ['tabla' => 'clients', 'columna_id' => 'comercio_city_client_id', 'campo' => 'name'],
            ],
        ],

        'seller' => [
            'etiqueta'    => 'vendedores',
            'descripcion' => 'Los vendedores con comision.',
            'campos'      => [
                'name'                   => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'percentage_commission'  => ['tipo' => 'number', 'etiqueta' => 'porcentaje de comision'],
                'created_at'             => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [],
        ],

        'combo' => [
            'etiqueta'    => 'combos',
            'descripcion' => 'Los combos de articulos con su precio.',
            'campos'      => [
                'name'       => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'price'      => ['tipo' => 'number', 'etiqueta' => 'precio'],
                'cost'       => ['tipo' => 'number', 'etiqueta' => 'costo'],
                'created_at' => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [],
        ],

        'category' => [
            'etiqueta'    => 'rubros',
            'descripcion' => 'Los rubros del catalogo.',
            'campos'      => [
                'name'             => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'percentage_gain'  => ['tipo' => 'number', 'etiqueta' => 'porcentaje de ganancia'],
                'created_at'       => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [],
        ],

        'sub_category' => [
            'etiqueta'    => 'sub rubros',
            'descripcion' => 'Los sub rubros, cada uno colgado de su rubro.',
            'campos'      => [
                'name'        => ['tipo' => 'text',   'etiqueta' => 'nombre'],
                'category_id' => ['tipo' => 'search', 'etiqueta' => 'rubro'],
                'created_at'  => ['tipo' => 'date',   'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [
                'rubro' => ['tabla' => 'categories', 'columna_id' => 'category_id', 'campo' => 'name'],
            ],
        ],

        'brand' => [
            'etiqueta'    => 'marcas',
            'descripcion' => 'Las marcas del catalogo.',
            'campos'      => [
                'name'       => ['tipo' => 'text', 'etiqueta' => 'nombre'],
                'created_at' => ['tipo' => 'date', 'etiqueta' => 'fecha de alta'],
            ],
            'relaciones'  => [],
        ],
    ];

    /**
     * Los nombres de entidad que acepta consultar_datos().
     *
     * @return array<int, string>
     */
    public static function entidades(): array
    {
        return array_keys(self::ENTIDADES);
    }

    /**
     * Si una entidad está en la whitelist.
     *
     * @param  string  $entidad
     * @return bool
     */
    public static function acepta(string $entidad): bool
    {
        return isset(self::ENTIDADES[$entidad]);
    }

    /**
     * EL CATÁLOGO, BAJO DEMANDA.
     *
     * Sin entidad devuelve la lista corta —nombre y para qué sirve cada una—, que es lo que el
     * modelo necesita para elegir. Con una entidad devuelve sus campos con tipo, etiqueta y qué
     * operadores acepta cada uno, que es lo que necesita para armar el filtro.
     *
     * Va en dos niveles a propósito: el catálogo completo de las diecisiete entidades con todos sus
     * campos es varias veces más grande que la lista, y el modelo casi nunca necesita más de una.
     *
     * @param  string|null  $entidad
     * @return array<string, mixed>
     */
    public static function que_puedo_consultar($entidad = null): array
    {
        $entidad = is_null($entidad) ? '' : trim((string) $entidad);

        if ($entidad === '') {
            $lista = [];

            foreach (self::ENTIDADES as $nombre => $declaracion) {
                $lista[] = [
                    'entidad'     => $nombre,
                    'etiqueta'    => $declaracion['etiqueta'],
                    'descripcion' => $declaracion['descripcion'],
                ];
            }

            return [
                'entidades' => $lista,
                'como_sigo' => 'Volve a llamar con una entidad para ver sus campos y que se puede filtrar.',
            ];
        }

        if (! self::acepta($entidad)) {
            return self::no_existe_la_entidad($entidad);
        }

        $declaracion = self::ENTIDADES[$entidad];
        $campos = [];

        foreach ($declaracion['campos'] as $clave => $campo) {
            $campos[] = [
                'campo'      => $clave,
                'etiqueta'   => $campo['etiqueta'],
                'tipo'       => $campo['tipo'],
                'operadores' => self::operadores_de($campo['tipo']),
            ];
        }

        $relaciones = [];

        foreach ($declaracion['relaciones'] as $clave => $relacion) {
            $relaciones[] = [
                'campo_en_la_respuesta' => $clave,
                'se_filtra_por'         => $relacion['columna_id'],
            ];
        }

        return [
            'entidad'     => $entidad,
            'etiqueta'    => $declaracion['etiqueta'],
            'descripcion' => $declaracion['descripcion'],
            'campos'      => $campos,
            // El nombre lindo que sale en la respuesta y la columna con la que se filtra NO son la
            // misma: filtrar por "proveedor" en vez de por provider_id es el error más fácil.
            'relaciones'  => $relaciones,
        ];
    }

    /**
     * LA CONSULTA GENÉRICA.
     *
     * @param  int          $owner_id  Dueño de los datos. Nunca Auth: esto corre en un job sin sesión.
     * @param  string       $entidad   Una de las de la whitelist.
     * @param  array        $filtros   [['campo' => 'name', 'operador' => 'contiene', 'valor' => 'tornillo'], ...]
     * @param  array|null   $orden     ['campo' => 'final_price', 'direccion' => 'DESC']
     * @param  int          $pagina    1 en adelante.
     * @param  int          $limite    0 = ConsultasSistemaIaHelper::MAX_RESULTS.
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function consultar_datos(int $owner_id, string $entidad, array $filtros = [], $orden = null, int $pagina = 1, int $limite = 0): array
    {
        $entidad = trim($entidad);

        if (! self::acepta($entidad)) {
            return self::no_existe_la_entidad($entidad);
        }

        $declaracion = self::ENTIDADES[$entidad];

        $traducidos = self::traducir_filtros($declaracion, $filtros);

        if (isset($traducidos['error'])) {
            return $traducidos;
        }

        $columna_de_orden = self::traducir_orden($declaracion, $orden);

        if (isset($columna_de_orden['error'])) {
            return $columna_de_orden;
        }

        $filtros_de_columna = $traducidos['filtros'];

        if (! empty($columna_de_orden['filtro'])) {
            $filtros_de_columna[] = $columna_de_orden['filtro'];
        }

        if ($pagina < 1) {
            $pagina = 1;
        }

        // El mismo default y el mismo techo duro que las consultas de intención: el tope está para
        // que el JSON de una tool no se coma el presupuesto de tiempo, así que es uno solo.
        $limite = ConsultasSistemaIaHelper::limite_pedido($limite);

        /*
         * El Request se arma a mano porque `SearchController::search()` lee de ahí la página
         * (`query('page')`) y el tamaño (`input('per_page')`). Es el mismo camino que recorre el
         * listado de la pantalla, con los mismos topes: no hay una segunda paginación escrita acá.
         */
        $request = Request::create('/', 'GET', ['page' => $pagina, 'per_page' => $limite]);

        $buscador = new BuscadorDeDatosIa($owner_id);

        $paginador = $buscador->search($request, $entidad, $filtros_de_columna, 1, false, true);

        $modelos = $paginador->items();

        $etiquetas = self::etiquetas_de_relaciones($declaracion, $modelos);

        $lista = [];

        foreach ($modelos as $modelo) {
            $lista[] = self::proyectar($declaracion, $modelo, $etiquetas);
        }

        return [
            'entidad'                 => $entidad,
            'etiqueta'                => $declaracion['etiqueta'],
            'filtros_aplicados'       => $traducidos['aplicados'],
            'orden'                   => $columna_de_orden['declarado'],
            // 🔴 Cuántos hay EN TOTAL además de cuántos van en esta lista: sin los dos números, una
            // página de 20 sobre 400 filas llega a la IA como "hay 20", que es el dato de la
            // herramienta haciendose pasar por el dato del negocio.
            'registros_encontrados'   => (int) $paginador->total(),
            'registros_en_esta_lista' => count($lista),
            'pagina'                  => (int) $paginador->currentPage(),
            'paginas'                 => (int) $paginador->lastPage(),
            'registros'               => $lista,
        ];
    }

    /**
     * Los operadores que sabe aplicar ColumnFiltersHelper para un tipo de campo.
     *
     * @param  string  $tipo
     * @return array<int, string>
     */
    protected static function operadores_de(string $tipo): array
    {
        if ($tipo === 'text' || $tipo === 'textarea') {
            return ['contiene', 'igual', 'vacio', 'no_vacio'];
        }

        if ($tipo === 'number' || $tipo === 'date') {
            return ['igual', 'mayor', 'menor', 'vacio', 'no_vacio'];
        }

        if ($tipo === 'checkbox') {
            return ['igual'];
        }

        // search / select: por el id de la relación.
        return ['igual', 'vacio', 'no_vacio'];
    }

    /**
     * Traduce los filtros simples del asistente al formato de ColumnFiltersHelper.
     *
     * ⚠️ Un campo o un operador que no existe CORTA con el motivo. Descartarlo en silencio
     * devolvería la tabla entera con cara de respuesta filtrada.
     *
     * @param  array  $declaracion
     * @param  array  $filtros
     * @return array<string, mixed>
     */
    protected static function traducir_filtros(array $declaracion, array $filtros): array
    {
        $resultado = [];
        $aplicados = [];

        foreach ($filtros as $filtro) {
            if (! is_array($filtro) || ! isset($filtro['campo'])) {
                return self::error(
                    'Cada filtro tiene que ser un objeto con `campo` y `operador`.',
                    $declaracion
                );
            }

            $campo = trim((string) $filtro['campo']);

            if (! isset($declaracion['campos'][$campo])) {
                return self::error(
                    'La entidad no tiene un campo llamado "' . $campo . '".',
                    $declaracion
                );
            }

            $operador = isset($filtro['operador']) ? trim((string) $filtro['operador']) : 'igual';
            $tipo = $declaracion['campos'][$campo]['tipo'];

            if (! in_array($operador, self::operadores_de($tipo), true)) {
                return self::error(
                    'El campo "' . $campo . '" es de tipo ' . $tipo . ' y no acepta el operador "' . $operador . '".',
                    $declaracion
                );
            }

            $valor = isset($filtro['valor']) ? $filtro['valor'] : null;

            $traducido = ['key' => $campo, 'type' => $tipo];

            if ($operador === 'vacio') {
                $traducido['en_blanco'] = true;
            } elseif ($operador === 'no_vacio') {
                $traducido['no_en_blanco'] = true;
            } elseif ($tipo === 'checkbox') {
                // ColumnFiltersHelper trata el NULL como desactivado cuando el valor pedido es 0.
                $traducido['checkbox'] = self::a_booleano($valor) ? 1 : 0;
            } else {
                if (is_null($valor) || trim((string) $valor) === '') {
                    return self::error(
                        'El filtro sobre "' . $campo . '" con el operador "' . $operador . '" necesita un valor.',
                        $declaracion
                    );
                }

                $clave = 'igual_que';

                if ($operador === 'contiene') {
                    $clave = 'que_contenga';
                } elseif ($operador === 'mayor') {
                    $clave = 'mayor_que';
                } elseif ($operador === 'menor') {
                    $clave = 'menor_que';
                }

                $traducido[$clave] = (string) $valor;
            }

            $resultado[] = $traducido;

            $aplicados[] = [
                'campo'    => $campo,
                'operador' => $operador,
                'valor'    => $operador === 'vacio' || $operador === 'no_vacio' ? null : $valor,
            ];
        }

        return ['filtros' => $resultado, 'aplicados' => $aplicados];
    }

    /**
     * Traduce el orden pedido a un filtro con `ordenar_de`, que es como lo entiende
     * ColumnFiltersHelper.
     *
     * Sin orden pedido no se devuelve nada: `SearchController` ya ordena por `created_at DESC` y
     * desempata por la clave primaria, que es lo más nuevo primero.
     *
     * @param  array       $declaracion
     * @param  array|null  $orden
     * @return array<string, mixed>
     */
    protected static function traducir_orden(array $declaracion, $orden): array
    {
        if (empty($orden) || ! is_array($orden) || ! isset($orden['campo'])) {
            return ['filtro' => [], 'declarado' => 'mas_nuevos_primero'];
        }

        $campo = trim((string) $orden['campo']);

        if (! isset($declaracion['campos'][$campo])) {
            return self::error(
                'No se puede ordenar por "' . $campo . '": la entidad no tiene ese campo.',
                $declaracion
            );
        }

        $direccion = isset($orden['direccion']) ? strtoupper(trim((string) $orden['direccion'])) : 'DESC';

        if ($direccion !== 'ASC' && $direccion !== 'DESC') {
            $direccion = 'DESC';
        }

        return [
            'filtro' => [
                'key'        => $campo,
                'type'       => $declaracion['campos'][$campo]['tipo'],
                'ordenar_de' => $direccion,
            ],
            'declarado' => $campo . ' ' . $direccion,
        ];
    }

    /**
     * Las etiquetas de las relaciones declaradas, en UNA consulta por relación.
     *
     * Van por acá y no leyendo `$modelo->relacion` para no disparar una consulta por fila: el
     * `withAll()` del buscador no garantiza traer justo estas relaciones, y veinte filas con cuatro
     * relaciones cada una son ochenta consultas contra un presupuesto de tiempo que ya es ajustado.
     *
     * @param  array  $declaracion
     * @param  array  $modelos
     * @return array<string, array<int, string>>  relacion => [id => etiqueta]
     */
    protected static function etiquetas_de_relaciones(array $declaracion, array $modelos): array
    {
        $etiquetas = [];

        foreach ($declaracion['relaciones'] as $clave => $relacion) {
            $ids = [];

            foreach ($modelos as $modelo) {
                $valor = $modelo->{$relacion['columna_id']};

                if (! is_null($valor) && (int) $valor > 0) {
                    $ids[] = (int) $valor;
                }
            }

            $ids = array_values(array_unique($ids));

            if (empty($ids)) {
                $etiquetas[$clave] = [];
                continue;
            }

            $mapa = [];

            $filas = DB::table($relacion['tabla'])
                ->whereIn('id', $ids)
                ->get(['id', $relacion['campo']]);

            foreach ($filas as $fila) {
                $mapa[(int) $fila->id] = (string) $fila->{$relacion['campo']};
            }

            $etiquetas[$clave] = $mapa;
        }

        return $etiquetas;
    }

    /**
     * Un registro con los campos declarados y nada más.
     *
     * @param  array  $declaracion
     * @param  mixed  $modelo
     * @param  array  $etiquetas
     * @return array<string, mixed>
     */
    protected static function proyectar(array $declaracion, $modelo, array $etiquetas): array
    {
        // El id va siempre: es lo que permite encadenar con las consultas que reciben un id.
        $fila = ['id' => (int) $modelo->getKey()];

        foreach ($declaracion['campos'] as $clave => $campo) {
            $valor = $modelo->{$clave};

            if (is_null($valor)) {
                $fila[$clave] = null;
                continue;
            }

            if ($campo['tipo'] === 'number') {
                $fila[$clave] = (float) $valor;
            } elseif ($campo['tipo'] === 'checkbox') {
                $fila[$clave] = (bool) $valor;
            } elseif ($campo['tipo'] === 'date') {
                $fila[$clave] = $valor instanceof \DateTimeInterface ? $valor->format('d/m/Y') : (string) $valor;
            } elseif ($campo['tipo'] === 'search' || $campo['tipo'] === 'select') {
                $fila[$clave] = (int) $valor;
            } else {
                $fila[$clave] = (string) $valor;
            }
        }

        foreach ($declaracion['relaciones'] as $clave => $relacion) {
            $id = $modelo->{$relacion['columna_id']};

            $fila[$clave] = (! is_null($id) && isset($etiquetas[$clave][(int) $id]))
                ? $etiquetas[$clave][(int) $id]
                : null;
        }

        return $fila;
    }

    /**
     * Un valor que llegó como 1/0/'1'/'true'/true leído como booleano.
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected static function a_booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (is_numeric($valor)) {
            return ((float) $valor) != 0;
        }

        $texto = strtolower(trim((string) $valor));

        return in_array($texto, ['1', 'true', 'si', 'sí'], true);
    }

    /**
     * Respuesta de error con el motivo y con qué se puede hacer en vez.
     *
     * @param  string  $motivo
     * @param  array   $declaracion
     * @return array<string, mixed>
     */
    protected static function error(string $motivo, array $declaracion): array
    {
        return [
            'error'          => $motivo,
            'campos_validos' => array_keys($declaracion['campos']),
        ];
    }

    /**
     * Una entidad fuera de la whitelist. Se devuelve la lista para que el modelo pueda corregir sin
     * una vuelta más.
     *
     * @param  string  $entidad
     * @return array<string, mixed>
     */
    protected static function no_existe_la_entidad(string $entidad): array
    {
        return [
            'error'              => 'No se puede consultar "' . $entidad . '": no esta entre las entidades habilitadas.',
            'entidades_validas'  => self::entidades(),
        ];
    }
}
