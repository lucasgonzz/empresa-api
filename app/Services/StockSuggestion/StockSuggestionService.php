<?php

namespace App\Services\StockSuggestion;

use App\Models\Address;
use App\Models\Article;
use Illuminate\Support\Collection;

/**
 * Calcula traslados sugeridos entre depósitos según stock min/max por artículo.
 *
 * v2: respeta los depósitos designados como origen preferente
 * (addresses.es_deposito_origen), suma el objetivo 'maximo' y scopea el
 * catálogo al comercio dueño de la sugerencia (user_id), no al de la instancia.
 *
 * v3 (misión deposito-madre, 2/10/2026): DEPÓSITO MADRE. Si el comercio marcó
 * una sucursal como madre (addresses.es_deposito_madre, una sola por comercio),
 * el madre MANDA:
 *
 *   - las sugerencias salen primero del madre hacia las demás, y lo que el madre
 *     no cubre se completa desde sucursales a las que les sobra (respaldo; ahí
 *     los designados es_deposito_origen van primero);
 *   - el madre nunca es destino: se repone por compras, no por traslados;
 *   - cuando no alcanza para todas, se lo llevan primero las que más venden,
 *     con el criterio del comercio (users.sugerencias_prioridad_destino:
 *     facturación de la sucursal o ventas de ESE artículo);
 *   - NO hay escalón 4: nunca se le saca stock a una sucursal en déficit para
 *     dárselo a otra en déficit, porque eso desharía la prioridad por ventas.
 *
 * Sin madre, todo sigue EXACTAMENTE como en v2 (build_suggestions_for_article
 * toma el camino de siempre); lo único que se suma es la consulta, una vez por
 * instancia, que averigua que no hay madre.
 */
class StockSuggestionService
{
    /** @var \App\Models\StockSuggestion Configuración de la sugerencia (modo, origen, límite, user_id) */
    protected $suggestion;

    /** @var bool Si ya se buscó el depósito madre (lazy: una vez por instancia, nunca por artículo) */
    protected $madre_cargado = false;

    /** @var int|null Id del depósito madre del comercio dueño de la sugerencia, null sin madre */
    protected $madre_id = null;

    /** @var string|null Criterio de prioridad (CoberturaService::PRIORIDAD_*), solo con madre */
    protected $criterio = null;

    /** @var array Mapa address_id => facturación en pesos de 90 días, solo con madre */
    protected $facturacion = [];

    /** @var array Velocidades del lote de 50 en curso (CoberturaService::clave_para => float), solo con madre y criterio 'ventas_articulo' */
    protected $velocidades_lote = [];

    /** @var CoberturaService|null */
    protected $cobertura_service = null;

    /**
     * @param \App\Models\StockSuggestion $suggestion Registro con modo, origen y limite_origen
     */
    public function __construct($suggestion)
    {
        $this->suggestion = $suggestion;
    }

    /**
     * Sugerencias para todo el catálogo.
     *
     * @return Collection
     */
    public function getSuggestions(): Collection
    {
        return $this->getSuggestionsForArticles([]);
    }

    /**
     * Sugerencias para un subconjunto de artículos (lote de procesamiento).
     *
     * @param array $article_ids IDs de artículos; vacío = todos
     * @return Collection
     */
    public function getSuggestionsForArticles(array $article_ids = []): Collection
    {
        $suggestions = collect();

        // Se filtra por el dueño de la sugerencia (no por config('app.USER_ID')):
        // en producción es lo mismo, pero en la base de testing conviven varios
        // user_id y sin este where el cálculo mezclaba catálogos ajenos.
        $query = Article::with(['addresses'])
            ->where('user_id', $this->suggestion->user_id);
        if (!empty($article_ids)) {
            $query->whereIn('id', $article_ids);
        }

        $query->chunk(50, function ($articles) use (&$suggestions) {
            // Con madre y criterio 'ventas_articulo': UNA consulta de velocidades
            // por lote de 50, nunca una por artículo. Sin madre no hace nada.
            $this->preparar_lote($articles);

            foreach ($articles as $article) {
                $article_suggestions = $this->build_suggestions_for_article($article);
                foreach ($article_suggestions as $item) {
                    $suggestions->push($item);
                }
            }
        });

        return $suggestions;
    }

