<?php

namespace App\Http\Controllers\Helpers\article;

use App\Jobs\FinalizeEmbeddingRun;
use App\Models\Article;
use App\Models\EmbeddingRun;
use Carbon\Carbon;

/**
 * Contadores de estado de los embeddings vectoriales de los artículos de un comercio, para el
 * whatsapp-dashboard (misión embeddings-estado-whatsapp-dashboard, 15/9/2026).
 *
 * No existe un estado explícito por artículo más allá de "hace falta generarle uno" o no: lo que
 * ya distingue `GenerateArticleEmbeddings::handle()` para elegir qué procesar es exactamente
 * `sin_generar` y `pendiente` de acá, partido en dos mitades excluyentes por `whereNotNull`.
 *
 * 🔴 EL CORTE ES POR `embedding`, NO POR `embedding_generated_at` -- corregido tras el chequeo
 * independiente de esta misma misión. `embedding_generated_at` no es "tiene un vector": es "ya se
 * intentó". `GenerateArticleEmbeddingJob` escribe ese timestamp por query builder incluso cuando
 * `ArticleEmbeddingService::update_article_embedding()` no encontró texto representativo y NO
 * escribió ningún vector (`embedding` queda NULL para siempre). Con el corte viejo
 * (`whereNull('embedding_generated_at')`) ese artículo no caía en `sin_generar` -tiene el
 * timestamp- ni en `pendiente` -el query builder no toca `updated_at`, así que no queda posterior
 * al timestamp-: desaparecía de los dos contadores aunque el comando siguiera re-encolándolo cada
 * ciclo para siempre (matchea su `whereNull('embedding')`). Es un caso raro en la práctica (exige
 * artículo sin nombre, sin categoría, sin marca, sin código de barras y sin descripciones, ver
 * `ArticleEmbeddingService::embedding_for_article()`), pero el corte por `embedding` lo cubre sin
 * costo y es un espejo exacto de lo que el comando de verdad evalúa.
 *
 * `generandose` no se puede saber a nivel de artículo (exigiría leer la tabla `jobs` de Laravel,
 * sin ningún precedente en el repo, y en el hosting compartido -la mayoría de los clientes- el
 * worker corre un proceso por vez cada un minuto: ese número leería casi siempre 0 o 1). Se
 * informa a nivel de TANDA en cambio, con `embedding_runs` (la misma tabla que ya trackea cada
 * corrida del comando): si hay una tanda viva, cuántos artículos le faltan.
 */
class ArticleEmbeddingsEstadoHelper {

    /**
     * @param  int  $user_id
     * @return array{sin_generar:int, pendiente:int, generandose:int}
     */
    static function estado($user_id) {

        $base = Article::query()
            ->where('user_id', $user_id)
            ->where('status', 'active')
            ->whereNull('deleted_at');

        // Nunca se le escribió un vector (primera vez, o el intento no encontró texto que
        // vectorizar). Mismo criterio que la primera rama del WHERE de
        // GenerateArticleEmbeddings::handle() (whereNull('embedding')).
        $sin_generar = (clone $base)->whereNull('embedding')->count();

        // Ya tiene un vector, pero le falta el timestamp o el artículo cambió después de la
        // última generación: desactualizado, a la espera de que el próximo ciclo lo regenere.
        // El whereNotNull('embedding') lo deja excluyente de sin_generar. Cubre las otras dos
        // ramas del WHERE del comando (whereNull('embedding_generated_at') OR
        // whereColumn('updated_at', '>', 'embedding_generated_at')), que sin el whereNotNull de
        // acá se solaparían con sin_generar.
        $pendiente = (clone $base)
            ->whereNotNull('embedding')
            ->where(function ($q) {
                $q->whereNull('embedding_generated_at')
                  ->orWhereColumn('updated_at', '>', 'embedding_generated_at');
            })
            ->count();

        return [
            'sin_generar' => $sin_generar,
            'pendiente'   => $pendiente,
            'generandose' => self::generandose($user_id),
        ];
    }

    /**
     * Artículos que le faltan a la tanda de generación en curso, si hay una viva.
     *
     * Misma noción de "viva" que ya usa GenerateArticleEmbeddings::handle() antes de decidir si
     * abre una tanda nueva: una tanda 'despachando'/'en_proceso' más vieja que
     * FinalizeEmbeddingRun::MINUTOS_PARA_VENCER se considera abandonada, no en curso. Acá no se
     * la marca 'vencida' -eso es trabajo exclusivo del propio comando, la próxima vez que corra-,
     * simplemente se la ignora para este número.
     *
     * Mientras la tanda está en 'despachando' (el comando todavía está encolando), `total_jobs`
     * sigue en 0 -recién se escribe el valor final al pasar a 'en_proceso'-, así que este número
     * puede leer 0 unos segundos aunque ya haya jobs corriendo: se autocorrige solo apenas el
     * comando termina de despachar, y no vale la pena una segunda consulta para evitar esa
     * ventana chica.
     *
     * @param  int  $user_id
     * @return int
     */
    static function generandose($user_id) {

        $run = EmbeddingRun::where('user_id', $user_id)
            ->whereIn('status', ['despachando', 'en_proceso'])
            ->orderBy('id', 'desc')
            ->first();

        if (is_null($run)) {
            return 0;
        }

        $vence_at = Carbon::parse($run->created_at)->addMinutes(FinalizeEmbeddingRun::MINUTOS_PARA_VENCER);

        if (Carbon::now()->greaterThanOrEqualTo($vence_at)) {
            return 0;
        }

        return max($run->total_jobs - $run->terminados, 0);
    }
}
