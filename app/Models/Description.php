<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Description extends Model
{
    protected $guarded = [];

    /**
     * Casts de atributos.
     * - ai_generated: booleano (0/1 en BD).
     * - ai_sources: array PHP <-> JSON guardado en la columna `text` (asignar
     *   siempre un array, nunca un string ya serializado).
     * - ai_reviewed_at: instancia Carbon.
     */
    protected $casts = [
        'ai_generated'   => 'boolean',
        'ai_sources'     => 'array',
        'ai_reviewed_at' => 'datetime',
    ];

    /*
     * 🔴 ACÁ NO VA `protected $touches = ['article']`. Se probó y se sacó el 15/8/2026.
     *
     * La idea era buena en apariencia: bumpear `articles.updated_at` al guardar una
     * descripcion, para que el comando agendado articles:generate-embeddings (que busca
     * `updated_at > embedding_generated_at`) levantara el cambio como segunda defensa
     * detras de DescriptionObserver. El problema es lo que arrastra:
     *
     * 1. `articles.updated_at` es la clave del sync incremental de la SPA y del export
     *    por keyset. Con el touch, despues de una importacion de 20.000 articulos con
     *    descripcion el front se baja el catalogo ENTERO. Eso es un cambio de producto
     *    visible, y no se decide de costado adentro de un arreglo de embeddings.
     * 2. Esos mismos 20.000 articulos quedan con updated_at > embedding_generated_at, asi
     *    que el comando de 30 minutos encola 20.000 jobs. Cada uno hidrata el articulo con
     *    category, brand y descriptions antes de poder cortar por hash: el hash ahorra las
     *    llamadas a OpenAI, no las ~80.000 consultas.
     * 3. Suma un UPDATE por descripcion guardada en el camino mas caliente del sistema.
     *
     * La revectorizacion inmediata —que es lo que se pidio— la garantiza DescriptionObserver,
     * que dispara en created, updated y deleted. Si en algun momento hace falta la segunda
     * defensa, la forma correcta es que el comando mire tambien `descriptions.updated_at`,
     * no ensuciar el reloj del articulo, que lo lee medio sistema.
     */

    function scopeWithAll($q) {

    }

    function article() {
        return $this->belongsTo(Article::class);
    }

    /**
     * Descripciones generadas por IA que todavia no fueron revisadas por un humano.
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopePendingAiReview($q) {
        return $q->where('ai_generated', true)
                 ->where('ai_confidence', 'low')
                 ->whereNull('ai_reviewed_at');
    }

    /**
     * Descripciones escritas por una persona (nunca se pisan automaticamente).
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeHuman($q) {
        return $q->where('ai_generated', false);
    }
}
