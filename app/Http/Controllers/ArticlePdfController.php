<?php

namespace App\Http\Controllers;

use App\Models\ArticlePdf;
use Illuminate\Http\Request;

/**
 * ABM de plantillas de diseño para el PDF de ofertas de artículos.
 */
class ArticlePdfController extends Controller
{
    /**
     * Reglas de validación para crear o actualizar una plantilla (flags booleanos sin `$casts` en el modelo).
     *
     * @return array<string, string|array<int, string>>
     */
    protected function rules_article_pdf()
    {
        return [
            'nombre' => 'required|string|max:120',
            'titulo' => 'nullable|string|max:200',
            'mostrar_precio_anterior' => 'boolean',
            'texto_personalizado' => 'nullable|string',
            'motrar_fecha_impresion' => 'boolean',
        ];
    }

    /**
     * Lista las plantillas del usuario autenticado (owner).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = ArticlePdf::where('user_id', $this->userId())
            ->orderBy('nombre', 'ASC')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Persiste una nueva plantilla.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate($this->rules_article_pdf());

        $model = ArticlePdf::create([
            'nombre' => $request->nombre,
            'titulo' => $request->titulo,
            'mostrar_precio_anterior' => $request->boolean('mostrar_precio_anterior'),
            'texto_personalizado' => $request->texto_personalizado,
            'motrar_fecha_impresion' => $request->boolean('motrar_fecha_impresion'),
            'user_id' => $this->userId(),
        ]);

        return response()->json(['model' => $this->fullModel('ArticlePdf', $model->id)], 201);
    }

    /**
     * Muestra una plantilla por id.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        ArticlePdf::where('user_id', $this->userId())->where('id', $id)->firstOrFail();

        return response()->json(['model' => $this->fullModel('ArticlePdf', $id)], 200);
    }

    /**
     * Actualiza una plantilla existente.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $model = ArticlePdf::where('user_id', $this->userId())->where('id', $id)->firstOrFail();

        $request->validate($this->rules_article_pdf());

        $model->nombre = $request->nombre;
        $model->titulo = $request->titulo;
        $model->mostrar_precio_anterior = $request->boolean('mostrar_precio_anterior');
        $model->texto_personalizado = $request->texto_personalizado;
        $model->motrar_fecha_impresion = $request->boolean('motrar_fecha_impresion');
        $model->save();

        return response()->json(['model' => $this->fullModel('ArticlePdf', $model->id)], 200);
    }

    /**
     * Elimina una plantilla.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $model = ArticlePdf::where('user_id', $this->userId())->where('id', $id)->firstOrFail();
        $deleted_id = $model->id;
        $model->delete();
        $this->sendDeleteModelNotification('ArticlePdf', $deleted_id);

        return response(null, 200);
    }
}
