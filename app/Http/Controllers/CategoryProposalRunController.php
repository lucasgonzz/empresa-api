<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Las corridas de propuestas de categorías para Alertas → Catálogo → Categorías de la SPA (misión
 * categorizacion-tres-modelos, 5/10/2026). Contrato B del plan, §6: lectura (resumen del badge, la
 * corrida actual con sus tarjetas, marcar vista, ítems paginados de la revisión).
 *
 * Solo traduce a HTTP: la lógica vive en CategoryProposalLecturaHelper. Todo filtrado por el DUEÑO
 * (`$this->userId()`); un id de otro comercio responde 404 igual que uno inexistente.
 */
class CategoryProposalRunController extends Controller
{
    /**
     * GET category-proposal-runs/resumen — lo pide el login; alimenta el badge.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen(Request $request)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * GET category-proposal-runs/actual — la corrida vigente con tarjetas, árboles y conteos.
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
     * PUT category-proposal-runs/{id}/visto — el dueño abrió la solapa con la corrida lista.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function visto(Request $request, $id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * GET category-proposal-runs/{id}/items — los ítems de la revisión (solapa, página, búsqueda).
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function items(Request $request, $id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }
}
