<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una "computadora" conectada al sistema de un dueño (misión aviso-actualizacion-sistema, 25/11/2026).
 *
 * Computadora = sesión de Laravel (un navegador; sus pestañas comparten sesión). `sesion` es
 * `hash('sha256', session()->getId())`, nunca el id en claro. `visto_at` lo refresca el polling del SPA y
 * `respuesta` guarda lo que contestó al pedido de OK: `pendiente`, `acepto`, `ahora_no` o `expulsado`.
 * Toda la lógica vive en `ActualizacionDelSistemaHelper`.
 */
class ActualizacionDispositivo extends Model
{
    /** Todavía no contestó el pedido de OK. */
    const RESPUESTA_PENDIENTE = 'pendiente';

    /** Aceptó que se actualice. */
    const RESPUESTA_ACEPTO = 'acepto';

    /** Apretó "Ahora no". */
    const RESPUESTA_AHORA_NO = 'ahora_no';

    /** No contestó a tiempo: se le cerró la sesión sola. */
    const RESPUESTA_EXPULSADO = 'expulsado';

    /**
     * Tabla explícita: el plural automático de Laravel no sabe pluralizar «actualizacion_dispositivo».
     *
     * @var string
     */
    protected $table = 'actualizacion_dispositivos';

    /**
     * Columnas que se pueden asignar en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'owner_id',
        'user_id',
        'sesion',
        'visto_at',
        'pedido_uuid',
        'respuesta',
        'respondio_at',
    ];

    /**
     * Casts de ids y de las columnas de fecha (Carbon).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id'     => 'integer',
        'user_id'      => 'integer',
        'visto_at'     => 'datetime',
        'respondio_at' => 'datetime',
    ];

    /**
     * Scope estándar de los modelos del sistema (sin relaciones que cargar en este modelo).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query) {}
}
