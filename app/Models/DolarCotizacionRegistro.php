<?php

namespace App\Models;

use Carbon\Carbon;
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

    /**
     * Marca la cotización como cargada A MANO desde el formulario de Configuración y deja la fila
     * de historial correspondiente. Lo llama `UserController@update` cuando el dueño cambió
     * `users.dollar` desde el form de perfil.
     *
     * 🔴 `casa`, `punta` y `valor` quedan en NULL a propósito: desde ese formulario no hay ninguna
     * casa de referencia elegida, y no se sale a la API externa desde un PUT de perfil. Sin
     * referencia no hay comparación posible, así que el modal del login NO va a aparecer hasta que
     * el usuario elija una casa desde el botón de Configuración. Eso es correcto y es explícito:
     * inventarle una referencia acá haría que la próxima variación se mida contra algo que el
     * usuario nunca eligió.
     *
     * Vive acá y no en un helper nuevo porque es una fila de esta tabla más las cinco columnas que
     * la acompañan: un archivo aparte para dos escrituras no agrega nada.
     *
     * @param  \App\Models\User $owner          Fila del dueño, ya con el `dollar` nuevo asignado.
     * @param  mixed            $dolar_anterior El `users.dollar` de antes del guardado.
     * @return \App\Models\DolarCotizacionRegistro
     */
    public static function marcar_manual_desde_formulario($owner, $dolar_anterior)
    {
        $owner->dolar_cotizacion_origen         = 'manual';
        $owner->dolar_cotizacion_casa           = null;
        $owner->dolar_cotizacion_punta          = null;
        $owner->dolar_cotizacion_valor          = null;
        $owner->dolar_cotizacion_actualizada_at = Carbon::now();
        $owner->save();

        /*
         * 🔴 null y no 0 cuando no había valor anterior (o era 0): sin base positiva no hay
         * porcentaje que calcular, y un 0 se leería como "no varió" cuando lo cierto es que no se
         * pudo medir.
         */
        $variacion = null;

        if (!is_null($dolar_anterior) && (float) $dolar_anterior > 0) {
            $variacion = round(((float) $owner->dollar - (float) $dolar_anterior) / (float) $dolar_anterior * 100, 2);
        }

        return self::create([
            'user_id'              => $owner->id,
            /* El gancho solo corre cuando el autenticado ES el dueño, así que son el mismo id. */
            'auth_user_id'         => $owner->id,
            'origen'               => 'manual',
            'casa'                 => null,
            'punta'                => null,
            'valor_dolar'          => round((float) $owner->dollar, 2),
            'valor_cotizacion'     => null,
            'valor_dolar_anterior' => $dolar_anterior,
            'variacion_porcentaje' => $variacion,
            'disparo'              => 'formulario',
        ]);
    }
}
