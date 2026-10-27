<?php

namespace App\Services\MercadoPago;

use App\Http\Controllers\Helpers\MercadoPagoCredentialsHelper;
use App\Models\Address;
use App\Models\Caja;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\MercadoPagoQrCaja;
use App\Models\MercadoPagoQrCobro;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cobro con el QR de Mercado Pago en la pantalla de Vender (misión cobro-qr-mercado-pago,
 * 26/10/2026). Toda la conversación con Mercado Pago del módulo vive acá; el controller
 * (`MercadoPagoQrController`), el comando (`mercadopago:qr-conciliar`) y `SaleController@store`
 * solo llaman a estos métodos.
 *
 * CÓMO FUNCIONA EN MERCADO PAGO (doc oficial relevada el 26/10/2026, API "Orders"; la vieja
 * `instore/...` está deprecada):
 *
 * 1. Una vez, en la configuración: se crea una SUCURSAL (`POST /users/{mp_user_id}/stores`) y
 *    adentro una CAJA (`POST /v2/pos`). La caja trae un QR ESTÁTICO (`qr_response`) que el
 *    comercio imprime y pega en el mostrador.
 * 2. En cada venta: se le carga el total a esa caja con una ORDEN (`POST /v1/orders`,
 *    `type: qr`, `mode: static`). El cliente escanea el QR del mostrador y su app de MP le
 *    muestra ese total para pagar.
 * 3. Mientras el cajero espera, el SPA consulta la orden (`GET /v1/orders/{id}`) cada pocos
 *    segundos. Cuando MP la da por `processed`, el cobro pasa a `pagado` y el SPA guarda la venta
 *    con `mercado_pago_qr_cobro_id`. `SaleController@store` valida ese cobro y lo ata a la venta.
 *
 * SIN WEBHOOKS, a propósito: con Orders el webhook se configura una sola vez en el panel de la
 * APP (una URL para todos los comercios), y repartir esos avisos entre las instancias de cada
 * cliente necesita un relay en el admin, que es otra misión. Mientras tanto: polling del SPA +
 * el comando que concilia lo pendiente cada minuto.
 *
 * CREDENCIALES: el access token OAuth del comercio (`MercadoPagoCredentialsHelper`) y la cuenta
 * de MP de su conector (`platform_user_id`). 🔴 El token NUNCA se loguea ni se devuelve, y
 * tampoco las respuestas crudas de MP: a los logs va el status y el código de error, y al SPA un
 * texto legible.
 *
 * QR ATADO A LA CUENTA: cada QR guarda la cuenta de MP con la que se creó (`mp_user_id`). Si el
 * comercio conecta otra cuenta, los QR viejos quedan inactivos y nunca se usan para cobrar: el
 * token nuevo no puede cargarle órdenes a una caja de la cuenta vieja, y si pudiera, la plata
 * iría a parar a otro lado.
 *
 * PHP 7.4: sin match, ?->, str_contains, argumentos nombrados ni union types.
 */
class MercadoPagoQrService
{
    /** Base de la API de Mercado Pago. */
    const API_BASE = 'https://api.mercadopago.com';

    /**
     * Timeout de cada llamada a MP, en segundos. El cajero está esperando con el cliente
     * adelante: más de esto y es mejor decirle que no anduvo que dejarlo colgado.
     */
    const TIMEOUT_SEGUNDOS = 15;

    /**
     * Vencimiento de la orden en MP (ISO 8601). Si el cliente no paga en 10 minutos la orden
     * expira sola y el QR del mostrador queda libre.
     */
    const VENCIMIENTO_ORDEN = 'PT10M';

    /**
     * Horas después de las cuales un cobro que sigue `pendiente` se da por vencido localmente.
     * La orden en MP vence a los 10 minutos; las 3 horas son el margen para que el comando la
     * consulte aunque el cron haya estado caído un rato.
     */
    const HORAS_DE_VIGENCIA = 3;

    /** Tolerancia de un centavo al comparar el total del cobro con el de la venta. */
    const TOLERANCIA_TOTAL = 0.01;

    /** Id del catálogo que se usa como método de pago del QR si no hay uno llamado "Mercado Pago". */
    const METODO_DE_PAGO_DE_RESPALDO = 6;

    /** Máximo de cobros que devuelve el listado de la tarjeta de configuración. */
    const LIMITE_MAXIMO_DE_COBROS = 100;

    // =========================================================================================
    // Conexión y configuración
    // =========================================================================================

    /**
     * Datos para hablar con Mercado Pago en nombre del comercio, o null si no está conectado.
     *
     * "Conectado" acá es más estricto que en el resto del sistema: hace falta el CONECTOR OAuth
     * conectado y con su `platform_user_id`, porque el QR se crea y se valida contra esa cuenta.
     * Una credencial cargada a mano en `payment_methods` (el fallback viejo del helper) sirve para
     * la tienda, pero no dice de qué cuenta es, así que no alcanza para el QR.
     *
     * @param int $owner_id Comercio (owner).
     * @return array{connector: PlatformConnector, token: string, mp_user_id: string}|null
     */
    public function conexion($owner_id)
    {
        $owner_id = (int) $owner_id;

        $connector = PlatformConnector::find_for_user_and_slug($owner_id, Platform::SLUG_MERCADO_PAGO);

        if (!$connector || !$connector->is_connected() || empty($connector->platform_user_id)) {
            return null;
        }

        $token = MercadoPagoCredentialsHelper::access_token($owner_id);

        if (empty($token)) {
            return null;
        }

        return [
            'connector'  => $connector,
            'token'      => $token,
            'mp_user_id' => (string) $connector->platform_user_id,
        ];
    }

    /**
     * Igual que `conexion()`, pero sin conexión corta con el 422 del contrato (`sin_conexion`).
     *
     * @param int $owner_id
     * @return array
     * @throws MercadoPagoQrException
     */
    protected function conexion_o_falla($owner_id)
    {
        $conexion = $this->conexion($owner_id);

        if (is_null($conexion)) {
            throw new MercadoPagoQrException(
                'El comercio no tiene una cuenta de Mercado Pago conectada. Conectala en ABM → Integraciones.',
                422,
                ['sin_conexion' => true]
            );
        }

        return $conexion;
    }

    /**
     * Método de pago con el que se registran las ventas cobradas por QR.
     *
     * Primero el que el comercio eligió (`extra_config['qr']` del conector de MP), siempre que
     * siga existiendo en el catálogo; si no eligió ninguno (o lo borraron), el default.
     *
     * @param int $owner_id Comercio (owner).
     * @param PlatformConnector|null $connector El conector, si el llamador ya lo tiene a mano.
     * @return int|null
     */
    public function metodo_de_pago_configurado($owner_id, $connector = null)
    {
        if (is_null($connector)) {
            $connector = PlatformConnector::find_for_user_and_slug((int) $owner_id, Platform::SLUG_MERCADO_PAGO);
        }

        if ($connector) {
            $extra = $connector->extra_config;

            if (is_array($extra)
                && isset($extra['qr'])
                && is_array($extra['qr'])
                && !empty($extra['qr']['current_acount_payment_method_id'])) {

                $id = (int) $extra['qr']['current_acount_payment_method_id'];

                if (CurrentAcountPaymentMethod::where('id', $id)->exists()) {
                    return $id;
                }
            }
        }

        return $this->metodo_de_pago_default();
    }

    /**
     * Método de pago por defecto del QR: el del catálogo cuyo nombre, en minúsculas y sin
     * espacios, sea "mercadopago" (la base real tiene "Mercado Pago", pero hay instalaciones con
     * "MercadoPago" o "mercado pago"); si no hay, el id 6 (el que siembra el catálogo para
     * Mercado Pago); si tampoco existe, null y el comercio lo elige a mano.
     *
     * @return int|null
     */
    public function metodo_de_pago_default()
    {
        $metodos = CurrentAcountPaymentMethod::select('id', 'name')->orderBy('id')->get();

        foreach ($metodos as $metodo) {
            $normalizado = preg_replace('/\s+/u', '', mb_strtolower((string) $metodo->name));

            if ($normalizado === 'mercadopago') {
                return (int) $metodo->id;
            }
        }

        foreach ($metodos as $metodo) {
            if ((int) $metodo->id === self::METODO_DE_PAGO_DE_RESPALDO) {
                return self::METODO_DE_PAGO_DE_RESPALDO;
            }
        }

        return null;
    }

