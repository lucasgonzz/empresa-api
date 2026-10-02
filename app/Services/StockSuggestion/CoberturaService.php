<?php

namespace App\Services\StockSuggestion;

use App\Models\Address;
use App\Models\Sale;
use App\Models\StockSuggestionArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Velocidad de venta y días hasta quiebre para priorizar sugerencias de stock.
 *
 * StockSuggestionService decide QUÉ mover; este servicio decide EN QUÉ ORDEN
 * mirarlo: cobertura_dias = stock del destino ÷ velocidad de venta diaria en
 * esa sucursal. Cuanto menor la cobertura, más urgente el traslado.
 *
 * La velocidad mezcla la ventana reciente con la misma ventana del año
 * anterior (misma estación contra misma estación); sin historia interanual
 * degrada a la velocidad reciente sola. Todo determinístico, en SQL + PHP:
 * la IA no participa de ningún número.
 *
 * Con DEPÓSITO MADRE (misión deposito-madre, 2/10/2026) el orden deja de ser
 * solo urgencia: Lucas pidió que el reparto y la lista prioricen a las
 * sucursales que más venden, con un criterio configurable por comercio
 * (users.sugerencias_prioridad_destino, ver PRIORIDADES_DESTINO). La cobertura
 * pasa a desempatar. Sin madre, el ranking es exactamente el de siempre.
 */
class CoberturaService
{
    /** Días de la ventana de venta (reciente e interanual, la misma corrida un año atrás). */
    const VENTANA_DIAS = 90;

    /** Desfase de la ventana interanual: la misma ventana, 365 días antes. */
    const DESFASE_INTERANUAL_DIAS = 365;

    /** Peso de la velocidad reciente en la mezcla (ajustar acá, no en la fórmula). */
    const PESO_VELOCIDAD_RECIENTE = 2;

    /** Peso de la velocidad interanual en la mezcla. */
    const PESO_VELOCIDAD_INTERANUAL = 1;

    /** Tamaño de lote del UPDATE de prioridades. */
    const LOTE_UPDATE_PRIORIDADES = 500;

    /**
     * Criterio de prioridad con depósito madre: la sucursal que más factura en
     * general (pesos, últimos VENTANA_DIAS días, ver facturacion_por_sucursal()).
     */
    const PRIORIDAD_VENTAS_SUCURSAL = 'ventas_sucursal';

    /**
     * Criterio de prioridad con depósito madre: la sucursal que más vende ESE
     * artículo (la misma velocidad de venta que mide la urgencia).
     */
    const PRIORIDAD_VENTAS_ARTICULO = 'ventas_articulo';

    /**
     * Lista blanca de users.sugerencias_prioridad_destino. UserController@update
     * ignora cualquier valor que no esté acá, y criterio_prioridad_destino() cae
     * al primero si la columna trae algo desconocido.
     */
    const PRIORIDADES_DESTINO = [self::PRIORIDAD_VENTAS_SUCURSAL, self::PRIORIDAD_VENTAS_ARTICULO];

    /** Columna del criterio en users. */
    const COLUMNA_PRIORIDAD = 'sugerencias_prioridad_destino';

    /**
     * Memo de la guarda de esquema de COLUMNA_PRIORIDAD (solo el SÍ, ver
     * columna_prioridad_existe()).
     *
     * @var bool|null
     */
    private static $columna_prioridad_existe = null;

    /** @var int Comercio dueño (articles.user_id) al que se acota toda consulta */
    protected $user_id;

    /**
     * @param int $user_id Dueño de la sugerencia (no config('app.USER_ID'))
     */
    public function __construct($user_id)
    {
        $this->user_id = $user_id;
    }

    /**
     * Clave del mapa de velocidades para un par artículo/sucursal destino.
     *
     * @param int $article_id
     * @param int $address_id
     * @return string
     */
    public function clave_para($article_id, $address_id)
    {
        return $article_id . '-' . $address_id;
    }

    /**
     * Clave del mapa de velocidades para un artículo a nivel global (todas
     * las sucursales del comercio sumadas, sin distinguir destino). Existe
     * para que el llamador use la misma forma `->clave_*()` que clave_para(),
     * no porque haga falta ofuscar el id: sin sucursal no hay nada que
     * concatenar.
     *
     * @param int $article_id
     * @return string
     */
    public function clave_global($article_id)
    {
        return (string) $article_id;
    }

