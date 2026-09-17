<?php

namespace App\Jobs;

use App\Events\ImportStatusUpdated;
use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Imports\ProviderOrderArticleImport;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Corre la importación de Excel de una compra a proveedor fuera del request HTTP (misión
 * `import-excel-compras-chunks`, 14/9/2026). Reemplaza al job homónimo que existía antes: ese
 * nunca se llegó a usar (dispatch() comentado en ProviderOrderController) y tenía bugs de runtime
 * que lo hubieran roto apenas algo fallara — ver informe de esta misión para el detalle.
 *
 * No trocea el archivo en varios jobs de Laravel: procesa todo en una sola corrida, reportando
 * avance cada N filas (ver ProviderOrderArticleImport::FILAS_POR_AVISO_DE_PROGRESO), porque el
 * pipeline de negocio final (attach_articles → check_modo_facturacion → procesar_pedido) opera
 * sobre el conjunto COMPLETO de líneas de la compra y tiene que correr una única vez — trocearlo
 * en jobs independientes reproduciría el bug ya conocido de "la compra se procesa N veces" (ver
 * informe `20260822-importacion-excel-tres-defectos.md`).
 */
class ProcessProviderOrderArticleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $columns, $start_row, $finish_row, $user, $provider_order, $import_type,
              $overwrite_articles, $hoja, $hoja_nombre, $archivo_excel_path,
              $import_status_id, $import_history_id;

    public $timeout = 1800; // 30 minutos, igual piso que ProcessArticleChunk
    public $tries = 1;

    public function __construct(
        $columns,
        $start_row,
        $finish_row,
        $user,
        $provider_order,
        $import_type,
        $overwrite_articles,
        $hoja,
        $hoja_nombre,
        $archivo_excel_path,
        $import_status_id,
        $import_history_id
    ) {
        $this->columns             = $columns;
        $this->start_row           = $start_row;
        $this->finish_row          = $finish_row;
        $this->user                = $user;
        $this->provider_order      = $provider_order;
        $this->import_type         = $import_type;
        $this->overwrite_articles  = $overwrite_articles;
        $this->hoja                = $hoja;
        $this->hoja_nombre         = $hoja_nombre;
        $this->archivo_excel_path  = $archivo_excel_path;
        $this->import_status_id    = $import_status_id;
        $this->import_history_id   = $import_history_id;
    }

    public function handle()
    {
        $import_status = ImportStatus::find($this->import_status_id);

        // Watchdog/reintento ya la marcó fallida: no reprocesar (mismo guard que ProcessArticleChunk).
        if ($import_status && $import_status->status === 'fallo') {
            return;
        }

        $this->asegurar_memoria_minima();

        try {

            $this->verificar_memoria_disponible();

            $this->marcar_en_proceso();
            $this->notificar();

            $importer = new ProviderOrderArticleImport(
                $this->columns,
                $this->start_row,
                $this->finish_row,
                $this->user,
                $this->provider_order,
                $this->import_type,
                $this->overwrite_articles,
                $this->hoja,
                $this->hoja_nombre,
                function ($filas_de_este_lote, $num_row) {
                    $this->avanzar_progreso($filas_de_este_lote);
                }
            );

            Excel::import($importer, $this->archivo_excel_path);

            $this->marcar_completado($importer);
            $this->notificar();

        } catch (Throwable $e) {

            Log::error('Error al importar Excel de compra a proveedor, desde ProcessProviderOrderArticleImport::handle', [
                'provider_order_id' => $this->provider_order->id ?? null,
                'mensaje' => $e->getMessage(),
                'archivo'  => $e->getFile(),
                'linea'    => $e->getLine(),
            ]);

            ImportFailureHandler::desde_excepcion(
                $this->import_history_id,
                $this->import_status_id,
                $this->user->id ?? null,
                $e,
                null,
                ['start_row' => $this->start_row, 'finish_row' => $this->finish_row]
            );

            throw $e;
        }
    }

    public function failed(Throwable $exception)
    {
        ImportFailureHandler::desde_excepcion(
            $this->import_history_id,
            $this->import_status_id,
            $this->user->id ?? null,
            $exception,
            null,
            ['start_row' => $this->start_row, 'finish_row' => $this->finish_row]
        );
    }

    /**
     * Marca ImportStatus/ImportHistory como 'en_proceso' al arrancar, si todavía no lo estaban.
     * Da feedback inmediato al usuario sin esperar al primer aviso de progreso.
     *
     * @return void
     */
    private function marcar_en_proceso()
    {
        ImportStatus::where('id', $this->import_status_id)
            ->where('status', '!=', 'en_proceso')
            ->update(['status' => 'en_proceso']);

        ImportHistory::where('id', $this->import_history_id)
            ->where('status', '!=', 'en_proceso')
            ->update(['status' => 'en_proceso']);
    }

    /**
     * Avanza el contador de "chunks lógicos" (sub-lotes de progreso, no jobs de Laravel) y
     * notifica por WebSocket. Actualización atómica por si en el futuro esto corriera con más de
     * un worker sobre la misma importación (hoy no aplica: es un solo job).
     *
     * @param int $filas_de_este_lote
     * @return void
     */
    private function avanzar_progreso($filas_de_este_lote)
    {
        DB::table('import_statuses')
            ->where('id', $this->import_status_id)
            ->update([
                'processed_chunks' => DB::raw('LEAST(processed_chunks + 1, total_chunks)'),
                'filas_procesadas' => DB::raw('filas_procesadas + ' . (int) $filas_de_este_lote),
            ]);

        DB::table('import_histories')
            ->where('id', $this->import_history_id)
            ->update([
                'processed_chunks' => DB::raw('LEAST(processed_chunks + 1, total_chunks)'),
                'filas_procesadas' => DB::raw('filas_procesadas + ' . (int) $filas_de_este_lote),
            ]);

        $this->notificar();
    }

    /**
     * Cierra la importación: contadores finales, status 'completado'/'terminado', y el diff
     * pedido/recibido si el modo de importación es 'recibido'.
     *
     * @param ProviderOrderArticleImport $importer Ya corrió collection(); trae los contadores y el diff.
     * @return void
     */
    private function marcar_completado(ProviderOrderArticleImport $importer)
    {
        $operaciones = $this->import_type === 'recibido'
            ? json_encode(['diff' => $importer->diff])
            : null;

        DB::table('import_statuses')
            ->where('id', $this->import_status_id)
            ->update([
                'processed_chunks' => DB::raw('total_chunks'),
                'created_models'   => DB::raw('created_models + ' . (int) $importer->creados),
                'updated_models'   => DB::raw('updated_models + ' . (int) $importer->actualizados),
                'articles_match'   => DB::raw('articles_match + ' . (int) $importer->actualizados),
                'status'           => 'completado',
            ]);

        ImportHistory::where('id', $this->import_history_id)
            ->update([
                'processed_chunks' => DB::raw('total_chunks'),
                'created_models'   => DB::raw('created_models + ' . (int) $importer->creados),
                'updated_models'   => DB::raw('updated_models + ' . (int) $importer->actualizados),
                'articles_match'   => DB::raw('articles_match + ' . (int) $importer->actualizados),
                'status'           => 'terminado',
                'terminado_at'     => now(),
                'operaciones'      => $operaciones,
            ]);
    }

    private function notificar()
    {
        try {
            broadcast(new ImportStatusUpdated($this->import_status_id, $this->user->id));
        } catch (Throwable $e) {
            Log::error('ProcessProviderOrderArticleImport: falló broadcast ImportStatusUpdated.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sube el memory_limit a un piso razonable si está por debajo (nunca lo baja). Best effort:
     * en hosting compartido puede estar capado y el ini_set no tener efecto. Mismo mecanismo que
     * ProcessArticleChunk::asegurar_memoria_minima() — duplicado a propósito en vez de extraído a
     * un helper compartido, para no tocar un archivo fuera del alcance de esta misión.
     *
     * @return void
     */
    private function asegurar_memoria_minima()
    {
        $piso_bytes = 512 * 1024 * 1024; // 512 MB
        $actual = $this->memory_limit_bytes();

        if ($actual > 0 && $actual < $piso_bytes) {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
     * Si ya se está usando más del 85% del memory_limit al arrancar, corta con un mensaje claro
     * en vez de esperar a un OOM crudo. Mismo umbral que ProcessArticleChunk.
     *
     * @return void
     */
    private function verificar_memoria_disponible()
    {
        $limite = $this->memory_limit_bytes();

        if ($limite <= 0) {
            return;
        }

        $en_uso = memory_get_usage(true);
        $umbral = (int) ($limite * 0.85);

        if ($en_uso > $umbral) {
            $usados_mb = number_format($en_uso / 1048576, 0);
            $limite_mb = number_format($limite / 1048576, 0);

            throw new \RuntimeException(
                'Memoria insuficiente al iniciar la importación de la compra: '
                . $usados_mb . ' MB usados de ' . $limite_mb . ' MB. '
                . 'Bajá el tamaño del archivo o subí el memory_limit del worker.'
            );
        }
    }

    /**
     * @return int Bytes del memory_limit vigente. -1 = sin límite. 0 = no se pudo parsear.
     */
    private function memory_limit_bytes()
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $unidad = strtoupper(substr($raw, -1));
        $numero = (int) $raw;

        switch ($unidad) {
            case 'G':
                return $numero * 1024 * 1024 * 1024;
            case 'M':
                return $numero * 1024 * 1024;
            case 'K':
                return $numero * 1024;
            default:
                if (is_numeric($raw)) {
                    return (int) $raw;
                }
                return 0;
        }
    }
}
