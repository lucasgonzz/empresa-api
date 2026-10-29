<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Lo que el agente sabe del negocio, visto y corregido por el dueño desde la app (misión
 * app-ia-y-memoria, 29/10/2026, contrato C5 del plan):
 *
 *   GET  api/agente-contexto                     → {archivos: [{ruta, titulo, resumen, contenido, version, origen, actualizado_en}]}
 *   PUT  api/agente-contexto  {ruta, contenido}  → {archivo: {...}}
 *
 * Las dos van en el gate del chat (auth:sanctum + check_extencion_empresa:agentes_ia +
 * solo_el_dueno_ia). La memoria es del COMERCIO: se lee y se escribe en el dueño
 * (UserHelper::user(true)), abra quien la abra, igual que la config del asistente.
 *
 * El PUT reemplaza el archivo entero con origen `dueno` y deja la revisión: lo que había antes no se
 * pierde. El índice no se escribe (lo arma el sistema). Los errores traen `{code, message}`:
 * `validacion`, `ruta_invalida`, `archivo_generado`, `contexto_muy_largo`, `demasiados_archivos` (422)
 * y `sin_dueno` (409).
 */
class AgenteContextoController extends Controller
{
    /**
     * GET api/agente-contexto — todos los archivos de la memoria. Si todavía no existía, la crea.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $owner = UserHelper::user(true);

        if (is_null($owner)) {

            return $this->sin_dueno();
        }

        return response()->json(['archivos' => AgenteContextoHelper::archivos($owner)], 200);
    }

    /**
     * PUT api/agente-contexto — el dueño reemplaza un archivo (o crea uno nuevo en una carpeta válida).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'ruta'      => 'required|string|max:150',
            'contenido' => 'present|nullable|string|max:' . AgenteContextoHelper::TOPE_ARCHIVO,
        ]);

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

        $resultado = AgenteContextoHelper::guardar(
            $owner,
            (string) $request->input('ruta'),
            AgenteContextoHelper::REEMPLAZAR,
            (string) $request->input('contenido'),
            null,
            'Lo editó el dueño desde la app',
            AgenteContextoHelper::ORIGEN_DUENO,
            null
        );

        if (!$resultado['ok']) {

            return response()->json([
                'code'    => $resultado['code'],
                'message' => $resultado['error'],
            ], $resultado['code'] === 'sin_dueno' ? 409 : 422);
        }

        return response()->json(['archivo' => $resultado['archivo']], 200);
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
