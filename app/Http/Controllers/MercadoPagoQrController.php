<?php

namespace App\Http\Controllers;

use App\Models\MercadoPagoQrCobro;
use App\Services\MercadoPago\MercadoPagoQrException;
use App\Services\MercadoPago\MercadoPagoQrService;
use Illuminate\Http\Request;

/**
 * Endpoints del cobro con el QR de Mercado Pago en Vender (misión cobro-qr-mercado-pago,
 * 26/10/2026): la configuración de los QR del mostrador (ABM → Integraciones) y los cobros que
 * el cajero dispara desde Vender.
 *
 * El contrato con el SPA (rutas, cuerpos y las formas `QrCaja` y `Cobro`) está fijado en el plan
 * de la misión y lo implementa otro agente en paralelo: no se cambia sin cambiarlo allá.
 *
 * Toda la lógica y la conversación con Mercado Pago viven en
 * `App\Services\MercadoPago\MercadoPagoQrService`. Acá solo se resuelve el comercio
 * (`$this->userId()`, el owner, como en el resto de los controllers), se llama al servicio y se
 * traduce su `MercadoPagoQrException` a la respuesta JSON que ya trae armada. Sin extensión: el
 * módulo está disponible para todo comercio con Mercado Pago conectado.
 */
class MercadoPagoQrController extends Controller
{
    /**
     * `GET /api/mercado-pago/qr`: estado de la conexión, método de pago del QR y la lista de QR.
     *
     * Responde 200 también sin conexión (`conectado: false`): el SPA usa esto para decidir si
     * muestra la tarjeta y el botón "Cobrar con QR", y un "no" no es un error.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $service  = new MercadoPagoQrService();
        $owner_id = $this->userId();
        $conexion = $service->conexion($owner_id);

        return response()->json([
            'conectado'                        => !is_null($conexion),
            'current_acount_payment_method_id' => $service->metodo_de_pago_configurado($owner_id, $conexion ? $conexion['connector'] : null),
            'qrs'                              => $service->listar_qrs($owner_id, $conexion ? $conexion['mp_user_id'] : null),
        ], 200);
    }

    /**
     * `PUT /api/mercado-pago/qr/config`: guarda el método de pago con el que se registran los
     * cobros por QR. 422 si el id no existe en el catálogo.
     *
     * @param Request $request `{current_acount_payment_method_id}`.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_config(Request $request)
    {
        $service = new MercadoPagoQrService();

        try {
            $id = $service->guardar_metodo_de_pago($this->userId(), $request->input('current_acount_payment_method_id'));
        } catch (MercadoPagoQrException $e) {
            return response()->json($e->payload(), $e->http_status());
        }

        return response()->json(['current_acount_payment_method_id' => $id], 200);
    }

    /**
     * `POST /api/mercado-pago/qr/cajas`: crea un QR del mostrador (sucursal + caja en MP).
     *
     * @param Request $request `{name, caja_id?, address_id?, street_name, street_number,
     *                         city_name, state_name, latitude?, longitude?, reference?}`.
     * @return \Illuminate\Http\JsonResponse 201 `{qr: QrCaja}`; 422 `{message}` (o
     *                                       `{message, sin_conexion: true}`).
     */
    public function store_qr(Request $request)
    {
        $service  = new MercadoPagoQrService();
        $owner_id = $this->userId();

        try {
            $qr = $service->crear_qr($owner_id, $request->only([
                'name', 'caja_id', 'address_id', 'street_name', 'street_number',
                'city_name', 'state_name', 'latitude', 'longitude', 'reference',
            ]));
        } catch (MercadoPagoQrException $e) {
            return response()->json($e->payload(), $e->http_status());
        }

        // Recién creado con la cuenta conectada: siempre activo.
        return response()->json(['qr' => $service->serializar_qr($qr, $qr->mp_user_id)], 201);
    }

