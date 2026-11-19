<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Mostrador\RecolectorBase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «DE LO QUE COMPRÉ ESTE MES, ¿CUÁNTO QUEDÓ EN STOCK? ¿QUÉ COMPRÉ MAL?» — LA HERRAMIENTA DE LECTURA
 * consultar_rendimiento_de_compras (misión asistente-diario-20261116, rama A, 30/10 #11).
 *
 * 🔴 EL CASO REAL (Ferre Total, 30/10/2026, conv. 31, 20:45-20:48). Pablo preguntó «De lo que compré este
 * mes, ¿cuánto quedó en stock?» y «¿qué compré mal?». El agente contestó que el sistema no guarda qué se
 * compró y cuándo, y dio una estimación global. Las compras SÍ tienen artículos y fechas; faltaba la
 * consulta que las cruzara con las ventas y el stock.
 *
 * Por cada artículo comprado en el período (renglones de `article_provider_order` de las compras del
 * dueño con `provider_orders.created_at` en el rango):
 *  - `comprado`: lo que entró, con el criterio de la compra (NewProviderOrderHelper::interpretar_cantidad_real():
 *    `received` si se completó —incluido 0—, si no `amount`);
 *  - `vendido_desde_la_compra`: unidades de `article_sale` de ventas reales (sin las contenedoras de la
 *    consolidación AFIP), terminadas y no borradas, cargadas desde la PRIMERA compra del artículo en el
 *    período hasta hoy;
 *  - `stock_actual`: el del artículo, o el del depósito si se pidió uno (address_article.amount);
 *  - `sin_rotar`: lo comprado que no se vendió y sigue en stock = max(0, min(stock, comprado − vendido));
 *  - `valor_sin_rotar`: `sin_rotar` × el costo unitario en pesos de HOY, con la MISMA expresión que
 *    valorizar_stock (ValorizacionDeStockIaHelper::expresion_costo_unitario()).
 *  - `invertido`: la parte del TOTAL de cada compra que le toca al artículo (misión asistente-diario-20261119,
 *    rama A, A1: antes era la suma cruda de renglones y en Ferre Total infló el mes de $34,7 M a $227 M).
 * Y, aparte, el TOTAL DE COMPRAS del período desde `provider_orders` (total_compras_en_pesos), que es el
 * «¿cuánto compré?».
 * Ordenado por valor sin rotar (lo que más plata dejó parada primero), con totales de TODOS los artículos.
 *
 * Todo en SQL (subconsultas agrupadas), sin cargar las compras ni las ventas en memoria: la lista viaja
 * topeada y los totales son de todo el conjunto.
 *
 * 🔴 EL COSTO ES DEL QUE LO PUEDE VER, como en valorizar_stock: sin el permiso `article.cost` no viajan el
 * costo, el valor sin rotar ni lo invertido, y se ordena por unidades sin rotar. 🔴 Y LA SUCURSAL: el
 * empleado que solo ve su sucursal (`article.stock_only_sucursal`) ve siempre la suya: sus compras, sus
 * ventas y su stock.
 *
 * Es SOLO LECTURA. Corre sin Auth (el dueño viene explícito).
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class RendimientoDeComprasIaHelper
{
    /** Los órdenes que se pueden pedir. */
    const ORDENES = ['valor_sin_rotar', 'sin_rotar', 'comprado', 'vendido'];

    /** Cuántos artículos viajan si no se pide otra cosa. */
    const LIMITE_POR_DEFECTO = 20;

    /** Techo de la lista: la respuesta viaja entera en cada vuelta del loop. */
    const LIMITE_MAXIMO = 100;

    /** Techo del rango, en días (como el resumen de ventas). */
    const TOPE_DIAS = 400;

    /**
     * LA HERRAMIENTA.
     *
     * @param  int  $owner_id  Dueño. Nunca Auth.
     * @param  AiConversation|null  $conversation  Para saber QUIÉN pregunta (costo y sucursal).
     * @param  array  $input  desde, hasta, proveedor, deposito, orden, limite.
     * @return array<string, mixed>
     */
    public static function consultar($owner_id, $conversation, array $input)
    {
        $owner = User::find((int) $owner_id);

        if (is_null($owner)) {

            return RespuestaDeCargaIa::error('No se encontró el negocio.');
        }

        // ------------------------------------------------------------------ período
        $rango = self::rango($input);

        if (RespuestaDeCargaIa::es_negativa($rango)) {

            return $rango;
        }

        list($inicio, $fin) = $rango;

        // ------------------------------------------------------------------ quién pregunta
        $ver_costo = ValorizacionDeStockIaHelper::puede_ver_costo($conversation);

        $deposito = self::deposito((int) $owner_id, $conversation, isset($input['deposito']) ? $input['deposito'] : null);

        if (RespuestaDeCargaIa::es_negativa($deposito)) {

            return $deposito;
        }

        // ------------------------------------------------------------------ proveedor
        $proveedor = null;

        if (isset($input['proveedor']) && trim((string) $input['proveedor']) !== '') {

            $proveedor = self::resolver_proveedor((int) $owner_id, (string) $input['proveedor']);

            if (RespuestaDeCargaIa::es_negativa($proveedor)) {

                return $proveedor;
            }
        }

        // ------------------------------------------------------------------ orden y límite
        $orden = isset($input['orden']) ? strtolower(trim((string) $input['orden'])) : '';

        if ($orden === '') {
            $orden = $ver_costo ? 'valor_sin_rotar' : 'sin_rotar';
        }

        if (!in_array($orden, self::ORDENES, true)) {

            return RespuestaDeCargaIa::error('No se puede ordenar por "' . $orden . '". Valen: ' . implode(', ', self::ORDENES) . '.');
        }

        if ($orden === 'valor_sin_rotar' && !$ver_costo) {
            $orden = 'sin_rotar';
        }

        $limite = isset($input['limite']) ? (int) $input['limite'] : self::LIMITE_POR_DEFECTO;
        $limite = $limite <= 0 ? self::LIMITE_POR_DEFECTO : min($limite, self::LIMITE_MAXIMO);

        // ------------------------------------------------------------------ la consulta
        /*
         * ⚠️ DEUDA DECLARADA (revisión independiente, 16/11/2026): el mismo builder se ejecuta dos veces, una
         * para los totales y otra para la lista topeada. Juntarlas en una sola pasada pide funciones de
         * ventana (SUM() OVER ()), que no están garantizadas en todos los motores de la flota (shared y VPS);
         * se deja así, que son dos consultas agrupadas y acotadas al período, no una por artículo.
         */
        $filas = self::consulta((int) $owner_id, $owner, $inicio, $fin, $proveedor, $deposito);

        $totales = DB::query()->fromSub($filas, 't')->selectRaw(
            'COUNT(*) as articulos, COALESCE(SUM(t.comprado), 0) as comprado, COALESCE(SUM(t.vendido), 0) as vendido, '
            . 'COALESCE(SUM(t.sin_rotar), 0) as sin_rotar, COALESCE(SUM(t.valor_sin_rotar), 0) as valor_sin_rotar, '
            . 'COALESCE(SUM(t.invertido), 0) as invertido, COALESCE(SUM(t.renglones_en_otra_moneda), 0) as en_otra_moneda, '
            . 'COALESCE(SUM(CASE WHEN t.sin_rotar > 0 AND t.valor_sin_rotar IS NULL THEN 1 ELSE 0 END), 0) as sin_costo'
        )->first();

        $ordenes = [
            'valor_sin_rotar' => 'COALESCE(r.valor_sin_rotar, -1) DESC, r.sin_rotar DESC',
            'sin_rotar'       => 'r.sin_rotar DESC, COALESCE(r.valor_sin_rotar, -1) DESC',
            'comprado'        => 'r.comprado DESC',
            'vendido'         => 'r.vendido DESC',
        ];

        $lista = DB::query()->fromSub($filas, 'r')
            ->orderByRaw($ordenes[$orden] . ', r.article_id ASC')
            ->limit($limite)
            ->get();

        $articulos = [];

        foreach ($lista as $fila) {
            $articulos[] = self::fila($fila, $ver_costo);
        }

        // ------------------------------------------------------------------ la respuesta
        $respuesta = [
            'desde'                              => $inicio->format('Y-m-d'),
            'hasta'                              => $fin->format('Y-m-d'),
            'proveedor'                          => is_null($proveedor) ? null : $proveedor['nombre'],
            'deposito'                           => is_null($deposito) ? null : $deposito['nombre'],
            'articulos_comprados'                => (int) $totales->articulos,
            'unidades_compradas'                 => round((float) $totales->comprado, 2),
            'unidades_vendidas_desde_la_compra'  => round((float) $totales->vendido, 2),
            'unidades_sin_rotar'                 => round((float) $totales->sin_rotar, 2),
        ];

        if ($ver_costo) {
            /*
             * 🔴 Misión asistente-diario-20261119 (rama A, A1): EL TOTAL DE COMPRAS va primero y es el
             * «cuánto compré». Sale de `provider_orders` (total_de_compras()), no de sumar renglones: en
             * Ferre Total la suma de renglones dio $227 M para un mes de $34,7 M y el asistente le dijo al
             * dueño que estaba comprando muy por encima de lo que vendía.
             */
            $compras = self::total_de_compras((int) $owner_id, $owner, $inicio, $fin, $proveedor, $deposito);

            $respuesta['compras_del_periodo'] = $compras['cantidad'];
            $respuesta['total_compras_en_pesos'] = $compras['total_en_pesos'];

            if ($compras['en_otra_moneda'] > 0) {
                $respuesta['compras_en_otra_moneda'] = $compras['en_otra_moneda'];
            }

            if ($compras['sin_mover_stock'] > 0) {
                $respuesta['compras_que_no_movieron_stock'] = $compras['sin_mover_stock'];
            }

            $respuesta['valor_sin_rotar_a_costo'] = round((float) $totales->valor_sin_rotar, 2);
            // Lo invertido en los artículos de la lista de compras que movieron stock: el total de cada
            // compra repartido entre sus renglones (consulta()). Nunca supera total_compras_en_pesos.
            $respuesta['invertido_en_pesos'] = round((float) $totales->invertido, 2);
            $respuesta['articulos_sin_rotar_sin_costo'] = (int) $totales->sin_costo;
        } else {
            $respuesta['sin_costos'] = 'Quien pregunta no tiene permiso para ver costos: van las unidades, sin plata.';
        }

        if ((int) $totales->en_otra_moneda > 0) {
            $respuesta['renglones_en_dolares'] = (int) $totales->en_otra_moneda;
        }

        $respuesta['ordenado_por'] = $orden;
        $respuesta['articulos_en_esta_lista'] = count($articulos);
        $respuesta['articulos'] = $articulos;
        $respuesta['nota'] = self::nota($ver_costo, (int) $totales->en_otra_moneda > 0, !is_null($deposito));

        return $respuesta;
    }

    /**
     * El período: `desde` y `hasta` en AAAA-MM-DD; sin ninguno, el mes en curso hasta hoy; con uno solo,
     * el otro es hoy (o el mismo día).
     *
     * @param  array  $input
     * @return array  [Carbon inicio, Carbon fin] o la respuesta negativa.
     */
    protected static function rango(array $input)
    {
        $desde = isset($input['desde']) ? trim((string) $input['desde']) : '';
        $hasta = isset($input['hasta']) ? trim((string) $input['hasta']) : '';

        if ($desde === '' && $hasta === '') {

            return [Carbon::today()->startOfMonth(), Carbon::today()];
        }

        $valido = function ($texto) {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) && strtotime($texto) !== false;
        };

        if (($desde !== '' && !$valido($desde)) || ($hasta !== '' && !$valido($hasta))) {

            return RespuestaDeCargaIa::error('desde y hasta van en formato AAAA-MM-DD.');
        }

        $inicio = $desde === '' ? Carbon::parse($hasta)->startOfDay() : Carbon::parse($desde)->startOfDay();
        $fin = $hasta === '' ? Carbon::today() : Carbon::parse($hasta)->startOfDay();

        if ($inicio > $fin) {

            return RespuestaDeCargaIa::error('desde (' . $inicio->format('Y-m-d') . ') es posterior a hasta (' . $fin->format('Y-m-d') . ').');
        }

        if ($inicio->diffInDays($fin) + 1 > self::TOPE_DIAS) {

            return RespuestaDeCargaIa::error('El rango tiene más de ' . self::TOPE_DIAS . ' días. Acotalo.');
        }

        return [$inicio, $fin];
    }

    /**
     * El depósito: el pedido por nombre (ValorizacionDeStockIaHelper::resolver_deposito()) o, para el
     * empleado que solo ve su sucursal, SIEMPRE la suya (mismo recorte que valorizar_stock).
     *
     * @param  int  $owner_id
     * @param  AiConversation|null  $conversation
     * @param  mixed  $pedido
     * @return array|null  {address_id, nombre}, null (todo el negocio) o la respuesta negativa.
     */
    protected static function deposito($owner_id, $conversation, $pedido)
    {
        $deposito = null;

        $texto = is_null($pedido) ? '' : trim((string) $pedido);

        if ($texto !== '' && mb_strtolower($texto) !== 'todos') {

            $deposito = ValorizacionDeStockIaHelper::resolver_deposito($owner_id, $texto);

            if (RespuestaDeCargaIa::es_negativa($deposito)) {

                return $deposito;
            }
        }

        $persona = ValorizacionDeStockIaHelper::persona($conversation);

        $solo_su_sucursal = !is_null($persona) && !PermisosIaHelper::es_admin($persona)
            && PermisosIaHelper::puede($persona, FichaArticuloIaHelper::SOLO_MI_SUCURSAL);

        if (!$solo_su_sucursal) {

            return $deposito;
        }

        $suya = is_null($persona->address_id) ? null : DB::table('addresses')
            ->where('id', (int) $persona->address_id)
            ->where('user_id', $owner_id)
            ->first(['id', 'street']);

        if (is_null($suya)) {

            return RespuestaDeCargaIa::error('Quien pregunta solo puede ver su sucursal y no tiene ninguna asignada. Decíselo tal cual: la tiene que asignar el dueño.');
        }

        if (!is_null($deposito) && (int) $deposito['address_id'] !== (int) $suya->id) {

            return RespuestaDeCargaIa::error('Quien pregunta solo puede ver su sucursal (' . $suya->street . '), no ' . $deposito['nombre'] . '. Decíselo tal cual.');
        }

        return ['address_id' => (int) $suya->id, 'nombre' => (string) $suya->street];
    }

    /**
     * El proveedor por nombre o razón social, entre los del negocio: el exacto gana; si no, uno solo
     * que contenga el texto. Con varios se pregunta; con ninguno, error.
     *
     * @param  int  $owner_id
     * @param  string  $texto
     * @return array  {id, nombre} o la respuesta negativa.
     */
    protected static function resolver_proveedor($owner_id, $texto)
    {
        $texto = trim($texto);

        $exacto = DB::table('providers')
            ->where('user_id', $owner_id)
            ->whereNull('deleted_at')
            ->where('name', $texto)
            ->orderBy('id')
            ->first(['id', 'name']);

        if (!is_null($exacto)) {

            return ['id' => (int) $exacto->id, 'nombre' => (string) $exacto->name];
        }

        $escapado = addcslashes($texto, '%_\\');

        $candidatos = DB::table('providers')
            ->where('user_id', $owner_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($escapado) {
                $q->where('name', 'LIKE', '%' . $escapado . '%')
                    ->orWhere('razon_social', 'LIKE', '%' . $escapado . '%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name']);

        if (count($candidatos) === 1) {

            return ['id' => (int) $candidatos[0]->id, 'nombre' => (string) $candidatos[0]->name];
        }

        if (!count($candidatos)) {

            return RespuestaDeCargaIa::error('No encontré un proveedor que se llame "' . $texto . '". Preguntale el nombre exacto o sacá proveedor.');
        }

        $nombres = [];

        foreach ($candidatos as $candidato) {
            $nombres[] = (string) $candidato->name;
        }

        return RespuestaDeCargaIa::faltan(['cuál proveedor: hay varios que encajan con "' . $texto . '"'], ['proveedores' => $nombres]);
    }

    /**
     * EL VALOR DE UN RENGLÓN DE COMPRA, en SQL y con la MISMA cuenta que el total de la compra
     * (NewProviderOrderHelper::get_total_article(), que es la que llena `provider_orders.sub_total`):
     *
     *   costo del renglón (× dólar si el renglón está en dólares y la compra en pesos: el del proveedor
     *   si tiene uno cargado > 0, si no el del negocio)
     *   × cantidad efectiva (received completado —incluido 0—, si no amount)
     *   (si eso da 0 y el renglón trae `price`, price × cantidad efectiva)
     *   × presentación del artículo (si no es null: la compra se carga por bulto y el costo es por unidad)
     *   × (1 − descuento del renglón / 100).
     *
     * 🔴 Misión asistente-diario-20261119 (rama A, A1). Antes lo invertido salía de
     * cantidad × `apo.cost` × (1 − descuento), SIN la presentación, SIN el price de respaldo, SIN pasar a
     * pesos los renglones en dólares y, sobre todo, SIN mirar el total de la compra: los descuentos de
     * cabecera, los costos extra, el IVA sumado y las compras cuyo total sale de sus facturas
     * (`total_from_provider_order_afip_tickets`) no estaban. En Ferre Total (18/11) dio $227 M para un mes
     * cuyas 48 compras suman $34,7 M. Este renglón ya no se suma tal cual: es el PESO con el que el total
     * de cada compra se reparte entre sus artículos (consulta()).
     *
     * Espera los alias `apo` (article_provider_order), `po` (provider_orders), `prov_compra` (providers de
     * la compra) y `art_compra` (articles del renglón, por la presentación).
     *
     * @param  float|null  $dolar_del_negocio  `users.dollar` del dueño (null si no tiene).
     * @return string
     */
    protected static function expresion_renglon($dolar_del_negocio)
    {
        // El dólar va como literal numérico (sale de un float casteado: no hay inyección posible) para no
        // depender del orden de los bindings en subconsultas anidadas.
        $dolar = is_null($dolar_del_negocio) ? 'NULL' : sprintf('%.6F', (float) $dolar_del_negocio);

        $cantidad = 'COALESCE(apo.received, apo.amount, 0)';

        // Igual que get_total_article(): convierte solo si el renglón está en dólares Y la compra es en
        // pesos (moneda_id = 1); el dólar del proveedor gana si es > 0. Sin ningún dólar, queda el costo
        // crudo (lo mismo hace el código de la compra cuando el negocio no cargó el suyo).
        $costo = 'COALESCE(apo.cost, 0) * (CASE WHEN COALESCE(apo.cost_in_dollars, 0) = 1 AND COALESCE(po.moneda_id, 1) = 1'
            . ' THEN COALESCE(NULLIF(prov_compra.dolar, 0), ' . $dolar . ', 1) ELSE 1 END)';

        $bruto = '(CASE WHEN (' . $costo . ') * ' . $cantidad . ' = 0 AND COALESCE(apo.price, 0) > 0'
            . ' THEN apo.price * ' . $cantidad . ' ELSE (' . $costo . ') * ' . $cantidad . ' END)';

        return '(' . $bruto . ' * (CASE WHEN art_compra.presentacion IS NULL THEN 1 ELSE art_compra.presentacion END)'
            . ' * (1 - COALESCE(apo.discount, 0) / 100))';
    }

    /**
     * Las compras del período (con los mismos filtros de proveedor y depósito), agrupadas por compra, con
     * su total y la suma de sus renglones. Es la base de dos cosas:
     *  - el TOTAL DE COMPRAS del período (total_de_compras()), que es lo que el negocio compró;
     *  - el reparto de ese total entre los artículos (consulta()).
     *
     * El total de cada compra es `provider_orders.total` (el que calcula set_totales(): artículos menos
     * descuentos de renglón y de cabecera, más costos extra, más el IVA si la compra lo suma, o el total de
     * sus facturas). Una compra SIN total guardado (null: cargada por un camino que no corrió
     * set_totales()) vale la suma de sus renglones, para que no desaparezca.
     *
     * @param  int  $owner_id
     * @param  User  $owner
     * @param  Carbon  $inicio
     * @param  Carbon  $fin
     * @param  array|null  $proveedor
     * @param  array|null  $deposito
     * @param  bool  $solo_las_que_mueven_stock  true para el reparto por artículo (mismo universo que lo comprado).
     * @return \Illuminate\Database\Query\Builder  Columnas: provider_order_id, moneda_id, total_guardado, suma_renglones, update_stock.
     */
    protected static function compras_del_periodo($owner_id, User $owner, Carbon $inicio, Carbon $fin, $proveedor, $deposito, $solo_las_que_mueven_stock)
    {
        $dolar = (float) $owner->dollar > 0 ? (float) $owner->dollar : null;

        $compras = DB::table('provider_orders as po')
            ->leftJoin('article_provider_order as apo', 'apo.provider_order_id', '=', 'po.id')
            ->leftJoin('articles as art_compra', 'art_compra.id', '=', 'apo.article_id')
            ->leftJoin('providers as prov_compra', 'prov_compra.id', '=', 'po.provider_id')
            ->where('po.user_id', $owner_id)
            ->where('po.created_at', '>=', $inicio->copy()->startOfDay()->format('Y-m-d H:i:s'))
            ->where('po.created_at', '<', $fin->copy()->startOfDay()->addDay()->format('Y-m-d H:i:s'))
            ->groupBy('po.id', 'po.moneda_id', 'po.total', 'po.update_stock')
            ->selectRaw(
                'po.id as provider_order_id, COALESCE(po.moneda_id, 1) as moneda_id, po.total as total_guardado, '
                // Los renglones de la compra que no tienen artículo (left join vacío) suman 0.
                . 'COALESCE(SUM(CASE WHEN apo.id IS NULL THEN 0 ELSE ' . self::expresion_renglon($dolar) . ' END), 0) as suma_renglones, '
                . 'COALESCE(po.update_stock, 1) as update_stock'
            );

        if ($solo_las_que_mueven_stock) {
            $compras->where(function ($q) {
                $q->whereNull('po.update_stock')->orWhere('po.update_stock', 1);
            });
        }

        if (!is_null($proveedor)) {
            $compras->where('po.provider_id', (int) $proveedor['id']);
        }

        if (!is_null($deposito)) {
            $compras->where('po.address_id', (int) $deposito['address_id']);
        }

        return $compras;
    }

    /**
     * 🔴 EL TOTAL DE COMPRAS DEL PERÍODO (misión asistente-diario-20261119, rama A, A1): cuántas compras y
     * cuánto suman, desde `provider_orders` (lo mismo que suma el informe del mostrador,
     * RecolectorDia::compras()). Es el número de «¿cuánto compré este mes?». Entran TODAS las compras del
     * período, también las que no movieron stock (esas no entran en lo comprado por artículo, pero se
     * pagaron igual), separadas en su propio contador.
     *
     * @param  int  $owner_id
     * @param  User  $owner
     * @param  Carbon  $inicio
     * @param  Carbon  $fin
     * @param  array|null  $proveedor
     * @param  array|null  $deposito
     * @return array{cantidad:int, total_en_pesos:float, en_otra_moneda:int, sin_mover_stock:int}
     */
    protected static function total_de_compras($owner_id, User $owner, Carbon $inicio, Carbon $fin, $proveedor, $deposito)
    {
        $pesos = implode(',', array_map('intval', RecolectorBase::MONEDAS_PESOS));

        $fila = DB::query()
            ->fromSub(self::compras_del_periodo($owner_id, $owner, $inicio, $fin, $proveedor, $deposito, false), 'cp')
            ->selectRaw(
                'COUNT(*) as cantidad, '
                . 'COALESCE(SUM(CASE WHEN cp.moneda_id IN (' . $pesos . ') THEN COALESCE(cp.total_guardado, cp.suma_renglones) ELSE 0 END), 0) as total_en_pesos, '
                . 'COALESCE(SUM(CASE WHEN cp.moneda_id IN (' . $pesos . ') THEN 0 ELSE 1 END), 0) as en_otra_moneda, '
                . 'COALESCE(SUM(CASE WHEN cp.update_stock = 0 THEN 1 ELSE 0 END), 0) as sin_mover_stock'
            )
            ->first();

        return [
            'cantidad'        => (int) $fila->cantidad,
            'total_en_pesos'  => round((float) $fila->total_en_pesos, 2),
            'en_otra_moneda'  => (int) $fila->en_otra_moneda,
            'sin_mover_stock' => (int) $fila->sin_mover_stock,
        ];
    }

    /**
     * LA CONSULTA, en tres pisos de SQL (sin nada en memoria):
     *  1. `c`: las compras del período agrupadas por artículo (comprado, primera compra, invertido);
     *  2. `r`: cada artículo con su stock, lo vendido desde la primera compra (subconsulta correlacionada)
     *     y su costo unitario de hoy;
     *  3. lo sin rotar y su valor.
     *
     * 🔴 LO INVERTIDO (misión asistente-diario-20261119, rama A, A1): es la parte del TOTAL DE CADA COMPRA que
     * le toca al artículo, repartida en proporción al valor de su renglón (expresion_renglon()):
     *   invertido del renglón = total de la compra × valor del renglón / suma de los renglones de la compra.
     * Así la suma de lo invertido de todos los artículos de una compra es su total (con descuentos de
     * cabecera, costos extra, IVA y totales por factura incluidos), y la suma del período nunca puede ser
     * mayor que lo que de verdad se compró. Una compra sin total guardado usa la suma de sus renglones; una
     * compra cuyos renglones suman 0 (sin costos) no le asigna plata a ningún artículo (su total igual
     * está en total_de_compras()). Solo en pesos: las compras en otra moneda se cuentan aparte.
     *
     * @param  int  $owner_id
     * @param  User  $owner
     * @param  Carbon  $inicio
     * @param  Carbon  $fin
     * @param  array|null  $proveedor
     * @param  array|null  $deposito
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta($owner_id, User $owner, Carbon $inicio, Carbon $fin, $proveedor, $deposito)
    {
        $pesos = implode(',', array_map('intval', RecolectorBase::MONEDAS_PESOS));

        // Lo comprado: el mismo criterio que mueve el stock (received completado manda, si no amount).
        $cantidad = 'COALESCE(apo.received, apo.amount)';

        $dolar = (float) $owner->dollar > 0 ? (float) $owner->dollar : null;

        $en_pesos = 'tot.moneda_id IN (' . $pesos . ')';

        // La parte del total de la compra que le toca a este renglón (ver el docblock).
        $parte = 'CASE WHEN tot.total_guardado IS NULL THEN ' . self::expresion_renglon($dolar)
            . ' WHEN tot.suma_renglones = 0 THEN 0'
            . ' ELSE ' . self::expresion_renglon($dolar) . ' * tot.total_guardado / tot.suma_renglones END';

        $totales_por_compra = self::compras_del_periodo($owner_id, $owner, $inicio, $fin, $proveedor, $deposito, true);

        $compras = DB::table('article_provider_order as apo')
            ->join('provider_orders as po', 'po.id', '=', 'apo.provider_order_id')
            ->leftJoin('articles as art_compra', 'art_compra.id', '=', 'apo.article_id')
            ->leftJoin('providers as prov_compra', 'prov_compra.id', '=', 'po.provider_id')
            // Solo las compras del período que mueven stock, con los filtros de proveedor y depósito: el
            // join con sus totales ya las acota (compras_del_periodo()).
            ->joinSub($totales_por_compra, 'tot', 'tot.provider_order_id', '=', 'po.id')
            ->groupBy('apo.article_id')
            ->selectRaw(
                'apo.article_id as article_id, '
                . 'SUM(' . $cantidad . ') as comprado, '
                . 'MIN(po.created_at) as primera_compra, '
                . 'MAX(po.created_at) as ultima_compra, '
                . 'COUNT(DISTINCT po.id) as compras, '
                . 'SUM(CASE WHEN ' . $en_pesos . ' THEN ' . $parte . ' ELSE 0 END) as invertido, '
                . 'SUM(CASE WHEN ' . $en_pesos . ' THEN 0 ELSE 1 END) as renglones_en_otra_moneda'
            );

        // Lo vendido desde la primera compra del período: ventas reales, terminadas, no borradas.
        $vendido = '(SELECT COALESCE(SUM(asale.amount), 0) FROM article_sale asale'
            . ' JOIN sales s ON s.id = asale.sale_id'
            . ' WHERE asale.article_id = c.article_id'
            . ' AND s.user_id = ?'
            . ' AND s.deleted_at IS NULL'
            . ' AND s.terminada = 1'
            . ' AND (s.is_consolidacion_facturacion IS NULL OR s.is_consolidacion_facturacion = 0)'
            . ' AND s.created_at >= c.primera_compra'
            . (is_null($deposito) ? '' : ' AND s.address_id = ?')
            . ')';

        $bindings_vendido = [$owner_id];

        if (!is_null($deposito)) {
            $bindings_vendido[] = (int) $deposito['address_id'];
        }

        $stock = is_null($deposito) ? 'articles.stock' : 'stock_del_deposito.amount';

        $preparado = ['dolar' => (float) $owner->dollar > 0 ? (float) $owner->dollar : null];

        // El costo de hoy, en pesos, con la expresión de valorizar_stock; NULL si el artículo no tiene costo.
        $costo = 'CASE WHEN articles.cost IS NULL OR articles.cost <= 0 THEN NULL ELSE '
            . ValorizacionDeStockIaHelper::expresion_costo_unitario($preparado) . ' END';

        $por_articulo = DB::query()->fromSub($compras, 'c')
            ->join('articles', 'articles.id', '=', 'c.article_id')
            ->leftJoin('providers', 'providers.id', '=', 'articles.provider_id')
            ->where('articles.user_id', $owner_id)
            ->whereNull('articles.deleted_at');

        if (!is_null($deposito)) {
            $address_id = (int) $deposito['address_id'];

            $por_articulo->leftJoin('address_article as stock_del_deposito', function ($join) use ($address_id) {
                $join->on('stock_del_deposito.article_id', '=', 'articles.id')
                    ->where('stock_del_deposito.address_id', '=', $address_id);
            });
        }

        $por_articulo->selectRaw(
            'c.article_id, c.comprado, c.primera_compra, c.ultima_compra, c.compras, c.invertido, c.renglones_en_otra_moneda, '
            . 'articles.name as nombre, providers.name as proveedor, '
            . $stock . ' as stock_actual, '
            . $vendido . ' as vendido, '
            . '(' . $costo . ') as costo_unitario',
            array_merge($bindings_vendido, [$preparado['dolar']])
        );

        $sin_rotar = 'GREATEST(0, LEAST(COALESCE(r0.stock_actual, 0), r0.comprado - r0.vendido))';

        return DB::query()->fromSub($por_articulo, 'r0')->selectRaw(
            'r0.*, ' . $sin_rotar . ' as sin_rotar, '
            . '(' . $sin_rotar . ' * r0.costo_unitario) as valor_sin_rotar'
        );
    }

    /**
     * Un artículo de la lista, con o sin la plata según el permiso.
     *
     * @param  object  $fila
     * @param  bool  $ver_costo
     * @return array<string, mixed>
     */
    protected static function fila($fila, $ver_costo)
    {
        $comprado = round((float) $fila->comprado, 2);
        $vendido = round((float) $fila->vendido, 2);

        $articulo = [
            'article_id'              => (int) $fila->article_id,
            'nombre'                  => (string) $fila->nombre,
            'proveedor'               => is_null($fila->proveedor) ? null : (string) $fila->proveedor,
            'compras'                 => (int) $fila->compras,
            'primera_compra'          => substr((string) $fila->primera_compra, 0, 10),
            'ultima_compra'           => substr((string) $fila->ultima_compra, 0, 10),
            'comprado'                => $comprado,
            'vendido_desde_la_compra' => $vendido,
            'stock_actual'            => is_null($fila->stock_actual) ? null : round((float) $fila->stock_actual, 2),
            'sin_rotar'               => round((float) $fila->sin_rotar, 2),
            'porcentaje_vendido'      => $comprado > 0 ? round(min($vendido, $comprado) / $comprado * 100, 1) : null,
        ];

        if ($ver_costo) {
            $articulo['costo_unitario'] = is_null($fila->costo_unitario) ? null : round((float) $fila->costo_unitario, 2);
            $articulo['valor_sin_rotar'] = is_null($fila->valor_sin_rotar) ? null : round((float) $fila->valor_sin_rotar, 2);
            $articulo['invertido'] = round((float) $fila->invertido, 2);
        }

        return $articulo;
    }

    /**
     * Lo que el modelo tiene que decir sin que se lo pregunten.
     *
     * @param  bool  $ver_costo
     * @param  bool  $hay_dolares
     * @param  bool  $con_deposito
     * @return string
     */
    protected static function nota($ver_costo, $hay_dolares, $con_deposito)
    {
        $nota = 'sin_rotar es lo comprado en el periodo que no se vendio y sigue en stock (nunca mas que el stock de hoy). '
            . 'vendido_desde_la_compra cuenta las ventas desde la PRIMERA compra del articulo en el periodo, y puede incluir unidades de stock anterior; no descuenta devoluciones. '
            . 'Solo entran las compras que actualizan stock. ';

        if ($ver_costo) {
            $nota .= 'Lo que se compro en el periodo es total_compras_en_pesos (compras_del_periodo compras, desde el total de cada compra): ese es el numero de "cuanto compre", nunca la suma de los articulos. '
                . 'invertido es la parte del total de cada compra que le toca a cada articulo (con descuentos, costos extra e IVA de la compra), en pesos, y nunca supera el total de compras. '
                . 'valor_sin_rotar es sin_rotar por el costo de HOY en pesos (el mismo de valorizar_stock). Un articulo sin costo cargado va con valor null: decilo, no lo cuentes como cero. ';
        }

        if ($hay_dolares) {
            $nota .= 'Hay compras en otra moneda (dolares) que no entran en invertido ni en el total en pesos. ';
        }

        if ($con_deposito) {
            $nota .= 'Con deposito: las compras, las ventas y el stock son los de esa sucursal. ';
        }

        return trim($nota . 'Contesta con estos numeros, sin recalcular; para la lista completa, subi el limite (hasta ' . self::LIMITE_MAXIMO . ').');
    }
}
