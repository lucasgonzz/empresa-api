<?php

namespace App\Http\Controllers\Helpers\article\precios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Support\Facades\DB;

/**
 * Motor del recálculo de precios en segundo plano (misión recalculo-precios-motor-rapido,
 * 28/9/2026).
 *
 * QUÉ RESUELVE. Hasta esta misión, ProcessChunkSetFinalPrices recorría sus 100 artículos llamando
 * a ArticleHelper::setFinalPrice() con $guardar_cambios = true, y cada artículo leía sus
 * relaciones de a una (descuentos, recargos, proveedor, categoría, IVA, listas...) y escribía por
 * su cuenta: dos UPDATE de `articles`, un INSERT de price_changes y tres o cuatro consultas por
 * lista, cada una con su commit. Medido en Servian el 28/9/2026: 21 a 50 segundos por lote de 100,
 * y un proveedor de 40.000 artículos tardando tres horas. Casi todo era base de datos: la consulta
 * de descuentos sola se llevaba 200 ms por artículo.
 *
 * QUÉ HACE. Lo mismo, en bloque, con el MISMO cálculo:
 *
 *  1. Las listas del dueño se leen una vez por llamada (la misma consulta y el mismo orden que
 *     aplicar_precios_segun_listas_de_precios() hacía por artículo).
 *  2. Por tanda (tamanio_de_lote()), los artículos se leen de una vez con todas las relaciones que
 *     el cálculo va a mirar (PreciosEnLote::RELACIONES_A_PRECARGAR).
 *  3. Se enciende el modo lote de PreciosEnLote (el de la importación de Excel) y se llama a
 *     ArticleHelper::setFinalPrice() por artículo, en orden de id, con $guardar_cambios = false:
 *     el cálculo queda en memoria y los pivots de listas, los price_changes y las entradas por
 *     moneda se registran en PreciosEnLote en vez de escribirse.
 *  4. Lo que cada artículo cambió se escribe con un UPDATE en bloque de `articles` y
 *     PreciosEnLote::volcar() escribe el resto, todo en UNA transacción por tanda.
 *
 * 🔴 EL INVARIANTE. Para cualquier conjunto de artículos, la base tiene que quedar EXACTAMENTE
 * igual que si se hubiera corrido, artículo por artículo y en orden de id, lo que hacía
 * ProcessChunkSetFinalPrices hasta esta misión: setFinalPrice($article, $owner->id, $owner,
 * $auth_user_id) con $guardar_cambios = true. Mismas columnas de `articles` (updated_at incluido,
 * ver columnas_a_escribir()), mismos pivots, mismos price_changes. Lo prueba
 * tests/Feature/Precios/RecalculoEnLote/1_Equivalencia_con_el_camino_por_articulo_Test.php, campo
 * por campo, en todas las configuraciones de cuenta que el cálculo distingue. El cálculo NO vive
 * acá y no se toca: si alguien necesita cambiar un precio, se cambia en ArticleHelper /
 * ArticlePricesHelper y este motor lo hereda solo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RecalculoDePreciosEnLote
{
    /** Filas de `articles` por sentencia UPDATE (el SQL crece con cada WHEN). */
    const FILAS_POR_UPDATE = 500;

    /** Filas por INSERT de price_update_run_articles. */
    const FILAS_POR_INSERT = 500;

    /**
     * Columnas de `articles` con su tipo, tal como las devuelve SHOW COLUMNS: [columna => tipo].
     * Se lee una vez por proceso (un request, un worker): no se hardcodea porque hay clientes con
     * columnas distintas, y la escala de cada decimal es la que decide si un valor "cambió" para la
     * base (ver es_decimal_sin_cambio()).
     *
     * @var array<string, string>|null
     */
    protected static $columnas_de_articles = null;

    /**
     * Tamaño de tanda configurado: config('app.RECALCULO_PRECIOS_LOTE'), 1.000 por defecto, nunca
     * menos de 1 (un 0 o un valor inválido en el .env no puede dejar el recálculo en un bucle).
     *
     * @return int
     */
    public static function tamanio_de_lote()
    {
        $lote = (int) config('app.RECALCULO_PRECIOS_LOTE', 1000);

        return $lote < 1 ? 1 : $lote;
    }

    /**
     * Recalcula el precio de un conjunto de artículos y lo escribe en bloque.
     *
     * Si recibe más ids que tamanio_de_lote(), los procesa en tandas de ese tamaño. Cada tanda es
     * una unidad atómica: o se escribe entera o no se escribe nada (ver recalcular_tanda()). Si
     * una tanda tira, las anteriores ya quedaron escritas y la excepción sube: el que llama decide
     * (el job de chunk se reintenta, y reintentar es seguro porque recalcular dos veces lo mismo
     * no cambia nada la segunda vez).
     *
     * @param  int[]             $article_ids   Ids a recalcular. Los borrados o inexistentes se
     *                                          saltean. Se procesan en orden de id, sin repetidos.
     * @param  \App\Models\User  $owner         Dueño de la cuenta (NO un empleado): su
     *                                          configuración es la que decide el cálculo.
     * @param  int|null          $auth_user_id  Quién queda como employee_id de los price_changes.
     *                                          null = UserHelper::userId(false), como hoy.
     * @param  array             $opciones      ['price_update_run_id' => int|null]: si viene, los
     *                                          artículos cuyo precio cambió se registran en
     *                                          price_update_run_articles, adentro de la misma
     *                                          transacción de su tanda.
     * @return array ['recalculados' => int, 'cambiaron' => int[], 'filas_escritas' => int]
     */
    public static function recalcular(array $article_ids, $owner, $auth_user_id = null, array $opciones = [])
    {
        $resultado = [
            'recalculados'   => 0,
            'cambiaron'      => [],
            'filas_escritas' => 0,
        ];

        $ids = self::normalizar_ids($article_ids);

        if (empty($ids)) {
            return $resultado;
        }

        self::validar_dueno($owner);

        /*
         * 🔴 El estado de PreciosEnLote es estático, uno por proceso. Si ya está encendido, hay
         * otro cálculo en lote a mitad de camino (una importación en este mismo proceso) y
         * activar() le tiraría lo pendiente sin escribirlo. Se corta acá, antes de tocar nada.
         */
        if (PreciosEnLote::esta_activo()) {
            throw new \LogicException('RecalculoDePreciosEnLote: el modo lote de precios ya está encendido por otro cálculo en este proceso.');
        }

        $price_update_run_id = isset($opciones['price_update_run_id']) && !is_null($opciones['price_update_run_id'])
                                    ? (int) $opciones['price_update_run_id']
                                    : null;

        /* Las extensiones del dueño deciden el camino del cálculo: una consulta por llamada. */
        $owner->loadMissing('extencions');

        /*
         * El employee_id de los price_changes se resuelve UNA vez. Hoy se resolvía por cada
         * artículo que cambiaba de precio (PriceChangeController::store() con null), y con un
         * usuario logueado eso es un User::find() por artículo. El valor es el mismo: el contexto
         * de sesión no cambia en el medio de una llamada.
         */
        if (is_null($auth_user_id)) {
            $auth_user_id = UserHelper::userId(false);
        }

        /*
         * Las listas del dueño, una vez por llamada: la MISMA consulta y el MISMO orden que
         * ArticlePricesHelper::aplicar_precios_segun_listas_de_precios() hace por artículo cuando
         * no se las pasan. Con los recargos de cada lista precargados, que el cierre de cada lista
         * lee (aplicar_price_type_surchages()). Las instancias se comparten entre artículos: el
         * cálculo solo las lee (verificado leyendo el helper), no las modifica.
         */
        $price_types = PriceType::where('user_id', $owner->id)
                                ->orderBy('position', 'ASC')
                                ->with('price_type_surchages')
                                ->get();

        /*
         * 🔴 Los impuestos sobre ventas del dueño se releen en cada llamada. ArticlePricesHelper
         * los cachea en una estática que vive lo que vive el proceso, y un worker del VPS vive
         * decenas de jobs: si el dueño agregó un impuesto (SaleTaxController despacha justamente
         * este recálculo), el worker seguía calculando con la lista vieja hasta reiniciarse. Una
         * consulta por llamada es lo que cuesta que el recálculo vea lo que el dueño acaba de
         * cargar. No se toca el caché de nadie más.
         */
        unset(ArticlePricesHelper::$sale_taxes_cache[$owner->id]);

        foreach (array_chunk($ids, self::tamanio_de_lote()) as $tanda) {

            $parcial = self::recalcular_tanda($tanda, $owner, $auth_user_id, $price_types, $price_update_run_id);

            $resultado['recalculados']   += $parcial['recalculados'];
            $resultado['filas_escritas'] += $parcial['filas_escritas'];

            foreach ($parcial['cambiaron'] as $article_id) {
                $resultado['cambiaron'][] = $article_id;
            }
        }

        return $resultado;
    }

    /**
     * Una tanda: lectura en bloque, cálculo en memoria y escritura en bloque en una transacción.
     *
     * @param  int[]                                    $ids
     * @param  \App\Models\User                         $owner
     * @param  int|null                                 $auth_user_id
     * @param  \Illuminate\Database\Eloquent\Collection $price_types
     * @param  int|null                                 $price_update_run_id
     * @return array ['recalculados' => int, 'cambiaron' => int[], 'filas_escritas' => int]
     */
    protected static function recalcular_tanda(array $ids, $owner, $auth_user_id, $price_types, $price_update_run_id)
    {
        /*
         * 🔴 sinEmbedding() y NO un select de columnas elegidas a mano. El cálculo lee columnas de
         * `articles` desperdigadas en tres helpers (cost, price, percentage_gain, cost_in_dollars,
         * unidades_individuales, aplicar_iva, presentacion, ...), y una columna que no se trajo no
         * da error: da null, y null en el medio de la cadena es un precio mal calculado en
         * silencio. sinEmbedding() trae todas menos el vector del agente de WhatsApp, que son
         * ~29 KB por fila en los clientes con asistente y no participa de ningún precio.
         *
         * La lectura va afuera de la transacción a propósito: es la parte larga de una tanda y no
         * escribe nada, así que no tiene por qué alargar el tiempo con filas bloqueadas.
         */
        $articles = Article::sinEmbedding()
                            ->whereIn('articles.id', $ids)
                            ->with(PreciosEnLote::RELACIONES_A_PRECARGAR)
                            ->orderBy('articles.id')
                            ->get();

        if ($articles->isEmpty()) {
            return [
                'recalculados'   => 0,
                'cambiaron'      => [],
                'filas_escritas' => 0,
            ];
        }

        /*
         * 🔴 UNA transacción por tanda y SIN reintentos adentro (DB::transaction con el intento
         * único por defecto). Es tentador pasarle un 3 "por los deadlocks", y es un error:
         * PreciosEnLote::volcar() vacía su estado en un finally, así que un segundo intento
         * encontraría el recolector vacío y escribiría los artículos sin sus listas ni sus
         * price_changes, y commitearía eso como si estuviera completo. El reintento seguro es el
         * del job entero (ProcessChunkSetFinalPrices::$tries), que vuelve a leer y a calcular.
         *
         * El cálculo va ADENTRO de la transacción, no solo las escrituras: setFinalPrice() todavía
         * escribe algo por su cuenta en modo lote (el `price = null; save()` del artículo con
         * margen y precio viejo, y la marca de sincronización de Tienda Nube / Mercado Libre), y
         * así eso también se revierte si la tanda se aborta.
         */
        return DB::transaction(function () use ($articles, $owner, $auth_user_id, $price_types, $price_update_run_id) {

            PreciosEnLote::activar($owner, $auth_user_id);

            try {

                /*
                 * activar() no enciende nada si alguien dejó el interruptor de pruebas
                 * PreciosEnLote::deshabilitar() prendido. Sin el modo lote, setFinalPrice() con
                 * $guardar_cambios = false escribiría los pivots por su cuenta y
                 * PriceChangeController::store() leería las listas precargadas ANTES de esas
                 * escrituras: price_change_price_type con precios viejos, en silencio. Mejor no
                 * recalcular que recalcular mal.
                 */
                if (!PreciosEnLote::esta_activo()) {
                    throw new \LogicException('RecalculoDePreciosEnLote: el modo lote de precios está deshabilitado (PreciosEnLote::deshabilitar()); el motor no puede garantizar el resultado sin él.');
                }

                $cambiaron  = [];
                $escrituras = [];

                foreach ($articles as $article) {

                    /*
                     * La comparación suelta de hoy ($precio_anterior != final_price), la misma que
                     * hacía ProcessChunkSetFinalPrices::handle(): el precio de antes sale de la
                     * base (string con dos decimales) y el de después es el float ya cuantizado a
                     * dos decimales por setFinalPrice().
                     */
                    $precio_anterior = $article->final_price;

                    ArticleHelper::setFinalPrice($article, $owner->id, $owner, $auth_user_id, false, $price_types);

                    if ($precio_anterior != $article->final_price) {
                        $cambiaron[] = (int) $article->id;
                    }

                    $columnas = self::columnas_a_escribir($article);

                    if (!empty($columnas)) {
                        $escrituras[(int) $article->id] = $columnas;
                    }
                }

                $filas_escritas = self::actualizar_articulos($escrituras);

                PreciosEnLote::volcar();

                /*
                 * Los artículos que cambiaron de precio, en la misma transacción que sus precios:
                 * si el commit no llega, no quedan contados. insertOrIgnore contra el único
                 * (price_update_run_id, article_id): si el job se reintenta, no se duplican.
                 */
                if (!is_null($price_update_run_id) && !empty($cambiaron)) {

                    foreach (array_chunk($cambiaron, self::FILAS_POR_INSERT) as $lote) {

                        $filas = [];

                        foreach ($lote as $article_id) {
                            $filas[] = [
                                'price_update_run_id' => $price_update_run_id,
                                'article_id'          => $article_id,
                            ];
                        }

                        DB::table('price_update_run_articles')->insertOrIgnore($filas);
                    }
                }

                return [
                    'recalculados'   => count($articles),
                    'cambiaron'      => $cambiaron,
                    'filas_escritas' => $filas_escritas,
                ];

            } finally {

                /*
                 * 🔴 SIEMPRE, en finally. El estado de PreciosEnLote es estático: si un artículo
                 * tira a mitad de la tanda y el modo queda prendido, el próximo job que tome este
                 * mismo worker arranca con el modo lote encendido y con lo pendiente de esta tanda
                 * adentro, y lo escribiría como propio. Después de un volcar() exitoso esto no hace
                 * nada (volcar() ya dejó todo apagado y vacío).
                 */
                PreciosEnLote::descartar();
            }
        });
    }

    /**
     * Las columnas de `articles` que hay que escribir para este artículo: lo que escribirían los
     * save() del camino por artículo, y nada más.
     *
     * - Punto de partida: $article->getDirty(), que es exactamente lo que save() manda al UPDATE.
     *
     * - Menos las columnas decimales cuyo valor nuevo y el de la base, los dos no nulos, quedarían
     *   guardados igual (ver es_decimal_sin_cambio()). Eloquent compara decimales como texto
     *   ("123.450000" de la base contra 123.45 en memoria) y los da por sucios siempre; save()
     *   los escribía igual y la base quedaba con el mismo número. Saltearlos hace que un
     *   recálculo que no cambia ningún precio no reescriba esas columnas; NO hace que no
     *   escriba nada: un artículo con costo igual entra al UPDATE, con updated_at = ahora y
     *   nada más, porque así lo dejaba el camino por artículo (el punto de abajo, a propósito).
     *   Los únicos que no se escriben en absoluto son los que no traen ninguna columna: en la
     *   práctica, los artículos sin costo cuyo precio no cambió.
     *
     * - 🔴 Más `updated_at`, cuando el camino por artículo lo tocaba. setFinalPrice() hace un
     *   save() CON timestamps apenas calcula costo_real (línea ~355, `if ($guardar_cambios)`), y
     *   por lo de arriba ese save() encontraba costo_real sucio en todo artículo con costo: o sea
     *   que hoy todo recálculo le pone updated_at = ahora a cada artículo con costo, aunque el
     *   precio no se mueva. El save() final va con timestamps = false y ese no lo toca. Quien lee
     *   updated_at: el export incremental de artículos para integraciones (n8n,
     *   Integraciones\ArticulosExportController, que filtra SOLO por updated_at) y la descarga
     *   offline del listado. Si el motor dejara de tocarlo, un cambio de dólar dejaría de llegar a
     *   esas integraciones. Se reproduce tal cual: se toca exactamente cuando el save() de hoy lo
     *   tocaba (costo_real sucio para Eloquent al terminar el cálculo). Si el artículo pasó por el
     *   `price = null; save()` del modo lote, ese save() ya lo tocó y costo_real quedó limpio.
     *
     * @param  \App\Models\Article $article
     * @return array [columna => valor]
     */
    protected static function columnas_a_escribir($article)
    {
        $sucias = $article->getDirty();

        if (empty($sucias)) {
            return [];
        }

        $tocar_updated_at = array_key_exists('costo_real', $sucias) && $article->usesTimestamps();

        $columnas = [];

        foreach ($sucias as $columna => $valor) {

            if (self::es_decimal_sin_cambio($columna, $valor, $article->getRawOriginal($columna))) {
                continue;
            }

            $columnas[$columna] = $valor;
        }

        if ($tocar_updated_at) {
            $columnas[$article->getUpdatedAtColumn()] = $article->freshTimestampString();
        }

        return $columnas;
    }

    /**
     * UPDATE en bloque de `articles`: las filas se agrupan por el conjunto de columnas que traen,
     * y cada grupo sale en sentencias de a FILAS_POR_UPDATE filas con `col = CASE id WHEN ... THEN
     * ? ... ELSE col END` por columna y `WHERE id IN (...)`.
     *
     * - Agrupar por columnas hace que cada fila lleve exactamente las suyas: una columna que una
     *   fila no cambió no se reescribe en esa fila.
     * - Una columna con el MISMO valor para todas las filas del grupo (updated_at,
     *   final_price_updated_at: el mismo instante) va como `col = ?`, sin CASE.
     * - Los valores van como bindings, igual que los manda save() (un float se convierte a texto
     *   con la precisión de PHP y MySQL lo redondea a la escala de la columna: el mismo número
     *   guardado). Los ids van en el SQL como enteros validados, y los nombres de columna salen de
     *   la lista de columnas reales de la tabla.
     * - `updated_at` no se agrega solo: ya viene en las columnas cuando corresponde
     *   (columnas_a_escribir()). Por eso esto es SQL a mano y no un update() de Eloquent.
     *
     * @param  array $escrituras [article_id => [columna => valor]]
     * @return int   Filas enviadas al UPDATE.
     */
    protected static function actualizar_articulos(array $escrituras)
    {
        if (empty($escrituras)) {
            return 0;
        }

        $columnas_reales = self::columnas_de_articles();

        $grupos = [];

        foreach ($escrituras as $article_id => $columnas) {

            ksort($columnas);

            $grupos[implode(',', array_keys($columnas))][(int) $article_id] = $columnas;
        }

        $filas_escritas = 0;

        foreach ($grupos as $firma => $filas_del_grupo) {

            $nombres = explode(',', $firma);

            foreach ($nombres as $columna) {
                if (!array_key_exists($columna, $columnas_reales)) {
                    /* Lo mismo que le pasaría a save(): una columna que no existe es un error. */
                    throw new \RuntimeException('RecalculoDePreciosEnLote: la columna "' . $columna . '" no existe en articles.');
                }
            }

            foreach (array_chunk($filas_del_grupo, self::FILAS_POR_UPDATE, true) as $tanda) {

                $sets     = [];
                $bindings = [];

                foreach ($nombres as $columna) {

                    if (self::mismo_valor_en_todas($tanda, $columna)) {

                        $primera    = reset($tanda);
                        $sets[]     = '`' . $columna . '` = ?';
                        $bindings[] = $primera[$columna];
                        continue;
                    }

                    $whens = [];

                    foreach ($tanda as $article_id => $columnas) {
                        $whens[]    = 'WHEN ' . (int) $article_id . ' THEN ?';
                        $bindings[] = $columnas[$columna];
                    }

                    $sets[] = '`' . $columna . '` = CASE `id` ' . implode(' ', $whens) . ' ELSE `' . $columna . '` END';
                }

                $ids_sql = [];

                foreach (array_keys($tanda) as $article_id) {
                    $ids_sql[] = (int) $article_id;
                }

                DB::update(
                    'UPDATE `articles` SET ' . implode(', ', $sets) . ' WHERE `id` IN (' . implode(', ', $ids_sql) . ')',
                    $bindings
                );

                $filas_escritas += count($tanda);
            }
        }

        return $filas_escritas;
    }

    /**
     * ¿Todas las filas de la tanda traen el mismo valor (idéntico, ===) para esta columna?
     *
     * @param  array  $tanda   [article_id => [columna => valor]]
     * @param  string $columna
     * @return bool
     */
    protected static function mismo_valor_en_todas(array $tanda, $columna)
    {
        $primero = true;
        $valor   = null;

        foreach ($tanda as $columnas) {

            if ($primero) {
                $valor   = $columnas[$columna];
                $primero = false;
                continue;
            }

            if ($columnas[$columna] !== $valor) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿La columna es decimal y el valor nuevo quedaría guardado EXACTAMENTE igual que el que ya
     * tiene la base? Solo con los dos no nulos: null contra un número es un cambio.
     *
     * Se emula lo que haría MySQL con el valor que manda save(), en vez de comparar con round():
     * PDO manda el float como texto con la precisión de PHP (14 dígitos significativos en 7.4) y
     * MySQL lo redondea a la escala de la columna, mitad hacia afuera del cero. round() de PHP
     * pre-redondea a 15 dígitos, y en un borde podría decir "igual" donde la base guardaría un
     * centavo distinto: eso sería un precio que no se escribe. Si el texto no se puede emular con
     * certeza (notación científica, algo no numérico) se contesta false y la columna se escribe,
     * que es lo que hacía save(): escribir de más nunca cambia el resultado.
     *
     * @param  string $columna
     * @param  mixed  $nuevo
     * @param  mixed  $original  El valor que trajo la base (getRawOriginal()).
     * @return bool
     */
    protected static function es_decimal_sin_cambio($columna, $nuevo, $original)
    {
        if (is_null($nuevo) || is_null($original)) {
            return false;
        }

        $escala = self::escala_decimal($columna);

        if (is_null($escala)) {
            return false;
        }

        $guardado_nuevo    = self::decimal_como_lo_guarda_mysql($nuevo, $escala);
        $guardado_original = self::decimal_como_lo_guarda_mysql($original, $escala);

        if (is_null($guardado_nuevo) || is_null($guardado_original)) {
            return false;
        }

        return $guardado_nuevo === $guardado_original;
    }

    /**
     * El texto que quedaría guardado en una columna DECIMAL de esta escala si se le manda $valor
     * como lo manda PDO. null si no se puede emular con certeza.
     *
     * Público para que el test de bordes lo pueda ejercitar contra la base real.
     *
     * @param  mixed $valor
     * @param  int   $escala
     * @return string|null
     */
    public static function decimal_como_lo_guarda_mysql($valor, $escala)
    {
        if (is_bool($valor)) {
            $valor = (int) $valor;
        }

        if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
            return null;
        }

        /* La misma conversión que hace PDO con un float en PHP 7.4 (precision = 14). */
        $texto = trim((string) $valor);

        if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $texto, $partes)) {
            return null;
        }

        $signo   = $partes[1] === '-' ? '-' : '';
        $entera  = ltrim($partes[2], '0');
        $decimal = isset($partes[3]) ? $partes[3] : '';

        if ($partes[2] === '' && $decimal === '') {
            return null;
        }

        $escala = (int) $escala;

        if (strlen($decimal) <= $escala) {

            $decimal = str_pad($decimal, $escala, '0');

        } else {

            /* Redondeo mitad hacia afuera del cero, sobre los dígitos (sin pasar por float). */
            $redondear_para_arriba = ((int) $decimal[$escala]) >= 5;

            $digitos = ($entera === '' ? '0' : $entera) . substr($decimal, 0, $escala);

            if ($redondear_para_arriba) {
                $digitos = self::sumar_uno_a_digitos($digitos);
            }

            $largo_entero = strlen($digitos) - $escala;
            $entera       = ltrim(substr($digitos, 0, $largo_entero), '0');
            $decimal      = substr($digitos, $largo_entero);
        }

        if ($entera === '') {
            $entera = '0';
        }

        /* DECIMAL no tiene cero negativo: -0.004 se guarda 0.00. */
        if ($signo === '-' && trim($entera . $decimal, '0') === '') {
            $signo = '';
        }

        return $signo . $entera . ($escala > 0 ? '.' . $decimal : '');
    }

    /**
     * Suma uno a una tira de dígitos decimales, con acarreo ("0999" -> "1000", "99" -> "100").
     *
     * @param  string $digitos
     * @return string
     */
    protected static function sumar_uno_a_digitos($digitos)
    {
        $resultado = '';
        $acarreo   = 1;

        for ($i = strlen($digitos) - 1; $i >= 0; $i--) {

            $suma      = ((int) $digitos[$i]) + $acarreo;
            $acarreo   = $suma >= 10 ? 1 : 0;
            $resultado = ($suma % 10) . $resultado;
        }

        if ($acarreo) {
            $resultado = '1' . $resultado;
        }

        return $resultado;
    }

    /**
     * Escala de una columna decimal de `articles`, o null si la columna no es decimal.
     *
     * @param  string $columna
     * @return int|null
     */
    protected static function escala_decimal($columna)
    {
        $columnas = self::columnas_de_articles();

        if (!array_key_exists($columna, $columnas)) {
            return null;
        }

        if (!preg_match('/^decimal\(\s*\d+\s*,\s*(\d+)\s*\)/i', $columnas[$columna], $partes)) {
            return null;
        }

        return (int) $partes[1];
    }

    /**
     * Columnas de `articles` con su tipo, leídas una vez por proceso con SHOW COLUMNS.
     *
     * @return array<string, string>
     */
    protected static function columnas_de_articles()
    {
        if (is_null(self::$columnas_de_articles)) {

            $tabla    = (new Article)->getTable();
            $columnas = [];

            foreach (DB::select('SHOW COLUMNS FROM `' . str_replace('`', '', $tabla) . '`') as $fila) {
                $columnas[(string) $fila->Field] = strtolower((string) $fila->Type);
            }

            self::$columnas_de_articles = $columnas;
        }

        return self::$columnas_de_articles;
    }

    /**
     * Ids enteros positivos, sin repetidos y ordenados: el orden de id es el del invariante.
     *
     * @param  array $article_ids
     * @return int[]
     */
    protected static function normalizar_ids(array $article_ids)
    {
        $ids = [];

        foreach ($article_ids as $article_id) {

            if (is_object($article_id) || is_array($article_id)) {
                continue;
            }

            $id = (int) $article_id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        sort($ids);

        return array_values($ids);
    }

    /**
     * El cálculo es del dueño de la cuenta: sus flags de IVA, redondeo, dólar, extensiones y
     * listas. Un empleado tiene su propia fila en `users` con otros valores, y pasárselo a
     * setFinalPrice() calcula con la configuración equivocada sin ningún error. Se corta acá.
     *
     * @param  mixed $owner
     * @return void
     */
    protected static function validar_dueno($owner)
    {
        if (is_null($owner) || !is_object($owner) || empty($owner->id)) {
            throw new \InvalidArgumentException('RecalculoDePreciosEnLote: hace falta el usuario dueño de la cuenta.');
        }

        if (!empty($owner->owner_id)) {
            throw new \InvalidArgumentException('RecalculoDePreciosEnLote: el usuario ' . $owner->id . ' es un empleado; el recálculo se hace con el dueño de la cuenta (' . $owner->owner_id . ').');
        }
    }
}