    /**
     * Guarda el método de pago del QR en `extra_config['qr']` del conector de MP, sin pisar el
     * resto de `extra_config` (ni otras claves de `qr`). Es configuración no secreta y sobrevive
     * al disconnect (`mark_disconnected` no toca `extra_config`), así que al reconectar el
     * comercio no tiene que volver a elegirlo.
     *
     * @param int $owner_id Comercio (owner).
     * @param mixed $current_acount_payment_method_id Id del catálogo `current_acount_payment_methods`.
     * @return int El id guardado.
     * @throws MercadoPagoQrException 422 si el id no existe o no hay plataforma de MP.
     */
    public function guardar_metodo_de_pago($owner_id, $current_acount_payment_method_id)
    {
        $id = is_numeric($current_acount_payment_method_id) ? (int) $current_acount_payment_method_id : 0;

        if ($id <= 0 || !CurrentAcountPaymentMethod::where('id', $id)->exists()) {
            throw new MercadoPagoQrException('El método de pago elegido no existe.', 422);
        }

        $connector = PlatformConnector::find_or_create_for_user_and_slug((int) $owner_id, Platform::SLUG_MERCADO_PAGO);

        if (!$connector) {
            // Solo pasa si falta la fila `mercado_pago` en `platforms` (base armada a mano).
            throw new MercadoPagoQrException('Mercado Pago no está disponible en esta instalación.', 422);
        }

        $extra = is_array($connector->extra_config) ? $connector->extra_config : [];
        $qr    = (isset($extra['qr']) && is_array($extra['qr'])) ? $extra['qr'] : [];

        $qr['current_acount_payment_method_id'] = $id;
        $extra['qr'] = $qr;

        // El cast `array` necesita que se reasigne el array entero para detectar el cambio.
        $connector->extra_config = $extra;
        $connector->save();

        return $id;
    }

    // =========================================================================================
    // QR del mostrador (sucursal + caja en Mercado Pago)
    // =========================================================================================

    /**
     * QR del comercio, en la forma `QrCaja` del contrato, con `activo` según la cuenta
     * conectada hoy.
     *
     * @param int $owner_id
     * @param string|null $mp_user_id_conectado Cuenta de MP conectada (null = ninguna).
     * @return array
     */
    public function listar_qrs($owner_id, $mp_user_id_conectado)
    {
        $qrs = MercadoPagoQrCaja::where('user_id', (int) $owner_id)
            ->whereNotNull('mp_pos_id')
            ->withAll()
            ->orderBy('id')
            ->get();

        $resultado = [];

        foreach ($qrs as $qr) {
            $resultado[] = $this->serializar_qr($qr, $mp_user_id_conectado);
        }

        return $resultado;
    }

    /**
     * Forma `QrCaja` del contrato con el SPA:
     * `{id, name, caja_id, caja_name, address_id, address_name, qr_image_url, qr_template_url,
     * qr_code, activo}`.
     *
     * Se arma a mano (y no con el `toArray()` del modelo) para que no viajen los ids internos de
     * Mercado Pago ni la cuenta: el SPA no los necesita.
     *
     * @param MercadoPagoQrCaja $qr
     * @param string|null $mp_user_id_conectado
     * @return array
     */
    public function serializar_qr(MercadoPagoQrCaja $qr, $mp_user_id_conectado)
    {
        $caja    = $qr->caja;
        $address = $qr->address;

        return [
            'id'              => (int) $qr->id,
            'name'            => $qr->name,
            'caja_id'         => is_null($qr->caja_id) ? null : (int) $qr->caja_id,
            'caja_name'       => $caja ? $caja->name : null,
            'address_id'      => is_null($qr->address_id) ? null : (int) $qr->address_id,
            'address_name'    => $address ? $this->nombre_de_sucursal($address) : null,
            'qr_image_url'    => $qr->qr_image_url,
            'qr_template_url' => $qr->qr_template_url,
            'qr_code'         => $qr->qr_code,
            'activo'          => !empty($mp_user_id_conectado) && (string) $qr->mp_user_id === (string) $mp_user_id_conectado,
        ];
    }

    /**
     * Nombre legible de una sucursal. `addresses` no tiene columna de nombre: la sucursal se
     * llama por su calle ("Sucursal Centro" en el fixture, "Av. Mitre 1234" en un cliente real),
     * que es lo mismo que muestra el resto del sistema.
     *
     * @param Address $address
     * @return string
     */
    protected function nombre_de_sucursal(Address $address)
    {
        return trim(trim((string) $address->street) . ' ' . trim((string) $address->street_number));
    }

    /**
     * Crea un QR para el mostrador: la sucursal en MP (o reusa la que ya existe para esa misma
     * sucursal y cuenta) y la caja en MP, y guarda la fila con la imagen y el PDF para imprimir.
     *
     * Orden de las cosas, y por qué:
     * 1. Se valida todo lo local ANTES de llamar a MP, para no dejar sucursales huérfanas en la
     *    cuenta del comercio por un dato que se podía rechazar acá.
     * 2. La fila se inserta antes de crear la caja en MP porque el `external_id` de la caja lleva
     *    el id de la fila. Mientras `mp_pos_id` está en null la fila no cuenta para nada (ni para
     *    listar ni para cobrar), y si MP rechaza la caja se borra del todo (`forceDelete`).
     * 3. Si MP dice que la caja ya existe (409 `pos_already_exists`: un reintento después de un
     *    timeout, con el mismo external_id), se busca por `external_id` y se usa esa.
     *
     * @param int $owner_id Comercio (owner).
     * @param array $datos `{name, caja_id?, address_id?, street_name, street_number, city_name,
     *                     state_name, latitude?, longitude?, reference?}`.
     * @return MercadoPagoQrCaja La fila creada, con `caja` y `address` cargadas.
     * @throws MercadoPagoQrException 422 con el texto del problema (o el de MP).
     */
    public function crear_qr($owner_id, array $datos)
    {
        $owner_id = (int) $owner_id;
        $conexion = $this->conexion_o_falla($owner_id);
        $datos    = $this->validar_datos_del_qr($owner_id, $datos);

        $this->verificar_que_no_haya_otro_qr($owner_id, $conexion['mp_user_id'], $datos['caja_id'], $datos['address_id']);

        $sucursal = $this->sucursal_en_mp($owner_id, $conexion, $datos);

        $qr = MercadoPagoQrCaja::create([
            'user_id'              => $owner_id,
            'name'                 => $datos['name'],
            'caja_id'              => $datos['caja_id'],
            'address_id'           => $datos['address_id'],
            'mp_user_id'           => $conexion['mp_user_id'],
            'mp_store_id'          => $sucursal['id'],
            'mp_store_external_id' => $sucursal['external_id'],
            // Provisorio: el definitivo lleva el id de esta misma fila (ver abajo).
            'mp_pos_external_id'   => '',
        ]);

        // Solo letras y números, ≤ 40 y único por cuenta de MP. Se genera una vez y se guarda.
        $qr->mp_pos_external_id = 'CC' . $owner_id . 'P' . $qr->id . Str::random(6);
        $qr->save();

        try {
            $caja_mp = $this->caja_en_mp($conexion, $qr, $sucursal['external_id']);
        } catch (\Throwable $e) {
            $qr->forceDelete();
            throw $e;
        }

        $qr_response = $this->qr_de_la_caja($caja_mp);

        $qr->mp_pos_id       = (string) $caja_mp['id'];
        $qr->qr_image_url    = $qr_response['image'];
        $qr->qr_template_url = $qr_response['template_document'];
        $qr->qr_code         = $qr_response['qr_code'];
        $qr->save();

        Log::info('MercadoPagoQrService: QR ' . $qr->id . ' creado para el comercio ' . $owner_id . ' (caja MP ' . $qr->mp_pos_id . ').');

        return MercadoPagoQrCaja::withAll()->find($qr->id);
    }

