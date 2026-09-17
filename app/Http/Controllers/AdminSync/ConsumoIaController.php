<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consumo de tokens de IA de este comercio, para que el admin lo espeje y le ponga precio
 * (misión tokens-por-cliente, §1.3 del plan).
 *
 * Es la primera lectura que tiene `ai_token_usages`: hasta esta misión la tabla era
 * write-only y nadie miraba lo que se estaba juntando. Acá no se calcula ningún costo —la
 * tabla de precios vive SOLO en el admin, para poder corregirla sin tocar a los 45 clientes—:
 * lo único que sale de acá son tokens contados.
 *
 * 🔴 LOS DOS CORTES VAN ABIERTOS POR DÍA, `personas[]` TAMBIÉN, y no es cosmético. El admin
 * guarda lo que recibe con clave `(client_id, fecha, ...)`. Un bloque agregado por todo el
 * rango lo obligaría a inventarle una fecha, y entonces la recolección nocturna de 3 días
 * pisaría, con su total de 3 días, lo que una consulta manual de 30 días dejó escrito — todas
 * las noches, sin un solo error en ningún log. Cualquier corte que el admin espeje tiene que
 * venir por día.
 */
class ConsumoIaController extends Controller
{
    /**
     * Tope de días del rango.
     *
     * Existe para que un `desde` mal armado del otro lado no termine en un GROUP BY sobre la
     * tabla entera de un comercio con años de historia. Dos meses cubren de sobra el caso real
     * (la recolección pide 3 días; la pantalla, 30) y dejan margen para un backfill.
     */
    const MAX_DIAS = 62;

    /** Ventana por defecto cuando el admin no manda fechas. */
    const DIAS_POR_DEFECTO = 30;

    /**
     * GET api/admin-sync/consumo-ia?desde=AAAA-MM-DD&hasta=AAAA-MM-DD
     *
     * 200 {user_id, desde, hasta, dias[], personas[]}
     * 401 la clave del header no coincide con la que tiene cargada este cliente
     * 409 no se pudo resolver el dueño de esta instancia
     * 422 fechas mal formadas, invertidas, o rango de más de MAX_DIAS días
     *
     * El orden de los rechazos: primero la clave (sin credencial no se contesta nada), después
     * lo que vino en el request (un 422 es culpa del que llama y lo puede arreglar solo), y
     * recién al final el dueño (un 409 es una configuración de este cliente que el que llama no
     * puede arreglar, y conviene que llegue cuando ya sabemos que lo demás estaba bien).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $rechazo_clave = $this->rechazo_por_clave($request);

        if (! is_null($rechazo_clave)) {

            return $rechazo_clave;
        }

        $request->validate([
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => 'nullable|date_format:Y-m-d',
        ]);

        /*
         * Las fechas se arman con Carbon, que toma la zona de `config('app.timezone')` —
         * fijada en `America/Argentina/Buenos_Aires` en `config/app.php`, a mano y no por env.
         * Eso mismo es lo que hace que el `DATE(created_at)` de abajo ya esté en la zona
         * correcta: Laravel escribe los timestamps en la zona de la app, no en la del sistema
         * operativo (que en el shared hosting es UTC y en el VPS es -03).
         *
         * 🔴 NO se usa `CONVERT_TZ()` para forzar la zona en SQL: necesita las tablas de zonas
         * horarias cargadas en MySQL, que en el shared hosting NO están, y cuando faltan
         * devuelve NULL sin error — o sea, todos los días agrupados bajo una fecha nula y nadie
         * enterándose.
         */
        $hasta = $request->filled('hasta')
            ? Carbon::createFromFormat('Y-m-d', $request->input('hasta'))->endOfDay()
            : Carbon::now()->endOfDay();

        $desde = $request->filled('desde')
            ? Carbon::createFromFormat('Y-m-d', $request->input('desde'))->startOfDay()
            : (clone $hasta)->subDays(self::DIAS_POR_DEFECTO - 1)->startOfDay();

        if ($desde->greaterThan($hasta)) {

            return response()->json([
                'message' => 'El rango está invertido: "desde" tiene que ser anterior o igual a "hasta".',
            ], 422);
        }

        $dias_pedidos = $desde->diffInDays($hasta) + 1;

        if ($dias_pedidos > self::MAX_DIAS) {

            return response()->json([
                'message' => 'El rango no puede superar los ' . self::MAX_DIAS . ' días (pediste ' . $dias_pedidos . ').',
            ], 422);
        }

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        $user_id = (int) $dueno->id;

