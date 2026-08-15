<?php

namespace App\Services\Compras;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Histórico de precios ofertados por proveedor (App\Models\ProviderPriceOffer):
 * único punto de escritura y de lectura masiva de la tabla `provider_price_offers`.
 * Ningún otro lugar del código escribe en esa tabla directamente (salvo la
 * migración de dedupe del pivot, que rescata filas viejas con su propia
 * fecha histórica y por eso no pasa por acá).
 */
class OfertasDeProveedorService
{
    /** Tamaño de lote del upsert masivo (mismo orden de magnitud que provider_relations_buffer) */
    const LOTE_UPSERT = 1000;

    /** Un costo nuevo se considera "igual" al último vigente si difiere menos que esto */
    const EPSILON_COSTO = 0.000001;

    /** Moneda de una oferta que no trae moneda_id explícito (1 = Peso) */
    const MONEDA_POR_DEFECTO = 1;

    /**
     * Arreglo post-chequeo (A3). Aunque el costo no haya cambiado, se escribe
     * una fila de reconfirmación si la última vigente tiene MÁS de esta
     * cantidad de días. Por qué hace falta: registrar_lote() lee "la última
     * fila" SIN ventana de fecha (mira todo el historial), pero
     * mejores_ofertas_para() sí filtra por `fecha >= hoy - dias_vigencia`
     * (120 por default). Sin este reconfirmado, un proveedor que manda la
     * misma lista de precios todas las semanas deja UNA sola fila -- la del
     * primer día -- y al día 121 esa fila deja de estar vigente: el
     * proveedor deja de competir por "el más barato" aunque siga ofreciendo
     * exactamente lo mismo hoy. 30 días asegura que, con dias_vigencia en su
     * default de 120, siempre quede al menos una fila de los últimos 30 días
     * dentro de la ventana de 120: nunca se llega a la fecha de corte sin
     * una fila reciente que la sostenga.
     */
    const DIAS_RECONFIRMACION = 30;

