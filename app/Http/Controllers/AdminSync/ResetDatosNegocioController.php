<?php

namespace App\Http\Controllers\AdminSync;

use App\Exceptions\ResetDatosNegocioRechazadoException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ResetDatosNegocio\ResetDatosNegocioHelper;
use App\Http\Middleware\ClaveDeAdminEnSetup;
use Illuminate\Http\Request;

/**
 * Reset de datos de negocio del cliente, pedido por el admin (misión reset-datos-cliente, 21/11/2026).
 *
 * `POST api/admin-sync/reset-datos-negocio` simula o ejecuta (contrato C1 del plan) y
 * `GET api/admin-sync/reset-datos-negocio/registros` lista los últimos intentos. Toda la lógica vive en
 * `ResetDatosNegocioHelper`: este controlador sólo exige la clave y traduce excepciones a HTTP.
 *
 * Autenticación en dos capas: la ruta va dentro de `admin.api.key:siempre` (exige `X-Admin-Api-Key` aunque la
 * instancia no tenga clave cargada) y acá se vuelve a exigir con `ClaveDeAdminEnSetup::trae_la_clave_de_admin()`,
 * el comparador que usan las demás puertas destructivas. Una instancia sin `ADMIN_API_INBOUND_KEY` contesta 401.
 *
 * No es un CRUD y no usa `fullModel()`. Hereda de `Controller` como los demás controladores de AdminSync (la clase
 * `CommonLaravel\__base_controller` es una plantilla de texto, sin clase que extender).
 */
class ResetDatosNegocioController extends Controller
{
    /**
     * Simula (por defecto) o ejecuta el reset.
     *
     * @param Request $request Pedido HTTP con el cuerpo del contrato C1.
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        if (!ClaveDeAdminEnSetup::trae_la_clave_de_admin($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        try {
            return response()->json(ResetDatosNegocioHelper::procesar($this->cuerpo_crudo($request), $request), 200);
        } catch (ResetDatosNegocioRechazadoException $e) {
            return $this->respuesta_de_error($e);
        }
    }

    /**
     * Últimos registros del reset (por defecto 10), sin el hash del token.
     *
     * @param Request $request Pedido HTTP; `limit` opcional (1 a 50).
     * @return \Illuminate\Http\JsonResponse
     */
    public function registros(Request $request)
    {
        if (!ClaveDeAdminEnSetup::trae_la_clave_de_admin($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return response()->json([
            'ok' => true,
            'registros' => ResetDatosNegocioHelper::registros((int) $request->query('limit', 10)),
        ], 200);
    }

    /**
     * Cuerpo del pedido tal como llegó.
     *
     * Se lee el JSON crudo y no `$request->all()` porque los middlewares `TrimStrings` y `ConvertEmptyStringsToNull`
     * recortan espacios y convierten '' en null: un nombre de base con espacios alrededor NO tiene que confirmar el reset.
     *
     * @param Request $request Pedido HTTP.
     * @return array
     */
    protected function cuerpo_crudo(Request $request)
    {
        $contenido = (string) $request->getContent();

        if ($contenido !== '') {
            $decodificado = json_decode($contenido, true);

            if (is_array($decodificado)) {
                return $decodificado;
            }
        }

        return $request->all();
    }

    /**
     * Arma la respuesta de error del contrato C1: `{ok:false, error, mensaje, detalle?, registro_id?}`.
     *
     * @param ResetDatosNegocioRechazadoException $e Rechazo o falla.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_de_error(ResetDatosNegocioRechazadoException $e)
    {
        $cuerpo = ['ok' => false, 'error' => $e->codigo(), 'mensaje' => $e->getMessage()];

        if (count($e->detalle()) > 0) {
            $cuerpo['detalle'] = $e->detalle();
        }

        if ($e->registro_id() !== null) {
            $cuerpo['registro_id'] = $e->registro_id();
        }

        return response()->json($cuerpo, $e->http_status());
    }
}
