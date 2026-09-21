<?php

namespace App\Jobs;

use App\Events\ImportStatusUpdated;
use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
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

    /**
     * En shared hosting va a la cola 'excel' (separada del asistente por WhatsApp/panel), para
     * que un import o export grande no retenga el mismo worker. En el VPS, null: sigue en
     * 'default', la única que el supervisor de cada cliente consume hoy — cambiar eso es un
     * cambio de infraestructura aparte, no de este job.
     *
     * @return string|null
     */
    public function viaQueue()
    {
        return config('app.VPS') ? null : 'excel';
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

            /*
             * Registro visible del proceso: se cierra después de que el ImportStatus tiene los
             * contadores finales (los lee de ahí) y antes del aviso, para que la píldora y la
             * tarjeta de importación no se contradigan.
             */
            $this->completar_proceso_en_segundo_plano();

            $this->notificar();

        } catch (Throwable $e) {

            Log::error('Error al importar Excel de compra a proveedor, desde ProcessProviderOrderArticleImport::handle', [
                'provider_order_id' => $this->provider_order->id ?? null,
                'mensaje' => $e->getMessage(),
                'archivo'  => $e->getFile(),
                'linea'    => $e->getLine(),
            ]);

            $this->marcar_fallo($e);

            throw $e;
        }
    }

    public function failed(Throwable $exception)
    {
        $this->marcar_fallo($exception);
    }

    /**
     * Marca esta importación como fallida con un mensaje que el USUARIO pueda leer.
     *
     * 🔴 Por qué no se usa `ImportFailureHandler::desde_excepcion()`, que sería lo obvio: ese
     * camino arma el `error_message` concatenando `$e->getMessage()` crudo, y `error_message` es
     * literalmente el texto que la tarjeta de fallo le muestra al usuario. Con una
     * `QueryException` eso le tira por pantalla el SQL completo CON LOS BINDINGS — o sea, datos de
     * su propia base y la estructura de las tablas, en un cartel de error. `formatImportErrorMessage()`
     * ya sabe recortar el SQL y traducir los errores típicos de importación (decimal mal formateado,
     * etc.), así que se reusa esa y se entra por `registrar()`, que es el mismo punto único de
     * marcado pero recibiendo el mensaje ya armado.
     *
     * El detalle técnico no se pierde: va entero al `error_trace` (columna de investigación, no se
     * le muestra al usuario) y al `Log::error` del catch de handle().
     *
     * ⚠️ El mecanismo del mensaje crudo es PREEXISTENTE y sigue vivo en el camino de ARTÍCULOS.
     * Acá se corrige solo compras a propósito: tocar `armar_mensaje_humano()` cambiaría el
     * comportamiento de la importación de catálogo, que está fuera de esta misión.
     *
     * @param \Throwable $e
     * @return void
     */
    private function marcar_fallo($e)
    {
        $rango = ' (filas ' . $this->start_row . '–' . $this->finish_row . ')';

        $mensaje_humano = 'La importación de la compra falló' . $rango . '. Motivo: ' . ImportHelper::formatImportErrorMessage($e);

        ImportFailureHandler::registrar(
            $this->import_history_id,
            $this->import_status_id,
            isset($this->user->id) ? $this->user->id : null,
            $mensaje_humano,
            $this->armar_detalle_tecnico($e)
        );

        /*
         * Registro visible del proceso: registrar() ya lo cierra por referencia al ImportStatus,
         * pero se vuelve a cerrar acá para el caso en que registrar() salga temprano por su
         * idempotencia (el ImportHistory ya resuelto por el watchdog, que no siempre tiene el
         * ImportStatus a mano). `fallar()` es idempotente: el que llega segundo no pisa nada.
         */
        $this->fallar_proceso_en_segundo_plano($mensaje_humano);
    }

    /**
     * Detalle técnico completo del fallo para la columna `error_trace`: clase, mensaje crudo,
     * ubicación, cadena de causas y stack. Es lo que se mira para investigar, y es el lugar donde
     * el mensaje crudo de la base SÍ tiene que estar entero.
     *
     * @param \Throwable $e
     * @return string
     */
    private function armar_detalle_tecnico($e)
    {
        $out = 'Importación de compra #' . (isset($this->provider_order->id) ? $this->provider_order->id : '?')
             . ' — filas ' . $this->start_row . ' a ' . $this->finish_row . "\n\n";

        $actual = $e;
        $nivel  = 0;

        while (!is_null($actual)) {
            $out .= ($nivel === 0 ? 'Excepción' : 'Causada por') . ': ' . get_class($actual) . "\n";
            $out .= 'Mensaje: ' . $actual->getMessage() . "\n";
            $out .= 'Ubicación: ' . $actual->getFile() . ':' . $actual->getLine() . "\n";
            $out .= "Stack:\n" . $actual->getTraceAsString() . "\n\n";

            $actual = $actual->getPrevious();
            $nivel++;
        }

        return $out;
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
     * 🔴 `updated_at` se escribe a mano: `DB::table()->update()` va por el query builder crudo y
     * NO toca los timestamps de Eloquent. El watchdog `imports:detectar-colgadas` decide si una
     * importación está colgada mirando cuánto hace que no se actualiza — sin esta línea, una
     * importación larga pero viva se ve idéntica a una muerta, y lo único que la salvaba era que
     * el timeout del job (30 min) cae antes que el umbral del watchdog (45 min). Eso es un margen
     * de 15 minutos apoyado en dos constantes que viven en archivos distintos: cualquiera de las
     * dos que se mueva y el watchdog empieza a matar importaciones sanas.
     *
     * @param int $filas_de_este_lote
     * @return void
     */
    private function avanzar_progreso($filas_de_este_lote)
    {
        $ahora = now();

        DB::table('import_statuses')
            ->where('id', $this->import_status_id)
            ->update([
                'processed_chunks' => DB::raw('LEAST(processed_chunks + 1, total_chunks)'),
                'filas_procesadas' => DB::raw('filas_procesadas + ' . (int) $filas_de_este_lote),
                'updated_at'       => $ahora,
            ]);

        DB::table('import_histories')
            ->where('id', $this->import_history_id)
            ->update([
                'processed_chunks' => DB::raw('LEAST(processed_chunks + 1, total_chunks)'),
                'filas_procesadas' => DB::raw('filas_procesadas + ' . (int) $filas_de_este_lote),
                'updated_at'       => $ahora,
            ]);

        $this->notificar();

        /* Registro visible del proceso: después de las escrituras propias del flujo, nunca en el medio. */
        $this->avanzar_proceso_en_segundo_plano();
    }

    /**
     * Escribe el avance en el registro visible de procesos (misión procesos-en-segundo-plano).
     *
     * Las filas hechas se leen del ImportStatus recién incrementado y no del argumento del
     * callback: son las acumuladas de toda la corrida, que es lo que mide la barra. La fila del
     * proceso se busca por referencia en cada llamada, no se guarda en el job (viaja serializado
     * por la cola y un id guardado quedaría rancio). Los creados/actualizados no se conocen hasta
     * el final (attach_articles corre una sola vez sobre el conjunto completo), así que en el
     * medio solo viajan las filas; el cierre trae los cuatro números.
     *
     * Nunca tira: es presentación, y una excepción acá caería en el catch del handle() y marcaría
     * la importación como fallida por una barrita.
     *
     * @return void
     */
    private function avanzar_proceso_en_segundo_plano()
    {
        try {
            $import_status = ImportStatus::select('id', 'filas_procesadas')->find($this->import_status_id);

            if (is_null($import_status)) {
                return;
            }

            $proceso = BackgroundProcessHelper::por_referencia($import_status);

            if (is_null($proceso)) {
                return;
            }

            $filas = (int) $import_status->filas_procesadas;

            BackgroundProcessHelper::avanzar($proceso, $filas, [
                'etapa'     => 'Fila ' . number_format($filas, 0, ',', '.') . ' de ' . number_format((int) $proceso->total, 0, ',', '.'),
                'resultado' => ['filas_procesadas' => $filas],
            ]);
        } catch (Throwable $e) {
            Log::warning('ProcessProviderOrderArticleImport: no se pudo registrar el avance del proceso en segundo plano (la importación sigue).', [
                'import_status_id' => $this->import_status_id,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra el registro visible del proceso con los contadores finales del ImportStatus (que
     * marcar_completado() acaba de escribir). Idempotente del lado del helper y nunca tira.
     *
     * @return void
     */
    private function completar_proceso_en_segundo_plano()
    {
        try {
            $import_status = ImportStatus::find($this->import_status_id);

            if (is_null($import_status)) {
                return;
            }

            $proceso = BackgroundProcessHelper::por_referencia($import_status);

            if (is_null($proceso)) {
                return;
            }

            BackgroundProcessHelper::completar($proceso, [
                'filas_procesadas' => (int) $import_status->filas_procesadas,
                'creados'          => (int) $import_status->created_models,
                'actualizados'     => (int) $import_status->updated_models,
                'coincidencias'    => (int) $import_status->articles_match,
            ], 'Terminado');
        } catch (Throwable $e) {
            Log::warning('ProcessProviderOrderArticleImport: no se pudo cerrar el proceso en segundo plano (la importación terminó igual).', [
                'import_status_id' => $this->import_status_id,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra el registro visible del proceso en `fallo` con el mensaje que ve el usuario. Se
     * llama desde marcar_fallo(), que corre desde el catch del handle() Y desde failed(): el
     * helper es idempotente, así que el segundo no pisa al primero. Nunca tira.
     *
     * @param  string $mensaje_humano
     * @return void
     */
    private function fallar_proceso_en_segundo_plano($mensaje_humano)
    {
        try {
            $import_status = ImportStatus::select('id')->find($this->import_status_id);

            if (is_null($import_status)) {
                return;
            }

            $proceso = BackgroundProcessHelper::por_referencia($import_status);

            if (is_null($proceso)) {
                return;
            }

            BackgroundProcessHelper::fallar($proceso, $mensaje_humano);
        } catch (Throwable $e) {
            Log::warning('ProcessProviderOrderArticleImport: no se pudo marcar el fallo del proceso en segundo plano.', [
                'import_status_id' => $this->import_status_id,
                'error'            => $e->getMessage(),
            ]);
        }
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
