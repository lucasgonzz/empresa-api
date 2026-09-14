<?php

namespace App\Services\Zipnova;

use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Models\Platform;
use App\Models\PlatformConnector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * La conexión del comercio con Zipnova y sus preferencias, tal como las maneja la tarjeta de
 * ABM -> Integraciones -> Tienda online (misión zipnova-envios, 14/9/2026).
 *
 * Todo vive en el conector `zipnova` de `platform_connectors` del comercio:
 *
 *  - `access_token`   = `base64(api_token:api_secret)`, el valor exacto del header
 *                       `Authorization: Basic`, cifrado por el cast `encrypted` del modelo.
 *  - `platform_user_id` = id numérico de la cuenta de Zipnova (string).
 *  - `extra_config`   = las preferencias no secretas (§2.3 del plan): nombre de la cuenta, los
 *                       depósitos de origen y el elegido, el bulto por defecto, si se asegura
 *                       el envío por el valor de la compra, el umbral de envío gratis, y el id
 *                       y la URL del webhook registrado.
 *
 * Nada se guarda en `online_configurations`: el OAuth viejo de Zippin (`zippin_*`) queda como
 * está y no se lee más desde acá.
 *
 * Conectar es la única operación que habla con Zipnova con credenciales que todavía no están
 * guardadas: se prueban contra `GET /accounts` y recién si responde se persiste el conector.
 * Un token mal copiado nunca llega a la base.
 *
 * Solo `empresa-api`: la tienda lee el conector (`ZipnovaCredentialsHelper`), no lo escribe.
 */
class ZipnovaConexionService
{
    /** Tópico de webhooks de Zipnova que avisa cada cambio de estado de un envío. */
    const WEBHOOK_TOPIC = 'status';

    /** Largo máximo del nombre de la cuenta y de la etiqueta de un depósito que se guardan. */
    const MAX_ETIQUETA = 160;

    /**
     * Mensaje para el operador cuando hay conector pero el token no se puede descifrar (la
     * APP_KEY cambió, o la fila se escribió en plano). Apunta a la causa: sin esto la prueba
     * respondía "Zipnova no reconoció el token o el secret", que culpa al comercio por unas
     * credenciales que nunca llegaron a Zipnova.
     */
    const MENSAJE_CREDENCIAL_ILEGIBLE = 'La credencial guardada no se puede leer. Desconectá y volvé a conectar Zipnova.';

