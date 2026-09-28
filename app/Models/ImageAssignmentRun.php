<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Una asignación inteligente de imágenes: un lanzamiento de búsqueda de imágenes para un grupo de
 * artículos (misión imagenes-catalogo-completo, 27/9/2026).
 *
 * Nace desde tres lugares: el botón del listado sobre una selección (`seleccion`), el asistente
 * (`asistente`) o "todo el catálogo" desde el acceso maestro (`catalogo`). Cada artículo es una
 * fila de ImageAssignmentItem, y el trabajo lo hace ProcessImageAssignmentRunJob por tramos.
 *
 * Es lo que la SPA lista en Alertas → Imágenes (una fila por asignación).
 */
class ImageAssignmentRun extends Model
{
    const STATUS_PENDIENTE  = 'pendiente';
    const STATUS_EN_PROCESO = 'en_proceso';
    const STATUS_TERMINADA  = 'terminada';
    const STATUS_DETENIDA   = 'detenida';
    const STATUS_FALLIDA    = 'fallida';

    const ORIGEN_CATALOGO  = 'catalogo';
    const ORIGEN_SELECCION = 'seleccion';
    const ORIGEN_ASISTENTE = 'asistente';

    const PROVEEDOR_SERPER = 'serper';
    const PROVEEDOR_GOOGLE = 'google';

    /**
     * Minutos sin señal de vida (last_progress_at) para considerar "trabada" una corrida en
     * proceso. Un artículo, en el peor caso (dos búsquedas, descargas y tres llamadas a la IA),
     * tarda un par de minutos: quince sin avanzar es un worker muerto, no uno lento.
     */
    const MINUTOS_PARA_TRABADA = 15;

    protected $guarded = [];

    protected $casts = [
        'aplica_tope_diario' => 'boolean',
    ];

    protected $dates = ['started_at', 'finished_at', 'last_progress_at', 'visto_at'];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. Los contadores
     * por estado los arma ImageAssignmentRunHelper con una consulta agrupada, no con una relación.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * Los artículos de la asignación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(ImageAssignmentItem::class, 'run_id');
    }

    /**
     * Las que todavía están en proceso (o esperando que un worker las levante).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActivas($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDIENTE, self::STATUS_EN_PROCESO]);
    }

    /**
     * ¿Todavía en proceso (o esperando al worker)?
     *
     * @return bool
     */
    public function esta_activa()
    {
        return in_array($this->status, [self::STATUS_PENDIENTE, self::STATUS_EN_PROCESO], true);
    }

    /**
     * ¿Ya salió de proceso (terminada, detenida o fallida)?
     *
     * @return bool
     */
    public function salio_de_proceso()
    {
        return in_array($this->status, [self::STATUS_TERMINADA, self::STATUS_DETENIDA, self::STATUS_FALLIDA], true);
    }

    /**
     * "Parece trabada": en proceso y sin avanzar hace más de MINUTOS_PARA_TRABADA. Se mide contra
     * la última señal de vida y, si todavía no hubo ninguna, contra el arranque.
     *
     * @return bool
     */
    public function esta_trabada()
    {
        if ($this->status !== self::STATUS_EN_PROCESO) {
            return false;
        }

        $ultima_senal = !is_null($this->last_progress_at) ? $this->last_progress_at : $this->started_at;

        if (is_null($ultima_senal)) {
            return false;
        }

        return Carbon::parse($ultima_senal)->lt(Carbon::now()->subMinutes(self::MINUTOS_PARA_TRABADA));
    }
}
