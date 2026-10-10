<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\RecipeRoute;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RecipeRouteController extends Controller
{

    public function index() {
        $models = RecipeRoute::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    /**
     * Normaliza a null el 0 que manda el select de la SPA cuando el usuario deja "Seleccione...".
     *
     * Sin este guard, un 0 en order_production_status_group_id haria que la cascada del estado
     * final busque el grupo con id 0 en vez de caer al comportamiento global, y un 0 en
     * end_order_production_status_id la haria comparar contra un estado inexistente: en los dos
     * casos el lote deja de dar de alta el producto y nada avisa.
     *
     * @param  mixed  $value
     * @return int|null
     */
    private function nullIfZero($value)
    {
        if (is_null($value) || $value == 0) {
            return null;
        }

        return $value;
    }

    /**
     * Rechaza la ruta si algun insumo no trae el estado en el que se consume.
     *
     * El select "Estado" del insumo manda 0 cuando el usuario deja "Seleccione...", y un insumo
     * con el estado en 0 (o en null) no se consume en NINGUN movimiento: la ruta se guarda, se ve
     * completa y el stock del insumo nunca baja. Sin error y sin log. Por eso se rechaza con un
     * aviso que nombra el insumo, en vez de guardarlo a medias (decision de Lucas, 10/10/2026).
     *
     * 🔴 Tiene que llamarse ANTES de cualquier escritura. GeneralHelper::attachModels() hace
     * detach() de todos los insumos antes de re-adjuntar: validar despues dejaria la ruta sin
     * insumos con un 422 que llega tarde.
     *
     * Responde 422 con `errors` como MAPA (articles => lista de textos) y `message`: es la forma
     * que la SPA muestra como toast aun sin sesion.
     *
     * @param  mixed  $articles  Los insumos tal como llegan en el request (cada uno con su `pivot`).
     * @return void
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validar_estado_de_los_insumos($articles)
    {
        if (!is_array($articles)) {
            return;
        }

        $mensajes = [];

        foreach ($articles as $article) {

            $estado_id = GeneralHelper::getPivotValue($article, 'order_production_status_id');

            if (!is_null($estado_id) && (int)$estado_id !== 0) {
                continue;
            }

            if (isset($article['name']) && trim((string)$article['name']) !== '') {
                $nombre = $article['name'];
            } else if (isset($article['id'])) {
                $nombre = 'sin nombre (id '.$article['id'].')';
            } else {
                $nombre = 'sin nombre';
            }

            $mensajes[] = 'Elegí en qué estado se consume el insumo "'.$nombre.'".';
        }

        if (count($mensajes) > 0) {
            throw ValidationException::withMessages(['articles' => $mensajes]);
        }
    }

    public function store(Request $request) {
        $this->validar_estado_de_los_insumos($request->articles);

        $model = RecipeRoute::create([
            'recipe_id'                 => $request->recipe_id,
            'recipe_route_type_id'      => $request->recipe_route_type_id,
            'from_address_id'           => $request->from_address_id,
            'to_address_id'             => $request->to_address_id,
            'order_production_status_group_id' => $this->nullIfZero($request->order_production_status_group_id),
            'end_order_production_status_id'   => $this->nullIfZero($request->end_order_production_status_id),
            'temporal_id'               => $this->getTemporalId($request),
            'recipe_id'                 => $request->model_id,
            'notes'                     => $request->notes,
        ]);

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount', 'notes', 'order_production_status_id', 'address_id']);

        return response()->json(['model' => $this->fullModel('RecipeRoute', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('RecipeRoute', $id)], 200);
    }

    public function update(Request $request, $id) {
        $this->validar_estado_de_los_insumos($request->articles);

        $model = RecipeRoute::find($id);
        $model->recipe_route_type_id      = $request->recipe_route_type_id;
        $model->from_address_id           = $request->from_address_id;
        $model->to_address_id             = $request->to_address_id;
        $model->order_production_status_group_id = $this->nullIfZero($request->order_production_status_group_id);
        $model->end_order_production_status_id   = $this->nullIfZero($request->end_order_production_status_id);

        // Las notas se escriben solo si el request trae la clave: un cliente que no la manda no
        // tiene que borrarle la nota a la ruta con un null que nunca pidio.
        if ($request->has('notes')) {
            $model->notes = $request->notes;
        }

        $model->save();

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount', 'notes', 'order_production_status_id', 'address_id']);
        
        return response()->json(['model' => $this->fullModel('RecipeRoute', $model->id)], 200);
    }

    public function destroy($id) {
        $model = RecipeRoute::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('RecipeRoute', $model->id);
        return response(null);
    }
}
