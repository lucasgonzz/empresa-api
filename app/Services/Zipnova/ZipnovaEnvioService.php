<?php

namespace App\Services\Zipnova;

use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Models\Envio;
use App\Models\Order;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * El ciclo de vida de un envío en Zipnova a partir de un pedido de la tienda (misión
 * zipnova-envios, 14/9/2026): generarlo, sincronizar su estado, cancelarlo y bajar la etiqueta.
 *
 * Lo que entra es un `Order` que la tienda dejó con `envio_opcion` (lo que eligió el comprador:
 * correo, servicio, forma de despacho) y `envio_destino` (destinatario y dirección). Lo que sale
 * es una fila de `envios` con el id del envío en Zipnova, la guía y el estado.
 *
 * Reglas que valen para todos los métodos:
 *  - Las credenciales salen SIEMPRE del conector del comercio dueño del pedido
 *    (`ZipnovaCredentialsHelper`), nunca del usuario autenticado: el webhook y el comando no
 *    tienen sesión.
 *  - Toda respuesta de Zipnova pasa por `aplicar_respuesta()`, que es el único lugar que sabe
 *    mapear el JSON a columnas. El payload completo queda en `respuesta` para lo que la tabla no
 *    modela.
 *  - Si `POST /shipments` falla, la fila se guarda igual con `status = error` y el motivo
 *    legible: el operador tiene que VER por qué no salió y poder reintentar desde el mismo
 *    botón. El reintento reutiliza esa fila; un envío cancelado en Zipnova, en cambio, queda
 *    como historia y el reintento crea otra.
 *
 * Solo `empresa-api`: la tienda no genera, sincroniza ni cancela envíos.
 */
class ZipnovaEnvioService
{
    /** Prefijo del `external_id` con el que el envío queda identificado del lado de Zipnova. */
    const EXTERNAL_ID_PREFIJO = 'CC-';

    /** Largo máximo del `external_id` que acepta Zipnova. */
    const EXTERNAL_ID_MAX = 30;

    /** `status_name` propio de la fila que nunca se pudo crear. */
    const STATUS_NAME_ERROR = 'No se pudo generar';

    /** `status_name` que se escribe si Zipnova canceló y la sincronización posterior falló. */
    const STATUS_NAME_CANCELADO = 'Cancelado';

