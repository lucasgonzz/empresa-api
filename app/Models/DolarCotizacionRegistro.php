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
     * 🔴 El origen pasa a 'manual', pero la REFERENCIA que el comercio ya tenía elegida
     * (`casa`, `punta`, `valor`) SE CONSERVA. No se sale a la API externa desde un PUT de perfil,
     * así que no hay forma de refrescar el valor de referencia acá — pero pisarlo con null era
     * peor: apagaba los avisos para siempre y en silencio.
     *
     * El caso real: el comercio elige Blue/venta desde el modal, y semanas después entra a
     * Configuración a corregir el valor a mano (que es como se hacía antes de esta funcionalidad).
     * Con la referencia en null, `comparar()` devuelve null, `debe_avisar` da false para siempre y
     * el modal no vuelve a aparecer nunca — mientras el checkbox "Avisarme cuando cambie" le
     * sigue apareciendo tildado. Lucas pidió explícitamente que el aviso funcione también cuando
     * la cotización se cargó a mano, así que apagarlo en silencio contradice el pedido.
     *
     * Conservándola, la próxima medición dice cuánto se movió esa casa desde la última vez que el
     * usuario la eligió, que es una afirmación cierta y verificable. Y si nunca eligió una casa,
     * las tres quedan en null igual que antes: ahí sí no hay nada contra qué medir, y la salida es
     * el botón de Configuración (que ahora sí funciona, ver `sembrar_defaults_de_referencia()`).
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
            /* La referencia que se conserva es la que queda vigente: el historial la refleja. */
            'casa'                 => $owner->dolar_cotizacion_casa,
            'punta'                => $owner->dolar_cotizacion_punta,
            'valor_dolar'          => round((float) $owner->dollar, 2),
            'valor_cotizacion'     => $owner->dolar_cotizacion_valor,
            'valor_dolar_anterior' => $dolar_anterior,
            'variacion_porcentaje' => $variacion,
            'disparo'              => 'formulario',
        ]);
    }
}