    /**
     * Normaliza y valida los datos del formulario de "Crear QR". Los mensajes son en castellano
     * y vuelven como 422 `{message}`, igual que los rechazos de MP.
     *
     * @param int $owner_id
     * @param array $datos
     * @return array Los datos normalizados (`caja_id` / `address_id` en null si no vinieron).
     * @throws MercadoPagoQrException
     */
    protected function validar_datos_del_qr($owner_id, array $datos)
    {
        $texto = function ($clave, $maximo) use ($datos) {
            if (!isset($datos[$clave]) || is_array($datos[$clave])) {
                return '';
            }

            return mb_substr(trim((string) $datos[$clave]), 0, $maximo);
        };

        $limpio = [
            'name'          => $texto('name', 120),
            'street_name'   => $texto('street_name', 120),
            'street_number' => $texto('street_number', 20),
            'city_name'     => $texto('city_name', 120),
            'state_name'    => $texto('state_name', 120),
            'reference'     => $texto('reference', 120),
            'caja_id'       => (isset($datos['caja_id']) && is_numeric($datos['caja_id']) && (int) $datos['caja_id'] > 0) ? (int) $datos['caja_id'] : null,
            'address_id'    => (isset($datos['address_id']) && is_numeric($datos['address_id']) && (int) $datos['address_id'] > 0) ? (int) $datos['address_id'] : null,
            'latitude'      => (isset($datos['latitude']) && is_numeric($datos['latitude'])) ? (float) $datos['latitude'] : null,
            'longitude'     => (isset($datos['longitude']) && is_numeric($datos['longitude'])) ? (float) $datos['longitude'] : null,
            'address'       => null,
        ];

        if ($limpio['name'] === '') {
            throw new MercadoPagoQrException('Poné un nombre para el QR (por ejemplo, "Mostrador").', 422);
        }

        if ($limpio['street_name'] === '' || $limpio['street_number'] === '' || $limpio['city_name'] === '' || $limpio['state_name'] === '') {
            throw new MercadoPagoQrException('Mercado Pago pide la dirección completa: calle, número, ciudad y provincia.', 422);
        }

        if (!is_null($limpio['caja_id'])
            && !Caja::where('id', $limpio['caja_id'])->where('user_id', $owner_id)->exists()) {
            throw new MercadoPagoQrException('La caja elegida no existe.', 422);
        }

        if (!is_null($limpio['address_id'])) {
            $address = Address::where('id', $limpio['address_id'])->where('user_id', $owner_id)->first();

            if (!$address) {
                throw new MercadoPagoQrException('La sucursal elegida no existe.', 422);
            }

            $limpio['address'] = $address;

            // Si el formulario no mandó coordenadas, se usan las de la sucursal (si tiene).
            if (is_null($limpio['latitude']) && is_numeric($address->lat)) {
                $limpio['latitude'] = (float) $address->lat;
            }

            if (is_null($limpio['longitude']) && is_numeric($address->lng)) {
                $limpio['longitude'] = (float) $address->lng;
            }
        }

        return $limpio;
    }

    /**
     * Rechaza un segundo QR para el mismo lugar: la misma caja, o —sin caja— la misma sucursal, o
     * —sin caja ni sucursal— el "General". Dos QR para el mismo lugar harían ambigua la
     * resolución al cobrar.
     *
     * Solo mira los QR de la cuenta de MP conectada HOY: uno de una cuenta vieja está inactivo y
     * no compite, y exigir borrarlo antes de crear el nuevo sería un paso de más para el comercio
     * que cambió de cuenta.
     *
     * @param int $owner_id
     * @param string $mp_user_id
     * @param int|null $caja_id
     * @param int|null $address_id
     * @return void
     * @throws MercadoPagoQrException
     */
    protected function verificar_que_no_haya_otro_qr($owner_id, $mp_user_id, $caja_id, $address_id)
    {
        $query = MercadoPagoQrCaja::where('user_id', $owner_id)
            ->where('mp_user_id', $mp_user_id)
            ->whereNotNull('mp_pos_id');

        if (!is_null($caja_id)) {
            $query->where('caja_id', $caja_id);
            $mensaje = 'Ya hay un QR para esa caja. Borralo antes de crear otro.';
        } elseif (!is_null($address_id)) {
            $query->whereNull('caja_id')->where('address_id', $address_id);
            $mensaje = 'Ya hay un QR sin caja para esa sucursal. Borralo antes de crear otro.';
        } else {
            $query->whereNull('caja_id')->whereNull('address_id');
            $mensaje = 'Ya hay un QR General. Borralo antes de crear otro.';
        }

        if ($query->exists()) {
            throw new MercadoPagoQrException($mensaje, 422);
        }
    }

    /**
     * Sucursal de Mercado Pago para el QR: la que ya se creó para esta misma sucursal de
     * ComercioCity y esta misma cuenta (aunque su QR se haya borrado: la sucursal sigue viva en
     * MP), o una nueva.
     *
     * Reusar evita que cada QR de la misma sucursal deje una sucursal repetida en el panel de MP
     * del comercio.
     *
     * @param int $owner_id
     * @param array $conexion
     * @param array $datos Ya validados.
     * @return array{id: string|null, external_id: string}
     * @throws MercadoPagoQrException
     */
    protected function sucursal_en_mp($owner_id, array $conexion, array $datos)
    {
        $query = MercadoPagoQrCaja::withTrashed()
            ->where('user_id', $owner_id)
            ->where('mp_user_id', $conexion['mp_user_id'])
            ->whereNotNull('mp_pos_id');

        if (is_null($datos['address_id'])) {
            $query->whereNull('address_id');
        } else {
            $query->where('address_id', $datos['address_id']);
        }

        $existente = $query->orderBy('id', 'DESC')->first();

        if ($existente && !empty($existente->mp_store_external_id)) {
            return [
                'id'          => $existente->mp_store_id,
                'external_id' => $existente->mp_store_external_id,
            ];
        }

        // Alfanumérico, ≤ 60 y SIN guiones (MP los rechaza en el external_id de la sucursal).
        $external_id = 'CC' . $owner_id . 'S' . (is_null($datos['address_id']) ? 0 : $datos['address_id']) . Str::random(6);

        $location = [
            'street_number' => $datos['street_number'],
            'street_name'   => $datos['street_name'],
            'city_name'     => $datos['city_name'],
            'state_name'    => $datos['state_name'],
        ];

        if (!is_null($datos['latitude'])) {
            $location['latitude'] = $datos['latitude'];
        }

        if (!is_null($datos['longitude'])) {
            $location['longitude'] = $datos['longitude'];
        }

        if ($datos['reference'] !== '') {
            $location['reference'] = $datos['reference'];
        }

        $nombre = $datos['address'] ? $this->nombre_de_sucursal($datos['address']) : '';

        if ($nombre === '') {
            $nombre = $datos['name'];
        }

        $respuesta = $this->llamar($conexion['token'], 'POST', '/users/' . rawurlencode($conexion['mp_user_id']) . '/stores', [
            'json' => [
                'name'        => mb_substr($nombre, 0, 120),
                'external_id' => $external_id,
                'location'    => $location,
            ],
        ]);

        if ($respuesta['ok'] && isset($respuesta['json']['id'])) {
            return [
                'id'          => (string) $respuesta['json']['id'],
                'external_id' => $external_id,
            ];
        }

        /*
         * Red de abajo para un reintento: si la sucursal en realidad se creó (timeout de la
         * respuesta, no del pedido) MP rechaza el external_id repetido. Se busca por external_id
         * antes de darse por vencido. Con un external_id recién sorteado lo normal es que no haya
         * nada y se devuelva el error de MP.
         */
        $busqueda = $this->llamar($conexion['token'], 'GET', '/users/' . rawurlencode($conexion['mp_user_id']) . '/stores/search', [
            'query' => ['external_id' => $external_id],
        ]);

        if ($busqueda['ok'] && !empty($busqueda['json']['results'][0]['id'])) {
            return [
                'id'          => (string) $busqueda['json']['results'][0]['id'],
                'external_id' => $external_id,
            ];
        }

        $this->log_error_de_mp('crear sucursal', $owner_id, $respuesta);

        throw new MercadoPagoQrException($this->mensaje_de_error('Mercado Pago no dejó crear la sucursal', $respuesta), 422);
    }

