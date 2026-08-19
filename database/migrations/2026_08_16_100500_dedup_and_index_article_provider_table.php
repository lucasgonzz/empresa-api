<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arregla el pivot `article_provider` entero (misión sugerencias de compra a proveedores):
 * hoy puede tener más de una fila por (article_id, provider_id) porque el índice único que
 * lo iba a evitar quedó comentado (2026_03_18_163200_add_indice_to_article_provider.php) y
 * el `upsert()` masivo de ActualizarBBDD::upsert_provider_relations() nunca detecta
 * conflicto sin ese índice: en MySQL, `INSERT ... ON DUPLICATE KEY UPDATE` decide el
 * conflicto por los índices únicos REALES de la tabla, no por el argumento `uniqueBy` que
 * Laravel recibe. Sin índice, ese upsert es siempre un INSERT.
 *
 * Orden de esta migración, y por qué es ESTE orden y no otro:
 * 1. Vuelca al histórico (`provider_price_offers`, origen='pivot_dedupe') las filas que se
 *    van a borrar, ANTES de borrarlas. El dedupe no puede ser una simple limpieza que tira
 *    datos: son costos reales, fechados por su created_at, y quedan rescatados.
 * 2. Borra, conservando el id más alto de cada par (la fila "más nueva" gana, mismo criterio
 *    que ya usa el upsert masivo con la columna updated_at).
 * 3. Re-chequea que no quedaron duplicados. Si quedan (no debería, es el mismo criterio de
 *    los dos pasos anteriores, pero mejor un error explícito que un índice a medio crear),
 *    revienta la migración ANTES de tocar el schema.
 * 4. Recién ahí crea el índice único `uniq_article_provider` que quedó comentado en marzo.
 *
 * 🔴 Con este índice creado, cualquier attach() ciego sobre un par ya existente pasa de
 * insertar en silencio a tirar "Integrity constraint violation". El único lugar del código
 * que hacía eso (ActualizarBBDD::set_articles_providers()) se convierte a
 * syncWithoutDetaching() en el MISMO commit que esta migración — no es una casualidad, es
 * la razón por la que van juntos. set_article_provider_codes.php:81 ya está a salvo
 * (guardado por `count($article->providers) == 0`) y no se toca. Los seeders
 * (ArticleSeederHelper.php, ArticlesTableSeeder.php) siguen siendo attach() ciegos: quedan
 * anotados en el informe de la misión con "no correrlos dos veces sobre la misma base".
 *
 * Efecto colateral bueno y esperado: con el índice creado, el upsert() masivo de
 * ActualizarBBDD (hoy siempre INSERT) empieza a hacer UPDATE cuando corresponde. Ese es el
 * arreglo real del bug que esta migración viene a cerrar.
 *
 * 🔴 Dos casos límite del paso 1 (arreglo A12 post-chequeo), documentados y NO arreglados
 * en esta pasada porque arreglarlos de verdad excede el alcance (el segundo pide decidir
 * qué user_id usar para una fila sin artículo, y eso es una decisión de negocio, no un
 * one-liner):
 * - **Fila huérfana**: `volcar_duplicadas_al_historico()` hace INNER JOIN contra `articles`
 *   para conseguir el `user_id` que `provider_price_offers` exige y `article_provider` no
 *   tiene. Si una fila descartable del pivot apunta a un `article_id` que ya no existe (el
 *   artículo se borró pero el pivot quedó huérfano), el INNER JOIN la excluye del SELECT y
 *   **nunca se vuelca** al histórico — pero el DELETE del paso 2 no tiene ese join (solo se
 *   apoya en la subquery de duplicados) y **sí la borra**. Es una pérdida de dato histórico
 *   silenciosa para el caso específico de pivots huérfanos con más de una fila.
 * - **`created_at` null**: sin guard, `DATE(ap.created_at)` de una fila legacy con
 *   `created_at` null evalúa a NULL, y como `provider_price_offers.fecha` es NOT NULL, ese
 *   INSERT degradaría a `'0000-00-00'` en vez de fallar (según el modo SQL del server). Se
 *   excluyen esas filas del volcado con `whereNotNull('ap.created_at')`: mismo destino que
 *   la huérfana (se borran sin volcar) en vez de ensuciar el histórico con una fecha falsa.
 */
class DedupAndIndexArticleProviderTable extends Migration
{
    /** Nombre del índice único que reemplaza al que quedó comentado en marzo. */
    const NOMBRE_INDICE = 'uniq_article_provider';

    /** Tamaño de tanda del insertOrIgnore de volcado (mismo orden de magnitud que el resto del schema). */
    const TAMANO_TANDA = 1000;

    /**
     * @return void
     */
    public function up()
    {
        $this->volcar_duplicadas_al_historico();
        $this->borrar_duplicadas();
        $this->verificar_que_no_quedan_duplicados();
        $this->crear_indice_unico();
    }

