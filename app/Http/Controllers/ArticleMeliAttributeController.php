<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\TenenciaDeLaTiendaHelper;
use App\Models\Article;
use App\Models\ArticleMeliAttribute;
use Illuminate\Http\Request;

/**
 * Atributos de Mercado Libre cargados en un artículo (`article_meli_attribute`).
 *
 * Revisión de la misión mercado-libre-integracion (27/10/2026): show/store/update no cruzaban el
 * artículo con el dueño de la sesión, así que se podían leer y escribir los atributos de un
 * artículo de OTRO comercio mandando su id (y el formulario nuevo usa `show` para precargar). Ahora
 * lo ajeno contesta 404 igual que lo inexistente, como el resto de la tenencia
 * (`TenenciaDeLaTiendaHelper`).
 */
class ArticleMeliAttributeController extends Controller
{
    /** Respuesta de tenencia (ajeno, inexistente y no-id contestan lo mismo). */
    const MENSAJE_AJENO = 'El artículo no existe o no es de tu cuenta.';

    /**
     * Atributos de ML del artículo: `GET article-meli-attribute/{article_id}`.
     *
     * @param mixed $article_id
     * @return \Illuminate\Http\JsonResponse
     */
    function show($article_id) {
        $ajeno = $this->articulo_ajeno_o_404($article_id);
        if ($ajeno) {
            return $ajeno;
        }

        $models = ArticleMeliAttribute::where('article_id', $article_id)
                                    ->withAll()
                                    ->get();

        return response()->json(['models'    => $models], 200);
    }

    /**
     * @param Request $request
     * @param mixed $id Id de la fila de `article_meli_attribute`.
     * @return \Illuminate\Http\JsonResponse
     */
    function update(Request $request, $id) {

        $model = ArticleMeliAttribute::find($id);

        $ajeno = $this->articulo_ajeno_o_404($model ? $model->article_id : null);
        if ($ajeno) {
            return $ajeno;
        }

        $model->meli_attribute_value_id     = $request->meli_attribute_value_id;
        $model->value_id              = $request->value_id;
        $model->value_name            = $request->value_name;

        $model->save();

        return response()->json(['model'    => $model], 200);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function store(Request $request) {
        $ajeno = $this->articulo_ajeno_o_404($request->article_id);
        if ($ajeno) {
            return $ajeno;
        }

        $model = ArticleMeliAttribute::create([
            'article_id'            => $request->article_id,
            'meli_attribute_id'     => $request->meli_attribute_id,
            'meli_attribute_value_id'     => $request->meli_attribute_value_id,
            'value_id'              => $request->value_id,
            'value_name'            => $request->value_name,
        ]);
        return response()->json(['model'    => $model], 201);
    }

    /**
     * Null si el artículo es del dueño de la sesión; si no, el 404 que el método contesta tal cual.
     *
     * @param mixed $article_id
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function articulo_ajeno_o_404($article_id)
    {
        return TenenciaDeLaTiendaHelper::respuesta_si_no_es_del_dueno(Article::class, $article_id, $this->userId(), self::MENSAJE_AJENO);
    }
}
