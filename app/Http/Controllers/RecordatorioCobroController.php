<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Jobs\SendRecordatorioCobroJob;
use App\Models\Client;
use App\Services\RecordatorioCobroSenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Recordatorio de cobro por WhatsApp desde el módulo de alertas, solapa Cobros (misión
 * recordatorio-cobro-whatsapp). Cuatro endpoints: previsualizar, enviar de a uno, enviar a
 * todos los deudores del filtro, y leer el progreso del lote.
 *
 * 🔴 EL BACKEND NUNCA RECIBE `sale_ids`. Recibe `client_id` y `dias`, y vuelve a resolver la
 * query él mismo, con la misma cascada por rol y el mismo recorte
 * (`ver_solo_las_ventas_suyas`) que usa `SaleController::ventas_sin_cobrar()`. Si el front
 * pudiera mandar la lista de ventas, un empleado con el recorte puesto podría hacerle llegar a
 * un cliente ventas que no le corresponde ver. Por lo mismo, los jobs también recalculan.
 *
 * 🔴 `@enviar` Y `@enviar_masivo` DEVUELVEN EXACTAMENTE LA MISMA FORMA DE 202, incluso cuando
 * el único cliente del envío individual queda salteado (`encolados: 0` y el cliente adentro de
 * `salteados[]`). Un cliente salteado NO es un error de la request: es la carrera real entre la
 * previsualización y el POST, porque entre que el operador mira el mensaje y aprieta "Enviar"
 * otro pudo haberle mandado el recordatorio. Devolver 422 ahí haría que el front lo mostrara
 * como error de servidor y el operador leería el mensaje equivocado. El único 422 del módulo es
 * `sin_ventas`: el cliente no tiene nada que cobrarle dentro del filtro, o no aparece en el
 * recorte del usuario.
 *
 * ⚠️ El progreso del lote vive en `Cache` (TTL 1 h) y no en una tabla ni en un broadcast. Con
 * `CACHE_DRIVER=file` el incremento es read-modify-write y dos jobs en paralelo pueden perder
 * un tick. Hoy no pasa (un solo worker, serial). Si algún día se levantan varios, la barra
 * puede no llegar al 100%: por eso el front corta también por timeout a los 5 minutos y no sólo
 * cuando `terminado` viene en true. Se eligió polling y no Pusher porque el broadcast pedía un
 * canal privado nuevo más Echo conectado, y acá la ventana de interés son 30 segundos.
 */
class RecordatorioCobroController extends Controller
{
    /**
     * Cuánto vive el progreso de un lote en Cache. Desde la misión asistente-envios-a-clientes el
     * lote lo escribe `RecordatorioCobroSenderService::encolar_lote()`, y el valor vive allá.
     *
     * @var int
     */
    const MINUTOS_DE_VIDA_DEL_LOTE = RecordatorioCobroSenderService::MINUTOS_DE_VIDA_DEL_LOTE;

    /**
     * Previsualiza el recordatorio de un cliente: el mensaje EXACTO que va a salir, ya con el
     * nombre, los montos y las ventas resueltos, más cuál de los dos caminos (texto libre o
     * plantilla) le toca según la ventana de 24 h.
     *
     * @param  \Illuminate\Http\Request  $request  Puede traer `dias` por query string.
     * @param  int  $client_id
     * @return \Illuminate\Http\JsonResponse
     */
    function preview(Request $request, $client_id)
    {
        if (! $this->check_permiso()) {
            return $this->respuesta_sin_permiso();
        }

        $client = $this->client_del_owner($client_id);

        if (is_null($client)) {
            return response()->json([
                'code'    => 'cliente_no_encontrado',
                'message' => 'No se encontró el cliente.',
            ], 404);
        }

        $dias = $this->dias_del_request($request->query('dias'));

        $ventas = $this->ventas_del_cliente($client->id, $dias);

        if ($ventas->count() === 0) {
            return response()->json([
                'code'    => RecordatorioCobroSenderService::CODE_SIN_VENTAS,
                'message' => 'Este cliente no tiene ventas sin cobrar con el filtro actual.',
            ], 422);
        }

        $preview = (new RecordatorioCobroSenderService())
            ->previsualizar($this->userId(), $client, $ventas, $this->userId(false));

        return response()->json(['preview' => $preview], 200);
    }