    /**
     * Arma sugerencias de traslado para un solo artículo.
     *
     * @param Article $article
     * @return array
     */
    protected function build_suggestions_for_article(Article $article): array
    {
        $madre_id = $this->deposito_madre_id();

        // Con depósito madre el reparto es otro (ver build_suggestions_con_madre).
        // Sin madre, de acá para abajo es el cálculo de siempre.
        if (!is_null($madre_id)) {
            return $this->build_suggestions_con_madre($article, $madre_id);
        }

        $suggestions = [];
        $stock_data = $this->datos_de_stock($article);

        if (empty($stock_data)) {
            return $suggestions;
        }

        $deficits = $this->deficits_de($stock_data);

        if (empty($deficits)) {
            return $suggestions;
        }

        $origin = $this->obtenerOrigen($stock_data, $deficits);
        if (!$origin) {
            return $suggestions;
        }

        $limite = $this->resolve_limite_origen($origin);
        $disponible = max(0, $origin['amount'] - $limite);

        foreach ($deficits as $deficit) {
            if ($disponible <= 0) {
                break;
            }

            // No sugerir traslado al mismo depósito
            if ($deficit['to_address_id'] === $origin['address_id']) {
                continue;
            }

            $mover = min($deficit['needed'], $disponible);
            if ($mover > 0) {
                $suggestions[] = [
                    'article_id' => $article->id,
                    'from_address_id' => $origin['address_id'],
                    'to_address_id' => $deficit['to_address_id'],
                    'suggested_amount' => $mover,
                    'stock_destino' => $deficit['stock_destino'],
                ];
                $disponible -= $mover;
            }
        }

        return $suggestions;
    }

    /**
     * Stock de un artículo en cada sucursal donde tiene fila, con su objetivo
     * ideal y la designación de origen. Una sucursal sin amount no entra (min/max
     * pueden faltar, amount no). Es lo que armaba build_suggestions_for_article
     * inline: se extrajo tal cual para que el camino con madre lea exactamente
     * los mismos datos.
     *
     * @param Article $article
     * @return array
     */
    protected function datos_de_stock(Article $article): array
    {
        $stock_data = [];

        foreach ($article->addresses as $address) {
            $pivot = $address->pivot;

            // amount es obligatorio; min/max pueden faltar en depósitos que solo tienen stock
            if (!isset($pivot->amount) || $pivot->amount === '' || $pivot->amount === null) {
                continue;
            }

            $stock_min = $pivot->stock_min;
            $stock_max = $pivot->stock_max;

            $ideal = null;
            if ($stock_min !== null && $stock_max !== null) {
                $ideal = ($stock_min + $stock_max) / 2;
            }

            $stock_data[] = [
                'address_id' => $address->id,
                'amount' => (float) $pivot->amount,
                'stock_min' => $stock_min !== null ? (float) $stock_min : null,
                'stock_max' => $stock_max !== null ? (float) $stock_max : null,
                'ideal' => $ideal,
                // Designación de depósito de origen preferente (v2). Reemplaza
                // al viejo is_central, que leía una columna inexistente.
                'es_deposito_origen' => (bool) ($address->es_deposito_origen ?? false),
            ];
        }

        return $stock_data;
    }

    /**
     * Sucursales por debajo de su objetivo (resolve_objetivo), en el orden en que
     * vienen, con lo que les falta redondeado a entero. Extraído tal cual de
     * build_suggestions_for_article, igual que datos_de_stock().
     *
     * @param array $stock_data
     * @return array [['to_address_id' =>, 'needed' => int, 'stock_destino' => float], ...]
     */
    protected function deficits_de(array $stock_data): array
    {
        $deficits = [];
        foreach ($stock_data as $data) {
            $objetivo = $this->resolve_objetivo($data);
            if ($objetivo === null) {
                continue;
            }
            if ($data['amount'] < $objetivo) {
                $deficits[] = [
                    'to_address_id' => $data['address_id'],
                    'needed' => (int) round($objetivo - $data['amount']),
                    // Stock del destino al momento del cálculo: viaja con la
                    // sugerencia para que la cobertura quede auditable sin
                    // re-consultar address_article.
                    'stock_destino' => $data['amount'],
                ];
            }
        }

        return $deficits;
    }

