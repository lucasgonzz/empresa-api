<?php

namespace App\Http\Controllers\Helpers;

use App\Jobs\ProcessSyncArticleDescriptionTiendaNube;
use App\Models\Article;
use App\Models\ArticleDescriptionProposal;
use App\Models\Description;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La revisión de las descripciones automáticas (misión alertas-descripciones, 14/10/2026): qué hace
 * el lote con lo que genera la IA, y Alertas → Catálogo → Descripciones. Contrato: §1.4 del plan de
 * la misión. ArticleDescriptionReviewController solo traduce a HTTP.
 *
 * Las dos decisiones del lote viven acá y no en ProcessArticleBatchDescriptionsJob porque el job
 * instancia la búsqueda y la IA con `new` y no se puede probar sin salir a internet:
 *  - motivo_para_saltear(): a qué artículo no se le genera nada.
 *  - guardar_resultado(): lo seguro se publica (filas de `descriptions`); lo dudoso queda como
 *    propuesta `a_revisar` y NO se ve en ningún lado hasta que alguien lo aprueba (decisión D1).
 *
 * La revisión la ve todo el que ve Catálogo, como Imágenes (decisión D2): todo se acota por el
 * DUEÑO y lo de otro comercio es "no existe" (404). Quién resolvió cada propuesta es la persona
 * real (`resolved_by`), no el dueño.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class AiDescriptionReviewHelper
{
    /** Solapas de la revisión (contrato §1.4). */
    const SOLAPA_A_REVISAR  = 'a_revisar';
    const SOLAPA_PUBLICADAS = 'publicadas';
    const SOLAPAS           = [self::SOLAPA_A_REVISAR, self::SOLAPA_PUBLICADAS];

    /** Paginado en el servidor (25, 50 o 100 en la SPA). */
    const POR_PAGINA_DEFECTO = 25;
    const POR_PAGINA_MINIMO  = 10;
    const POR_PAGINA_MAXIMO  = 100;

    /** Cuántos artículos acepta un aprobar / descartar en lote (una página es de 100 como mucho). */
    const MAXIMO_EN_LOTE = 100;

    /**
     * Secciones que se aceptan al aprobar o editar una descripción. La IA arma de 1 a 3, pero la
     * migración de datos (y el barrido) junta TODAS las filas dudosas de un artículo en una sola
     * propuesta, y un artículo con dos corridas viejas puede tener más de 3: con un tope de 3 esa
     * propuesta no se podía aprobar editada ni corregir publicada.
     */
    const MAXIMO_DE_SECCIONES = 10;

    /**
     * Largo máximo del título de una sección: lo que entra en `descriptions.title` (varchar(191),
     * por el defaultStringLength del proyecto). Con 255 un título de 192 a 255 caracteres pasaba la
     * validación y reventaba en el INSERT con un error de SQL en vez de un 422.
     */
    const MAXIMO_DEL_TITULO = 191;

    /** Por qué el lote saltea un artículo (van al log del job). */
    const MOTIVO_DESCRIPCION_HUMANA   = 'descripcion_humana';
    const MOTIVO_DESCRIPCION_REVISADA = 'descripcion_revisada';
    const MOTIVO_YA_TIENE_DESCRIPCION = 'ya_tiene_descripcion';
    const MOTIVO_PROPUESTA_A_REVISAR  = 'propuesta_a_revisar';
    const MOTIVO_PROPUESTA_DESCARTADA = 'propuesta_descartada';

    /** Qué quedó guardado de un resultado de la IA. */
    const GUARDADO_PUBLICADA = 'publicada';
    const GUARDADO_A_REVISAR = 'a_revisar';

    /** El mensaje de un 404 de propuesta. */
    const MENSAJE_SIN_PROPUESTA = 'Ese artículo no tiene una descripción esperando revisión.';

    /** El mensaje del 409 de aprobar un artículo que ya tiene descripción escrita a mano. */
    const MENSAJE_YA_TIENE_HUMANA = 'Este artículo ya tiene una descripción escrita a mano: la sugerencia se descartó para no duplicarla.';

    /** Artículos por tanda al barrer descripciones viejas pendientes (barrer_pendientes()). */
    const TANDA_DEL_BARRIDO = 200;

    /* ----------------------------------------------------------------------------------------
     * El lote
     * -------------------------------------------------------------------------------------- */

    /**
     * ¿Por qué el lote no le genera descripción a este artículo? null = se le genera.
     *
     * 1. Con una descripción escrita por una persona, nunca: en ninguno de los dos modos.
     * 2. En modo automático (sin overwrite), tampoco si ya tiene cualquier descripción (correr el
     *    lote dos veces sobre el mismo filtro duplicaba filas), si tiene una propuesta esperando
     *    revisión, o si tiene una DESCARTADA: lo que el dueño descartó o quitó, la IA no se lo
     *    vuelve a proponer.
     * 3. En modo reemplazar las propuestas no frenan: el que reemplaza pide explícitamente volver a
     *    generar (guardar_resultado() se encarga de la que estaba a revisar). Lo que SÍ frena es una
     *    descripción de IA que tocó una persona (`ai_reviewed_at` no nulo: la aprobó o la corrigió
     *    en Alertas, o la confirmó en el preview): regenerar no pisa trabajo humano, igual que con
     *    una escrita a mano.
     *
     * @param  \App\Models\Article $article
     * @param  bool                $overwrite
     * @return string|null  Uno de los MOTIVO_*.
     */
    public static function motivo_para_saltear(Article $article, $overwrite)
    {
        if ($article->descriptions()->human()->exists()) {
            return self::MOTIVO_DESCRIPCION_HUMANA;
        }

        if ((bool) $overwrite) {
            $revisada = $article->descriptions()
                ->where('ai_generated', true)
                ->whereNotNull('ai_reviewed_at')
                ->exists();

            return $revisada ? self::MOTIVO_DESCRIPCION_REVISADA : null;
        }

        if ($article->descriptions()->exists()) {
            return self::MOTIVO_YA_TIENE_DESCRIPCION;
        }

        $estado = ArticleDescriptionProposal::where('article_id', $article->id)
            ->whereIn('status', [ArticleDescriptionProposal::STATUS_A_REVISAR, ArticleDescriptionProposal::STATUS_DESCARTADA])
            ->orderByRaw("CASE WHEN status = '".ArticleDescriptionProposal::STATUS_A_REVISAR."' THEN 0 ELSE 1 END")
            ->value('status');

        if ($estado === ArticleDescriptionProposal::STATUS_A_REVISAR) {
            return self::MOTIVO_PROPUESTA_A_REVISAR;
        }

        if ($estado === ArticleDescriptionProposal::STATUS_DESCARTADA) {
            return self::MOTIVO_PROPUESTA_DESCARTADA;
        }

        return null;
    }

    /**
     * Guarda lo que generó la IA para un artículo. Quien llama ya pasó por motivo_para_saltear().
     *
     * - La propuesta `a_revisar` que el artículo tuviera queda reemplazada por este resultado (con
     *   overwrite es lo que pide el plan; sin overwrite el lote no llega acá si había una, salvo dos
     *   lotes sobre el mismo artículo a la vez, y así sigue habiendo una sola). Las aprobadas y
     *   descartadas quedan como historia.
     * - Confianza `low`: una propuesta `a_revisar` y NINGUNA fila en `descriptions`. No la ve la
     *   tienda ni la ficha del ERP hasta que alguien la apruebe.
     *   🔴 Aun con overwrite, lo dudoso NO borra lo publicado: reemplazar una descripción que se ve
     *   por una que la IA no pudo confirmar dejaba al artículo sin descripción hasta que alguien la
     *   aprobara. Lo publicado sigue a la vista; al aprobar la propuesta, aprobar() lo reemplaza.
     * - `medium` / `high`: con overwrite se borran antes las descripciones de IA que nadie revisó
     *   (las humanas y las revisadas nunca; igual, con alguna de esas el artículo no llega acá: ver
     *   motivo_para_saltear()), de a una para que el observer de embeddings se entere; después,
     *   filas en `descriptions` como siempre, sin `ai_reviewed_at`.
     *
     * @param  \App\Models\Article $article
     * @param  array               $result     El de ArticleDescriptionAiService::generate() con
     *                                         found=true: sections, confidence, sources.
     * @param  bool                $overwrite
     * @return array  ['tipo' => 'publicada'|'a_revisar', 'description_ids' => int[],
     *                 'proposal_id' => int|null, 'primer_texto' => string]
     */
    public static function guardar_resultado(Article $article, array $result, $overwrite)
    {
        $secciones = self::secciones_de(isset($result['sections']) ? $result['sections'] : []);
        $confianza = isset($result['confidence']) && $result['confidence'] !== '' ? (string) $result['confidence'] : 'low';
        $fuentes   = isset($result['sources']) && is_array($result['sources']) ? $result['sources'] : [];

        $primer_texto = '';

        foreach ($secciones as $seccion) {
            if ($seccion['content'] !== '') {
                $primer_texto = $seccion['content'];
                break;
            }
        }

        if ((bool) $overwrite && $confianza !== 'low') {
            self::borrar_las_de_ia_sin_revisar($article->id);
        }

        ArticleDescriptionProposal::where('article_id', $article->id)
            ->where('status', ArticleDescriptionProposal::STATUS_A_REVISAR)
            ->delete();

        if ($confianza === 'low') {
            $propuesta = ArticleDescriptionProposal::create([
                'user_id'       => (int) $article->user_id,
                'article_id'    => (int) $article->id,
                'sections'      => $secciones,
                'ai_confidence' => $confianza,
                'ai_sources'    => $fuentes,
                'status'        => ArticleDescriptionProposal::STATUS_A_REVISAR,
            ]);

            return [
                'tipo'            => self::GUARDADO_A_REVISAR,
                'description_ids' => [],
                'proposal_id'     => (int) $propuesta->id,
                'primer_texto'    => $primer_texto,
            ];
        }

        $description_ids = [];

        foreach ($secciones as $seccion) {
            $description = Description::create([
                'title'         => $seccion['title'],
                'content'       => $seccion['content'],
                'article_id'    => $article->id,
                'ai_generated'  => true,
                'ai_confidence' => $confianza,
                'ai_sources'    => $fuentes,
                // ai_reviewed_at queda NULL: nadie la revisó todavía.
            ]);

            $description_ids[] = (int) $description->id;
        }

        return [
            'tipo'            => self::GUARDADO_PUBLICADA,
            'description_ids' => $description_ids,
            'proposal_id'     => null,
            'primer_texto'    => $primer_texto,
        ];
    }

    /**
     * Una persona le guardó al artículo una descripción revisada (el preview individual de
     * ArticleDescriptionAiController::store()): la propuesta que estuviera a revisar ya no tiene
     * sentido y queda descartada, con quién y cuándo.
     *
     * @param  int      $owner_id
     * @param  int      $article_id
     * @param  int|null $resolved_by
     * @return int  Cuántas propuestas se resolvieron.
     */
    public static function descartar_pendientes_por_descripcion_revisada($owner_id, $article_id, $resolved_by = null)
    {
        return (int) ArticleDescriptionProposal::where('user_id', (int) $owner_id)
            ->where('article_id', (int) $article_id)
            ->where('status', ArticleDescriptionProposal::STATUS_A_REVISAR)
            ->update([
                'status'      => ArticleDescriptionProposal::STATUS_DESCARTADA,
                'resolved_at' => Carbon::now(),
                'resolved_by' => self::entero_o_null($resolved_by),
                'updated_at'  => Carbon::now(),
            ]);
    }

    /* ----------------------------------------------------------------------------------------
     * Barrido
     * -------------------------------------------------------------------------------------- */

    /**
     * Pone al día lo pendiente de un dueño. Idempotente: corre al principio de resumen(), de
     * revision() y del lote, y cuando no hay nada que hacer cuesta dos consultas livianas.
     *
     * (a) Mueve a propuestas `a_revisar` las descripciones de IA de baja confianza sin revisar que
     *     todavía estén en `descriptions` (`Description::pendingAiReview()`).
     *     🔴 POR QUÉ, si la migración `mover_descripciones_ia_pendientes_a_propuestas` ya las movió:
     *     un lote que estaba corriendo durante el deploy termina con el código VIEJO y las sigue
     *     guardando ahí, después de que la migración pasó; y la migración no vuelve a correr. Sin
     *     esto, esas quedan visibles en la tienda sin que nadie las apruebe.
     * (b) Descarta (sin persona: `resolved_by` null) las propuestas `a_revisar` de un artículo que
     *     ya tiene una descripción escrita a mano: el dueño la escribió después, y aprobar la
     *     sugerencia se la duplicaría.
     *
     * Nunca lanza: si algo falla queda en el log y el número rojo o la lista se arman igual.
     *
     * @param  int $owner_id
     * @return array  ['movidas' => artículos movidos, 'descartadas' => propuestas descartadas]
     */
    public static function barrer_pendientes($owner_id)
    {
        $resultado = ['movidas' => 0, 'descartadas' => 0];

        try {
            $resultado['movidas']     = self::mover_pendientes_viejas((int) $owner_id);
            $resultado['descartadas'] = self::descartar_las_que_tienen_descripcion_humana((int) $owner_id);
        } catch (\Throwable $e) {
            Log::warning('[DescripcionesIA] No se pudo barrer lo pendiente.', [
                'user_id' => (int) $owner_id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $resultado;
    }

    /**
     * (a) de barrer_pendientes().
     *
     * 🔴 Es la MISMA lógica que la migración `2026_10_14_180100_mover_descripciones_ia_pendientes_a_propuestas`,
     * duplicada a propósito: la migración tiene que quedar autónoma (corre con el código de su
     * versión y no puede depender de un helper que cambie después). Si se toca una, revisar la
     * otra. La diferencia: acá se acota al dueño, y solo se recorre si un exists() da true.
     *
     * @param  int $owner_id
     * @return int  Cuántos artículos se movieron.
     */
    protected static function mover_pendientes_viejas($owner_id)
    {
        // Los artículos del dueño, incluidos los de la papelera (DB::table no filtra deleted_at).
        $pendientes = function () use ($owner_id) {
            return DB::table('descriptions')
                ->join('articles', 'articles.id', '=', 'descriptions.article_id')
                ->where('articles.user_id', $owner_id)
                ->where('descriptions.ai_generated', 1)
                ->where('descriptions.ai_confidence', 'low')
                ->whereNull('descriptions.ai_reviewed_at');
        };

        if (!$pendientes()->exists()) {
            return 0;
        }

        $movidos = 0;

        $pendientes()
            ->select('descriptions.article_id')
            ->groupBy('descriptions.article_id')
            ->chunkById(self::TANDA_DEL_BARRIDO, function ($filas) use ($owner_id, &$movidos) {
                $article_ids = [];

                foreach ($filas as $fila) {
                    $article_ids[] = (int) $fila->article_id;
                }

                $movidos += self::mover_tanda_de_pendientes($owner_id, $article_ids);
            }, 'descriptions.article_id', 'article_id');

        return $movidos;
    }

    /**
     * Una tanda de (a): una propuesta por artículo con sus filas en orden de id (confianza y fuentes
     * de la primera, fecha de la más vieja) y las filas, borradas. Todo en una transacción.
     *
     * @param  int   $owner_id
     * @param  array $article_ids
     * @return int
     */
    protected static function mover_tanda_de_pendientes($owner_id, array $article_ids)
    {
        return DB::transaction(function () use ($owner_id, $article_ids) {
            $filas = DB::table('descriptions')
                ->whereIn('article_id', $article_ids)
                ->where('ai_generated', 1)
                ->where('ai_confidence', 'low')
                ->whereNull('ai_reviewed_at')
                ->orderBy('article_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'article_id', 'title', 'content', 'ai_confidence', 'ai_sources', 'created_at']);

            $por_articulo = [];

            foreach ($filas as $fila) {
                $por_articulo[(int) $fila->article_id][] = $fila;
            }

            $ahora      = Carbon::now();
            $propuestas = [];
            $a_borrar   = [];

            foreach ($por_articulo as $article_id => $filas_del_articulo) {
                $primera   = $filas_del_articulo[0];
                $secciones = [];
                $creada    = null;

                foreach ($filas_del_articulo as $fila) {
                    $secciones[] = [
                        'title'   => is_null($fila->title) ? '' : (string) $fila->title,
                        'content' => is_null($fila->content) ? '' : (string) $fila->content,
                    ];

                    $a_borrar[] = (int) $fila->id;

                    if (!is_null($fila->created_at) && (is_null($creada) || $fila->created_at < $creada)) {
                        $creada = $fila->created_at;
                    }
                }

                $propuestas[] = [
                    'user_id'       => $owner_id,
                    'article_id'    => (int) $article_id,
                    'sections'      => json_encode($secciones, JSON_UNESCAPED_UNICODE),
                    'ai_confidence' => $primera->ai_confidence,
                    // Ya es el JSON [{source, url, title}] que guardó el lote: se copia tal cual.
                    'ai_sources'    => $primera->ai_sources,
                    'status'        => ArticleDescriptionProposal::STATUS_A_REVISAR,
                    'resolved_at'   => null,
                    'resolved_by'   => null,
                    'created_at'    => is_null($creada) ? $ahora : $creada,
                    'updated_at'    => $ahora,
                ];
            }

            if (!empty($propuestas)) {
                DB::table('article_description_proposals')->insert($propuestas);
            }

            foreach (array_chunk($a_borrar, 1000) as $ids) {
                DB::table('descriptions')->whereIn('id', $ids)->delete();
            }

            return count($propuestas);
        });
    }

    /**
     * (b) de barrer_pendientes(). Primero se buscan los ids (una lectura) y solo si hay se escribe:
     * esto corre en cada resumen y un UPDATE sin filas igual toma locks.
     *
     * @param  int $owner_id
     * @return int  Cuántas propuestas se descartaron.
     */
    protected static function descartar_las_que_tienen_descripcion_humana($owner_id)
    {
        $ids = DB::table('article_description_proposals')
            ->where('article_description_proposals.user_id', $owner_id)
            ->where('article_description_proposals.status', ArticleDescriptionProposal::STATUS_A_REVISAR)
            ->whereExists(function ($humana) {
                $humana->select(DB::raw(1))
                    ->from('descriptions')
                    ->whereColumn('descriptions.article_id', 'article_description_proposals.article_id')
                    ->where('descriptions.ai_generated', 0);
            })
            ->pluck('article_description_proposals.id')
            ->all();

        if (empty($ids)) {
            return 0;
        }

        $ahora = Carbon::now();

        return (int) DB::table('article_description_proposals')
            ->whereIn('id', $ids)
            ->where('status', ArticleDescriptionProposal::STATUS_A_REVISAR)
            ->update([
                'status'      => ArticleDescriptionProposal::STATUS_DESCARTADA,
                'resolved_at' => $ahora,
                'resolved_by' => null,
                'updated_at'  => $ahora,
            ]);
    }

    /* ----------------------------------------------------------------------------------------
     * Conteos y listados
     * -------------------------------------------------------------------------------------- */

    /**
     * Conteos de las dos solapas (contrato §1.4).
     *
     * - a_revisar: artículos vivos del dueño con una propuesta esperando revisión.
     * - publicadas: artículos vivos del dueño con al menos una descripción de IA.
     *
     * @param  int $owner_id
     * @return array  ['a_revisar' => int, 'publicadas' => int]
     */
    public static function conteos($owner_id)
    {
        return [
            'a_revisar'  => (int) self::consulta_a_revisar($owner_id)->count(),
            'publicadas' => (int) self::consulta_publicadas($owner_id)->count(),
        ];
    }

    /**
     * El resumen liviano del número rojo.
     *
     * @param  int $owner_id
     * @return array  ['a_revisar' => int, 'publicadas' => int, 'badge' => int]
     */
    public static function resumen($owner_id)
    {
        // Antes de contar, lo que dejó un lote viejo o lo que ya escribió a mano el dueño.
        self::barrer_pendientes($owner_id);

        $conteos = self::conteos($owner_id);

        return [
            'a_revisar'  => $conteos['a_revisar'],
            'publicadas' => $conteos['publicadas'],
            'badge'      => $conteos['a_revisar'],
        ];
    }

    /**
     * Una página de una solapa, paginada en el servidor.
     *
     * Orden: "A revisar" por propuesta más nueva primero; "Publicadas" por descripción de IA más
     * nueva primero.
     *
     * @param  int    $owner_id
     * @param  string $solapa      a_revisar | publicadas (ya validada).
     * @param  mixed  $pagina      ≥ 1 (otra cosa = 1).
     * @param  mixed  $por_pagina  Se acota a 10..100 (25 por defecto).
     * @param  mixed  $buscar      Por nombre, código de barras o código de proveedor.
     * @return array  ['models' => Item[], 'total', 'pagina', 'por_pagina', 'conteos']
     */
    public static function revision($owner_id, $solapa, $pagina, $por_pagina, $buscar = '')
    {
        // Antes de listar, lo que dejó un lote viejo o lo que ya escribió a mano el dueño.
        self::barrer_pendientes($owner_id);

        $pagina     = max(1, (int) $pagina);
        $por_pagina = self::por_pagina($por_pagina);
        $buscar     = is_string($buscar) ? trim($buscar) : '';

        if ($solapa === self::SOLAPA_PUBLICADAS) {
            $consulta = self::consulta_publicadas($owner_id);
        } else {
            $consulta = self::consulta_a_revisar($owner_id);
        }

        if ($buscar !== '') {
            $patron = '%'.addcslashes($buscar, '%_\\').'%';

            $consulta->where(function ($condicion) use ($patron) {
                $condicion->where('articles.name', 'like', $patron)
                    ->orWhere('articles.bar_code', 'like', $patron)
                    ->orWhere('articles.provider_code', 'like', $patron);
            });
        }

        $total = (int) (clone $consulta)->count();

        if ($solapa === self::SOLAPA_PUBLICADAS) {
            $filas = $consulta
                ->select('articles.id', 'articles.name', 'articles.bar_code', 'articles.provider_code')
                ->selectSub(function ($sub) {
                    $sub->from('descriptions')
                        ->selectRaw('MAX(descriptions.created_at)')
                        ->whereColumn('descriptions.article_id', 'articles.id')
                        ->where('descriptions.ai_generated', 1);
                }, 'ultima_de_ia')
                ->orderBy('ultima_de_ia', 'DESC')
                ->orderBy('articles.id', 'DESC')
                ->forPage($pagina, $por_pagina)
                ->get();

            $models = self::items_publicados($filas);
        } else {
            $filas = $consulta
                ->select(
                    'article_description_proposals.*',
                    'articles.name as articulo_nombre',
                    'articles.bar_code as articulo_codigo_de_barras',
                    'articles.provider_code as articulo_codigo_de_proveedor'
                )
                ->orderBy('article_description_proposals.created_at', 'DESC')
                ->orderBy('article_description_proposals.id', 'DESC')
                ->forPage($pagina, $por_pagina)
                ->get();

            $models = [];

            foreach ($filas as $fila) {
                $models[] = self::item_a_revisar($fila);
            }
        }

        return [
            'models'     => $models,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $por_pagina,
            'conteos'    => self::conteos($owner_id),
        ];
    }

    /**
     * El Item de "Publicadas" de un artículo del dueño (lo que devuelve editar una publicada).
     *
     * @param  int $owner_id
     * @param  int $article_id
     * @return array|null  null si el artículo no es del dueño o no tiene descripciones de IA.
     */
    public static function item_publicado($owner_id, $article_id)
    {
        $filas = self::consulta_publicadas($owner_id)
            ->where('articles.id', (int) $article_id)
            ->select('articles.id', 'articles.name', 'articles.bar_code', 'articles.provider_code')
            ->get();

        $items = self::items_publicados($filas);

        return empty($items) ? null : $items[0];
    }

    /**
     * Propuestas `a_revisar` del dueño con el artículo vivo, una por artículo (la más nueva, por si
     * dos lotes a la vez dejaron dos). Con el join a `articles` para buscar y para el payload.
     *
     * @param  int $owner_id
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta_a_revisar($owner_id)
    {
        return DB::table('article_description_proposals')
            ->join('articles', 'articles.id', '=', 'article_description_proposals.article_id')
            ->where('article_description_proposals.user_id', (int) $owner_id)
            ->where('article_description_proposals.status', ArticleDescriptionProposal::STATUS_A_REVISAR)
            ->where('articles.user_id', (int) $owner_id)
            ->whereNull('articles.deleted_at')
            ->whereNotExists(function ($mas_nueva) {
                $mas_nueva->select(DB::raw(1))
                    ->from('article_description_proposals as otra')
                    ->whereColumn('otra.article_id', 'article_description_proposals.article_id')
                    ->where('otra.status', ArticleDescriptionProposal::STATUS_A_REVISAR)
                    ->whereColumn('otra.id', '>', 'article_description_proposals.id');
            });
    }

    /**
     * Artículos vivos del dueño con al menos una descripción de IA.
     *
     * @param  int $owner_id
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function consulta_publicadas($owner_id)
    {
        return DB::table('articles')
            ->where('articles.user_id', (int) $owner_id)
            ->whereNull('articles.deleted_at')
            ->whereExists(function ($de_ia) {
                $de_ia->select(DB::raw(1))
                    ->from('descriptions')
                    ->whereColumn('descriptions.article_id', 'articles.id')
                    ->where('descriptions.ai_generated', 1);
            });
    }

    /**
     * Item de "A revisar" (contrato §1.4): las secciones salen de la propuesta, con `id` null.
     *
     * @param  object $fila  Propuesta con articulo_nombre / articulo_codigo_de_barras /
     *                       articulo_codigo_de_proveedor (ver revision()).
     * @return array
     */
    protected static function item_a_revisar($fila)
    {
        $secciones = [];

        foreach (self::secciones_de(self::decodificar($fila->sections)) as $seccion) {
            $secciones[] = [
                'id'      => null,
                'title'   => $seccion['title'],
                'content' => $seccion['content'],
            ];
        }

        return [
            'article_id' => (int) $fila->article_id,
            'articulo'   => self::articulo(
                $fila->article_id,
                $fila->articulo_nombre,
                $fila->articulo_codigo_de_barras,
                $fila->articulo_codigo_de_proveedor
            ),
            'secciones'  => $secciones,
            'fuentes'    => self::fuentes_de($fila->ai_sources),
            'confianza'  => is_null($fila->ai_confidence) ? null : (string) $fila->ai_confidence,
            'fecha'      => self::fecha($fila->created_at),
        ];
    }

    /**
     * Items de "Publicadas" (contrato §1.4) para unos artículos, con sus descripciones de IA en UNA
     * consulta. Las secciones son las filas de `descriptions` por `id`; fuentes y confianza, las de
     * la primera; la fecha, la de la más nueva.
     *
     * @param  \Illuminate\Support\Collection $articulos  Filas con id, name, bar_code, provider_code.
     * @return array
     */
    protected static function items_publicados($articulos)
    {
        $ids = [];

        foreach ($articulos as $articulo) {
            $ids[] = (int) $articulo->id;
        }

        if (empty($ids)) {
            return [];
        }

        $por_articulo = [];

        $descripciones = DB::table('descriptions')
            ->whereIn('article_id', $ids)
            ->where('ai_generated', 1)
            ->orderBy('id')
            ->get(['id', 'article_id', 'title', 'content', 'ai_confidence', 'ai_sources', 'created_at']);

        foreach ($descripciones as $descripcion) {
            $por_articulo[(int) $descripcion->article_id][] = $descripcion;
        }

        $items = [];

        foreach ($articulos as $articulo) {
            $propias = isset($por_articulo[(int) $articulo->id]) ? $por_articulo[(int) $articulo->id] : [];

            if (empty($propias)) {
                continue;
            }

            $secciones = [];
            $mas_nueva = null;

            foreach ($propias as $descripcion) {
                $secciones[] = [
                    'id'      => (int) $descripcion->id,
                    'title'   => is_null($descripcion->title) ? '' : (string) $descripcion->title,
                    'content' => is_null($descripcion->content) ? '' : (string) $descripcion->content,
                ];

                if (!is_null($descripcion->created_at) && (is_null($mas_nueva) || $descripcion->created_at > $mas_nueva)) {
                    $mas_nueva = $descripcion->created_at;
                }
            }

            $items[] = [
                'article_id' => (int) $articulo->id,
                'articulo'   => self::articulo($articulo->id, $articulo->name, $articulo->bar_code, $articulo->provider_code),
                'secciones'  => $secciones,
                'fuentes'    => self::fuentes_de($propias[0]->ai_sources),
                'confianza'  => is_null($propias[0]->ai_confidence) ? null : (string) $propias[0]->ai_confidence,
                'fecha'      => self::fecha($mas_nueva),
            ];
        }

        return $items;
    }

    /* ----------------------------------------------------------------------------------------
     * Resolver una propuesta
     * -------------------------------------------------------------------------------------- */

    /**
     * Aprueba la propuesta a revisar de un artículo: recién acá pasa a ser una descripción del
     * artículo (filas de `descriptions` con `ai_generated`, la confianza y las fuentes de la
     * propuesta y `ai_reviewed_at`), con las secciones editadas si vinieron. Tienda Nube se entera
     * después del commit.
     *
     * 🔴 La propuesta se toma con lockForUpdate y se vuelve a mirar su estado adentro de la
     * transacción: un doble clic (o aprobar uno y el mismo en lote) no puede crear las descripciones
     * dos veces.
     *
     * 🔴 Si mientras tanto alguien le escribió al artículo una descripción a mano, aprobar se la
     * duplicaría: la propuesta queda descartada (con quien la quiso aprobar) y la respuesta es 409.
     *
     * @param  int        $owner_id
     * @param  int        $article_id
     * @param  array|null $secciones    [{title, content}] ya validadas, o null = las de la propuesta.
     * @param  int|null   $resolved_by
     * @return array  ['status' => 200|404|409, 'message' => string|null]
     */
    public static function aprobar($owner_id, $article_id, $secciones = null, $resolved_by = null)
    {
        $tiene_humana = false;

        $article = DB::transaction(function () use ($owner_id, $article_id, $secciones, $resolved_by, &$tiene_humana) {
            $propuesta = self::propuesta_a_revisar_bloqueada($owner_id, $article_id);

            if (is_null($propuesta)) {
                return null;
            }

            $article = Article::where('id', (int) $article_id)
                ->where('user_id', (int) $owner_id)
                ->first();

            if ($article->descriptions()->human()->exists()) {
                $tiene_humana = true;
                self::resolver($propuesta, ArticleDescriptionProposal::STATUS_DESCARTADA, $resolved_by);

                return null;
            }

            /*
             * Lo aprobado reemplaza a lo que la IA había publicado sola (un "reemplazar" con
             * resultado dudoso deja lo publicado a la vista hasta este momento, ver
             * guardar_resultado()). Las humanas y las que ya revisó una persona, nunca.
             */
            self::borrar_las_de_ia_sin_revisar($article->id);

            $ahora = Carbon::now();
            $a_guardar = self::secciones_de(is_null($secciones) ? $propuesta->sections : $secciones);

            foreach ($a_guardar as $seccion) {
                Description::create([
                    'title'          => $seccion['title'],
                    'content'        => $seccion['content'],
                    'article_id'     => (int) $article->id,
                    'ai_generated'   => true,
                    'ai_confidence'  => $propuesta->ai_confidence,
                    'ai_sources'     => is_array($propuesta->ai_sources) ? $propuesta->ai_sources : [],
                    'ai_reviewed_at' => $ahora,
                ]);
            }

            self::resolver($propuesta, ArticleDescriptionProposal::STATUS_APROBADA, $resolved_by);

            return $article;
        });

        if ($tiene_humana) {
            return self::respuesta(409, self::MENSAJE_YA_TIENE_HUMANA);
        }

        if (is_null($article)) {
            return self::respuesta(404, self::MENSAJE_SIN_PROPUESTA);
        }

        self::sincronizar_tienda_nube($article);

        return self::respuesta(200);
    }

    /**
     * Descarta la propuesta a revisar de un artículo: no se publica nada y el artículo queda sin
     * descripción (se le puede escribir una a mano). El lote automático no se la vuelve a proponer.
     *
     * @param  int      $owner_id
     * @param  int      $article_id
     * @param  int|null $resolved_by
     * @return array  ['status' => 200|404, 'message' => string|null]
     */
    public static function descartar($owner_id, $article_id, $resolved_by = null)
    {
        $hecho = DB::transaction(function () use ($owner_id, $article_id, $resolved_by) {
            $propuesta = self::propuesta_a_revisar_bloqueada($owner_id, $article_id);

            if (is_null($propuesta)) {
                return false;
            }

            self::resolver($propuesta, ArticleDescriptionProposal::STATUS_DESCARTADA, $resolved_by);

            return true;
        });

        if (!$hecho) {
            return self::respuesta(404, self::MENSAJE_SIN_PROPUESTA);
        }

        return self::respuesta(200);
    }

    /**
     * Aprobar (tal cual) o descartar varios artículos. Los que no tienen propuesta a revisar (ajenos,
     * borrados, ya resueltos) se ignoran sin error. Al aprobar, los que ya tienen descripción escrita
     * a mano se descartan igual que de a uno (409 en aprobar()) y no cuentan como resueltos.
     *
     * @param  string   $accion       aprobar | descartar
     * @param  int      $owner_id
     * @param  array    $article_ids  Ya validados (1 a MAXIMO_EN_LOTE enteros).
     * @param  int|null $resolved_by
     * @return int  Cuántos se resolvieron.
     */
    public static function en_lote($accion, $owner_id, array $article_ids, $resolved_by = null)
    {
        $resueltos = 0;
        $vistos    = [];

        foreach ($article_ids as $article_id) {
            $id = (int) $article_id;

            if ($id <= 0 || isset($vistos[$id])) {
                continue;
            }

            $vistos[$id] = true;

            if ($accion === 'aprobar') {
                $resultado = self::aprobar($owner_id, $id, null, $resolved_by);
            } else {
                $resultado = self::descartar($owner_id, $id, $resolved_by);
            }

            if ($resultado['status'] === 200) {
                $resueltos++;
            }
        }

        return $resueltos;
    }

    /* ----------------------------------------------------------------------------------------
     * Publicadas: editar y quitar
     * -------------------------------------------------------------------------------------- */

    /**
     * Edita título y texto de descripciones de IA ya publicadas de un artículo y las marca revisadas
     * por una persona. Tienda Nube se entera después.
     *
     * 🔴 Cada `id` tiene que ser una descripción `ai_generated` de ESE artículo: una humana o una de
     * otro artículo no se toca por acá (422, sin escribir nada).
     *
     * @param  int   $owner_id
     * @param  int   $article_id
     * @param  array $secciones  [{id, title, content}] ya validadas (1 a 3).
     * @return array  ['status' => 200|404|422, 'message' => string|null]
     */
    public static function editar_publicada($owner_id, $article_id, array $secciones)
    {
        $article = Article::where('id', (int) $article_id)
            ->where('user_id', (int) $owner_id)
            ->first();

        if (is_null($article)) {
            return self::respuesta(404, 'No se encontró ese artículo.');
        }

        $resultado = DB::transaction(function () use ($article, $secciones) {
            $de_ia = Description::where('article_id', $article->id)
                ->where('ai_generated', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($secciones as $seccion) {
                $id = isset($seccion['id']) ? (int) $seccion['id'] : 0;

                if (!$de_ia->has($id)) {
                    return false;
                }
            }

            $ahora = Carbon::now();

            foreach ($secciones as $seccion) {
                $description = $de_ia->get((int) $seccion['id']);

                $description->title          = (string) $seccion['title'];
                $description->content        = (string) $seccion['content'];
                $description->ai_reviewed_at = $ahora;
                $description->save();
            }

            return true;
        });

        if (!$resultado) {
            return self::respuesta(422, 'Alguna de las secciones no es una descripción automática de este artículo.');
        }

        self::sincronizar_tienda_nube($article);

        return self::respuesta(200);
    }

    /**
     * Quita las descripciones de IA publicadas de un artículo (las humanas nunca) y deja una
     * propuesta `descartada` con esas secciones: así queda la historia de qué se quitó, y el lote
     * automático no se la vuelve a generar. Tienda Nube se entera después.
     *
     * @param  int      $owner_id
     * @param  int      $article_id
     * @param  int|null $resolved_by
     * @return array  ['status' => 200|404, 'message' => string|null]
     */
    public static function quitar($owner_id, $article_id, $resolved_by = null)
    {
        $article = Article::where('id', (int) $article_id)
            ->where('user_id', (int) $owner_id)
            ->first();

        if (is_null($article)) {
            return self::respuesta(404, 'No se encontró ese artículo.');
        }

        $hecho = DB::transaction(function () use ($article, $resolved_by) {
            $de_ia = Description::where('article_id', $article->id)
                ->where('ai_generated', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($de_ia->isEmpty()) {
                return false;
            }

            $secciones = [];

            foreach ($de_ia as $description) {
                $secciones[] = [
                    'title'   => is_null($description->title) ? '' : (string) $description->title,
                    'content' => is_null($description->content) ? '' : (string) $description->content,
                ];
            }

            $primera = $de_ia->first();

            ArticleDescriptionProposal::create([
                'user_id'       => (int) $article->user_id,
                'article_id'    => (int) $article->id,
                'sections'      => $secciones,
                'ai_confidence' => $primera->ai_confidence,
                'ai_sources'    => is_array($primera->ai_sources) ? $primera->ai_sources : [],
                'status'        => ArticleDescriptionProposal::STATUS_DESCARTADA,
                'resolved_at'   => Carbon::now(),
                'resolved_by'   => self::entero_o_null($resolved_by),
            ]);

            // De a una, para que el observer de embeddings se entere de que el artículo perdió texto.
            foreach ($de_ia as $description) {
                $description->delete();
            }

            return true;
        });

        if (!$hecho) {
            return self::respuesta(404, 'Ese artículo no tiene descripciones automáticas publicadas.');
        }

        self::sincronizar_tienda_nube($article);

        return self::respuesta(200);
    }

    /* ----------------------------------------------------------------------------------------
     * Ayudas
     * -------------------------------------------------------------------------------------- */

    /**
     * La propuesta `a_revisar` de un artículo vivo del dueño, bloqueada para resolverla. Si hubiera
     * más de una (dos lotes a la vez), la más nueva.
     *
     * Va adentro de una transacción (la de quien llama).
     *
     * @param  int $owner_id
     * @param  int $article_id
     * @return \App\Models\ArticleDescriptionProposal|null
     */
    protected static function propuesta_a_revisar_bloqueada($owner_id, $article_id)
    {
        $existe = Article::where('id', (int) $article_id)
            ->where('user_id', (int) $owner_id)
            ->exists();

        if (!$existe) {
            return null;
        }

        return ArticleDescriptionProposal::where('user_id', (int) $owner_id)
            ->where('article_id', (int) $article_id)
            ->where('status', ArticleDescriptionProposal::STATUS_A_REVISAR)
            ->orderBy('id', 'DESC')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Marca resuelta una propuesta, y descartadas las otras a revisar del mismo artículo si las
     * hubiera (dos lotes a la vez): resuelto el artículo, no le queda nada pendiente.
     *
     * @param  \App\Models\ArticleDescriptionProposal $propuesta
     * @param  string   $status
     * @param  int|null $resolved_by
     * @return void
     */
    protected static function resolver(ArticleDescriptionProposal $propuesta, $status, $resolved_by)
    {
        $ahora = Carbon::now();

        $propuesta->status      = $status;
        $propuesta->resolved_at = $ahora;
        $propuesta->resolved_by = self::entero_o_null($resolved_by);
        $propuesta->save();

        ArticleDescriptionProposal::where('article_id', $propuesta->article_id)
            ->where('status', ArticleDescriptionProposal::STATUS_A_REVISAR)
            ->where('id', '<>', $propuesta->id)
            ->update([
                'status'      => ArticleDescriptionProposal::STATUS_DESCARTADA,
                'resolved_at' => $ahora,
                'resolved_by' => self::entero_o_null($resolved_by),
                'updated_at'  => $ahora,
            ]);
    }

    /**
     * Borra las descripciones de IA de un artículo que publicó el lote sin que nadie las revisara
     * (`ai_generated = 1` y `ai_reviewed_at` nulo). Las humanas y las revisadas nunca. De a una, para
     * que el observer de embeddings se entere.
     *
     * @param  int $article_id
     * @return void
     */
    protected static function borrar_las_de_ia_sin_revisar($article_id)
    {
        $sin_revisar = Description::where('article_id', (int) $article_id)
            ->where('ai_generated', true)
            ->whereNull('ai_reviewed_at')
            ->get();

        foreach ($sin_revisar as $description) {
            $description->delete();
        }
    }

    /**
     * Mismo gate que el resto del flujo de descripciones: solo se sincroniza si la integración con
     * Tienda Nube está habilitada en el entorno.
     *
     * @param  \App\Models\Article|null $article
     * @return void
     */
    public static function sincronizar_tienda_nube($article)
    {
        if (is_null($article)) {
            return;
        }

        if (env('USA_TIENDA_NUBE', false)) {
            dispatch(new ProcessSyncArticleDescriptionTiendaNube($article));
        }
    }

    /**
     * Secciones normalizadas: [{title, content}] como strings, sin lo que no sea una sección.
     *
     * @param  mixed $secciones
     * @return array
     */
    protected static function secciones_de($secciones)
    {
        $normalizadas = [];

        foreach (is_array($secciones) ? $secciones : [] as $seccion) {
            if (!is_array($seccion)) {
                continue;
            }

            $normalizadas[] = [
                'title'   => isset($seccion['title']) ? (string) $seccion['title'] : '',
                'content' => isset($seccion['content']) ? (string) $seccion['content'] : '',
            ];
        }

        return $normalizadas;
    }

    /**
     * Fuentes normalizadas: siempre un arreglo de {source, url, title}.
     *
     * @param  mixed $fuentes  El JSON de la columna o el arreglo ya decodificado.
     * @return array
     */
    protected static function fuentes_de($fuentes)
    {
        $normalizadas = [];

        foreach (self::decodificar($fuentes) as $fuente) {
            if (!is_array($fuente)) {
                continue;
            }

            $normalizadas[] = [
                'source' => isset($fuente['source']) ? (string) $fuente['source'] : null,
                'url'    => isset($fuente['url']) ? (string) $fuente['url'] : null,
                'title'  => isset($fuente['title']) ? (string) $fuente['title'] : null,
            ];
        }

        return $normalizadas;
    }

    /**
     * Un JSON de columna como arreglo (o el arreglo tal cual). Lo que no es un arreglo, vacío.
     *
     * @param  mixed $valor
     * @return array
     */
    protected static function decodificar($valor)
    {
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        return is_array($valor) ? $valor : [];
    }

    /**
     * El bloque `articulo` del Item. Los códigos vacíos van en null.
     *
     * @param  mixed $id
     * @param  mixed $nombre
     * @param  mixed $codigo_de_barras
     * @param  mixed $codigo_de_proveedor
     * @return array
     */
    protected static function articulo($id, $nombre, $codigo_de_barras, $codigo_de_proveedor)
    {
        return [
            'id'                  => (int) $id,
            'nombre'              => is_null($nombre) ? null : (string) $nombre,
            'codigo_de_barras'    => self::texto_o_null($codigo_de_barras),
            'codigo_de_proveedor' => self::texto_o_null($codigo_de_proveedor),
        ];
    }

    /**
     * @param  mixed $valor
     * @return string|null
     */
    protected static function texto_o_null($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * La fecha del Item: "2026-10-14 18:00:00".
     *
     * @param  mixed $fecha
     * @return string|null
     */
    protected static function fecha($fecha)
    {
        return is_null($fecha) || $fecha === '' ? null : Carbon::parse($fecha)->format('Y-m-d H:i:s');
    }

    /**
     * @param  mixed $por_pagina
     * @return int
     */
    protected static function por_pagina($por_pagina)
    {
        $por_pagina = (int) $por_pagina;

        if ($por_pagina <= 0) {
            return self::POR_PAGINA_DEFECTO;
        }

        return max(self::POR_PAGINA_MINIMO, min(self::POR_PAGINA_MAXIMO, $por_pagina));
    }

    /**
     * @param  mixed $valor
     * @return int|null
     */
    protected static function entero_o_null($valor)
    {
        return is_null($valor) || (int) $valor <= 0 ? null : (int) $valor;
    }

    /**
     * @param  int         $status
     * @param  string|null $message
     * @return array
     */
    protected static function respuesta($status, $message = null)
    {
        return ['status' => (int) $status, 'message' => $message];
    }
}
