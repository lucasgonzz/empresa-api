<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estado de actualización del sistema de UN dueño (misión aviso-actualizacion-sistema, 25/11/2026).
 *
 * Una fila por `owner_id`. La tabla la crea sólo empresa-api pero la lee también tienda-api (misma base):
 * tienda bloquea sii `estado = 'actualizando' AND vence_at > NOW()` para ese comercio. Toda la lógica
 * (transiciones, presencia, pedido de OK) vive en `ActualizacionDelSistemaHelper`; este modelo no decide nada.
 *
 * Estados: `libre`, `pidiendo_ok`, `rechazado`, `actualizando`.
 */
class ActualizacionDelSistema extends Model
{
    /** Estado normal: nada que avisar ni bloquear. */
    const ESTADO_LIBRE = 'libre';

    /** Hay un pedido de OK abierto: cada computadora ve el modal. */
    const ESTADO_PIDIENDO_OK = 'pidiendo_ok';

    /** Alguien apretó "Ahora no": el admin lo lee y cancela. */
    const ESTADO_RECHAZADO = 'rechazado';

    /** Se está actualizando: no se puede ingresar ni usar el sistema. */
    const ESTADO_ACTUALIZANDO = 'actualizando';

    /**
     * Tabla explícita: el plural automático de Laravel no sabe pluralizar «actualizacion_del_sistema».
     *
     * @var string
     */
    protected $table = 'actualizaciones_del_sistema';

    /**
     * Columnas que se pueden asignar en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'owner_id',
        'estado',
        'upgrade_id',
        'version',
        'pedido_uuid',
        'pedido_at',
        'vence_respuesta_at',
        'rechazado_at',
        'rechazado_por_user_id',
        'actualizando_desde',
        'vence_at',
    ];

    /**
     * Casts de ids y de las columnas de fecha (Carbon).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id'              => 'integer',
        'upgrade_id'            => 'integer',
        'rechazado_por_user_id' => 'integer',
        'pedido_at'             => 'datetime',
        'vence_respuesta_at'    => 'datetime',
        'rechazado_at'          => 'datetime',
        'actualizando_desde'    => 'datetime',
        'vence_at'              => 'datetime',
    ];

    /**
     * Scope estándar de los modelos del sistema (sin relaciones que cargar en este modelo).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query) {}
}