    /**
     * Crea la caja en Mercado Pago (la que trae el QR). Si ya existe (409 `pos_already_exists`),
     * la busca por `external_id` y devuelve esa.
     *
     * @param array $conexion
     * @param MercadoPagoQrCaja $qr Con su `mp_pos_external_id` definitivo ya guardado.
     * @param string $store_external_id
     * @return array La caja según MP (con `id` y `qr_response`).
     * @throws MercadoPagoQrException
     */
    protected function caja_en_mp(array $conexion, MercadoPagoQrCaja $qr, $store_external_id)
    {
        $respuesta = $this->llamar($conexion['token'], 'POST', '/v2/pos', [
            'idempotency_key' => (string) Str::uuid(),
            'json'            => [
                'name'              => mb_substr($qr->name, 0, 120),
                'external_store_id' => $store_external_id,
                'external_id'       => $qr->mp_pos_external_id,
                'config'            => [
                    'qr' => ['operating_mode' => 'pdv'],
                ],
            ],
        ]);

        if ($respuesta['ok'] && isset($respuesta['json']['id'])) {
            return $respuesta['json'];
        }

        if (in_array('pos_already_exists', $this->codigos_de_error($respuesta['json']), true)) {

            $busqueda = $this->llamar($conexion['token'], 'GET', '/v2/pos', [
                'query' => ['external_id' => $qr->mp_pos_external_id],
            ]);

            $existente = $this->primera_caja_de_la_busqueda($busqueda['json'], $qr->mp_pos_external_id);

            if ($busqueda['ok'] && !is_null($existente)) {
                return $existente;
            }
        }

        $this->log_error_de_mp('crear caja', $qr->user_id, $respuesta);

        throw new MercadoPagoQrException($this->mensaje_de_error('Mercado Pago no dejó crear la caja del QR', $respuesta), 422);
    }

    /**
     * Primera caja de una búsqueda `GET /v2/pos?external_id=`. MP la devuelve en `data[]` (v2) o
     * en `results[]` (forma vieja); se aceptan las dos y se prefiere la del external_id pedido.
     *
     * @param array $json
     * @param string $external_id
     * @return array|null
     */
    protected function primera_caja_de_la_busqueda(array $json, $external_id)
    {
        $lista = [];

        if (isset($json['data']) && is_array($json['data'])) {
            $lista = $json['data'];
        } elseif (isset($json['results']) && is_array($json['results'])) {
            $lista = $json['results'];
        }

        $primera = null;

        foreach ($lista as $caja) {
            if (!is_array($caja) || !isset($caja['id'])) {
                continue;
            }

            if (isset($caja['external_id']) && (string) $caja['external_id'] === (string) $external_id) {
                return $caja;
            }

            if (is_null($primera)) {
                $primera = $caja;
            }
        }

        return $primera;
    }

    /**
     * Imagen, PDF para imprimir y contenido del QR de una caja de MP. La v2 los trae en
     * `qr_response`; la forma vieja de `/pos`, en `qr`. Se aceptan las dos.
     *
     * @param array $caja_mp
     * @return array{image: string|null, template_document: string|null, qr_code: string|null}
     */
    protected function qr_de_la_caja(array $caja_mp)
    {
        $qr = [];

        if (isset($caja_mp['qr_response']) && is_array($caja_mp['qr_response'])) {
            $qr = $caja_mp['qr_response'];
        } elseif (isset($caja_mp['qr']) && is_array($caja_mp['qr'])) {
            $qr = $caja_mp['qr'];
        }

        return [
            'image'             => isset($qr['image']) ? (string) $qr['image'] : null,
            'template_document' => isset($qr['template_document']) ? (string) $qr['template_document'] : null,
            'qr_code'           => isset($qr['qr_code']) ? (string) $qr['qr_code'] : null,
        ];
    }

    /**
     * Borra un QR: la caja en MP (`DELETE /v2/pos/{id}`) y la fila, con soft delete.
     *
     * Si MP falla (sin conexión, la caja ya no existe, cambió la cuenta) se borra igual acá y se
     * deja dicho en el log: el comercio quiere sacarlo de la lista, y una caja que quedó viva en MP
     * no le hace daño a nadie (sin órdenes cargadas no cobra nada). La sucursal de MP no se toca:
     * puede tener otras cajas, y `sucursal_en_mp()` la reusa si se crea otro QR en el mismo lugar.
     *
     * @param int $owner_id
     * @param int $qr_id
     * @return void
     * @throws MercadoPagoQrException 404 si el QR no es del comercio.
     */
    public function eliminar_qr($owner_id, $qr_id)
    {
        $qr = MercadoPagoQrCaja::where('user_id', (int) $owner_id)->where('id', (int) $qr_id)->first();

        if (!$qr) {
            throw new MercadoPagoQrException('El QR no existe.', 404);
        }

        $conexion = $this->conexion($owner_id);

        if (!empty($qr->mp_pos_id) && !is_null($conexion) && (string) $conexion['mp_user_id'] === (string) $qr->mp_user_id) {

            $respuesta = $this->llamar($conexion['token'], 'DELETE', '/v2/pos/' . rawurlencode($qr->mp_pos_id), [
                'idempotency_key' => (string) Str::uuid(),
            ]);

            if (!$respuesta['ok']) {
                $this->log_error_de_mp('borrar caja (se borra igual en ComercioCity)', $owner_id, $respuesta);
            }
        } else {
            Log::info('MercadoPagoQrService: QR ' . $qr->id . ' borrado sin tocar Mercado Pago (sin conexión o de otra cuenta).');
        }

        $qr->delete();
    }

    /**
     * Elige el QR con el que se cobra una venta, solo entre los de la cuenta de MP conectada hoy:
     *
     * 1. el de la caja de la venta (si tiene caja);
     * 2. si no, el de su sucursal sin caja;
     * 3. si no, el General (sin caja ni sucursal);
     * 4. si no, el único que haya;
     * 5. si no, 422 `{message, sin_qr: true}`.
     *
     * El paso 4 es para el comercio que creó un solo QR para "Caja 1" y vende desde otra caja: con
     * un solo QR en el mostrador no hay ambigüedad posible.
     *
     * @param int $owner_id
     * @param int|null $caja_id Caja de la venta (0/null = sin caja).
     * @param int|null $address_id Sucursal de la venta (0/null = sin sucursal).
     * @param string $mp_user_id Cuenta de MP conectada.
     * @return MercadoPagoQrCaja
     * @throws MercadoPagoQrException
     */
    public function resolver_qr($owner_id, $caja_id, $address_id, $mp_user_id)
    {
        $base = MercadoPagoQrCaja::where('user_id', (int) $owner_id)
            ->where('mp_user_id', (string) $mp_user_id)
            ->whereNotNull('mp_pos_id');

        $caja_id    = (int) $caja_id;
        $address_id = (int) $address_id;

        if ($caja_id > 0) {
            $qr = (clone $base)->where('caja_id', $caja_id)->orderBy('id', 'DESC')->first();

            if ($qr) {
                return $qr;
            }
        }

        if ($address_id > 0) {
            $qr = (clone $base)->whereNull('caja_id')->where('address_id', $address_id)->orderBy('id', 'DESC')->first();

            if ($qr) {
                return $qr;
            }
        }

        $qr = (clone $base)->whereNull('caja_id')->whereNull('address_id')->orderBy('id', 'DESC')->first();

        if ($qr) {
            return $qr;
        }

        $todos = (clone $base)->limit(2)->get();

        if ($todos->count() === 1) {
            return $todos->first();
        }

        throw new MercadoPagoQrException(
            'No hay un QR de Mercado Pago configurado para esta caja.',
            422,
            ['sin_qr' => true]
        );
    }

    // =========================================================================================
    // Cobros
    // =========================================================================================

