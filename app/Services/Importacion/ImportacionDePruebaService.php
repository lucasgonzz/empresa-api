<?php

namespace App\Services\Importacion;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\InitExcelImport;
use App\Jobs\FinalizeEmbeddingRun;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ImageAssignmentRun;
use App\Models\ImportacionDePrueba;
use App\Models\ImportHistory;
use App\Models\User;
use App\Services\CupoEmbeddingsDePrueba;
use App\Services\ImageAssignment\CupoImagenesDePrueba;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ImportacionDePruebaService
{
    public function avanzar(ImportacionDePrueba $run): void
    {
        if (!PruebaHelper::en_periodo_vigente((int) $run->user_id) && in_array($run->estado, ['analizar', 'preparar'], true)) {
            $this->fallar($run, 'La prueba terminó antes de empezar a importar el archivo.');
            return;
        }

        if ($run->estado === 'analizar') {
            $this->analizar($run);
        }

        switch ($run->estado) {
            case 'preparar':
                $this->preparar($run);
                break;
            case 'importando':
                $this->comprobar_importacion($run);
                break;
            case 'imagenes':
                $this->comprobar_imagenes($run);
                break;
            case 'indexar':
                $this->iniciar_indexacion($run);
                break;
            case 'indexando':
                $this->comprobar_indexacion($run);
                break;
        }
    }

    private function analizar(ImportacionDePrueba $run): void
    {
        $planilla = new PlanillaDePrueba($run);
        $hojas = $planilla->analizar(app(AiExcelAnalyzer::class, ['user_id' => (int) $run->user_id]));
        $preguntas = $planilla->preguntas_columnas($hojas);

        DB::transaction(function () use ($run, $hojas, $preguntas) {
            $run->hojas = $hojas;
            if ($preguntas !== []) {
                $this->consultar($run, 'columnas', $preguntas);
            } else {
                $run->update(['estado' => 'preparar']);
            }
        });
    }

    private function preparar(ImportacionDePrueba $run): void
    {
        $planilla = new PlanillaDePrueba($run);
        $decisiones = $run->decisiones ?? [];
        try {
            $hojas = $planilla->resolver_columnas($run->hojas ?? [], $decisiones);
        } catch (InvalidArgumentException $error) {
            DB::transaction(function () use ($run, $planilla, $error) {
                $this->consultar($run, 'columnas', $planilla->preguntas_columnas($run->hojas ?? []), $error->getMessage());
            });
            return;
        }

        if ($hojas === []) {
            $this->fallar($run, 'No encontré nombres ni códigos de artículos en el archivo.');
            return;
        }

        $coincidencias = $planilla->coincidencias($hojas, $decisiones);
        if ($coincidencias['preguntas'] !== []) {
            DB::transaction(function () use ($run, $coincidencias) {
                $this->consultar($run, 'repetidos', $coincidencias['preguntas']);
            });
            return;
        }

        $plan = $planilla->escribir($hojas, $coincidencias);
        if ($plan['filas'] === 0) {
            $this->fallar($run, 'El archivo no tiene filas con artículos para importar.');
            return;
        }

        $owner = User::findOrFail($run->user_id);
        $resultado = DB::transaction(function () use ($run, $owner, $plan, $hojas) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if (ImportHistory::where('user_id', $owner->id)->whereIn('status', ['en_preparacion', 'en_proceso'])->exists()) {
                // Otra importación ya está en marcha. El barrido retoma esta cuando termina.
                return null;
            }

            $run->update(['hojas' => $hojas]);

            return (new InitExcelImport())->importar([
                'import_uuid' => $run->uuid,
                'archivo_excel' => storage_path('app/' . $run->archivo),
                'archivo_excel_path' => $run->archivo,
                'planilla_de_prueba' => $plan + ['id' => $run->id],
                'columns' => $plan['columnas'],
                'create_and_edit' => true,
                'start_row' => 2,
                'finish_row' => $plan['filas'] + 1,
                'provider_id' => null,
                'user' => $owner,
                'auth_user_id' => $owner->id,
                'prueba_origen' => $run->origen,
                'registrar_art_cre' => true,
                'registrar_art_act' => true,
                'permitir_provider_code_repetido' => false,
                'permitir_provider_code_repetido_en_multi_providers' => true,
                'actualizar_articulos_de_otro_proveedor' => false,
                'actualizar_por_provider_code' => true,
                'actualizar_proveedor' => false,
                'desempatar_por_nombre' => true,
            ]);
        });

        @unlink($plan['ruta']);
        if ($resultado !== null && $resultado['hubo_un_error']) {
            throw new RuntimeException('El motor de importación no pudo preparar el archivo.');
        }
    }

    private function consultar(ImportacionDePrueba $run, string $tipo, array $preguntas, string $prefacio = ''): void
    {
        $decisiones = $run->decisiones ?? [];
        if ($tipo === 'columnas') {
            $decisiones = array_diff_key($decisiones, $preguntas);
        }
        $run->update([
            'estado' => $tipo, 'revision' => (int) $run->revision + 1,
            'preguntas' => $preguntas, 'decisiones' => $decisiones,
        ]);

        $mensajes = array_column(array_slice($preguntas, 0, 6), 'texto');
        $texto = 'Para seguir con «' . mb_substr($run->nombre, 0, 110) . '» (referencia ' . substr($run->uuid, 0, 8)
            . '), necesito aclarar ' . (count($preguntas) === 1 ? 'esto' : 'estos datos') . '. ';
        $texto .= $prefacio !== '' ? $prefacio . "\n\n" : "\n\n";
        $texto .= implode("\n\n", $mensajes) . "\n\nRespondeme por acá y sigo con la carga.";
        if (count($preguntas) > 6) {
            $texto .= ' Después te consulto lo que falte.';
        }
        $run->aviso('pregunta-' . $run->revision, $texto);
    }

    private function comprobar_importacion(ImportacionDePrueba $run): void
    {
        $historial = ImportHistory::findOrFail($run->import_history_id);
        if (in_array($historial->status, ['fallo', 'fallido'], true)) {
            $this->fallar($run, 'La importación se interrumpió. Es posible que se hayan cargado algunos artículos.');
            return;
        }
        // El último lote también marca «terminado». El agregado se escribe recién en el finalizador.
        if ($historial->status !== 'terminado' || $historial->matching_counts_json === null
            || (int) $historial->processed_chunks < (int) $historial->total_chunks) {
            return;
        }

        DB::transaction(function () use ($run, $historial) {
            $run = ImportacionDePrueba::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $creados = (int) $historial->created_models;
            $actualizados = (int) $historial->updated_models;
            $problemas = ArticleImportHelper::contar_filas_con_problemas($historial->id);
            $conteos = json_decode((string) $historial->matching_counts_json, true) ?? [];
            $omitidas = max($problemas['no_importadas'], ($conteos['ambiguo'] ?? 0) + ($conteos['bloqueado_otro_proveedor'] ?? 0)
                + ($conteos['sin_match_no_creado'] ?? 0) + ($conteos['sin_clasificar'] ?? 0));
            $estado = $omitidas > 0 || $problemas['para_revisar'] > 0 ? 'parcial' : 'completado';
            $detalle = $creados . ($creados === 1 ? ' artículo creado y ' : ' artículos creados y ')
                . $actualizados . ($actualizados === 1 ? ' actualizado.' : ' actualizados.');
            if ($omitidas > 0) {
                $detalle .= ' ' . $omitidas . ' filas quedaron sin importar.';
            }
            if ($problemas['para_revisar'] > 0) {
                $detalle .= ' ' . $problemas['para_revisar'] . ' filas quedaron con datos para revisar.';
            }

            $lote = $this->iniciar_imagenes($run, $historial);
            $vigente = PruebaHelper::en_periodo_vigente((int) $run->user_id);
            $siguientes = [];
            if ($lote !== null) {
                $siguientes[] = 'Estamos buscando imágenes para tus artículos más nuevos.';
            }
            if ($vigente) {
                $siguientes[] = $lote !== null
                    ? 'Después de la búsqueda de imágenes, el agente de WhatsApp va a analizar tus artículos.'
                    : 'Estamos preparando el análisis de tus artículos para el agente de WhatsApp.';
            }

            $run->hito('migracion', [
                'etapa' => 4, 'icono' => 'file-earmark-arrow-up', 'titulo' => 'Migración de datos',
                'estado' => $estado, 'detalle' => $detalle, 'siguientes' => $siguientes, 'accion' => null,
                'creados' => $creados, 'actualizados' => $actualizados,
            ]);
            $texto = ($estado === 'completado' ? 'Terminé de importar tu catálogo. ' : 'Procesé tu catálogo y quedaron datos para revisar. ') . $detalle;
            $campos = [];
            foreach ($run->hojas ?? [] as $hoja) {
                $campos += $hoja['mapeo'] ?? [];
            }
            if ($campos !== []) {
                $texto .= ' En el archivo encontré estos datos: ' . mb_substr(str_replace('_', ' ', implode(', ', array_keys($campos))), 0, 700) . '.';
            }
            $semilla_borrada_at = optional(PruebaHelper::config((int) $run->user_id))->semilla_borrada_at;
            if ($semilla_borrada_at !== null && $semilla_borrada_at->gte($historial->created_at)) {
                $texto .= ' La información de ejemplo ya se reemplazó por la tuya.';
            }
            $run->aviso('resultado', $texto);
            if ($lote !== null) {
                $run->image_assignment_run_id = $lote->id;
            } else {
                $run->hito('imagenes', [
                    'etapa' => 6, 'icono' => 'images', 'titulo' => 'Imágenes del catálogo',
                    'estado' => 'parcial', 'detalle' => 'No había artículos nuevos sin imagen o ya no quedaban búsquedas disponibles.',
                    'siguientes' => [], 'accion' => null, 'avisar' => false,
                ]);
            }
            $run->estado = $lote !== null ? 'imagenes' : ($vigente ? 'indexar' : 'terminado');
            $run->save();
        });
    }

    private function iniciar_imagenes(ImportacionDePrueba $run, ImportHistory $historial): ?ImageAssignmentRun
    {
        $owner_id = (int) $run->user_id;
        if (!PruebaHelper::en_periodo_vigente($owner_id)) {
            return null;
        }

        $saldo = CupoImagenesDePrueba::estado($owner_id);
        $limite = (int) ($saldo['restantes'] ?? 0);
        if ($limite === 0) {
            return null;
        }

        $ids = $historial->articulos_creados()
            ->where('articles.user_id', $owner_id)->where('articles.status', 'active')->whereDoesntHave('images')
            ->orderByDesc('articles.id')->limit($limite)->pluck('articles.id')->all();

        return $ids === [] ? null : ImageAssignmentRunHelper::crear(
            User::findOrFail($owner_id), $ids, ImageAssignmentRun::ORIGEN_SELECCION, $owner_id, ['purgar' => false]
        );
    }

    private function comprobar_imagenes(ImportacionDePrueba $run): void
    {
        $lote = ImageAssignmentRun::where('user_id', $run->user_id)->find($run->image_assignment_run_id);
        if ($lote !== null && in_array($lote->status, [ImageAssignmentRun::STATUS_PENDIENTE, ImageAssignmentRun::STATUS_EN_PROCESO], true)) {
            if ($lote->updated_at->gt(now()->subHour())) {
                return;
            }
            // La cola puede retomar el lote. La implementación no queda clavada esperando una hora sin señal.
        }

        $conteos = $lote === null ? [] : ImageAssignmentRunHelper::conteos_de([$lote->id])[$lote->id];
        $asignadas = (int) ($conteos['asignadas'] ?? 0);
        $a_revisar = (int) ($conteos['a_revisar'] ?? 0);
        $total = (int) ($lote->total_articulos ?? 0);
        $vigente = PruebaHelper::en_periodo_vigente((int) $run->user_id);
        $detalle = $asignadas . ($asignadas === 1 ? ' imagen asignada. ' : ' imágenes asignadas. ')
            . $a_revisar . ($a_revisar === 1 ? ' sugerencia para revisar' : ' sugerencias para revisar')
            . ' de ' . $total . ($total === 1 ? ' artículo.' : ' artículos.');
        DB::transaction(function () use ($run, $lote, $asignadas, $total, $vigente, $detalle) {
            $run = ImportacionDePrueba::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $run->hito('imagenes', [
                'etapa' => 6, 'icono' => 'images', 'titulo' => 'Imágenes del catálogo',
                'estado' => $total > 0 && $asignadas === $total ? 'completado' : 'parcial',
                'detalle' => $detalle,
                'siguientes' => $vigente ? ['Ahora el agente de WhatsApp va a analizar tus artículos.'] : [],
                'accion' => $lote !== null ? ['tipo' => 'imagenes', 'lote_id' => (int) $lote->id, 'lote_uuid' => $lote->uuid] : null,
            ]);
            $run->estado = $vigente ? 'indexar' : 'terminado';
            $run->save();
        });
    }

    private function iniciar_indexacion(ImportacionDePrueba $run): void
    {
        $owner_id = (int) $run->user_id;
        if (!PruebaHelper::en_periodo_vigente($owner_id)
            || CupoEmbeddingsDePrueba::omitir_importacion($owner_id)
            || config('services.openai.embeddings_generacion_pausada')
            || trim((string) config('services.openai.api_key')) === ''
            || !UserHelper::hasExtencion('agentes_ia', User::findOrFail($owner_id))) {
            $this->terminar_indexacion($run);
            return;
        }

        Artisan::call('articles:generate-embeddings', [
            '--ignorar-importacion-en-curso' => true,
            '--origen' => 'prueba',
            '--importacion-prueba' => $run->id,
        ]);

        $run->refresh();
        if ($run->embedding_run_id !== null) {
            $this->comprobar_indexacion($run);
        } else {
            $abierta = EmbeddingRun::where('user_id', $owner_id)->whereIn('status', ['despachando', 'en_proceso'])
                ->latest('id')->first();
            if ($abierta === null || now()->gte(FinalizeEmbeddingRun::vence_at($abierta))) {
                $this->terminar_indexacion($run);
            }
        }
    }

    private function comprobar_indexacion(ImportacionDePrueba $run): void
    {
        $tanda = EmbeddingRun::where('user_id', $run->user_id)->find($run->embedding_run_id);
        if ($tanda !== null && in_array($tanda->status, ['despachando', 'en_proceso'], true)
            && now()->lt(FinalizeEmbeddingRun::vence_at($tanda))) {
            return;
        }

        $this->terminar_indexacion($run);
    }

    private function terminar_indexacion(ImportacionDePrueba $run): void
    {
        $activos = Article::where('user_id', $run->user_id)->where('status', 'active');
        $total = (clone $activos)->count();
        $listos = $activos->whereNotNull('embedding')->whereColumn('embedding_generated_at', '>=', 'updated_at')->count();
        DB::transaction(function () use ($run, $total, $listos) {
            $run = ImportacionDePrueba::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $run->hito('agente', [
                'etapa' => 6, 'icono' => 'robot', 'titulo' => 'Agente de WhatsApp',
                'estado' => $listos > 0 ? ($listos === $total ? 'completado' : 'parcial') : 'error',
                'detalle' => $listos > 0
                    ? 'El agente ya analizó ' . $listos . ' de tus ' . $total . ' artículos. Ya podés probar cómo responde sobre ellos.'
                    : 'Todavía no hay artículos listos para que el agente pueda responder sobre ellos.',
                'siguientes' => [], 'accion' => $listos > 0 ? ['tipo' => 'agente'] : null,
                'aprobado' => false,
            ]);
            $run->estado = 'terminado';
            $run->save();
        });
    }

    public function fallar(ImportacionDePrueba $run, string $detalle = 'No pude terminar de procesar el archivo. Avisame por acá y lo revisamos.'): void
    {
        DB::transaction(function () use ($run, $detalle) {
            $run = ImportacionDePrueba::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $hitos = $run->hitos ?? [];
            if (isset($hitos['migracion'])) {
                $imagenes = $run->estado === 'imagenes';
                $run->hito($imagenes ? 'imagenes' : 'agente', [
                    'etapa' => 6, 'icono' => $imagenes ? 'images' : 'robot',
                    'titulo' => $imagenes ? 'Imágenes del catálogo' : 'Agente de WhatsApp',
                    'implementacion' => $imagenes ? 'sistema' : 'agente',
                    'estado' => 'error', 'detalle' => 'No pudimos terminar esta etapa.',
                    'siguientes' => [], 'accion' => null,
                ]);
                $run->estado = $imagenes ? 'indexar' : 'error';
            } else {
                $run->hito('migracion', [
                    'etapa' => 4, 'icono' => 'file-earmark-arrow-up', 'titulo' => 'Migración de datos',
                    'estado' => 'error', 'detalle' => $detalle, 'siguientes' => [], 'accion' => null,
                ]);
                $run->aviso('error', $detalle);
                $run->estado = 'error';
            }
            $run->save();
        });
    }
}
