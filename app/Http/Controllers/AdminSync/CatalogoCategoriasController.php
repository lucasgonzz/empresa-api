<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Lo que la skill /categorizar escribe: la corrida, las propuestas y las asignaciones (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato A del plan, §5.3 a §5.8. Dueño por
 * `AsistenteCanalHelper::dueno()`; la clave `X-Admin-Api-Key` se exige SIEMPRE, adentro del
 * controlador (trait ClaveEstrictaDeAdmin). Nada de esto toca `categories` ni `articles`.
 */
class CatalogoCategoriasController extends Controller
{
    /**
     * POST admin-sync/catalogo/categorias/propuestas — crea la corrida con sus propuestas y árboles.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function crear(Request $request)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * GET admin-sync/catalogo/categorias/propuestas/actual — la última corrida no descartada.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function actual(Request $request)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * GET admin-sync/catalogo/categorias/propuestas/{run_id} — estado y progreso.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function mostrar(Request $request, $run_id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * POST admin-sync/catalogo/categorias/propuestas/{run_id}/asignaciones — lote de hasta 500.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function asignaciones(Request $request, $run_id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * GET admin-sync/catalogo/categorias/propuestas/{run_id}/pendientes — artículos sin ítem.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendientes(Request $request, $run_id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * POST admin-sync/catalogo/categorias/propuestas/{run_id}/listo — publica la corrida al dueño.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function listo(Request $request, $run_id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * POST admin-sync/catalogo/categorias/propuestas/{run_id}/descartar — la da de baja.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $run_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function descartar(Request $request, $run_id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }
}