    /**
     * Reparto CON depósito madre (v3). Cuatro pasos, en este orden:
     *
     *   1. Destinos: las sucursales en déficit contra el objetivo, sin el madre
     *      (el madre se repone por compras, no por traslados).
     *   2. Orden de los destinos (ordenar_destinos): decide quién se lleva el
     *      stock cuando no alcanza, según el criterio del comercio.
     *   3. Orígenes (origenes_con_madre): primero el madre; después el respaldo,
     *      solo sucursales SIN déficit.
     *   4. Reparto: cada destino, en su orden, se llena desde los orígenes en su
     *      orden hasta cubrir lo que le falta o agotarlos. Un destino puede
     *      recibir de dos orígenes (dos líneas). Misma aritmética que sin madre:
     *      `needed` entero, min() contra el disponible, nunca cantidades <= 0.
     *
     * La forma de cada línea es la de siempre: article_id, from_address_id,
     * to_address_id, suggested_amount, stock_destino.
     *
     * @param Article $article
     * @param int $madre_id
     * @return array
     */
    protected function build_suggestions_con_madre(Article $article, $madre_id): array
    {
        $suggestions = [];
        $stock_data = $this->datos_de_stock($article);

        if (empty($stock_data)) {
            return $suggestions;
        }

        $deficits = $this->deficits_de($stock_data);

        // 1. Destinos: todo déficit menos el del madre.
        $destinos = [];
        foreach ($deficits as $deficit) {
            if ((int) $deficit['to_address_id'] !== $madre_id) {
                $destinos[] = $deficit;
            }
        }

        if (empty($destinos)) {
            return $suggestions;
        }

        // 2. Quién se lleva primero.
        $destinos = $this->ordenar_destinos($article->id, $destinos);

        // 3. De dónde sale, en orden.
        $origenes = $this->origenes_con_madre($stock_data, $deficits, $madre_id);

        if (empty($origenes)) {
            return $suggestions;
        }

        // 4. Reparto.
        foreach ($destinos as $destino) {
            $falta = $destino['needed'];

            foreach ($origenes as $indice => $origen) {
                if ($falta <= 0) {
                    break;
                }

                if ($origenes[$indice]['disponible'] <= 0) {
                    continue;
                }

                $mover = min($falta, $origenes[$indice]['disponible']);
                if ($mover > 0) {
                    $suggestions[] = [
                        'article_id' => $article->id,
                        'from_address_id' => $origen['address_id'],
                        'to_address_id' => $destino['to_address_id'],
                        'suggested_amount' => $mover,
                        'stock_destino' => $destino['stock_destino'],
                    ];
                    $origenes[$indice]['disponible'] -= $mover;
                    $falta -= $mover;
                }
            }
        }

        return $suggestions;
    }

    /**
     * Orden de los destinos con madre, según el criterio del comercio (decisión
     * de Lucas, 2/10/2026: el reparto prioriza a las sucursales que más venden):
     *
     *   - 'ventas_sucursal': facturación de 90 días desc; desempata address_id asc.
     *   - 'ventas_articulo': velocidad de venta de ESTE artículo en el destino
     *     desc; desempata la facturación desc y después address_id asc.
     *
     * El address_id final hace el orden determinístico (usort no es estable).
     *
     * @param int $article_id
     * @param array $destinos Déficits de deficits_de(), sin el madre
     * @return array
     */
    protected function ordenar_destinos($article_id, array $destinos): array
    {
        $por_articulo = $this->criterio === CoberturaService::PRIORIDAD_VENTAS_ARTICULO;
        $facturacion = $this->facturacion;

        usort($destinos, function ($a, $b) use ($article_id, $por_articulo, $facturacion) {
            $id_a = (int) $a['to_address_id'];
            $id_b = (int) $b['to_address_id'];

            if ($por_articulo) {
                $velocidad_a = $this->velocidad_en($article_id, $id_a);
                $velocidad_b = $this->velocidad_en($article_id, $id_b);

                if ($velocidad_a != $velocidad_b) {
                    return $velocidad_b <=> $velocidad_a;
                }
            }

            $facturacion_a = isset($facturacion[$id_a]) ? $facturacion[$id_a] : 0.0;
            $facturacion_b = isset($facturacion[$id_b]) ? $facturacion[$id_b] : 0.0;

            if ($facturacion_a != $facturacion_b) {
                return $facturacion_b <=> $facturacion_a;
            }

            return $id_a <=> $id_b;
        });

        return $destinos;
    }

