<?php

namespace App\Services\StockSuggestion;

use App\Models\StockSuggestionArticle;
use Illuminate\Support\Facades\DB;

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
     * Materializa el ranking 1..N de una sugerencia terminada, en una sola
     * pasada global (no por chunk: numerar por chunk daría varias
     * "prioridad 1" en la misma corrida).
     *
     * Orden: cobertura ascendente, NULLs (cobertura infinita) al final,
     * desempate por cantidad sugerida descendente, y por id para que el
     * ranking sea estable entre corridas del mismo dato.
     *
     * @param int $stock_suggestion_id
     * @return void
     */
    public function asignar_prioridades($stock_suggestion_id)
    {
        $ids_ordenados = StockSuggestionArticle::where('stock_suggestion_id', $stock_suggestion_id)
            ->orderByRaw('cobertura_dias IS NULL ASC')
            ->orderBy('cobertura_dias', 'ASC')
            ->orderBy('suggested_amount', 'DESC')
            ->orderBy('id', 'ASC')
            ->pluck('id');

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
}
