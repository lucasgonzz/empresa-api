<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use Illuminate\Support\Facades\DB;

/**
 * LA CONSULTA GENÉRICA DEL ASISTENTE, Y EL CATÁLOGO QUE DICE QUÉ SE PUEDE CONSULTAR.
 *
 * Las tools de intención (ConsultasSistemaIaHelper) cubren las preguntas que el dueño hace
 * siempre. Esto cubre el resto: "listame los cheques que vencen este mes", "qué presupuestos tengo
 * abiertos", "los renglones de la última compra a Rosario", "los movimientos de la caja Efectivo".
 *
 * 🔴 EL CATÁLOGO VA BAJO DEMANDA Y NO EN EL PROMPT. El bloque de definiciones de tools viaja
 * ENTERO en cada vuelta del loop del asistente. Si el catálogo viviera en el system prompt, cada
 * entidad nueva engordaría todas las llamadas de todas las conversaciones. Acá el modelo pide el
 * catálogo cuando lo necesita y paga ese costo una sola vez, en la conversación donde hace falta.
 * Con ciento y pico de entidades esto pesa más que antes: por eso `que_puedo_consultar` acepta
 * `buscar` y devuelve la lista agrupada por módulo.
 *
 * Misión asistente-omnisciente (21/9/2026): EL MOTOR CAMBIÓ POR DENTRO Y LA CARA NO.
 *
 *   - Las entidades ya no son una whitelist escrita a mano: salen de EsquemaDeDatosIaHelper, que
 *     deriva toda tabla con `user_id` de `information_schema` (con lista negra de tablas técnicas y
 *     de columnas sensibles) y suma las entidades hijas scopeadas por su padre. Las diecisiete de
 *     ENTIDADES siguen acá, como OVERRIDES: su descripción, sus etiquetas y sus relaciones mandan
 *     sobre lo derivado, y sus campos son los que viajan por defecto.
 *   - La consulta se arma con `DB::table()` sobre la declaración: scope por dueño (`tabla.user_id`
 *     o el join al padre), condiciones fijas, filtros, orden y paginación con `count()` aparte. Ya
 *     no pasa por `SearchController::search()` ni por `BuscadorDeDatosIa` (que se borró): el
 *     buscador de la pantalla exigía `scopeWithAll()` y traía el modelo entero con sus relaciones
 *     para tirar casi todo, y su `userId()` salía de la sesión, que en un job no existe.
 *   - Operadores nuevos por tipo (`distinto`, `en`, `desde`/`hasta` inclusivos por día,
 *     `mayor_o_igual`/`menor_o_igual`) y filtro por relación POR NOMBRE: `provider_id contiene
 *     "mayorista"` se traduce a `provider_id IN (SELECT id FROM providers WHERE name LIKE ? AND
 *     user_id = ?)`. Es lo que hace que "qué le compro a EL MAYORISTA DEL NORTE" no necesite una
 *     vuelta previa para conseguir el id.
 *   - `campos` opcional para elegir qué columnas viajan; sin él, las declaradas (y, en una entidad
 *     derivada sin override, las primeras treinta, con `campos_omitidos` en la respuesta).
 *
 * 🔴 NADA DE LO QUE MANDA EL MODELO SE INTERPOLA EN SQL. Los nombres de tabla y de columna que van
 * en el SQL salen de la declaración (o sea, de `information_schema` o de una constante); lo que
 * manda el modelo —entidad, campo, operador, relación— se busca en la declaración y, si no está,
 * corta con error y con la lista de lo válido. Los valores van siempre por binding.
 *
 * ⚠️ Un filtro que no se entiende NO se ignora: se contesta con el motivo y la lista de campos
 * válidos. Un filtro descartado en silencio devuelve la tabla entera, y eso llega al comerciante
 * como una respuesta.
 */
class CatalogoDeDatosIaHelper
{
    /**
     * LAS DIECISIETE CURADAS. entidad => declaración.
     *
     * Ya no son la whitelist: son los OVERRIDES sobre el esquema derivado (ver
     * EsquemaDeDatosIaHelper::aplicar_override). Se conservan con esta forma porque son el
     * contrato que el modelo ya conoce y el que fija el test de proyección: los campos declarados
     * acá son los que viajan por defecto, en este orden, y las relaciones se llaman como acá.
     *
     * Cada campo declara su `tipo` con el vocabulario de siempre, que es el que decide qué
     * operadores acepta (ver operadores_de()):
     *
     *   text/textarea → contiene, igual, distinto, vacio, no_vacio, en
     *   number        → igual, distinto, mayor, menor, mayor_o_igual, menor_o_igual, vacio, no_vacio, en
     *   date          → igual (el día), desde, hasta (inclusivos), mayor, menor, vacio, no_vacio
     *   search        → igual (id o nombre), en (ids), contiene (nombre), vacio, no_vacio
     *   checkbox      → igual (1 o 0)
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
            'descripcion' => 'Las ventas del comercio (el ERP), SIN las ventas contenedoras de consolidacion AFIP (agrupan otras ventas ya contadas; hasta el 21/9/2026 si entraban). Para el total vendido de un periodo va consultar_resumen_de_ventas, que es el mismo numero que el reporte de Rendimiento; para articulos dentro de las ventas va renglon_de_venta.',
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
     * Los nombres de entidad que acepta consultar_datos(): las curadas, las derivadas y las hijas.
     *
     * @return array<int, string>
     */
    public static function entidades(): array
    {
        return EsquemaDeDatosIaHelper::entidades();
    }

