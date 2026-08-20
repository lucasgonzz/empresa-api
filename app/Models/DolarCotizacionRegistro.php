<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila del historial de cotizaciones del dólar del comercio (misión cotizacion-dolar).
 *
 * `user_id` es SIEMPRE el dueño (el historial es del comercio, no de la persona) y `auth_user_id`
 * es quien lo hizo: el dueño mismo, o el empleado con admin_access que operó en su nombre.
 *
 * 🔴 `variacion_porcentaje` en NULL significa NO SE PUDO MEDIR (no había valor anterior, o era 0),
 * y no "no varió". Leer un null de acá como un 0 es exactamente la medición que falla y devuelve un
 * valor tranquilizador que APRENDER_NO_PARCHEAR.md prohíbe.
 *
 * Sin $table declarada: la pluralización de Laravel ya da `dolar_cotizacion_registros`.
 */
class DolarCotizacionRegistro extends Model
{
    protected $guarded = [];

    /**
     * El comercio dueño del registro.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function user() {
        return $this->belongsTo(User::class);
    }

    /**
     * La persona que hizo el cambio (dueño o empleado con admin_access).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function auth_user() {
        return $this->belongsTo(User::class, 'auth_user_id');
    }
}
