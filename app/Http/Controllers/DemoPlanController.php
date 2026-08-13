<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use Illuminate\Http\Request;

/**
 * El plan de la demo, para el panel lateral del lead (mision 51).
 *
 * Devuelve lo que la mision 50 persistio en `demo_tracking_config`, con la URL de cada clip
 * ya resuelta contra `media_urls`. Las URLs viajan aparte del plan a proposito: el plan es
 * estructura y se congela del lado del admin, las URLs son contenido y se cargan despues.
 *
 * 🔴 Este codigo corre en las instancias de los ~40 clientes reales. Fuera de una sesion de
 * demo responde 204 sin cuerpo y sin tocar la base -- no 403, porque el mismo empresa-spa se
 * despliega en todas y un 403 les llenaria la consola de errores ajenos.
 */
class DemoPlanController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        /**
         * Guarda primera y sin base: el marcador lo pone DemoIngresoController al abrir la
         * sesion del lead, y es la misma senal que usan DemoSessionVigente y el emisor de
         * eventos. Sin el, no hay nada que devolver.
         */
        if (!$request->hasSession() || empty($request->session()->get('demo_ingreso_token_id'))) {
            return response(null, 204);
        }

        $config = DemoTrackingConfigHelper::actual();

        /**
         * Una demo de una instancia que nunca recibio plan (setup viejo, o admin sin
         * desplegar) es un caso previsto: el lead entra igual y el panel no se muestra.
         */
        if (is_null($config) || !is_array($config->plan)) {
            return response(null, 204);
        }

        return response()->json([
            'secciones' => self::secciones_con_urls($config->plan, $config->media_urls),
        ], 200);
    }

    /**
     * Arma las secciones del plan con la URL de cada clip resuelta.
     *
     * Un clip sin URL cargada **viaja igual**, con `url: null`. Filtrarlos dejaria el panel
     * vacio mientras los videos no esten grabados, que es hoy: solo esta subido `intro.mp4`.
     * El panel sabe que hacer con un clip sin video.
     *
     * @param array $plan Plan congelado tal como lo dejo el admin (mision 48).
     * @param array|null $media_urls Mapa slot_id => url.
     * @return array
     */
    private static function secciones_con_urls(array $plan, $media_urls)
    {
        $urls = is_array($media_urls) ? $media_urls : [];

        $secciones = isset($plan['secciones']) && is_array($plan['secciones']) ? $plan['secciones'] : [];

        $salida = [];

        foreach ($secciones as $seccion) {
            if (!is_array($seccion)) {
                continue;
            }

            $id = isset($seccion['id']) ? (string) $seccion['id'] : '';
            $clips = isset($seccion['clips']) && is_array($seccion['clips']) ? $seccion['clips'] : [];

            $clips_salida = [];

            foreach ($clips as $clip) {
                if (!is_array($clip) || !isset($clip['id'])) {
                    continue;
                }

                $clip_id = (string) $clip['id'];

                $clips_salida[] = [
                    'id' => $clip_id,
                    'titulo' => isset($clip['titulo']) ? $clip['titulo'] : '',
                    'tipo' => isset($clip['tipo']) ? $clip['tipo'] : 'nucleo',
                    'practica' => isset($clip['practica']) ? (bool) $clip['practica'] : false,
                    'url' => isset($urls[$clip_id]) && trim((string) $urls[$clip_id]) !== ''
                        ? (string) $urls[$clip_id]
                        : null,
                ];
            }

            $salida[] = [
                'id' => $id,
                /**
                 * Los ids del catalogo vienen con la forma "S1 - Listado". El titulo que se
                 * muestra es lo que va despues del guion; si no hay guion, el id entero.
                 */
                'titulo' => self::titulo_de_seccion($id),
                'clips' => $clips_salida,
            ];
        }

        return $salida;
    }

    /**
     * @param string $id
     * @return string
     */
    private static function titulo_de_seccion($id)
    {
        $partes = explode(' - ', $id, 2);

        return count($partes) === 2 ? trim($partes[1]) : trim($id);
    }
}