    /**
     * Si una entidad está en el catálogo.
     *
     * @param  string  $entidad
     * @return bool
     */
    public static function acepta(string $entidad): bool
    {
        return EsquemaDeDatosIaHelper::existe($entidad);
    }

    /**
     * EL CATÁLOGO, BAJO DEMANDA.
     *
     * Sin entidad devuelve la lista corta —entidad, etiqueta y módulo, y la descripción solo de
     * las curadas—, agrupada por módulo y filtrada por `buscar` (substring sin acentos sobre
     * entidad, etiqueta y módulo). Con una entidad devuelve sus campos con tipo, etiqueta y qué
     * operadores acepta cada uno, sus condiciones fijas en texto, su padre si lo tiene y qué
     * campos viajan por defecto.
     *
     * Va en dos niveles a propósito: el catálogo completo con todos los campos de ciento y pico
     * de entidades es varias veces más grande que la lista, y el modelo casi nunca necesita más
     * de una.
     *
     * @param  string|null  $entidad
     * @param  string|null  $buscar   Solo se usa sin entidad.
     * @return array<string, mixed>
     */
    public static function que_puedo_consultar($entidad = null, $buscar = null): array
    {
        $entidad = is_null($entidad) ? '' : trim((string) $entidad);

        if ($entidad === '') {
            $lista = EsquemaDeDatosIaHelper::lista_para_el_modelo($buscar);

            $respuesta = [
                'entidades' => $lista,
                'como_sigo' => 'Volve a llamar con una entidad para ver sus campos y que se puede filtrar. La lista viene agrupada por modulo; con `buscar` la acotas por nombre, etiqueta o modulo.',
            ];

            if (empty($lista) && ! is_null($buscar) && trim((string) $buscar) !== '') {
                $respuesta['como_sigo'] = 'Nada coincide con "' . trim((string) $buscar) . '". Proba con otra palabra o llama sin `buscar` para ver la lista entera.';
            }

            return $respuesta;
        }

        $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);

        if (is_null($declaracion)) {
            return self::no_existe_la_entidad($entidad);
        }

        /*
         * Los campos por defecto van con todo el detalle; los adicionales (los que la curada no
         * declaró, o los que pasan el tope de treinta en una derivada) van como `campo => tipo`.
         * `article` tiene noventa y un campos: con el detalle completo en todos, este catálogo
         * pesaba 16 KB, y así pesa un tercio. Los adicionales se filtran, se ordenan y se piden
         * con `campos` exactamente igual que los otros.
         */
        $campos = [];
        $adicionales = [];
        $por_defecto = $declaracion['campos_por_defecto'];

        foreach ($declaracion['campos'] as $clave => $campo) {
            if (! in_array($clave, $por_defecto, true)) {
                $adicionales[$clave] = $campo['tipo'] . (isset($campo['valores']) ? ' (' . implode('|', $campo['valores']) . ')' : '');
                continue;
            }

            $fila = [
                'campo'      => $clave,
                'etiqueta'   => $campo['etiqueta'],
                'tipo'       => $campo['tipo'],
                'operadores' => self::operadores_de($campo['tipo']),
            ];

            if (isset($campo['valores'])) {
                $fila['valores'] = $campo['valores'];
            }

            // Un campo calculado se usa igual que cualquier otro; lo que cambia es que no se puede
            // cargar ni editar, y que es el que hay que sumar cuando la pregunta es de plata.
            if (isset($campo['calculado'])) {
                $fila['calculado'] = 'se calcula por renglon; se filtra, se ordena y se suma como cualquier campo numerico, pero no se puede cargar ni editar';
            }

            $campos[] = $fila;
        }

        $relaciones = [];

        foreach ($declaracion['relaciones'] as $clave => $relacion) {
            $relaciones[] = [
                'campo_en_la_respuesta' => $clave,
                'se_filtra_por'         => $relacion['columna_id'],
            ];
        }

        $respuesta = [
            'entidad'     => $entidad,
            'etiqueta'    => $declaracion['etiqueta'],
            'modulo'      => $declaracion['modulo'],
            'descripcion' => $declaracion['descripcion'],
            'condiciones_fijas' => array_column($declaracion['condiciones_fijas'], 'texto'),
            // Los que viajan sin pedir `campos`, con detalle completo.
            'campos'      => $campos,
            // El nombre lindo que sale en la respuesta y la columna con la que se filtra NO son la
            // misma: filtrar por "proveedor" en vez de por provider_id es el error más fácil. La
            // columna _id acepta además `contiene` / `igual` con el NOMBRE de la relación.
            'relaciones'  => $relaciones,
        ];

