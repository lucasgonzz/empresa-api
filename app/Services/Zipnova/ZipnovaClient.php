<?php

namespace App\Services\Zipnova;

use App\Models\PlatformConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de la API v2 de Zipnova (ex Zippin), misión zipnova-envios, 14/9/2026.
 *
 * Autenticación básica con las credenciales de cuenta del comercio (API Token + API Secret,
 * generados en Zipnova → Configuración → Integraciones → Gestionar credenciales y webhooks):
 * `Authorization: Basic base64(token:secret)`. Es la vía que la propia doc recomienda para "una
 * tienda propia / un ERP" y la única que no depende de que Zipnova apruebe una aplicación
 * (docs.zipnova.com/envios/principios/urls-y-autenticacion). Los endpoints que se usan:
 *
 *   GET  /accounts                          → validar credenciales y resolver la cuenta
 *   GET  /addresses?account_id=             → depósitos de origen
 *   POST /shipments/quote                   → cotizar (rate limit 500/min por IP)
 *   POST /shipments                         → crear el envío (100/min)
 *   GET  /shipments/{id}                    → estado actual
 *   GET  /shipments/{id}/tracking           → historial
 *   POST /shipments/{id}/cancel             → cancelar
 *   GET  /shipments/{id}/label.pdf          → etiqueta (409 si todavía no está lista)
 *   POST|DELETE /accounts/{id}/webhooks     → avisos de cambio de estado
 *
 * 🔴 ESTE ARCHIVO ES ESPEJO ENTRE `empresa-api` Y `tienda-api` (solo cambia el `use` del
 * conector). El chequeo de contrato de la misión los compara token a token: si se cambia acá,
 * se cambia allá.
 *
 * 🔴 Nunca se loguea el header de autorización ni el token. Ante un error se loguean método,
 * ruta y código HTTP; el body de la respuesta solo si no es 2xx (no trae credenciales).
 */
class ZipnovaClient
{
    /** Identificador que Zipnova pide en `source` para reconocer la integración. */
    const SOURCE = 'comerciocity';

    /** Host por defecto de la API v2 (Argentina). Los dominios `zippin` vencen el 1/4/2026. */
    const BASE_URL_DEFAULT = 'https://api.zipnova.com.ar/v2';

    /** Segundos máximos de espera por respuesta. */
    const TIMEOUT_DEFAULT = 20;

    /** Tope de segundos que se respeta de un `Retry-After` antes de reintentar una sola vez. */
    const MAX_RETRY_AFTER = 5;

    /** @var string Credencial ya codificada: `base64(token:secret)`. */
    protected $basic_credential;

    /** @var string|null Id de la cuenta de Zipnova sobre la que se opera. */
    protected $account_id;

    /**
     * @param string $basic_credential `base64(api_token:api_secret)`, tal cual va en el header.
     * @param string|int|null $account_id Cuenta de Zipnova (null hasta resolverla con `accounts()`).
     */
    public function __construct($basic_credential, $account_id = null)
    {
        $this->basic_credential = (string) $basic_credential;
        $this->account_id = is_null($account_id) ? null : (string) $account_id;
    }

    /**
     * Cliente a partir del token y el secret sin codificar (lo que pega el comercio).
     *
     * @param string $api_token
     * @param string $api_secret
     * @param string|int|null $account_id
     * @return self
     */
    public static function con_credenciales($api_token, $api_secret, $account_id = null)
    {
        return new self(self::codificar_credencial($api_token, $api_secret), $account_id);
    }

    /**
     * Credencial Basic a partir del token y el secret. Es lo que se persiste en
     * `platform_connectors.access_token` (cifrado por el cast) para el conector de Zipnova.
     *
     * @param string $api_token
     * @param string $api_secret
     * @return string
     */
    public static function codificar_credencial($api_token, $api_secret)
    {
        return base64_encode(trim((string) $api_token) . ':' . trim((string) $api_secret));
    }

    /**
     * Cliente a partir del conector de Zipnova del comercio.
     *
     * Leer `$connector->access_token` descifra con la APP_KEY: puede tirar `DecryptException` en
     * un cliente con clave distinta. El llamador (el helper de credenciales) lo atrapa.
     *
     * @param PlatformConnector $connector Conector con `access_token` y `platform_user_id`.
     * @return self
     */
    public static function from_connector(PlatformConnector $connector)
    {
        return new self((string) $connector->access_token, $connector->platform_user_id);
    }

