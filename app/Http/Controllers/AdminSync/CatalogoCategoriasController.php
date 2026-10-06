<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\AdminSync\Concerns\ClaveEstrictaDeAdmin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\category_proposal\CategoryProposalIngestaHelper;
use Illuminate\Http\Request;

/**
 * Lo que la skill /categorizar escribe: la corrida, las propuestas y las asignaciones (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato A del plan, §5.3 a §5.8. Dueño por
 * `AsistenteCanalHelper::dueno()`; la clave `X-Admin-Api-Key` se exige SIEMPRE, adentro del
 * controlador (trait ClaveEstrictaDeAdmin). Nada de esto toca `categories` ni `articles`.
 *
 * Solo traduce a HTTP: la lógica vive en CategoryProposalIngestaHelper, que devuelve
 * `['status' => int, 'body' => array]`. Un `run_id` de otro comercio se contesta igual que uno
 * inexistente (404 `no_encontrado`).
 */
class CatalogoCategoriasController extends Controller
{
    use ClaveEstrictaDeAdmin;

    /**
     * POST admin-sync/catalogo/categorias/propuestas — crea la corrida con sus propuestas y árboles.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function crear(Request $request)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return $this->responder(CategoryProposalIngestaHelper::crear(AsistenteCanalHelper::dueno(), $request->all()));
    }

    /**
     * GET admin-sync/catalogo/categorias/propuestas/actual — la última corrida no descartada.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function actual(Request $request)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return $this->responder(CategoryProposalIngestaHelper::actual(AsistenteCanalHelper::dueno()));
    }

    /**
     * GET admin-sync/catalogo/categorias/propuestas/{run_id} — estado y progreso.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function mostrar(Request $request, $run_id)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return $this->responder(CategoryProposalIngestaHelper::mostrar(AsistenteCanalHelper::dueno(), $run_id));
    }

    /**
     * POST admin-sync/catalogo/categorias/propuestas/{run_id}/asignaciones — lote de hasta 500.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function asignaciones(Request $request, $run_id)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return $this->responder(CategoryProposalIngestaHelper::asignaciones(AsistenteCanalHelper::dueno(), $run_id, $request->all()));
    }

    /**
     * GET admin-sync/catalogo/categorias/propuestas/{run_id}/pendientes?propuesta=A&limite=300 —
     * artículos sin ítem en esa propuesta (lo que hace la corrida retomable).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendientes(Request $request, $run_id)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return $this->responder(CategoryProposalIngestaHelper::pendientes(
            AsistenteCanalHelper::dueno(),
            $run_id,
            $request->query('propuesta'),
            $request->query('limite', CategoryProposalIngestaHelper::PENDIENTES_POR_DEFECTO)
        ));
    }

    /**
     * POST admin-sync/catalogo/categorias/propuestas/{run_id}/listo — publica la corrida al dueño.
     * La skill NO lo llama sin el ok explícito de Lucas.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function listo(Request $request, $run_id)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return $this->responder(CategoryProposalIngestaHelper::listo(AsistenteCanalHelper::dueno(), $run_id, $request->all()));
    }

    /**
     * POST admin-sync/catalogo/categorias/propuestas/{run_id}/descartar — la da de baja.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function descartar(Request $request, $run_id)
    {
        $rechazo = $this->rechazo_de_acceso($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        return $this->responder(CategoryProposalIngestaHelper::descartar(AsistenteCanalHelper::dueno(), $run_id));
    }

    /**
     * Traduce lo que devuelve el helper (`status` + `body`) a la respuesta HTTP.
     *
     * @param  array $resultado  ['status' => int, 'body' => array]
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder(array $resultado)
    {
        return response()->json($resultado['body'], $resultado['status']);
    }
}
