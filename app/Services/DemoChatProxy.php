<?php

namespace App\Services;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** El navegador habla con su sesión; la credencial del canal queda siempre en el servidor. */
class DemoChatProxy
{
    public function solicitar($metodo, $sufijo = '', array $datos = [])
    {
        return $this->enviar($metodo, $sufijo, $datos, 'demo-chat');
    }

    public function solicitar_prueba($metodo, $sufijo = '', array $datos = [])
    {
        return $this->enviar($metodo, $sufijo, $datos, 'prueba-chat');
    }

    private function enviar($metodo, $sufijo, array $datos, $canal)
    {
        $config = DemoTrackingConfigHelper::actual();
        if (!$config || !$config->eventos_token) {
            return response()->json(['disponible' => false]);
        }

        $es_prueba = $canal === 'prueba-chat';
        $url = $es_prueba ? self::url_prueba($sufijo) : self::url($config->eventos_url, $sufijo);
        if (!$url) {
            return response()->json(['disponible' => false]);
        }

        $headers = [
            'Accept' => 'application/json',
            'X-Demo-Eventos-Key' => $config->eventos_token,
        ];
        $clave_instancia = $es_prueba ? trim((string) config('services.admin_api.inbound_key')) : '';
        if ($es_prueba) {
            if ($clave_instancia === '') {
                return response()->json(['disponible' => false]);
            }
            $headers['X-Admin-Api-Key'] = $clave_instancia;
        }

        try {
            $http = Http::withHeaders($headers)->timeout(8);
            if ($es_prueba) {
                $http->withoutRedirecting();
            }
            $respuesta = $metodo === 'GET' ? $http->get($url) : $http->post($url, $datos);

            // Una actualización por etapas conserva el recorrido de la API anterior.
            if (in_array($respuesta->status(), [404, 405], true)) {
                return response()->json(['disponible' => false]);
            }
            if (!$respuesta->successful()) {
                $codigo = in_array($respuesta->status(), [409, 422, 429], true) ? $respuesta->status() : 503;
                return response()->json(['message' => 'No pudimos conectar con el asistente. Volvé a intentar.'], $codigo);
            }

            $cuerpo = $respuesta->json();
            if (!is_array($cuerpo) || !isset($cuerpo['disponible'])) {
                return response()->json(['message' => 'El asistente no respondió correctamente.'], 503);
            }
            return response()->json($cuerpo, $respuesta->status());
        } catch (\Throwable $e) {
            $error = DemoTrackingConfigHelper::ocultar_token($e->getMessage(), $config->eventos_token);
            Log::warning('DemoChatProxy: no se pudo consultar al asistente: ' . DemoTrackingConfigHelper::ocultar_token($error, $clave_instancia));
            return response()->json(['message' => 'No pudimos conectar con el asistente. Volvé a intentar.'], 503);
        }
    }

    private static function url_prueba($sufijo)
    {
        // La clave de la instancia solo se envía al Admin del entorno, nunca a una URL del setup.
        $base = rtrim(trim((string) config('services.admin_api.url')), '/');
        if ($base === '') {
            return null;
        }
        if (substr($base, -4) !== '/api') {
            $base .= '/api';
        }
        return $base . '/prueba-chat' . $sufijo;
    }

    public static function url($eventos_url, $sufijo = '')
    {
        $url = DemoMediaUrlsFetcher::url_del_endpoint($eventos_url);
        if (!$url) {
            return null;
        }
        // Solo el último segmento: ni el host ni una carpeta homónima se reescriben.
        $corte = strcspn($url, '?#');
        $camino = substr($url, 0, $corte);
        $resto = substr($url, $corte);
        if (!preg_match('~/demo-media-urls/?$~', $camino)) {
            return null;
        }
        return preg_replace('~/demo-media-urls/?$~', '/demo-chat' . $sufijo, $camino) . $resto;
    }
}
