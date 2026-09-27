<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El registro de las consultas del circuito de imágenes de artículos de este comercio, para que el
 * admin lo muestre por cliente (misión imagenes-catalogo-completo, agregado del 27/9/2026, plan
 * §12.1): cada búsqueda a Serper / Google y cada validación con IA, con lo que devolvió, los tokens,
 * lo que tardó y el error si lo hubo.
 *
 * Dos endpoints:
 *   - resumen:   totales, corte por día, corte por modelo de IA y las asignaciones del rango.
 *   - consultas: el registro fila por fila, paginado y filtrable.
 *
 * Misma protección y convenciones que ConsumoIaController (la solapa Tokens del admin), a propósito,
 * para que el admin los consuma igual: header `X-Admin-Api-Key` validado ADENTRO del controlador
 * (solo si este cliente tiene la clave cargada: ver rechazo_por_clave()), dueño por
 * AsistenteCanalHelper::dueno(), días por `DATE(created_at)` en la zona de la app, rango de 62 días
 * como mucho, `desde`/`hasta` en `Y-m-d` y, sin fechas, los últimos 30 días.
 *
 * Acá no se calcula ningún costo: la tabla de precios vive SOLO en el admin (mismo criterio que
 * `ia_precios.php`), para poder corregirla sin tocar a los clientes. Solo lee.
 *
 * 🔴 LOS NOMBRES DE LOS CAMPOS SON EL CONTRATO con admin-api (plan §12.1): los consume otro agente,
 * construido en paralelo contra esos nombres. Renombrar uno no da error en ningún lado: la pantalla
 * del admin simplemente muestra cero.
 */
class ImagenesController extends Controller
{
    /** Tope de días del rango (mismo que consumo-ia). */
    const MAX_DIAS = 62;

    /** Ventana por defecto cuando el admin no manda fechas. */
    const DIAS_POR_DEFECTO = 30;

    /** Asignaciones del rango que devuelve el resumen, como mucho. */
    const MAXIMO_DE_ASIGNACIONES = 100;

    /** Paginado del registro de consultas. */
    const POR_PAGINA_DEFECTO = 50;
    const POR_PAGINA_MINIMO  = 10;
    const POR_PAGINA_MAXIMO  = 200;

    /** Los proveedores de búsqueda que el resumen informa siempre, aunque estén en cero. */
    const PROVEEDORES_DE_BUSQUEDA = ['serper', 'google'];

