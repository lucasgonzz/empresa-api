<?php

namespace App\Services\Zipnova;

use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
use App\Models\Envio;
use App\Models\Order;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
 *  - 🔴 Generar es a prueba de doble click. Entre "¿ya tiene envío?" y "guardo lo que devolvió
 *    Zipnova" hay una llamada HTTP de segundos; dos requests (dos clicks, o el modal más la
 *    confirmación automática del pedido) creaban DOS envíos en Zipnova. Ahora la fila se
 *    escribe en `generando` ANTES de salir a Zipnova, dentro de una transacción corta con la
 *    fila del pedido bloqueada (`lockForUpdate`, el mismo candado que usa la confirmación del
 *    pedido contra la doble venta), y el segundo request la ve como un envío vivo.
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

    /** `status_name` propio mientras el request está esperando a Zipnova. */
    const STATUS_NAME_GENERANDO = 'Generando en Zipnova';

    /** `status_name` propio cuando Zipnova ya no encuentra el envío. */
    const STATUS_NAME_NO_ENCONTRADO = 'No encontrado en Zipnova';

    /** `status_name` que se escribe si Zipnova canceló y la sincronización posterior falló. */
    const STATUS_NAME_CANCELADO = 'Cancelado';

    /** Mensaje cuando el comercio no tiene conector de Zipnova. */
    const MENSAJE_SIN_CONECTOR = 'El comercio no tiene Zipnova conectado. Conectalo desde ABM → Integraciones → Tienda online.';

    /**
     * El envío vivo de un pedido, si hay: existe en Zipnova y no está en un estado
     * reemplazable, o se está generando en este momento (`generando` de hace menos de
     * `Envio::MINUTOS_GENERANDO`). Con uno vivo no se genera otro.
     *
     * @param Order $order
     * @return Envio|null
     */
    public static function envio_vivo(Order $order)
    {
        $limite = Carbon::now()->subMinutes(Envio::MINUTOS_GENERANDO);

        return Envio::where('order_id', $order->id)
            ->where(function ($q) use ($limite) {
                $q->where(function ($existe) {
                    // Con id en Zipnova y en un estado que no sea reemplazable (un status null
                    // con id es un envío del que no se sabe nada todavía: cuenta como vivo).
                    $existe->whereNotNull('proveedor_envio_id')
                        ->where(function ($estado) {
                            $estado->whereNull('status')
                                ->orWhereNotIn('status', Envio::ESTADOS_REEMPLAZABLES);
                        });
                })->orWhere(function ($generando) use ($limite) {
                    $generando->where('status', Envio::STATUS_GENERANDO)
                        ->where('updated_at', '>=', $limite);
                });
            })
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * `external_id` del envío: `CC-{user_id}-{order_id}`, más `-2`, `-3`... a partir del
     * segundo intento del mismo pedido (después de una cancelación o de un error). Alfanumérico
     * y guiones, ≤ 30 caracteres.
     *
     * Lleva el comercio porque en las bases compartidas conviven varios y el id del pedido solo
     * no alcanza para reconocerlo desde el panel de Zipnova. Y lleva el intento porque Zipnova
     * indexa por `external_id`: repetir el del envío cancelado (o el de un intento que quedó a
     * medias por un timeout) mezclaría dos envíos bajo el mismo identificador.
     *
     * @param Order $order
     * @param int $intento 1 para el primer envío del pedido, 2 para el siguiente, etc.
     * @return string
     */
    public static function external_id(Order $order, $intento = 1)
    {
        $base = self::EXTERNAL_ID_PREFIJO . (int) $order->user_id . '-' . (int) $order->id;
        $sufijo = (int) $intento > 1 ? '-' . (int) $intento : '';

        if (strlen($base . $sufijo) > self::EXTERNAL_ID_MAX) {
            // El sufijo es lo que distingue los intentos: se sacrifica el final de la base.
            $base = substr($base, 0, self::EXTERNAL_ID_MAX - strlen($sufijo));
        }

        return $base . $sufijo;
    }

    /**
     * Genera el envío del pedido en Zipnova.
     *
     * Precondiciones (cada una con su mensaje para el operador): comercio conectado, opción de
     * envío elegida, destino completo, al menos un artículo que viaje, y ningún envío vivo.
     *
     * Orden de las cosas, y el porqué:
     *  1. Se arma TODO el payload sin tocar la base (ninguna precondición escribe nada).
     *  2. Transacción corta: se bloquea la fila del pedido, se re-chequea que no haya envío vivo
     *     y se deja escrita (y commiteada) la fila en `generando`. Es lo que ve el segundo click.
     *  3. Recién ahí, fuera de la transacción, se sale a Zipnova. Con la respuesta la fila pasa
     *     a lo que devolvió; si Zipnova falla, pasa a `error` con el motivo.
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
            throw new EnvioNoGenerableException(self::motivo_sin_credencial((int) $order->user_id));
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

        $lineas = [];
        foreach ($order->articles as $article) {
            $lineas[] = ['article' => $article, 'amount' => $article->pivot->amount];
        }
        $items = ZipnovaPaquetesHelper::items_desde_lineas($lineas, $config['bulto_default']);
        if (count($items) === 0) {
            throw new EnvioNoGenerableException('Ninguno de los artículos del pedido requiere envío.');
        }

        $declared_value = $config['declarar_valor'] ? round((float) $order->total, 2) : 0;

        $payload = [
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

        $atributos = [
            'user_id'        => $order->user_id,
            'order_id'       => $order->id,
            'sale_id'        => Sale::where('order_id', $order->id)->value('id'),
            'proveedor'      => Envio::PROVEEDOR_ZIPNOVA,
            'proveedor_envio_id' => null,
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
            'status'         => Envio::STATUS_GENERANDO,
            'status_name'    => self::STATUS_NAME_GENERANDO,
            'error_message'  => null,
            'respuesta'      => null,
        ];

        // La fila en `generando` se reserva con el pedido bloqueado. Ver el docblock de la clase.
        $envio = $this->reservar_fila($order, $atributos);

        $payload['external_id'] = $envio->external_id;

        try {
            $respuesta = $client->create_shipment($payload);

            // Un 2xx sin el id del envío no es un envío: sin id no hay etiqueta, ni estado, ni
            // cancelación posible, y la fila quedaría en `generando` hasta abandonarse. Se trata
            // igual que un rechazo, con el body guardado para diagnosticar.
            if (!isset($respuesta['id']) || trim((string) $respuesta['id']) === '') {
                throw new ZipnovaException('Zipnova aceptó el envío pero no devolvió su id. Probá de nuevo en un rato.', 0, $respuesta);
            }
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
     * Deja registrado, en una fila de `envios`, que el envío del pedido no se pudo generar por
     * una precondición (sin conector, destino incompleto, nada que enviar). La usa la
     * confirmación automática del pedido: sin esto el listado diría "Sin generar" sin ningún
     * motivo a la vista, y el operador no sabría qué arreglar.
     *
     * Reutiliza la fila en `error` (o la `generando` abandonada) si la hay, y no escribe nada si
     * el pedido tiene un envío vivo: ese no es un fallo, es un envío.
     *
     * @param Order $order
     * @param string $motivo Mensaje para el operador.
     * @return Envio|null La fila escrita, o null si había un envío vivo.
     */
    public function registrar_fallo(Order $order, $motivo)
    {
        if (!is_null(self::envio_vivo($order))) {
            return null;
        }

        $opcion = is_array($order->envio_opcion) ? $order->envio_opcion : [];

        $envio = $this->fila_para_reintentar($order);
        if (is_null($envio)) {
            $envio = new Envio();
        }

        $envio->fill([
            'user_id'            => $order->user_id,
            'order_id'           => $order->id,
            'sale_id'            => Sale::where('order_id', $order->id)->value('id'),
            'proveedor'          => Envio::PROVEEDOR_ZIPNOVA,
            'proveedor_envio_id' => null,
            'carrier_id'         => self::recortar(isset($opcion['carrier_id']) ? $opcion['carrier_id'] : null, 20),
            'carrier_name'       => self::recortar(isset($opcion['carrier_name']) ? $opcion['carrier_name'] : null, 120),
            'carrier_logo'       => self::recortar(isset($opcion['carrier_logo']) ? $opcion['carrier_logo'] : null, 255),
            'service_type'       => self::recortar(isset($opcion['service_type']) ? $opcion['service_type'] : null, 60),
            'service_name'       => self::recortar(isset($opcion['service_name']) ? $opcion['service_name'] : null, 120),
            'logistic_type'      => self::recortar(isset($opcion['logistic_type']) ? $opcion['logistic_type'] : null, 60),
            'status'             => Envio::STATUS_ERROR,
            'status_name'        => self::STATUS_NAME_ERROR,
            'error_message'      => (string) $motivo,
            'ultima_sincronizacion' => Carbon::now(),
        ]);
        $envio->save();

        return $envio;
    }

    /**
     * Trae el estado actual del envío desde Zipnova (`GET /shipments/{id}`) y lo guarda,
     * resolviendo el cliente del comercio dueño del envío.
     *
     * @param Envio $envio
     * @return Envio
     * @throws EnvioNoGenerableException Si el envío no existe en Zipnova o el comercio no está conectado.
     * @throws ZipnovaException Si Zipnova no respondió (404 incluido, ya marcado en la fila).
     */
    public function sincronizar(Envio $envio)
    {
        if (empty($envio->proveedor_envio_id)) {
            throw new EnvioNoGenerableException('Este envío nunca se generó en Zipnova: no hay nada que sincronizar. Generalo de nuevo.');
        }

        return $this->sincronizar_con($this->client_del_comercio($envio), $envio);
    }

    /**
     * Igual que `sincronizar()` pero con un cliente ya resuelto: el comando de cada 30 minutos
     * arma uno por comercio y lo reutiliza para todos sus envíos, en vez de descifrar el token
     * una vez por envío.
     *
     * Un 404 no es "Zipnova no respondió": es "Zipnova ya no tiene este envío" (lo borraron del
     * panel, o el comercio reconectó con otra cuenta que no lo ve). Se deja la fila en
     * `not_found` con el motivo y la marca de sincronización, para que el comando deje de
     * consultarla y el pedido pueda generar otro envío. No dispara nada más aunque el estado
     * haya cambiado: los avisos al comprador quedan fuera de alcance de esta misión.
     *
     * @param ZipnovaClient $client Cliente del comercio dueño del envío.
     * @param Envio $envio
     * @return Envio
     * @throws EnvioNoGenerableException Si el envío nunca se generó, o si Zipnova ya no lo encuentra (404).
     * @throws ZipnovaException Si Zipnova no respondió.
     */
    public function sincronizar_con(ZipnovaClient $client, Envio $envio)
    {
        if (empty($envio->proveedor_envio_id)) {
            throw new EnvioNoGenerableException('Este envío nunca se generó en Zipnova: no hay nada que sincronizar. Generalo de nuevo.');
        }

        try {
            $respuesta = $client->get_shipment($envio->proveedor_envio_id);
        } catch (ZipnovaException $e) {
            if ($e->getStatus() !== 404) {
                throw $e;
            }

            $envio->status = Envio::STATUS_NO_ENCONTRADO;
            $envio->status_name = self::STATUS_NAME_NO_ENCONTRADO;
            $envio->error_message = 'Zipnova no encuentra el envío N° ' . $envio->proveedor_envio_id . ' (HTTP 404). Si lo borraste desde el panel de Zipnova, generá uno nuevo desde acá.';
            $envio->ultima_sincronizacion = Carbon::now();
            $envio->save();

            Log::warning('ZipnovaEnvioService: Zipnova respondió 404 para el envío ' . $envio->id . ' (Zipnova ' . $envio->proveedor_envio_id . ', user_id ' . $envio->user_id . '); queda en not_found.');

            throw new EnvioNoGenerableException($envio->error_message, 0, $e);
        }

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
     * 🔴 Zipnova responde 401 cuando el envío YA NO se puede cancelar (doc de `/cancel`), el
     * mismo código que usa para credenciales inválidas, y `ZipnovaClient` lo traduce a "no
     * reconoció el token o el secret". Para distinguir los dos casos se sincroniza el envío con
     * las mismas credenciales: si el `GET` anda, el token es válido y el 401 significa "ya
     * salió"; si el `GET` también falla por credenciales, el problema es el token y sube tal
     * cual.
     *
     * Si la sincronización posterior a una cancelación exitosa falla, la cancelación NO se
     * pierde: se deja el estado en `cancelled` cuando Zipnova confirmó `canceled`, y el comando
     * de cada 30 minutos lo termina de emparejar.
     *
     * @param Envio $envio
     * @return Envio
     * @throws EnvioNoGenerableException Si no se puede cancelar (nunca se generó, ya está cerrado, o Zipnova ya no lo permite).
     * @throws ZipnovaException Si Zipnova rechazó la cancelación por otro motivo.
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

        try {
            $resultado = $client->cancel($envio->proveedor_envio_id);
        } catch (ZipnovaException $e) {
            if (!$e->esDeCredenciales()) {
                throw $e;
            }

            try {
                $this->sincronizar_con($client, $envio);
            } catch (ZipnovaException $e_sync) {
                if ($e_sync->esDeCredenciales()) {
                    // El GET tampoco pasa: el 401 era de verdad de credenciales.
                    throw $e;
                }
                Log::warning('ZipnovaEnvioService: el envío ' . $envio->proveedor_envio_id . ' ya no se puede cancelar y tampoco se pudo sincronizar: ' . $e_sync->getMessage());
            }

            if ($envio->esta_cerrado()) {
                // La sincronización trajo la verdad: lo cancelaron (o se cerró) desde el panel.
                $estado = $envio->status_name ? $envio->status_name : $envio->status;

                throw new EnvioNoGenerableException('El envío ya está "' . $estado . '" y no se puede cancelar.');
            }

            throw new EnvioNoGenerableException('Zipnova ya no permite cancelar este envío: ya fue despachado. Si hace falta, pedí el rescate desde el panel de Zipnova.');
        }

        try {
            return $this->sincronizar_con($client, $envio);
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
     * Reserva la fila del envío en `generando`, con el pedido bloqueado, y la devuelve ya
     * commiteada.
     *
     * Adentro de la transacción: `SELECT ... FOR UPDATE` sobre la fila del pedido —serializa
     * los requests concurrentes del mismo pedido: el segundo espera acá hasta el commit del
     * primero—, re-chequeo de envío vivo (ahora sí ve la fila `generando` que el otro dejó),
     * número de intento para el `external_id`, y el `save()`. Afuera queda la llamada a Zipnova,
     * que puede tardar segundos y no tiene por qué sostener un lock sobre el pedido.
     *
     * @param Order $order
     * @param array $atributos Todo lo que ya se sabe del envío (menos `external_id`).
     * @return Envio
     * @throws EnvioNoGenerableException Si otro request ya generó (o está generando) el envío.
     */
    protected function reservar_fila(Order $order, array $atributos)
    {
        return DB::transaction(function () use ($order, $atributos) {
            Order::where('id', $order->id)->lockForUpdate()->first();

            $vivo = self::envio_vivo($order);
            if (!is_null($vivo)) {
                if ((string) $vivo->status === Envio::STATUS_GENERANDO) {
                    throw new EnvioNoGenerableException('El envío de este pedido se está generando en este momento. Esperá unos segundos y actualizá.');
                }

                throw new EnvioNoGenerableException('El pedido ya tiene un envío generado en Zipnova (N° ' . $vivo->proveedor_envio_id . '). Cancelalo antes de generar otro.');
            }

            // Intento N: cuenta TODAS las filas previas del pedido, incluida la que se reutiliza.
            // Así un reintento nunca repite el `external_id` de un intento que pudo haber llegado
            // a Zipnova aunque acá quedara en `error` (un timeout después de crear, por ejemplo).
            $intento = Envio::where('order_id', $order->id)->count() + 1;

            // Un intento anterior que falló (o quedó abandonado) deja su fila: se actualiza esa.
            $envio = $this->fila_para_reintentar($order);
            if (is_null($envio)) {
                $envio = new Envio();
            }

            $atributos['external_id'] = self::external_id($order, $intento);
            $envio->fill($atributos);
            // `updated_at` se pisa a mano: `save()` no lo toca si ningún atributo cambió, y la
            // ventana de `generando` se mide justamente desde acá.
            $envio->updated_at = Carbon::now();
            $envio->save();

            return $envio;
        });
    }

    /**
     * La fila reutilizable del pedido: la que quedó en `error` sin id de Zipnova, o una
     * `generando` abandonada (más vieja que la ventana). Un envío cancelado o no encontrado en
     * Zipnova, con id, NO se reutiliza: es historia y el reintento crea otra fila.
     *
     * @param Order $order
     * @return Envio|null
     */
    protected function fila_para_reintentar(Order $order)
    {
        $limite = Carbon::now()->subMinutes(Envio::MINUTOS_GENERANDO);

        return Envio::where('order_id', $order->id)
            ->whereNull('proveedor_envio_id')
            ->where(function ($q) use ($limite) {
                $q->where('status', Envio::STATUS_ERROR)
                    ->orWhere(function ($abandonada) use ($limite) {
                        $abandonada->where('status', Envio::STATUS_GENERANDO)
                            ->where('updated_at', '<', $limite);
                    });
            })
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
            throw new EnvioNoGenerableException(self::motivo_sin_credencial((int) $envio->user_id));
        }

        return $client;
    }

    /**
     * Por qué no hay credencial usable: no hay conector, o lo hay pero el token no se puede
     * descifrar (APP_KEY cambiada, fila en plano). Son dos arreglos distintos y el mensaje tiene
     * que apuntar al correcto; el segundo usa el mismo texto que la tarjeta de la integración.
     *
     * @param int $user_id
     * @return string
     */
    protected static function motivo_sin_credencial($user_id)
    {
        if (!is_null(ZipnovaCredentialsHelper::connector($user_id))) {
            return ZipnovaConexionService::MENSAJE_CREDENCIAL_ILEGIBLE;
        }

        return self::MENSAJE_SIN_CONECTOR;
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
