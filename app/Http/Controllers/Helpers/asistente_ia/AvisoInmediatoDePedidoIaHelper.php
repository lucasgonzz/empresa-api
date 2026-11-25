<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EL EMPUJE INMEDIATO DEL AVISO DE PEDIDO (misión whatsapp-aviso-pedido, 25/11/2026).
 *
 * tienda-api, apenas crea un pedido, le pega a empresa: `POST api/tienda/pedidos/{id}/aviso-al-asistente`
 * (TiendaAvisoDePedidoController). Esta clase decide qué hacer y, si corresponde, DESPUÉS de responder le avisa al
 * admin (`POST {ADMIN_API_URL}/api/inbound/asistente/pedido-nuevo`, con la clave de salida) para que adelante su
 * consulta de recordatorios para este cliente. El admin sigue mandando por el mismo camino de siempre: el ítem
 * `pedido_tienda` de `recordatorios-pendientes`, su reclamo y su sello. Por eso hay UN solo mensaje por pedido
 * aunque coincida con la consulta de 5 minutos del admin, que queda sólo como RESPALDO (empuje caído, punta
 * vieja, dueño con un turno en curso). No se agrega ninguna consulta periódica.
 *
 * Motivos (siempre 202 `{motivo}`), en este orden:
 *   sin_agentes_ia · apagado_por_el_dueno · va_en_el_resumen_por_horas · canal_no_conectado · sin_admin_configurado ·
 *   ya_avisado · se_avisa_al_admin.
 * Un pedido inexistente, de otro dueño o de más de 30 minutos es un 404 (no se loguea: no hay dueño que atribuir).
 *
 * 🔴 `canal_no_conectado` depende de que el admin haya consultado los recordatorios en las últimas horas
 * (RecordatoriosDelAsistenteHelper::admin_avisa). Si algún día se apagara la consulta periódica del admin, el empuje
 * diría siempre `canal_no_conectado`.
 *
 * 🔴 El endpoint es público y sin clave (tienda-api no tiene secreto compartido con empresa). Es seguro porque sólo
 * adelanta un aviso que igual saldría: el pedido es del dueño de esta instancia, tiene menos de 30 minutos, se
 * empuja una sola vez por pedido y la ruta lleva `throttle:60,1`.
 *
 * 🔴 Logs: `Aviso de pedido al asistente` con SÓLO `order_id`, `owner_id`, `motivo` y, si hubo respuesta, `http_status`.
 * Nunca el comprador, el importe, el teléfono ni la URL.
 *
 * Nunca lanza hacia el pedido de la tienda.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class AvisoInmediatoDePedidoIaHelper
{
    /** Mensaje fijo de los logs de esta función. */
    const MENSAJE_DEL_LOG = 'Aviso de pedido al asistente';

    /** Pasados estos minutos desde que se creó el pedido, ya no se empuja (404). */
    const MINUTOS_DE_VIDA_DEL_PEDIDO = 30;

    /** Prefijo de la marca «este pedido ya se empujó» (una hora). */
    const CLAVE_EMPUJADO = 'asistente_ia:pedido_empujado:';

    /** Segundos de espera para conectar con el admin. */
    const SEGUNDOS_DE_CONEXION = 2;

    /** Segundos totales que se espera la respuesta del admin. */
    const SEGUNDOS_TOTALES = 5;

    /** Ruta del admin que adelanta el aviso. */
    const RUTA_DEL_ADMIN = '/api/inbound/asistente/pedido-nuevo';

    /**
     * Resuelve el empuje de un pedido.
     *
     * @param  mixed  $order_id  Id del pedido que llegó por la ruta.
     * @return array{status: int, motivo: string|null}  202 con el motivo, o 404 (motivo null).
     */
    public static function avisar($order_id): array
    {
        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno) || !ctype_digit((string) $order_id)) {

            return ['status' => 404, 'motivo' => null];
        }

        $pedido = Order::where('id', (int) $order_id)->where('user_id', (int) $dueno->id)->first();

        $limite = Carbon::now()->subMinutes(self::MINUTOS_DE_VIDA_DEL_PEDIDO);

        if (is_null($pedido) || is_null($pedido->created_at) || Carbon::parse($pedido->created_at)->lt($limite)) {

            return ['status' => 404, 'motivo' => null];
        }

        $motivo = self::motivo($dueno, $pedido);

        self::registrar($pedido, $dueno, $motivo);

        if ($motivo === 'se_avisa_al_admin') {

            /* Que el pedido que disparó el empuje entre en pendientes() aunque el cursor todavía no exista. */
            AvisosDelDuenoIaHelper::cursor_para_el_pedido($dueno, (int) $pedido->id);

            /* Después de responderle a la tienda: un admin lento no demora a nadie. */
            $pedido_id = (int) $pedido->id;
            $dueno_id = (int) $dueno->id;

            /*
             * Corre UNA sola vez. En producción cada request vive en su proceso y no hace falta, pero con un
             * worker que atiende varios requests (Octane, o los tests con una sola aplicación) los callbacks de
             * `terminating` no se vacían y este volvería a correr en el request siguiente.
             */
            $ya_corrio = false;

            app()->terminating(function () use ($pedido_id, $dueno_id, &$ya_corrio) {

                if ($ya_corrio) {
                    return;
                }

                $ya_corrio = true;

                self::llamar_al_admin($pedido_id, $dueno_id);
            });
        }

        return ['status' => 202, 'motivo' => $motivo];
    }

    /**
     * El motivo por el que se avisa o no se avisa (ver el orden en el docblock de la clase). Reserva el empuje del
     * pedido (una sola vez por hora) sólo cuando devuelve `se_avisa_al_admin`.
     *
     * @param  \App\Models\User  $dueno
     * @param  \App\Models\Order  $pedido
     * @return string
     */
    protected static function motivo($dueno, Order $pedido): string
    {
        if (!AsistenteCanalHelper::tiene_extension($dueno)) {

            return 'sin_agentes_ia';
        }

        /* El modo efectivo ya resuelve la casilla, lo elegido por chat y la extensión. */
        $modo = AvisosDelDuenoIaHelper::preferencias($dueno)['pedidos'];

        if ($modo === AvisosDelDuenoIaHelper::PEDIDOS_APAGADO) {

            return 'apagado_por_el_dueno';
        }

        if ($modo === AvisosDelDuenoIaHelper::PEDIDOS_HORAS) {

            return 'va_en_el_resumen_por_horas';
        }

        if (!RecordatoriosDelAsistenteHelper::admin_avisa((int) $dueno->id)) {

            return 'canal_no_conectado';
        }

        if (trim((string) config('services.admin_api.url')) === '') {

            return 'sin_admin_configurado';
        }

        if (AvisosDelDuenoIaHelper::pedido_ya_sellado($dueno, $pedido)) {

            return 'ya_avisado';
        }

        if (!Cache::add(self::CLAVE_EMPUJADO . (int) $pedido->id, 1, Carbon::now()->addHour())) {

            return 'ya_avisado';
        }

        return 'se_avisa_al_admin';
    }

    /**
     * Le avisa al admin que adelante la consulta de este cliente. Corre después de la respuesta: nunca lanza y
     * deja el log `admin_respondio` con el estado HTTP (sin él si no hubo respuesta).
     *
     * @param  int  $pedido_id
     * @param  int  $dueno_id
     * @return void
     */
    protected static function llamar_al_admin($pedido_id, $dueno_id)
    {
        $contexto = ['order_id' => (int) $pedido_id, 'owner_id' => (int) $dueno_id, 'motivo' => 'admin_respondio'];

        try {

            $url = rtrim((string) config('services.admin_api.url'), '/') . self::RUTA_DEL_ADMIN;

            $respuesta = Http::acceptJson()
                ->withHeaders(['X-Admin-Api-Key' => config('services.admin_api.inbound_key')])
                ->withOptions(['connect_timeout' => self::SEGUNDOS_DE_CONEXION])
                ->timeout(self::SEGUNDOS_TOTALES)
                ->post($url, ['order_id' => (int) $pedido_id]);

            $contexto['http_status'] = (int) $respuesta->status();

        } catch (\Throwable $e) {

            /* Timeout o conexión caída: queda el log sin estado; el aviso sale por la consulta de respaldo del admin. */
        }

        self::loguear($contexto);
    }

    /**
     * Deja el log de un motivo.
     *
     * @param  \App\Models\Order  $pedido
     * @param  \App\Models\User  $dueno
     * @param  string  $motivo
     * @return void
     */
    protected static function registrar(Order $pedido, $dueno, $motivo)
    {
        self::loguear(['order_id' => (int) $pedido->id, 'owner_id' => (int) $dueno->id, 'motivo' => $motivo]);
    }

    /**
     * Escribe el log sin dejar que un logger roto convierta el aviso en un error.
     *
     * @param  array  $contexto  Sólo order_id, owner_id, motivo y opcionalmente http_status.
     * @return void
     */
    protected static function loguear(array $contexto)
    {
        try {
            Log::info(self::MENSAJE_DEL_LOG, $contexto);
        } catch (\Throwable $e) {
            // Un logger que falla no puede romper el aviso.
        }
    }
}
