<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de una sincronización masiva de publicaciones ML hacia artículos locales.
 *
 * Responsabilidad:
 * - Persistir estado del job y contadores de resumen para la UI.
 * - Relacionar artículos tocados en la corrida (pivot con status).
 */
class SyncFromMeliArticle extends Model
{
    use HasFactory;

    /** Estado: job encolado, aún no procesado. */
    public const STATUS_PENDIENTE = 'pendiente';

    /** Estado: importación en curso. */
    public const STATUS_EN_PROGRESO = 'en_progreso';

    /** Estado: finalizó sin error fatal. */
    public const STATUS_EXITOSA = 'exitosa';

    /** Estado: falló la corrida completa. */
    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    /**
     * Casts de columnas de resumen y fechas.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'attempted_at'                 => 'datetime',
        'synced_at'                    => 'datetime',
        'meli_items_total'             => 'integer',
        'articles_created_count'       => 'integer',
        'articles_skipped_count'       => 'integer',
        'articles_error_count'         => 'integer',
        'articles_linked_total_count'  => 'integer',
    ];

    /**
     * Carga artículos vinculados a esta corrida.
     *
     * @param \Illuminate\Database\Eloquent\Builder $q Query base.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($q)
    {
        return $q->with('articles');
    }

    /**
     * Artículos locales asociados a esta sincronización (detalle por ítem).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function articles()
    {
        return $this->belongsToMany(Article::class, 'sync_from_meli_article_article')
            ->withPivot('status', 'error_code');
    }
}
