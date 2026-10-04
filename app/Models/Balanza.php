<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una balanza del comercio (misión balanzas-configurables, 3/10/2026).
 *
 * Describe cómo leer los tickets que imprime: el código EMPIEZA con `prefijo`, los `digitos`
 * anteriores al último (el verificador) son un importe o un peso según `tipo_dato`, y el renglón
 * se le imputa a `article_id`. La lectura vive en BalanzaHelper::leer_ticket_por_balanzas() y solo
 * corre si el dueño tiene `users.tickets_de_balanza = 'balanzas'`.
 *
 * Es por dueño (`user_id`), como ChequeBanco, y baja en recursos-iniciales.
 */
class Balanza extends Model
{
    /**
     * Nombre explícito: el pluralizador de Laravel es inglés y no conviene depender de que acierte
     * con un nombre en castellano.
     *
     * @var string
     */
    protected $table = 'balanzas';

    protected $guarded = [];

    /**
     * Enteros como enteros en el JSON: la SPA compara `article_id` y `digitos` contra números, y
     * `balanza_id` viaja en la respuesta del escaneo.
     *
     * @var array
     */
    protected $casts = [
        'user_id'    => 'integer',
        'article_id' => 'integer',
        'digitos'    => 'integer',
    ];

    /**
     * El artículo, para que el ABM muestre su nombre en la tabla y en el campo de búsqueda.
     * Liviano a propósito (sin las relaciones del artículo): el ABM solo lee el nombre, y la
     * lectura del ticket trae el artículo completo por su cuenta.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @return void
     */
    function scopeWithAll($q) {
        $q->with('article');
    }

    /**
     * Artículo al que se le imputa el ticket. Un artículo borrado (soft delete) vuelve null:
     * la balanza queda "sin artículo válido" hasta que se le asigne otro.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function article() {
        return $this->belongsTo(Article::class);
    }
}