    /**
     * GET api/admin-sync/imagenes/resumen?desde=AAAA-MM-DD&hasta=AAAA-MM-DD
     *
     * 200 {desde, hasta, totales{}, dias[], modelos[], asignaciones[]}
     * 401 la clave del header no coincide con la que tiene cargada este cliente
     * 409 no se pudo resolver el dueño de esta instancia
     * 422 fechas mal formadas, invertidas, o rango de más de MAX_DIAS días
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function resumen(Request $request): JsonResponse
    {
        $preparado = $this->preparar($request, []);

        if ($preparado instanceof JsonResponse) {
            return $preparado;
        }

        $user_id = $preparado['user_id'];
        $desde   = $preparado['desde'];
        $hasta   = $preparado['hasta'];

        return response()->json([
            'desde'        => $desde->toDateString(),
            'hasta'        => $hasta->toDateString(),
            'totales'      => $this->totales($user_id, $desde, $hasta),
            'dias'         => $this->dias($user_id, $desde, $hasta),
            'modelos'      => $this->modelos($user_id, $desde, $hasta),
            'asignaciones' => $this->asignaciones($user_id, $desde, $hasta),
        ], 200);
    }

    /**
     * GET api/admin-sync/imagenes/consultas?desde=&hasta=&tipo=busqueda|validacion_ia&asignacion=<run_id>&solo_errores=0|1&page=&per_page=50
     *
     * 200 {models: <paginador de Laravel>}, más nuevas primero; per_page entre 10 y 200.
     * 401 / 409 / 422 como resumen (422 también por un `tipo`, `asignacion` o `solo_errores` inválido).
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function consultas(Request $request): JsonResponse
    {
        $preparado = $this->preparar($request, [
            'tipo'         => 'nullable|in:'.ImageServiceCall::TIPO_BUSQUEDA.','.ImageServiceCall::TIPO_VALIDACION_IA,
            'asignacion'   => 'nullable|integer|min:1',
            'solo_errores' => 'nullable|in:0,1,true,false',
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer',
        ]);

        if ($preparado instanceof JsonResponse) {
            return $preparado;
        }

        $por_pagina = $request->filled('per_page') ? (int) $request->input('per_page') : self::POR_PAGINA_DEFECTO;
        $por_pagina = max(self::POR_PAGINA_MINIMO, min(self::POR_PAGINA_MAXIMO, $por_pagina));

        $consulta = DB::table('image_service_calls')
            ->where('user_id', $preparado['user_id'])
            ->whereBetween('created_at', [$preparado['desde'], $preparado['hasta']]);

        if ($request->filled('tipo')) {
            $consulta->where('tipo', (string) $request->input('tipo'));
        }

        if ($request->filled('asignacion')) {
            $consulta->where('run_id', (int) $request->input('asignacion'));
        }

        if (in_array((string) $request->input('solo_errores'), ['1', 'true'], true)) {
            $consulta->where('ok', false);
        }

        $pagina = $consulta
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($por_pagina)
            ->appends($request->query())
            ->through(function ($fila) {
                return $this->fila_de_consulta($fila);
            });

        return response()->json(['models' => $pagina], 200);
    }

    /**
     * Lo común de los dos endpoints, en el orden de ConsumoIaController: primero la clave, después
     * lo que vino en el request (un 422 es del que llama y lo puede arreglar solo) y al final el dueño
     * (un 409 es de configuración de este cliente).
     *
     * @param  Request $request
     * @param  array   $reglas_extra  Reglas de validación propias del endpoint.
     * @return JsonResponse|array  El rechazo, o ['user_id', 'desde', 'hasta'].
     */
    protected function preparar(Request $request, array $reglas_extra)
    {
        $rechazo_clave = $this->rechazo_por_clave($request);

        if (!is_null($rechazo_clave)) {
            return $rechazo_clave;
        }

        $request->validate(array_merge([
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => 'nullable|date_format:Y-m-d',
        ], $reglas_extra));

        // Mismas fechas que consumo-ia: Carbon en la zona de config('app.timezone') y el día que
        // devuelve DATE(created_at) es ese mismo (ver el docblock largo de ConsumoIaController::index).
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
                'message' => 'El rango no puede superar los '.self::MAX_DIAS.' días (pediste '.$dias_pedidos.').',
            ], 422);
        }

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {
            return response()->json([
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           .'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        return [
            'user_id' => (int) $dueno->id,
            'desde'   => $desde,
            'hasta'   => $hasta,
        ];
    }

    /**
     * Los totales del rango.
     *
     * @param  int    $user_id
     * @param  Carbon $desde
     * @param  Carbon $hasta
     * @return array
     */
    protected function totales($user_id, Carbon $desde, Carbon $hasta)
    {
        $fila = DB::table('image_service_calls')
            ->selectRaw($this->sumas_de_conteo().', '
                .'SUM(COALESCE(tokens_entrada, 0)) as tokens_entrada, '
                .'SUM(COALESCE(tokens_salida, 0)) as tokens_salida, '
                .'SUM(COALESCE(tokens_cache_escritura, 0)) as tokens_cache_escritura, '
                .'SUM(COALESCE(tokens_cache_lectura, 0)) as tokens_cache_lectura')
            ->where('user_id', $user_id)
            ->whereBetween('created_at', [$desde, $hasta])
            ->first();

        $por_proveedor = [];

        foreach (self::PROVEEDORES_DE_BUSQUEDA as $proveedor) {
            $por_proveedor[$proveedor] = 0;
        }

        $filas_por_proveedor = DB::table('image_service_calls')
            ->selectRaw('proveedor, COUNT(*) as busquedas')
            ->where('user_id', $user_id)
            ->where('tipo', ImageServiceCall::TIPO_BUSQUEDA)
            ->whereBetween('created_at', [$desde, $hasta])
            ->groupBy('proveedor')
            ->get();

        // Un proveedor que no es ninguno de los dos de hoy entra igual (aditivo): no se esconde gasto.
        foreach ($filas_por_proveedor as $por) {
            $por_proveedor[(string) $por->proveedor] = (int) $por->busquedas;
        }

        return [
            'busquedas'                => (int) $fila->busquedas,
            'busquedas_cobradas'       => (int) $fila->busquedas_cobradas,
            'busquedas_por_proveedor'  => $por_proveedor,
            'validaciones_ia'          => (int) $fila->validaciones_ia,
            'validaciones_ia_cobradas' => (int) $fila->validaciones_ia_cobradas,
            'errores'                  => (int) $fila->errores,
            'tokens_entrada'           => (int) $fila->tokens_entrada,
            'tokens_salida'            => (int) $fila->tokens_salida,
            'tokens_cache_escritura'   => (int) $fila->tokens_cache_escritura,
            'tokens_cache_lectura'     => (int) $fila->tokens_cache_lectura,
        ];
    }

    /**
     * El corte por día (solo los días con alguna consulta, del más viejo al más nuevo, como
     * consumo-ia).
     *
     * @param  int    $user_id
     * @param  Carbon $desde
     * @param  Carbon $hasta
     * @return array
     */
    protected function dias($user_id, Carbon $desde, Carbon $hasta)
    {
        $filas = DB::table('image_service_calls')
            ->selectRaw('DATE(created_at) as fecha, '.$this->sumas_de_conteo())
            ->where('user_id', $user_id)
            ->whereBetween('created_at', [$desde, $hasta])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get();

        $salida = [];

        foreach ($filas as $fila) {
            $salida[] = [
                'fecha'                    => (string) $fila->fecha,
                'busquedas'                => (int) $fila->busquedas,
                'busquedas_cobradas'       => (int) $fila->busquedas_cobradas,
                'busquedas_serper'         => (int) $fila->busquedas_serper,
                'busquedas_google'         => (int) $fila->busquedas_google,
                'validaciones_ia'          => (int) $fila->validaciones_ia,
                'validaciones_ia_cobradas' => (int) $fila->validaciones_ia_cobradas,
                'errores'                  => (int) $fila->errores,
            ];
        }

        return $salida;
    }

    /**
     * El corte por modelo de IA (el modelo es la unidad de precio del admin).
     *
     * @param  int    $user_id
     * @param  Carbon $desde
     * @param  Carbon $hasta
     * @return array
     */
    protected function modelos($user_id, Carbon $desde, Carbon $hasta)
    {
        $filas = DB::table('image_service_calls')
            ->selectRaw('modelo, COUNT(*) as llamadas, '
                .'SUM(COALESCE(tokens_entrada, 0)) as tokens_entrada, '
                .'SUM(COALESCE(tokens_salida, 0)) as tokens_salida, '
                .'SUM(COALESCE(tokens_cache_escritura, 0)) as tokens_cache_escritura, '
                .'SUM(COALESCE(tokens_cache_lectura, 0)) as tokens_cache_lectura')
            ->where('user_id', $user_id)
            ->where('tipo', ImageServiceCall::TIPO_VALIDACION_IA)
            ->whereBetween('created_at', [$desde, $hasta])
            ->groupBy('modelo')
            ->orderBy('modelo')
            ->get();

        $salida = [];

        foreach ($filas as $fila) {
            $salida[] = [
                'modelo'                 => (string) $fila->modelo,
                'llamadas'               => (int) $fila->llamadas,
                'tokens_entrada'         => (int) $fila->tokens_entrada,
                'tokens_salida'          => (int) $fila->tokens_salida,
                'tokens_cache_escritura' => (int) $fila->tokens_cache_escritura,
                'tokens_cache_lectura'   => (int) $fila->tokens_cache_lectura,
            ];
        }

        return $salida;
    }

    /**
     * Las asignaciones creadas en el rango, más nuevas primero, MAXIMO_DE_ASIGNACIONES como mucho.
     * Los conteos por estado salen de ImageAssignmentRunHelper::conteos_de(), los mismos que ve la
     * SPA en Alertas → Imágenes.
     *
     * @param  int    $user_id
     * @param  Carbon $desde
     * @param  Carbon $hasta
     * @return array
     */
    protected function asignaciones($user_id, Carbon $desde, Carbon $hasta)
    {
        $runs = ImageAssignmentRun::where('user_id', $user_id)
            ->whereBetween('created_at', [$desde, $hasta])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::MAXIMO_DE_ASIGNACIONES)
            ->get();

        $ids = [];

        foreach ($runs as $run) {
            $ids[] = (int) $run->id;
        }

        $conteos = ImageAssignmentRunHelper::conteos_de($ids);

        $salida = [];

        foreach ($runs as $run) {
            $conteo = $conteos[(int) $run->id];

            $salida[] = [
                'id'              => (int) $run->id,
                'uuid'            => (string) $run->uuid,
                'created_at'      => $this->fecha($run->created_at),
                'origen'          => (string) $run->origen,
                'status'          => (string) $run->status,
                'proveedor'       => (string) $run->proveedor,
                'total_articulos' => (int) $run->total_articulos,
                'asignadas'       => (int) $conteo['asignadas'],
                'a_revisar'       => (int) $conteo['a_revisar'],
                'no_asignadas'    => (int) $conteo['no_asignadas'],
                'busquedas'       => (int) $run->busquedas,
                'validaciones_ia' => (int) $run->validaciones_ia,
            ];
        }

        return $salida;
    }

    /**
     * Una fila del registro con los campos exactos del contrato (plan §12.1).
     *
     * @param  object $fila
     * @return array
     */
    protected function fila_de_consulta($fila)
    {
        return [
            'id'                     => (int) $fila->id,
            'created_at'             => $this->fecha($fila->created_at),
            'tipo'                   => (string) $fila->tipo,
            'origen'                 => (string) $fila->origen,
            'proveedor'              => (string) $fila->proveedor,
            'modelo'                 => $fila->modelo,
            'criterio'               => $fila->criterio,
            'consulta'               => $fila->consulta,
            'article_id'             => $this->entero_o_null($fila->article_id),
            'article_name'           => $fila->article_name,
            'run_id'                 => $this->entero_o_null($fila->run_id),
            'ok'                     => (bool) $fila->ok,
            'cobrada'                => (bool) $fila->cobrada,
            'http_status'            => $this->entero_o_null($fila->http_status),
            'error'                  => $fila->error,
            'resultados'             => $this->entero_o_null($fila->resultados),
            'candidatas'             => $this->entero_o_null($fila->candidatas),
            'resumen'                => $fila->resumen,
            'tokens_entrada'         => $this->entero_o_null($fila->tokens_entrada),
            'tokens_salida'          => $this->entero_o_null($fila->tokens_salida),
            'tokens_cache_escritura' => $this->entero_o_null($fila->tokens_cache_escritura),
            'tokens_cache_lectura'   => $this->entero_o_null($fila->tokens_cache_lectura),
            'duracion_ms'            => $this->entero_o_null($fila->duracion_ms),
        ];
    }

    /**
     * Las sumas por tipo y estado que comparten los totales y el corte por día. Los literales son
     * constantes de la clase, no datos del request.
     *
     * @return string
     */
    protected function sumas_de_conteo()
    {
        $busqueda   = "'".ImageServiceCall::TIPO_BUSQUEDA."'";
        $validacion = "'".ImageServiceCall::TIPO_VALIDACION_IA."'";

        return 'COALESCE(SUM(CASE WHEN tipo = '.$busqueda.' THEN 1 ELSE 0 END), 0) as busquedas, '
            .'COALESCE(SUM(CASE WHEN tipo = '.$busqueda.' AND cobrada = 1 THEN 1 ELSE 0 END), 0) as busquedas_cobradas, '
            ."COALESCE(SUM(CASE WHEN tipo = ".$busqueda." AND proveedor = 'serper' THEN 1 ELSE 0 END), 0) as busquedas_serper, "
            ."COALESCE(SUM(CASE WHEN tipo = ".$busqueda." AND proveedor = 'google' THEN 1 ELSE 0 END), 0) as busquedas_google, "
            .'COALESCE(SUM(CASE WHEN tipo = '.$validacion.' THEN 1 ELSE 0 END), 0) as validaciones_ia, '
            .'COALESCE(SUM(CASE WHEN tipo = '.$validacion.' AND cobrada = 1 THEN 1 ELSE 0 END), 0) as validaciones_ia_cobradas, '
            .'COALESCE(SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END), 0) as errores';
    }

    /**
     * Una fecha en ISO 8601 con la zona de la app (el mismo formato que usan las fechas de las
     * asignaciones en la SPA).
     *
     * @param  mixed $fecha
     * @return string|null
     */
    protected function fecha($fecha)
    {
        return is_null($fecha) ? null : Carbon::parse($fecha)->toIso8601String();
    }

    /**
     * @param  mixed $valor
     * @return int|null
     */
    protected function entero_o_null($valor)
    {
        return is_null($valor) ? null : (int) $valor;
    }

    /**
     * Valida el header `X-Admin-Api-Key` SOLO si este cliente tiene la clave cargada: exactamente la
     * misma regla que ConsumoIaController::rechazo_por_clave() (ver ahí el porqué, largo). Lo que
     * sale de acá son consultas de imágenes (nombres de artículos, qué se buscó, qué tardó), nada de
     * ventas, clientes, precios ni stock; y el endpoint no escribe nada.
     *
     * @param  Request $request
     * @return JsonResponse|null
     */
    protected function rechazo_por_clave(Request $request)
    {
        $esperada = (string) config('services.admin_api.api_key');

        if ($esperada === '') {
            return null;
        }

        $recibida = (string) $request->header('X-Admin-Api-Key');

        if ($recibida === '' || !hash_equals($esperada, $recibida)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }
}
