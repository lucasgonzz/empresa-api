<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\MostradorAcceso;
use App\Models\MostradorReporte;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El link con el que el dueño abre un informe del mostrador desde el teléfono (misión
 * asistente-por-whatsapp, §3.7 del plan, 16/9/2026).
 *
 * Lucas pidió que el informe de la mañana llegue por WhatsApp como "resumen + link", y que el link
 * abra el informe sin pedir usuario y contraseña: el dueño está en la calle, con el teléfono en la
 * mano, y no va a tipear una contraseña para leer cuánto vendió ayer.
 *
 * 🔴 EL TOKEN SE GUARDA HASHEADO, NUNCA EN CLARO. Copia el patrón de demo_ingreso_tokens: la fila
 * lleva `token_hash` y el token en claro existe una sola vez, en el instante en que se emite, para
 * viajar al admin. Un dump de la base no abre el informe de nadie.
 *
 * 🔴 Y LA URL LA ARMA ESTE API, PERO NO CON config('app.url'). `app.url` es la URL DE LA API
 * (api-cliente.comerciocity.com), no la del sistema que el dueño abre: un link armado con eso da
 * 404 en el teléfono de 40 dueños a las 8:30 de la mañana. Es exactamente la clase "la URL que un
 * sistema le entrega a otro, armada con APP_URL" de APRENDER_NO_PARCHEAR.md (9/9/2026). Sale de
 * `services.mostrador.spa_url` (variable SPA_URL del `.env`), y si no está cargada se devuelve
 * `url: null` y el admin manda el resumen sin link: se elige fallar visible antes que mandar un
 * link roto.
 *
 * 🔴 PERO EL TOKEN SE EMITE SIEMPRE, TENGA O NO SPA_URL CARGADA, y ese es el punto entero de que
 * `emitir()` devuelva las dos cosas y no solo la URL. `SPA_URL` no la escribe ningún seeder ni
 * instalador, así que HOY NO LA TIENE NINGÚN CLIENTE: si el token viniera atado a la URL, no se
 * emitiría nunca y el repliegue del admin —que arma el link con `client_apis.spa_url`, que sí está
 * cargada en las 105 filas— quedaría siendo código muerto. Lo encontró el revisor de merge del
 * 16/9/2026, con las dos suites en verde: la de este lado fijaba que sin `SPA_URL` no se emite
 * token, y la del admin fabricaba la clave `token` en un `Http::fake`. Cada punta probaba su propia
 * versión de un contrato que no existía.
 */
class MostradorAccesoHelper
{
    /** Días que vale el link. Un acceso a los números del negocio no puede valer para siempre. */
    const DIAS_DE_VIGENCIA = 7;

    /** Largo del token en claro, en caracteres. Str::random() da alfanumérico. */
    const LARGO_TOKEN = 64;

    /**
     * Emite un acceso nuevo para un informe y devuelve el token en claro y la URL con la que se
     * abre. La URL es null si esta instancia no tiene cargada `SPA_URL`; **el token nunca lo es**,
     * porque con él el admin arma el link desde su propio lado.
     *
     * Se emite uno por pedido y no se reusa el anterior a propósito: el token en claro no se puede
     * recuperar de la base, así que "reusar" sería imposible sin guardarlo en claro.
     *
     * @param  \App\Models\MostradorReporte  $reporte
     * @return array{token: string, url: string|null}
     */
    public static function emitir(MostradorReporte $reporte)
    {
        $base = self::base_del_sistema();

        if (is_null($base)) {

            Log::warning('MostradorAccesoHelper: no hay SPA_URL cargada; el token igual se emite y el link lo arma el admin.', [
                'mostrador_reporte_id' => (int) $reporte->id,
            ]);
        }

        $token = Str::random(self::LARGO_TOKEN);

        MostradorAcceso::create([
            'mostrador_reporte_id' => (int) $reporte->id,
            'user_id'              => (int) $reporte->user_id,
            'token_hash'           => MostradorAcceso::hashear($token),
            'expira_at'            => Carbon::now()->addDays(self::DIAS_DE_VIGENCIA),
        ]);

        return [
            'token' => $token,
            'url'   => is_null($base) ? null : $base . '/informe/' . $token,
        ];
    }

    /**
     * El acceso vigente de un token en claro, o null si no existe o venció.
     *
     * Sella `usado_at` la primera vez, y NO invalida el link: el dueño abre el informe, lo cierra y
     * lo vuelve a abrir desde el mismo WhatsApp un rato después. `usado_at` es traza, no un
     * contador de un solo uso.
     *
     * @param  string  $token
     * @return \App\Models\MostradorAcceso|null
     */
    public static function resolver($token)
    {
        $token = trim((string) $token);

        if ($token === '') {

            return null;
        }

        $acceso = MostradorAcceso::where('token_hash', MostradorAcceso::hashear($token))->first();

        if (is_null($acceso) || $acceso->vencio()) {

            return null;
        }

        if (is_null($acceso->usado_at)) {

            $acceso->usado_at = Carbon::now();
            $acceso->save();
        }

        return $acceso;
    }

    /**
     * true si el token existe pero ya venció: es lo que separa un 410 (el link caducó, mandá otro)
     * de un 404 (este link nunca existió).
     *
     * @param  string  $token
     * @return bool
     */
    public static function vencido($token)
    {
        $token = trim((string) $token);

        if ($token === '') {

            return false;
        }

        $acceso = MostradorAcceso::where('token_hash', MostradorAcceso::hashear($token))->first();

        return !is_null($acceso) && $acceso->vencio();
    }

    /**
     * La base de la URL del SISTEMA del cliente (no la de la API), sin barra final, o null.
     *
     * Ver el 🔴 del docblock de la clase: acá NO se cae a `config('app.url')` ni se deduce sacándole
     * el "api-" al dominio. Un repliegue "ingenioso" es peor que no mandar link, porque falla en
     * silencio y el dueño se queda tocando un link roto sin que nadie se entere.
     *
     * @return string|null
     */
    protected static function base_del_sistema()
    {
        $url = trim((string) config('services.mostrador.spa_url'));

        if ($url === '') {

            return null;
        }

        return rtrim($url, '/');
    }
}