    /**
     * El envío vivo de un pedido: existe en Zipnova y no está en un estado reemplazable
     * (`error`, `cancelled`, `expired`). Con uno vivo no se genera otro.
     *
     * @param Order $order
     * @return Envio|null
     */
    public static function envio_vivo(Order $order)
    {
        return Envio::where('order_id', $order->id)
            ->whereNotNull('proveedor_envio_id')
            ->whereNotIn('status', Envio::ESTADOS_REEMPLAZABLES)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * `external_id` del envío: `CC-{user_id}-{order_id}`, alfanumérico y guiones, ≤ 30
     * caracteres. Lleva el comercio porque en las bases compartidas conviven varios y el id del
     * pedido solo no alcanza para reconocerlo desde el panel de Zipnova.
     *
     * @param Order $order
     * @return string
     */
    public static function external_id(Order $order)
    {
        return substr(self::EXTERNAL_ID_PREFIJO . (int) $order->user_id . '-' . (int) $order->id, 0, self::EXTERNAL_ID_MAX);
    }

    /**
     * Genera el envío del pedido en Zipnova.
     *
     * Precondiciones (cada una con su mensaje para el operador): comercio conectado, opción de
     * envío elegida, destino completo, ningún envío vivo, y al menos un artículo que viaje.
     *
     * @param Order $order Pedido con `envio_opcion` y `envio_destino`.
     * @return Envio La fila ya guardada con lo que devolvió Zipnova.
     * @throws EnvioNoGenerableException Si falla una precondición (no se llamó a Zipnova).
     * @throws ZipnovaException Si Zipnova rechazó el envío (la fila queda en `error`).
     */
    public function crear_desde_pedido(Order $order)
    {
        $credentials = ZipnovaCredentialsHelper::credentials((int) $order->user_id);
        if (is_null($credentials['basic'])) {
            throw new EnvioNoGenerableException('El comercio no tiene Zipnova conectado. Conectalo desde ABM → Integraciones → Tienda online.');
        }
        $config = $credentials['config'];
        $client = new ZipnovaClient($credentials['basic'], $credentials['account_id']);

        $opcion = is_array($order->envio_opcion) ? $order->envio_opcion : [];
        if (empty($opcion['service_type']) || !isset($opcion['carrier_id'])) {
            throw new EnvioNoGenerableException('El pedido no tiene una forma de envío de Zipnova elegida.');
        }
        $es_punto_de_retiro = !empty($opcion['es_punto_de_retiro'])
            || (string) $opcion['service_type'] === ZipnovaQuoteNormalizer::SERVICE_PICKUP_POINT;

        $destino = is_array($order->envio_destino) ? $order->envio_destino : [];
        // La sucursal elegida la completa el SPA en la opción; si el destino no la trae, se copia
        // de ahí para que la validación y el payload la vean en un solo lugar.
        if (empty($destino['point_id']) && !empty($opcion['point_id'])) {
            $destino['point_id'] = $opcion['point_id'];
        }
        $destino = EnvioDestinoHelper::normalizar($destino);
        $faltantes = EnvioDestinoHelper::faltantes($destino, $es_punto_de_retiro);
        if (count($faltantes) > 0) {
            throw new EnvioNoGenerableException('Faltan datos del destinatario para generar el envío: ' . implode(', ', $faltantes) . '.');
        }

        $vivo = self::envio_vivo($order);
        if (!is_null($vivo)) {
            throw new EnvioNoGenerableException('El pedido ya tiene un envío generado en Zipnova (N° ' . $vivo->proveedor_envio_id . '). Cancelalo antes de generar otro.');
        }

        $lineas = [];
        foreach ($order->articles as $article) {
            $lineas[] = ['article' => $article, 'amount' => $article->pivot->amount];
        }
        $items = ZipnovaPaquetesHelper::items_desde_lineas($lineas, $config['bulto_default']);
        if (count($items) === 0) {
            throw new EnvioNoGenerableException('Ninguno de los artículos del pedido requiere envío.');
        }

        $declared_value = $config['declarar_valor'] ? round((float) $order->total, 2) : 0;
        $external_id = self::external_id($order);

        $payload = [
            'external_id'    => $external_id,
            'service_type'   => (string) $opcion['service_type'],
            'carrier_id'     => (int) $opcion['carrier_id'],
            // Sin depósito elegido, `auto`: Zipnova despacha desde el que tenga marcado por defecto.
            'origin_id'      => !empty($config['origin_id']) ? (int) $config['origin_id'] : 'auto',
            'declared_value' => $declared_value,
            'destination'    => EnvioDestinoHelper::a_zipnova($destino, $es_punto_de_retiro),
            'items'          => $items,
        ];
        if (!empty($opcion['logistic_type'])) {
            $payload['logistic_type'] = (string) $opcion['logistic_type'];
        }

        // Un intento anterior que falló deja su fila en `error`: se actualiza esa, no se crea otra.
        $envio = $this->fila_para_reintentar($order);
        if (is_null($envio)) {
            $envio = new Envio();
        }

        $envio->fill([
            'user_id'        => $order->user_id,
            'order_id'       => $order->id,
            'sale_id'        => Sale::where('order_id', $order->id)->value('id'),
            'proveedor'      => Envio::PROVEEDOR_ZIPNOVA,
            'external_id'    => $external_id,
            'account_id'     => $credentials['account_id'],
            'carrier_id'     => self::recortar($opcion['carrier_id'], 20),
            'carrier_name'   => self::recortar(isset($opcion['carrier_name']) ? $opcion['carrier_name'] : null, 120),
            'carrier_logo'   => self::recortar(isset($opcion['carrier_logo']) ? $opcion['carrier_logo'] : null, 255),
            'service_type'   => self::recortar($opcion['service_type'], 60),
            'service_name'   => self::recortar(isset($opcion['service_name']) ? $opcion['service_name'] : null, 120),
            'logistic_type'  => self::recortar(isset($opcion['logistic_type']) ? $opcion['logistic_type'] : null, 60),
            'declared_value' => $declared_value,
            'destino'        => $destino,
            'bultos'         => $items,
        ]);

        try {
            $respuesta = $client->create_shipment($payload);
        } catch (ZipnovaException $e) {
            $envio->proveedor_envio_id = null;
            $envio->status = Envio::STATUS_ERROR;
            $envio->status_name = self::STATUS_NAME_ERROR;
            $envio->error_message = $e->getMessage();
            $envio->respuesta = count($e->getBody()) > 0 ? $e->getBody() : null;
            $envio->ultima_sincronizacion = Carbon::now();
            $envio->save();

            Log::warning('ZipnovaEnvioService: no se pudo generar el envío del pedido ' . $order->id . ' (user_id ' . $order->user_id . '): ' . $e->getMessage());

            throw $e;
        }

        $this->aplicar_respuesta($envio, $respuesta);
        $envio->error_message = null;
        $envio->save();

        return $envio;
    }

    /**
     * Trae el estado actual del envío desde Zipnova (`GET /shipments/{id}`) y lo guarda. No
     * dispara nada más aunque el estado haya cambiado: los avisos al comprador quedan fuera de
     * alcance de esta misión.
     *
     * @param Envio $envio
     * @return Envio
     * @throws EnvioNoGenerableException Si el envío no existe en Zipnova o el comercio no está conectado.
     * @throws ZipnovaException Si Zipnova no respondió.
     */
    public function sincronizar(Envio $envio)
    {
        if (empty($envio->proveedor_envio_id)) {
            throw new EnvioNoGenerableException('Este envío nunca se generó en Zipnova: no hay nada que sincronizar. Generalo de nuevo.');
        }

        $client = $this->client_del_comercio($envio);

        $respuesta = $client->get_shipment($envio->proveedor_envio_id);

        $this->aplicar_respuesta($envio, $respuesta);
        $envio->error_message = null;
        $envio->save();

        return $envio;
    }

    /**
     * Pide la cancelación a Zipnova (`POST /shipments/{id}/cancel`) y después sincroniza. Si ya
     * está despachado Zipnova pide el rescate en vez de cancelar (`result: rescue_requested`) y
     * el estado real lo dice la sincronización.
     *
     * Si la sincronización posterior falla, la cancelación NO se pierde: se deja el estado en
     * `cancelled` cuando Zipnova confirmó `canceled`, y el comando de cada 30 minutos lo termina
     * de emparejar.
     *
     * @param Envio $envio
     * @return Envio
     * @throws EnvioNoGenerableException Si no se puede cancelar (nunca se generó o ya está cerrado).
     * @throws ZipnovaException Si Zipnova rechazó la cancelación.
     */
    public function cancelar(Envio $envio)
    {
        if (empty($envio->proveedor_envio_id)) {
            throw new EnvioNoGenerableException('Este envío nunca se generó en Zipnova: no hay nada que cancelar.');
        }
        if ($envio->esta_cerrado()) {
            $estado = $envio->status_name ? $envio->status_name : $envio->status;

            throw new EnvioNoGenerableException('El envío ya está "' . $estado . '" y no se puede cancelar.');
        }

        $client = $this->client_del_comercio($envio);

        $resultado = $client->cancel($envio->proveedor_envio_id);

        try {
            return $this->sincronizar($envio);
        } catch (ZipnovaException $e) {
            Log::warning('ZipnovaEnvioService: el envío ' . $envio->proveedor_envio_id . ' se canceló pero no se pudo sincronizar: ' . $e->getMessage());

            if (isset($resultado['result']) && $resultado['result'] === 'canceled') {
                $envio->status = 'cancelled';
                $envio->status_name = self::STATUS_NAME_CANCELADO;
            }
            $envio->ultima_sincronizacion = Carbon::now();
            $envio->save();

            return $envio;
        }
    }

    /**
     * Bytes del PDF de la etiqueta. Zipnova responde 409 mientras la etiqueta no está lista; la
     * `ZipnovaException` con ese código sube al controller, que la traduce.
     *
     * @param Envio $envio
     * @return string
     * @throws EnvioNoGenerableException Si el envío nunca se generó.
     * @throws ZipnovaException Si Zipnova no la entregó (409 incluido).
     */
    public function etiqueta_pdf(Envio $envio)
    {
        if (empty($envio->proveedor_envio_id)) {
            throw new EnvioNoGenerableException('Este envío nunca se generó en Zipnova: no hay etiqueta.');
        }

        return $this->client_del_comercio($envio)->label_pdf($envio->proveedor_envio_id);
    }

    /**
     * Vuelca en la fila lo que devolvió `POST /shipments` o `GET /shipments/{id}` (misma forma).
     * `service_type` viene como string en el envío (código) y como objeto en la cotización: se
     * aceptan los dos. Los textos se recortan al largo de cada columna para que un nombre largo
     * de Zipnova no tumbe el `save()` con MySQL en modo estricto.
     *
     * @param Envio $envio
     * @param array $r Respuesta de Zipnova.
     * @return void
     */
    public function aplicar_respuesta(Envio $envio, array $r)
    {
        if (isset($r['id'])) {
            $envio->proveedor_envio_id = self::recortar($r['id'], 40);
        }
        if (isset($r['external_id'])) {
            $envio->external_id = self::recortar($r['external_id'], 40);
        }
        if (isset($r['account_id'])) {
            $envio->account_id = self::recortar($r['account_id'], 40);
        }

        if (isset($r['carrier']) && is_array($r['carrier'])) {
            $carrier = $r['carrier'];
            if (isset($carrier['id'])) {
                $envio->carrier_id = self::recortar($carrier['id'], 20);
            }
            if (isset($carrier['name'])) {
                $envio->carrier_name = self::recortar($carrier['name'], 120);
            }
            if (isset($carrier['logo'])) {
                $envio->carrier_logo = self::recortar($carrier['logo'], 255);
            }
        }

        if (isset($r['service_type'])) {
            if (is_array($r['service_type'])) {
                if (isset($r['service_type']['code'])) {
                    $envio->service_type = self::recortar($r['service_type']['code'], 60);
                }
                if (isset($r['service_type']['name'])) {
                    $envio->service_name = self::recortar($r['service_type']['name'], 120);
                }
            } else {
                $envio->service_type = self::recortar($r['service_type'], 60);
            }
        }
        if (isset($r['logistic_type']) && !is_array($r['logistic_type'])) {
            $envio->logistic_type = self::recortar($r['logistic_type'], 60);
        }

        foreach (['status' => 60, 'status_name' => 120, 'substatus_code' => 60, 'substatus_name' => 120] as $campo => $largo) {
            if (array_key_exists($campo, $r)) {
                $envio->{$campo} = self::recortar($r[$campo], $largo);
            }
        }

        if (array_key_exists('tracking', $r)) {
            $envio->tracking_url = self::recortar($r['tracking'], 255);
        }
        if (array_key_exists('tracking_external', $r)) {
            $envio->tracking_external_url = self::recortar($r['tracking_external'], 255);
        }
        if (array_key_exists('carrier_tracking_id', $r)) {
            $envio->carrier_tracking_id = self::recortar($r['carrier_tracking_id'], 120);
        }
        if (array_key_exists('delivery_id', $r)) {
            $envio->delivery_id = self::recortar($r['delivery_id'], 120);
        }

        foreach (['price', 'price_incl_tax', 'declared_value'] as $campo) {
            if (isset($r[$campo]) && is_numeric($r[$campo])) {
                $envio->{$campo} = round((float) $r[$campo], 2);
            }
        }

        if (isset($r['delivery_time']['estimated_delivery'])) {
            $envio->estimated_delivery = self::fecha($r['delivery_time']['estimated_delivery']);
        }

        $envio->respuesta = $r;
        $envio->ultima_sincronizacion = Carbon::now();
    }

    /**
     * La fila en `error` del pedido (el intento anterior que no llegó a Zipnova), si hay.
     *
     * @param Order $order
     * @return Envio|null
     */
    protected function fila_para_reintentar(Order $order)
    {
        return Envio::where('order_id', $order->id)
            ->where('status', Envio::STATUS_ERROR)
            ->whereNull('proveedor_envio_id')
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Cliente de Zipnova del comercio dueño del envío.
     *
     * @param Envio $envio
     * @return ZipnovaClient
     * @throws EnvioNoGenerableException Si el comercio no está conectado.
     */
    protected function client_del_comercio(Envio $envio)
    {
        $client = ZipnovaCredentialsHelper::client((int) $envio->user_id);

        if (is_null($client)) {
            throw new EnvioNoGenerableException('El comercio no tiene Zipnova conectado. Conectalo desde ABM → Integraciones → Tienda online.');
        }

        return $client;
    }

    /**
     * Fecha ISO 8601 de Zipnova (con zona, `2026-09-18T23:59:59+00:00`) a Carbon en la zona de
     * la app, o null si no se puede leer.
     *
     * @param mixed $valor
     * @return Carbon|null
     */
    protected static function fecha($valor)
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Escalar a string recortado al largo de la columna, o null.
     *
     * @param mixed $valor
     * @param int $largo
     * @return string|null
     */
    protected static function recortar($valor, $largo)
    {
        if (is_null($valor) || !is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }
}
