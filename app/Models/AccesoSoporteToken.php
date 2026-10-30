<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un acceso de soporte de un solo uso (misión acceso-soporte-firmado, 30/10/2026).
 *
 * Lo emite el admin con la clave compartida (`AdminSync\AccesoSoporteController@store`), se guarda SOLO su
 * hash y se canjea una única vez (`AccesoSoporteController@canjear`) por una sesión del dueño. La fila queda
 * después de usarse: es la auditoría del acceso. Ver la migración `create_acceso_soporte_tokens_table`.
 *
 * 🔴 Este modelo nunca ve el token en claro: lo único que existe acá es `token_hash`. Toda la lógica
 * (emitir, validar el formato, consumir de forma atómica) vive en `AccesoSoporteHelper`.
 */
class AccesoSoporteToken extends Model
{
    /**
     * Tabla explícita: el plural automático de Laravel no sabe pluralizar «acceso_soporte_token».
     *
     * @var string
     */
    protected $table = 'acceso_soporte_tokens';

    /**
     * Columnas que se pueden asignar en masa. `token_hash` está acá a propósito: el helper lo arma a mano
     * con `hash('sha256', ...)` y es el único punto de escritura.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'token_hash',
        'user_id',
        'solicitante',
        'motivo',
        'ip_solicitud',
        'vence_en',
        'usado_en',
        'ip_canje',
        'user_agent_canje',
    ];

    /**
     * Casts de las columnas de fecha (Carbon).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id'  => 'integer',
        'vence_en' => 'datetime',
        'usado_en' => 'datetime',
    ];

    /**
     * Scope requerido por Controller::fullModel() en todo modelo del repo. Este modelo no se expone por
     * fullModel() ni tiene relaciones que cargar, así que queda vacío.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query) {}
}
