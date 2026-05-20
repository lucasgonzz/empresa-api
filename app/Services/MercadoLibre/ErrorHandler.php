<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Notificaciones globales (broadcast) por errores de integración con Mercado Libre.
 */
class ErrorHandler
{
    /**
     * Botones por defecto del modal de notificación.
     *
     * @return array<int, array<string, string>>
     */
    public static function default_functions_to_execute(): array
    {
        return [
            [
                'btn_text'    => 'Entendido',
                'btn_variant' => 'primary',
            ],
        ];
    }

    /**
     * Envía GlobalNotification al usuario del tenant.
     *
     * @param int $user_id Usuario dueño de la operación.
     * @param string $message_text Título breve del modal.
     * @param array<int, array<string, mixed>> $info_to_show Bloques con title y parrafos.
     * @param string $color_variant Variante bootstrap (danger, warning, success, ...).
     * @param array<int, array<string, string>>|null $functions_to_execute Botones del modal.
     * @return void
     */
    public static function notify(
        int $user_id,
        string $message_text,
        array $info_to_show = [],
        string $color_variant = 'danger',
        ?array $functions_to_execute = null
    ): void {
        $user = User::find($user_id);
        if (!$user) {
            Log::warning('ErrorHandler::notify sin usuario id='.$user_id);

            return;
        }

        if ($functions_to_execute === null) {
            $functions_to_execute = self::default_functions_to_execute();
        }

        $user->notify(new GlobalNotification([
            'message_text'          => $message_text,
            'color_variant'         => $color_variant,
            'functions_to_execute'  => $functions_to_execute,
            'info_to_show'          => $info_to_show,
            'owner_id'              => $user->id,
            'is_only_for_auth_user' => false,
        ]));
    }

    /**
     * Notifica error parseado desde respuesta HTTP de la API de Mercado Libre.
     *
     * @param Response|object $response Respuesta de Http:: client.
     * @param int|null $user_id Usuario a notificar; si es null usa UserHelper::userId().
     * @param string $message_text Título del modal.
     * @return void
     */
    public static function send_notification($response, ?int $user_id = null, string $message_text = 'Error al sincronizar con Mercado Libre'): void
    {
        $user_id = self::resolve_user_id($user_id);
        if (!$user_id) {
            return;
        }

        $body = method_exists($response, 'body') ? $response->body() : (string) $response;
        $errores = self::parse_meli_error($body);
        $info_to_show = [];

        foreach ($errores as $error) {
            $info_to_show[] = [
                'title'    => $error['titulo'],
                'parrafos' => [$error['mensaje']],
            ];
        }

        self::notify($user_id, $message_text, $info_to_show, 'danger');
    }

    /**
     * Notifica una excepción genérica (evita duplicar si ya vino de error API ML).
     *
     * @param int|null $user_id Usuario a notificar.
     * @param \Throwable $exception Excepción capturada.
     * @param string $message_text Título del modal.
     * @param bool $skip_if_meli_api Si true, no notifica cuando el mensaje es de make_request.
     * @return void
     */
    public static function notify_exception(
        ?int $user_id,
        \Throwable $exception,
        string $message_text = 'Error en Mercado Libre',
        bool $skip_if_meli_api = true
    ): void {
        $user_id = self::resolve_user_id($user_id);
        if (!$user_id) {
            return;
        }

        if ($skip_if_meli_api && str_contains($exception->getMessage(), 'Mercado Libre API error:')) {
            return;
        }

        $detail = $exception->getMessage();
        if (str_contains($detail, 'cURL error 60') || str_contains($detail, 'SSL certificate problem')) {
            $detail = 'Problema de certificados SSL en el servidor (común en WAMP local). '
                .'Agregá MERCADO_LIBRE_GUZZLE_VERIFY_SSL=false en .env o configurá MERCADO_LIBRE_GUZZLE_CA_BUNDLE con cacert.pem. '
                .'Detalle técnico: '.$detail;
        }
        if (strlen($detail) > 2000) {
            $detail = substr($detail, 0, 2000).'…';
        }

        self::notify($user_id, $message_text, [
            [
                'title'    => 'Detalle',
                'parrafos' => [$detail],
            ],
        ], 'danger');
    }

