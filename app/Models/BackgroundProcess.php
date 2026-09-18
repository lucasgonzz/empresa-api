<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un proceso en segundo plano visible para el usuario (misión procesos-en-segundo-plano,
 * 18/9/2026).
 *
 * Es la capa de presentación común de todos los procesos largos: la fila la escribe
 * `BackgroundProcessHelper` desde cada job, y la SPA la muestra en la píldora de arriba a la
 * derecha, en el modal de procesos y en el detalle. El estado de verdad de cada flujo sigue
 * viviendo en su tabla propia (import_statuses, price_update_runs, masive_updates...): esta
 * fila apunta a esa con `referencia()` y no la reemplaza.
 */
class BackgroundProcess extends Model
{
    const STATUS_PENDIENTE  = 'pendiente';
    const STATUS_EN_PROCESO = 'en_proceso';
    const STATUS_COMPLETADO = 'completado';
    const STATUS_FALLO      = 'fallo';

    protected $guarded = [];

    protected $dates = ['started_at', 'finished_at', 'broadcast_at', 'visto_at'];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. La referencia
     * NO se carga acá a propósito: es polimórfica y el detalle la arma
     * BackgroundProcessHelper::referencia_para_detalle() eligiendo columnas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @return void
     */
    public function scopeWithAll($q)
    {
    }

    /**
     * Registro propio del flujo al que pertenece este proceso (ImportStatus, PriceUpdateRun,
     * MasiveUpdate, ExportHistory, ExcelAnalysisRun...). Puede no haber ninguno.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function referencia()
    {
        return $this->morphTo('referencia', 'referencia_type', 'referencia_id');
    }

    /**
     * Los que todavía no terminaron.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActivos($q)
    {
        return $q->whereIn('status', [self::STATUS_PENDIENTE, self::STATUS_EN_PROCESO]);
    }

    /**
     * Los que ya terminaron (bien o mal).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTerminados($q)
    {
        return $q->whereIn('status', [self::STATUS_COMPLETADO, self::STATUS_FALLO]);
    }

    /**
     * ¿Ya está cerrado? Un proceso cerrado no vuelve a cambiar de estado (ver
     * BackgroundProcessHelper::completar / fallar, que son idempotentes por esto).
     *
     * @return bool
     */
    public function esta_terminado()
    {
        return in_array($this->status, [self::STATUS_COMPLETADO, self::STATUS_FALLO]);
    }

    /**
     * Resultado decodificado (los números que muestra el detalle).
     *
     * @return array
     */
    public function resultado()
    {
        if (is_null($this->resultado_json) || $this->resultado_json === '') {
            return [];
        }

        $decoded = json_decode($this->resultado_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