    /**
     * Cuenta de Zipnova sobre la que opera este cliente.
     *
     * @return string|null
     */
    public function account_id()
    {
        return $this->account_id;
    }

    /**
     * Fija la cuenta (se usa después de resolverla con `accounts()`).
     *
     * @param string|int $account_id
     * @return self
     */
    public function con_cuenta($account_id)
    {
        $this->account_id = (string) $account_id;

        return $this;
    }

    /**
     * Host base de la API, configurable por `.env` (`ZIPNOVA_BASE_URL`) sin tocar código.
     *
     * @return string
     */
    public static function base_url()
    {
        $base_url = (string) config('services.zipnova.base_url', self::BASE_URL_DEFAULT);

        if ($base_url === '') {
            $base_url = self::BASE_URL_DEFAULT;
        }

        return rtrim($base_url, '/');
    }

    /**
     * Opciones Guzzle/cURL para las peticiones. Mismo criterio que
     * `MercadoPagoOAuthService::http_client_options()`: el PHP de wamp no valida el certificado
     * si no se le pasa el `cacert.pem`, y desactivar la verificación no es opción porque en ese
     * header viaja el token. Si no hay bundle propio de Zipnova, se usa el que ya está cargado
     * para Zippin.
     *
     * @return array
     */
    public static function http_client_options()
    {
        $ca_bundle = (string) config('services.zipnova.guzzle_ca_bundle', '');
        if ($ca_bundle === '') {
            $ca_bundle = (string) config('services.zippin.guzzle_ca_bundle', '');
        }
        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return [
            'verify' => (bool) config('services.zipnova.guzzle_verify', true),
        ];
    }

    /**
     * Cuentas de Zipnova a las que acceden estas credenciales. Sirve para validar el token y el
     * secret (401/403 → `ZipnovaException::esDeCredenciales()`) y para saber el `account_id`.
     *
     * @return array Lista de cuentas (`data[]` de Zipnova): `{id, name, company_name, ...}`.
     */
    public function accounts()
    {
        $respuesta = $this->request('GET', '/accounts');

        return isset($respuesta['data']) && is_array($respuesta['data']) ? $respuesta['data'] : [];
    }

    /**
     * Depósitos (address book) de una cuenta.
     *
     * @param string|int|null $account_id Cuenta; por defecto la del cliente.
     * @return array Lista de direcciones (`data[]`): `{id, name, street, street_number, city{name}, state{name}, zipcode, use_for_shipping, ...}`.
     */
    public function addresses($account_id = null)
    {
        $account_id = is_null($account_id) ? $this->account_id : $account_id;

        $respuesta = $this->request('GET', '/addresses', $account_id ? ['account_id' => $account_id] : []);

        return isset($respuesta['data']) && is_array($respuesta['data']) ? $respuesta['data'] : [];
    }

    /**
     * Cotiza un envío. Completa `account_id` y `source` si el payload no los trae.
     *
     * @param array $payload `{origin_id?, declared_value, destination{zipcode, city?, state?}, items[] | packages[], sort_by?}`.
     * @return array Respuesta cruda de Zipnova (`results`, `all_results`, `destination`, `packages`).
     */
    public function quote(array $payload)
    {
        return $this->request('POST', '/shipments/quote', [], $this->completar_payload($payload));
    }

    /**
     * Crea un envío. Completa `account_id` y `source` si el payload no los trae.
     *
     * @param array $payload Ver docs: `{external_id, service_type, logistic_type?, carrier_id?, origin_id, declared_value, destination{...}, items[] | packages[]}`.
     * @return array El envío creado (`id`, `status`, `tracking`, `carrier`, ...).
     */
    public function create_shipment(array $payload)
    {
        return $this->request('POST', '/shipments', [], $this->completar_payload($payload));
    }

    /**
     * Estado actual y datos completos de un envío.
     *
     * @param string|int $shipment_id
     * @return array
     */
    public function get_shipment($shipment_id)
    {
        return $this->request('GET', '/shipments/' . rawurlencode((string) $shipment_id));
    }

