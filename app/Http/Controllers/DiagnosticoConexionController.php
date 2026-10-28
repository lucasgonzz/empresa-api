<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Diagnóstico optativo: metadatos acotados, sin reporter de errores ni información comercial. */
class DiagnosticoConexionController extends Controller
{
    const FAMILIAS = 'user login logout sanctum csrf-cookie provider-order provider provider-order-status article client sale budget order afip-ticket current-acount payment-method table-column-preferences pdf-column-options column-position recursos-iniciales background-processes article-pdf configuration address download-resources estado';
    const SUFIJOS = 'config index show search all count total totals status pending health not-received days-to-advise last activity user csrf-cookie problemas-al-facturar';
    const RUTAS = 'root login demoIngreso informeCompartido passwordReset configuration abm employee alertas vender expense cheque reportes panel article deposito-para-checkear deposito-checkeadas por-entregar por-estado rutas sale VentasAll provider client budget produccion produccionV2 caja online whatsapp tienda_nube mercado_libre pending comprobantes consultora_de_precios papelera devoluciones ia ofertas';

    public function configuracion(Request $request)
    {
        return response()->json(['habilitado' => $this->habilitado($request), 'persona' => (int) $request->user()->id]);
    }

    private function habilitado(Request $request)
    {
        return $request->user() && in_array((string) $request->user()->id, config('diagnostico_conexion.usuarios', []), true);
    }

    /** Segunda barrera: no confiar en que el navegador ya quitó datos del path. */
    public static function endpoint_seguro($valor)
    {
        $valor = preg_replace('/[?#].*$/s', '', (string) $valor);
        $valor = preg_replace('#^[a-z]+://[^/]*#i', '', $valor);
        $partes = array_values(array_filter(explode('/', trim($valor, '/')), 'strlen'));
        if (isset($partes[0]) && $partes[0] === 'api') {
            array_shift($partes);
        }
        $seguras = [];
        foreach (array_slice($partes, 0, 6) as $i => $parte) {
            $permitidos = explode(' ', $i === 0 ? self::FAMILIAS : self::SUFIJOS);
            $seguras[] = in_array($parte, $permitidos, true) ? $parte : ':valor';
        }
        return '/' . implode('/', $seguras);
    }

    public function eventos(Request $request)
    {
        if (!$this->habilitado($request)) {
            return response()->json(['ok' => false], 403);
        }
        // Las cookies se comparten entre pestañas. La cola de A no puede atribuirse a B
        // aunque ambos estén habilitados; el header sólo compara, nunca acredita identidad.
        if ((string) $request->header('X-Diagnostico-Persona') !== (string) $request->user()->id) {
            return response()->json(['ok' => false], 409);
        }
        if (strlen($request->getContent()) > 24000) {
            return response()->json(['ok' => false], 413);
        }
        if (array_diff(array_keys($request->all()), ['eventos'])) {
            return response()->json(['ok' => false], 422);
        }
        $minimo = (int) floor(microtime(true) * 1000) - 86400000;
        $maximo = (int) floor(microtime(true) * 1000) + 300000;
        $datos = $request->validate([
            'eventos' => 'required|array|min:1|max:10',
            'eventos.*' => 'required|array:at_ms,duracion_ms,tipo,metodo,endpoint,ruta,status,origen,nav_online,auth_online,sesion_offline,pendientes,exitos_cercanos,skip_global,skip_navegacion,cancelable,request_id',
            'eventos.*.request_id' => ['present', 'nullable', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'eventos.*.at_ms' => 'required|integer|min:' . $minimo . '|max:' . $maximo,
            'eventos.*.duracion_ms' => 'required|integer|min:0|max:86400000',
            'eventos.*.tipo' => 'required|in:http,sin_respuesta,timeout,cancelado,pendiente,recuperacion,aviso',
            'eventos.*.metodo' => 'required|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS,ESTADO',
            'eventos.*.endpoint' => 'required|string|max:160',
            'eventos.*.ruta' => 'required|string|max:60',
            'eventos.*.status' => 'required|integer|min:0|max:599',
            'eventos.*.origen' => 'required|in:ninguno,toast_conexion,transicion_offline,aviso_sesion_offline',
            'eventos.*.nav_online' => 'required|boolean',
            'eventos.*.auth_online' => 'required|boolean',
            'eventos.*.sesion_offline' => 'required|boolean',
            'eventos.*.pendientes' => 'required|integer|min:0|max:200',
            'eventos.*.exitos_cercanos' => 'required|integer|min:0|max:20',
            'eventos.*.skip_global' => 'required|boolean',
            'eventos.*.skip_navegacion' => 'required|boolean',
            'eventos.*.cancelable' => 'required|boolean',
        ]);
        try {
            foreach ($datos['eventos'] as $evento) {
                $evento['endpoint'] = self::endpoint_seguro($evento['endpoint']);
                $evento['ruta'] = in_array($evento['ruta'], explode(' ', self::RUTAS), true) ? $evento['ruta'] : 'otra';
                // La identidad sale exclusivamente de Sanctum. No existe user_id en el contrato.
                Log::channel('diagnostico_conexion')->info('evento_conexion', [
                    'auth_user_id' => (int) $request->user()->id,
                    'owner_id' => (int) ($request->user()->owner_id ?: $request->user()->id),
                    'evento' => $evento,
                ]);
            }
        } catch (\Throwable $error) {
            // Nunca reportar el cuerpo/error en Laravel/GitHub: este canal es independiente.
            return response()->json(['ok' => false], 503);
        }
        return response()->json(['ok' => true], 202);
    }
}
