<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\MeliAttribute;
use App\Models\MeliAttributeTag;
use App\Models\MeliAttributeValue;
use App\Models\MeliCategory;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ErrorHandler 
{

    static function send_notification($error) {
        $errores = Self::parse_meli_error($error->body());

        $user = User::find(UserHelper::userId());

        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                'btn_variant'   => 'primary',
            ],
        ];

        $info_to_show = [];

        foreach ($errores as $error) {
            $info_to_show[] = [
                'title' => $error['titulo'],
                'value' => $error['mensaje'],
            ];
        }

        $user->notify(new GlobalNotification([
            'message_text'              => 'Error al sincronizar con Mercado Libre',
            'color_variant'             => 'danger',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => $info_to_show,
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
            ])
        );
    }

    /**
     * Procesa un error devuelto por la API de MercadoLibre y devuelve
     * un array de notificaciones con título y mensaje traducido.
     *
     * @param string|array $error_body JSON o array del error de ML
     * @return array [ ['titulo' => string, 'mensaje' => string], ... ]
     */
    static function parse_meli_error($error_body): array
    {
        $errores = [];

        // Decodificar si viene como string
        if (is_string($error_body)) {
            $error_body = json_decode($error_body, true);
        }

        // Si no hay data válida
        if (empty($error_body) || !is_array($error_body)) {
            return [[
                'titulo' => 'Error desconocido',
                'mensaje' => 'No se pudo interpretar el error devuelto por MercadoLibre.'
            ]];
        }

        // Si viene mensaje general sin cause[]
        if (!empty($error_body['message']) && empty($error_body['cause'])) {
            $errores[] = [
                'titulo' => ucfirst($error_body['error'] ?? 'Error'),
                'mensaje' => Self::translate_meli_message($error_body['message'])
            ];
            return $errores;
        }

        // Procesar cada causa
        if (!empty($error_body['cause']) && is_array($error_body['cause'])) {
            foreach ($error_body['cause'] as $c) {
                $titulo = ucfirst($c['type'] ?? 'Error');

                // Traducción automática del mensaje
                $mensaje = Self::translate_meli_message($c['message'] ?? 'Error desconocido');

                // Opcional: agregar el código o departamento al título
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
     * Traduce o adapta al español mensajes comunes de errores de MercadoLibre.
     * Se pueden agregar más según los casos que vayas recibiendo.
     */
    static function translate_meli_message(string $mensaje): string
    {
        $traducciones = [
            // Validaciones comunes
            'does not support titles greater than' => 'no permite títulos de más de',
            'are required for category' => 'son obligatorios para la categoría',
            'User has not mode me1' => 'El usuario no tiene habilitado el modo Mercado Envíos 1',
            'Free shipping costs exceeds sale' => 'El costo de envío gratis supera el precio de venta',
            'Validation error' => 'Error de validación',
            'missing_required' => 'faltan atributos obligatorios',

            // General
            'not authorized' => 'No autorizado',
            'forbidden' => 'Acceso denegado',
            'invalid' => 'inválido',
        ];

        foreach ($traducciones as $ing => $esp) {
            if (stripos($mensaje, $ing) !== false) {
                return str_ireplace($ing, $esp, $mensaje);
            }
        }

        return $mensaje;
    }

}