    /**
     * Paso 1: SELECT de las filas descartables de cada par (article_id, provider_id) con
     * más de una fila -- todas menos la de mayor id, el mismo criterio que usa el DELETE
     * del paso 2 -- con join a `articles` para conseguir el user_id y el cost_in_dollars
     * que hacen falta más abajo (provider_price_offers exige el user_id y article_provider
     * no lo tiene; cost_in_dollars decide moneda_id, ver el paso de armado de filas). Solo
     * se vuelcan filas con costo real (cost IS NOT NULL AND cost > 0): una fila sin costo
     * no es una oferta.
     *
     * 🔴 El join a `articles` es INNER (no LEFT): una fila de pivot huérfana (article_id sin
     * Article vivo) no aparece acá y por lo tanto no se vuelca, aunque el DELETE del paso 2
     * sí la borre -- ver el docblock de la clase, "Fila huérfana". Y `whereNotNull('ap.created_at')`
     * excluye del volcado (no del DELETE) las filas legacy sin created_at, para no degradar
     * `fecha` a '0000-00-00' -- ver "created_at null" en el mismo docblock.
     *
     * insertOrIgnore respeta el unique (article_id, provider_id, fecha, origen) de
     * provider_price_offers: si dos duplicadas descartables del mismo par comparten fecha
     * (mismo día de created_at), colapsan en una sola fila del histórico -- eso puede pasar
     * con costos DISTINTOS (dos importaciones del mismo día, cada una con su propio precio),
     * así que "es la misma información" no era cierto en general (arreglo de bloqueante de
     * merge, 15/8/2026 -- el comentario original lo afirmaba sin esa salvedad). El ORDER BY
     * de abajo deja primero, dentro de cada par, a la fila más nueva -- mismo criterio "la
     * más nueva gana" que ya usa el DELETE del paso 2 -- así que es ESA la que efectivamente
     * se inserta y sobrevive el insertOrIgnore cuando dos duplicadas comparten fecha. Antes
     * el SELECT no tenía ORDER BY: cuál sobrevivía dependía del orden físico que MySQL
     * eligiera devolver, no de una regla.
     *
     * @return void
     */
    private function volcar_duplicadas_al_historico()
    {
        $incluir_provider_code = Schema::hasColumn('article_provider', 'provider_code');

        $columnas = [
            'a.user_id as user_id',
            'ap.article_id as article_id',
            'ap.provider_id as provider_id',
            'ap.cost as cost',
            'a.cost_in_dollars as cost_in_dollars',
            DB::raw('DATE(ap.created_at) as fecha_creacion'),
        ];

        if ($incluir_provider_code) {
            // 🔴 Hay bases (ferretotal) sin esta columna: no se puede seleccionar a ciegas.
            $columnas[] = 'ap.provider_code as provider_code';
        }

        $duplicadas_descartables = DB::table('article_provider as ap')
            ->join('articles as a', 'a.id', '=', 'ap.article_id')
            ->joinSub(
                DB::table('article_provider')
                    ->select('article_id', 'provider_id', DB::raw('MAX(id) as keep_id'))
                    ->groupBy('article_id', 'provider_id')
                    ->havingRaw('COUNT(*) > 1'),
                'dup',
                function ($join) {
                    $join->on('ap.article_id', '=', 'dup.article_id')
                        ->on('ap.provider_id', '=', 'dup.provider_id');
                }
            )
            ->whereColumn('ap.id', '<', 'dup.keep_id')
            ->whereNotNull('ap.cost')
            ->where('ap.cost', '>', 0)
            // A12: sin esto, una fila legacy con created_at null volcaría
            // DATE(NULL) = NULL a una columna `fecha` NOT NULL (degrada a
            // '0000-00-00' según el modo SQL). Se excluye del volcado -- el
            // DELETE del paso 2 la sigue borrando igual, mismo destino que
            // la fila huérfana documentada arriba.
            ->whereNotNull('ap.created_at')
            // Arreglo de bloqueante de merge (15/8/2026): ORDER BY explícito para que
            // el volcado sea determinista. Dentro de cada par (article_id, provider_id)
            // deja primero a la fila más nueva (created_at DESC; id DESC para desempatar
            // un created_at idéntico) -- mismo criterio "la más nueva gana" que el DELETE
            // del paso 2 -- así que si dos duplicadas colapsan por compartir fecha en el
            // insertOrIgnore de abajo, la que gana es siempre la más nueva de las dos.
            ->orderBy('ap.article_id')
            ->orderBy('ap.provider_id')
            ->orderByDesc('ap.created_at')
            ->orderByDesc('ap.id')
            ->select($columnas)
            ->get();

        if ($duplicadas_descartables->isEmpty()) {
            return;
        }

        $ahora = now();
        $filas = [];

        foreach ($duplicadas_descartables as $fila) {
            $filas[] = [
                'user_id' => (int) $fila->user_id,
                'article_id' => (int) $fila->article_id,
                'provider_id' => (int) $fila->provider_id,
                'provider_code' => $incluir_provider_code ? $fila->provider_code : null,
                'cost' => $fila->cost,
                // Arreglo de bloqueante de merge (15/8/2026): moneda REAL del costo
                // volcado, igual que ya hacen los otros dos escritores del histórico
                // (NewProviderOrderHelper::catalogar_costo_proveedor() y ProcessRow::
                // registrar_oferta_de_otro_proveedor()). article_provider no tiene su
                // propia columna de moneda -- ActualizarBBDD::set_articles_providers()
                // copia articles.cost tal cual a article_provider.cost -- así que el
                // flag real vive en articles.cost_in_dollars (ArticleHelper.php:643-649
                // confirma que el sistema trata ese costo como dólares cuando
                // cost_in_dollars > 0), ya disponible en $fila por el JOIN que este
                // método hace de todos modos, arriba, para el user_id. Sin esto, TODAS
                // las filas volcadas por esta migración quedaban ancladas a 1 (Peso) sin
                // importar la moneda real del costo.
                'moneda_id' => !empty($fila->cost_in_dollars) ? 2 : 1,
                'origen' => 'pivot_dedupe',
                'fecha' => $fila->fecha_creacion,
                'referencia_id' => null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        foreach (array_chunk($filas, self::TAMANO_TANDA) as $tanda) {
            DB::table('provider_price_offers')->insertOrIgnore($tanda);
        }
    }

    /**
     * Paso 2: borra las filas descartables, conservando el id más alto de cada par. El
     * volcado del paso 1 ya rescató lo que hacía falta: esto es una limpieza, no una
     * pérdida de datos.
     *
     * @return void
     */
    private function borrar_duplicadas()
    {
        DB::statement('
            DELETE ap FROM article_provider ap
            JOIN (
                SELECT article_id, provider_id, MAX(id) AS keep_id
                FROM article_provider
                GROUP BY article_id, provider_id
                HAVING COUNT(*) > 1
            ) d ON ap.article_id = d.article_id AND ap.provider_id = d.provider_id
            WHERE ap.id < d.keep_id
        ');
    }

    /**
     * Paso 3: mejor fallar la migración con un mensaje claro que dejar el índice único a
     * medio crear sobre una tabla que todavía tiene pares duplicados.
     *
     * @return void
     */
    private function verificar_que_no_quedan_duplicados()
    {
        $pares_duplicados = DB::table('article_provider')
            ->select('article_id', 'provider_id')
            ->groupBy('article_id', 'provider_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($pares_duplicados->count() > 0) {
            throw new \RuntimeException(
                'DedupAndIndexArticleProviderTable: quedaron ' . $pares_duplicados->count()
                . ' pares (article_id, provider_id) duplicados en article_provider después '
                . 'del DELETE. Se frena antes de crear el índice único para no dejarlo a medias.'
            );
        }
    }

    /**
     * Paso 4: el índice que en marzo quedó comentado
     * (2026_03_18_163200_add_indice_to_article_provider.php). Guardado con SHOW INDEX por
     * nombre, no por columna: article_id ya tiene otros índices y comparar solo por columna
     * daría un falso positivo.
     *
     * @return void
     */
    private function crear_indice_unico()
    {
        if ($this->existe_indice('article_provider', self::NOMBRE_INDICE)) {
            return;
        }

        Schema::table('article_provider', function (Blueprint $table) {
            $table->unique(['article_id', 'provider_id'], self::NOMBRE_INDICE);
        });
    }

    /**
     * Dropea el índice único si existe.
     *
     * 🔴 NO restaura los duplicados que borró up(): son historia, no estado, y ya quedaron
     * a salvo en provider_price_offers con origen='pivot_dedupe'. Un down() que reinserte
     * filas idénticas a las que había antes no es un rollback real (los ids serían otros,
     * y cualquier referencia externa a esos ids ya se habría perdido igual).
     *
     * @return void
     */
    public function down()
    {
        if (! $this->existe_indice('article_provider', self::NOMBRE_INDICE)) {
            return;
        }

        Schema::table('article_provider', function (Blueprint $table) {
            $table->dropUnique(self::NOMBRE_INDICE);
        });
    }

    /**
     * Devuelve true si la tabla ya tiene un índice con ESE NOMBRE. SHOW INDEX en vez de
     * Schema::hasIndex() porque ese método no existe en la versión de Laravel del proyecto,
     * y en vez de doctrine/dbal para no depender de que esté instalado en cada cliente
     * (mismo motivo que el resto de las migraciones recientes del schema).
     *
     * @param  string  $tabla
     * @param  string  $nombre_indice
     * @return bool
     */
    private function existe_indice($tabla, $nombre_indice)
    {
        $indices = DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$nombre_indice]);

        return count($indices) >= 1;
    }
}