    /**
     * Encola el recordatorio de UN cliente. Devuelve un 202 con la misma forma que el masivo,
     * así los dos modales leen el resultado igual.
     *
     * @param  \Illuminate\Http\Request  $request  `client_id` y `dias` (opcional).
     * @return \Illuminate\Http\JsonResponse
     */
    function enviar(Request $request)
    {
        if (! $this->check_permiso()) {
            return $this->respuesta_sin_permiso();
        }

        $client = $this->client_del_owner($request->client_id);

        if (is_null($client)) {
            return response()->json([
                'code'    => 'cliente_no_encontrado',
                'message' => 'No se encontró el cliente.',
            ], 404);
        }

        $dias = $this->dias_del_request($request->dias);

        $ventas = $this->ventas_del_cliente($client->id, $dias);

        // Esto SÍ es un 422 y no un salteo: el cliente no tiene nada que cobrarle dentro del
        // filtro, o directamente no aparece en el recorte de este usuario.
        if ($ventas->count() === 0) {
            return response()->json([
                'code'    => RecordatorioCobroSenderService::CODE_SIN_VENTAS,
                'message' => 'Este cliente no tiene ventas sin cobrar con el filtro actual.',
            ], 422);
        }

        return $this->encolar([$client], $dias);
    }

    /**
     * Encola el recordatorio para todos los clientes con ventas sin cobrar del filtro.
     *
     * 🔴 No recibe ninguna lista de clientes: corre la MISMA secuencia que armó la pantalla
     * (cascada por rol -> `dias` del request la pisa -> `query_de_ventas()` ->
     * `ordenar_por_clientes()`), así que el recorte por rol vale también acá.
     *
     * @param  \Illuminate\Http\Request  $request  `dias` (opcional).
     * @return \Illuminate\Http\JsonResponse
     */
    function enviar_masivo(Request $request)
    {
        if (! $this->check_permiso()) {
            return $this->respuesta_sin_permiso();
        }

        $dias = $this->dias_del_request($request->dias);

        $ventas = VentasSinCobrarHelper::query_de_ventas($this->userId(), $this->employee_id_del_recorte(), $dias)
            ->with('client')
            ->orderBy('created_at', 'DESC')
            ->get();

        $agrupadas = VentasSinCobrarHelper::ordenar_por_clientes($ventas);

        $clientes = [];

        foreach ($agrupadas as $fila) {
            // Una venta sin cliente no tiene a quién avisarle: no entra ni como salteada,
            // porque no hay ningún renglón que el operador pueda leer o accionar.
            if (! is_null($fila['client'])) {
                $clientes[] = $fila['client'];
            }
        }

        return $this->encolar($clientes, $dias);
    }

    /**
     * Progreso de un lote. El front lo sondea cada 2 segundos mientras el modal está abierto.
     *
     * 🔴 La clave de Cache lleva el `owner_id` adentro, así que un lote de otra empresa no se
     * puede leer aunque se adivine el uuid.
     *
     * Devuelve el lote tal cual está en Cache, con los contadores Y los `motivos_fallo` /
     * `fallos` que fueron cargando los jobs. Sin eso, los cuatro motivos que sólo el job puede
     * detectar (la plantilla que no está aprobada, las ventas que se cobraron mientras el job
     * esperaba, el envío que Kapso no confirmó y el freno de 24 h revalidado) terminaban en
     * pantalla como un número pelado, "3 fallidos", que no le dice al operador qué hacer.
     *
     * @param  string  $lote_uuid
     * @return \Illuminate\Http\JsonResponse
     */
    function estado_lote($lote_uuid)
    {
        if (! $this->check_permiso()) {
            return $this->respuesta_sin_permiso();
        }

        $lote = Cache::get(SendRecordatorioCobroJob::cache_key($this->userId(), $lote_uuid));

        if (is_null($lote) || ! is_array($lote)) {
            // Vencido (más de una hora) o inexistente. El front corta el polling y deja la
            // pantalla como está: el envío sigue por su cuenta igual.
            return response()->json([
                'code'    => 'lote_no_encontrado',
                'message' => 'El lote ya no está disponible.',
            ], 404);
        }

        return response()->json(['lote' => $lote], 200);
    }

    /**
     * Evalúa cada cliente, despacha un job por cada uno que pueda recibir el recordatorio y
     * devuelve el 202 con los salteados.
     *
     * Desde la misión asistente-envios-a-clientes (16/10/2026) la lógica vive en
     * `RecordatorioCobroSenderService::encolar_lote()`, porque el asistente de IA encola los mismos
     * recordatorios (con el sí del dueño) y tiene que hacerlo por EL MISMO camino: las dos pasadas,
     * el lote escrito en Cache antes de despachar y un job por cliente. Acá queda solo el armado de
     * la respuesta, que es exactamente la de antes (mismas claves, mismo 202).
     *
     * @param  array  $clientes
     * @param  int    $dias
     * @return \Illuminate\Http\JsonResponse
     */
    private function encolar(array $clientes, $dias)
    {
        $lote = (new RecordatorioCobroSenderService())->encolar_lote(
            $this->userId(),
            $clientes,
            $dias,
            $this->userId(false)
        );

        return response()->json([
            'lote_uuid'      => $lote['lote_uuid'],
            'total_clientes' => $lote['total_clientes'],
            'encolados'      => $lote['encolados'],
            'salteados'      => $lote['salteados'],
        ], 202);
    }

