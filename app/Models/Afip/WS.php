<?php

namespace App\Models\Afip;

use Illuminate\Support\Facades\Log;
use SoapVar;

/**
 * WS (WebService).
 *
 * Clase base para WebServices SOAP.
 *
 * @author Juan Pablo Candioti (@JPCandioti)
 */
abstract class WS
{
    /**
     * @var string URL del WebService.
     */
    protected $ws_url;

    /**
     * @var string URL del WSDL del WebService.
     */
    protected $wsdl_url;

    /**
     * @var string Ubicación dónde se almacena el caché del WSDL del WebService.
     */
    protected $wsdl_cache_file;

    /**
     * @var array Campo options del SoapClient del WebService.
     */
    protected $soap_options;

    /**
     * @var \SoapClient|null Instancia del cliente SOAP ya configurado.
     */
    protected $soap_client;

    /**
     * @var bool
     */
    protected $for_wsfex = false;

    /**
     * Cache TTL del WSDL local (segundos). Default 7 días.
     *
     * @var int
     */
    protected $wsdl_cache_ttl_seconds = 604800;

    /**
     * Timeout de descarga del WSDL (segundos).
     *
     * @var int
     */
    protected $wsdl_download_timeout_seconds = 15;

    /**
     * Reintentos al descargar el WSDL.
     *
     * @var int
     */
    protected $wsdl_download_retries = 2;

    /**
     * __construct
     *
     * Valores aceptados en $config:
     * - ws_url
     * - wsdl_cache_file
     * - soap_options
     * - wsdl_cache_ttl_seconds
     * - wsdl_download_timeout_seconds
     * - wsdl_download_retries
     */
    public function __construct(array $config = array())
    {
        $this->ws_url           = isset($config['ws_url']) ? $config['ws_url'] : '';
        $this->wsdl_url         = isset($config['ws_url']) ? $config['ws_url'] . '?wsdl' : null;
        $this->wsdl_cache_file  = isset($config['wsdl_cache_file']) ? $config['wsdl_cache_file'] : null;
        $this->soap_client      = null;

        $this->for_wsfex        = isset($config['for_wsfex']) ? $config['for_wsfex'] : false;

        if (isset($config['wsdl_cache_ttl_seconds'])) {
            $this->wsdl_cache_ttl_seconds = (int) $config['wsdl_cache_ttl_seconds'];
        }
        if (isset($config['wsdl_download_timeout_seconds'])) {
            $this->wsdl_download_timeout_seconds = (int) $config['wsdl_download_timeout_seconds'];
        }
        if (isset($config['wsdl_download_retries'])) {
            $this->wsdl_download_retries = (int) $config['wsdl_download_retries'];
        }

        // OJO: tenían timeouts en 1 segundo -> muy propenso a fallas intermitentes.
        $this->soap_options = array(
            'soap_version' => SOAP_1_1,
            'cache_wsdl'   => WSDL_CACHE_NONE,
            'trace'        => 1,
            'encoding'     => 'ISO-8859-1',
            'exceptions'   => 1,
            'connection_timeout' => 15,
        );

        if (isset($config['soap_options']) && is_array($config['soap_options'])) {
            $this->soap_options += $config['soap_options'];
        }
    }

    public function getSoapOptions()
    {
        return $this->soap_options;
    }

    public function getWsUrl()
    {
        return $this->ws_url;
    }

    public function getWsdlCacheFile()
    {
        return $this->wsdl_cache_file;
    }

    /**
     * Decide qué WSDL usar (local si existe / se puede descargar; remoto como fallback).
     *
     * @return string
     */
    protected function get_wsdl_to_use()
    {
        // Si no hay cache file configurado, directo a URL
        if (empty($this->wsdl_cache_file)) {
            return $this->wsdl_url;
        }

        // Si existe y no venció, usarlo
        if ($this->is_wsdl_cache_valid()) {
            return $this->wsdl_cache_file;
        }

        // Si está vencido o no existe, intentar actualizar/crear
        $updated = $this->update_wsdl_cache_file();

        // Si pudo descargar, usar local
        if ($updated && file_exists($this->wsdl_cache_file)) {
            return $this->wsdl_cache_file;
        }

        // Si no pudo descargar pero existe un archivo previo, usar stale
        if (file_exists($this->wsdl_cache_file)) {
            Log::warning('AFIP WSDL: no se pudo actualizar, usando WSDL local existente (stale): ' . $this->wsdl_cache_file);
            return $this->wsdl_cache_file;
        }

        // Último recurso: URL remota
        return $this->wsdl_url;
    }