    /**
     * Carga el total de la venta en el QR del mostrador: resuelve el QR, cancela en MP los cobros
     * pendientes anteriores de ese mismo QR y crea la orden.
     *
     * Por qué se cancelan los anteriores: el QR es estático y tiene UNA orden a la vez. Si quedó
     * una vieja colgada (el cajero cerró el modal, se cortó la luz), el cliente que escanea podría
     * pagar el total de otra venta. Si MP contesta que el cliente está pagando esa vieja justo
     * ahora, no se pisa: 422 con `pagando: true` y el cajero espera.
     *
     * Si MP rechaza la orden nueva, el cobro queda en `error` (con el motivo legible) y se
     * responde 422 con ese mismo texto.
     *
     * @param int $owner_id Comercio (owner).
     * @param int|null $employee_id Usuario autenticado (dueño o empleado) que cobra.
     * @param array $datos `{total, caja_id?, address_id?, description?}`.
     * @return MercadoPagoQrCobro Con `qr` y `sale` cargadas.
     * @throws MercadoPagoQrException
     */
    public function crear_cobro($owner_id, $employee_id, array $datos)
    {
        $owner_id = (int) $owner_id;

        $total = (isset($datos['total']) && is_numeric($datos['total'])) ? round((float) $datos['total'], 2) : 0;

        if ($total <= 0) {
            throw new MercadoPagoQrException('El total a cobrar tiene que ser mayor a cero.', 422);
        }

        $conexion = $this->conexion_o_falla($owner_id);

        $caja_id    = isset($datos['caja_id']) && is_numeric($datos['caja_id']) && (int) $datos['caja_id'] > 0 ? (int) $datos['caja_id'] : null;
        $address_id = isset($datos['address_id']) && is_numeric($datos['address_id']) && (int) $datos['address_id'] > 0 ? (int) $datos['address_id'] : null;

        $qr = $this->resolver_qr($owner_id, $caja_id, $address_id, $conexion['mp_user_id']);

        $this->cancelar_pendientes_del_qr($qr, $conexion);

        $cobro = MercadoPagoQrCobro::create([
            'user_id'                 => $owner_id,
            'mercado_pago_qr_caja_id' => $qr->id,
            'employee_id'             => $employee_id,
            'caja_id'                 => $caja_id,
            'address_id'              => $address_id,
            'total'                   => $total,
            // Provisorio y único: el definitivo lleva el id de esta misma fila (ver abajo).
            'external_reference'      => 'tmp-' . Str::uuid(),
            'estado'                  => MercadoPagoQrCobro::ESTADO_PENDIENTE,
        ]);

        // Letras, números, - y _, ≤ 64. Lo aleatorio evita que dos instancias que comparten
        // cuenta de MP (pasa: hay clientes con más de un sistema) choquen por el mismo id.
        $cobro->external_reference = 'ccqr-' . $owner_id . '-' . $cobro->id . '-' . Str::random(4);
        $cobro->save();

        $monto = number_format($total, 2, '.', '');

        $descripcion = isset($datos['description']) && !is_array($datos['description']) ? trim((string) $datos['description']) : '';

        if ($descripcion === '') {
            $descripcion = 'Venta en el mostrador';
        }

        $cuerpo = [
            'type'               => 'qr',
            // STRING con 2 decimales: Orders rechaza el número.
            'total_amount'       => $monto,
            'external_reference' => $cobro->external_reference,
            'description'        => mb_substr($descripcion, 0, 150),
            'expiration_time'    => self::VENCIMIENTO_ORDEN,
            'config'             => [
                'qr' => [
                    'external_pos_id' => $qr->mp_pos_external_id,
                    'mode'            => 'static',
                ],
            ],
            'transactions'       => [
                'payments' => [
                    ['amount' => $monto],
                ],
            ],
        ];

        /*
         * La MISMA clave de idempotencia en el reintento: si el primer intento murió por la red
         * pero la orden se creó en MP, el reintento devuelve esa misma orden en vez de crear otra.
         * Sin esto, un timeout dejaría una orden viva en el QR que el cobro no conoce y que el
         * cliente podría pagar sin que nadie se entere.
         */
        $idempotency_key = (string) Str::uuid();

        $respuesta = $this->llamar($conexion['token'], 'POST', '/v1/orders', [
            'idempotency_key' => $idempotency_key,
            'json'            => $cuerpo,
        ]);

        if ($respuesta['status'] === 0) {
            $respuesta = $this->llamar($conexion['token'], 'POST', '/v1/orders', [
                'idempotency_key' => $idempotency_key,
                'json'            => $cuerpo,
            ]);
        }

        if (!$respuesta['ok'] || empty($respuesta['json']['id'])) {

            $this->log_error_de_mp('crear orden del cobro ' . $cobro->id, $owner_id, $respuesta);

            $mensaje = $this->mensaje_de_error('Mercado Pago no dejó cargar el cobro en el QR', $respuesta);

            $cobro->estado        = MercadoPagoQrCobro::ESTADO_ERROR;
            $cobro->error_message = mb_substr($mensaje, 0, 191);
            $cobro->save();

            throw new MercadoPagoQrException($mensaje, 422);
        }

        $orden = $respuesta['json'];

        $cobro->mp_order_id      = (string) $orden['id'];
        $cobro->mp_status        = isset($orden['status']) ? mb_substr((string) $orden['status'], 0, 60) : null;
        $cobro->mp_status_detail = isset($orden['status_detail']) ? mb_substr((string) $orden['status_detail'], 0, 120) : null;

        $pago = $this->primer_pago($orden);

        if (!empty($pago['id'])) {
            $cobro->mp_payment_id = (string) $pago['id'];
        }

        $cobro->save();

        Log::info('MercadoPagoQrService: cobro ' . $cobro->id . ' cargado en el QR ' . $qr->id . ' (orden ' . $cobro->mp_order_id . ', total ' . $monto . ').');

        return MercadoPagoQrCobro::withAll()->find($cobro->id);
    }

    /**
     * Cancela en MP los cobros pendientes de un QR antes de cargarle uno nuevo (ver
     * `crear_cobro()`).
     *
     * @param MercadoPagoQrCaja $qr
     * @param array $conexion
     * @return void
     * @throws MercadoPagoQrException 422 `pagando: true` si el cliente está pagando uno de esos.
     */
    protected function cancelar_pendientes_del_qr(MercadoPagoQrCaja $qr, array $conexion)
    {
        $pendientes = MercadoPagoQrCobro::where('mercado_pago_qr_caja_id', $qr->id)
            ->where('estado', MercadoPagoQrCobro::ESTADO_PENDIENTE)
            ->get();

        foreach ($pendientes as $pendiente) {
            try {
                $this->cancelar_cobro_con_conexion($pendiente, $conexion);
            } catch (MercadoPagoQrException $e) {
                $payload = $e->payload();

                if (!empty($payload['pagando'])) {
                    throw new MercadoPagoQrException(
                        'El cliente está pagando un cobro anterior en este QR. Esperá a que termine y volvé a intentar.',
                        422,
                        ['pagando' => true]
                    );
                }

                // Cualquier otro problema con el viejo no frena el cobro nuevo: queda dicho y el
                // comando lo concilia (o lo vence) solo.
                Log::warning('MercadoPagoQrService: no se pudo cancelar el cobro pendiente ' . $pendiente->id . ' antes de cargar uno nuevo: ' . $e->getMessage());
            }
        }
    }

