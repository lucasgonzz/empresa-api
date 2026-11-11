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
use App\Services\Importacion\PlanillaDePrueba;
use App\Services\Importacion\PlanillaStockExistente;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportacionDePruebaController extends Controller
{
    public static function soporta_stock_existente(): bool
    {
        return Schema::hasColumn('importaciones_de_prueba', 'alcance');
    }

    public static function iniciar(Request $request, int $user_id, bool $modo_soporte = false): JsonResponse
    {
        self::comprobar_dueno($user_id, $modo_soporte);
        $datos = $request->validate([
            'excel_file' => 'required|file|max:10240',
            'support_ticket_id' => 'required|integer|min:1',
            'importacion_clave' => 'required|regex:/^[a-f0-9]{64}$/',
            'provider_id' => 'nullable|integer|min:1',
            'origen' => 'nullable|string|max:40',
            'alcance' => 'sometimes|required|in:stock_existente',
            // Mapeo de columnas que soporte ya confirmó con el cliente: string JSON (viaja en multipart).
            'columnas_previas' => 'sometimes|nullable|string|max:8000',
        ]);

        $alcance = $datos['alcance'] ?? null;
        abort_if($alcance !== null && !$modo_soporte, 422, 'El alcance de stock es exclusivo de soporte.');
        $proveedor = $datos['provider_id'] ?? null;
        if ($proveedor !== null && !Provider::where('user_id', $user_id)->whereKey($proveedor)->exists()) {
            abort(422, 'El proveedor no pertenece al negocio.');
        }

        // Las claves nuevas de la respuesta solo salen si el pedido trajo el campo: sin él, todo igual que antes.
        $trajo_previas = array_key_exists('columnas_previas', $datos);
        $previas = null;
        if (($datos['columnas_previas'] ?? null) !== null) {
            if ($alcance !== null) {
                self::rechazar_previas('El mapeo previo es para la importación general de artículos.');
            }
            $previas = self::columnas_previas((string) $datos['columnas_previas'], $user_id);
        }
        // Una base sin la migración sigue como hoy, sin mapeo previo, en lugar de caerse al guardar.
        $guarda_previas = $trajo_previas && self::soporta_columnas_previas();

        $run = DB::transaction(function () use ($request, $datos, $user_id, $proveedor, $modo_soporte, $alcance, $previas, $guarda_previas) {
            // Serializa reenvíos simultáneos del mismo adjunto, antes de escribir otro archivo.
            User::whereKey($user_id)->lockForUpdate()->firstOrFail();
            $origen = [
                'user_id' => $user_id,
                'support_ticket_id' => $datos['support_ticket_id'],
                'clave' => $modo_soporte
                    ? hash('sha256', 'soporte:' . ($alcance === null ? '' : $alcance . ':') . $datos['importacion_clave'])
                    : $datos['importacion_clave'],
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
                'estado' => $alcance === null ? 'analizar' : 'stock_analizar',
                'modo_soporte' => $modo_soporte,
            ] + ($alcance === null ? [] : ['alcance' => $alcance])
              + ($previas !== null && $guarda_previas ? ['columnas_previas' => $previas] : []));
        });

        if ($run->wasRecentlyCreated) {
            AvanzarImportacionDePrueba::dispatch((int) $run->id);
        }

        // Una corrida que ya existía por la clave idempotente conserva sus previas: no se pisan.
        $extra_previas = $trajo_previas ? [
            'columnas_previas' => $guarda_previas ? count($run->columnas_previas ?? []) : 0,
            'corrida_existente' => !$run->wasRecentlyCreated,
        ] : [];

        return response()->json([
            'analysis_uuid' => $run->uuid,
            'estado' => $run->estado_publico(),
            ($modo_soporte ? 'automatizar_soporte' : 'automatizar_prueba') => true,
        ] + ($alcance === null ? [] : ['alcance' => $run->alcance]) + $extra_previas, 202);
    }

    /**
     * Indica si la base ya tiene la columna del mapeo previo de columnas.
     *
     * @return bool
     */
    public static function soporta_columnas_previas(): bool
    {
        return Schema::hasColumn('importaciones_de_prueba', 'columnas_previas');
    }

    /**
     * Valida y normaliza el mapeo previo de columnas que manda soporte.
     *
     * Cada ítem trae `columna` (letra de Excel), `campo` (clave, etiqueta o `ignorar`) y `hoja`
     * opcional. El campo se resuelve contra los campos del negocio con la misma lógica de
     * `PlanillaDePrueba`, así lo guardado es exactamente lo que el análisis va a aplicar.
     *
     * Formato guardado: lista de ['hoja' => ?string, 'columna' => 'A'..'ZZZ', 'campo' => ?string],
     * con `campo` ya resuelto a la clave del importador y **null para ignorar** (nunca la cadena
     * `ignorar`, que no es un campo del importador).
     *
     * @param  string $json     Lista JSON recibida en el pedido
     * @param  int    $user_id  Dueño del negocio, para leer sus depósitos y listas de precio
     * @return array            Lista normalizada
     *
     * @throws HttpResponseException  422 si el JSON, una columna o un campo no son válidos
     */
    private static function columnas_previas(string $json, int $user_id): array
    {
        $lista = json_decode($json, true);
        if (!is_array($lista) || $lista === [] || array_keys($lista) !== range(0, count($lista) - 1)) {
            self::rechazar_previas('El mapeo previo de columnas tiene que ser una lista JSON con al menos una columna.');
        }
        if (count($lista) > 80) {
            self::rechazar_previas('El mapeo previo de columnas admite hasta 80 columnas.');
        }

        // La planilla resuelve los campos con los depósitos y listas del negocio; la corrida todavía no existe.
        $planilla = new PlanillaDePrueba(new ImportacionDePrueba(['user_id' => $user_id]));
        $normalizadas = [];
        // Por hoja comparable: columnas y campos ya usados, para rechazar duplicados.
        $columnas_vistas = [];
        $campos_vistos = [];
        foreach ($lista as $numero => $item) {
            $orden = $numero + 1;
            if (!is_array($item)) {
                self::rechazar_previas('El ítem ' . $orden . ' del mapeo previo no es un objeto con columna y campo.');
            }
            $hoja = $item['hoja'] ?? null;
            if ($hoja !== null && (!is_string($hoja) || mb_strlen($hoja) > 120)) {
                self::rechazar_previas('La hoja del ítem ' . $orden . ' del mapeo previo no es válida.');
            }
            $hoja = $hoja === null || trim($hoja) === '' ? null : trim($hoja);
            $columna = $item['columna'] ?? null;
            if (!is_string($columna) || !preg_match('/^[A-Za-z]{1,3}$/', $columna)) {
                self::rechazar_previas('La columna del ítem ' . $orden . ' del mapeo previo tiene que ser una letra de Excel (A, B, …, AA).');
            }
            $columna = strtoupper($columna);
            $referencia = 'columna ' . $columna . ($hoja === null ? '' : ' de «' . $hoja . '»');
            $campo = $item['campo'] ?? null;
            if (!is_string($campo) || trim($campo) === '' || mb_strlen($campo) > 120) {
                self::rechazar_previas('Falta el campo de la ' . $referencia . ' en el mapeo previo.');
            }

            $resuelto = $planilla->resolver_campo_previo($campo);
            if ($resuelto === null) {
                self::rechazar_previas('El campo «' . trim($campo) . '» de la ' . $referencia
                    . ' no existe en este negocio. Campos posibles: ' . implode(', ', $planilla->campos_posibles()) . '.');
            }

            $clave_hoja = $hoja === null ? '' : PlanillaDePrueba::titulo_comparable($hoja);
            if (isset($columnas_vistas[$clave_hoja][$columna])) {
                self::rechazar_previas('La ' . $referencia . ' aparece dos veces en el mapeo previo.');
            }
            $columnas_vistas[$clave_hoja][$columna] = true;
            if ($resuelto !== PlanillaDePrueba::CAMPO_IGNORAR) {
                if (isset($campos_vistos[$clave_hoja][$resuelto])) {
                    self::rechazar_previas('Las columnas ' . $campos_vistos[$clave_hoja][$resuelto] . ' y ' . $columna
                        . ($hoja === null ? '' : ' de «' . $hoja . '»') . ' quedaron asignadas al mismo campo «' . $resuelto . '».');
                }
                $campos_vistos[$clave_hoja][$resuelto] = $columna;
            }

            $normalizadas[] = [
                'hoja' => $hoja,
                'columna' => $columna,
                'campo' => $resuelto === PlanillaDePrueba::CAMPO_IGNORAR ? null : $resuelto,
            ];
        }

        return $normalizadas;
    }

    /**
     * Corta el pedido con un 422 sobre el mapeo previo, con el mismo formato que una validación.
     *
     * @param  string $mensaje  Motivo, que soporte le muestra a quien inició la importación
     * @return void
     *
     * @throws HttpResponseException
     */
    private static function rechazar_previas(string $mensaje): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $mensaje,
            'errors' => ['columnas_previas' => [$mensaje]],
        ], 422));
    }

    public function pendientes(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'user_id' => 'required|integer|min:1',
            'support_ticket_id' => 'required|integer|min:1',
        ]);
        $modo_soporte = $request->boolean('automatizar_soporte');
        self::comprobar_dueno((int) $datos['user_id'], $modo_soporte);

        $del_ticket = ImportacionDePrueba::where('user_id', $datos['user_id'])
            ->where('support_ticket_id', $datos['support_ticket_id'])->where('modo_soporte', $modo_soporte);
        $corridas = (clone $del_ticket)->whereIn('estado', ImportacionDePrueba::ESPERANDO_RESPUESTA)->orderBy('id')->get();
        $en_proceso = $del_ticket->whereIn('estado', array_merge(ImportacionDePrueba::EN_CURSO, ImportacionDePrueba::ESPERANDO_RESPUESTA))->exists()
            || AiAvisoDelAsistente::where('owner_id', $datos['user_id'])
                ->where('support_ticket_id', $datos['support_ticket_id'])
                ->where('tipo', AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA)
                ->where('modo_soporte', $modo_soporte)->whereNull('avisado_por_whatsapp_at')
                ->when(!$modo_soporte, function ($consulta) {
                    $consulta->where('created_at', '>=', now()->subDay());
                })->exists();

        return response()->json(['en_proceso' => $en_proceso, 'pendientes' => $corridas->map(function (ImportacionDePrueba $run) {
            $preguntas = $run->preguntas ?? [];
            $consultadas = array_slice($preguntas, 0, ImportacionDePruebaService::LIMITE_PREGUNTAS_POR_AVISO, true);
            $pendientes = array_diff_key($preguntas, $run->decisiones ?? []);
            foreach ($pendientes as $clave => &$pregunta) {
                $pregunta['ya_consultada'] = isset($consultadas[$clave]);
                unset($pregunta['huella_plan']);
            }
            unset($pregunta);

            return [
                'analysis_uuid' => $run->uuid,
                'filename' => $run->nombre,
                'revision' => (int) $run->revision,
                'tipo' => $run->estado_publico(),
                'preguntas' => array_values($pendientes),
            ] + ($run->alcance === null ? [] : ['alcance' => $run->alcance]);
        })] + ($modo_soporte ? [
            'automatizar_soporte' => true,
            'alcances_soporte' => self::soporta_stock_existente() ? ['stock_existente'] : [],
        ] : []));
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
        $modo_soporte = $request->boolean('automatizar_soporte');
        self::comprobar_dueno((int) $datos['user_id'], $modo_soporte);

        [$run, $continuar] = DB::transaction(function () use ($uuid, $datos, $modo_soporte) {
            $run = ImportacionDePrueba::where('uuid', $uuid)->where('user_id', $datos['user_id'])
                ->where('support_ticket_id', $datos['support_ticket_id'])->where('modo_soporte', $modo_soporte)
                ->lockForUpdate()->firstOrFail();
            abort_if(in_array($run->estado, ImportacionDePrueba::ESPERANDO_RESPUESTA, true)
                && !$run->alcance_valido_para_avanzar(), 409, 'No se pudo verificar el alcance de la importación.');
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
                $pregunta = $preguntas[$clave] ?? null;
                if ($pregunta === null || (!in_array($respuesta, array_column($pregunta['opciones'], 'valor'), true)
                    && !self::codigo_de_stock_valido($run, $pregunta, $respuesta))) {
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
                $cancelar = $run->estado_publico() === 'confirmacion' && $guardadas['confirmacion'] === 'cancelar';
                $run->estado = $cancelar ? 'cancelado' : $run->estado_para('preparar');
                $continuar = !$cancelar;
            }
            $run->save();

            return [$run, $continuar];
        });

        if ($continuar) {
            AvanzarImportacionDePrueba::dispatch((int) $run->id);
        }

        return response()->json(['estado' => $run->estado_publico(), 'analysis_uuid' => $run->uuid], $continuar ? 202 : 200);
    }

    private static function codigo_de_stock_valido(ImportacionDePrueba $run, array $pregunta, string $respuesta): bool
    {
        if ($run->alcance !== 'stock_existente' || ($pregunta['acepta_codigo_articulo'] ?? false) !== true) {
            return false;
        }
        $codigo = PlanillaStockExistente::codigo_de_respuesta($respuesta);

        return $codigo !== null && (new PlanillaStockExistente($run))->codigo_inequivoco($codigo);
    }

    private static function comprobar_dueno(int $user_id, bool $modo_soporte): void
    {
        if ($modo_soporte) {
            abort_unless(ImportacionDePruebaService::dueno_activo($user_id), 409,
                'La importación de soporte está disponible para el dueño de un negocio activo.');

            return;
        }

        $dueno = PruebaHelper::dueno();
        if ($dueno === null || (int) $dueno->id !== $user_id || !PruebaHelper::en_periodo_vigente($user_id)) {
            abort(409, 'La importación automática está disponible para el dueño de una prueba vigente.');
        }
    }
}
