<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila del registro de auditoría de cambios (misión auditoria-de-cambios, 30/9/2026).
 *
 * Es INMUTABLE y no tiene pantalla, controlador ni ruta: la escribe únicamente
 * `App\Services\AuditLog\AuditLogRecorder` (con `DB::table()->insert()`, no con este modelo, para
 * no disparar eventos y no auditarse a sí misma) y hoy se consulta por SQL.
 *
 * Este modelo existe para poder leer la tabla cómodamente (soporte, tests, una pantalla futura)
 * y para que la exclusión "la auditoría no se audita a sí misma" tenga un nombre de clase.
 *
 * - `user_id`: el DUEÑO del comercio (no quien hizo la acción).
 * - `actor_id` / `actor_name`: quien lo hizo de verdad (un empleado, por ejemplo). Null en la cola.
 * - `auditable_type` / `auditable_id`: la clase completa del modelo cambiado y su id.
 * - `event`: created | updated | deleted | restored | truncated.
 * - `old_values` / `new_values`: JSON como texto (`longText`, ver la migración).
 * - `batch_uuid`: agrupa todo lo que salió de un mismo request o job.
 */
class AuditLog extends Model
{
    /**
     * Sin `updated_at`: la fila no se modifica nunca.
     */
    const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * Scope que piden `fullModel()` y los index de los ABM. Vacío a propósito: la fila no tiene
     * relaciones que cargar (regla del repo para todo modelo nuevo).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    function scopeWithAll($query)
    {
    }
}
