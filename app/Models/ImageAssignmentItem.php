<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un artículo dentro de una asignación inteligente de imágenes (misión imagenes-catalogo-completo,
 * 27/9/2026): su estado, el motivo, el diagnóstico por criterio de búsqueda y la imagen elegida.
 *
 * Las solapas del detalle en Alertas → Imágenes agrupan estos estados:
 *   - No asignadas: no_asignada, rechazada, quitada, sin_procesar.
 *   - A revisar:    a_revisar.
 *   - Asignadas:    asignada, aprobada.
 * Y "pendientes" (la barra de avance) son pendiente + procesando.
 */
class ImageAssignmentItem extends Model
{
    const STATUS_PENDIENTE    = 'pendiente';
    const STATUS_PROCESANDO   = 'procesando';
    const STATUS_ASIGNADA     = 'asignada';
    const STATUS_A_REVISAR    = 'a_revisar';
    const STATUS_NO_ASIGNADA  = 'no_asignada';
    const STATUS_APROBADA     = 'aprobada';
    const STATUS_RECHAZADA    = 'rechazada';
    const STATUS_QUITADA      = 'quitada';
    const STATUS_SIN_PROCESAR = 'sin_procesar';

    /** Estados que cuentan como "asignadas" (contrato §5.1). */
    const ESTADOS_ASIGNADAS = [self::STATUS_ASIGNADA, self::STATUS_APROBADA];

    /** Estados que cuentan como "a revisar". */
    const ESTADOS_A_REVISAR = [self::STATUS_A_REVISAR];

    /** Estados que cuentan como "no asignadas". */
    const ESTADOS_NO_ASIGNADAS = [
        self::STATUS_NO_ASIGNADA,
        self::STATUS_RECHAZADA,
        self::STATUS_QUITADA,
        self::STATUS_SIN_PROCESAR,
    ];

    /** Estados que cuentan como "pendientes" (todavía no los tocó el motor, o lo está haciendo). */
    const ESTADOS_PENDIENTES = [self::STATUS_PENDIENTE, self::STATUS_PROCESANDO];

    /** Prefijo de los archivos de las imágenes "a revisar" (se renombran al aprobarlas). */
    const PREFIJO_CANDIDATA = 'imgcand_';

    protected $guarded = [];

    protected $casts = [
        'diagnostico' => 'array',
        'imagen_meta' => 'array',
    ];

    protected $dates = ['revisado_at', 'procesado_at'];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. El payload del
     * item lo arma ImageAssignmentRunHelper, que resuelve el nombre de quien lo revisó.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * La asignación a la que pertenece.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function run()
    {
        return $this->belongsTo(ImageAssignmentRun::class, 'run_id');
    }
}