    /**
     * Historial de estados de un envío.
     *
     * @param string|int $shipment_id
     * @param string $sort `newest` (del más reciente al más viejo) o `oldest`.
     * @return array
     */
    public function tracking($shipment_id, $sort = 'newest')
    {
        return $this->request('GET', '/shipments/' . rawurlencode((string) $shipment_id) . '/tracking', ['sort' => $sort]);
    }

    /**
     * Cancela un envío (o pide el rescate si ya está despachado).
     *
     * @param string|int $shipment_id
     * @return array `{shipment_id, success, result: 'canceled'|'rescue_requested'}`.
     */
    public function cancel($shipment_id)
    {
        return $this->request('POST', '/shipments/' . rawurlencode((string) $shipment_id) . '/cancel', [], []);
    }

    /**
     * Etiqueta del envío en PDF (bytes). La doc describe la respuesta como "documento en base64
     * con formato y contenido"; se tolera también que venga el PDF crudo.
     *
     * @param string|int $shipment_id
     * @param bool $no_status_change Si es true, descargar no pasa el envío a "listo para despacho".
     * @return string Bytes del PDF.
     */
    public function label_pdf($shipment_id, $no_status_change = false)
    {
        $path = '/shipments/' . rawurlencode((string) $shipment_id) . '/label.pdf';
        $query = $no_status_change ? ['no_status_change' => 1] : [];

        $response = $this->enviar('GET', $path, $query, null);

        $content_type = strtolower((string) $response->header('Content-Type'));

        if (strpos($content_type, 'pdf') !== false) {
            return (string) $response->body();
        }

        $json = $response->json();

        if (is_array($json)) {
            $contenido = null;
            if (isset($json['content'])) {
                $contenido = $json['content'];
            } elseif (isset($json['data']['content'])) {
                $contenido = $json['data']['content'];
            } elseif (isset($json['file'])) {
                $contenido = $json['file'];
            }

            if (is_string($contenido) && $contenido !== '') {
                $decodificado = base64_decode($contenido, true);
                if ($decodificado !== false) {
                    return $decodificado;
                }
            }
        }

        throw new ZipnovaException('Zipnova devolvió la etiqueta en un formato que no se reconoce.', $response->status(), is_array($json) ? $json : []);
    }

    /**
     * Suscribe una URL a un tópico de webhooks de la cuenta (`status`, `shipment`, ...).
     *
     * @param string|int $account_id
     * @param string $topic
     * @param string $url
     * @return array `{id, account_id, topic, url}`.
     */
    public function create_webhook($account_id, $topic, $url)
    {
        return $this->request('POST', '/accounts/' . rawurlencode((string) $account_id) . '/webhooks', [], [
            'topic' => $topic,
            'url'   => $url,
        ]);
    }

    /**
     * Elimina una suscripción a webhook.
     *
     * @param string|int $account_id
     * @param string|int $webhook_id
     * @return void
     */
    public function delete_webhook($account_id, $webhook_id)
    {
        $this->request('DELETE', '/accounts/' . rawurlencode((string) $account_id) . '/webhooks/' . rawurlencode((string) $webhook_id));
    }

    /**
     * Agrega `account_id` y `source` a un payload de cotización/creación si no vienen.
     *
     * @param array $payload
     * @return array
     */
    protected function completar_payload(array $payload)
    {
        if (!isset($payload['account_id']) && !is_null($this->account_id)) {
            $payload['account_id'] = (int) $this->account_id;
        }
        if (!isset($payload['source'])) {
            $payload['source'] = self::SOURCE;
        }

        return $payload;
    }