    /**
     * Consulta la orden en MP y actualiza el cobro si todavía está pendiente. Si MP no contesta,
     * el cobro queda como estaba (el SPA vuelve a preguntar en 3 segundos; el comando, en un
     * minuto): un problema de red no es motivo para dar un cobro por perdido.
     *
     * @param MercadoPagoQrCobro $cobro
     * @param array|null $conexion La del comercio del cobro, si el llamador ya la tiene.
     * @return MercadoPagoQrCobro El mismo cobro, refrescado.
     */
    public function actualizar_cobro(MercadoPagoQrCobro $cobro, $conexion = null)
    {
        if (!$cobro->esta_pendiente() || empty($cobro->mp_order_id)) {
            return $cobro;
        }

        if (is_null($conexion)) {
            $conexion = $this->conexion($cobro->user_id);
        }

        if (is_null($conexion)) {
            return $cobro;
        }

        $respuesta = $this->llamar($conexion['token'], 'GET', '/v1/orders/' . rawurlencode($cobro->mp_order_id));

        if (!$respuesta['ok']) {
            $this->log_error_de_mp('consultar orden del cobro ' . $cobro->id, $cobro->user_id, $respuesta);

            return $cobro;
        }

        $this->aplicar_estado_de_la_orden($cobro, $respuesta['json']);

        return $cobro;
    }

    /**
     * Traduce el estado de una orden de MP al del cobro y lo guarda.
     *
     * - pagado: orden `processed`, o su pago `processed` con detalle `accredited` (MP a veces
     *   tarda un instante en pasar la orden después del pago).
     * - vencido: `expired`.
     * - cancelado: `canceled`, y también `refunded` (la plata volvió: para la venta es lo mismo
     *   que no haber cobrado).
     * - error: `failed`.
     * - cualquier otro (`created`, `action_required`, `at_terminal`...): sigue pendiente. Un
     *   rechazo de tarjeta deja la orden en `created`: el cliente puede reintentar.
     *
     * La escritura es CONDICIONAL (`where estado = pendiente`): si el SPA y el comando consultan
     * a la vez, el segundo no puede pisar lo que ya decidió el primero.
     *
     * @param MercadoPagoQrCobro $cobro
     * @param array $orden La orden según MP.
     * @return void
     */
    protected function aplicar_estado_de_la_orden(MercadoPagoQrCobro $cobro, array $orden)
    {
        $status        = isset($orden['status']) ? (string) $orden['status'] : '';
        $status_detail = isset($orden['status_detail']) ? (string) $orden['status_detail'] : '';

        $pago          = $this->primer_pago($orden);
        $pago_status   = isset($pago['status']) ? (string) $pago['status'] : '';
        $pago_detail   = isset($pago['status_detail']) ? (string) $pago['status_detail'] : '';

        $campos = [
            'mp_status'        => $status === '' ? null : mb_substr($status, 0, 60),
            'mp_status_detail' => $status_detail === '' ? null : mb_substr($status_detail, 0, 120),
        ];

        if ($status === 'processed' || ($pago_status === 'processed' && $pago_detail === 'accredited')) {
            $campos['estado']  = MercadoPagoQrCobro::ESTADO_PAGADO;
            $campos['paid_at'] = Carbon::now();

            if (!empty($pago['id'])) {
                $campos['mp_payment_id'] = (string) $pago['id'];
            }
        } elseif ($status === 'expired') {
            $campos['estado']        = MercadoPagoQrCobro::ESTADO_VENCIDO;
            $campos['error_message'] = 'El cobro venció sin que el cliente pagara.';
        } elseif ($status === 'canceled') {
            $campos['estado'] = MercadoPagoQrCobro::ESTADO_CANCELADO;
        } elseif ($status === 'refunded') {
            $campos['estado']        = MercadoPagoQrCobro::ESTADO_CANCELADO;
            $campos['error_message'] = 'Mercado Pago devolvió el pago.';
        } elseif ($status === 'failed') {
            $campos['estado']        = MercadoPagoQrCobro::ESTADO_ERROR;
            $campos['error_message'] = 'Mercado Pago marcó el cobro como fallido.';
        }

        MercadoPagoQrCobro::where('id', $cobro->id)
            ->where('estado', MercadoPagoQrCobro::ESTADO_PENDIENTE)
            ->update($campos);

        $cobro->refresh();

        if ($cobro->estado === MercadoPagoQrCobro::ESTADO_PAGADO) {
            Log::info('MercadoPagoQrService: cobro ' . $cobro->id . ' pagado (orden ' . $cobro->mp_order_id . ').');
        }
    }

    /**
     * Cancela un cobro pendiente a pedido del cajero.
     *
     * @param MercadoPagoQrCobro $cobro
     * @return MercadoPagoQrCobro Refrescado (puede volver `pagado` si el pago entró justo antes).
     * @throws MercadoPagoQrException 409 `pagando: true` si el cliente está pagando; 422 si no hay
     *                                conexión o MP no dejó cancelar.
     */
    public function cancelar_cobro(MercadoPagoQrCobro $cobro)
    {
        if (!$cobro->esta_pendiente()) {
            return $cobro;
        }

        if (empty($cobro->mp_order_id)) {
            // Nunca llegó a MP: no hay nada que cancelar allá.
            $this->marcar_si_sigue_pendiente($cobro, MercadoPagoQrCobro::ESTADO_CANCELADO, null);

            return $cobro;
        }

        /*
         * Sin conexión NO se cancela solo acá: la orden seguiría viva en el QR y el cliente podría
         * pagarla con el cobro ya marcado como cancelado. Se le dice al cajero y el comando la
         * concilia cuando vuelva la conexión (o vence a los 10 minutos en MP).
         */
        $conexion = $this->conexion_o_falla($cobro->user_id);

        $this->cancelar_cobro_con_conexion($cobro, $conexion);

        return $cobro;
    }