        return response()->json([
            'user_id'  => $user_id,
            'desde'    => $desde->toDateString(),
            'hasta'    => $hasta->toDateString(),
            'dias'     => $this->dias($user_id, $desde, $hasta),
            'personas' => $this->personas($user_id, $desde, $hasta),
        ], 200);
    }

    /**
     * El corte del pedido: por día, acción, proveedor y modelo.
     *
     * El modelo entra en el group by porque es la unidad de precio: el mismo `chat_mensaje`
     * corrido con dos modelos distintos cuesta distinto, y sumarlos dejaría al admin sin forma
     * de costear la fila.
     *
     * @param  int     $user_id
     * @param  Carbon  $desde
     * @param  Carbon  $hasta
     * @return array
     */
    protected function dias($user_id, Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('ai_token_usages')
                    ->selectRaw(
                        'DATE(created_at) as fecha, proceso, proveedor, modelo, COUNT(*) as llamadas, '
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens, '
                        . 'SUM(cache_creation_input_tokens) as cache_creation_input_tokens, '
                        . 'SUM(cache_read_input_tokens) as cache_read_input_tokens'
                    )
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$desde, $hasta])
                    ->groupByRaw('DATE(created_at), proceso, proveedor, modelo')
                    ->orderByRaw('DATE(created_at), proceso, modelo')
                    ->get();

        $salida = [];

        foreach ($filas as $fila) {

            $salida[] = [
                'fecha'     => (string) $fila->fecha,
                'proceso'   => (string) $fila->proceso,
                'proveedor' => (string) $fila->proveedor,
                'modelo'    => (string) $fila->modelo,
                'llamadas'  => (int) $fila->llamadas,
            ] + $this->contadores($fila);
        }

        return $salida;
    }

    /**
     * El corte por persona, también por día.
     *
     * `auth_user_id` nulo sale como `null`: son los procesos automáticos (el scheduler que
     * indexa el catálogo, el agente contestándole a un cliente del comercio, los resúmenes por
     * comando). No es un dato faltante, es una categoría con sentido propio y el admin la
     * muestra como tal.
     *
     * Los nombres se resuelven en UNA consulta sobre los ids que efectivamente aparecieron, no
     * con un join: la tabla de consumo puede tener filas de un empleado ya borrado, y un join
     * las haría desaparecer del informe — el gasto existió igual.
     *
     * @param  int     $user_id
     * @param  Carbon  $desde
     * @param  Carbon  $hasta
     * @return array
     */
    protected function personas($user_id, Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('ai_token_usages')
                    ->selectRaw(
                        'DATE(created_at) as fecha, auth_user_id, COUNT(*) as llamadas, '
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens, '
                        . 'SUM(cache_creation_input_tokens) as cache_creation_input_tokens, '
                        . 'SUM(cache_read_input_tokens) as cache_read_input_tokens'
                    )
                    ->where('user_id', $user_id)
                    ->whereBetween('created_at', [$desde, $hasta])
                    ->groupByRaw('DATE(created_at), auth_user_id')
                    ->orderByRaw('DATE(created_at), auth_user_id')
                    ->get();

        $ids = [];

        foreach ($filas as $fila) {

            if (! is_null($fila->auth_user_id)) {

                $ids[] = (int) $fila->auth_user_id;
            }
        }

        $nombres = count($ids) > 0
            ? User::whereIn('id', array_unique($ids))->pluck('name', 'id')
            : collect();

        $salida = [];

        foreach ($filas as $fila) {

            $auth_user_id = is_null($fila->auth_user_id) ? null : (int) $fila->auth_user_id;

            $salida[] = [
                'fecha'        => (string) $fila->fecha,
                'auth_user_id' => $auth_user_id,
                'nombre'       => is_null($auth_user_id) || ! isset($nombres[$auth_user_id])
                    ? null
                    : (string) $nombres[$auth_user_id],
                'llamadas' => (int) $fila->llamadas,
            ] + $this->contadores($fila);
        }

        return $salida;
    }

    /**
     * Los cuatro contadores, con los nombres exactos del contrato.
     *
     * Están en un solo lugar porque los dos cortes los devuelven iguales, y porque estas cuatro
     * claves son literalmente lo que el admin lee: es el punto donde este proyecto ya se quemó
     * una vez (`manual_tasks` vs `tareas`).
     *
     * @param  object  $fila
     * @return array
     */
    protected function contadores($fila): array
    {
        return [
            'input_tokens'                => (int) $fila->input_tokens,
            'output_tokens'               => (int) $fila->output_tokens,
            'cache_creation_input_tokens' => (int) $fila->cache_creation_input_tokens,
            'cache_read_input_tokens'     => (int) $fila->cache_read_input_tokens,
        ];
    }

    /**
     * Valida el header `X-Admin-Api-Key` SOLO si este cliente tiene la clave cargada.
     *
     * 🔴 POR QUÉ NO ES UN 401 DURO. El middleware del grupo (`AdminApiKey`) no exige nada
     * mientras `services.admin_api.require_api_key` esté en `false`, que es como está en
     * producción. La mayoría de los clientes todavía no tiene `ADMIN_API_INBOUND_KEY` en su
     * `.env`: exigir la clave siempre dejaría la recolección de tokens sin funcionar en casi
     * todos ellos —una funcionalidad rota en silencio, que es peor que una abierta— y el admin
     * los marcaría a todos como `failed` sin que nadie sepa por qué.
     *
     * Con esta validación condicional, el que TIENE la clave queda efectivamente protegido
     * (aunque el flag del middleware siga apagado) y el que no la tiene se comporta igual que
     * el resto del grupo. Es estrictamente más seguro que el status quo y no rompe a nadie.
     *
     * A diferencia del canal del asistente —que exige la clave siempre—, este endpoint SOLO
     * LEE, y lo que devuelve son contadores de tokens: no expone ni ventas, ni clientes, ni
     * precios, ni permite escribir nada. El riesgo de dejarlo como está hoy es otro.
     *
     * El día que Lucas prenda `ADMIN_SYNC_REQUIRE_API_KEY`, este método queda redundante y no
     * molesta.
     *
     * @param  Request  $request
     * @return JsonResponse|null
     */
    protected function rechazo_por_clave(Request $request)
    {
        $esperada = (string) config('services.admin_api.api_key');

        if ($esperada === '') {

            return null;
        }

        $recibida = (string) $request->header('X-Admin-Api-Key');

        if ($recibida === '' || ! hash_equals($esperada, $recibida)) {

            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }
}
