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
use Illuminate\Support\Facades\Storage;

/**
 * Le manda al admin un pedido de imagen del agente (misión app-ia-y-memoria, 29/10/2026, contrato
 * C2.1): `POST {admin}/api/inbound/ia-imagenes/openai` con la clave de salida y el `client_uuid` de este
 * cliente, el mismo mecanismo que SupportSyncHelper.
 *
 * Respuestas:
 *   - Primero se verifica que el admin pueda generar por OpenAI, con una consulta sin costo.
 *   - 201 / 200: el admin lo tiene (200 = ya lo tenía: el reintento es idempotente por uuid).
 *   - 404: el admin todavía no tiene la cola (versión vieja). El bloque queda `no_disponible` y NO se
 *     reintenta: reintentar un 404 no lo vuelve 201.
 *   - 401 / 403 / 409 / 422 y topes 429: rechazo definitivo, con el motivo para el dueño en el bloque.
 *   - Lo demás (5xx, timeout, red caída, un 429 ajeno al tope): se lanza y la cola reintenta (3
 *     intentos); si se agotan, failed() lo deja `fallida`.
 *
 * Sin la configuración del admin (`services.admin_api.url` / `client_uuid` / `inbound_key`) no hay a quién
 * mandarlo, o el admin lo rechazaría: `no_disponible`, sin salir a la red, y el log dice qué falta.
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
    const RUTA = '/inbound/ia-imagenes/openai';

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
        $client_uuid = trim((string) config('services.admin_api.client_uuid'));
        $clave       = trim((string) config('services.admin_api.inbound_key'));

        /*
         * Lo que falta DE VERDAD, por nombre (ronda de arreglos de la misión agente-mejoras-diario, F8,
         * 31/10/2026). El aviso decía siempre las tres variables (AVISO_SIN_CONEXION) y el motivo «falta
         * ADMIN_API_URL o ADMIN_API_CLIENT_UUID» aunque faltara una sola, y no miraba la clave de salida: sin
         * ella el pedido salía sin header, el admin daba 401 y la imagen quedaba `fallida`. Desde A5,
         * PedidosDeImagenIaHelper::falta_la_conexion_con_el_admin() también mira la clave y el pedido nace
         * `no_disponible`; acá se mira lo mismo para un pedido que nació pendiente y la clave se sacó después.
         * El texto del log sigue EMPEZANDO con AVISO_SIN_CONEXION (lo buscan los tests de IaApp/10) y suma
         * «(falta …)» con lo que falta.
         */
        $faltan = [];

        if ($admin_url === '') {
            $faltan[] = 'ADMIN_API_URL';
        }

        if ($client_uuid === '') {
            $faltan[] = 'ADMIN_API_CLIENT_UUID';
        }

        if ($clave === '') {
            $faltan[] = 'ADMIN_API_OUTBOUND_KEY';
        }

        if (count($faltan)) {

            /* Que quede claro en el log por qué ninguna imagen de este cliente llega (misión app-ia-imagen-prometida). */
            Log::warning('EnviarPedidoDeImagenAlAdminJob: ' . PedidosDeImagenIaHelper::AVISO_SIN_CONEXION . ' (falta ' . implode(', ', $faltan) . '); el pedido queda no_disponible.', [
                'ia_imagen_pedido_id' => $pedido->id,
                'falta_la_url'        => $admin_url === '',
                'falta_el_uuid'       => $client_uuid === '',
                'falta_la_clave'      => $clave === '',
            ]);

            PedidosDeImagenIaHelper::marcar_no_disponible($pedido, 'falta ' . implode(' y ', $faltan) . ' en este cliente');

            return;
        }

        $headers = [
            'Accept'          => 'application/json',
            'X-Admin-Api-Key' => $clave,
        ];

        $http = Http::withHeaders($headers)
            ->timeout(20)
            ->withOptions(['allow_redirects' => false]);
        $ruta = $admin_url . '/api' . self::RUTA;

        // Verifica el proveedor antes de subir una foto privada o pedir una generación.
        $capacidades = $http->post($ruta, [
            'client_uuid' => $client_uuid,
            'consultar_capacidades' => true,
        ]);
        if (in_array($capacidades->status(), [404, 405, 422], true)
            || $capacidades->json('code') === 'imagenes_no_disponibles'
            || ($capacidades->successful() && $capacidades->json('proveedor') !== 'openai')) {
            PedidosDeImagenIaHelper::marcar_no_disponible($pedido, 'el admin no ofrece generación por OpenAI');
            return;
        }

        $datos = [
            'client_uuid' => $client_uuid,
            'uuid' => $pedido->uuid,
            'prompt' => $pedido->prompt,
            'tamano' => $pedido->tamano,
            'estilo' => $pedido->estilo,
            'ai_message_id' => (int) $pedido->ai_message_id,
            'requiere_openai' => true,
        ];
        if ($capacidades->successful() && $pedido->referencia_path !== null) {
            if ($capacidades->json('acepta_referencia') !== true) {
                PedidosDeImagenIaHelper::marcar_no_disponible($pedido, 'el admin no acepta fotos de referencia');
                return;
            }

            $archivo = Storage::disk('local');
            $binario = $archivo->exists($pedido->referencia_path) ? $archivo->get($pedido->referencia_path) : null;
            if (!PedidosDeImagenIaHelper::referencia_valida($binario)) {
                PedidosDeImagenIaHelper::marcar_fallido($pedido, 'la foto de referencia ya no está disponible',
                    'No encontré la foto de referencia. Adjuntala de nuevo.');
                return;
            }

            $datos['referencia_base64'] = base64_encode($binario);
        }

        $response = $capacidades->successful() ? $http->post($ruta, $datos) : $capacidades;

        $status = $response->status();

        if ($status === 200 || $status === 201) {

            if ($response->json('proveedor') !== 'openai'
                || ($pedido->referencia_path !== null && $response->json('referencia_recibida') !== true)) {
                PedidosDeImagenIaHelper::marcar_no_disponible($pedido, 'el admin no confirmó el alta por OpenAI');
                return;
            }

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

        $motivos_para_el_dueno = [
            'tope_diario' => PedidosDeImagenIaHelper::TOPE_PARA_EL_DUENO,
            'tope_mensual_imagenes' => 'Llegaste al límite mensual de tu plan de imágenes. Podés generar más cuando se renueve el cupo.',
            'sin_plan_imagenes' => 'Tu negocio necesita un plan de imágenes activo para poder generarlas.',
        ];

        if ($status === 503 && $code === 'imagenes_no_disponibles') {
            PedidosDeImagenIaHelper::marcar_no_disponible($pedido, 'la generación por OpenAI no está disponible');
            return;
        }

        if (in_array($status, [401, 403, 409, 422], true) || ($status === 429 && isset($motivos_para_el_dueno[$code]))) {

            Log::warning('EnviarPedidoDeImagenAlAdminJob: el admin rechazó el pedido de imagen.', [
                'ia_imagen_pedido_id' => $pedido->id,
                'http'                => $status,
                'code'                => $code,
                'body'                => mb_substr((string) $response->body(), 0, 300),
            ]);

            PedidosDeImagenIaHelper::marcar_fallido(
                $pedido,
                'el admin rechazó el pedido (HTTP ' . $status . ($code === '' ? '' : ', ' . $code) . ')',
                $motivos_para_el_dueno[$code] ?? null
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
