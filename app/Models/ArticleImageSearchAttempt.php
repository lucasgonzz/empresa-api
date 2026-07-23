<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Diagnóstico de un intento de búsqueda automática de imagen para un artículo (grupo 201,
 * Prompt 01). Una fila por artículo + criterio de búsqueda probado (código de barras y/o
 * nombre) dentro de una misma corrida (`batch_uuid`) del job de asignación masiva de
 * imágenes. Ver comentario completo del contrato de `candidates` y de los valores de
 * `outcome` en la migración `create_article_image_search_attempts_table`.
 */
class ArticleImageSearchAttempt extends Model
{
    // Outcomes válidos de la fila completa (criterio de búsqueda probado para el artículo).
    const OUTCOME_ASSIGNED = 'assigned';
    const OUTCOME_NO_QUERY = 'no_query';
    const OUTCOME_NO_RESULTS = 'no_results';
    const OUTCOME_API_ERROR = 'api_error';
    const OUTCOME_QUOTA = 'quota';
    const OUTCOME_ALL_CANDIDATES_REJECTED = 'all_candidates_rejected';

    // Outcomes válidos de cada elemento del array `candidates` (una imagen candidata devuelta por Google).
    const CANDIDATE_OUTCOME_ASSIGNED = 'assigned';
    const CANDIDATE_OUTCOME_DOWNLOAD_FAILED = 'download_failed';
    const CANDIDATE_OUTCOME_NOT_PROCESSABLE = 'not_processable';
    const CANDIDATE_OUTCOME_REJECTED_BY_PREFILTER = 'rejected_by_prefilter';
    const CANDIDATE_OUTCOME_REJECTED_BY_AI = 'rejected_by_ai';
    const CANDIDATE_OUTCOME_NOT_EVALUATED = 'not_evaluated';

    // Todas las columnas persistibles de la tabla (menos id y timestamps).
    protected $fillable = [
        'user_id',
        'batch_uuid',
        'article_id',
        'article_name',
        'article_bar_code',
        'criterion',
        'criterion_order',
        'query',
        'google_items_count',
        'google_total_results',
        'api_error',
        'outcome',
        'outcome_detail',
        'candidates',
    ];

    // `candidates` se guarda como json en la base y se expone como array de PHP.
    protected $casts = [
        'candidates' => 'array',
    ];

    /**
     * Scope requerido por Controller::fullModel() del proyecto. Se completa si en el
     * futuro este modelo necesita exponer relaciones eager-loaded; por ahora no tiene
     * relaciones Eloquent nuevas (el artículo puede haberse borrado, por eso se guarda un
     * snapshot de su nombre/código de barras en vez de una relación).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($query)
    {

    }

    /**
     * Borra los intentos de búsqueda de imagen de un usuario más viejos que `$days` días,
     * para que la tabla no crezca sin techo. Pensado para llamarse desde el job de
     * asignación masiva de imágenes (prompt 03 de este grupo) al final de cada corrida.
     * Borra directo por query builder (sin cargar modelos) por performance.
     *
     * @param int $user_id Usuario dueño de los intentos a purgar.
     * @param int $days Antigüedad en días a partir de la cual se considera "viejo". Default 30.
     * @return int Cantidad de filas borradas.
     */
    static function purge_old(int $user_id, int $days = 30)
    {
        return DB::table('article_image_search_attempts')
            ->where('user_id', $user_id)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
