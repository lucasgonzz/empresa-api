<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «¿CUÁNTA PLATA HAY EN EL DEPÓSITO?» Y «PASAME LOS ARTÍCULOS EN UN EXCEL» — LAS CUENTAS LAS HACE EL
 * SERVIDOR (misión asistente-mejoras-4-dias, 16/11/2026, P5: V9 y V16 del análisis de los 4 días).
 *
 * 🔴 LOS CASOS REALES.
 *  - V9 (Pesca, conv 20 y 22): «cuánta plata hay de venta con todo el stock del depósito» y después
 *    «Si por favor» al Excel. El agente dijo «Genero el Excel con las 948 filas» y no llegó nada:
 *    exportar_excel exige que el MODELO escriba cada fila en la llamada, y con 948 filas se le acaba la
 *    respuesta. Tampoco había con qué sumar stock × precio: lo hacía de cabeza, sobre páginas.
 *  - V16 (Pesca, conv 18): «¿te fijás si todos los precios de Albatros están bien?». Revisó por páginas
 *    de 100 y cambió las cifras entre una respuesta y la otra (Okinawa 210: 50.638 y después 29.109).
 *
 * Desde esta misión hay dos herramientas de LECTURA, en los tres canales:
 *  - `valorizar_stock` (valorizar()): stock × costo y stock × precio en UNA consulta agregada (SUM en
 *    SQL, nunca artículo por artículo), en total y, si se pide, por rubro, proveedor o depósito. Con los
 *    mismos filtros que contar_articulos_por_filtro y con la auditoría de precios (cuántos con stock no
 *    tienen precio, no tienen costo o se venden a precio menor o igual al costo).
 *  - `exportar_articulos_excel` (exportar_excel()): el .xlsx lo arma el servidor desde un filtro. El
 *    modelo manda filtros y columnas; las filas las lee el servidor, por lotes (chunkById), y el archivo
 *    sale por el mismo canal de siempre (ArchivosDelAgenteIaHelper: disco privado, link con token, 7 días).
 *
 * LAS CUENTAS, igual que el informe de Inventario (InventoryPerformanceHelper::procesar_articulos()):
 *  - Solo los artículos ACTIVOS con stock MAYOR A CERO suman al valor (un stock negativo no es plata:
 *    se cuenta aparte, en `con_stock_negativo`).
 *  - El costo es el normalizado del Inventario (InventoryPerformanceHelper::costo_unitario_normalizado():
 *    × presentación y ÷ unidades individuales) y, si está en dólares, pasado a pesos con el dólar del
 *    proveedor o el de la cuenta (mismo criterio que RecolectorStock del Mostrador). Un artículo sin
 *    costo NO suma 0 a escondidas: queda afuera del valor a costo y se cuenta en `con_stock_sin_costo`.
 *  - El precio es el de HOY con el resolvedor único (ArticlePricesHelper::resolver_precio_de_venta()):
 *    sin listas, `final_price`; con listas, el de la lista de position más alta (desempate por id) y, si
 *    ese pivote no tiene precio, `final_price`. Acá va como subconsulta correlacionada, la misma cascada.
 *  - Con depósito, el stock es el de ese depósito (address_article.amount), no el total.
 *
 * 🔴 EL COSTO ES DEL QUE LO PUEDE VER. En la pantalla un empleado ve costos solo con el permiso
 * `article.cost` (o siendo administrador). Si quien pregunta no lo tiene, la respuesta no trae nada del
 * costo (ni el valor a costo, ni la columna en el Excel) y lo dice. Lo ven el dueño y el administrador
 * (también por su WhatsApp y por el MCP, que guardan la persona); sin persona comprobada, nadie.
 *
 * Es SOLO LECTURA: no toca ningún dato del negocio. Corre sin Auth (el dueño viene explícito).
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class ValorizacionDeStockIaHelper
{
    /** Las agrupaciones de valorizar_stock. */
    const AGRUPACIONES = ['ninguna', 'rubro', 'proveedor', 'deposito'];

    /** Cuántos grupos viajan en la respuesta (los de más valor primero; el total es de todos). */
    const TOPE_GRUPOS = 40;

    /** Techo de filas del Excel armado por el servidor. Más que esto, se pide filtrar (nunca se corta callado). */
    const MAX_FILAS_DEL_EXCEL = 15000;

    /** De a cuántos artículos se leen las filas del Excel. */
    const LOTE = 1000;

    /** El permiso de la pantalla para ver costos (PermissionsTableSeeder: «Ver costos de los articulos»). */
    const PERMISO_COSTO = 'article.cost';

    /** Los recortes de la auditoría de precios (V16) que también sirven de filtro del Excel. */
    const SOLO = ['con_stock', 'sin_stock', 'stock_negativo', 'sin_precio', 'sin_costo', 'precio_menor_o_igual_al_costo'];

    /**
     * Las columnas que se pueden pedir en el Excel: clave → [etiqueta, formato, de_costo].
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    const COLUMNAS_EXCEL = [
        'nombre'              => ['Artículo', 'texto', false],
        'codigo'              => ['Código de barras', 'texto', false],
        'codigo_de_proveedor' => ['Código de proveedor', 'texto', false],
        'rubro'               => ['Rubro', 'texto', false],
        'proveedor'           => ['Proveedor', 'texto', false],
        'marca'               => ['Marca', 'texto', false],
        'stock'               => ['Stock', 'numero', false],
        'costo'               => ['Costo (en pesos)', 'moneda', true],
        'precio'              => ['Precio', 'moneda', false],
        'valor_a_costo'       => ['Valor a costo', 'moneda', true],
        'valor_a_precio'      => ['Valor a precio', 'moneda', false],
        'en_tienda'           => ['En tienda', 'texto', false],
        /*
         * Misión asistente-diario-20261117 (parte B, M1). Truvari (conv. 36) pidió «costo, precio de venta y
         * qué margen de ganancia tiene cada artículo» y el Excel salió sin margen: no había columna. Las dos
         * son DE COSTO (sin el permiso `article.cost` no salen) y se calculan en columnas_de_margen().
         */
        'margen'              => ['Margen % sobre costo', 'porcentaje', true],
        'ganancia_por_unidad' => ['Ganancia por unidad', 'moneda', true],
        /*
         * Misión asistente-diario-20261119 (rama A, M2), AL FINAL para no mover las de antes. Ferre Total
         * (18/11, conv. 72, msgs 693-699) pidió el Excel de 145 artículos «y agregá categoría y subcategoría y
         * margen de ganancia»: este exportador no tenía subrubro, el asistente armó el archivo a mano con
         * exportar_excel leyendo `price` (el precio MANUAL, vacío en los 145: el de venta es `final_price`) y
         * el Excel salió con el precio en 0. Con estas dos ya no hace falta armarlo a mano:
         *  - `subrubro`: la subcategoría (sub_categories.name); `rubro` es la categoría.
         *  - `margen_cargado`: el % de ganancia CARGADO en el artículo (articles.percentage_gain), el número
         *    que el dueño ve en la ficha y llama «margen de ganancia». De costo, como las otras de margen.
         */
        'subrubro'            => ['Subrubro', 'texto', false],
        'margen_cargado'      => ['Margen de ganancia cargado %', 'porcentaje', true],
    ];

    /**
     * Las columnas si el modelo no pide otras. El margen y la ganancia van AL FINAL (M1, 17/11/2026) para
     * no correr de lugar las columnas que ya conocían quienes usaban este Excel.
     */
    const COLUMNAS_POR_DEFECTO = ['nombre', 'codigo', 'rubro', 'proveedor', 'stock', 'costo', 'precio', 'valor_a_costo', 'valor_a_precio', 'margen', 'ganancia_por_unidad'];

    /** Las columnas que necesitan el precio NETO de cada artículo (ver columnas_de_margen()). */
    const COLUMNAS_DE_MARGEN = ['margen', 'ganancia_por_unidad'];

    /**
     * Herramienta valorizar_stock.
     *
     * @param  int  $owner_id  Dueño. Nunca Auth.
     * @param  AiConversation|null  $conversation  Para saber QUIÉN pregunta (el costo).
     * @param  array  $input  filtros, deposito, agrupar_por.
     * @return array<string, mixed>
     */
    public static function valorizar($owner_id, $conversation, array $input): array
    {
        $preparado = self::preparar((int) $owner_id, $conversation, $input);

        if (isset($preparado['ok']) && $preparado['ok'] === false) {

            return $preparado;
        }

        $agrupar = isset($input['agrupar_por']) ? (string) $input['agrupar_por'] : 'ninguna';

        if (!in_array($agrupar, self::AGRUPACIONES, true)) {

            return RespuestaDeCargaIa::error('agrupar_por tiene que ser ' . implode(', ', self::AGRUPACIONES) . '.');
        }

        if ($agrupar === 'deposito' && $preparado['solo_su_sucursal']) {

            return RespuestaDeCargaIa::error('Quien pregunta solo puede ver el stock de su sucursal (' . $preparado['deposito']['nombre'] . '): no hay otros depósitos para comparar. Volvé a llamar sin agrupar por depósito.');
        }

        if ($agrupar === 'deposito' && !is_null($preparado['deposito'])) {

            return RespuestaDeCargaIa::error('Con un depósito elegido no hay nada que agrupar por depósito: sacá deposito o agrupá por rubro o proveedor.');
        }

        $ver_costo = $preparado['ver_costo'];

        $totales = self::agregado($preparado, null);

        $respuesta = [
            'ok'                  => true,
            'filtros_legibles'    => $preparado['legibles'],
            'deposito'            => is_null($preparado['deposito']) ? 'todos (stock total del artículo)' : $preparado['deposito']['nombre'],
            'articulos_con_stock' => (int) $totales->con_stock,
            'unidades_en_stock'   => round((float) $totales->unidades, 2),
            'valor_a_precio'      => round((float) $totales->valor_a_precio, 2),
            'con_stock_sin_precio' => (int) $totales->sin_precio,
        ];

        if ($ver_costo) {

            $respuesta['valor_a_costo'] = round((float) $totales->valor_a_costo, 2);
            $respuesta['con_stock_sin_costo'] = (int) $totales->sin_costo;
            $respuesta['con_stock_y_precio_menor_o_igual_al_costo'] = (int) $totales->precio_bajo;
            $respuesta['ganancia_potencial'] = round((float) $totales->valor_a_precio_con_costo - (float) $totales->valor_a_costo, 2);
        }

        $respuesta['con_stock_negativo'] = (int) $totales->negativos;

        if ($agrupar !== 'ninguna') {

            $grupos = self::grupos($preparado, $agrupar);

            $respuesta['agrupado_por'] = $agrupar;
            $respuesta['grupos_encontrados'] = count($grupos);
            $respuesta['grupos'] = array_slice($grupos, 0, self::TOPE_GRUPOS);
        }

        $promos = self::promociones($preparado);

        if (!is_null($promos)) {

            $respuesta['promociones_de_vinoteca'] = $promos;
        }

        $respuesta['nota'] = self::nota_de_valorizar($ver_costo, $agrupar, !is_null($promos));

        return $respuesta;
    }

    /**
     * Herramienta exportar_articulos_excel: el .xlsx de los artículos que cumplen el filtro, armado en
     * el servidor (las filas nunca pasan por el modelo).
     *
     * @param  int  $owner_id
     * @param  AiConversation|null  $conversation
     * @param  \App\Models\AiMessage|null  $assistant_message
     * @param  array  $input  filtros, deposito, solo, columnas, titulo, nombre_archivo
     * @return array<string, mixed>
     */
    public static function exportar_excel($owner_id, $conversation, $assistant_message, array $input): array
    {
        $preparado = self::preparar((int) $owner_id, $conversation, $input);

        if (isset($preparado['ok']) && $preparado['ok'] === false) {

            return $preparado;
        }

        $ver_costo = $preparado['ver_costo'];

        $columnas = self::columnas_pedidas(isset($input['columnas']) ? $input['columnas'] : null, $ver_costo);

        if (isset($columnas['ok']) && $columnas['ok'] === false) {

            return $columnas;
        }

        $total = (int) (clone $preparado['base'])->count();

        if ($total === 0) {

            return RespuestaDeCargaIa::error('Ningún artículo cumple ese filtro, así que no armé ningún Excel. Decíselo a la persona tal cual.');
        }

        if ($total > self::MAX_FILAS_DEL_EXCEL) {

            return RespuestaDeCargaIa::error(
                'Son ' . $total . ' artículos y el Excel llega hasta ' . self::MAX_FILAS_DEL_EXCEL . '. No armé nada: '
                . 'preguntale a la persona por qué filtrar (rubro, proveedor, con stock) y volvé a llamar.'
            );
        }

        $filas = [];

        $consulta = self::consulta_de_filas($preparado);

        // El margen necesita el IVA y los impuestos de cada artículo: solo se cargan si se pidió (y se ve el costo).
        $con_margen = count(array_intersect(self::COLUMNAS_DE_MARGEN, $columnas)) > 0;

        $consulta->chunkById(self::LOTE, function ($lote) use (&$filas, $columnas, $con_margen, $preparado) {

            $factores = $con_margen ? self::factores_netos($lote, $preparado['owner']) : [];

            foreach ($lote as $fila) {

                $factor = isset($factores[(int) $fila->id]) ? $factores[(int) $fila->id] : null;

                $filas[] = self::fila_del_excel($fila, $columnas, $factor);
            }

        }, 'articles.id', 'id');

        // El orden del listado: por nombre (los lotes vienen por id).
        usort($filas, function ($a, $b) {
            return strcmp(mb_strtolower((string) $a['__orden']), mb_strtolower((string) $b['__orden']));
        });

        foreach ($filas as $i => $fila) {
            unset($filas[$i]['__orden']);
        }

        $titulo = isset($input['titulo']) && trim((string) $input['titulo']) !== '' ? trim((string) $input['titulo']) : 'Artículos';

        $tabla = [
            'titulo'   => mb_substr($titulo, 0, 120),
            'columnas' => array_map(function ($clave) {
                $def = self::COLUMNAS_EXCEL[$clave];

                return ['clave' => $clave, 'etiqueta' => $def[0], 'formato' => $def[1]];
            }, $columnas),
            'filas'    => $filas,
        ];

        $respuesta = ArchivosDelAgenteIaHelper::guardar_tabla_en_excel(
            (int) $owner_id,
            $conversation,
            $assistant_message,
            $tabla,
            isset($input['nombre_archivo']) ? $input['nombre_archivo'] : null
        );

        if (!empty($respuesta['ok'])) {

            $respuesta['filtros_legibles'] = $preparado['legibles'];

            if (!$ver_costo) {

                $respuesta['sin_costos'] = 'Quien lo pidió no tiene permiso para ver costos: el Excel va sin costo ni valor a costo.';
            }
        }

        return $respuesta;
    }

    /**
     * Lo común a las dos herramientas: el dueño, quién pregunta, los filtros traducidos y el depósito.
     *
     * @param  int  $owner_id
     * @param  AiConversation|null  $conversation
     * @param  array  $input
     * @return array  {base, legibles, deposito, ver_costo, owner, dolar, con_listas, solo} o la respuesta negativa.
     */
    protected static function preparar($owner_id, $conversation, array $input)
    {
        $owner = User::find($owner_id);

        if (is_null($owner)) {

            return RespuestaDeCargaIa::error('No se encontró el negocio.');
        }

        $filtros = isset($input['filtros']) ? $input['filtros'] : [];

        if (!is_array($filtros)) {

            return RespuestaDeCargaIa::error('filtros tiene que ser una lista de {campo, operador, valor}.');
        }

        $traducido = FiltroDeArticulosIaHelper::traducir(array_values($filtros), $owner_id, true);

        if (RespuestaDeCargaIa::es_negativa($traducido)) {

            return $traducido;
        }

        /*
         * 🔴 EL EMPLEADO QUE VE SOLO SU SUCURSAL (permiso article.stock_only_sucursal, el mismo recorte que
         * la ficha: FichaArticuloIaHelper::depositos()). Para él el stock es SIEMPRE el de su sucursal: no
         * puede pedir otro depósito ni el total del negocio.
         */
        $persona = self::persona($conversation);
        $solo_su_sucursal = !is_null($persona) && !PermisosIaHelper::es_admin($persona)
            && PermisosIaHelper::puede($persona, FichaArticuloIaHelper::SOLO_MI_SUCURSAL);

        $deposito = null;

        if (isset($input['deposito']) && trim((string) $input['deposito']) !== '' && mb_strtolower(trim((string) $input['deposito'])) !== 'todos') {

            $deposito = self::resolver_deposito($owner_id, (string) $input['deposito']);

            if (isset($deposito['ok']) && $deposito['ok'] === false) {

                return $deposito;
            }
        }

        if ($solo_su_sucursal) {

            $suya = is_null($persona->address_id) ? null : DB::table('addresses')
                ->where('id', (int) $persona->address_id)
                ->where('user_id', $owner_id)
                ->first(['id', 'street']);

            if (is_null($suya)) {

                return RespuestaDeCargaIa::error('Quien pregunta solo puede ver el stock de su sucursal y no tiene ninguna asignada. Decíselo tal cual: lo tiene que asignar el dueño.');
            }

            if (!is_null($deposito) && (int) $deposito['address_id'] !== (int) $suya->id) {

                return RespuestaDeCargaIa::error('Quien pregunta solo puede ver el stock de su sucursal (' . $suya->street . '), no el de ' . $deposito['nombre'] . '. Decíselo tal cual.');
            }

            $deposito = ['address_id' => (int) $suya->id, 'nombre' => (string) $suya->street];
        }

        $solo = isset($input['solo']) ? $input['solo'] : [];

        if (is_string($solo)) {
            $solo = [$solo];
        }

        if (!is_array($solo)) {

            return RespuestaDeCargaIa::error('solo tiene que ser una lista con ' . implode(', ', self::SOLO) . '.');
        }

        foreach ($solo as $recorte) {

            if (!in_array($recorte, self::SOLO, true)) {

                return RespuestaDeCargaIa::error('No conozco "' . $recorte . '" en solo. Valen: ' . implode(', ', self::SOLO) . '.');
            }
        }

        $ver_costo = self::puede_ver_costo($conversation);

        foreach (['sin_costo', 'precio_menor_o_igual_al_costo'] as $de_costo) {

            if (!$ver_costo && in_array($de_costo, $solo, true)) {

                return RespuestaDeCargaIa::error('Quien pregunta no tiene permiso para ver costos, así que no puedo filtrar por costo. Decíselo tal cual.');
            }
        }

        /*
         * Los ids que cumplen el filtro, como SUBCONSULTA (la de FiltroDeArticulosIaHelper::query(), la
         * misma de contar_articulos_por_filtro y de la masiva). Va por id y no mezclada con los joins de
         * abajo porque ColumnFiltersHelper escribe columnas sin tabla («name», «provider_id») que con
         * providers o categories al lado serían ambiguas.
         */
        $ids = FiltroDeArticulosIaHelper::query($owner_id, $traducido['filter_form'], $traducido['imagen'], $traducido['por_lista'])
            ->select('articles.id')
            ->toBase();

        $base = DB::table('articles')
            ->where('articles.user_id', $owner_id)
            ->whereNull('articles.deleted_at')
            ->where('articles.status', 'active')
            ->whereIn('articles.id', $ids);

        $preparado = [
            'owner'      => $owner,
            'owner_id'   => $owner_id,
            'legibles'   => $traducido['legibles'],
            'deposito'   => $deposito,
            'ver_costo'  => $ver_costo,
            'dolar'      => (float) $owner->dollar > 0 ? (float) $owner->dollar : null,
            'con_listas' => UserHelper::uses_listas_de_precio($owner),
            'solo'       => array_values(array_unique($solo)),
            'solo_su_sucursal' => $solo_su_sucursal,
        ];

        // La base SIN recortes queda guardada: por depósito se vuelven a aplicar con el stock de cada uno.
        $preparado['base_filtrada'] = $base;
        $preparado['base'] = self::con_los_recortes(clone $base, $preparado);

        return $preparado;
    }

    /**
     * true si quien pregunta puede ver costos. Ver el 🔴 del docblock de la clase.
     *
     * @param  AiConversation|null  $conversation
     * @return bool
     */
    public static function puede_ver_costo($conversation): bool
    {
        /*
         * 🔴 Revisión de P5 (16/11/2026): sin persona comprobada NO hay costos, igual que ContextoDeCargaIa
         * (persona null = sin permisos). Los canales del dueño ya traen la persona: el WhatsApp del dueño
         * (AsistenteCanalHelper::nueva_conversacion) y el MCP (McpSesionHelper) crean la conversación con
         * `auth_user_id`, y el dueño pasa por PermisosIaHelper::puede() como admin. Una llamada sin
         * conversación o una conversación sin `auth_user_id` no sabe quién pregunta: va sin costos.
         */
        return PermisosIaHelper::puede(self::persona($conversation), self::PERMISO_COSTO);
    }

    /**
     * Quien charla con el asistente, o null si es el canal del dueño (MCP o conversación sin persona).
     *
     * @param  AiConversation|null  $conversation
     * @return User|null
     */
    public static function persona($conversation)
    {
        if (!($conversation instanceof AiConversation) || empty($conversation->auth_user_id)) {

            return null;
        }

        return User::find((int) $conversation->auth_user_id);
    }

    /**
     * El depósito por su nombre (addresses.street, como lo nombra stock_por_deposito), entre las
     * sucursales del negocio (sin las direcciones de compradores de la tienda).
     *
     * @param  int  $owner_id
     * @param  string  $texto
     * @return array  {address_id, nombre} o la respuesta negativa.
     */
    public static function resolver_deposito($owner_id, $texto)
    {
        $sucursales = DB::table('addresses')
            ->where('user_id', $owner_id)
            ->whereNull('buyer_id')
            ->orderBy('id')
            ->get(['id', 'street']);

        $pedido = self::sin_tildes($texto);

        $exactas = [];
        $parecidas = [];

        foreach ($sucursales as $sucursal) {

            $nombre = self::sin_tildes((string) $sucursal->street);

            if ($nombre === $pedido) {
                $exactas[] = $sucursal;
            } elseif ($nombre !== '' && $pedido !== '' && (mb_strpos($nombre, $pedido) !== false || mb_strpos($pedido, $nombre) !== false)) {
                $parecidas[] = $sucursal;
            }
        }

        $elegidas = count($exactas) ? $exactas : $parecidas;

        if (count($elegidas) === 1) {

            return ['address_id' => (int) $elegidas[0]->id, 'nombre' => (string) $elegidas[0]->street];
        }

        $opciones = [];

        foreach ($sucursales as $sucursal) {
            $opciones[] = (string) $sucursal->street;
        }

        if (!count($elegidas)) {

            return RespuestaDeCargaIa::error(
                'No encontré un depósito que se llame "' . $texto . '". ' . (count($opciones) ? 'Los del negocio son: ' . implode(', ', $opciones) . '.' : 'El negocio no tiene sucursales cargadas: sacá deposito.'),
                ['depositos' => $opciones]
            );
        }

        return RespuestaDeCargaIa::faltan(['cuál depósito: hay varios que encajan con "' . $texto . '"'], ['depositos' => $opciones]);
    }

    /**
     * Los recortes de `solo` (la auditoría de precios de V16 y el stock) sobre la consulta base.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  array  $preparado
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function con_los_recortes($base, array $preparado)
    {
        $base = self::con_el_stock($base, $preparado);

        $stock = self::expresion_stock($preparado);

        foreach ($preparado['solo'] as $recorte) {

            if ($recorte === 'con_stock') {
                $base->whereRaw($stock . ' > 0');
            } elseif ($recorte === 'sin_stock') {
                $base->whereRaw('(' . $stock . ' IS NULL OR ' . $stock . ' <= 0)');
            } elseif ($recorte === 'stock_negativo') {
                $base->whereRaw($stock . ' < 0');
            } elseif ($recorte === 'sin_precio') {
                $base->whereRaw('COALESCE(' . self::expresion_precio($preparado) . ', 0) <= 0');
            } elseif ($recorte === 'sin_costo') {
                $base->where(function ($q) {
                    $q->whereNull('articles.cost')->orWhere('articles.cost', '<=', 0);
                });
            } elseif ($recorte === 'precio_menor_o_igual_al_costo') {
                $base->where('articles.cost', '>', 0)
                    ->whereRaw('COALESCE(' . self::expresion_precio($preparado) . ', 0) <= ' . self::expresion_costo_unitario($preparado), self::bindings_del_costo($preparado));
            }
        }

        return $base;
    }

    /**
     * Suma el join del proveedor (para el dólar del costo) y, con depósito, el del stock de ese
     * depósito. Siempre por LEFT JOIN: un artículo sin proveedor o sin fila en el depósito sigue estando.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $preparado
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function con_el_stock($query, array $preparado)
    {
        $query->leftJoin('providers', 'providers.id', '=', 'articles.provider_id');

        if (!is_null($preparado['deposito'])) {

            $address_id = (int) $preparado['deposito']['address_id'];

            $query->leftJoin('address_article as stock_del_deposito', function ($join) use ($address_id) {
                $join->on('stock_del_deposito.article_id', '=', 'articles.id')
                    ->where('stock_del_deposito.address_id', '=', $address_id);
            });
        }

        return $query;
    }

    /**
     * El stock que se valoriza: el del depósito elegido o el total del artículo.
     *
     * @param  array  $preparado
     * @return string
     */
    protected static function expresion_stock(array $preparado)
    {
        return is_null($preparado['deposito']) ? 'articles.stock' : 'stock_del_deposito.amount';
    }

    /**
     * El costo de UNA unidad de stock, en pesos: el normalizado del Inventario
     * (InventoryPerformanceHelper::costo_unitario_normalizado) por la cotización si está en dólares
     * (RecolectorStock). Lleva UN binding: el dólar de la cuenta (ver bindings_del_costo()).
     *
     * Pública desde la misión asistente-diario-20261116 (30/10 #11), igual que persona() y
     * resolver_deposito(): las reusa RendimientoDeComprasIaHelper para valorizar lo que no rotó con el
     * MISMO costo que valorizar_stock (un solo criterio de costo en el asistente). Necesita
     * `providers` unido a `articles` y `$preparado['dolar']`.
     *
     * @param  array  $preparado
     * @return string
     */
    public static function expresion_costo_unitario(array $preparado)
    {
        return '(articles.cost'
            . ' * (CASE WHEN articles.presentacion IS NULL THEN 1 ELSE articles.presentacion END)'
            . ' / (CASE WHEN articles.unidades_individuales > 0 THEN articles.unidades_individuales ELSE 1 END)'
            . ' * (CASE WHEN COALESCE(articles.cost_in_dollars, 0) = 1 THEN COALESCE(NULLIF(providers.dolar, 0), ?, 1) ELSE 1 END))';
    }

    /**
     * @param  array  $preparado
     * @return array<int, float|null>
     */
    protected static function bindings_del_costo(array $preparado)
    {
        return [$preparado['dolar']];
    }

    /**
     * El precio de venta de HOY, con la cascada de ArticlePricesHelper::resolver_precio_de_venta() (ver
     * el docblock de la clase). Sin bindings.
     *
     * @param  array  $preparado
     * @return string
     */
    protected static function expresion_precio(array $preparado)
    {
        if (!$preparado['con_listas']) {

            return 'articles.final_price';
        }

        /*
         * Revisión de P5 (16/11/2026): la lista «Precio final» (`price_types.es_precio_final = 1`) ES el precio
         * del artículo y el resolvedor contesta `articles.final_price`, no el pivote
         * (PriceTypeHelper::es_lista_precio_final). Acá la subconsulta devuelve NULL para esa lista y el
         * COALESCE cae en final_price; lo mismo que cuando el pivote elegido no tiene precio.
         */
        $del_pivote = self::hay_columna_precio_final()
            ? 'CASE WHEN COALESCE(pt_precio.es_precio_final, 0) = 1 THEN NULL ELSE apt_precio.final_price END'
            : 'apt_precio.final_price';

        return 'COALESCE((SELECT ' . $del_pivote . ' FROM article_price_type apt_precio'
            . ' JOIN price_types pt_precio ON pt_precio.id = apt_precio.price_type_id'
            . ' WHERE apt_precio.article_id = articles.id'
            . ' ORDER BY COALESCE(pt_precio.position, 0) DESC, pt_precio.id DESC LIMIT 1), articles.final_price)';
    }

    /**
     * true si `price_types.es_precio_final` existe en esta instalación.
     *
     * @return bool
     */
    protected static function hay_columna_precio_final()
    {
        static $hay = null;

        if (is_null($hay)) {
            try {
                $hay = Schema::hasColumn('price_types', 'es_precio_final');
            } catch (\Throwable $e) {
                $hay = false;
            }
        }

        return $hay;
    }

    /**
     * Los totales (o los de un grupo, con $agrupar) en UNA consulta: SUM y COUNT en SQL.
     *
     * @param  array  $preparado
     * @param  string|null  $columna_de_grupo  La expresión del grupo, o null para el total.
     * @return \stdClass|\Illuminate\Support\Collection
     */
    protected static function agregado(array $preparado, $columna_de_grupo)
    {
        $stock = self::expresion_stock($preparado);
        $costo = self::expresion_costo_unitario($preparado);
        $precio = self::expresion_precio($preparado);
        $dolar = $preparado['dolar'];

        $con_stock = $stock . ' > 0';
        $tiene_costo = 'articles.cost IS NOT NULL AND articles.cost > 0';

        $select = 'COALESCE(SUM(CASE WHEN ' . $con_stock . ' THEN 1 ELSE 0 END), 0) as con_stock,'
            . ' COALESCE(SUM(CASE WHEN ' . $con_stock . ' THEN ' . $stock . ' ELSE 0 END), 0) as unidades,'
            . ' COALESCE(SUM(CASE WHEN ' . $con_stock . ' THEN ' . $stock . ' * COALESCE(' . $precio . ', 0) ELSE 0 END), 0) as valor_a_precio,'
            . ' COALESCE(SUM(CASE WHEN ' . $con_stock . ' AND ' . $tiene_costo . ' THEN ' . $stock . ' * COALESCE(' . $precio . ', 0) ELSE 0 END), 0) as valor_a_precio_con_costo,'
            . ' COALESCE(SUM(CASE WHEN ' . $con_stock . ' AND ' . $tiene_costo . ' THEN ' . $stock . ' * ' . $costo . ' ELSE 0 END), 0) as valor_a_costo,'
            . ' COALESCE(SUM(CASE WHEN ' . $con_stock . ' AND (COALESCE(' . $precio . ', 0) <= 0) THEN 1 ELSE 0 END), 0) as sin_precio,'
            . ' COALESCE(SUM(CASE WHEN ' . $con_stock . ' AND NOT (' . $tiene_costo . ') THEN 1 ELSE 0 END), 0) as sin_costo,'
            . ' COALESCE(SUM(CASE WHEN ' . $con_stock . ' AND ' . $tiene_costo . ' AND COALESCE(' . $precio . ', 0) <= ' . $costo . ' THEN 1 ELSE 0 END), 0) as precio_bajo,'
            . ' COALESCE(SUM(CASE WHEN ' . $stock . ' < 0 THEN 1 ELSE 0 END), 0) as negativos';

        // El costo aparece DOS veces en el SELECT (valor_a_costo y precio_bajo): un binding (el dólar) por cada una.
        $bindings = [$dolar, $dolar];

        $query = clone $preparado['base'];

        if (is_null($columna_de_grupo)) {

            return $query->selectRaw($select, $bindings)->first();
        }

        return $query->selectRaw($columna_de_grupo . ' as grupo, ' . $select, $bindings)
            ->groupBy('grupo')
            ->get();
    }

    /**
     * Los grupos (rubro, proveedor o depósito), de mayor a menor valor a precio.
     *
     * @param  array  $preparado
     * @param  string  $agrupar
     * @return array<int, array<string, mixed>>
     */
    protected static function grupos(array $preparado, $agrupar)
    {
        if ($agrupar === 'deposito') {

            return self::grupos_por_deposito($preparado);
        }

        if ($agrupar === 'rubro') {

            $preparado['base'] = (clone $preparado['base'])->leftJoin('categories as grupo_categoria', 'grupo_categoria.id', '=', 'articles.category_id');
            $columna = "COALESCE(grupo_categoria.name, 'Sin rubro')";

        } else {

            $columna = "COALESCE(providers.name, 'Sin proveedor')";
        }

        $filas = self::agregado($preparado, $columna);

        $grupos = [];

        foreach ($filas as $fila) {

            if ((int) $fila->con_stock === 0 && (int) $fila->negativos === 0) {
                continue;
            }

            $grupos[] = self::fila_de_grupo($fila, $preparado['ver_costo']);
        }

        usort($grupos, function ($a, $b) {
            return $b['valor_a_precio'] <=> $a['valor_a_precio'];
        });

        return $grupos;
    }

    /**
     * Por depósito: una consulta agregada por cada sucursal del negocio (son pocas), con el stock de
     * ESA sucursal.
     *
     * @param  array  $preparado
     * @return array<int, array<string, mixed>>
     */
    protected static function grupos_por_deposito(array $preparado)
    {
        $sucursales = DB::table('addresses')
            ->where('user_id', $preparado['owner_id'])
            ->whereNull('buyer_id')
            ->orderBy('id')
            ->get(['id', 'street']);

        $grupos = [];

        foreach ($sucursales as $sucursal) {

            $por_deposito = $preparado;
            $por_deposito['deposito'] = ['address_id' => (int) $sucursal->id, 'nombre' => (string) $sucursal->street];

            /*
             * La base se rearma desde los ids filtrados con el join del stock de ESTE depósito, y los recortes
             * de `solo` (sin_stock, stock_negativo...) miran ese stock y no el total (revisión de P5, 16/11/2026).
             */
            $por_deposito['base'] = self::con_los_recortes(clone $preparado['base_filtrada'], $por_deposito);

            $fila = self::agregado($por_deposito, null);
            $fila->grupo = (string) $sucursal->street;

            if ((int) $fila->con_stock === 0 && (int) $fila->negativos === 0) {
                continue;
            }

            $grupos[] = self::fila_de_grupo($fila, $preparado['ver_costo']);
        }

        usort($grupos, function ($a, $b) {
            return $b['valor_a_precio'] <=> $a['valor_a_precio'];
        });

        return $grupos;
    }

    /**
     * Un grupo, como lo ve el modelo.
     *
     * @param  \stdClass  $fila
     * @param  bool  $ver_costo
     * @return array<string, mixed>
     */
    protected static function fila_de_grupo($fila, $ver_costo)
    {
        $grupo = [
            'nombre'              => (string) $fila->grupo,
            'articulos_con_stock' => (int) $fila->con_stock,
            'unidades_en_stock'   => round((float) $fila->unidades, 2),
            'valor_a_precio'      => round((float) $fila->valor_a_precio, 2),
            'con_stock_sin_precio' => (int) $fila->sin_precio,
            // Revisión de P5: el stock negativo también por grupo (por depósito, el de ESE depósito).
            'con_stock_negativo'  => (int) $fila->negativos,
        ];

        if ($ver_costo) {
            $grupo['valor_a_costo'] = round((float) $fila->valor_a_costo, 2);
            $grupo['con_stock_sin_costo'] = (int) $fila->sin_costo;
            $grupo['con_stock_y_precio_menor_o_igual_al_costo'] = (int) $fila->precio_bajo;
        }

        return $grupo;
    }

    /**
     * Las promociones de vinoteca con stock (el Inventario las suma: InventoryPerformanceHelper::
     * promocion_vinotecas()). Van APARTE de los artículos y solo sin filtros ni depósito (los filtros son
     * de artículos). Null si no hay ninguna.
     *
     * @param  array  $preparado
     * @return array|null
     */
    protected static function promociones(array $preparado)
    {
        if (count($preparado['legibles']) || count($preparado['solo']) || !is_null($preparado['deposito'])) {

            return null;
        }

        try {

            if (!Schema::hasTable('promocion_vinotecas')) {
                return null;
            }

            $fila = DB::table('promocion_vinotecas')
                ->where('user_id', $preparado['owner_id'])
                ->whereNull('deleted_at')
                ->where('stock', '>', 0)
                ->selectRaw('COUNT(*) as cuantas, COALESCE(SUM(stock), 0) as unidades, COALESCE(SUM(stock * COALESCE(final_price, 0)), 0) as valor_a_precio, COALESCE(SUM(stock * COALESCE(cost, 0)), 0) as valor_a_costo')
                ->first();

        } catch (\Throwable $e) {

            return null;
        }

        if (is_null($fila) || (int) $fila->cuantas === 0) {

            return null;
        }

        $promos = [
            'promociones_con_stock' => (int) $fila->cuantas,
            'unidades_en_stock'     => round((float) $fila->unidades, 2),
            'valor_a_precio'        => round((float) $fila->valor_a_precio, 2),
        ];

        if ($preparado['ver_costo']) {
            $promos['valor_a_costo'] = round((float) $fila->valor_a_costo, 2);
        }

        return $promos;
    }

    /**
     * Las columnas del Excel: las pedidas (válidas, sin repetir y en el orden pedido) o las de siempre.
     * Sin permiso de costo, las de costo se sacan.
     *
     * @param  mixed  $pedidas
     * @param  bool  $ver_costo
     * @return array<int, string>|array  O la respuesta negativa.
     */
    protected static function columnas_pedidas($pedidas, $ver_costo)
    {
        $columnas = [];

        if (is_array($pedidas) && count($pedidas)) {

            foreach ($pedidas as $clave) {

                $clave = (string) $clave;

                if (!isset(self::COLUMNAS_EXCEL[$clave])) {

                    return RespuestaDeCargaIa::error('No conozco la columna "' . $clave . '". Valen: ' . implode(', ', array_keys(self::COLUMNAS_EXCEL)) . '.');
                }

                if (!in_array($clave, $columnas, true)) {
                    $columnas[] = $clave;
                }
            }

        } else {

            $columnas = self::COLUMNAS_POR_DEFECTO;
        }

        if (!$ver_costo) {

            $columnas = array_values(array_filter($columnas, function ($clave) {
                return !self::COLUMNAS_EXCEL[$clave][2];
            }));
        }

        if (!count($columnas)) {

            return RespuestaDeCargaIa::error('No quedó ninguna columna para el Excel (las de costo necesitan permiso para ver costos).');
        }

        return $columnas;
    }

    /**
     * La consulta de las filas del Excel: lo que hace falta para TODAS las columnas posibles (son pocas
     * y vienen en la misma fila).
     *
     * @param  array  $preparado
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta_de_filas(array $preparado)
    {
        $stock = self::expresion_stock($preparado);

        // La base del margen (base_del_margen()): viaja en la misma fila, sin consultas por artículo.
        $de_la_base = self::hay_columnas_de_base_del_margen()
            ? ', articles.base_margen as base_margen, articles.costo_real as costo_real,'
                . ' articles.cost_in_dollars as costo_en_dolares, articles.unidades_individuales as unidades_individuales'
            : '';

        return (clone $preparado['base'])
            ->leftJoin('categories as fila_categoria', 'fila_categoria.id', '=', 'articles.category_id')
            ->leftJoin('brands as fila_marca', 'fila_marca.id', '=', 'articles.brand_id')
            // M2 (asistente-diario-20261119): la subcategoría, en la misma fila.
            ->leftJoin('sub_categories as fila_subcategoria', 'fila_subcategoria.id', '=', 'articles.sub_category_id')
            ->selectRaw(
                'articles.id as id, articles.name as nombre, articles.bar_code as codigo, articles.provider_code as codigo_de_proveedor,'
                . ' fila_categoria.name as rubro, fila_subcategoria.name as subrubro, articles.percentage_gain as margen_cargado,'
                . ' providers.name as proveedor, fila_marca.name as marca, articles.online as en_tienda,'
                . ' ' . $stock . ' as stock, ' . self::expresion_precio($preparado) . ' as precio,'
                . ' CASE WHEN articles.cost IS NULL OR articles.cost <= 0 THEN NULL ELSE ' . self::expresion_costo_unitario($preparado) . ' END as costo'
                . $de_la_base,
                self::bindings_del_costo($preparado)
            );
    }

    /**
     * El factor que lleva el precio de venta de cada artículo del lote a su precio NETO de IVA y de impuestos
     * sobre ventas: ArticlePricesHelper::quitar_iva_y_sale_taxes() aplicado sobre 1 (es un producto de
     * escalares, igual que lo usa ArticleHelper::base_margen_en_precio_de_venta()).
     *
     * 🔴 POR QUÉ NETO (misión asistente-diario-20261117, M1): es el criterio del sistema para la ganancia de un
     * artículo. ArticlePricesHelper::aplicar_price_type_surchages() guarda `monto_ganancia` contra el precio
     * neto de IVA y de impuestos (tarea 9, aprobado por Lucas el 10/8/2026: una lista al 40 % sobre un costo
     * de 1000 mostraba $694 de ganancia cuando la real es $400), y la ficha (PercentageGainInput.vue) mide el
     * margen implícito de un precio contra una base del mismo lado del IVA. Comparar el precio final con el
     * costo tal cual inflaría el margen en el IVA entero.
     *
     * Tres consultas por lote (artículos, su IVA y sus impuestos), nunca una por artículo.
     *
     * @param  \Illuminate\Support\Collection  $lote  Filas de consulta_de_filas() (traen `id`).
     * @param  User  $owner
     * @return array<int, float>  article_id => factor (precio neto = precio × factor).
     */
    protected static function factores_netos($lote, User $owner)
    {
        $ids = [];

        foreach ($lote as $fila) {
            $ids[] = (int) $fila->id;
        }

        if (!count($ids)) {

            return [];
        }

        $articulos = Article::whereIn('id', $ids)
            ->where('user_id', (int) $owner->id)
            ->with(['iva', 'sale_taxes'])
            ->get(['id', 'user_id', 'iva_id', 'aplicar_iva']);

        $factores = [];

        foreach ($articulos as $articulo) {

            try {

                $factores[(int) $articulo->id] = (float) ArticlePricesHelper::quitar_iva_y_sale_taxes($articulo, 1, $owner);

            } catch (\Throwable $e) {

                // Sin factor el artículo va sin margen (celdas vacías): nunca un margen inventado.
                continue;
            }
        }

        return $factores;
    }

    /**
     * El margen y la ganancia de UNA unidad, con el criterio de factores_netos():
     *  - ganancia por unidad = precio de venta neto de IVA e impuestos − la BASE del margen (ver base_del_margen());
     *  - margen % = ganancia / base × 100.
     * Es la misma cuenta que el «margen implícito» de ArticleHelper::setFinalPrice_sin_historial()
     * ((precio neto − base_margen) / base_margen), así que coincide con el % de ganancia de la ficha.
     * Sin base, sin precio o sin factor: null (celda vacía), nunca 0.
     *
     * @param  float|null  $base  La base del margen (base_del_margen()).
     * @param  float|null  $precio
     * @param  float|null  $factor_neto
     * @return array  [margen en puntos (15 = 15 %) o null, ganancia por unidad o null]
     */
    public static function columnas_de_margen($base, $precio, $factor_neto)
    {
        if (is_null($base) || $base <= 0 || is_null($precio) || $precio <= 0 || is_null($factor_neto) || $factor_neto <= 0) {

            return [null, null];
        }

        $ganancia = ((float) $precio * (float) $factor_neto) - (float) $base;

        return [round($ganancia / (float) $base * 100, 2), round($ganancia, 2)];
    }

    /**
     * LA BASE DEL MARGEN de una fila: contra qué se mide la ganancia (revisión independiente de M1,
     * 17/11/2026). El sistema aplica `percentage_gain` sobre la base de ArticleHelper::calcular_base_antes_de_listas()
     * más los márgenes de proveedor y categoría, y la guarda en `articles.base_margen` cada vez que calcula el
     * precio. Esa base parte del `costo_real` (con los descuentos y recargos del artículo y, en cuentas con
     * «aplicar IVA al costo», con el IVA adentro), ya dividido por unidades individuales y cotizado. Medir contra
     * `articles.cost` daba 12,5 % con un descuento de proveedor del 10 % y 51,25 % con IVA al costo, sobre un
     * artículo cargado al 25 %.
     *
     * La cascada, de la más fiel a la menos:
     *  1. `base_margen` si es mayor que cero;
     *  2. `costo_real` ÷ unidades individuales, si es mayor que cero y el costo NO está en dólares (costo_real no
     *     está cotizado: en dólares se cae al paso 3, que sí cotiza);
     *  3. el costo de la columna Costo (normalizado en pesos de valorizar_stock).
     * La columna Costo del Excel no cambia: esto es solo para el margen y la ganancia.
     *
     * @param  \stdClass  $fila  Fila de consulta_de_filas().
     * @param  float|null  $costo  El de la columna Costo.
     * @return float|null
     */
    protected static function base_del_margen($fila, $costo)
    {
        if (isset($fila->base_margen) && !is_null($fila->base_margen) && (float) $fila->base_margen > 0) {

            return (float) $fila->base_margen;
        }

        if (isset($fila->costo_real) && !is_null($fila->costo_real) && (float) $fila->costo_real > 0
            && empty($fila->costo_en_dolares)) {

            $unidades = isset($fila->unidades_individuales) && (float) $fila->unidades_individuales > 0
                ? (float) $fila->unidades_individuales
                : 1.0;

            return (float) $fila->costo_real / $unidades;
        }

        return $costo;
    }

    /**
     * true si las columnas de la base del margen (`base_margen`, `costo_real`) existen en esta instalación.
     *
     * @return bool
     */
    protected static function hay_columnas_de_base_del_margen()
    {
        static $hay = null;

        if (is_null($hay)) {
            try {
                $hay = Schema::hasColumn('articles', 'base_margen') && Schema::hasColumn('articles', 'costo_real');
            } catch (\Throwable $e) {
                $hay = false;
            }
        }

        return $hay;
    }

    /**
     * Una fila del Excel con las columnas pedidas. Los números van como número; lo que falta, vacío.
     * El valor a costo y a precio son solo del stock positivo (igual que el total de valorizar_stock).
     *
     * @param  \stdClass  $fila
     * @param  array<int, string>  $columnas
     * @param  float|null  $factor_neto  El de factores_netos(), o null si no se pidió margen.
     * @return array<string, mixed>
     */
    protected static function fila_del_excel($fila, array $columnas, $factor_neto = null)
    {
        $stock = is_null($fila->stock) ? null : (float) $fila->stock;
        $precio = is_null($fila->precio) ? null : round((float) $fila->precio, 2);
        $costo = is_null($fila->costo) ? null : round((float) $fila->costo, 2);
        $positivo = !is_null($stock) && $stock > 0;

        list($margen, $ganancia) = self::columnas_de_margen(self::base_del_margen($fila, $costo), $precio, $factor_neto);

        $valores = [
            'nombre'              => (string) $fila->nombre,
            'codigo'              => is_null($fila->codigo) ? null : (string) $fila->codigo,
            'codigo_de_proveedor' => is_null($fila->codigo_de_proveedor) ? null : (string) $fila->codigo_de_proveedor,
            'rubro'               => is_null($fila->rubro) ? null : (string) $fila->rubro,
            'proveedor'           => is_null($fila->proveedor) ? null : (string) $fila->proveedor,
            'marca'               => is_null($fila->marca) ? null : (string) $fila->marca,
            'stock'               => $stock,
            'costo'               => $costo,
            'precio'              => $precio,
            'valor_a_costo'       => ($positivo && !is_null($costo)) ? round($stock * $costo, 2) : null,
            'valor_a_precio'      => ($positivo && !is_null($precio)) ? round($stock * $precio, 2) : null,
            'margen'              => $margen,
            'ganancia_por_unidad' => $ganancia,
            'en_tienda'           => (bool) $fila->en_tienda ? 'Sí' : 'No',
            // M2 (asistente-diario-20261119): sin dato, celda vacía (nunca 0).
            'subrubro'            => (isset($fila->subrubro) && !is_null($fila->subrubro)) ? (string) $fila->subrubro : null,
            'margen_cargado'      => (isset($fila->margen_cargado) && !is_null($fila->margen_cargado)) ? round((float) $fila->margen_cargado, 2) : null,
        ];

        $salida = ['__orden' => (string) $fila->nombre];

        foreach ($columnas as $clave) {
            $salida[$clave] = $valores[$clave];
        }

        return $salida;
    }

    /**
     * La nota para el modelo.
     *
     * @param  bool  $ver_costo
     * @param  string  $agrupar
     * @param  bool  $con_promos
     * @return string
     */
    protected static function nota_de_valorizar($ver_costo, $agrupar, $con_promos)
    {
        $nota = 'Cuentas hechas por el sistema sobre TODOS los artículos activos que cumplen el filtro (no es una muestra): contestá con estos números, sin recalcular. '
            . 'Solo suma el stock MAYOR A CERO; valor_a_precio es stock × precio de venta de hoy (con listas, el de la lista principal). '
            . 'con_stock_sin_precio son artículos con stock que no tienen precio: no suman al valor a precio, decilo si no es cero. ';

        if ($ver_costo) {
            $nota .= 'valor_a_costo es stock × costo (en pesos; el costo en dólares pasado con la cotización del proveedor o de la cuenta) y deja afuera a los con_stock_sin_costo. '
                . 'ganancia_potencial es lo que se ganaría vendiendo todo ese stock (solo los que tienen costo). '
                . 'Para revisar precios (precio menor o igual al costo, sin precio, sin costo), los números están acá y la lista completa sale con exportar_articulos_excel y solo. ';
        } else {
            $nota .= 'Quien pregunta NO tiene permiso para ver costos: no hay valor a costo ni ganancia, y no los estimes. ';
        }

        if ($agrupar !== 'ninguna') {
            $nota .= 'grupos viene de mayor a menor valor a precio (hasta ' . self::TOPE_GRUPOS . '; grupos_encontrados dice cuántos hay). ';
        }

        if ($con_promos) {
            $nota .= 'promociones_de_vinoteca va APARTE de los artículos (el informe de Inventario las suma al total): si preguntan por todo el stock, sumalas y decilo. ';
        }

        return trim($nota . 'Si quieren la lista artículo por artículo, ofrecé el Excel con exportar_articulos_excel (mismos filtros).');
    }

    /**
     * Minúsculas, sin tildes y con los espacios colapsados.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function sin_tildes($texto)
    {
        $texto = strtr(mb_strtolower(trim((string) $texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
