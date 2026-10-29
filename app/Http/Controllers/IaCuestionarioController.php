<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Http\Controllers\Helpers\asistente_ia\CuestionarioDeInicioIaHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * El cuestionario de inicio de la app del agente, «Conozcámonos» (misión app-ia-y-memoria,
 * 29/10/2026, §E2.4 y contrato C5 del plan):
 *
 *   GET   api/ia-app/cuestionario           → {completado, salteado, completado_en, respuestas: {...}|null,
 *                                            preferencias: {largo, forma, confianza}}
 *   POST  api/ia-app/cuestionario           → lo mismo + {escritos: [rutas], avisos: [...]}
 *   POST  api/ia-app/cuestionario/saltear   → 204
 *   PATCH api/ia-app/preferencias {largo?, forma?, confianza?} → {largo, forma, confianza}
 *
 * Mismo gate que el chat (auth:sanctum + check_extencion_empresa:agentes_ia + solo_el_dueno_ia). Las
 * respuestas se guardan en el DUEÑO (UserHelper::user(true)): el cuestionario es del comercio. La
 * escritura es determinista y vive en CuestionarioDeInicioIaHelper; la confianza se guarda en la misma
 * columna y con el mismo enum que PUT user/asistente-config.
 *
 * Errores con `{code, message}`: `validacion` (422) y `sin_dueno` (409).
 */
class IaCuestionarioController extends Controller
{
    /**
     * GET api/ia-app/cuestionario.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return $this->sin_dueno();
        }

        return response()->json($this->estado($owner), 200);
    }

    /**
     * POST api/ia-app/cuestionario — valida, escribe la memoria y la confianza, y lo marca completado.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validador = Validator::make($request->all(), CuestionarioDeInicioIaHelper::reglas());

        if ($validador->fails()) {

            return response()->json([
                'code'    => 'validacion',
                'message' => $validador->errors()->first(),
                'errors'  => $validador->errors(),
            ], 422);
        }

        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return $this->sin_dueno();
        }

        $resultado = CuestionarioDeInicioIaHelper::completar($owner, $validador->validated());

        $respuesta = $this->estado($owner->fresh());
        $respuesta['escritos'] = $resultado['escritos'];
        $respuesta['avisos']   = $resultado['avisos'];

        return response()->json($respuesta, 200);
    }

    /**
     * POST api/ia-app/cuestionario/saltear — «Ahora no». Idempotente.
     *
     * @return JsonResponse|Response
     */
    public function saltear()
    {
        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return $this->sin_dueno();
        }

        CuestionarioDeInicioIaHelper::saltear($owner);

        return response()->noContent();
    }

    /**
     * PATCH api/ia-app/preferencias — body parcial `{largo?, forma?, confianza?}` → `{largo, forma,
     * confianza}` vigentes. Reescribe SOLO el bloque de cómo responder de la memoria (origen `dueno`, con
     * revisión) y la confianza por la misma columna que la configuración del asistente. Lo que no vino
     * (o viene null) no cambia.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function preferencias(Request $request): JsonResponse
    {
        $validador = Validator::make($request->all(), CuestionarioDeInicioIaHelper::reglas_de_preferencias());

        if ($validador->fails()) {

            return response()->json([
                'code'    => 'validacion',
                'message' => $validador->errors()->first(),
                'errors'  => $validador->errors(),
            ], 422);
        }

        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return $this->sin_dueno();
        }

        $resultado = CuestionarioDeInicioIaHelper::cambiar_preferencias($owner, $validador->validated());

        return response()->json($resultado['preferencias'], 200);
    }

    /**
     * El estado del cuestionario, las respuestas guardadas y las preferencias vigentes (estas van
     * siempre, aunque el cuestionario se haya salteado).
     *
     * @param  \App\Models\User  $owner
     * @return array
     */
    protected function estado($owner): array
    {
        $estado = AgenteContextoHelper::estado_cuestionario($owner);
        $estado['respuestas']   = CuestionarioDeInicioIaHelper::respuestas($owner);
        $estado['preferencias'] = CuestionarioDeInicioIaHelper::preferencias($owner);

        return $estado;
    }

    /**
     * La respuesta cuando no se puede resolver el dueño de la cuenta.
     *
     * @return JsonResponse
     */
    protected function sin_dueno(): JsonResponse
    {
        return response()->json([
            'code'    => 'sin_dueno',
            'message' => 'No se pudo resolver el dueño de la cuenta.',
        ], 409);
    }
}