        if (! empty($adicionales)) {
            $respuesta['campos_adicionales'] = $adicionales;
            $respuesta['operadores_por_tipo'] = [
                'text'     => self::operadores_de('text'),
                'number'   => self::operadores_de('number'),
                'date'     => self::operadores_de('date'),
                'search'   => self::operadores_de('search'),
                'checkbox' => self::operadores_de('checkbox'),
            ];
            $respuesta['como_sigo'] = 'Los campos_adicionales no viajan por defecto: pedilos en `campos` de consultar_datos. Se filtran y se ordenan igual que los otros.';
        }

        if (! is_null($declaracion['padre'])) {
            $respuesta['padre'] = 'Cada fila cuelga de un registro de "' . $declaracion['padre']['entidad'] . '" (' . $declaracion['padre']['tabla'] . '), y solo se ven las del dueño.';
        }

        return $respuesta;
    }

    /**
     * LA CONSULTA GENÉRICA.
     *
     * @param  int          $owner_id  Dueño de los datos. Nunca Auth: esto corre en un job sin sesión.
     * @param  string       $entidad   Una del catálogo.
     * @param  array        $filtros   [['campo' => 'name', 'operador' => 'contiene', 'valor' => 'tornillo'], ...]
     * @param  array|null   $orden     ['campo' => 'final_price', 'direccion' => 'DESC']
     * @param  int          $pagina    1 en adelante.
     * @param  int          $limite    0 = ConsultasSistemaIaHelper::MAX_RESULTS.
     * @param  array|null   $campos    Qué campos devolver; null = los declarados por defecto.
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function consultar_datos(int $owner_id, string $entidad, array $filtros = [], $orden = null, int $pagina = 1, int $limite = 0, $campos = null): array
    {
        $entidad = trim($entidad);

        $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);

        if (is_null($declaracion)) {
            return self::no_existe_la_entidad($entidad);
        }

        $base = self::consulta_base($owner_id, $declaracion, $filtros);

        if (isset($base['error'])) {
            return $base;
        }

        $orden_traducido = self::traducir_orden($declaracion, $orden);

        if (isset($orden_traducido['error'])) {
            return $orden_traducido;
        }

        $proyeccion = self::proyeccion($declaracion, $campos);

        if (isset($proyeccion['error'])) {
            return $proyeccion;
        }

        if ($pagina < 1) {
            $pagina = 1;
        }

        // El mismo default y el mismo techo duro que las consultas de intención: el tope está para
        // que el JSON de una tool no se coma el presupuesto de tiempo, así que es uno solo.
        $limite = ConsultasSistemaIaHelper::limite_pedido($limite);

        /** @var \Illuminate\Database\Query\Builder $query */
        $query = $base['query'];

        // El total se cuenta aparte, ANTES de paginar y sin la proyección.
        $total = (int) (clone $query)->count(DB::raw('DISTINCT ' . self::columna_sql($declaracion, 'id')));

        $paginas = $total > 0 ? (int) ceil($total / $limite) : 1;

        foreach ($orden_traducido['sql'] as $tramo) {
            $query->orderByRaw($tramo);
        }

        $filas = $query
            ->offset(($pagina - 1) * $limite)
            ->limit($limite)
            ->get($proyeccion['select']);

        $etiquetas = self::etiquetas_de_relaciones($owner_id, $declaracion, $proyeccion['relaciones'], $filas);

        $lista = [];

        foreach ($filas as $fila) {
            $lista[] = self::proyectar($declaracion, $proyeccion, $fila, $etiquetas);
        }

        $respuesta = [
            'entidad'                 => $entidad,
            'etiqueta'                => $declaracion['etiqueta'],
            'filtros_aplicados'       => $base['aplicados'],
            'orden'                   => $orden_traducido['declarado'],
            // 🔴 Cuántos hay EN TOTAL además de cuántos van en esta lista: sin los dos números, una
            // página de 20 sobre 400 filas llega a la IA como "hay 20", que es el dato de la
            // herramienta haciendose pasar por el dato del negocio.
            'registros_encontrados'   => $total,
            'registros_en_esta_lista' => count($lista),
            'pagina'                  => $pagina,
            'paginas'                 => $paginas,
            'registros'               => $lista,
        ];

        if (! empty($proyeccion['omitidos'])) {
            // Qué columnas existen y no viajaron: se piden con `campos`.
            $respuesta['campos_omitidos'] = $proyeccion['omitidos'];
        }

        return $respuesta;
    }

    /**
     * LA CONSULTA BASE: scope por dueño, condiciones fijas y filtros traducidos, sobre
     * `DB::table()`. Es lo que comparten consultar_datos() y ResumenDeDatosIaHelper::resumir():
     * el mismo conjunto de filas para listar y para sumar, por construcción.
     *
     * @param  int    $owner_id
     * @param  array  $declaracion
     * @param  array  $filtros
     * @return array{query: \Illuminate\Database\Query\Builder, aplicados: array}|array{error: string}
     */
    public static function consulta_base(int $owner_id, array $declaracion, array $filtros): array
    {
        $traducidos = self::traducir_filtros($owner_id, $declaracion, $filtros);

        if (isset($traducidos['error'])) {
            return $traducidos;
        }

        $tabla = $declaracion['tabla'];

        $query = DB::table($tabla);

        if (! is_null($declaracion['padre'])) {
            $padre = $declaracion['padre'];

            // 🔴 El scope va sobre el padre: la tabla hija no tiene user_id. Un renglón de una venta
            // de OTRO dueño no aparece porque su venta no es del dueño.
            $query->join($padre['tabla'], $tabla . '.' . $padre['columna_local'], '=', $padre['tabla'] . '.' . $padre['columna_padre'])
                ->where($padre['tabla'] . '.user_id', $owner_id);
        } else {
            $query->where($tabla . '.' . $declaracion['columna_dueno'], $owner_id);
        }

        foreach ($declaracion['condiciones_fijas'] as $condicion) {
            $query->whereRaw($condicion['sql']);
        }

        foreach ($traducidos['filtros'] as $filtro) {
            $query->whereRaw($filtro['sql'], $filtro['bindings']);
        }

        return ['query' => $query, 'aplicados' => $traducidos['aplicados']];
    }

    /**
     * Los operadores que acepta un tipo de campo.
     *
     * @param  string  $tipo
     * @return array<int, string>
     */
    public static function operadores_de(string $tipo): array
    {
        if ($tipo === 'text' || $tipo === 'textarea') {
            return ['contiene', 'igual', 'distinto', 'vacio', 'no_vacio', 'en'];
        }

        if ($tipo === 'number') {
            return ['igual', 'distinto', 'mayor', 'menor', 'mayor_o_igual', 'menor_o_igual', 'vacio', 'no_vacio', 'en'];
        }

        if ($tipo === 'date') {
            return ['igual', 'desde', 'hasta', 'mayor', 'menor', 'vacio', 'no_vacio'];
        }

        if ($tipo === 'checkbox') {
            return ['igual'];
        }

        // search: por el id de la relación (igual, en) o por su NOMBRE (contiene, igual con texto).
        return ['igual', 'en', 'contiene', 'vacio', 'no_vacio'];
    }

    /**
     * La columna SQL de un campo, calificada y con backticks. `id` es siempre válido: es la clave
     * de la tabla y el modelo la recibe en cada fila.
     *
     * @param  array   $declaracion
     * @param  string  $campo
     * @return string|null  null si el campo no existe
     */
    public static function columna_sql(array $declaracion, string $campo)
    {
        if ($campo === 'id') {
            return '`' . $declaracion['tabla'] . '`.`id`';
        }

        if (! isset($declaracion['campos'][$campo])) {
            return null;
        }

        /*
         * 🔴 UN CAMPO CALCULADO devuelve su expresión, entre paréntesis, y con eso alcanza: este
         * método es el único lugar por el que pasan el SELECT, el WHERE, el ORDER BY y el SUM()
         * de la agregación, así que `importe` se proyecta, se filtra, se ordena y se suma como
         * cualquier columna, sin una sola rama nueva en los consumidores.
         *
         * El filtro repite la expresión en el WHERE en vez de irse a HAVING: es lo más simple y,
         * sobre todo, deja intactos el `count()` del total y la paginación, que son sobre la misma
         * query sin agrupar.
         *
         * ⚠️ La expresión sale de EsquemaDeDatosIaHelper::HIJAS —una constante de este código—,
         * nunca del input del modelo: lo que el modelo manda es el NOMBRE, que si no está declarado
         * devuelve null acá y corta con error arriba.
         */
        if (isset($declaracion['campos'][$campo]['calculado'])) {
            return '(' . $declaracion['campos'][$campo]['expresion'] . ')';
        }

        $partes = explode('.', $declaracion['campos'][$campo]['columna']);

        return '`' . implode('`.`', $partes) . '`';
    }

    /**
     * El tipo de un campo (o de `id`, que es number).
     *
     * @param  array   $declaracion
     * @param  string  $campo
     * @return string|null
     */
    public static function tipo_del_campo(array $declaracion, string $campo)
    {
        if ($campo === 'id') {
            return 'number';
        }

        return isset($declaracion['campos'][$campo]) ? $declaracion['campos'][$campo]['tipo'] : null;
    }

    /**
     * La relación que cuelga de un campo `search`, o null.
     *
     * @param  array   $declaracion
     * @param  string  $campo
     * @return array<string, mixed>|null
     */
    public static function relacion_del_campo(array $declaracion, string $campo)
    {
        foreach ($declaracion['relaciones'] as $relacion) {
            if ($relacion['columna_id'] === $campo) {
                return $relacion;
            }
        }

        return null;
    }

    /**
     * Traduce los filtros simples del asistente a SQL con bindings.
     *
     * ⚠️ Un campo o un operador que no existe CORTA con el motivo. Descartarlo en silencio
     * devolvería la tabla entera con cara de respuesta filtrada.
     *
     * @param  int    $owner_id
     * @param  array  $declaracion
     * @param  array  $filtros
     * @return array<string, mixed>
     */
    protected static function traducir_filtros(int $owner_id, array $declaracion, array $filtros): array
    {
        $resultado = [];
        $aplicados = [];

        foreach ($filtros as $filtro) {
            if (! is_array($filtro) || ! isset($filtro['campo'])) {
                return self::error('Cada filtro tiene que ser un objeto con `campo` y `operador`.', $declaracion);
            }

            $campo = trim((string) $filtro['campo']);
            $tipo = self::tipo_del_campo($declaracion, $campo);

            if (is_null($tipo)) {
                return self::error('La entidad no tiene un campo llamado "' . $campo . '".', $declaracion);
            }

            $operador = isset($filtro['operador']) ? trim((string) $filtro['operador']) : 'igual';

            if (! in_array($operador, self::operadores_de($tipo), true)) {
                return self::error(
                    'El campo "' . $campo . '" es de tipo ' . $tipo . ' y no acepta el operador "' . $operador . '".',
                    $declaracion
                );
            }

            $valor = isset($filtro['valor']) ? $filtro['valor'] : null;

            $condicion = self::condicion_sql($owner_id, $declaracion, $campo, $tipo, $operador, $valor);

            if (isset($condicion['error'])) {
                return $condicion;
            }

            $resultado[] = $condicion;

            $aplicados[] = [
                'campo'    => $campo,
                'operador' => $operador,
                'valor'    => ($operador === 'vacio' || $operador === 'no_vacio') ? null : $valor,
            ];
        }

        return ['filtros' => $resultado, 'aplicados' => $aplicados];
    }

    /**
     * UNA condición SQL con sus bindings, según el tipo del campo y el operador.
     *
     * Semántica que conviene saber:
     *   - `distinto` incluye los NULL (un estado "distinto de pagado" también es el que no tiene estado).
     *   - `vacio` en texto es NULL o cadena vacía; en search es NULL o 0; en número y fecha, NULL.
     *   - En fechas todo se compara POR DÍA, con rangos semiabiertos que dejan usar el índice:
     *     `igual` es el día entero, `desde`/`hasta` son inclusivos, `mayor`/`menor` estrictos.
     *   - `checkbox` con 0 incluye los NULL, como ColumnFiltersHelper: un pendiente sin `completado`
     *     cargado es un pendiente sin hacer.
     *
     * @param  int     $owner_id
     * @param  array   $declaracion
     * @param  string  $campo
     * @param  string  $tipo
     * @param  string  $operador
     * @param  mixed   $valor
     * @return array<string, mixed>  ['sql' => ..., 'bindings' => [...]] o ['error' => ...]
     */
    protected static function condicion_sql(int $owner_id, array $declaracion, string $campo, string $tipo, string $operador, $valor): array
    {
        $col = self::columna_sql($declaracion, $campo);

        if ($operador === 'vacio') {
            if ($tipo === 'text' || $tipo === 'textarea') {
                return ['sql' => '(' . $col . ' IS NULL OR ' . $col . " = '')", 'bindings' => []];
            }

            if ($tipo === 'search') {
                return ['sql' => '(' . $col . ' IS NULL OR ' . $col . ' = 0)', 'bindings' => []];
            }

            return ['sql' => $col . ' IS NULL', 'bindings' => []];
        }

        if ($operador === 'no_vacio') {
            if ($tipo === 'text' || $tipo === 'textarea') {
                return ['sql' => '(' . $col . ' IS NOT NULL AND ' . $col . " <> '')", 'bindings' => []];
            }

            if ($tipo === 'search') {
                return ['sql' => '(' . $col . ' IS NOT NULL AND ' . $col . ' <> 0)', 'bindings' => []];
            }

            return ['sql' => $col . ' IS NOT NULL', 'bindings' => []];
        }

        if ($tipo === 'checkbox') {
            return self::a_booleano($valor)
                ? ['sql' => $col . ' = 1', 'bindings' => []]
                : ['sql' => '(' . $col . ' IS NULL OR ' . $col . ' = 0)', 'bindings' => []];
        }

        if ($operador === 'en') {
            $valores = self::lista_de_valores($valor);

            if (empty($valores)) {
                return self::error('El operador "en" sobre "' . $campo . '" necesita una lista de valores.', $declaracion);
            }

            if ($tipo === 'number' || $tipo === 'search') {
                foreach ($valores as $uno) {
                    if (! is_numeric($uno)) {
                        return self::error(
                            'El operador "en" sobre "' . $campo . '" acepta solo ' . ($tipo === 'search' ? 'ids' : 'numeros') . '; "' . $uno . '" no lo es.'
                            . ($tipo === 'search' ? ' Para buscar por nombre usa "contiene".' : ''),
                            $declaracion
                        );
                    }
                }
            }

            return [
                'sql'      => $col . ' IN (' . implode(', ', array_fill(0, count($valores), '?')) . ')',
                'bindings' => $valores,
            ];
        }

        if (is_null($valor) || trim((string) $valor) === '') {
            return self::error(
                'El filtro sobre "' . $campo . '" con el operador "' . $operador . '" necesita un valor.',
                $declaracion
            );
        }

        if ($tipo === 'text' || $tipo === 'textarea') {
            if ($operador === 'contiene') {
                return ['sql' => $col . ' LIKE ?', 'bindings' => ['%' . self::escapar_like((string) $valor) . '%']];
            }

            if ($operador === 'distinto') {
                return ['sql' => '(' . $col . ' IS NULL OR ' . $col . ' <> ?)', 'bindings' => [(string) $valor]];
            }

            return ['sql' => $col . ' = ?', 'bindings' => [(string) $valor]];
        }

        if ($tipo === 'number') {
            if (! is_numeric($valor)) {
                return self::error('El campo "' . $campo . '" es numerico y "' . $valor . '" no es un numero.', $declaracion);
            }

            $simbolos = ['igual' => '=', 'mayor' => '>', 'menor' => '<', 'mayor_o_igual' => '>=', 'menor_o_igual' => '<='];

            if ($operador === 'distinto') {
                return ['sql' => '(' . $col . ' IS NULL OR ' . $col . ' <> ?)', 'bindings' => [$valor + 0]];
            }

            return ['sql' => $col . ' ' . $simbolos[$operador] . ' ?', 'bindings' => [$valor + 0]];
        }

        if ($tipo === 'date') {
            $dia = self::dia_de($valor);

            if (is_null($dia)) {
                return self::error('La fecha "' . $valor . '" del filtro sobre "' . $campo . '" no se entiende: va en formato AAAA-MM-DD.', $declaracion);
            }

            $inicio = $dia . ' 00:00:00';
            $siguiente = date('Y-m-d', strtotime($dia . ' +1 day')) . ' 00:00:00';

            if ($operador === 'igual') {
                return ['sql' => '(' . $col . ' >= ? AND ' . $col . ' < ?)', 'bindings' => [$inicio, $siguiente]];
            }

            if ($operador === 'desde' || $operador === 'mayor_o_igual') {
                return ['sql' => $col . ' >= ?', 'bindings' => [$inicio]];
            }

            if ($operador === 'hasta') {
                return ['sql' => $col . ' < ?', 'bindings' => [$siguiente]];
            }

            if ($operador === 'mayor') {
                return ['sql' => $col . ' >= ?', 'bindings' => [$siguiente]];
            }

            // menor: antes del día.
            return ['sql' => $col . ' < ?', 'bindings' => [$inicio]];
        }

        // search: igual con id, o igual/contiene con el NOMBRE de la relación.
        if ($operador === 'igual' && is_numeric($valor)) {
            return ['sql' => $col . ' = ?', 'bindings' => [(int) $valor]];
        }

        return self::condicion_por_nombre($owner_id, $declaracion, $campo, $col, $operador, (string) $valor);
    }

    /**
     * EL FILTRO POR NOMBRE DE UNA RELACIÓN: `columna_id IN (SELECT id FROM tabla WHERE name LIKE ?
     * [AND user_id = ?])`. El `user_id` va solo si la tabla destino lo tiene: sin él, un rubro de
     * OTRO comercio que se llame "Bulonería" matchearía ids que acá no existen (inocuo) pero
     * también podría colar un nombre repetido entre comercios en una tabla global.
     *
     * Para `users` (empleados) el scope es `id = dueño OR owner_id = dueño`, que es la cuenta entera.
     * Para las etiquetas fijas (moneda) se resuelve contra la lista de etiquetas, sin subconsulta.
     *
     * @param  int     $owner_id
     * @param  array   $declaracion
     * @param  string  $campo
     * @param  string  $col
     * @param  string  $operador   igual | contiene
     * @param  string  $texto
     * @return array<string, mixed>
     */
    protected static function condicion_por_nombre(int $owner_id, array $declaracion, string $campo, string $col, string $operador, string $texto): array
    {
        $relacion = self::relacion_del_campo($declaracion, $campo);

        if (is_null($relacion)) {
            return self::error('El campo "' . $campo . '" se filtra por id; no tiene un nombre por el que buscar.', $declaracion);
        }

        if (isset($relacion['etiquetas_fijas'])) {
            $buscado = ConsultasSistemaIaHelper::normalize_text($texto);
            $ids = [];

            foreach ($relacion['etiquetas_fijas'] as $id => $etiqueta) {
                $normalizada = ConsultasSistemaIaHelper::normalize_text($etiqueta);

                if ($operador === 'contiene' ? strpos($normalizada, $buscado) !== false : $normalizada === $buscado) {
                    $ids[] = (int) $id;
                }
            }

            if (empty($ids)) {
                return self::error(
                    'Ninguna moneda se llama "' . $texto . '". Las opciones son: ' . implode(', ', array_unique(array_values($relacion['etiquetas_fijas']))) . '.',
                    $declaracion
                );
            }

            $sql = $col . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';

            // Pesos incluye el NULL: una venta vieja sin moneda cargada es una venta en pesos.
            if (in_array(0, $ids, true) || in_array(1, $ids, true)) {
                $sql = '(' . $sql . ' OR ' . $col . ' IS NULL)';
            }

            return ['sql' => $sql, 'bindings' => $ids];
        }

        $sub = 'SELECT `id` FROM `' . $relacion['tabla'] . '` WHERE `' . $relacion['campo'] . '` ';
        $bindings = [];

        if ($operador === 'contiene') {
            $sub .= 'LIKE ?';
            $bindings[] = '%' . self::escapar_like($texto) . '%';
        } else {
            $sub .= '= ?';
            $bindings[] = $texto;
        }

        if ($relacion['tabla'] === 'users') {
            $sub .= ' AND (`id` = ? OR `owner_id` = ?)';
            $bindings[] = $owner_id;
            $bindings[] = $owner_id;
        } elseif (! empty($relacion['con_user_id'])) {
            $sub .= ' AND `user_id` = ?';
            $bindings[] = $owner_id;
        }

        return ['sql' => $col . ' IN (' . $sub . ')', 'bindings' => $bindings];
    }

    /**
     * Traduce el orden pedido. Sin orden, lo más nuevo primero: `created_at DESC` (si la tabla la
     * tiene) y desempate por la clave primaria.
     *
     * @param  array       $declaracion
     * @param  array|null  $orden
     * @return array<string, mixed>
     */
    protected static function traducir_orden(array $declaracion, $orden): array
    {
        $id = self::columna_sql($declaracion, 'id');

        if (empty($orden) || ! is_array($orden) || ! isset($orden['campo'])) {
            $sql = [];

            if (isset($declaracion['campos']['created_at'])) {
                $sql[] = self::columna_sql($declaracion, 'created_at') . ' DESC';
            }

            $sql[] = $id . ' DESC';

            return ['sql' => $sql, 'declarado' => 'mas_nuevos_primero'];
        }

        $campo = trim((string) $orden['campo']);
        $col = self::columna_sql($declaracion, $campo);

        if (is_null($col)) {
            return self::error('No se puede ordenar por "' . $campo . '": la entidad no tiene ese campo.', $declaracion);
        }

        $direccion = isset($orden['direccion']) ? strtoupper(trim((string) $orden['direccion'])) : 'DESC';

        if ($direccion !== 'ASC' && $direccion !== 'DESC') {
            $direccion = 'DESC';
        }

        return [
            'sql'       => [$col . ' ' . $direccion, $id . ' DESC'],
            'declarado' => $campo . ' ' . $direccion,
        ];
    }

    /**
     * Qué columnas van en el SELECT y qué relaciones se resuelven: las pedidas en `campos` o, sin
     * eso, las declaradas por defecto. Una relación se resuelve solo si su columna viaja.
     *
     * @param  array       $declaracion
     * @param  array|null  $campos
     * @return array<string, mixed>
     */
    protected static function proyeccion(array $declaracion, $campos): array
    {
        $omitidos = [];

        if (is_array($campos) && ! empty($campos)) {
            $elegidos = [];

            foreach ($campos as $campo) {
                $campo = trim((string) $campo);

                if ($campo === 'id') {
                    continue;
                }

                if (! isset($declaracion['campos'][$campo])) {
                    return self::error('No se puede devolver "' . $campo . '": la entidad no tiene ese campo.', $declaracion);
                }

                if (! in_array($campo, $elegidos, true)) {
                    $elegidos[] = $campo;
                }
            }
        } else {
            $elegidos = $declaracion['campos_por_defecto'];
            $omitidos = $declaracion['campos_omitidos'];
        }

        $select = [DB::raw(self::columna_sql($declaracion, 'id') . ' as `id`')];

        foreach ($elegidos as $campo) {
            $select[] = DB::raw(self::columna_sql($declaracion, $campo) . ' as `' . $campo . '`');
        }

        $relaciones = [];

        foreach ($declaracion['relaciones'] as $clave => $relacion) {
            if (in_array($relacion['columna_id'], $elegidos, true)) {
                $relaciones[$clave] = $relacion;
            }
        }

        return ['campos' => $elegidos, 'select' => $select, 'relaciones' => $relaciones, 'omitidos' => $omitidos];
    }

    /**
     * Las etiquetas de las relaciones, en UNA consulta por relación (no por fila): veinte filas con
     * cuatro relaciones cada una serían ochenta consultas contra un presupuesto de tiempo ajustado.
     *
     * @param  int    $owner_id
     * @param  array  $declaracion
     * @param  array  $relaciones  Las de la proyección.
     * @param  mixed  $filas
     * @return array<string, array<int, string>>  relacion => [id => etiqueta]
     */
    protected static function etiquetas_de_relaciones(int $owner_id, array $declaracion, array $relaciones, $filas): array
    {
        $etiquetas = [];

        foreach ($relaciones as $clave => $relacion) {
            $ids = [];

            foreach ($filas as $fila) {
                $valor = $fila->{$relacion['columna_id']};

                if (! is_null($valor) && (int) $valor > 0) {
                    $ids[] = (int) $valor;
                }
            }

            $etiquetas[$clave] = self::etiquetas_de_relacion($relacion, array_values(array_unique($ids)));
        }

        return $etiquetas;
    }

    /**
     * id => etiqueta para un lote de ids de UNA relación. Público porque la agregación lo usa para
     * ponerle nombre a los grupos.
     *
     * @param  array  $relacion
     * @param  array  $ids
     * @return array<int, string>
     */
    public static function etiquetas_de_relacion(array $relacion, array $ids): array
    {
        if (isset($relacion['etiquetas_fijas'])) {
            return $relacion['etiquetas_fijas'];
        }

        if (empty($ids)) {
            return [];
        }

        $mapa = [];

        $filas = DB::table($relacion['tabla'])
            ->whereIn('id', $ids)
            ->get(['id', $relacion['campo']]);

        foreach ($filas as $fila) {
            $mapa[(int) $fila->id] = (string) $fila->{$relacion['campo']};
        }

        return $mapa;
    }

    /**
     * Un registro con los campos proyectados y nada más.
     *
     * @param  array  $declaracion
     * @param  array  $proyeccion
     * @param  object $fila
     * @param  array  $etiquetas
     * @return array<string, mixed>
     */
    protected static function proyectar(array $declaracion, array $proyeccion, $fila, array $etiquetas): array
    {
        // El id va siempre: es lo que permite encadenar con las consultas que reciben un id.
        $registro = ['id' => (int) $fila->id];

        foreach ($proyeccion['campos'] as $campo) {
            $registro[$campo] = self::valor_legible($fila->{$campo}, $declaracion['campos'][$campo]['tipo']);
        }

        foreach ($proyeccion['relaciones'] as $clave => $relacion) {
            $registro[$clave] = self::etiqueta_de($etiquetas[$clave], $fila->{$relacion['columna_id']});
        }

        return $registro;
    }

    /**
     * La etiqueta de un id dentro de un mapa de relación (null si no hay).
     *
     * ⚠️ Con etiquetas fijas (moneda), el NULL también tiene etiqueta: es pesos.
     *
     * @param  array  $mapa
     * @param  mixed  $id
     * @return string|null
     */
    public static function etiqueta_de(array $mapa, $id)
    {
        if (is_null($id)) {
            return isset($mapa[0]) && isset($mapa[1]) && $mapa[0] === $mapa[1] ? $mapa[0] : null;
        }

        return isset($mapa[(int) $id]) ? $mapa[(int) $id] : null;
    }

    /**
     * Un valor de la base casteado según el tipo declarado, como lo lee la persona: número como
     * float, checkbox como bool, fecha como dd/mm/aaaa, id de relación como int, texto como string.
     *
     * @param  mixed   $valor
     * @param  string  $tipo
     * @return mixed
     */
    public static function valor_legible($valor, string $tipo)
    {
        if (is_null($valor)) {
            return null;
        }

        if ($tipo === 'number') {
            return (float) $valor;
        }

        if ($tipo === 'checkbox') {
            return (bool) $valor;
        }

        if ($tipo === 'date') {
            if ($valor instanceof \DateTimeInterface) {
                return $valor->format('d/m/Y');
            }

            $momento = strtotime((string) $valor);

            return $momento === false ? (string) $valor : date('d/m/Y', $momento);
        }

        if ($tipo === 'search') {
            return (int) $valor;
        }

        return (string) $valor;
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
     * Una lista de valores para `en`: un array, o un string separado por comas.
     *
     * @param  mixed  $valor
     * @return array<int, mixed>
     */
    protected static function lista_de_valores($valor): array
    {
        if (is_array($valor)) {
            $lista = $valor;
        } elseif (is_null($valor)) {
            $lista = [];
        } else {
            $lista = explode(',', (string) $valor);
        }

        $limpia = [];

        foreach ($lista as $uno) {
            if (is_array($uno)) {
                continue;
            }

            $uno = trim((string) $uno);

            if ($uno !== '') {
                $limpia[] = $uno;
            }
        }

        return $limpia;
    }

    /**
     * Una fecha del modelo normalizada a 'AAAA-MM-DD'. Acepta también dd/mm/aaaa, que es como la
     * escribe la persona; cualquier otra cosa es null (= "no se entiende", nunca 01/01/1970).
     *
     * @param  mixed  $valor
     * @return string|null
     */
    public static function dia_de($valor)
    {
        $texto = trim((string) $valor);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1] . '-' . $m[2] . '-' . $m[3] : null;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $texto, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $m[3] . '-' . $m[2] . '-' . $m[1] : null;
        }

        return null;
    }

    /**
     * Los comodines del LIKE escapados (molde: ConsultasSistemaIaHelper::resolver_articulo). Un
     * término real como "50%" o "cable_2" los trae adentro, y sin escaparlos "50%" matchea todo lo
     * que empieza con 50.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function escapar_like(string $texto): string
    {
        return addcslashes($texto, '%_\\');
    }

    /**
     * Respuesta de error con el motivo y con qué se puede hacer en vez.
     *
     * @param  string  $motivo
     * @param  array   $declaracion
     * @return array<string, mixed>
     */
    public static function error(string $motivo, array $declaracion): array
    {
        return [
            'error'          => $motivo,
            'campos_validos' => array_keys($declaracion['campos']),
        ];
    }

    /**
     * Una entidad fuera del catálogo. Se devuelve la lista de nombres para que el modelo pueda
     * corregir sin una vuelta más (los nombres solos: la lista con etiquetas es que_puedo_consultar).
     *
     * @param  string  $entidad
     * @return array<string, mixed>
     */
    public static function no_existe_la_entidad(string $entidad): array
    {
        return [
            'error'              => 'No se puede consultar "' . $entidad . '": no esta entre las entidades habilitadas. Busca la que corresponde con que_puedo_consultar (acepta `buscar`).',
            'entidades_validas'  => self::entidades(),
        ];
    }
}
