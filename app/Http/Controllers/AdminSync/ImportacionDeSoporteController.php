<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Jobs\AvanzarImportacionDeSoporte;
use App\Models\ImportacionDeSoporte;
use App\Models\User;
use App\Services\Importacion\ImportacionDePruebaService;
use App\Services\Importacion\ImportacionDeSoporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importación de clientes y proveedores desde el ticket de soporte, con código y deshacer (misión
 * importaciones-de-martin, 11/11/2026). Contrato con el admin:
 *
 *   POST admin-sync/importacion-soporte/{modelo}/iniciar   modelo client|provider, multipart:
 *        excel_file, user_id, support_ticket_id, importacion_clave? (sha256), avisar_por_whatsapp? (default 1)
 *        → 202 {corrida, corrida_existente}
 *   GET  admin-sync/importacion-soporte/{corrida}?user_id=&support_ticket_id=        → 200 {corrida}
 *   POST admin-sync/importacion-soporte/{corrida}/confirmar  {user_id, support_ticket_id, codigo, decision?}
 *        → 202 (confirmada, se importa en la cola) · 200 (cancelada, o el mismo pedido repetido) · 409 codigo_*
 *   POST admin-sync/importacion-soporte/{corrida}/revertir   {user_id, support_ticket_id}
 *        → 202 (queda `revirtiendo`; el job deshace tanda por tanda) · 200 (ya revertida) · 409 no_revertible
 *
 * `{corrida}` es el `corrida_id` (uuid). Errores: `{message, motivo}`. 409 `dueno_no_activo` si el negocio no
 * es un dueño activo (en prueba o vencido no entra); 404 CON `motivo: corrida_no_encontrada` si la corrida no
 * es de ese dueño y ticket. 🔴 Un 404 SIN `motivo` es un empresa viejo sin estas rutas: `version_vieja`.
 *
 * Prueba gratuita (ronda 8, D1): `iniciar` con `modo_prueba=1` corre el mismo motor para el dueño de una prueba
 * vigente, sin código (analizar → importar → terminada, o requiere_correccion), y si el dueño no está en una prueba
 * vigente contesta 409 `dueno_no_en_prueba`. Sin `modo_prueba`, el dueño en prueba sigue recibiendo `dueno_no_activo`.
 * `mostrar`/`revertir` de una corrida con `modo_prueba` pasan con la prueba vigente o con el dueño ya activo.
 *
 * La clave X-Admin-Api-Key se exige siempre (middleware `admin.api.key:siempre` en la ruta).
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class ImportacionDeSoporteController extends Controller
{
    public function iniciar(Request $request, string $modelo): JsonResponse
    {
        if (!in_array($modelo, ImportacionDeSoporte::MODELOS, true)) {
            return $this->error(422, 'modelo_invalido', 'El modelo tiene que ser client o provider.');
        }
        $datos = $request->validate([
            'excel_file' => 'required|file|max:10240',
            'user_id' => 'required|integer|min:1',
            'support_ticket_id' => 'required|integer|min:1',
            'importacion_clave' => 'nullable|regex:/^[a-f0-9]{64}$/',
            'avisar_por_whatsapp' => 'nullable|in:0,1,true,false',
            'modo_prueba' => 'nullable|in:0,1,true,false',
        ]);
        $archivo = $request->file('excel_file');
        $extension = strtolower((string) $archivo->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->error(422, 'archivo_invalido', 'El archivo tiene que ser un Excel (.xlsx o .xls).');
        }
        $user_id = (int) $datos['user_id'];
        // La prueba gratuita (ronda 8, D1): mismo motor, sin código. Sin `modo_prueba`, todo como hasta hoy.
        $modo_prueba = isset($datos['modo_prueba']) && in_array((string) $datos['modo_prueba'], ['1', 'true'], true);
        if ($modo_prueba) {
            if (!ImportacionDeSoporteService::habilitado($user_id, true)) {
                return $this->error(409, 'dueno_no_en_prueba', 'La carga automática de la prueba está disponible para el dueño de una prueba vigente.');
            }
        } else {
            $rechazo = $this->comprobar_dueno($user_id);
            if (!is_null($rechazo)) {
                return $rechazo;
            }
        }

        $clave = hash('sha256', 'soporte-contactos:' . $modelo . ':'
            . (isset($datos['importacion_clave']) ? $datos['importacion_clave'] : hash_file('sha256', $archivo->getRealPath())));
        $avisar = !isset($datos['avisar_por_whatsapp']) || in_array((string) $datos['avisar_por_whatsapp'], ['1', 'true'], true);

        $corrida = DB::transaction(function () use ($archivo, $extension, $datos, $user_id, $modelo, $clave, $avisar, $modo_prueba) {
            // Serializa reenvíos simultáneos del mismo adjunto.
            User::whereKey($user_id)->lockForUpdate()->firstOrFail();
            $origen = ['user_id' => $user_id, 'support_ticket_id' => (int) $datos['support_ticket_id'], 'clave' => $clave];
            // Idempotencia entre corridas vivas: una con error, cancelada, revertida o que pide corregir el Excel no
            // frena el reintento con el mismo archivo.
            $existente = ImportacionDeSoporte::where($origen)->whereIn('estado', ImportacionDeSoporte::VIVAS)->latest('id')->first();
            if (!is_null($existente)) {
                return $existente;
            }
            $uuid = (string) Str::uuid();
            $ruta = $archivo->storeAs('imported_files', 'soporte_' . $uuid . '.' . $extension);
            if (!$ruta) {
                abort(500, 'No pudimos guardar el archivo de la importación.');
            }

            return ImportacionDeSoporte::create($origen + [
                'uuid' => $uuid,
                'modelo' => $modelo,
                'archivo' => $ruta,
                'nombre' => mb_substr((string) $archivo->getClientOriginalName(), 0, 255),
                'estado' => 'analizar',
                'avisar_por_whatsapp' => $avisar,
            ] + ($modo_prueba ? ['modo_prueba' => true] : []));
        });

        if ($corrida->wasRecentlyCreated) {
            AvanzarImportacionDeSoporte::dispatch((int) $corrida->id);
        }

        return response()->json([
            'corrida' => ImportacionDeSoporteService::payload($corrida),
            'corrida_existente' => !$corrida->wasRecentlyCreated,
        ], 202);
    }

    public function mostrar(Request $request, string $corrida): JsonResponse
    {
        $encontrada = $this->corrida($request, $corrida);
        if ($encontrada instanceof JsonResponse) {
            return $encontrada;
        }
        app(ImportacionDeSoporteService::class)->reanudar_si_hace_falta($encontrada);
        $encontrada->refresh();

        return response()->json(['corrida' => ImportacionDeSoporteService::payload($encontrada)], 200);
    }

    public function confirmar(Request $request, string $corrida, ImportacionDeSoporteService $servicio): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => 'required|string|max:16',
            'decision' => 'nullable|in:confirmar,cancelar',
        ]);
        $encontrada = $this->corrida($request, $corrida);
        if ($encontrada instanceof JsonResponse) {
            return $encontrada;
        }
        $decision = isset($datos['decision']) ? $datos['decision'] : 'confirmar';
        list($status, $motivo, $actualizada) = $servicio->confirmar($encontrada, (string) $datos['codigo'], $decision);
        if (!is_null($motivo)) {
            return $this->error($status, $motivo, $motivo === 'codigo_incorrecto'
                ? 'El código no coincide con el de la última consulta al dueño.'
                : 'La importación no está esperando una confirmación.', ['corrida' => ImportacionDeSoporteService::payload($actualizada)]);
        }
        if ($status === 202) {
            AvanzarImportacionDeSoporte::dispatch((int) $actualizada->id);
        }

        return response()->json(['corrida' => ImportacionDeSoporteService::payload($actualizada)], $status);
    }

    public function revertir(Request $request, string $corrida, ImportacionDeSoporteService $servicio): JsonResponse
    {
        $encontrada = $this->corrida($request, $corrida);
        if ($encontrada instanceof JsonResponse) {
            return $encontrada;
        }
        list($status, $motivo, $actualizada) = $servicio->revertir($encontrada);
        if (!is_null($motivo)) {
            return $this->error($status, $motivo, 'Solo se puede deshacer una importación terminada (o cortada con algo cargado).',
                ['corrida' => ImportacionDeSoporteService::payload($actualizada)]);
        }
        if ($status === 202) {
            AvanzarImportacionDeSoporte::dispatch((int) $actualizada->id);
        }

        return response()->json(['corrida' => ImportacionDeSoporteService::payload($actualizada)], $status);
    }

    /**
     * La corrida del dueño y del ticket del pedido, o la respuesta de rechazo.
     *
     * @return \App\Models\ImportacionDeSoporte|\Illuminate\Http\JsonResponse
     */
    private function corrida(Request $request, string $uuid)
    {
        $datos = $request->validate([
            'user_id' => 'required|integer|min:1',
            'support_ticket_id' => 'required|integer|min:1',
        ]);
        // Primero la corrida y después la guarda, que depende de ella (ronda 8, D1): la de la prueba se puede consultar
        // y deshacer con la prueba vigente o, ya contratado, como dueño activo; la de un activo, como siempre.
        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->where('user_id', (int) $datos['user_id'])
            ->where('support_ticket_id', (int) $datos['support_ticket_id'])->first();
        if (is_null($corrida)) {
            return $this->error(404, 'corrida_no_encontrada', 'No se encontró esa importación para este negocio y ticket.');
        }
        if (!ImportacionDeSoporteService::habilitado((int) $datos['user_id'], (bool) $corrida->modo_prueba, true)) {
            return $corrida->modo_prueba
                ? $this->error(409, 'dueno_no_en_prueba', 'La importación de la prueba está disponible para el dueño de una prueba vigente o de un negocio activo.')
                : $this->comprobar_dueno((int) $datos['user_id']);
        }

        return $corrida;
    }

    private function comprobar_dueno(int $user_id)
    {
        return ImportacionDePruebaService::dueno_activo($user_id) ? null
            : $this->error(409, 'dueno_no_activo', 'La importación de soporte está disponible para el dueño de un negocio activo.');
    }

    private function error(int $status, string $motivo, string $mensaje, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'motivo' => $motivo] + $extra, $status);
    }
}