    /**
     * Hace la petición y devuelve el JSON decodificado. Lanza `ZipnovaException` ante cualquier
     * respuesta que no sea 2xx o si no hubo respuesta.
     *
     * @param string $method
     * @param string $path Ruta relativa al host (`/shipments/quote`).
     * @param array $query
     * @param array|null $body null para no mandar body (GET/DELETE).
     * @return array
     */
    protected function request($method, $path, array $query = [], $body = null)
    {
        $response = $this->enviar($method, $path, $query, $body);

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Envía la petición, reintenta una vez ante 429 respetando `Retry-After` (máx. 5 s) y valida
     * el código de respuesta.
     *
     * @param string $method
     * @param string $path
     * @param array $query
     * @param array|null $body
     * @return \Illuminate\Http\Client\Response
     */
    protected function enviar($method, $path, array $query, $body)
    {
        $url = self::base_url() . $path;

        try {
            $response = $this->ejecutar($method, $url, $query, $body);

            // El reintento ante 429 se apaga por config en la tienda (`services.zipnova.reintento_429`):
            // ahí cotiza un endpoint público, y un worker PHP dormido 5 segundos por cada comprador
            // mientras Zipnova limita por IP del servidor —compartida por todas las tiendas del
            // shared— es justo lo que frena al cliente entero. En el ERP, que opera de a un envío,
            // el reintento sí paga.
            if ($response->status() === 429 && (bool) config('services.zipnova.reintento_429', true)) {
                $espera = (int) $response->header('Retry-After');
                if ($espera > 0) {
                    sleep(min($espera, self::MAX_RETRY_AFTER));
                }
                $response = $this->ejecutar($method, $url, $query, $body);
            }
        } catch (\Throwable $e) {
            Log::warning('ZipnovaClient: sin respuesta de Zipnova en ' . $method . ' ' . $path . ': ' . $e->getMessage());

            throw new ZipnovaException('No pudimos comunicarnos con Zipnova. Probá de nuevo en un rato.', 0, [], $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $json = $response->json();
        $body_decodificado = is_array($json) ? $json : [];

        Log::warning('ZipnovaClient: ' . $method . ' ' . $path . ' respondió ' . $response->status() . ': ' . substr((string) $response->body(), 0, 1000));

        throw new ZipnovaException(self::mensaje_de_error($response->status(), $body_decodificado), $response->status(), $body_decodificado);
    }

    /**
     * Petición HTTP concreta, con la autenticación y los headers obligatorios.
     *
     * @param string $method
     * @param string $url
     * @param array $query
     * @param array|null $body
     * @return \Illuminate\Http\Client\Response
     */
    protected function ejecutar($method, $url, array $query, $body)
    {
        $timeout = (int) config('services.zipnova.timeout', self::TIMEOUT_DEFAULT);
        if ($timeout <= 0) {
            $timeout = self::TIMEOUT_DEFAULT;
        }

        $pending = Http::withOptions(self::http_client_options())
            ->withHeaders([
                'Authorization' => 'Basic ' . $this->basic_credential,
                'Accept'        => 'application/json',
            ])
            ->timeout($timeout);

        if (count($query) > 0) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $method = strtoupper($method);

        if ($method === 'GET') {
            return $pending->get($url);
        }
        if ($method === 'DELETE') {
            return $pending->delete($url);
        }
        if ($method === 'PUT') {
            return $pending->asJson()->put($url, is_array($body) ? $body : []);
        }

        return $pending->asJson()->post($url, is_array($body) ? $body : []);
    }

    /**
     * Mensaje legible, en castellano, a partir del código y el body de error de Zipnova.
     *
     * @param int $status
     * @param array $body
     * @return string
     */
    protected static function mensaje_de_error($status, array $body)
    {
        $detalle = '';

        if (isset($body['message']) && is_string($body['message'])) {
            $detalle = $body['message'];
        } elseif (isset($body['error']) && is_string($body['error'])) {
            $detalle = $body['error'];
        } elseif (isset($body['errors']) && is_array($body['errors'])) {
            $partes = [];
            foreach ($body['errors'] as $campo => $mensajes) {
                if (is_array($mensajes)) {
                    $partes[] = $campo . ': ' . implode(' ', array_map('strval', $mensajes));
                } elseif (is_string($mensajes)) {
                    $partes[] = $campo . ': ' . $mensajes;
                }
            }
            $detalle = implode('; ', $partes);
        }

        if ($status === 401 || $status === 403) {
            return 'Zipnova no reconoció el token o el secret (HTTP ' . $status . ').' . ($detalle !== '' ? ' ' . $detalle : '');
        }
        if ($status === 429) {
            return 'Zipnova está recibiendo demasiadas consultas. Probá de nuevo en un minuto.';
        }
        if ($status >= 500) {
            return 'Zipnova no está respondiendo (HTTP ' . $status . '). Probá de nuevo en un rato.';
        }

        return 'Zipnova rechazó la operación (HTTP ' . $status . ').' . ($detalle !== '' ? ' ' . $detalle : '');
    }
}
