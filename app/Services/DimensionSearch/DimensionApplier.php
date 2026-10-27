<?php

namespace App\Services\DimensionSearch;

use App\Models\Article;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Support\Facades\DB;

/**
 * Escribe (y deshace) el peso y las medidas que encontró la búsqueda masiva en un artículo (misión
 * medidas-masivas-catalogo, 27/10/2026). Lo usan la asignación automática del motor y el «aprobar» de la
 * revisión; «quitar» usa revertir().
 *
 * 🔴 REGLA D5: NUNCA PISA. Solo se llenan los campos que estén vacíos (null o 0) EN EL MOMENTO de
 * escribir, con el artículo bloqueado: si mientras el motor buscaba alguien cargó el peso a mano, ese
 * campo se saltea y se informa. No pisar trabajo humano es lo mismo que hace Descripciones.
 *
 * 🔴 POR QUÉ ESTE CAMINO DE ESCRITURA (y no un `update()` crudo ni ArticleController::update()):
 *
 *   - Los caminos que hoy escriben `peso/alto/ancho/profundidad` son ArticleController::store/update
 *     (a mano, el formulario del artículo) y la importación. El formulario manda el artículo ENTERO
 *     (precios, stock, categorías, etc.): reusar update() desde acá re-guardaría todo eso y pisaría
 *     cualquier cambio concurrente. Este servicio toca SOLO las cuatro columnas.
 *   - Lo que el formulario dispara además del save() y que acá se respeta: (1) `needs_sync_with_tn = 1`
 *     y `TiendaNubeSyncArticleService::add_article_to_sync()`, porque TiendaNubeProductService manda
 *     `weight/depth/width/height` a Tienda Nube con estos mismos campos; es EXACTAMENTE lo que ya hace
 *     la asignación de imágenes (ImageAssignmentRunHelper). (2) El `save()` del modelo (no un
 *     `DB::table()->update()`), que toca `updated_at`: el sync incremental de la SPA y la
 *     sincronización offline bajan los artículos por `updated_at`.
 *   - ArticleObserver (única observación sobre Article) solo mira name, bar_code, category_id, brand_id y
 *     status para regenerar el embedding: peso y medidas no lo disparan, así que no hay costo escondido.
 *   - Mercado Libre: el formulario no marca nada específico para estos campos (no hay `needs_sync_with_meli`
 *     en update()), y ProductService de Mercado Libre no manda peso ni medidas: nada que disparar.
 *   - La tienda (tienda-api) lee estas columnas para cotizar envíos con Zipnova; cada campo vacío cae al
 *     bulto por defecto del comercio, por eso escribir campo por campo es seguro.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionApplier
{
    /** Campo del artículo => columna de la propuesta en dimension_search_items. */
    const COLUMNA_DE_PROPUESTA = [
        'peso'        => 'peso_kg',
        'alto'        => 'alto_cm',
        'ancho'       => 'ancho_cm',
        'profundidad' => 'profundidad_cm',
    ];

    /** Decimales de la columna (decimal(30,4)): lo escrito y lo comparado se redondea igual. */
    const DECIMALES = 4;

    /**
     * Escribe en el artículo los campos propuestos que sigan vacíos.
     *
     * @param  int   $owner_id    El dueño: un artículo de otro comercio es «no existe».
     * @param  int   $article_id
     * @param  array $valores     campo (peso|alto|ancho|profundidad) => valor en kg / cm (null = no hay).
     * @return array  ['existe' => bool, 'escritos' => campo => ['valor', 'anterior'],
     *                'salteados' => campo => valor que ya tenía el artículo]
     */
    public static function aplicar($owner_id, $article_id, array $valores)
    {
        return DB::transaction(function () use ($owner_id, $article_id, $valores) {
            // Con la fila bloqueada: dos aplicaciones seguidas (o una a mano) no se pisan entre sí.
            $articulo = Article::where('id', (int) $article_id)
                ->where('user_id', (int) $owner_id)
                ->lockForUpdate()
                ->first();

            if (is_null($articulo)) {
                return ['existe' => false, 'escritos' => [], 'salteados' => []];
            }

            $escritos  = [];
            $salteados = [];

            foreach (self::COLUMNA_DE_PROPUESTA as $campo => $columna) {
                if (!array_key_exists($campo, $valores) || is_null($valores[$campo])) {
                    continue;
                }

                $valor = round((float) $valores[$campo], self::DECIMALES);

                if ($valor <= 0) {
                    continue;
                }

                if (!self::esta_vacio($articulo->{$campo})) {
                    $salteados[$campo] = round((float) $articulo->{$campo}, self::DECIMALES);
                    continue;
                }

                // Lo que había (null o 0), para devolverlo tal cual si después se quita.
                $escritos[$campo] = ['valor' => $valor, 'anterior' => is_null($articulo->{$campo}) ? null : 0];

                $articulo->{$campo} = $valor;
            }

            if (!empty($escritos)) {
                self::marcar_para_sincronizar($articulo);
            }

            return ['existe' => true, 'escritos' => $escritos, 'salteados' => $salteados];
        });
    }

    /**
     * Deshace lo que escribió aplicar(): cada campo vuelve a su valor anterior SOLO si todavía vale lo que
     * puso la búsqueda. Si alguien lo editó después, ese campo se conserva (es trabajo humano).
     *
     * @param  int   $owner_id
     * @param  int   $article_id
     * @param  array $aplicado   Lo que guardó el item: campo => ['valor', 'anterior'].
     * @return array  ['existe' => bool, 'revertidos' => [campo, ...], 'conservados' => [campo, ...]]
     */
    public static function revertir($owner_id, $article_id, array $aplicado)
    {
        return DB::transaction(function () use ($owner_id, $article_id, $aplicado) {
            $articulo = Article::where('id', (int) $article_id)
                ->where('user_id', (int) $owner_id)
                ->lockForUpdate()
                ->first();

            if (is_null($articulo)) {
                return ['existe' => false, 'revertidos' => [], 'conservados' => []];
            }

            $revertidos  = [];
            $conservados = [];

            foreach ($aplicado as $campo => $dato) {
                if (!isset(self::COLUMNA_DE_PROPUESTA[$campo]) || !is_array($dato) || !isset($dato['valor'])) {
                    continue;
                }

                $actual = is_null($articulo->{$campo}) ? null : round((float) $articulo->{$campo}, self::DECIMALES);

                if (!is_null($actual) && abs($actual - round((float) $dato['valor'], self::DECIMALES)) < 0.00005) {
                    $articulo->{$campo} = array_key_exists('anterior', $dato) ? $dato['anterior'] : null;
                    $revertidos[] = $campo;
                } else {
                    $conservados[] = $campo;
                }
            }

            if (!empty($revertidos)) {
                self::marcar_para_sincronizar($articulo);
            }

            return ['existe' => true, 'revertidos' => $revertidos, 'conservados' => $conservados];
        });
    }

    /**
     * ¿Qué campos de la propuesta le faltan al artículo? (Los que están vacíos, null o 0.)
     *
     * @param  \App\Models\Article $articulo
     * @return array  Lista de campos.
     */
    public static function campos_vacios($articulo)
    {
        $vacios = [];

        foreach (array_keys(self::COLUMNA_DE_PROPUESTA) as $campo) {
            if (self::esta_vacio($articulo->{$campo})) {
                $vacios[] = $campo;
            }
        }

        return $vacios;
    }

    /**
     * Un valor de peso o medida está vacío si es null o vale cero (el formulario guarda 0 cuando no se
     * carga nada).
     *
     * @param  mixed $valor
     * @return bool
     */
    public static function esta_vacio($valor)
    {
        return is_null($valor) || (float) $valor <= 0;
    }

    /**
     * Guarda el artículo con las marcas que dispara el formulario del artículo al cambiar estos
     * campos: `needs_sync_with_tn` y el alta en la cola de sincronización con Tienda Nube. El save()
     * del modelo toca `updated_at` (sync incremental de la SPA y offline).
     *
     * @param  \App\Models\Article $articulo
     * @return void
     */
    protected static function marcar_para_sincronizar(Article $articulo)
    {
        $articulo->needs_sync_with_tn = true;
        $articulo->save();

        TiendaNubeSyncArticleService::add_article_to_sync($articulo);
    }
}