    /**
     * Velocidad de venta diaria para cada par (article_id, address_id), en UNA
     * consulta agregada (nunca una por artículo: article_purchases tiene
     * 10⁵-10⁶ filas y este cálculo corre por chunk de hasta 5000 artículos).
     *
     * Se apoya en el índice (address_id, article_id, created_at) y copia el
     * patrón de agregación de AdminSync\SistemaQueryController: join a
     * articles por user_id, join a sales excluyendo borradas y consolidaciones
     * de facturación.
     *
     * @param array $pares [['article_id' => int, 'address_id' => int], ...]
     * @return array Mapa clave_para() => velocidad diaria (float, 0.0 sin ventas)
     */
    public function velocidades_para(array $pares): array
    {
        $velocidades = [];

        foreach ($pares as $par) {
            $velocidades[$this->clave_para($par['article_id'], $par['address_id'])] = 0.0;
        }

        if (empty($pares)) {
            return $velocidades;
        }

        $article_ids = array_values(array_unique(array_column($pares, 'article_id')));
        $address_ids = array_values(array_unique(array_column($pares, 'address_id')));

        $ahora = now();
        $desde_reciente = $ahora->copy()->subDays(self::VENTANA_DIAS);
        $desde_interanual = $ahora->copy()->subDays(self::DESFASE_INTERANUAL_DIAS + self::VENTANA_DIAS);
        $hasta_interanual = $ahora->copy()->subDays(self::DESFASE_INTERANUAL_DIAS);

        // El WHERE global acota a [hoy-455, hoy]; cada CASE recorta su ventana.
        // Las filas del hueco entre ambas ventanas no suman en ninguno.
        $filas = DB::table('article_purchases')
            ->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $this->user_id)
            ->whereNull('sales.deleted_at')
            // Condición de Sale::scopeSoloVentasReales, con prefijo de tabla
            // porque acá el FROM es article_purchases (las consolidaciones de
            // facturación duplicarían las ventas que agrupan).
            ->where(function ($q) {
                $q->whereNull('sales.is_consolidacion_facturacion')
                  ->orWhere('sales.is_consolidacion_facturacion', 0);
            })
            ->whereIn('article_purchases.article_id', $article_ids)
            ->whereIn('article_purchases.address_id', $address_ids)
            ->where('article_purchases.created_at', '>=', $desde_interanual)
            ->groupBy('article_purchases.article_id', 'article_purchases.address_id')
            ->selectRaw(
                'article_purchases.article_id as article_id,
                 article_purchases.address_id as address_id,
                 SUM(CASE WHEN article_purchases.created_at >= ? THEN article_purchases.amount ELSE 0 END) as suma_reciente,
                 SUM(CASE WHEN article_purchases.created_at <= ? THEN article_purchases.amount ELSE 0 END) as suma_interanual',
                [$desde_reciente, $hasta_interanual]
            )
            ->get();

        foreach ($filas as $fila) {
            $clave = $this->clave_para($fila->article_id, $fila->address_id);

            // El whereIn es cartesiano (artículos × sucursales del chunk):
            // pueden venir pares que nadie pidió y se ignoran.
            if (!array_key_exists($clave, $velocidades)) {
                continue;
            }

            $velocidades[$clave] = $this->mezclar_velocidades(
                (float) $fila->suma_reciente,
                (float) $fila->suma_interanual
            );
        }

