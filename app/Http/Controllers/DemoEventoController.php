<?php

namespace App\Http\Controllers;

use App\Services\DemoEventoEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Eventos de UX que reporta el SPA mientras el lead recorre la demo (mision 50, pieza 4).
 *
 * Los eventos de NEGOCIO no entran por aca: los emite el servidor en el punto donde la
 * escritura ya esta confirmada. Si los emitiera el front serian falsificables y, peor, se
 * perderian cuando el lead haga la accion por un camino distinto al guiado — que es justo
 * lo que la demo quiere permitir (decision T4 de demo_experiencia.md §9).
 */
class DemoEventoController extends Controller
{
    /**
     * Registra un evento de UX de la demo.
     *
     * Responde 204 ante cualquier motivo de rechazo, nunca 403: el mismo empresa-spa se
     * despliega en las instancias de los ~40 clientes reales, y un 403 llenaria de errores
     * la consola de gente que no tiene nada que ver con esto.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        /**
         * Guarda obligatoria y primera: sin el marcador que pone DemoIngresoController,
         * esta sesion no es de demo. Se corta antes de mirar siquiera el cuerpo del
         * request, y sin tocar la base.
         */
        if (!$request->hasSession() || empty($request->session()->get('demo_ingreso_token_id'))) {
            return response(null, 204);
        }

        /**
         * `is_scalar` y no un cast directo: el cuerpo lo arma el navegador, y un
         * `{"nombre": ["clip.terminado"]}` convertiría el 204 mudo de este endpoint en un
         * 500 con traza. La lista blanca de abajo ya rechaza la cadena vacía.
         */
        $nombre_crudo = $request->input('nombre', '');
        $nombre = is_scalar($nombre_crudo) ? trim((string) $nombre_crudo) : '';

        if (!DemoEventoEmitter::nombre_ux_aceptado($nombre)) {
            Log::info('Demo evento: nombre fuera de la lista blanca, se descarta: "' . $nombre . '".');

            return response(null, 204);
        }

        $datos = $request->input('datos', []);

        $evento = DemoEventoEmitter::emitir(
            $nombre,
            $request->input('clip_id'),
            is_array($datos) ? $datos : []
        );

        /**
         * emitir() devuelve null cuando la instancia no tiene canal configurado. Es un
         * caso previsto (una demo vieja, o un admin todavia sin desplegar): el SPA no
         * tiene que enterarse ni mostrar nada.
         */
        if (is_null($evento)) {
            return response(null, 204);
        }

        return response()->json(['ok' => true], 200);
    }
}
