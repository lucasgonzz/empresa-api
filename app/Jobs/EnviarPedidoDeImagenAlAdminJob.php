<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Models\IaImagenPedido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le manda al admin un pedido de imagen del agente (misión app-ia-y-memoria, 29/10/2026, contrato
 * C2.1): `POST {admin}/api/inbound/ia-imagenes` con la clave de salida y el `client_uuid` de este
 * cliente, el mismo mecanismo que SupportSyncHelper.
 *
 * Respuestas:
 *   - 201 / 200: el admin lo tiene (200 = ya lo tenía: el reintento es idempotente por uuid).
 *   - 404: el admin todavía no tiene la cola (versión vieja). El bloque queda `no_disponible` y NO se
 *     reintenta: reintentar un 404 no lo vuelve 201.
 *   - 401 / 403 (clave, o `cliente_no_coincide`) / 409 `uuid_en_uso` / 422 / 429 `tope_diario`: el admin
 *     rechazó el pedido y reintentar no lo cambia. Queda `fallida`, con un texto humano en el bloque (el
 *     del tope: «Llegaste al tope de imágenes de hoy; probá mañana»).
 *   - Lo demás (5xx, timeout, red caída, un 429 sin `tope_diario`): se lanza y la cola reintenta (3
 *     intentos); si se agotan, failed() lo deja `fallida`.
 *
 * Sin la configuración del admin (`services.admin_api.url` / `client_uuid`) no hay a quién mandarlo:
 * `no_disponible`, sin salir a la red.
 *
 * Se encola DESPUÉS de que el job de respuesta guardó el mensaje con su bloque (ver
 * ResponderMensajeChatIaJob): si corriera antes, la marca de no_disponible no tendría bloque que tocar.
 * `afterCommit` por si alguna vez se despacha adentro de una transacción.
 */
class EnviarPedidoDeImagenAlAdminJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $timeout = 60;

    /** Ruta del admin (contrato C2.1), relativa a su `/api`. */
    const RUTA = '/inbound/ia-imagenes';

    /** @var int */
    protected $ia_imagen_pedido_id;

    /**
     * @param  int  $ia_imagen_pedido_id
     */
    public function __construct($ia_imagen_pedido_id)
    {
        $this->ia_imagen_pedido_id = (int) $ia_imagen_pedido_id;

        /*
         * Después del commit, si alguna vez se despacha adentro de una transacción. Va acá y no como
         * propiedad: `$afterCommit` ya la declara el trait Queueable y redeclararla con otro valor es un
         * error fatal de PHP.
         */
        $this->afterCommit();
    }

    /**
     * Espera entre reintentos, en segundos.
     *
     * @return array<int, int>
     */
    public function backoff()
    {
        return [30, 120];
    }

    public function handle()
    {
        $pedido = IaImagenPedido::find($this->ia_imagen_pedido_id);

        if (is_null($pedido) || $pedido->estado !== IaImagenPedido::ESTADO_PENDIENTE || !is_null($pedido->enviado_al_admin_en)) {

            return;
        }

        $admin_url   = self::base_del_admin();
        $client_uuid = (string) config('services.admin_api.client_uuid');

        if ($admin_url === '' || $client_uuid === '') {

            PedidosDeImagenIaHelper::marcar_no_disponible($pedido, 'falta ADMIN_API_URL o ADMIN_API_CLIENT_UUID en este cliente');

            return;
        }

        $headers = ['Accept' => 'application/json'];
        $clave   = (string) config('services.admin_api.inbound_key');

        if ($clave !== '') {
            $headers['X-Admin-Api-Key'] = $clave;
        }

        $response = Http::withHeaders($headers)
            ->timeout(20)
            ->withOptions(['allow_redirects' => false])
            ->post($admin_url . '/api' . self::RUTA, [
                'client_uuid'   => $client_uuid,
                'uuid'          => $pedido->uuid,
                'prompt'        => $pedido->prompt,
                'tamano'        => $pedido->tamano,
                'estilo'        => $pedido->estilo,
                'ai_message_id' => (int) $pedido->ai_message_id,
            ]);

        $status = $response->status();

        if ($status === 200 || $status === 201) {

            $pedido->enviado_al_admin_en = now();
            $pedido->save();

            return;
        }

        if ($status === 404) {

            Log::info('EnviarPedidoDeImagenAlAdminJob: el admin no tiene la cola de imágenes (404); queda no_disponible.', [
                'ia_imagen_pedido_id' => $pedido->id,
            ]);

            PedidosDeImagenIaHelper::marcar_no_disponible($pedido, 'el admin no tiene la cola de imágenes (HTTP 404)');

            return;
        }

        $code = is_array($response->json()) ? (string) ($response->json()['code'] ?? '') : '';

        $es_el_tope = $status === 429 && $code === 'tope_diario';

        if (in_array($status, [401, 403, 409, 422], true) || $es_el_tope) {

            Log::warning('EnviarPedidoDeImagenAlAdminJob: el admin rechazó el pedido de imagen.', [
                'ia_imagen_pedido_id' => $pedido->id,
                'http'                => $status,
                'code'                => $code,
                'body'                => mb_substr((string) $response->body(), 0, 300),
            ]);

            PedidosDeImagenIaHelper::marcar_fallido(
                $pedido,
                'el admin rechazó el pedido (HTTP ' . $status . ($code === '' ? '' : ', ' . $code) . ')',
                $es_el_tope ? PedidosDeImagenIaHelper::TOPE_PARA_EL_DUENO : null
            );

            return;
        }

        throw new \RuntimeException('El admin contestó HTTP ' . $status . ' al pedido de imagen ' . $pedido->uuid);
    }

    /**
     * La base del admin SIN el `/api` del final: hay clientes con ADMIN_API_URL cargada con `/api` y otros
     * sin él (el mismo criterio que CentralProductFactsClient), y pegarle `/api/inbound/…` a una que ya lo
     * trae daba `/api/api/…`, un 404 que dejaba el pedido como `no_disponible`. '' si no está cargada.
     *
     * @return string
     */
    public static function base_del_admin(): string
    {
        $url = rtrim(trim((string) config('services.admin_api.url')), '/');

        if (substr($url, -4) === '/api') {
            $url = substr($url, 0, -4);
        }

        return $url;
    }

    /**
     * Se agotaron los reintentos: el pedido queda fallido y el dueño lo ve en el bloque.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::warning('EnviarPedidoDeImagenAlAdminJob: no se pudo mandar el pedido al admin', [
            'ia_imagen_pedido_id' => $this->ia_imagen_pedido_id,
            'error'               => $exception->getMessage(),
        ]);

        $pedido = IaImagenPedido::find($this->ia_imagen_pedido_id);

        if (!is_null($pedido) && $pedido->estado === IaImagenPedido::ESTADO_PENDIENTE && is_null($pedido->enviado_al_admin_en)) {
            PedidosDeImagenIaHelper::marcar_fallido($pedido, 'no se pudo mandar el pedido al admin: ' . $exception->getMessage());
        }
    }
}