    /**
     * `DELETE /api/mercado-pago/qr/cajas/{id}`: borra el QR (en MP y acá, con soft delete).
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse 200 `{ok: true}`; 404 si no es del comercio.
     */
    public function destroy_qr($id)
    {
        $service = new MercadoPagoQrService();

        try {
            $service->eliminar_qr($this->userId(), $id);
        } catch (MercadoPagoQrException $e) {
            return response()->json($e->payload(), $e->http_status());
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * `GET /api/mercado-pago/qr/cobros?limit=20`: los últimos cobros del comercio.
     *
     * Con `pagados_sin_venta=1`: solo los cobros PAGADOS que ninguna venta usó, de los últimos 7
     * días, más nuevos primero. Es la plata que entró por QR y no quedó registrada en una venta.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse 200 `{cobros: [Cobro]}`.
     */
    public function index_cobros(Request $request)
    {
        $service  = new MercadoPagoQrService();
        $owner_id = $this->userId();
        $metodo   = $service->metodo_de_pago_configurado($owner_id);
        $limit    = $request->query('limit', 20);

        $lista = $request->boolean('pagados_sin_venta')
            ? $service->pagados_sin_venta($owner_id, $limit)
            : $service->ultimos_cobros($owner_id, $limit);

        $cobros = [];

        foreach ($lista as $cobro) {
            $cobros[] = $service->serializar_cobro($cobro, $metodo);
        }

        return response()->json(['cobros' => $cobros], 200);
    }

    /**
     * `POST /api/mercado-pago/qr/cobros`: carga el total de la venta en el QR del mostrador.
     *
     * El `employee_id` es el usuario autenticado de verdad (`userId(false)`: dueño o empleado),
     * no el owner: es el rastro de quién cobró.
     *
     * @param Request $request `{total, caja_id?, address_id?, description?}`.
     * @return \Illuminate\Http\JsonResponse 201 `{cobro: Cobro}` (si MP no contestó, el cobro
     *                                       viene `pendiente` sin `mp_order_id` y con "Mercado
     *                                       Pago no respondió, reintentando…": el SPA sigue
     *                                       consultando y el GET recupera la orden);
     *                                       422 `{message, sin_qr: true}` /
     *                                       `{message, sin_conexion: true}` /
     *                                       `{message, qr_ocupado: true}` (otro cobro cargándose en
     *                                       el mismo QR) / `{message, pagando: true}` (el cliente
     *                                       paga el cobro anterior del QR) /
     *                                       `{message, sin_respuesta: true}` (hay un cobro anterior
     *                                       sin confirmar y MP no contesta) / `{message}` (texto de MP).
     */
    public function store_cobro(Request $request)
    {
        $service  = new MercadoPagoQrService();
        $owner_id = $this->userId();

        try {
            $cobro = $service->crear_cobro($owner_id, $this->userId(false), $request->only([
                'total', 'caja_id', 'address_id', 'description',
            ]));
        } catch (MercadoPagoQrException $e) {
            return response()->json($e->payload(), $e->http_status());
        }

        return response()->json([
            'cobro' => $service->serializar_cobro($cobro, $service->metodo_de_pago_configurado($owner_id)),
        ], 201);
    }

    /**
     * `GET /api/mercado-pago/qr/cobros/{id}`: el cobro, consultando antes a MP si sigue
     * pendiente. Es lo que el modal de Vender pregunta cada 3 segundos.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse 200 `{cobro: Cobro}`; 404 si no es del comercio.
     */
    public function show_cobro($id)
    {
        $service  = new MercadoPagoQrService();
        $owner_id = $this->userId();
        $cobro    = $this->cobro_del_comercio($owner_id, $id);

        if (is_null($cobro)) {
            return response()->json(['message' => 'El cobro no existe.'], 404);
        }

        $service->actualizar_cobro($cobro);
        $cobro->load('qr', 'sale');

        return response()->json([
            'cobro' => $service->serializar_cobro($cobro, $service->metodo_de_pago_configurado($owner_id)),
        ], 200);
    }

    /**
     * `POST /api/mercado-pago/qr/cobros/{id}/cancelar`: cancela el cobro en MP.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse 200 `{cobro: Cobro}`; 409 `{message, pagando: true}`
     *                                       si el cliente está pagando; 404 si no es del comercio.
     */
    public function cancelar_cobro($id)
    {
        $service  = new MercadoPagoQrService();
        $owner_id = $this->userId();
        $cobro    = $this->cobro_del_comercio($owner_id, $id);

        if (is_null($cobro)) {
            return response()->json(['message' => 'El cobro no existe.'], 404);
        }

        try {
            $service->cancelar_cobro($cobro);
        } catch (MercadoPagoQrException $e) {
            return response()->json($e->payload(), $e->http_status());
        }

        $cobro->load('qr', 'sale');

        return response()->json([
            'cobro' => $service->serializar_cobro($cobro, $service->metodo_de_pago_configurado($owner_id)),
        ], 200);
    }

    /**
     * Cobro del comercio por id, o null si no existe o es de otro comercio.
     *
     * @param int $owner_id
     * @param int $id
     * @return MercadoPagoQrCobro|null
     */
    protected function cobro_del_comercio($owner_id, $id)
    {
        return MercadoPagoQrCobro::where('user_id', (int) $owner_id)
            ->where('id', (int) $id)
            ->withAll()
            ->first();
    }
}