    /**
     * Registra un lote de ofertas de precio, aplicando la regla de
     * deduplicación temporal: una fila nueva SOLO si el costo cambió
     * respecto de la última fila vigente de ese par (artículo, proveedor).
     *
     * 🔴 Por qué no es "insertar todo lo que llega": una lista de precios de
     * un proveedor se reimporta entera cada semana (y cada compra real
     * vuelve a escribir el mismo costo muchas veces). Sin este filtro,
     * "una fila por evento" son millones de filas idénticas con el correr
     * de los meses — exactamente lo que esta tabla nace para evitar (ver el
     * docblock de la migración create_provider_price_offers_table). Leer
     * antes de escribir cuesta UNA query; no leer cuesta una tabla que no
     * se puede consultar en un año.
     *
     * Algoritmo, en este orden (no reordenar: cada paso depende del anterior):
     * 1. Aplanar el buffer y descartar de entrada lo que NUNCA se escribe:
     *    ids no positivos (nunca un fake_id: eso lo filtra el llamador antes
     *    de bufferear, pero acá se vuelve a chequear porque este es el único
     *    punto de escritura) y cost null o <= 0.
     * 2. Leer, en UNA sola query (whereIn sobre los article_id del lote), la
     *    última fila vigente de cada par (article_id, provider_id) que ya
     *    exista en la tabla, con su costo Y su fecha — sin filtrar por
     *    origen a propósito: un precio cargado por importación y después
     *    confirmado por una compra real es la misma "verdad" a efectos de
     *    detectar si cambió.
     * 3. Descartar las entradas cuyo costo sea igual, con epsilon 0.000001
     *    (nunca "==" a secas: son decimales que vienen de un Excel o de un
     *    cálculo previo, y una comparación exacta dejaría pasar diferencias
     *    de redondeo de centésimas de centavo como si fueran "cambios de
     *    precio" reales) al de esa última fila — EXCEPTO si esa última fila
     *    tiene más de DIAS_RECONFIRMACION (30) días: ahí se escribe de todos
     *    modos, para reconfirmar que el proveedor sigue vigente (ver el
     *    docblock de la constante — arreglo A3 post-chequeo: sin esto, un
     *    precio estable deja una sola fila que termina venciendo en
     *    mejores_ofertas_para() aunque el proveedor lo siga ofertando hoy).
     * 4. Las que sobreviven se escriben con upsert() por tandas de 1000
     *    contra el unique (article_id, provider_id, fecha, origen), pisando
     *    SOLO cost/provider_code/referencia_id/updated_at — nunca user_id,
     *    moneda_id, origen ni fecha, que son los que identifican la fila.
     *    Efecto: dos llamadas el mismo día con precios distintos dejan una
     *    fila con el último precio; en días distintos, dos filas (la fecha
     *    entra en el unique, así que "otro día" es otra fila por diseño).
     *
     * 🔴 Nunca hace una escritura por fila: sea cual sea el tamaño del
     * buffer, esto es UNA query de lectura + como máximo N/1000 upserts. Un
     * chunk de importación de decenas de miles de artículos no puede
     * convertirse en decenas de miles de INSERTs.
     *
     * @param array $buffer [article_id => [provider_id => ['cost' => float|string, 'provider_code' => string|null, 'moneda_id' => int|null]]]
     * @param int $user_id Dueño de las ofertas (articles.user_id)
     * @param string $origen 'importacion' | 'compra' | 'manual', una sola para TODO el lote
     * @param int|null $referencia_id import_history_id | provider_order_id | null
     * @return void
     */
    public static function registrar_lote(array $buffer, int $user_id, string $origen, ?int $referencia_id): void
    {
        if (empty($buffer)) {
            return;
        }

        // Paso 1: aplanar el buffer [article_id][provider_id] => datos y
        // descartar de entrada lo que nunca se escribe.
        $candidatas = [];

        foreach ($buffer as $article_id => $ofertas_por_proveedor) {
            $article_id = (int) $article_id;

            if ($article_id <= 0 || empty($ofertas_por_proveedor)) {
                continue;
            }

            foreach ($ofertas_por_proveedor as $provider_id => $oferta) {
                $provider_id = (int) $provider_id;

                if ($provider_id <= 0) {
                    continue;
                }

                $cost = isset($oferta['cost']) ? (float) $oferta['cost'] : null;

                // Nunca escribe cost null o <= 0.
                if ($cost === null || $cost <= 0) {
                    continue;
                }

                $candidatas[] = [
                    'article_id' => $article_id,
                    'provider_id' => $provider_id,
                    'provider_code' => isset($oferta['provider_code']) ? $oferta['provider_code'] : null,
                    'cost' => $cost,
                    'moneda_id' => isset($oferta['moneda_id']) ? (int) $oferta['moneda_id'] : self::MONEDA_POR_DEFECTO,
                ];
            }
        }

        if (empty($candidatas)) {
            return;
        }

        // Paso 2: UNA query de lectura para todo el lote (nunca una por artículo).
        $article_ids = array_values(array_unique(array_column($candidatas, 'article_id')));

        $filas_existentes = DB::table('provider_price_offers')
            ->where('user_id', $user_id)
            ->whereIn('article_id', $article_ids)
            ->orderBy('article_id')
            ->orderBy('provider_id')
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->select(['article_id', 'provider_id', 'cost', 'fecha'])
            ->get();

        // [article_id][provider_id] => ['cost' => float, 'fecha' => 'Y-m-d']
        // Se guarda también la fecha (antes solo el costo) para poder decidir
        // la reconfirmación del paso 3 (A3): hace falta saber CUÁNTO hace que
        // no se escribe una fila de este par, no solo si el costo cambió.
        $ultima_fila_vigente = [];

        foreach ($filas_existentes as $fila) {
            $clave_article = (int) $fila->article_id;
            $clave_provider = (int) $fila->provider_id;

            // El ORDER BY ya deja la más reciente primero dentro de cada
            // par: la primera vez que aparece un (article_id, provider_id)
            // es la vigente. Las filas siguientes del mismo par son
            // historial más viejo y no interesan acá.
            if (!isset($ultima_fila_vigente[$clave_article][$clave_provider])) {
                $ultima_fila_vigente[$clave_article][$clave_provider] = [
                    'cost' => (float) $fila->cost,
                    'fecha' => $fila->fecha,
                ];
            }
        }

        // Paso 3 y 4: descartar sin cambio de costo, armar filas y upsert por tandas.
        $ahora = now();
        $fecha_hoy = $ahora->toDateString();
        $filas_a_escribir = [];

        foreach ($candidatas as $candidata) {
            $previa = isset($ultima_fila_vigente[$candidata['article_id']][$candidata['provider_id']])
                ? $ultima_fila_vigente[$candidata['article_id']][$candidata['provider_id']]
                : null;

            // Sin fila previa: siempre es una novedad y se escribe. Con fila
            // previa: solo se descarta si el costo NO cambió (epsilon) Y
            // además esa fila previa todavía está dentro de la ventana de
            // reconfirmación (A3). Pasados los DIAS_RECONFIRMACION días, se
            // escribe igual aunque el costo sea el mismo — ver el docblock
            // de la constante y del método.
            if ($previa !== null && abs($previa['cost'] - $candidata['cost']) < self::EPSILON_COSTO) {
                $dias_desde_la_ultima = Carbon::parse($previa['fecha'])->diffInDays($ahora);

                if ($dias_desde_la_ultima < self::DIAS_RECONFIRMACION) {
                    continue;
                }
                // Si no: cae al armado de abajo y escribe una fila de
                // reconfirmación con el mismo costo y fecha = hoy.
            }

            $filas_a_escribir[] = [
                'user_id' => $user_id,
                'article_id' => $candidata['article_id'],
                'provider_id' => $candidata['provider_id'],
                'provider_code' => $candidata['provider_code'],
                'cost' => $candidata['cost'],
                'moneda_id' => $candidata['moneda_id'],
                'origen' => $origen,
                'fecha' => $fecha_hoy,
                'referencia_id' => $referencia_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        if (empty($filas_a_escribir)) {
            return;
        }

        foreach (array_chunk($filas_a_escribir, self::LOTE_UPSERT) as $lote) {
            DB::table('provider_price_offers')->upsert(
                $lote,
                ['article_id', 'provider_id', 'fecha', 'origen'],
                /*
                 * `moneda_id` va en la lista de columnas a pisar, y no es un detalle:
                 * en un INSERT nuevo se escribe bien igual, pero en el UPDATE por
                 * conflicto del unique (mismo par, mismo dia, mismo origen) sin esta
                 * clave quedaba el valor viejo. Escenario real: el proveedor manda su
                 * lista en dolares a la tarde despues de una en pesos, o el usuario
                 * corrige la columna `moneda` y reimporta -- el costo se actualizaba y
                 * la moneda no, que es exactamente el error de plata que este campo
                 * vino a evitar (una oferta en dolares etiquetada como pesos compite y
                 * gana "el mas barato" por ~1000x).
                 */
                ['cost', 'provider_code', 'moneda_id', 'referencia_id', 'updated_at']
            );
        }
    }

    /**
     * Última oferta vigente de cada (article_id, provider_id) dentro de la
     * ventana de `$dias_vigencia` días, para el motor de sugerencia
     * (PurchaseSuggestionService). UNA query + resolución en PHP: nunca una
     * consulta por artículo, porque esto corre una vez por chunk (hasta
     * varios miles de artículos).
     *
     * 🔴 Esto NO elige "el proveedor más barato": devuelve TODAS las ofertas
     * vigentes por proveedor, en cualquier moneda (las de moneda_id = 2 se
     * guardan y se muestran, pero no compiten por el más barato — ver la
     * decisión R6 del plan). La elección de a quién conviene comprarle
     * (solo compiten las de moneda 1, empate a favor del titular, etc.) es
     * responsabilidad de quien llama, que además necesita cruzar contra
     * articles.provider_id y article_provider.cost — datos que este método
     * no tiene por qué conocer.
     *
     * 🔴 Arreglo post-chequeo (A11): el desempate entre dos filas del MISMO
     * día ya no es solo `id DESC`. Antes, si una importación se procesaba
     * más tarde en el día que una compra real, la fila de la importación
     * (id más alto) le ganaba a la de la compra — siendo la compra el dato
     * más confiable del histórico (es plata que efectivamente se pagó, no
     * una lista de precios). Ahora se prioriza `origen = 'compra'` antes que
     * `id DESC`: ese criterio solo entra a jugar cuando la fecha ya empató
     * (el ORDER BY por fecha sigue siendo el criterio principal), y dentro
     * de un mismo origen sigue desempatando por id DESC como antes.
     *
     * @param array $article_ids
     * @param int $user_id
     * @param int $dias_vigencia
     * @return array [article_id => [provider_id => ['cost'=>float, 'fecha'=>string, 'origen'=>string, 'moneda_id'=>int]]]
     */
    public static function mejores_ofertas_para(array $article_ids, int $user_id, int $dias_vigencia): array
    {
        $resultado = [];

        if (empty($article_ids)) {
            return $resultado;
        }

        $desde = now()->subDays($dias_vigencia)->toDateString();

        $filas = DB::table('provider_price_offers')
            ->where('user_id', $user_id)
            ->whereIn('article_id', $article_ids)
            ->where('fecha', '>=', $desde)
            ->orderBy('article_id')
            ->orderBy('provider_id')
            ->orderBy('fecha', 'desc')
            // Desempate del mismo día (A11): 'compra' antes que cualquier
            // otro origen. `origen = 'compra'` evalúa a 1/0 en MySQL, así
            // que DESC deja primero las filas de compra.
            ->orderByRaw("origen = 'compra' desc")
            ->orderBy('id', 'desc')
            ->select(['article_id', 'provider_id', 'cost', 'fecha', 'origen', 'moneda_id'])
            ->get();

        foreach ($filas as $fila) {
            $article_id = (int) $fila->article_id;
            $provider_id = (int) $fila->provider_id;

            // Mismo criterio que en registrar_lote(): el ORDER BY deja la
            // más reciente primero, así que la primera aparición del par ya
            // es la vigente y el resto (historial más viejo) se ignora.
            if (isset($resultado[$article_id][$provider_id])) {
                continue;
            }

            $resultado[$article_id][$provider_id] = [
                'cost' => (float) $fila->cost,
                'fecha' => $fila->fecha,
                'origen' => $fila->origen,
                'moneda_id' => (int) $fila->moneda_id,
            ];
        }

        return $resultado;
    }
}
