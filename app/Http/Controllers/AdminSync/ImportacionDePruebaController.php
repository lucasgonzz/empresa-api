<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Jobs\AvanzarImportacionDePrueba;
use App\Models\AiAvisoDelAsistente;
use App\Models\ImportacionDePrueba;
use App\Models\Provider;
use App\Models\User;
use App\Services\Importacion\ImportacionDePruebaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportacionDePruebaController extends Controller
{
    public static function iniciar(Request $request, int $user_id): JsonResponse
    {
        self::comprobar_prueba($user_id);
        $datos = $request->validate([
            'excel_file' => 'required|file|max:10240',
            'support_ticket_id' => 'required|integer|min:1',
            'importacion_clave' => 'required|regex:/^[a-f0-9]{64}$/',
            'provider_id' => 'nullable|integer|min:1',
            'origen' => 'nullable|string|max:40',
        ]);

        $proveedor = $datos['provider_id'] ?? null;
        if ($proveedor !== null && !Provider::where('user_id', $user_id)->whereKey($proveedor)->exists()) {
            abort(422, 'El proveedor no pertenece al negocio.');
        }

        $run = DB::transaction(function () use ($request, $datos, $user_id, $proveedor) {
            // Serializa reenvíos simultáneos del mismo adjunto, antes de escribir otro archivo.
            User::whereKey($user_id)->lockForUpdate()->firstOrFail();
            $origen = [
                'user_id' => $user_id,
                'support_ticket_id' => $datos['support_ticket_id'],
                'clave' => $datos['importacion_clave'],
            ];
            $run = ImportacionDePrueba::where($origen)->first();
            if ($run !== null) {
                return $run;
            }

            $uuid = (string) Str::uuid();
            $archivo = $request->file('excel_file');
            $extension = strtolower($archivo->getClientOriginalExtension());
            $ruta = $archivo->storeAs('imported_files', 'prueba_' . $uuid . '.' . $extension);
            if (!$ruta) {
                abort(500, 'No pudimos guardar el archivo de la importación.');
            }

            return ImportacionDePrueba::create($origen + [
                'uuid' => $uuid,
                'archivo' => $ruta,
                'nombre' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                'origen' => $datos['origen'] ?? 'martin',
                'provider_id' => $proveedor,
                'estado' => 'analizar',
            ]);
        });

        if ($run->wasRecentlyCreated) {
            AvanzarImportacionDePrueba::dispatch((int) $run->id);
        }

        return response()->json([
            'analysis_uuid' => $run->uuid,
            'estado' => $run->estado,
            'automatizar_prueba' => true,
        ], 202);
    }

    public function pendientes(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'user_id' => 'required|integer|min:1',
            'support_ticket_id' => 'required|integer|min:1',
        ]);
        self::comprobar_prueba((int) $datos['user_id']);

        $del_ticket = ImportacionDePrueba::where('user_id', $datos['user_id'])
            ->where('support_ticket_id', $datos['support_ticket_id']);
        $corridas = (clone $del_ticket)->whereIn('estado', ImportacionDePrueba::ESPERANDO_RESPUESTA)->orderBy('id')->get();
        $en_proceso = $del_ticket->whereIn('estado', array_merge(ImportacionDePrueba::EN_CURSO, ImportacionDePrueba::ESPERANDO_RESPUESTA))->exists()
            || AiAvisoDelAsistente::where('owner_id', $datos['user_id'])
                ->where('support_ticket_id', $datos['support_ticket_id'])
                ->where('tipo', AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA)
                ->whereNull('avisado_por_whatsapp_at')->where('created_at', '>=', now()->subDay())->exists();

        return response()->json(['en_proceso' => $en_proceso, 'pendientes' => $corridas->map(function (ImportacionDePrueba $run) {
            $preguntas = $run->preguntas ?? [];
            $consultadas = array_slice($preguntas, 0, ImportacionDePruebaService::LIMITE_PREGUNTAS_POR_AVISO, true);
            $pendientes = array_diff_key($preguntas, $run->decisiones ?? []);
            foreach ($pendientes as $clave => &$pregunta) {
                $pregunta['ya_consultada'] = isset($consultadas[$clave]);
            }
            unset($pregunta);

            return [
                'analysis_uuid' => $run->uuid,
                'filename' => $run->nombre,
                'revision' => (int) $run->revision,
                'tipo' => $run->estado,
                'preguntas' => array_values($pendientes),
            ];
        })]);
    }

    public function resolver(Request $request, string $uuid): JsonResponse
    {
        $datos = $request->validate([
            'user_id' => 'required|integer|min:1',
            'support_ticket_id' => 'required|integer|min:1',
            'revision' => 'required|integer|min:1',
            'respuestas' => 'required|array|min:1',
            'respuestas.*' => 'required|string|max:120',
        ]);
        self::comprobar_prueba((int) $datos['user_id']);

        [$run, $continuar] = DB::transaction(function () use ($uuid, $datos) {
            $run = ImportacionDePrueba::where('uuid', $uuid)->where('user_id', $datos['user_id'])
                ->where('support_ticket_id', $datos['support_ticket_id'])->lockForUpdate()->firstOrFail();
            $guardadas = $run->decisiones ?? [];
            $revision_actual = (int) $run->revision === (int) $datos['revision'];
            if (!$revision_actual || !in_array($run->estado, ImportacionDePrueba::ESPERANDO_RESPUESTA, true)) {
                if ($revision_actual && array_diff_assoc($datos['respuestas'], $guardadas) === []) {
                    return [$run, false];
                }
                abort(409, 'La consulta cambió. Volvé a consultar las decisiones pendientes.');
            }

            $preguntas = $run->preguntas ?? [];
            foreach ($datos['respuestas'] as $clave => $respuesta) {
                if (!isset($preguntas[$clave])
                    || !in_array($respuesta, array_column($preguntas[$clave]['opciones'], 'valor'), true)) {
                    abort(422, 'La respuesta no coincide con las opciones de la consulta.');
                }
                if (isset($guardadas[$clave]) && $guardadas[$clave] !== $respuesta) {
                    abort(409, 'Esa respuesta ya se registró.');
                }
                $guardadas[$clave] = $respuesta;
            }

            $continuar = array_diff_key($preguntas, $guardadas) === [];
            $run->decisiones = $guardadas;
            if ($continuar) {
                $run->retirar_pregunta();
                $run->estado = 'preparar';
            }
            $run->save();

            return [$run, $continuar];
        });

        if ($continuar) {
            AvanzarImportacionDePrueba::dispatch((int) $run->id);
        }

        return response()->json(['estado' => $run->estado, 'analysis_uuid' => $run->uuid], $continuar ? 202 : 200);
    }

    private static function comprobar_prueba(int $user_id): void
    {
        $dueno = PruebaHelper::dueno();
        if ($dueno === null || (int) $dueno->id !== $user_id || !PruebaHelper::en_periodo_vigente($user_id)) {
            abort(409, 'La importación automática está disponible para el dueño de una prueba vigente.');
        }
    }
}
