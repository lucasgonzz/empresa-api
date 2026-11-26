<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ActualizacionDelSistemaHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los cuatro endpoints con los que admin-api maneja el aviso de actualización del sistema durante el
 * pipeline (misión aviso-actualizacion-sistema, 25/11/2026), autenticados con `X-Admin-Api-Key`
 * (middleware `admin.api.key` del grupo de rutas):
 *
 *   GET    api/admin-sync/actualizacion-del-sistema         lee el estado (evalúa las transiciones)
 *   PUT    api/admin-sync/actualizacion-del-sistema         prende (`actualizando`) o libera (`libre`)
 *   POST   api/admin-sync/actualizacion-del-sistema/pedido  abre el pedido de OK (idempotente)
 *   DELETE api/admin-sync/actualizacion-del-sistema/pedido  cancela el pedido (vuelve a `libre`)
 *
 * El controlador sólo traduce HTTP: lee la entrada, valida, resuelve el dueño y delega TODO en
 * `ActualizacionDelSistemaHelper`. Todas las respuestas de éxito son el `AvisoEstado` del contrato.
 *
 * Validación a mano (no `$request->validate()`): el contrato del admin exige 422 con el formato
 * `{error: 'validacion', message}` propio del canal admin-sync, igual que `SessionLockController`.
 *
 * Un empresa viejo no tiene estas rutas y contesta 404: el admin lo lee como "esta versión no soporta el
 * aviso". Durante el `down` del deploy, antes de migrar, las tablas todavía no existen: 409
 * `tabla_pendiente` (el admin reintenta después de las migraciones).
 *
 * Nota de arquitectura: el repo no tiene una clase `__base_controller` (`CommonLaravel/__base_controller.php`
 * es una plantilla de texto con `MODEL_NAME`); como todos los controladores de `AdminSync`, extiende
 * `App\Http\Controllers\Controller`.
 */
class ActualizacionDelSistemaController extends Controller
{
    /**
     * Lee el estado del dueño.
     *
     * @param \Illuminate\Http\Request $request Query `user_id?`.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        return $this->ejecutar($request, false, function ($owner_id) {
            return ActualizacionDelSistemaHelper::leer_para_admin($owner_id);
        });
    }

    /**
     * Prende o libera el estado.
     *
     * Cuerpo: `estado` (`actualizando`|`libre`, requerido), `upgrade_id?`, `version?` (hasta 40),
     * `vence_en_minutos?` (5..240, por defecto 120), `user_id?`.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        return $this->ejecutar($request, true, function ($owner_id) use ($request) {
            return ActualizacionDelSistemaHelper::aplicar_para_admin($owner_id, $request->all());
        });
    }

    /**
     * Abre el pedido de OK a las computadoras conectadas.
     *
     * Cuerpo: `upgrade_id?`, `version?`, `minutos_para_responder?` (1..30, por defecto 10), `user_id?`.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function pedir(Request $request): JsonResponse
    {
        return $this->ejecutar($request, false, function ($owner_id) use ($request) {
            return ActualizacionDelSistemaHelper::pedir_ok($owner_id, $request->all());
        });
    }

    /**
     * Cancela el pedido de OK (o el "Ahora no" que lo dejó `rechazado`).
     *
     * @param \Illuminate\Http\Request $request Query o cuerpo `user_id?`.
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelar(Request $request): JsonResponse
    {
        return $this->ejecutar($request, false, function ($owner_id) {
            return ActualizacionDelSistemaHelper::cancelar_pedido($owner_id);
        });
    }

    /**
     * Esqueleto común de los cuatro endpoints: valida la entrada (422 `validacion`), resuelve el dueño
     * (422 `owner_no_encontrado` con `user_id` explícito; 409 `dueno_no_resoluble` sin él y sin USER_ID), comprueba que las tablas existan (409 `tabla_pendiente`) y ejecuta la
     * operación del helper.
     *
     * @param \Illuminate\Http\Request $request
     * @param bool                     $exige_estado Si el cuerpo tiene que traer `estado` (el PUT).
     * @param callable                 $operacion    Recibe el id del dueño y devuelve `['status', 'body']`.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function ejecutar(Request $request, $exige_estado, callable $operacion): JsonResponse
    {
        $mensaje = ActualizacionDelSistemaHelper::validar_entrada($request->all(), $exige_estado);

        if (!is_null($mensaje)) {
            return response()->json(['error' => 'validacion', 'message' => $mensaje], 422);
        }

        $user_id = $request->input('user_id');
        $owner_id = ActualizacionDelSistemaHelper::resolver_owner_del_admin($user_id);

        if (is_null($owner_id)) {
            // Un `user_id` explícito que no es dueño es un error del pedido (422). Sin `user_id` y sin poder
            // decidir el dueño de la instancia (varios dueños y sin USER_ID) es 409, igual que el resto de
            // admin-sync: nunca se elige "el primero".
            if (!is_null($user_id) && $user_id !== '') {
                return response()->json([
                    'error'   => 'owner_no_encontrado',
                    'message' => 'No se encontró el dueño al que aplicar el estado de actualización.',
                ], 422);
            }

            return response()->json([
                'error'   => 'dueno_no_resoluble',
                'code'    => 'dueno_no_resoluble',
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        if (!ActualizacionDelSistemaHelper::tabla_disponible()) {
            return response()->json([
                'error'   => 'tabla_pendiente',
                'message' => 'Las tablas del aviso de actualización todavía no se migraron.',
            ], 409);
        }

        $resultado = $operacion($owner_id);

        return response()->json($resultado['body'], $resultado['status']);
    }
}