    /**
     * La cancelación en MP propiamente dicha (`POST /v1/orders/{id}/cancel`, sin cuerpo).
     *
     * - ok, o 409 `order_already_canceled` → `cancelado`.
     * - 409 `instore_order_locked_error` → el cliente está pagando AHORA: 409 `pagando: true` y el
     *   cobro sigue pendiente (si el pago entra, el polling lo ve).
     * - cualquier otro rechazo → se consulta la orden: si ya no está pendiente (se pagó o venció
     *   un instante antes) se devuelve así; si sigue pendiente, 422 con el texto de MP.
     *
     * @param MercadoPagoQrCobro $cobro
     * @param array $conexion
     * @return void
     * @throws MercadoPagoQrException
     */
    protected function cancelar_cobro_con_conexion(MercadoPagoQrCobro $cobro, array $conexion)
    {
        if (empty($cobro->mp_order_id)) {
            $this->marcar_si_sigue_pendiente($cobro, MercadoPagoQrCobro::ESTADO_CANCELADO, null);

            return;
        }

        $respuesta = $this->llamar($conexion['token'], 'POST', '/v1/orders/' . rawurlencode($cobro->mp_order_id) . '/cancel', [
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $codigos = $this->codigos_de_error($respuesta['json']);

        if ($respuesta['ok'] || in_array('order_already_canceled', $codigos, true)) {
            $this->marcar_si_sigue_pendiente($cobro, MercadoPagoQrCobro::ESTADO_CANCELADO, null, 'canceled');

            return;
        }

        if (in_array('instore_order_locked_error', $codigos, true)) {
            throw new MercadoPagoQrException(
                'El cliente está pagando en este momento. Esperá unos segundos: si el pago entra, la venta se guarda sola.',
                409,
                ['pagando' => true]
            );
        }

        $this->log_error_de_mp('cancelar orden del cobro ' . $cobro->id, $cobro->user_id, $respuesta);

        $this->actualizar_cobro($cobro, $conexion);

        if (!$cobro->esta_pendiente()) {
            return;
        }

        throw new MercadoPagoQrException($this->mensaje_de_error('Mercado Pago no dejó cancelar el cobro', $respuesta), 422);
    }

    /**
     * Pasa un cobro a un estado terminal solo si todavía está pendiente (escritura condicional,
     * mismo motivo que en `aplicar_estado_de_la_orden()`), y lo refresca.
     *
     * @param MercadoPagoQrCobro $cobro
     * @param string $estado
     * @param string|null $error_message
     * @param string|null $mp_status
     * @return void
     */
    protected function marcar_si_sigue_pendiente(MercadoPagoQrCobro $cobro, $estado, $error_message, $mp_status = null)
    {
        $campos = ['estado' => $estado];

        if (!is_null($error_message)) {
            $campos['error_message'] = $error_message;
        }

        if (!is_null($mp_status)) {
            $campos['mp_status'] = $mp_status;
        }

        MercadoPagoQrCobro::where('id', $cobro->id)
            ->where('estado', MercadoPagoQrCobro::ESTADO_PENDIENTE)
            ->update($campos);

        $cobro->refresh();
    }

    /**
     * Últimos cobros del comercio, para la tarjeta de configuración.
     *
     * @param int $owner_id
     * @param mixed $limit 20 por defecto, entre 1 y 100.
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function ultimos_cobros($owner_id, $limit)
    {
        $limit = is_numeric($limit) ? (int) $limit : 20;
        $limit = max(1, min(self::LIMITE_MAXIMO_DE_COBROS, $limit));

        return MercadoPagoQrCobro::where('user_id', (int) $owner_id)
            ->withAll()
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Forma `Cobro` del contrato con el SPA:
     * `{id, total, estado, mp_order_id, error_message, paid_at, sale_id, sale_num,
     * qr: {id, name, qr_image_url}, created_at, current_acount_payment_method_id}`.
     *
     * `current_acount_payment_method_id` es el método configurado HOY para el QR (no se guarda en
     * el cobro): es el que el SPA pone como método único de la venta cuando el pago entra.
     * Las fechas van como el resto de los modelos del sistema (ISO 8601 en UTC).
     *
     * @param MercadoPagoQrCobro $cobro Con `qr` y `sale` cargadas (o se cargan solas).
     * @param int|null $current_acount_payment_method_id
     * @return array
     */
    public function serializar_cobro(MercadoPagoQrCobro $cobro, $current_acount_payment_method_id)
    {
        $qr   = $cobro->qr;
        $sale = is_null($cobro->sale_id) ? null : $cobro->sale;

        return [
            'id'                               => (int) $cobro->id,
            'total'                            => (float) $cobro->total,
            'estado'                           => $cobro->estado,
            'mp_order_id'                      => $cobro->mp_order_id,
            'error_message'                    => $cobro->error_message,
            'paid_at'                          => $cobro->paid_at ? $cobro->paid_at->toJSON() : null,
            'sale_id'                          => is_null($cobro->sale_id) ? null : (int) $cobro->sale_id,
            'sale_num'                         => $sale ? (int) $sale->num : null,
            'qr'                               => $qr ? [
                'id'           => (int) $qr->id,
                'name'         => $qr->name,
                'qr_image_url' => $qr->qr_image_url,
            ] : null,
            'created_at'                       => $cobro->created_at ? $cobro->created_at->toJSON() : null,
            'current_acount_payment_method_id' => is_null($current_acount_payment_method_id) ? null : (int) $current_acount_payment_method_id,
        ];
    }

    // =========================================================================================
    // Venta (SaleController@store)
    // =========================================================================================

    /**
     * Valida el cobro que viene en `mercado_pago_qr_cobro_id` de una venta nueva. Corre ANTES del
     * candado y de la transacción de `SaleController@store`, como los otros 422 tempranos: un
     * rechazo no toca la base ni consume número de venta.
     *
     * Exige que el cobro sea del comercio, esté `pagado`, no lo haya usado otra venta y su total
     * coincida con el de la venta (al centavo). NO consulta a Mercado Pago: el SPA manda el campo
     * recién cuando vio el cobro pagado, y guardar una venta no puede depender de la red.
     *
     * 🔴 La excepción del "ya usado": si el cobro ya está atado a una venta que tiene el MISMO
     * `offline_uuid` que este request, es un REINTENTO de esa venta (la red se cortó después del
     * commit, o la venta guardada en el equipo se vuelve a subir). Se deja pasar: la guarda de
     * idempotencia de `store()` devuelve esa venta sin crear otra ni revincular nada.
     *
     * @param int $owner_id Comercio (owner).
     * @param int $cobro_id
     * @param mixed $total Total de la venta del request.
     * @param string|null $offline_uuid El de `VentaOfflineHelper::offline_uuid_utilizable()`.
     * @return array|null null si está todo bien; si no, el cuerpo del 422
     *                    (`{message, cobro_qr_invalido: true}`).
     */
    public function validar_cobro_para_venta($owner_id, $cobro_id, $total, $offline_uuid = null)
    {
        try {
            $cobro = MercadoPagoQrCobro::where('id', (int) $cobro_id)
                ->where('user_id', (int) $owner_id)
                ->first();

            if (!$cobro) {
                return $this->cobro_invalido('El cobro con QR de Mercado Pago no existe.');
            }

            if (!is_null($cobro->sale_id)) {

                if (!is_null($offline_uuid)
                    && Sale::withTrashed()->where('id', $cobro->sale_id)->where('offline_uuid', $offline_uuid)->exists()) {
                    return null;
                }

                $sale = Sale::withTrashed()->select('id', 'num')->find($cobro->sale_id);

                return $this->cobro_invalido(
                    'Este cobro con QR ya se usó en ' . ($sale ? 'la venta N° ' . $sale->num : 'otra venta') . '.'
                );
            }

            if ($cobro->estado !== MercadoPagoQrCobro::ESTADO_PAGADO) {
                return $this->cobro_invalido('El cobro con QR de Mercado Pago todavía no figura como pagado.');
            }

            $total = is_numeric($total) ? (float) $total : 0;

            if (abs((float) $cobro->total - $total) > self::TOLERANCIA_TOTAL) {
                return $this->cobro_invalido(
                    'El total de la venta ($' . number_format($total, 2, ',', '.') . ') no coincide con lo que se cobró por QR ($'
                    . number_format((float) $cobro->total, 2, ',', '.') . ').'
                );
            }

            return null;

        } catch (\Throwable $e) {
            // Por ejemplo, la tabla todavía sin migrar en la ventana de un deploy. Se rechaza (la
            // venta no puede quedar atada a un cobro que no se pudo verificar) con un texto legible.
            Log::error('MercadoPagoQrService: no se pudo validar el cobro ' . (int) $cobro_id . ' para una venta: ' . get_class($e) . ' ' . $e->getMessage());

            return $this->cobro_invalido('No se pudo verificar el cobro con QR de Mercado Pago. Probá de nuevo.');
        }
    }

    /**
     * Ata el cobro a la venta recién creada. Corre ADENTRO de la transacción de
     * `SaleController@store`, después del `Sale::create`.
     *
     * Relee el cobro con `lockForUpdate`: si dos ventas pasaron la validación de afuera a la vez
     * (el candado del comercio no se pudo tomar, por ejemplo), la segunda espera acá a la primera,
     * ve el `sale_id` ya puesto y falla, y su transacción se revierte entera. Un cobro, una venta.
     *
     * @param int $owner_id
     * @param int $cobro_id
     * @param Sale $sale
     * @return void
     * @throws MercadoPagoQrException 422 `cobro_qr_invalido: true` (el catch de `store()` la
     *                                devuelve tal cual, después del rollback).
     */
    public function vincular_cobro_a_venta($owner_id, $cobro_id, Sale $sale)
    {
        $cobro = MercadoPagoQrCobro::where('id', (int) $cobro_id)
            ->where('user_id', (int) $owner_id)
            ->lockForUpdate()
            ->first();

        if (!$cobro
            || $cobro->estado !== MercadoPagoQrCobro::ESTADO_PAGADO
            || (!is_null($cobro->sale_id) && (int) $cobro->sale_id !== (int) $sale->id)) {

            throw new MercadoPagoQrException(
                'Este cobro con QR ya se usó en otra venta o dejó de estar pagado. La venta no se guardó.',
                422,
                ['cobro_qr_invalido' => true]
            );
        }

        $cobro->sale_id = $sale->id;
        $cobro->save();

        Log::info('MercadoPagoQrService: cobro ' . $cobro->id . ' atado a la venta ' . $sale->id . ' (N° ' . $sale->num . ').');
    }

    /**
     * Cuerpo del 422 de un cobro inválido para la venta.
     *
     * @param string $mensaje
     * @return array
     */
    protected function cobro_invalido($mensaje)
    {
        return [
            'message'           => $mensaje,
            'cobro_qr_invalido' => true,
        ];
    }

    // =========================================================================================
    // Conciliación (comando mercadopago:qr-conciliar)
    // =========================================================================================

    /**
     * Concilia los cobros pendientes de TODOS los comercios de la instancia: consulta su orden en
     * MP y, los que pasaron las 3 horas sin novedad, los vence.
     *
     * Antes de vencer uno viejo se lo consulta una última vez: si el cron estuvo caído y el pago
     * entró, se lo ve pagado en vez de perderlo. La conexión se resuelve una vez por comercio. Un
     * fallo con un cobro (o con un comercio) no corta el resto.
     *
     * @return array{revisados: int, actualizados: int, vencidos: int, fallidos: int}
     */
    public function conciliar_pendientes()
    {
        $limite     = Carbon::now()->subHours(self::HORAS_DE_VIGENCIA);
        $conexiones = [];
        $conteo     = ['revisados' => 0, 'actualizados' => 0, 'vencidos' => 0, 'fallidos' => 0];

        $pendientes = MercadoPagoQrCobro::where('estado', MercadoPagoQrCobro::ESTADO_PENDIENTE)
            ->orderBy('id')
            ->get();

        foreach ($pendientes as $cobro) {

            $conteo['revisados']++;

            try {
                $owner_id = (int) $cobro->user_id;

                if (!array_key_exists($owner_id, $conexiones)) {
                    $conexiones[$owner_id] = $this->conexion($owner_id);
                }

                if (!is_null($conexiones[$owner_id]) && !empty($cobro->mp_order_id)) {

                    $this->actualizar_cobro($cobro, $conexiones[$owner_id]);

                    if (!$cobro->esta_pendiente()) {
                        $conteo['actualizados']++;
                    }
                }

                if ($cobro->esta_pendiente() && $cobro->created_at && $cobro->created_at->lt($limite)) {

                    $this->marcar_si_sigue_pendiente(
                        $cobro,
                        MercadoPagoQrCobro::ESTADO_VENCIDO,
                        'Pasaron más de ' . self::HORAS_DE_VIGENCIA . ' horas sin que el pago entrara.'
                    );

                    $conteo['vencidos']++;
                }
            } catch (\Throwable $e) {
                $conteo['fallidos']++;

                Log::warning('MercadoPagoQrService: no se pudo conciliar el cobro ' . $cobro->id . ': ' . get_class($e) . ' ' . $e->getMessage());
            }
        }

        return $conteo;
    }

    // =========================================================================================
    // HTTP y errores de Mercado Pago
    // =========================================================================================

    /**
     * Una llamada a la API de Mercado Pago con el token del comercio.
     *
     * Nunca tira: un problema de red vuelve como `status` 0, para que cada llamador decida (el
     * polling sigue esperando, la creación responde un 422 legible). El token va solo en el
     * header `Authorization` y no se loguea.
     *
     * @param string $token Access token del comercio.
     * @param string $metodo GET, POST o DELETE.
     * @param string $ruta Ruta desde la base (`/v1/orders`).
     * @param array $opciones `json` (cuerpo), `query` (para GET) e `idempotency_key`.
     * @return array{ok: bool, status: int, json: array}
     */
    protected function llamar($token, $metodo, $ruta, array $opciones = [])
    {
        $peticion = Http::withOptions(MercadoPagoOAuthService::http_client_options())
            ->withToken($token)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SEGUNDOS);

        if (!empty($opciones['idempotency_key'])) {
            $peticion = $peticion->withHeaders(['X-Idempotency-Key' => $opciones['idempotency_key']]);
        }

        $url = self::API_BASE . $ruta;

        try {
            if ($metodo === 'GET') {
                $response = $peticion->get($url, isset($opciones['query']) ? $opciones['query'] : []);
            } elseif (array_key_exists('json', $opciones)) {
                $response = $peticion->send($metodo, $url, ['json' => $opciones['json']]);
            } else {
                // Sin cuerpo: el cancel de Orders y el DELETE de la caja no llevan body.
                $response = $peticion->send($metodo, $url);
            }
        } catch (\Throwable $e) {
            // Solo la clase: el mensaje de Guzzle puede traer la URL y un pedazo de la respuesta.
            Log::warning('MercadoPagoQrService: no se pudo hablar con Mercado Pago (' . $metodo . ' ' . $ruta . '): ' . get_class($e));

            return ['ok' => false, 'status' => 0, 'json' => []];
        }

        $json = $response->json();

        return [
            'ok'     => $response->successful(),
            'status' => (int) $response->status(),
            'json'   => is_array($json) ? $json : [],
        ];
    }

    /**
     * Códigos de error de una respuesta de MP, juntando las dos formas que usa:
     * `{message, error, status, cause: [{code, description}]}` (stores, pos) y
     * `{errors: [{code, message}]}` (Orders). Todo en minúsculas.
     *
     * @param array $json
     * @return string[]
     */
    protected function codigos_de_error(array $json)
    {
        $codigos = [];

        foreach (['error', 'code'] as $clave) {
            if (isset($json[$clave]) && is_scalar($json[$clave])) {
                $codigos[] = mb_strtolower((string) $json[$clave]);
            }
        }

        foreach (['errors', 'cause'] as $clave) {
            if (isset($json[$clave]) && is_array($json[$clave])) {
                foreach ($json[$clave] as $item) {
                    if (is_array($item) && isset($item['code']) && is_scalar($item['code'])) {
                        $codigos[] = mb_strtolower((string) $item['code']);
                    }
                }
            }
        }

        return $codigos;
    }

    /**
     * Mensaje legible para el cajero a partir de una respuesta fallida de MP. Toma el texto que
     * devolvió MP (en Orders, `errors[0].message`; en el resto, `message` o `cause[0].description`)
     * y lo pone después de un encabezado en castellano. Casos con texto propio: sin conexión con
     * MP (status 0) y credencial rechazada (401/403).
     *
     * @param string $encabezado "Mercado Pago no dejó crear la caja del QR".
     * @param array $respuesta La de `llamar()`.
     * @return string
     */
    protected function mensaje_de_error($encabezado, array $respuesta)
    {
        if ($respuesta['status'] === 0) {
            return 'No se pudo hablar con Mercado Pago. Revisá la conexión a internet y probá de nuevo.';
        }

        if ($respuesta['status'] === 401 || $respuesta['status'] === 403) {
            return 'Mercado Pago rechazó la credencial del comercio. Volvé a conectar la cuenta en ABM → Integraciones.';
        }

        $json  = $respuesta['json'];
        $texto = '';

        if (isset($json['errors'][0]['message']) && is_scalar($json['errors'][0]['message'])) {
            $texto = (string) $json['errors'][0]['message'];
        } elseif (isset($json['cause'][0]['description']) && is_scalar($json['cause'][0]['description'])) {
            $texto = (string) $json['cause'][0]['description'];
        } elseif (isset($json['message']) && is_scalar($json['message'])) {
            $texto = (string) $json['message'];
        }

        $texto = trim(mb_substr($texto, 0, 150));

        if ($texto === '') {
            return $encabezado . ' (código ' . $respuesta['status'] . ').';
        }

        return $encabezado . ': ' . $texto;
    }

    /**
     * Deja en el log un rechazo de MP: status y códigos de error, nunca el cuerpo entero.
     *
     * @param string $que Qué se estaba haciendo.
     * @param int $owner_id
     * @param array $respuesta La de `llamar()`.
     * @return void
     */
    protected function log_error_de_mp($que, $owner_id, array $respuesta)
    {
        Log::warning(
            'MercadoPagoQrService: falló ' . $que . ' (comercio ' . (int) $owner_id . '): status '
            . $respuesta['status'] . ', códigos [' . implode(', ', $this->codigos_de_error($respuesta['json'])) . '].'
        );
    }

    /**
     * Primer pago de una orden de MP (`transactions.payments[0]`), o array vacío.
     *
     * @param array $orden
     * @return array
     */
    protected function primer_pago(array $orden)
    {
        if (isset($orden['transactions']['payments'][0]) && is_array($orden['transactions']['payments'][0])) {
            return $orden['transactions']['payments'][0];
        }

        return [];
    }
}
