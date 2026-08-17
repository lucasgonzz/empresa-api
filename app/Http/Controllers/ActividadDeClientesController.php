<?php

namespace App\Http\Controllers;

use App\Services\ActividadDeClientes\ActividadDeClientesService;
use App\Services\ActividadDeClientes\ResumenIaActividadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Qué hizo un cliente en la tienda online (misión actividad-de-clientes-y-oferta-por-whatsapp).
 * Dos endpoints de LECTURA PURA: ninguno de los dos escribe una fila en `buyer_tracking_events` ni
 * en `buyer_tracking_daily`.
 *
 *   GET  api/actividad-de-clientes            -> los números
 *   POST api/actividad-de-clientes/resumen    -> la lectura en criollo de esos mismos números
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 LAS DOS PUERTAS, UNA SOLA VALIDACIÓN
 * -------------------------------------------------------------------------------------------
 *
 * A la pantalla se entra por `client_id` (Clientes del ERP) o por `buyer_id` (Tienda Online →
 * Clientes), nunca por los dos. Los dos caminos colapsan en una lista de `buyer_id` y de ahí en
 * adelante son el mismo código.
 *
 * Esa validación vive en resolver_objetivo() y en ningún otro lado. Dos validaciones distintas del
 * mismo contrato es exactamente cómo se llega a que el GET acepte algo que el POST rechaza — y
 * entonces la pantalla dibuja una actividad que después no puede resumir, sin que nada lo denuncie.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL 404 ES DE "NO ES TUYO" Y DE "NO EXISTE" AL MISMO TIEMPO, Y ES A PROPÓSITO
 * -------------------------------------------------------------------------------------------
 *
 * Nunca 403: quién existe y quién no en la lista de clientes es información del comercio. Un 403
 * contra un id ajeno confirmaría que ese cliente existe en otra cuenta. La tenencia sale de
 * `$this->userId()`, que resuelve al dueño cuando el autenticado es un empleado
 * (Controller.php:28-34), y se aplica adentro del servicio contra `buyers.user_id` / `clients.user_id`.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL RESUMEN RECALCULA EN EL SERVIDOR Y NO ACEPTA LOS NÚMEROS DEL FRONT
 * -------------------------------------------------------------------------------------------
 *
 * El POST recibe el mismo `client_id`/`buyer_id`/`periodo` que el GET y vuelve a leer el tracking.
 * Aceptar los totales que mandara la SPA sería dejar que el navegador le dicte a la IA lo que tiene
 * que decir sobre un cliente.
 *
 * Y si no hay una sola señal de actividad en el periodo, NO SE LLAMA A LA IA: se contesta 422. Es
 * plata — una llamada paga por un cliente que no hizo nada.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class ActividadDeClientesController extends Controller
{
    /**
     * Ventana por defecto cuando el pedido no trae `periodo`. Son los últimos 30 días: la ventana
     * que el comerciante mira, y la que se contesta con los eventos crudos (con detalle por hora).
     */
    const PERIODO_POR_DEFECTO = 30;

    /** Mensaje del 422 cuando no vino un id, o vinieron los dos. */
    const MENSAJE_ID_INVALIDO = 'Mandá un client_id o un buyer_id: uno de los dos, y no los dos juntos.';

    /** Mensaje del 422 cuando el periodo no es de la lista blanca. */
    const MENSAJE_PERIODO_INVALIDO = 'El periodo tiene que ser 7, 30, 90 o 0 (todo el historial).';

    /** Mensaje del 422 cuando la cuenta no tiene la IA configurada. */
    const MENSAJE_SIN_IA = 'El resumen con IA no está disponible en esta cuenta.';

    /** Mensaje del 422 cuando no hay una sola señal de actividad en el periodo pedido. */
    const MENSAJE_SIN_ACTIVIDAD = 'Todavía no hay actividad de este cliente en la tienda para ese periodo.';

    /**
     * Mensaje del 422 de una falla que NO es transitoria. El texto crudo de la excepción no se le
     * muestra al comerciante: puede traer el body de una respuesta HTTP de Anthropic, que no le dice
     * nada y sí filtra el detalle de la integración a una pantalla del ERP.
     */
    const MENSAJE_RESUMEN_FALLIDO = 'No se pudo generar el resumen en este momento.';

    /**
     * El lector del tracking, memoizado: resolver_objetivo() y el método que lo llama lo usan los
     * dos, y no tiene sentido construirlo dos veces por request.
     *
     * @var ActividadDeClientesService|null
     */
    protected $servicio_actividad = null;

    /**
     * GET api/actividad-de-clientes
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $objetivo = $this->resolver_objetivo($request);

        if ($objetivo instanceof JsonResponse) {
            return $objetivo;
        }

        return response()->json(['actividad' => $this->actividad_de($objetivo)], 200);
    }

    /**
     * POST api/actividad-de-clientes/resumen
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function resumen(Request $request): JsonResponse
    {
        $objetivo = $this->resolver_objetivo($request);

        if ($objetivo instanceof JsonResponse) {
            return $objetivo;
        }

        $resumen_service = new ResumenIaActividadService();

        /*
         * El chequeo de credenciales va PRIMERO: no cuesta una consulta y separa "esta cuenta no
         * tiene la IA contratada" de "este cliente no hizo nada", que son dos carteles distintos.
         */
        if (!$resumen_service->hay_credenciales()) {
            return response()->json(['message' => self::MENSAJE_SIN_IA], 422);
        }

        $actividad = $this->actividad_de($objetivo);

        /*
         * 🔴 SIN ACTIVIDAD NO SE LLAMA A LA IA. Es plata: una llamada paga por un cliente que no
         * hizo nada, y encima el párrafo que volvería no podría decir nada más que "no hizo nada",
         * que es lo que la pantalla ya muestra sola con su cartel de vacío.
         */
        if (!$this->hay_actividad($actividad)) {
            return response()->json(['message' => self::MENSAJE_SIN_ACTIVIDAD], 422);
        }

        $prompt = $resumen_service->armar_prompt($actividad, $objetivo['nombre']);

        try {
            $texto = $resumen_service->pedir_resumen(
                $prompt,
                $this->userId(),
                // El empleado que apretó el botón, sin resolver al dueño: el gasto lo paga el
                // comercio pero lo pidió una persona (mismo criterio que WhatsappChatController::suggest()).
                $this->userId(false)
            );
        } catch (\RuntimeException $exception) {
            $mensaje = $exception->getMessage() === ResumenIaActividadService::MENSAJE_IA_NO_DISPONIBLE
                ? ResumenIaActividadService::MENSAJE_IA_NO_DISPONIBLE
                : self::MENSAJE_RESUMEN_FALLIDO;

            return response()->json(['message' => $mensaje], 422);
        }

        return response()->json(['resumen' => $texto], 200);
    }

    /**
     * 🔴 LA ÚNICA VALIDACIÓN DEL CONTRATO, PARA LOS DOS MÉTODOS.
     *
     * Devuelve el objetivo ya resuelto, o directamente la JsonResponse de error (422 / 404) que el
     * método de arriba tiene que contestar tal cual.
     *
     * @param  Request $request
     * @return array|JsonResponse ['buyer_ids' => int[], 'cliente' => array|null, 'nombre' => string, 'periodo' => int]
     */
    protected function resolver_objetivo(Request $request)
    {
        $client_id = $request->input('client_id');
        $buyer_id  = $request->input('buyer_id');

        $por_cliente   = !is_null($client_id) && $client_id !== '';
        $por_comprador = !is_null($buyer_id) && $buyer_id !== '';

        // Los dos, o ninguno: en los dos casos no hay a quién mirar. El === entre los dos booleanos
        // cubre las dos puntas con una sola condición.
        if ($por_cliente === $por_comprador) {
            return response()->json(['message' => self::MENSAJE_ID_INVALIDO], 422);
        }

        $periodo = $request->input('periodo');

        if (is_null($periodo) || $periodo === '') {
            $periodo = self::PERIODO_POR_DEFECTO;
        }

        /*
         * La lista blanca la decide el servicio, no este controller: es el mismo método que usan las
         * tools del chat, así que un periodo que acá pasa y allá no (o al revés) no puede existir.
         */
        if (!ActividadDeClientesService::es_periodo_valido($periodo)) {
            return response()->json(['message' => self::MENSAJE_PERIODO_INVALIDO], 422);
        }

        $periodo  = (int) $periodo;
        $servicio = $this->servicio();

        if ($por_cliente) {
            // nombre_del_cliente() ya filtra por dueño: null es "no existe" y "no es tuyo" a la vez.
            $nombre = $servicio->nombre_del_cliente($client_id);

            if (is_null($nombre)) {
                return response()->json(['message' => 'Cliente no encontrado.'], 404);
            }

            return [
                'buyer_ids' => $servicio->buyer_ids_de_un_cliente($client_id),
                // 🔴 Se entró por el cliente, así que el cliente se sabe ACÁ y no depende de que haya
                // compradores. Es lo que después pisa la clave `cliente` del bloque (ver actividad_de()).
                'cliente'   => ['client_id' => (int) $client_id, 'nombre' => $nombre],
                'nombre'    => $nombre,
                'periodo'   => $periodo,
            ];
        }

        $buyer_ids = $servicio->buyer_ids_de_un_comprador($buyer_id);

        if (empty($buyer_ids)) {
            return response()->json(['message' => 'Comprador no encontrado.'], 404);
        }

        $compradores = $servicio->compradores($buyer_ids);

        return [
            'buyer_ids' => $buyer_ids,
            /*
             * Entrando por `buyer_id` el cliente NO se sabe acá: puede haberlo o no (el vínculo
             * `buyers.comercio_city_client_id` es opcional y manual, y el buyer sin cliente es el
             * caso normal). Se deja en null a propósito para que valga el que resuelve el servicio
             * desde el propio comprador, que es el único que lo puede saber.
             */
            'cliente'   => null,
            'nombre'    => count($compradores) ? $compradores[0]['nombre'] : 'este comprador',
            'periodo'   => $periodo,
        ];
    }

    /**
     * El bloque `actividad` del contrato, ya con el cliente que corresponde.
     *
     * 🔴 ACÁ SE PISA LA CLAVE `cliente`, Y NO ES UN ADORNO. ActividadDeClientesService::actividad()
     * resuelve el cliente desde los propios compradores, así que un cliente del ERP que todavía no
     * tiene ningún comprador de la tienda asociado —que es el caso MÁS común— vuelve con
     * `cliente = null` aunque se haya entrado por `client_id`. Sin este pisado la pantalla no puede
     * dibujar el cartel de "este cliente no tiene ningún comprador asociado": no sabría de quién
     * está hablando.
     *
     * Cuando se entró por `buyer_id`, `$objetivo['cliente']` es null y gana el que resolvió el
     * servicio: ahí el único que sabe si el comprador tiene cliente es él.
     *
     * @param  array $objetivo Lo que devolvió resolver_objetivo()
     * @return array
     */
    protected function actividad_de(array $objetivo)
    {
        $actividad = $this->servicio()->actividad($objetivo['buyer_ids'], $objetivo['periodo']);

        if (!is_null($objetivo['cliente'])) {
            $actividad['cliente'] = $objetivo['cliente'];
        }

        return $actividad;
    }

    /**
     * ¿Hubo UNA sola señal de este cliente en el periodo pedido? Es la guarda que decide si se paga
     * una llamada a la IA.
     *
     * 🔴 ESTA ES UNA RED REDUNDANTE A PROPÓSITO, Y CONVIENE SABER QUE LO ES.
     *
     * `hay_datos` está BIEN. Lo estuvo roto un rato: `ActividadDeClientesService::actividad()` lo
     * armaba con `!empty($grupos)` sobre la Collection que devuelve el `->get()`, y en PHP `empty()`
     * de un objeto es SIEMPRE false —aunque la colección no tenga una sola fila—, así que venía
     * `true` para cualquier cliente que tuviera un comprador cargado aunque no hubiera hecho nada.
     * Eso se arregló en el commit 8da1913: hoy la clave se deriva de `totales.ultima_actividad`, que
     * es el mismo número que mira el `if` de acá abajo.
     *
     * O sea que el `foreach` de contadores del final es, hoy, INALCANZABLE: cualquier cosa que
     * mueva un contador movió también `ultima_actividad` (las columnas de momento son NOT NULL en
     * las dos tablas). Se deja igual, y la decisión es deliberada: lo que esta guarda decide es si
     * se paga o no una llamada a la IA, y una guarda de más sobre plata no molesta a nadie. Lo que
     * sí molestaría es que alguien la lea creyendo que compensa un bug que ya no existe.
     *
     * El orden también es a propósito: primero `ultima_actividad` y después los contadores, porque
     * si mañana la tienda manda un `event_type` nuevo —que no alimenta ningún contador— eso IGUAL
     * es actividad y el cliente merece su resumen.
     *
     * @param  array $actividad
     * @return bool
     */
    protected function hay_actividad(array $actividad)
    {
        if (empty($actividad['hay_datos'])) {
            return false;
        }

        $totales = (isset($actividad['totales']) && is_array($actividad['totales'])) ? $actividad['totales'] : [];

        if (isset($totales['ultima_actividad']) && !is_null($totales['ultima_actividad'])) {
            return true;
        }

        $contadores = [
            'vistas',
            'busquedas',
            'agregados_al_carrito',
            'quitados_del_carrito',
            'checkouts_empezados',
            'compras',
        ];

        foreach ($contadores as $clave) {
            if (isset($totales[$clave]) && (int) $totales[$clave] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ActividadDeClientesService Con el dueño del comercio, nunca con el empleado
     */
    protected function servicio()
    {
        if (is_null($this->servicio_actividad)) {
            $this->servicio_actividad = new ActividadDeClientesService($this->userId());
        }

        return $this->servicio_actividad;
    }
}
