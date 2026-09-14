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
 * tipo: 'dia' (rendimiento de ayer) | 'tienda' | 'compras' | 'stock'.
 *
 * La conversación del dueño sobre un informe es una AiConversation con
 * origen = 'mostrador_reporte' y referencia_id = este id (una por persona).
 */
class MostradorReporte extends Model
{
    /** Los cuatro tipos de informe, en el orden fijo del escritorio. */
    const TIPOS = ['dia', 'tienda', 'compras', 'stock'];

    /** Estado de un informe con hechos calculados y sin texto todavía. */
    const ESTADO_HECHOS = 'hechos';

    /** Estado de un informe con el contenido depositado por la skill. */
    const ESTADO_LISTO = 'listo';

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
        'hechos_at',
        'generado_at',
        'leido_at',
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
     * true si el tipo es uno de los cuatro del mostrador.
     *
     * @param mixed $tipo
     * @return bool
     */
    public static function es_tipo_valido($tipo)
    {
        return is_string($tipo) && in_array($tipo, self::TIPOS, true);
    }
}