    /**
     * Valida si el cache WSDL es usable según TTL.
     *
     * @return bool
     */
    protected function is_wsdl_cache_valid()
    {
        if (empty($this->wsdl_cache_file) || !file_exists($this->wsdl_cache_file)) {
            return false;
        }

        // TTL <= 0 => no vence nunca
        if ((int) $this->wsdl_cache_ttl_seconds <= 0) {
            return true;
        }

        $mtime = @filemtime($this->wsdl_cache_file);
        if (!$mtime) {
            return false;
        }

        return (time() - $mtime) <= (int) $this->wsdl_cache_ttl_seconds;
    }

    /**
     * Descarga el WSDL remoto y lo guarda localmente de forma más segura.
     *
     * @return bool
     */
    protected function update_wsdl_cache_file()
    {
        if (empty($this->wsdl_cache_file) || empty($this->wsdl_url)) {
            return false;
        }

        $dir = dirname($this->wsdl_cache_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $last_error = null;
        $last_http_code = null;

        for ($i = 0; $i <= (int) $this->wsdl_download_retries; $i++) {
            $download = $this->download_wsdl_xml();

            $last_http_code = $download['http_code'];
            $wsdl_xml = $download['body'];

            if (!$download['ok'] || empty($wsdl_xml)) {
                $last_error = $download['error'] ?? 'WSDL vacío';
                usleep(200000); // 200ms
                continue;
            }

            // Validación mínima
            if (stripos($wsdl_xml, '<definitions') === false && stripos($wsdl_xml, ':definitions') === false) {
                $last_error = 'Respuesta no parece WSDL (no contiene definitions)';
                usleep(200000);
                continue;
            }

            $tmp_file = $this->wsdl_cache_file . '.tmp';
            $bytes = @file_put_contents($tmp_file, $wsdl_xml, LOCK_EX);

            if ($bytes === false) {
                $last_error = 'No se pudo escribir tmp';
                usleep(200000);
                continue;
            }

            @rename($tmp_file, $this->wsdl_cache_file);

            Log::info('AFIP WSDL: cache actualizado: ' . $this->wsdl_cache_file);

            return true;
        }

        Log::warning(
            'AFIP WSDL: no se pudo descargar/actualizar desde ' . $this->wsdl_url .
            ' | http_code: ' . (string) $last_http_code .
            ' | error: ' . (string) $last_error
        );

        return false;
    }

    /**
     * __call
     *
     * Método mágico que ejecuta las funciones definidas en el WebService.
     */
    public function __call($name, array $arguments)
    {
        $hubo_un_error = false;
        $result = null;
        $error = null;

        // Timeout global de sockets (para lectura), razonable para AFIP/hosting compartido
        @ini_set('default_socket_timeout', (string) $this->wsdl_download_timeout_seconds);

        if (is_null($this->soap_client)) {
            $wsdl = $this->get_wsdl_to_use();

            try {
                $this->soap_client = new \SoapClient($wsdl, $this->soap_options);
            } catch (\SoapFault $e) {
                // Si está intentando con remoto y falla, reintentar con local si existe
                $hubo_un_error = true;
                $error = $e->getMessage();

                Log::error('AFIP SOAP: error creando SoapClient. WSDL usado: ' . $wsdl . ' | error: ' . $error);

                return [
                    'hubo_un_error' => true,
                    'result'        => null,
                    'error'         => $error,
                    'request'       => null,
                    'response'      => null,
                ];
            }
        }

        try {
            // Log::info('Llego esto a WS para enviar:');
            // Log::info((array) $arguments);

            $params = $arguments;

            if ($this->for_wsfex) {
                $params = $arguments[0];
                Log::info('Como es for_wsfex se van a enviar estos params:');
                Log::info((array) $params);
            }



            // Reintentos automaticos si falla por error de conexion
            $max_attempts = $this->should_retry_method($name) ? 3 : 1;

            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                try {
                    $result = $this->soap_client->$name($params);
                    $hubo_un_error = false;
                    $error = null;
                    break;
                } catch (\SoapFault $e) {
                    $hubo_un_error = true;
                    $error = $e->getMessage();

                    Log::info('ERROR en el nuevo metodo: '.$error);

                    if ($this->is_network_error($error) && $attempt < $max_attempts) {
                        Log::warning("AFIP SOAP: error de red en intento {$attempt}/{$max_attempts}. Reintentando... Error: {$error}");

                        // recrear SoapClient por si quedó en mal estado
                        $this->soap_client = null;

                        $wsdl = $this->get_wsdl_to_use();
                        try {
                            $this->soap_client = new \SoapClient($wsdl, $this->soap_options);
                        } catch (\Throwable $e2) {
                            // si no puede recrear, corta
                            $error = $e2->getMessage();
                            break;
                        }

                        usleep(250000); // 250ms
                        continue;
                    } else {
                        Log::info('No entro en el reintento: ');
                        Log::info('attempt: '.$attempt);
                        Log::info('max_attempts: '.$max_attempts);
                    }

                    break;
                } catch (\Throwable $e) {
                    $hubo_un_error = true;
                    $error = $e->getMessage();
                    break;
                }
            }



        } catch (\SoapFault $e) {
            $hubo_un_error = true;
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $hubo_un_error = true;
            $error = $e->getMessage();
        }

        // Guardar request/response sólo si existen (evita errores secundarios)
        $last_request = null;
        $last_response = null;

        try {
            if ($this->soap_client) {
                $last_request = $this->soap_client->__getLastRequest();
                $last_response = $this->soap_client->__getLastResponse();

                if (!empty($last_request)) {
                    @mkdir(public_path() . "/afip/ws", 0775, true);
                    @file_put_contents(public_path() . "/afip/ws/request-ws.xml", $last_request);
                }
                if (!empty($last_response)) {
                    @mkdir(public_path() . "/afip/ws", 0775, true);
                    @file_put_contents(public_path() . "/afip/ws/response-ws.xml", $last_response);
                }
            }
        } catch (\Throwable $e) {
            // no romper por logging
        }

        return [
            'hubo_un_error' => $hubo_un_error,
            'result'        => $result,
            'error'         => $error,
            'request'       => $last_request,
            'response'      => $last_response,
        ];
    }

