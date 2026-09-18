<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La configuración del asistente de IA del negocio y el consumo de su plan (misión
 * foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * Las tres rutas van con el gate del chat (auth:sanctum + check_extencion_empresa:asistente_ia +
 * solo_el_dueno_ia): es la misma decisión de Lucas de que solo el dueño (o admin_access / acceso
 * maestro) toca el módulo IA.
 *
 * 🔴 LA CONFIG SE GUARDA Y SE LEE DEL DUEÑO, NO DE LA PERSONA. A diferencia de
 * UserController::set_chat_ia_preferencias() —que guarda con Auth::user() porque una coordenada de
 * pantalla es de cada persona—, la confianza y el modelo del agente son del COMERCIO: el asistente
 * contesta lo mismo abra quien lo abra. Por eso se resuelve con UserHelper::user(true), que devuelve
 * el dueño aunque quien esté autenticado sea un admin_access.
 */
class AsistenteConfigController extends Controller
{
    /** Valores válidos de `agente_confianza`. */
    const CONFIANZAS = ['cauteloso', 'resuelto'];

    /** Valores válidos de `agente_pensamiento`. */
    const PENSAMIENTOS = ['agil', 'profundo'];

    /** Default de la confianza (coincide con el default de la columna). */
    const CONFIANZA_POR_DEFECTO = 'resuelto';

    /** Default del modelo (coincide con el default de la columna). */
    const PENSAMIENTO_POR_DEFECTO = 'agil';

    /**
     * GET api/user/asistente-config → {confianza, pensamiento} del dueño.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return response()->json(['message' => 'No se pudo resolver el dueño de la cuenta.'], 409);
        }

        return response()->json($this->config_de($owner), 200);
    }

    /**
     * PUT api/user/asistente-config → valida los enums y guarda en el dueño.
     *
     * Las dos claves son obligatorias: el modal las manda juntas. Un valor fuera del enum corta con
     * 422 sin guardar nada.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'confianza'   => 'required|in:' . implode(',', self::CONFIANZAS),
            'pensamiento' => 'required|in:' . implode(',', self::PENSAMIENTOS),
        ]);

        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return response()->json(['message' => 'No se pudo resolver el dueño de la cuenta.'], 409);
        }

        $owner->agente_confianza = (string) $request->input('confianza');
        $owner->agente_pensamiento = (string) $request->input('pensamiento');
        $owner->save();

        return response()->json($this->config_de($owner), 200);
    }

    /**
     * GET api/mi-consumo-ia → lo que muestra el footer del panel: consumo del mes contra el tope del
     * plan, más el modo activo del agente.
     *
     * @return JsonResponse
     */
    public function mi_consumo(): JsonResponse
    {
        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return response()->json(['message' => 'No se pudo resolver el dueño de la cuenta.'], 409);
        }

        $estado = TopeDeTokensHelper::estado($owner);

        return response()->json([
            'consumo_mes' => [
                'tokens'        => $estado['consumo_tokens'],
                'interacciones' => $estado['consumo_interacciones'],
            ],
            'plan' => [
                'nombre'                     => is_null($owner->plan_ia_nombre) ? null : (string) $owner->plan_ia_nombre,
                'tope_tokens_mensual'        => $estado['tope_tokens'],
                'tope_interacciones_diarias' => $estado['tope_interacciones'],
            ],
            'cerca'       => $estado['cerca'],
            'supero'      => $estado['supero'],
            'pensamiento' => $this->pensamiento_de($owner),
            'confianza'   => $this->confianza_de($owner),
        ], 200);
    }

    /**
     * {confianza, pensamiento} del dueño, con los defaults por si la columna quedó en null.
     *
     * @param  \App\Models\User  $owner
     * @return array
     */
    protected function config_de($owner): array
    {
        return [
            'confianza'   => $this->confianza_de($owner),
            'pensamiento' => $this->pensamiento_de($owner),
        ];
    }

    /**
     * La confianza del dueño, cayendo al default si viene vacía o fuera del enum.
     *
     * @param  \App\Models\User  $owner
     * @return string
     */
    protected function confianza_de($owner): string
    {
        $valor = (string) $owner->agente_confianza;

        return in_array($valor, self::CONFIANZAS, true) ? $valor : self::CONFIANZA_POR_DEFECTO;
    }

    /**
     * El modo de pensamiento del dueño, cayendo al default si viene vacío o fuera del enum.
     *
     * @param  \App\Models\User  $owner
     * @return string
     */
    protected function pensamiento_de($owner): string
    {
        $valor = (string) $owner->agente_pensamiento;

        return in_array($valor, self::PENSAMIENTOS, true) ? $valor : self::PENSAMIENTO_POR_DEFECTO;
    }
}
