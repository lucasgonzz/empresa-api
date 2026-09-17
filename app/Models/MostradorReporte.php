<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Informe del mostrador del módulo IA (misión modulo-ia-mostrador).
 *
 * Una fila por (dueño, tipo, fecha). Nace con estado 'hechos' cuando el API calcula
 * el JSON determinista de `hechos` (RecolectorDeHechos) y pasa a 'listo' cuando la
 * skill /mostrador deposita `titulo`, `resumen` y `contenido` (JSON de bloques que
 * valida MostradorContenidoValidator). El escritorio del dueño lista SOLO los 'listo'.
 *
 * Dos estados más, del cálculo asincrónico de compras y stock en un catálogo grande
 * (CalcularHechosMostradorJob): 'calculando' mientras el job corre (la skill hace
 * polling) y 'error' si reventó, con el motivo en `error_mensaje`. Al terminar bien, el
 * job deja 'listo' si la fila ya tenía contenido depositado (un recálculo forzado sobre
 * un informe visible) y 'hechos' si no.
 *
 * tipo: 'dia' (rendimiento de ayer) | 'caja' (caja y vencimientos, de hoy) | 'tienda' |
 * 'compras' | 'stock'.
 *
 * La conversación del dueño sobre un informe es una AiConversation con
 * origen = 'mostrador_reporte' y referencia_id = este id (una por persona).
 *
 * `avisado_at` (misión asistente-por-whatsapp, 16/9/2026): cuándo se le avisó de este
 * informe al dueño por WhatsApp. El comando del admin pide los 'listo' de hoy con la
 * columna en null y la sella DESPUÉS de que el envío salió, en un segundo request: si el
 * WhatsApp falla, el informe no queda marcado y el aviso sale la próxima corrida.
 */
class MostradorReporte extends Model
{
    /** Los cinco tipos de informe, en el orden fijo del escritorio. */
    const TIPOS = ['dia', 'caja', 'tienda', 'compras', 'stock'];

    /** Estado de un informe con hechos calculados y sin texto todavía. */
    const ESTADO_HECHOS = 'hechos';

    /** Estado de un informe con el contenido depositado por la skill. */
    const ESTADO_LISTO = 'listo';

    /** Estado de un informe cuyos hechos está calculando CalcularHechosMostradorJob. */
    const ESTADO_CALCULANDO = 'calculando';

    /** Estado de un informe cuyo cálculo asincrónico falló (motivo en error_mensaje). */
    const ESTADO_ERROR = 'error';

    /** Los cuatro estados. */
    const ESTADOS = ['hechos', 'listo', 'calculando', 'error'];

    /** Origen de las AiConversation que nacen de un informe del mostrador. */
    const ORIGEN_CONVERSACION = 'mostrador_reporte';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'tipo',
        'fecha',
        'titulo',
        'resumen',
        'hechos',
        'contenido',
        'estado',
        'error_mensaje',
        'hechos_at',
        'generado_at',
        'leido_at',
        'avisado_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id'     => 'integer',
        'fecha'       => 'date',
        'hechos'      => 'array',
        'contenido'   => 'array',
        'hechos_at'   => 'datetime',
        'generado_at' => 'datetime',
        'leido_at'    => 'datetime',
        'avisado_at'  => 'datetime',
    ];

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Solo los informes que ya tienen texto (los que ve el dueño).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeListos($query)
    {
        return $query->where('estado', self::ESTADO_LISTO);
    }

    /**
     * true si el cálculo de los hechos está en curso (o se cree que está: ver
     * AdminSync\MostradorController::calculo_vencido para la fila que quedó colgada).
     *
     * @return bool
     */
    public function esta_calculando()
    {
        return $this->estado === self::ESTADO_CALCULANDO;
    }

    /**
     * true si el tipo es uno de los del mostrador (TIPOS).
     *
     * @param mixed $tipo
     * @return bool
     */
    public static function es_tipo_valido($tipo)
    {
        return is_string($tipo) && in_array($tipo, self::TIPOS, true);
    }
}
