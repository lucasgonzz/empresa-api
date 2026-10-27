<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un artículo dentro de una búsqueda masiva de peso y medidas (misión medidas-masivas-catalogo,
 * 27/10/2026): su estado, el motivo, el diagnóstico por plan de búsqueda, la propuesta de medidas y la
 * evidencia (la cita literal de la página) de cada campo.
 *
 * Las solapas del detalle en Alertas → Catálogo → Medidas agrupan estos estados:
 *   - Asignadas: asignada, aprobada.
 *   - A revisar: a_revisar.
 *   - Sin datos: sin_datos, rechazada, quitada, sin_procesar, error_interno.
 * Y "pendientes" (la barra de avance) son pendiente + procesando.
 */
class DimensionSearchItem extends Model
{
    const STATUS_PENDIENTE     = 'pendiente';
    const STATUS_PROCESANDO    = 'procesando';
    const STATUS_ASIGNADA      = 'asignada';
    const STATUS_A_REVISAR     = 'a_revisar';
    const STATUS_SIN_DATOS     = 'sin_datos';
    const STATUS_APROBADA      = 'aprobada';
    const STATUS_RECHAZADA     = 'rechazada';
    const STATUS_QUITADA       = 'quitada';
    const STATUS_ERROR_INTERNO = 'error_interno';
    const STATUS_SIN_PROCESAR  = 'sin_procesar';

    /** Estados que cuentan como "asignadas" (la propuesta ya está escrita en el artículo). */
    const ESTADOS_ASIGNADAS = [self::STATUS_ASIGNADA, self::STATUS_APROBADA];

    /** Estados que cuentan como "a revisar". */
    const ESTADOS_A_REVISAR = [self::STATUS_A_REVISAR];

    /** Estados que cuentan como "sin datos" (no se escribió nada en el artículo). */
    const ESTADOS_SIN_DATOS = [
        self::STATUS_SIN_DATOS,
        self::STATUS_RECHAZADA,
        self::STATUS_QUITADA,
        self::STATUS_SIN_PROCESAR,
        self::STATUS_ERROR_INTERNO,
    ];

    /** Estados que cuentan como "pendientes" (todavía no los tocó el motor, o lo está haciendo). */
    const ESTADOS_PENDIENTES = [self::STATUS_PENDIENTE, self::STATUS_PROCESANDO];

    /** Criterios de búsqueda con los que se consigue un dato. */
    const CRITERIO_CODIGO_DE_BARRAS = 'codigo_de_barras';
    const CRITERIO_CODIGO_PROVEEDOR = 'codigo_proveedor';
    const CRITERIO_NOMBRE           = 'nombre';

    protected $guarded = [];

    protected $casts = [
        'evidencia'   => 'array',
        'fuentes'     => 'array',
        'diagnostico' => 'array',
        'aplicado'    => 'array',
    ];

    protected $dates = ['revisado_at', 'procesado_at'];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. El payload del item
     * lo arma DimensionSearchRunHelper.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * La corrida a la que pertenece.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function run()
    {
        return $this->belongsTo(DimensionSearchRun::class, 'run_id');
    }
}
