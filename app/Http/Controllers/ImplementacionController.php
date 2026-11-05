<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\ImportacionDePrueba;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImplementacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $disponible = empty($user->owner_id) && PruebaHelper::en_periodo_vigente((int) $user->id);
        if (!$disponible) {
            return response()->json(['disponible' => false, 'hitos' => []]);
        }

        $corridas = ImportacionDePrueba::where('user_id', $user->id)->orderBy('id')->get();
        $hitos = [
            $this->etapa('instalacion', 2, 'pc-display', 'Instalación del sistema', 'completado', 'Tu sistema ya está instalado.'),
            $this->etapa('recoleccion', 3, 'file-earmark-arrow-up', 'Recolección de archivos',
                $corridas->isEmpty() ? 'en_curso' : 'completado',
                $corridas->isEmpty() ? 'Mandanos tu catálogo por WhatsApp y empezamos la carga.' : 'Recibimos tu archivo por WhatsApp.'),
        ];

        if ($corridas->isEmpty()) {
            $hitos[] = $this->etapa('migracion', 4, 'file-earmark-arrow-up', 'Migración de datos', 'pendiente', 'Vamos a cargar tu catálogo cuando recibamos el archivo.');
            $hitos[] = $this->etapa('imagenes', 5, 'images', 'Imágenes del catálogo', 'pendiente', 'Buscaremos imágenes de tus artículos más nuevos.');
            $hitos[] = $this->etapa('agente', 6, 'robot', 'Agente de WhatsApp', 'pendiente', 'El agente va a analizar tus artículos para responder sobre tu catálogo.');
        }

        foreach ($corridas as $run) {
            $registrados = $run->hitos ?? [];
            if (isset($registrados['imagenes'])) {
                $registrados['imagenes']['etapa'] = 5;
            }
            $hitos[] = $registrados['migracion'] ?? $this->etapa($run->uuid . '-migracion', 4, 'file-earmark-arrow-up', 'Migración de datos', 'en_curso',
                in_array($run->estado, ['columnas', 'repetidos'], true)
                    ? 'Estamos esperando tu respuesta por WhatsApp para seguir.'
                    : 'Estamos preparando la carga de tus artículos.');
            $hitos[] = $registrados['imagenes'] ?? $this->etapa($run->uuid . '-imagenes', 5, 'images', 'Imágenes del catálogo',
                $run->estado === 'imagenes' ? 'en_curso' : 'pendiente',
                $run->estado === 'imagenes' ? 'Estamos buscando imágenes.' : 'La búsqueda empieza después de la carga.');
            $hitos[] = $registrados['agente'] ?? $this->etapa($run->uuid . '-agente', 6, 'robot', 'Agente de WhatsApp',
                in_array($run->estado, ['indexar', 'indexando'], true) ? 'en_curso' : 'pendiente',
                'El agente va a analizar tus artículos para responder sobre tu catálogo.');
        }

        return response()->json(['disponible' => true, 'hitos' => $hitos]);
    }

    public function visto(Request $request, string $id): JsonResponse
    {
        return $this->marcar($request, $id, 'visto');
    }

    public function aprobar(Request $request, string $id): JsonResponse
    {
        return $this->marcar($request, $id, 'aprobado');
    }

    private function marcar(Request $request, string $id, string $marca): JsonResponse
    {
        $user = $request->user();
        if (!empty($user->owner_id) || !PruebaHelper::en_periodo_vigente((int) $user->id)) {
            abort(404);
        }
        if (!preg_match('/^([a-f0-9-]{36})-(migracion|imagenes|agente)$/', $id, $partes)) {
            abort(404);
        }

        DB::transaction(function () use ($user, $partes, $id, $marca) {
            $run = ImportacionDePrueba::where('user_id', $user->id)->where('uuid', $partes[1])->lockForUpdate()->firstOrFail();
            $hitos = $run->hitos ?? [];
            if (!isset($hitos[$partes[2]]) || $hitos[$partes[2]]['id'] !== $id) {
                abort(404);
            }
            if ($marca === 'aprobado' && ($partes[2] !== 'agente' || ($hitos['agente']['accion']['tipo'] ?? null) !== 'agente')) {
                abort(409, 'Este resultado todavía no está listo para aprobar.');
            }
            $hitos[$partes[2]][$marca] = true;
            $run->update(['hitos' => $hitos]);
        });

        return response()->json([$marca => true]);
    }

    private function etapa(string $id, int $etapa, string $icono, string $titulo, string $estado, string $detalle): array
    {
        return compact('id', 'etapa', 'icono', 'titulo', 'estado', 'detalle') + [
            'implementacion' => $icono === 'robot' ? 'agente' : 'sistema',
            'siguientes' => [], 'accion' => null, 'avisar' => false, 'visto' => true,
        ];
    }
}