        return $velocidades;
    }

    /**
     * Velocidad de venta diaria por artículo, sumando TODAS las sucursales
     * del comercio (a diferencia de velocidades_para(), que la calcula por
     * par artículo/sucursal). Mismo patrón de UNA consulta agregada por
     * chunk; copia literal de velocidades_para() con tres diferencias, y
     * solo esas tres: sin whereIn de address_id, groupBy solo por
     * article_id, y el select sin address_id.
     *
     * 🔴 Este método NO es "sumar velocidades_para() por sucursal": la
     * mezcla 2/3-1/3 de mezclar_velocidades() no es aditiva por el branch
     * `if ($v_interanual > 0)` — sumar mezclas ya calculadas por sucursal da
     * un número distinto de mezclar las sumas de todas las sucursales
     * juntas. Por eso esta query propia en vez de reusar velocidades_para()
     * y sumar sus resultados por artículo.
     *
     * @param array $article_ids
     * @return array Mapa clave_global() => velocidad diaria (float, 0.0 sin ventas)
     */
    public function velocidades_globales(array $article_ids): array
    {
        $velocidades = [];

        foreach ($article_ids as $article_id) {
            $velocidades[$this->clave_global($article_id)] = 0.0;
        }

        if (empty($article_ids)) {
            return $velocidades;
        }

        $article_ids_unicos = array_values(array_unique($article_ids));

        $ahora = now();
        $desde_reciente = $ahora->copy()->subDays(self::VENTANA_DIAS);
        $desde_interanual = $ahora->copy()->subDays(self::DESFASE_INTERANUAL_DIAS + self::VENTANA_DIAS);
        $hasta_interanual = $ahora->copy()->subDays(self::DESFASE_INTERANUAL_DIAS);

        // Mismo WHERE global [hoy-455, hoy] que velocidades_para(): cada CASE
        // recorta su ventana y las filas del hueco entre ambas no suman en
        // ninguna. El scope por comercio lo da articles.user_id, igual que
        // en velocidades_para().
        $filas = DB::table('article_purchases')
            ->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $this->user_id)
            ->whereNull('sales.deleted_at')
            // Condición de Sale::scopeSoloVentasReales, con prefijo de tabla
            // porque acá el FROM es article_purchases (las consolidaciones de
            // facturación duplicarían las ventas que agrupan).
            ->where(function ($q) {
                $q->whereNull('sales.is_consolidacion_facturacion')
                  ->orWhere('sales.is_consolidacion_facturacion', 0);
            })
            ->whereIn('article_purchases.article_id', $article_ids_unicos)
            ->where('article_purchases.created_at', '>=', $desde_interanual)
            ->groupBy('article_purchases.article_id')
            ->selectRaw(
                'article_purchases.article_id as article_id,
                 SUM(CASE WHEN article_purchases.created_at >= ? THEN article_purchases.amount ELSE 0 END) as suma_reciente,
                 SUM(CASE WHEN article_purchases.created_at <= ? THEN article_purchases.amount ELSE 0 END) as suma_interanual',
                [$desde_reciente, $hasta_interanual]
            )
            ->get();

        foreach ($filas as $fila) {
            $clave = $this->clave_global($fila->article_id);

            // Sin sucursal para acotar, el whereIn de article_id ya garantiza
            // que no puede traer claves de más; se valida igual por simetría
            // con velocidades_para() (y por si el día de mañana esto se
            // reusa con un whereIn más laxo).
            if (!array_key_exists($clave, $velocidades)) {
                continue;
            }

            $velocidades[$clave] = $this->mezclar_velocidades(
                (float) $fila->suma_reciente,
                (float) $fila->suma_interanual
            );
        }

        return $velocidades;
    }

    /**
     * Mezcla de velocidades según los pesos de clase: con historia interanual,
     * (2·v_reciente + v_interanual) / 3; sin ella, la reciente sola.
     *
     * @param float $suma_reciente Unidades vendidas en la ventana reciente
     * @param float $suma_interanual Unidades vendidas en la misma ventana del año pasado
     * @return float
     */
    protected function mezclar_velocidades(float $suma_reciente, float $suma_interanual): float
    {
        $v_reciente = $suma_reciente / self::VENTANA_DIAS;
        $v_interanual = $suma_interanual / self::VENTANA_DIAS;

        if ($v_interanual > 0) {
            return (self::PESO_VELOCIDAD_RECIENTE * $v_reciente + self::PESO_VELOCIDAD_INTERANUAL * $v_interanual)
                / (self::PESO_VELOCIDAD_RECIENTE + self::PESO_VELOCIDAD_INTERANUAL);
        }

        return $v_reciente;
    }

    /**
     * Días hasta quiebre del destino, o null si la velocidad es cero
     * (cobertura infinita: un artículo que no se vende no es urgente aunque
     * esté en déficit).
     *
     * @param float|null $stock_destino Stock del destino al momento del cálculo
     * @param float $velocidad Velocidad diaria ya mezclada
     * @return float|null
     */
    public function cobertura($stock_destino, $velocidad): ?float
    {
        if ($stock_destino === null) {
            return null;
        }

        if ($velocidad <= 0) {
            return null;
        }

        return round($stock_destino / $velocidad, 2);
    }

    /**
     * ¿La tabla users ya tiene sugerencias_prioridad_destino? Guarda de esquema
     * para la ventana del deploy (el código sube antes que el migrate, o el
     * migrate se traba entre las dos migraciones de la misión): sin ella, leer
     * el criterio o guardarlo desde UserController reventaba. Mismo criterio que
     * Address::columna_madre_existe(): un Schema::hasColumn() por proceso, y se
     * memoiza solo el SÍ para que un queue:work booteado en la ventana no quede
     * clavado en "no existe".
     *
     * @return bool
     */
    public static function columna_prioridad_existe()
    {
        if (self::$columna_prioridad_existe !== true) {
            self::$columna_prioridad_existe = Schema::hasColumn('users', self::COLUMNA_PRIORIDAD);
        }

        return self::$columna_prioridad_existe;
    }

    /**
     * Borra el memo de la guarda (lo usan los tests que esconden la columna).
     *
     * @return void
     */
    public static function olvidar_esquema()
    {
        self::$columna_prioridad_existe = null;
    }

    /**
     * Criterio con el que se reparte desde el depósito madre y se ordena la
     * lista (users.sugerencias_prioridad_destino del comercio dueño). Un valor
     * nulo o fuera de PRIORIDADES_DESTINO cae a 'ventas_sucursal', el default
     * de la columna: un dato raro no puede dejar el reparto sin criterio. Sin
     * la columna todavía (columna_prioridad_existe()), también.
     *
     * @return string Uno de PRIORIDADES_DESTINO
     */
    public function criterio_prioridad_destino()
    {
        if (!self::columna_prioridad_existe()) {
            return self::PRIORIDAD_VENTAS_SUCURSAL;
        }

        $valor = DB::table('users')
            ->where('id', $this->user_id)
            ->value('sugerencias_prioridad_destino');

        if (in_array($valor, self::PRIORIDADES_DESTINO, true)) {
            return $valor;
        }

        return self::PRIORIDAD_VENTAS_SUCURSAL;
    }

    /**
     * Facturación en pesos de cada sucursal del comercio en los últimos
     * VENTANA_DIAS días: "la sucursal que más vende en general" del criterio
     * 'ventas_sucursal' (decisión de Lucas, 2/10/2026: plata facturada, en
     * pesos, 90 días). Es pública porque la usan tres lugares que tienen que dar
     * el mismo número: el reparto de StockSuggestionService, el ranking de
     * asignar_prioridades() y los hechos de RecolectorStock.
     *
     * UNA consulta agregada por sucursal (sales.address_id), nunca una por
     * sucursal ni por artículo. Cuenta solo ventas reales: sin borradas (el
     * SoftDeletes de Sale), sin consolidaciones de facturación
     * (Sale::scopeSoloVentasReales, que si no duplicarían las ventas que
     * agrupan) y en pesos con el mismo criterio que el informe del día
     * (Sale::EXPRESION_EN_PESOS: una venta sin moneda es pesos). Una venta en
     * dólares no se convierte: no suma, igual que en el Rendimiento.
     *
     * Y solo TERMINADAS (sales.terminada = 1), el mismo conjunto que el
     * Rendimiento (RecolectorDia::consulta_ventas): "plata facturada" no puede
     * incluir una venta cargada y todavía sin terminar (extensión check_sales,
     * ventas con fecha de entrega), que puede no concretarse nunca. La ventana
     * va por sales.created_at: la segunda puerta de RecolectorDia (terminada_at
     * en el rango) importa para "el día de ayer", no para 90 días.
     *
     * @return array Mapa address_id (int) => facturación (float, 2 decimales). Una sucursal sin ventas no aparece.
     */
    public function facturacion_por_sucursal(): array
    {
        $filas = Sale::query()
            ->where('sales.user_id', $this->user_id)
            ->soloVentasReales()
            ->where('sales.terminada', 1)
            ->whereRaw(Sale::EXPRESION_EN_PESOS)
            ->whereNotNull('sales.address_id')
            ->where('sales.created_at', '>=', now()->subDays(self::VENTANA_DIAS))
            ->groupBy('sales.address_id')
            ->selectRaw('sales.address_id as address_id, SUM(sales.total) as facturacion')
            ->toBase()
            ->get();

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->address_id] = round((float) $fila->facturacion, 2);
        }

        return $mapa;
    }

    /**
     * EL comparador del orden con depósito madre. Lo usan asignar_prioridades()
     * (las líneas guardadas) y RecolectorStock (los movimientos del informe del
     * mostrador, que se corta en 30): uno solo para que la lista del informe y
     * la prioridad de la sugerencia guardada no se despeguen nunca.
     *
     * Primero el criterio del comercio (lo que pidió Lucas: que manden las
     * sucursales que más venden), y la urgencia desempata:
     *
     *   - 'ventas_sucursal': PRIMERO todos los traslados cuyo destino vende ese
     *     artículo (cobertura no nula) y AL FINAL los de cobertura nula; dentro
     *     de cada bloque, facturación del destino desc → cobertura asc →
     *     cantidad desc → desempate asc.
     *   - 'ventas_articulo': velocidad del artículo en el destino desc →
     *     cobertura asc (nulos al final) → cantidad desc → desempate asc. La
     *     velocidad desc ya deja al final los de velocidad cero.
     *
     * Por qué el bloque de cobertura nula en 'ventas_sucursal' (decisión del
     * orquestador, post-chequeo del 2/10/2026): ordenando solo por facturación,
     * los traslados a la sucursal grande de artículos que ahí NO se venden
     * llenaban los 30 movimientos del informe del mostrador y tapaban quiebres
     * reales de otra sucursal.
     *
     * El REPARTO del stock escaso (StockSuggestionService::ordenar_destinos)
     * usa la MISMA regla (ronda final del 2/10/2026): con 'ventas_sucursal',
     * primero los destinos con velocidad > 0 de ese artículo y después los de
     * velocidad 0, cada grupo por facturación. Cobertura nula y velocidad 0 son
     * el mismo grupo: la cobertura es nula justo cuando la velocidad es 0. Así
     * la sucursal que encabeza la lista es también la que se lleva el stock.
     *
     * Cada lado es un array normalizado: to_address_id (int), velocidad
     * (float, redondeada a 4 como se guarda en velocidad_diaria), cobertura
     * (float|null), cantidad (float) y desempate (int: el id de la línea, o el
     * orden de llegada en el informe, que no tiene id).
     *
     * @param array $a
     * @param array $b
     * @param string $criterio Uno de PRIORIDADES_DESTINO
     * @param array $facturacion Mapa address_id => facturación (facturacion_por_sucursal())
     * @return int
     */
    public function comparar_con_madre(array $a, array $b, $criterio, array $facturacion)
    {
        if ($criterio === self::PRIORIDAD_VENTAS_ARTICULO) {
            if ($a['velocidad'] != $b['velocidad']) {
                return $b['velocidad'] <=> $a['velocidad'];
            }
        } else {
            // Primero el bloque de los destinos que venden el artículo.
            $a_sin_ventas = is_null($a['cobertura']);
            $b_sin_ventas = is_null($b['cobertura']);

            if ($a_sin_ventas !== $b_sin_ventas) {
                return $a_sin_ventas ? 1 : -1;
            }

            $facturacion_a = isset($facturacion[$a['to_address_id']]) ? $facturacion[$a['to_address_id']] : 0.0;
            $facturacion_b = isset($facturacion[$b['to_address_id']]) ? $facturacion[$b['to_address_id']] : 0.0;

            if ($facturacion_a != $facturacion_b) {
                return $facturacion_b <=> $facturacion_a;
            }
        }

        $a_nula = is_null($a['cobertura']);
        $b_nula = is_null($b['cobertura']);

        if ($a_nula !== $b_nula) {
            return $a_nula ? 1 : -1;
        }

        if (!$a_nula && $a['cobertura'] != $b['cobertura']) {
            return $a['cobertura'] <=> $b['cobertura'];
        }

        if ($a['cantidad'] != $b['cantidad']) {
            return $b['cantidad'] <=> $a['cantidad'];
        }

        return $a['desempate'] <=> $b['desempate'];
    }

    /**
     * Materializa el ranking 1..N de una sugerencia terminada, en una sola
     * pasada global (no por chunk: numerar por chunk daría varias
     * "prioridad 1" en la misma corrida).
     *
     * Orden: cobertura ascendente, NULLs (cobertura infinita) al final,
     * desempate por cantidad sugerida descendente, y por id para que el
     * ranking sea estable entre corridas del mismo dato.
     *
     * Con depósito madre del comercio (Address::deposito_madre_de) manda el
     * criterio de prioridad y la cobertura desempata: ver
     * ids_ordenados_con_madre() y comparar_con_madre(). Sin madre, la consulta
     * es la de siempre, sin un cambio.
     *
     * @param int $stock_suggestion_id
     * @return void
     */
    public function asignar_prioridades($stock_suggestion_id)
    {
        if (!is_null(Address::deposito_madre_de($this->user_id))) {
            $ids_ordenados = $this->ids_ordenados_con_madre($stock_suggestion_id);
        } else {
            $ids_ordenados = StockSuggestionArticle::where('stock_suggestion_id', $stock_suggestion_id)
                ->orderByRaw('cobertura_dias IS NULL ASC')
                ->orderBy('cobertura_dias', 'ASC')
                ->orderBy('suggested_amount', 'DESC')
                ->orderBy('id', 'ASC')
                ->pluck('id');
        }

        $prioridad = 1;

        foreach ($ids_ordenados->chunk(self::LOTE_UPDATE_PRIORIDADES) as $lote) {

            $cases = [];
            $ids = [];

            foreach ($lote as $id) {
                $cases[] = 'WHEN ' . (int) $id . ' THEN ' . $prioridad;
                $ids[] = (int) $id;
                $prioridad++;
            }

            // Un UPDATE por lote (ids propios, casteados a int: no hay input
            // de usuario acá) en vez de un UPDATE por fila.
            DB::update(
                'UPDATE stock_suggestion_articles
                 SET prioridad = CASE id ' . implode(' ', $cases) . ' END
                 WHERE id IN (' . implode(',', $ids) . ')'
            );
        }
    }

    /**
     * Ids de las líneas de la sugerencia en el orden con depósito madre. Se
     * ordena en PHP con comparar_con_madre() (y no en SQL) para que el ranking
     * guardado y el informe del mostrador usen literalmente el mismo
     * comparador. Una sola consulta para traer las líneas y, con el criterio
     * 'ventas_sucursal', una más para la facturación: nunca una por línea.
     *
     * @param int $stock_suggestion_id
     * @return \Illuminate\Support\Collection Ids en orden de prioridad
     */
    protected function ids_ordenados_con_madre($stock_suggestion_id)
    {
        $criterio = $this->criterio_prioridad_destino();

        $facturacion = $criterio === self::PRIORIDAD_VENTAS_SUCURSAL
            ? $this->facturacion_por_sucursal()
            : [];

        $lineas = DB::table('stock_suggestion_articles')
            ->where('stock_suggestion_id', $stock_suggestion_id)
            ->get(['id', 'to_address_id', 'velocidad_diaria', 'cobertura_dias', 'suggested_amount']);

        $filas = [];

        foreach ($lineas as $linea) {
            $filas[] = [
                'id'            => (int) $linea->id,
                'to_address_id' => (int) $linea->to_address_id,
                'velocidad'     => (float) $linea->velocidad_diaria,
                'cobertura'     => is_null($linea->cobertura_dias) ? null : (float) $linea->cobertura_dias,
                'cantidad'      => (float) $linea->suggested_amount,
                'desempate'     => (int) $linea->id,
            ];
        }

        // Las filas de la base ya están copiadas en $filas: se suelta la colección antes del
        // usort para no tener las dos en memoria en el pico (una sugerencia grande tiene
        // decenas de miles de líneas).
        unset($lineas);

        usort($filas, function ($a, $b) use ($criterio, $facturacion) {
            return $this->comparar_con_madre($a, $b, $criterio, $facturacion);
        });

        return collect(array_column($filas, 'id'));
    }
}