    /**
     * Orígenes con madre, en el orden en que se vacían:
     *
     *   1. El madre, si tiene fila del artículo con stock por encima de su
     *      límite (resolve_limite_origen, como cualquier origen).
     *   2. Respaldo: sucursales SIN déficit, distintas del madre, con stock por
     *      encima de su propio límite. Primero las designadas es_deposito_origen
     *      (con madre ese tilde solo sirve para esto) y, dentro de cada grupo, el
     *      criterio `origen` de siempre (absoluto = mayor stock; relativo = mayor
     *      % sobre el máximo); address_id asc desempata.
     *
     * 🔴 No hay escalón 4 a propósito: una sucursal en déficit nunca es origen,
     * ni aunque sea designada. Sacarle stock a una sucursal que le falta para
     * dárselo a otra que también le falta iría contra la prioridad por ventas.
     *
     * @param array $stock_data
     * @param array $deficits TODOS los déficits del artículo (incluido el del madre)
     * @param int $madre_id
     * @return array [['address_id' =>, 'disponible' => float], ...]
     */
    protected function origenes_con_madre(array $stock_data, array $deficits, $madre_id): array
    {
        $origenes = [];
        $deficit_ids = array_map('intval', array_column($deficits, 'to_address_id'));

        // 1. El madre.
        foreach ($stock_data as $data) {
            if ((int) $data['address_id'] !== $madre_id) {
                continue;
            }

            $disponible = max(0, $data['amount'] - $this->resolve_limite_origen($data));
            if ($disponible > 0) {
                $origenes[] = ['address_id' => $data['address_id'], 'disponible' => $disponible];
            }
            break;
        }

        // 2. Respaldo.
        $respaldo = [];
        foreach ($stock_data as $data) {
            $address_id = (int) $data['address_id'];

            if ($address_id === $madre_id || in_array($address_id, $deficit_ids, true)) {
                continue;
            }

            $limite = $this->resolve_limite_origen($data);
            if ($data['amount'] <= $limite) {
                continue;
            }

            $data['disponible'] = $data['amount'] - $limite;
            $respaldo[] = $data;
        }

        usort($respaldo, function ($a, $b) {
            if ($a['es_deposito_origen'] !== $b['es_deposito_origen']) {
                return $a['es_deposito_origen'] ? -1 : 1;
            }

            $puntaje_a = $this->puntaje_de_origen($a);
            $puntaje_b = $this->puntaje_de_origen($b);

            if ($puntaje_a != $puntaje_b) {
                return $puntaje_b <=> $puntaje_a;
            }

            return (int) $a['address_id'] <=> (int) $b['address_id'];
        });

        foreach ($respaldo as $data) {
            $origenes[] = ['address_id' => $data['address_id'], 'disponible' => $data['disponible']];
        }

        return $origenes;
    }

    /**
     * Valor con el que el criterio `origen` de la sugerencia compara dos
     * orígenes: el mismo cálculo que obtenerOrigen() (absoluto = stock;
     * relativo = stock sobre el máximo, o el mínimo, o 1).
     *
     * @param array $data
     * @return float
     */
    protected function puntaje_de_origen(array $data)
    {
        if ($this->suggestion->origen === 'relativo') {
            $max = $data['stock_max'] ?? $data['stock_min'] ?? 1;
            return $data['amount'] / max($max, 1);
        }

        return $data['amount'];
    }

    /**
     * Id del depósito madre del comercio dueño de la sugerencia, o null. Se
     * resuelve una sola vez por instancia (lazy), y con madre se cargan acá
     * también el criterio y la facturación de las sucursales (una consulta
     * agregada cada uno): ni una consulta más por artículo.
     *
     * La facturación se carga en los dos criterios: en 'ventas_sucursal' ordena
     * los destinos y en 'ventas_articulo' desempata.
     *
     * @return int|null
     */
    protected function deposito_madre_id()
    {
        if ($this->madre_cargado) {
            return $this->madre_id;
        }

        $this->madre_cargado = true;

        $madre = Address::deposito_madre_de($this->suggestion->user_id);

        if (is_null($madre)) {
            return null;
        }

        $this->madre_id = (int) $madre->id;
        $this->criterio = $this->cobertura()->criterio_prioridad_destino();
        $this->facturacion = $this->cobertura()->facturacion_por_sucursal();

        return $this->madre_id;
    }

    /**
     * Antes de cada lote de 50 artículos: con madre y criterio 'ventas_articulo',
     * trae en UNA llamada a CoberturaService::velocidades_para() la velocidad de
     * cada artículo del lote en cada una de sus sucursales (menos el madre, que
     * nunca es destino). Sin madre, o con 'ventas_sucursal', no consulta nada.
     *
     * @param \Illuminate\Support\Collection $articles
     * @return void
     */
    protected function preparar_lote($articles)
    {
        $this->velocidades_lote = [];

        if (is_null($this->deposito_madre_id()) || $this->criterio !== CoberturaService::PRIORIDAD_VENTAS_ARTICULO) {
            return;
        }

        $pares = [];

        foreach ($articles as $article) {
            foreach ($article->addresses as $address) {
                if ((int) $address->id === $this->madre_id) {
                    continue;
                }

                $pares[] = ['article_id' => $article->id, 'address_id' => $address->id];
            }
        }

        $this->velocidades_lote = $this->cobertura()->velocidades_para($pares);
    }