    protected function download_wsdl_xml()
    {
        // Preferir cURL si está disponible
        if (function_exists('curl_init')) {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $this->wsdl_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => (int) $this->wsdl_download_timeout_seconds,
                CURLOPT_TIMEOUT => (int) $this->wsdl_download_timeout_seconds,
                CURLOPT_USERAGENT => 'Laravel-AFIP-Client',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/xml, application/xml, */*',
                ],
                // Hosting compartido: a veces IPv6 da problemas; esto fuerza IPv4 si el hosting lo soporta
                CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($curl_errno) {
                return [
                    'ok' => false,
                    'body' => null,
                    'error' => "cURL errno {$curl_errno}: {$curl_error}",
                    'http_code' => $http_code,
                ];
            }

            return [
                'ok' => ($http_code >= 200 && $http_code < 300),
                'body' => $body,
                'error' => ($http_code >= 200 && $http_code < 300) ? null : "HTTP {$http_code}",
                'http_code' => $http_code,
            ];
        }

        // Fallback: file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => (int) $this->wsdl_download_timeout_seconds,
                'header'  => "User-Agent: Laravel-AFIP-Client\r\nAccept: text/xml, application/xml, */*\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ],
        ]);

        $body = @file_get_contents($this->wsdl_url, false, $context);

        return [
            'ok' => !empty($body),
            'body' => $body,
            'error' => empty($body) ? 'WSDL vacío' : null,
            'http_code' => null,
        ];
    }

    protected function is_network_error($message)
    {
        $message = mb_strtolower((string) $message);

        return str_contains($message, 'could not connect to host')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'timed out')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'name or service not known')
            || str_contains($message, 'temporary failure in name resolution');
    }

    protected function should_retry_method($method_name)
    {
        // Solo reintentar lecturas / parámetros / dummy.
        $retryable_methods = [
            'FEDummy',
            'FECompUltimoAutorizado',
            'FECompConsultar',
            'FEParamGetTiposCbte',
            'FEParamGetTiposDoc',
            'FEParamGetTiposIva',
            'FEParamGetTiposMonedas',
            'FEParamGetCotizacion',
            'FEParamGetPtosVenta',
            'FEParamGetCondicionIvaReceptor',
            // Agregá acá cualquier otro "GET" que uses
        ];

        return in_array($method_name, $retryable_methods, true);
    }
}