    /**
     * Notifica un mensaje de error simple sin payload de API.
     *
     * @param int|null $user_id Usuario a notificar.
     * @param string $message_text Título del modal.
     * @param string $detail Texto descriptivo opcional.
     * @return void
     */
    public static function notify_plain_message(
        ?int $user_id,
        string $message_text,
        string $detail = ''
    ): void {
        $user_id = self::resolve_user_id($user_id);
        if (!$user_id) {
            return;
        }

        $info_to_show = [];
        if ($detail !== '') {
            $info_to_show[] = [
                'title'    => 'Detalle',
                'parrafos' => [$detail],
            ];
        }

        self::notify($user_id, $message_text, $info_to_show, 'danger');
    }

    /**
     * Resuelve user_id efectivo para notificar.
     *
     * @param int|null $user_id
     * @return int|null
     */
    protected static function resolve_user_id(?int $user_id): ?int
    {
        if ($user_id) {
            return (int) $user_id;
        }

        $from_helper = UserHelper::userId();

        return $from_helper ? (int) $from_helper : null;
    }

    /**
     * Procesa un error devuelto por la API de MercadoLibre y devuelve
     * un array de notificaciones con título y mensaje traducido.
     *
     * @param string|array $error_body JSON o array del error de ML
     * @return array<int, array<string, string>>
     */
    public static function parse_meli_error($error_body): array
    {
        $errores = [];

        if (is_string($error_body)) {
            $error_body = json_decode($error_body, true);
        }

        if (empty($error_body) || !is_array($error_body)) {
            return [[
                'titulo'  => 'Error desconocido',
                'mensaje' => 'No se pudo interpretar el error devuelto por Mercado Libre.',
            ]];
        }

        if (!empty($error_body['message']) && empty($error_body['cause'])) {
            $errores[] = [
                'titulo'  => ucfirst($error_body['error'] ?? 'Error'),
                'mensaje' => self::translate_meli_message($error_body['message']),
            ];

            return $errores;
        }

        if (!empty($error_body['cause']) && is_array($error_body['cause'])) {
            foreach ($error_body['cause'] as $c) {
                $titulo = ucfirst($c['type'] ?? 'Error');
                $mensaje = self::translate_meli_message($c['message'] ?? 'Error desconocido');

                if (!empty($c['code'])) {
                    $titulo .= " ({$c['code']})";
                }

                $errores[] = [
                    'titulo'  => $titulo,
                    'mensaje' => $mensaje,
                ];
            }
        }

        return $errores;
    }

    /**
     * Traduce o adapta al español mensajes comunes de errores de Mercado Libre.
     *
     * @param string $mensaje Mensaje original de ML.
     * @return string
     */
    public static function translate_meli_message(string $mensaje): string
    {
        $traducciones = [
            'does not support titles greater than' => 'no permite títulos de más de',
            'are required for category'           => 'son obligatorios para la categoría',
            'User has not mode me1'               => 'El usuario no tiene habilitado el modo Mercado Envíos 1',
            'Free shipping costs exceeds sale'    => 'El costo de envío gratis supera el precio de venta',
            'Validation error'                    => 'Error de validación',
            'missing_required'                    => 'faltan atributos obligatorios',
            'not authorized'                      => 'No autorizado',
            'forbidden'                           => 'Acceso denegado',
            'invalid'                             => 'inválido',
        ];

        foreach ($traducciones as $ing => $esp) {
            if (stripos($mensaje, $ing) !== false) {
                return str_ireplace($ing, $esp, $mensaje);
            }
        }

        return $mensaje;
    }
}