    /**
     * Las ventas sin cobrar de UN cliente, con el mismo recorte que ve el usuario autenticado.
     *
     * @param  int  $client_id
     * @param  int  $dias
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function ventas_del_cliente($client_id, $dias)
    {
        return VentasSinCobrarHelper::query_de_ventas($this->userId(), $this->employee_id_del_recorte(), $dias)
            ->where('client_id', $client_id)
            ->with('current_acount')
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * El umbral de antigüedad en días: la misma cascada por rol de
     * `SaleController::ventas_sin_cobrar()`, y el `dias` del request la PISA si es válido.
     *
     * Que sea la misma secuencia no es un detalle: la previsualización, el envío y la pantalla
     * tienen que hablar del mismo recorte. Si acá se resolviera distinto, el operador vería una
     * lista de deudores y se le mandaría el recordatorio a otra.
     *
     * @param  mixed  $valor  Lo que vino por query string o por body.
     * @return int
     */
    private function dias_del_request($valor)
    {
        $owner = $this->user();

        $user = $this->user(false);

        $dias = $owner->dias_alertar_empleados_ventas_no_cobradas;

        if ($this->is_owner()) {
            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;
        } else if ($this->is_admin() && is_null($user->dias_alertar_empleados_ventas_no_cobradas)) {
            $dias = $owner->dias_alertar_administradores_ventas_no_cobradas;
        } else if (!is_null($user->dias_alertar_empleados_ventas_no_cobradas)) {
            $dias = $user->dias_alertar_empleados_ventas_no_cobradas;
        }

        $dias_input = VentasSinCobrarHelper::dias_del_input($valor);

        if (! is_null($dias_input)) {
            $dias = $dias_input;
        }

        return (int) $dias;
    }

    /**
     * El recorte `ver_solo_las_ventas_suyas`, con la misma regla que
     * `SaleController::ventas_sin_cobrar()`.
     *
     * @return int|null  Id del empleado si hay que recortar; null si ve todo.
     */
    private function employee_id_del_recorte()
    {
        if ($this->is_owner()) {
            return null;
        }

        $user = $this->user(false);

        if ($user->ver_alertas_de_todos_los_empleados) {
            return null;
        }

        return (int) $user->id;
    }

    /**
     * El cliente, sólo si es de esta empresa.
     *
     * @param  mixed  $client_id
     * @return Client|null
     */
    private function client_del_owner($client_id)
    {
        if (is_null($client_id)) {
            return null;
        }

        return Client::where('id', $client_id)
            ->where('user_id', $this->userId())
            ->first();
    }

    /**
     * ¿El usuario autenticado puede mandar recordatorios de cobro?
     *
     * 🔴 Espeja el `can()` del front (`common-vue/mixins/permissions.js`): el dueño y cualquier
     * usuario con `admin_access` pueden siempre; un empleado necesita el permiso explícito.
     *
     * ⚠️ Son ocho líneas propias y no una pieza de infraestructura compartida a propósito:
     * NINGÚN controller de este repo chequea permisos hoy (el único gate de servidor es
     * `check_extencion_empresa`). Inventar acá un middleware o un trait de permisos sería
     * introducir un mecanismo nuevo para toda la aplicación en el medio de una misión que no lo
     * pidió, y dejar a los otros cuarenta controllers con una asimetría sin explicar.
     *
     * El permiso NO es "ver la alerta de cobros": la solapa se sigue viendo sin él. Lo que
     * habilita es escribirle al cliente final en nombre del negocio, que es otra capacidad.
     *
     * @return bool
     */
    private function check_permiso()
    {
        if ($this->is_admin()) {
            return true;
        }

        return $this->user(false)->permissions->contains('slug', 'alerts.recordatorio_cobro');
    }

    /**
     * El 403 de los cuatro endpoints, con el mismo cuerpo para que el front lo muestre igual.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function respuesta_sin_permiso()
    {
        return response()->json([
            'code'    => 'sin_permiso',
            'message' => 'No tenés permiso para mandar recordatorios de cobro.',
        ], 403);
    }
}