    /**
     * Conecta la cuenta de Zipnova del comercio con su API Token + API Secret.
     *
     * Pasos, en orden y con el porqué:
     *  1. `GET /accounts` con las credenciales SIN guardar: si Zipnova las rechaza (401/403) la
     *     `ZipnovaException` sube tal cual y el controller responde 422; si no hay ninguna cuenta,
     *     `ZipnovaConexionException` (el token existe pero no sirve para operar).
     *  2. `GET /addresses` de esa cuenta para ofrecerle al comercio el depósito de origen. Solo
     *     los marcados `use_for_shipping`: los otros son direcciones de facturación o de
     *     devolución y Zipnova no despacha desde ahí. Se elige el primero por defecto para que
     *     un comercio con un solo depósito (la mayoría) no tenga que tocar nada.
     *  3. Se guarda el conector (`find_or_create_for_user_and_slug`), con `extra_config` nuevo:
     *     reconectar reemplaza las preferencias, porque pueden ser de otra cuenta.
     *  4. Se registra el webhook `status` apuntando a `$webhook_url`, best effort: si falla se
     *     loguea y `webhook_id` queda null. La conexión ya es válida sin él —el comando
     *     `zipnova:sincronizar-envios` consulta cada 30 minutos igual— y la tarjeta muestra el
     *     aviso a partir de `webhook_registrado`.
     *
     * @param int $user_id Comercio (owner).
     * @param string $api_token API Token generado en Zipnova.
     * @param string $api_secret API Secret generado en Zipnova.
     * @param string $webhook_url URL pública de `POST /api/zipnova/webhook` de esta instancia.
     * @return PlatformConnector El conector ya conectado.
     * @throws ZipnovaException Si Zipnova rechazó las credenciales o no respondió.
     * @throws ZipnovaConexionException Si no hay cuenta o falta la plataforma en el catálogo.
     */
    public function conectar($user_id, $api_token, $api_secret, $webhook_url)
    {
        $client = ZipnovaClient::con_credenciales($api_token, $api_secret);

        $cuentas = $client->accounts();

        $cuenta = $this->primera_cuenta($cuentas);
        if (is_null($cuenta)) {
            throw new ZipnovaConexionException('Las credenciales son válidas pero no tienen ninguna cuenta de Zipnova asociada. Entrá a app.zipnova.com.ar y completá los datos de tu negocio.');
        }

        $account_id = (string) $cuenta['id'];
        $client->con_cuenta($account_id);

        $origins = $this->origenes_desde_addresses($client->addresses($account_id));

        $connector = PlatformConnector::find_or_create_for_user_and_slug((int) $user_id, Platform::SLUG_ZIPNOVA);
        if (is_null($connector)) {
            throw new ZipnovaConexionException('Falta la plataforma Zipnova en el catálogo de esta instancia. Hay que correr las migraciones pendientes.');
        }

        // Si el comercio ya tenía un webhook registrado (reconexión con credenciales nuevas), se
        // intenta dar de baja con la credencial NUEVA: es la misma cuenta la mayoría de las veces.
        // Best effort, igual que el alta.
        $config_previa = ZipnovaCredentialsHelper::config_desde_conector($connector);
        if (!empty($config_previa['webhook_id'])) {
            $this->borrar_webhook($client, $account_id, $config_previa['webhook_id']);
        }

        $config = ZipnovaCredentialsHelper::config_defaults();
        $config['account_name'] = $this->nombre_de_cuenta($cuenta);
        $config['accounts'] = $this->cuentas_resumidas($cuentas);
        $config['origins'] = $origins;
        $config['origin_id'] = count($origins) > 0 ? $origins[0]['id'] : null;
        $config['origin_label'] = count($origins) > 0 ? $origins[0]['label'] : null;
        $config['conectado_en'] = Carbon::now()->toIso8601String();

        $connector->access_token = ZipnovaClient::codificar_credencial($api_token, $api_secret);
        $connector->refresh_token = null;
        $connector->auth_code = null;
        $connector->public_key = null;
        $connector->expires_at = null;
        $connector->platform_user_id = $account_id;
        $connector->status = PlatformConnector::STATUS_CONECTADO;
        $connector->error_message = null;
        $connector->extra_config = $config;
        $connector->save();

        $webhook = $this->registrar_webhook($client, $account_id, $webhook_url);
        if (!is_null($webhook)) {
            $config['webhook_id'] = $webhook['id'];
            $config['webhook_url'] = $webhook['url'];
            $connector->extra_config = $config;
            $connector->save();
        }

        return $connector;
    }

    /**
     * Desconecta: da de baja el webhook (best effort) y limpia credenciales, cuenta y
     * preferencias del conector. La fila queda en `sin_conectar`, como la deja el disconnect de
     * Mercado Pago, para que el próximo conectar la reutilice.
     *
     * @param int $user_id Comercio (owner).
     * @return PlatformConnector|null null si el comercio nunca tuvo conector.
     */
    public function desconectar($user_id)
    {
        $connector = PlatformConnector::find_for_user_and_slug((int) $user_id, Platform::SLUG_ZIPNOVA);
        if (is_null($connector)) {
            return null;
        }

        $credentials = ZipnovaCredentialsHelper::credentials((int) $user_id);
        $config = $credentials['config'];

        if (!is_null($credentials['basic']) && !is_null($credentials['account_id']) && !empty($config['webhook_id'])) {
            $client = new ZipnovaClient($credentials['basic'], $credentials['account_id']);
            $this->borrar_webhook($client, $credentials['account_id'], $config['webhook_id']);
        }

        $connector->access_token = null;
        $connector->refresh_token = null;
        $connector->auth_code = null;
        $connector->public_key = null;
        $connector->expires_at = null;
        $connector->platform_user_id = null;
        $connector->status = PlatformConnector::STATUS_SIN_CONECTAR;
        $connector->error_message = null;
        $connector->extra_config = null;
        $connector->save();

        return $connector;
    }