    /**
     * Velocidad de venta diaria de un artículo en una sucursal, del lote en curso.
     *
     * @param int $article_id
     * @param int $address_id
     * @return float
     */
    protected function velocidad_en($article_id, $address_id)
    {
        $clave = $this->cobertura()->clave_para($article_id, $address_id);

        return isset($this->velocidades_lote[$clave]) ? (float) $this->velocidades_lote[$clave] : 0.0;
    }

    /**
     * CoberturaService del comercio dueño de la sugerencia (uno por instancia).
     *
     * @return CoberturaService
     */
    protected function cobertura()
    {
        if (is_null($this->cobertura_service)) {
            $this->cobertura_service = new CoberturaService($this->suggestion->user_id);
        }

        return $this->cobertura_service;
    }

    /**
     * Objetivo de stock según modo de la sugerencia (minimo / ideal / maximo).
     *
     * @param array $data Datos de un depósito del artículo
     * @return float|null null si no hay datos para calcular objetivo
     */
    protected function resolve_objetivo(array $data): ?float
    {
        if ($this->suggestion->modo === 'maximo') {
            if ($data['stock_max'] !== null) {
                return $data['stock_max'];
            }
            // Sin máximo definido se degrada al ideal, y sin ideal al mínimo
            // (misma cadena de fallback que el resto de los modos).
            if ($data['ideal'] !== null) {
                return $data['ideal'];
            }
            return $data['stock_min'];
        }

        if ($this->suggestion->modo === 'ideal') {
            if ($data['ideal'] !== null) {
                return $data['ideal'];
            }
            // Sin máximo definido: ideal = mínimo si existe
            return $data['stock_min'];
        }

        return $data['stock_min'];
    }

    /**
     * Stock mínimo que debe quedar en el depósito origen según limite_origen.
     *
     * @param array $origin
     * @return float
     */
    protected function resolve_limite_origen(array $origin): float
    {
        if ($this->suggestion->limite_origen === 'ideal') {
            return $origin['ideal'] ?? ($origin['stock_min'] ?? 0);
        }
        if ($this->suggestion->limite_origen === 'sin_limite') {
            return 0;
        }
        if ($this->suggestion->limite_origen === 'minimo') {
            return $origin['stock_min'] ?? 0;
        }

        return $origin['stock_min'] ?? 0;
    }

    /**
     * Elige el depósito origen en cuatro escalones de preferencia:
     *
     *   (1) designados (es_deposito_origen) sin déficit
     *   (2) designados aunque estén en déficit — marcar un depósito no puede
     *       dejarlo afuera para siempre por estar bajo su propio mínimo
     *   (3) no designados sin déficit (el comportamiento histórico)
     *   (4) cualquiera — todos en déficit: usar el que pueda mover algo
     *
     * Un designado solo cuenta en (1)/(2) si tiene stock por encima de su
     * propio limite_origen: un depósito designado y vacío no puede paralizar
     * la reposición de toda la red, así que se cae a (3).
     *
     * Dentro del escalón elegido se aplica el criterio `origen` de siempre
     * (absoluto = mayor stock; relativo = mayor % sobre el máximo).
     *
     * @param array $stock_data
     * @param array $deficits
     * @return array|null
     */
    protected function obtenerOrigen(array $stock_data, array $deficits): ?array
    {
        $deficit_ids = array_column($deficits, 'to_address_id');

        $designados_con_stock = collect($stock_data)->filter(function ($d) {
            return $d['es_deposito_origen'] && $d['amount'] > $this->resolve_limite_origen($d);
        });

        // Escalón 1: designados sin déficit.
        $candidatos = $designados_con_stock->filter(function ($d) use ($deficit_ids) {
            return !in_array($d['address_id'], $deficit_ids);
        });

        // Escalón 2: designados aunque estén en déficit.
        if ($candidatos->isEmpty()) {
            $candidatos = $designados_con_stock;
        }

        // Escalón 3: sin designados utilizables, comportamiento histórico.
        if ($candidatos->isEmpty()) {
            $candidatos = collect($stock_data)->filter(function ($d) use ($deficit_ids) {
                return !$d['es_deposito_origen'] && !in_array($d['address_id'], $deficit_ids);
            });
        }

        // Escalón 4: todos en déficit — usar el que más stock tenga para poder mover algo.
        if ($candidatos->isEmpty()) {
            $candidatos = collect($stock_data);
        }

        if ($candidatos->isEmpty()) {
            return null;
        }

        if ($this->suggestion->origen === 'relativo') {
            return $candidatos->sortByDesc(function ($d) {
                $max = $d['stock_max'] ?? $d['stock_min'] ?? 1;
                return $d['amount'] / max($max, 1);
            })->first();
        }

        return $candidatos->sortByDesc('amount')->first();
    }
}
