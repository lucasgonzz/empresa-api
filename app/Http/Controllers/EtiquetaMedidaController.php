<?php

namespace App\Http\Controllers;

use App\Models\EtiquetaMedida;
use Illuminate\Http\Request;

/**
 * CRUD de medidas de etiquetas del usuario autenticado.
 */
class EtiquetaMedidaController extends Controller
{
    /**
     * Claves de propiedades válidas al generar PDF (referencia para futuras validaciones).
     *
     * @var string[]
     */
    public const PROPIEDADES_ETIQUETA_VALIDAS = [
        'nombre',
        'codigo_barras',
        'codigo_proveedor',
        'sku',
        'precio',
        'categoria',
        'marca',
        'fecha_actual',
        'nombre_negocio',
    ];

    /**
     * Lista medidas del usuario autenticado (owner).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = EtiquetaMedida::where('user_id', $this->userId())
            ->orderByDesc('es_predeterminada')
            ->orderBy('ancho')
            ->orderBy('alto')
            ->orderBy('nombre')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea una medida personalizada para el usuario autenticado.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'nullable|string|max:80',
            'ancho' => 'required|integer|min:10|max:200',
            'alto' => 'required|integer|min:10|max:200',
        ]);

        $nombre = $request->input('nombre');
        if (is_string($nombre)) {
            $nombre = trim($nombre);
        }
        if ($nombre === '') {
            $nombre = null;
        }

        $model = EtiquetaMedida::create([
            'user_id' => $this->userId(),
            'nombre' => $nombre,
            'ancho' => (int) $request->ancho,
            'alto' => (int) $request->alto,
            'es_predeterminada' => false,
        ]);

        return response()->json(['model' => $this->fullModel('EtiquetaMedida', $model->id)], 201);
    }

    /**
     * Elimina una medida propia que no sea predeterminada.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $model = EtiquetaMedida::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        if ($model->es_predeterminada) {
            return response()->json([
                'message' => 'No se puede eliminar una medida predeterminada.',
            ], 422);
        }

        $model->delete();

        return response()->json(['deleted' => true], 200);
    }
}