    /**
     * Guarda las preferencias que la tarjeta edita. Solo se tocan las claves que vienen; el
     * resto de `extra_config` (cuenta, depósitos, webhook) queda como está.
     *
     * @param int $user_id Comercio (owner).
     * @param array $cambios `{origin_id?, bulto_default?{peso,alto,ancho,profundidad}, declarar_valor?, envio_gratis_desde?}`, ya validados por el controller.
     * @return PlatformConnector
     * @throws ZipnovaConexionException Si no está conectado o el depósito no está en la lista.
     */
    public function guardar_config($user_id, array $cambios)
    {
        $connector = $this->conector_conectado($user_id);

        $config = ZipnovaCredentialsHelper::config_desde_conector($connector);

        if (array_key_exists('origin_id', $cambios)) {
            $origin_id = $cambios['origin_id'];
            if (!is_null($origin_id) && $origin_id !== '') {
                $origen = $this->origen_por_id($config['origins'], $origin_id);
                if (is_null($origen)) {
                    throw new ZipnovaConexionException('Ese depósito no está en la lista de Zipnova. Tocá "Actualizar depósitos" y volvé a elegirlo.');
                }
                $config['origin_id'] = $origen['id'];
                $config['origin_label'] = $origen['label'];
            } else {
                $config['origin_id'] = null;
                $config['origin_label'] = null;
            }
        }

        if (isset($cambios['bulto_default']) && is_array($cambios['bulto_default'])) {
            // Campo por campo sobre lo que ya había: el comercio puede mandar solo el peso.
            $bulto = is_array($config['bulto_default']) ? $config['bulto_default'] : [];
            foreach (['peso', 'alto', 'ancho', 'profundidad'] as $campo) {
                if (isset($cambios['bulto_default'][$campo]) && is_numeric($cambios['bulto_default'][$campo])) {
                    $bulto[$campo] = (float) $cambios['bulto_default'][$campo];
                }
            }
            $config['bulto_default'] = ZipnovaPaquetesHelper::bulto_default_completo($bulto);
        }

        if (array_key_exists('declarar_valor', $cambios)) {
            $config['declarar_valor'] = filter_var($cambios['declarar_valor'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('envio_gratis_desde', $cambios)) {
            $desde = $cambios['envio_gratis_desde'];
            // Cero o vacío es "sin envío gratis por monto": se guarda null, que es lo que el
            // lector (`ZipnovaEnvioGratisHelper`) entiende como regla apagada.
            $config['envio_gratis_desde'] = is_numeric($desde) && (float) $desde > 0 ? round((float) $desde, 2) : null;
        }

        $connector->extra_config = $config;
        $connector->save();

        return $connector;
    }

    /**
     * Vuelve a pedirle a Zipnova los depósitos de la cuenta y actualiza la lista. Si el depósito
     * elegido ya no existe allá, pasa al primero de la lista nueva (o a null si quedó vacía).
     *
     * @param int $user_id Comercio (owner).
     * @return PlatformConnector
     * @throws ZipnovaConexionException Si no está conectado.
     * @throws ZipnovaException Si Zipnova no respondió.
     */
    public function actualizar_origenes($user_id)
    {
        $connector = $this->conector_conectado($user_id);

        list($client, $credentials) = $this->client_del_comercio($user_id);

        $origins = $this->origenes_desde_addresses($client->addresses($credentials['account_id']));

        $config = ZipnovaCredentialsHelper::config_desde_conector($connector);
        $config['origins'] = $origins;

        $elegido = is_null($config['origin_id']) ? null : $this->origen_por_id($origins, $config['origin_id']);
        if (is_null($elegido) && count($origins) > 0) {
            $elegido = $origins[0];
        }
        $config['origin_id'] = is_null($elegido) ? null : $elegido['id'];
        $config['origin_label'] = is_null($elegido) ? null : $elegido['label'];

        $connector->extra_config = $config;
        $connector->save();

        return $connector;
    }

    /**
     * Cotización de prueba para la tarjeta ("Probá cómo lo ve tu cliente"): un solo ítem con el
     * bulto por defecto del comercio y un valor declarado fijo de $1000, al código postal que
     * escribió el operador. Devuelve la misma forma normalizada que ve el comprador en la tienda.
     *
     * @param int $user_id Comercio (owner).
     * @param string $zipcode Código postal de destino.
     * @param string|null $city Localidad, si Zipnova la pidió (`needs_location`).
     * @param string|null $state Provincia, ídem.
     * @return array `{zipcode, city, state, envio_gratis, opciones}` (ver `ZipnovaQuoteNormalizer`).
     * @throws ZipnovaConexionException Si no está conectado.
     * @throws ZipnovaException Si Zipnova rechazó la cotización (ubicación) o no respondió.
     */
    public function cotizar_prueba($user_id, $zipcode, $city = null, $state = null)
    {
        list($client, $credentials) = $this->client_del_comercio($user_id);
        $config = $credentials['config'];

        $destination = ['zipcode' => trim((string) $zipcode)];
        if (is_string($city) && trim($city) !== '') {
            $destination['city'] = trim($city);
        }
        if (is_string($state) && trim($state) !== '') {
            $destination['state'] = trim($state);
        }

        $payload = [
            'declared_value' => 1000,
            'destination'    => $destination,
            // Un objeto vacío: `item_desde_articulo` cae al bulto por defecto campo por campo.
            'items'          => [ZipnovaPaquetesHelper::item_desde_articulo((object) [], $config['bulto_default'])],
            'sort_by'        => 'price',
        ];
        if (!empty($config['origin_id'])) {
            $payload['origin_id'] = (int) $config['origin_id'];
        }

        $respuesta = $client->quote($payload);

        return ZipnovaQuoteNormalizer::normalizar($respuesta, false);
    }

    /**
     * Conector de Zipnova del comercio, conectado, o excepción con el mensaje para el operador.
     *
     * @param int $user_id
     * @return PlatformConnector
     * @throws ZipnovaConexionException
     */
    protected function conector_conectado($user_id)
    {
        $connector = ZipnovaCredentialsHelper::connector((int) $user_id);

        if (is_null($connector)) {
            throw new ZipnovaConexionException('Zipnova no está conectado. Pegá el API Token y el API Secret y tocá "Conectar".');
        }

        return $connector;
    }

    /**
     * Cliente de Zipnova del comercio con la credencial YA descifrada, más las credenciales.
     *
     * `connector()` mira el atributo crudo; `credentials()` además lo descifra y devuelve
     * `basic` null si no puede (APP_KEY cambiada, fila en plano). Ese caso NO arma el cliente:
     * con un `Basic` vacío Zipnova responde 401 y el mensaje culparía a las credenciales del
     * comercio, cuando lo que hay que hacer es desconectar y volver a conectar.
     *
     * @param int $user_id
     * @return array{0: ZipnovaClient, 1: array} El cliente y el array de `credentials()`.
     * @throws ZipnovaConexionException Si no está conectado o la credencial no se puede leer.
     */
    protected function client_del_comercio($user_id)
    {
        $this->conector_conectado($user_id);

        $credentials = ZipnovaCredentialsHelper::credentials((int) $user_id);

        if (is_null($credentials['basic'])) {
            throw new ZipnovaConexionException(self::MENSAJE_CREDENCIAL_ILEGIBLE);
        }

        return [new ZipnovaClient($credentials['basic'], $credentials['account_id']), $credentials];
    }

    /**
     * Primera cuenta usable de la lista de `GET /accounts` (la que tiene `id`).
     *
     * @param array $cuentas
     * @return array|null
     */
    protected function primera_cuenta(array $cuentas)
    {
        foreach ($cuentas as $cuenta) {
            if (is_array($cuenta) && isset($cuenta['id'])) {
                return $cuenta;
            }
        }

        return null;
    }

    /**
     * Nombre legible de una cuenta: `name`, si no `company_name`, si no el id.
     *
     * @param array $cuenta
     * @return string
     */
    protected function nombre_de_cuenta(array $cuenta)
    {
        foreach (['name', 'company_name'] as $clave) {
            if (isset($cuenta[$clave]) && is_string($cuenta[$clave]) && trim($cuenta[$clave]) !== '') {
                return mb_substr(trim($cuenta[$clave]), 0, self::MAX_ETIQUETA);
            }
        }

        return 'Cuenta ' . $cuenta['id'];
    }

    /**
     * `[{id, name}]` de todas las cuentas, para que la tarjeta pueda mostrarlas si algún día se
     * elige entre varias. Hoy se opera siempre con la primera.
     *
     * @param array $cuentas
     * @return array
     */
    protected function cuentas_resumidas(array $cuentas)
    {
        $resumen = [];
        foreach ($cuentas as $cuenta) {
            if (!is_array($cuenta) || !isset($cuenta['id'])) {
                continue;
            }
            $resumen[] = [
                'id'   => (int) $cuenta['id'],
                'name' => $this->nombre_de_cuenta($cuenta),
            ];
        }

        return $resumen;
    }

    /**
     * Depósitos de origen a partir de `GET /addresses`: solo los habilitados para despachar
     * (`use_for_shipping`), como `[{id, label}]`. La etiqueta junta nombre y dirección para que
     * el select de la tarjeta se lea sin abrir Zipnova: "Depósito Central · Av. Colón 123,
     * Córdoba (5000)".
     *
     * @param array $addresses
     * @return array
     */
    protected function origenes_desde_addresses(array $addresses)
    {
        $origins = [];

        foreach ($addresses as $address) {
            if (!is_array($address) || !isset($address['id'])) {
                continue;
            }
            if (isset($address['use_for_shipping']) && !filter_var($address['use_for_shipping'], FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $origins[] = [
                'id'    => (int) $address['id'],
                'label' => $this->etiqueta_de_origen($address),
            ];
        }

        return $origins;
    }

    /**
     * "{name} · {street} {street_number}, {city} ({zipcode})", con lo que haya.
     *
     * @param array $address
     * @return string
     */
    protected function etiqueta_de_origen(array $address)
    {
        $texto = function ($valor) {
            return is_scalar($valor) ? trim((string) $valor) : '';
        };

        $city = isset($address['city']) ? $address['city'] : null;
        if (is_array($city)) {
            $city = isset($city['name']) ? $city['name'] : null;
        }

        $calle = trim($texto(isset($address['street']) ? $address['street'] : null) . ' ' . $texto(isset($address['street_number']) ? $address['street_number'] : null));

        $partes = [];
        if ($calle !== '') {
            $partes[] = $calle;
        }
        $localidad = $texto($city);
        $cp = $texto(isset($address['zipcode']) ? $address['zipcode'] : null);
        if ($localidad !== '' || $cp !== '') {
            $partes[] = trim($localidad . ($cp !== '' ? ' (' . $cp . ')' : ''));
        }

        $nombre = $texto(isset($address['name']) ? $address['name'] : null);
        $direccion = implode(', ', $partes);

        if ($nombre !== '' && $direccion !== '') {
            $label = $nombre . ' · ' . $direccion;
        } elseif ($nombre !== '') {
            $label = $nombre;
        } elseif ($direccion !== '') {
            $label = $direccion;
        } else {
            $label = 'Depósito ' . $address['id'];
        }

        return mb_substr($label, 0, self::MAX_ETIQUETA);
    }

    /**
     * Busca un depósito por id en la lista guardada.
     *
     * @param mixed $origins
     * @param mixed $origin_id
     * @return array|null
     */
    protected function origen_por_id($origins, $origin_id)
    {
        if (!is_array($origins) || !is_numeric($origin_id)) {
            return null;
        }

        foreach ($origins as $origen) {
            if (is_array($origen) && isset($origen['id']) && (int) $origen['id'] === (int) $origin_id) {
                return [
                    'id'    => (int) $origen['id'],
                    'label' => isset($origen['label']) ? (string) $origen['label'] : 'Depósito ' . (int) $origen['id'],
                ];
            }
        }

        return null;
    }

    /**
     * Registra el webhook `status`. Devuelve `{id, url}` o null si Zipnova falló (queda en el
     * log; la conexión sigue siendo válida).
     *
     * @param ZipnovaClient $client
     * @param string $account_id
     * @param string $webhook_url
     * @return array|null
     */
    protected function registrar_webhook(ZipnovaClient $client, $account_id, $webhook_url)
    {
        try {
            $respuesta = $client->create_webhook($account_id, self::WEBHOOK_TOPIC, $webhook_url);
        } catch (ZipnovaException $e) {
            Log::warning('ZipnovaConexionService: no se pudo registrar el webhook de la cuenta ' . $account_id . ' en ' . $webhook_url . ': ' . $e->getMessage());

            return null;
        }

        $id = null;
        if (isset($respuesta['id'])) {
            $id = $respuesta['id'];
        } elseif (isset($respuesta['data']['id'])) {
            $id = $respuesta['data']['id'];
        }

        if (is_null($id)) {
            Log::warning('ZipnovaConexionService: Zipnova aceptó el webhook de la cuenta ' . $account_id . ' pero no devolvió un id.');

            return null;
        }

        return ['id' => is_numeric($id) ? (int) $id : (string) $id, 'url' => $webhook_url];
    }

    /**
     * Da de baja un webhook, sin propagar el error: un webhook huérfano en Zipnova solo genera
     * POSTs que este backend responde con 200 y descarta.
     *
     * @param ZipnovaClient $client
     * @param string $account_id
     * @param mixed $webhook_id
     * @return void
     */
    protected function borrar_webhook(ZipnovaClient $client, $account_id, $webhook_id)
    {
        try {
            $client->delete_webhook($account_id, $webhook_id);
        } catch (ZipnovaException $e) {
            Log::warning('ZipnovaConexionService: no se pudo dar de baja el webhook ' . $webhook_id . ' de la cuenta ' . $account_id . ': ' . $e->getMessage());
        }
    }
}
