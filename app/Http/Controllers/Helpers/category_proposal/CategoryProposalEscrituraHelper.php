<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Support\Facades\Log;

/**
 * Lo que se le escribe a `articles` cuando se elige un sistema de categorías, se vuelve atrás o se
 * aprueba un dudoso, y los efectos que esa escritura se saltea (misión categorizacion-tres-modelos,
 * 5/10/2026). Plan §4.2.2 y relevamiento R1 §1.5.
 *
 * Por qué una clase aparte de CategoryProposalAplicarHelper: las reglas (qué artículo recibe qué
 * categoría) y el efecto lateral (el UPDATE masivo y lo que hay que avisarle al resto del sistema)
 * son dos preguntas distintas, y esta segunda es la que tiene trampas.
 *
 * 🔴 EL UPDATE VA POR EL BUILDER DE ELOQUENT (`Article::where(...)->update(...)`), NO por
 * `DB::table('articles')`: el builder de Eloquent pone `updated_at`, que es el reloj del sync
 * incremental de la SPA y del scheduler de embeddings; `DB::table` no lo toca (la columna es
 * `timestamp NULL` sin `ON UPDATE`) y los artículos recategorizados no bajarían a las pantallas
 * abiertas. Lo que el builder NO dispara (observers, auditoría por artículo, embeddings) se
 * resuelve así:
 *  - Embeddings: nada. El scheduler los levanta cada 30 minutos por `updated_at > embedding_generated_at`.
 *  - Precios: solo si el dueño usa margen o listas por categoría (CategoryMargenesHelper), con el
 *    recálculo en segundo plano de siempre (`PriceTypeHelper::dispatch_recalculate_for_articles`),
 *    DESPUÉS del commit.
 *  - Tienda Nube: si el dueño la usa, `add_article_to_sync` por artículo, DESPUÉS del commit.
 *  - Auditoría: el builder no deja fila por artículo; las creaciones y borrados de categoría sí
 *    (son pocas). `articles.needs_sync_with_tn` no se toca: no tiene lectores.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
class CategoryProposalEscrituraHelper
{
    /**
     * El detalle con el que el recálculo de precios aparece en la píldora de procesos en segundo plano
     * ("Se recalcularon por un cambio en una categoría · Categorización con IA").
     *
     * @var string
     */
    const DETALLE_DEL_RECALCULO = 'Categorización con IA';

    /**
     * La categoría y subcategoría que tienen hoy estos artículos del dueño (solo los vivos).
     *
     * Se lee ANTES de escribir por dos razones: guardar en cada ítem lo que tenía el artículo (para
     * poder volver atrás, porque el UPDATE masivo no deja historial) y no reescribir los artículos
     * que ya están donde tienen que estar (cada escritura mueve `updated_at` y dispara sincronizaciones).
     * Un artículo borrado o de otro dueño NO aparece en el resultado: quien llama lo trata como "ya no existe".
     * La única excepción es `$con_borrados` (ver abajo), que solo usa "volver atrás"; el de otro dueño nunca aparece.
     *
     * @param  int   $dueno_id
     * @param  array $article_ids
     * @param  bool  $con_borrados  Si también se leen los artículos que están en la papelera. Solo para devolverle a un
     *                              artículo borrado después de elegir lo que tenía antes (B-09 del verificador); en el
     *                              resto del flujo un borrado "ya no existe". Con `true`, cada fila trae `borrado`.
     * @return array  [article_id => ['category_id' => int|null, 'sub_category_id' => int|null, 'borrado' => bool]]
     */
    public static function leer_articulos($dueno_id, array $article_ids, $con_borrados = false)
    {
        if (empty($article_ids)) {
            return [];
        }

        // Los artículos del dueño pedidos; con `$con_borrados` se saca el filtro de soft delete (el de otro dueño sigue
        // afuera por el `user_id`).
        $consulta = Article::where('user_id', (int) $dueno_id)->whereIn('id', $article_ids);

        if ($con_borrados) {
            $consulta->withTrashed();
        }

        // `select` explícito: nunca se trae la fila entera de un artículo (tiene el vector de embeddings).
        $articulos = $consulta->get(['id', 'category_id', 'sub_category_id', 'deleted_at']);

        // El resultado: [article_id => categoría y subcategoría que tiene hoy, y si está en la papelera].
        $mapa = [];

        foreach ($articulos as $articulo) {
            $mapa[(int) $articulo->id] = [
                'category_id'     => is_null($articulo->category_id) ? null : (int) $articulo->category_id,
                'sub_category_id' => is_null($articulo->sub_category_id) ? null : (int) $articulo->sub_category_id,
                'borrado'         => !is_null($articulo->deleted_at),
            ];
        }

        return $mapa;
    }

    /**
     * Suma un artículo al grupo de su destino. Los UPDATE se agrupan por destino
     * `(category_id, sub_category_id)`: una sola consulta por destino y por lote en vez de una por artículo.
     * NULL es un destino válido (el artículo queda sin categoría).
     *
     * @param  array    $grupos          Se modifica: ['<cat>|<sub>' => ['category_id' => .., 'sub_category_id' => .., 'ids' => [..]]]
     * @param  int|null $category_id
     * @param  int|null $sub_category_id
     * @param  int      $article_id
     * @return void
     */
    public static function sumar_a_grupo(array &$grupos, $category_id, $sub_category_id, $article_id)
    {
        // La clave del grupo: el destino (NULL se escribe 'N').
        $clave = (is_null($category_id) ? 'N' : (int) $category_id).'|'.(is_null($sub_category_id) ? 'N' : (int) $sub_category_id);

        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'category_id'     => is_null($category_id) ? null : (int) $category_id,
                'sub_category_id' => is_null($sub_category_id) ? null : (int) $sub_category_id,
                'ids'             => [],
            ];
        }

        $grupos[$clave]['ids'][] = (int) $article_id;
    }

    /**
     * Escribe `category_id` y `sub_category_id` de los artículos, LOS DOS CAMPOS JUNTOS (una subcategoría
     * suelta de otra categoría es un dato inconsistente que el sistema ya sufre), agrupados por destino
     * y de a `catalogo_ia.articulos_por_lote_de_escritura` ids.
     *
     * Todo acotado por el `user_id` del dueño y por los artículos vivos: un id ajeno o borrado simplemente
     * no se toca (salvo `$con_borrados`, ver abajo). NULL se escribe como NULL (nunca 0: "sin categoría" por esta vía es NULL).
     *
     * @param  int   $dueno_id
     * @param  array $grupos        Los grupos que arma sumar_a_grupo().
     * @param  bool  $con_borrados  Si también se escribe a los artículos de la papelera. Solo para "volver atrás": un
     *                              artículo borrado después de elegir tiene que recuperar lo que tenía, porque si el
     *                              dueño lo restaura de la papelera no puede quedar apuntando a una categoría que "volver
     *                              atrás" mandó a la papelera (B-09 del verificador). Siempre acotado por el dueño.
     * @return int  Cuántos artículos se actualizaron en total.
     */
    public static function escribir_destinos($dueno_id, array $grupos, $con_borrados = false)
    {
        // De a cuántos ids se escribe por UPDATE.
        $tam = max(1, (int) config('catalogo_ia.articulos_por_lote_de_escritura'));

        // Cuántos artículos se actualizaron en total.
        $escritos = 0;

        foreach ($grupos as $grupo) {
            foreach (array_chunk($grupo['ids'], $tam) as $tanda) {
                // Los artículos del dueño de esta tanda: solo los vivos, o también los de la papelera.
                $consulta = Article::where('user_id', (int) $dueno_id);

                if ($con_borrados) {
                    $consulta->withTrashed();
                } else {
                    $consulta->whereNull('deleted_at');
                }

                $escritos += $consulta
                    ->whereIn('id', $tanda)
                    ->update([
                        'category_id'     => $grupo['category_id'],
                        'sub_category_id' => $grupo['sub_category_id'],
                    ]);
            }
        }

        return $escritos;
    }

    /**
     * Lo que la escritura masiva se saltea, para correr DESPUÉS del commit (si se hiciera antes, un
     * rollback dejaría el recálculo y la sincronización apuntando a categorías que no quedaron).
     *
     * 🔴 Nunca tira: la elección ya está commiteada y no puede convertirse en un 500 porque falló un aviso
     * secundario; el error queda en el log con el dueño y la cantidad.
     *
     * @param  int       $dueno_id
     * @param  array     $article_ids   Los artículos cuya categoría se escribió.
     * @param  bool|null $usa_margenes  Si ya se sabe (CategoryMargenesHelper::usa_margenes_por_categoria()['usa']);
     *                                  null = se calcula acá.
     * @return void
     */
    public static function efectos_tras_el_commit($dueno_id, array $article_ids, $usa_margenes = null)
    {
        if (empty($article_ids)) {
            return;
        }

        // Precios: solo si el dueño usa margen o listas por categoría. Con eso apagado ningún precio
        // depende de la categoría y recalcular sería gastar el motor en vano.
        try {
            if (is_null($usa_margenes)) {
                // Si el dueño usa margen o listas por categoría (solo ahí el precio depende de la categoría).
                $usa_margenes = CategoryMargenesHelper::usa_margenes_por_categoria($dueno_id)['usa'];
            }

            if ($usa_margenes) {
                PriceTypeHelper::dispatch_recalculate_for_articles(
                    $article_ids,
                    (int) $dueno_id,
                    'categoria',
                    self::DETALLE_DEL_RECALCULO
                );
            }
        } catch (\Throwable $e) {
            Log::error('[CategorizacionIa] No se pudo encolar el recálculo de precios de '.count($article_ids).' artículos del dueño '.$dueno_id.': '.$e->getMessage());
        }

        // Tienda Nube: la categoría viaja en el payload del producto, así que hay que re-sincronizarlo.
        // `add_article_to_sync` necesita el modelo (lee `tiendanube_product_id`, `disponible_tienda_nube`
        // y `user_id`) y solo encola los artículos que ya están en TN o disponibles para TN.
        try {
            if (CategoryMargenesHelper::usa_tienda_nube($dueno_id)) {
                foreach (array_chunk($article_ids, 500) as $tanda) {
                    // Los artículos de la tanda, con solo las columnas que `add_article_to_sync` lee.
                    $articulos = Article::where('user_id', (int) $dueno_id)
                        ->whereIn('id', $tanda)
                        ->get(['id', 'user_id', 'tiendanube_product_id', 'disponible_tienda_nube']);

                    foreach ($articulos as $articulo) {
                        TiendaNubeSyncArticleService::add_article_to_sync($articulo);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('[CategorizacionIa] No se pudo marcar para sincronizar con Tienda Nube a '.count($article_ids).' artículos del dueño '.$dueno_id.': '.$e->getMessage());
        }
    }
}
