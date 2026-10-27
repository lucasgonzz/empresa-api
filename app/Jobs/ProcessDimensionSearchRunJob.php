<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Jobs\Concerns\InstrumentaMemoria;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use App\Models\User;
use App\Services\DimensionSearch\ArticleDimensionSearchEngine;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use App\Services\ProductFacts\TopeSerperDemo;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Procesa una búsqueda masiva de peso y medidas POR TRAMOS encadenados (misión medidas-masivas-catalogo,
 * 27/10/2026). Es el espejo de ProcessImageAssignmentRunJob.
 *
 * Cada tramo trabaja artículos durante ~50 segundos (config medidas_masivas.segundos_por_tramo, acotado a
 * 20..90) y, si quedan pendientes, se vuelve a encolar. Por qué por tramos y no un job de horas:
 *   - Hostinger mata los procesos largos, y «todo el catálogo» son hasta 5.000 artículos;
 *   - en el VPS hay UN worker por cliente: el asistente y Tienda Nube no pueden esperar más que un tramo;
 *   - si algo se corta, la corrida se retoma desde el artículo siguiente, no desde cero.
 *
 * En cada vuelta: mira el estado de la corrida (si la detuvieron, corta), RECLAMA el próximo artículo de
 * forma atómica (UPDATE ... WHERE status = 'pendiente': dos tramos vivos a la vez nunca procesan el mismo)
 * y se lo pasa al motor. Un artículo «venenoso» (que hace fallar el motor) no traba la corrida: al segundo
 * intento queda `error_interno` y se sigue con el próximo.
 *
 * Cortes automáticos, reanudables: 5 artículos seguidos sin buscador (sin créditos de Serper, clave
 * revocada) o sin IA (clave vencida, sin saldo) dejan la corrida `fallida`; seguir sería pagar para no
 * conseguir nada. Una demo sin búsquedas la termina con el motivo de la demo.
 *
 * La cola: `excel` en el shared hosting (ese worker corre con --memory=512 --max-time=1200 y no retiene
 * al del asistente), la default en el VPS. Mismo criterio que ProcessImageAssignmentRunJob.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 */
class ProcessDimensionSearchRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InstrumentaMemoria;

    /**
     * @var int Sin reintentos automáticos de Laravel: el reintento lo maneja failed(), que sabe devolver
     * el artículo a pendiente y contar los fallos seguidos.
     */
    public $tries = 1;

    /**
     * @var int 7 minutos. Un tramo son hasta SEGUNDOS_POR_TRAMO_MAXIMO (90 s) más el último artículo; el
     * peor artículo (tres búsquedas, hasta 90 s de páginas y tres llamadas a la IA de 45 s) ronda los 270 s.
     * Tiene que quedar por debajo del retry_after de las colas (config/queue.php: 4200 en database, 9000
     * en redis), o el mismo tramo se volvería a levantar mientras corre.
     */
    public $timeout = 420;

    /** Intentos por artículo antes de darlo por «venenoso» (error_interno). */
    const MAX_INTENTOS_POR_ARTICULO = 2;

    /** Tramos seguidos que murieron sin terminar antes de dar la corrida por fallida. */
    const MAX_FALLOS_CONSECUTIVOS = 3;

    /** Artículos seguidos en los que TODAS las búsquedas fallaron por el proveedor antes de frenar la corrida. */
    const MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR = 5;

    /** Artículos seguidos en los que se le pidió algo a la IA y NINGUNA llamada respondió antes de frenar la corrida. */
    const MAX_ARTICULOS_SEGUIDOS_SIN_IA = 5;

    /** Presupuesto de un tramo, en segundos: lo de config acotado a este rango. */
    const SEGUNDOS_POR_TRAMO_MINIMO = 20;
    const SEGUNDOS_POR_TRAMO_MAXIMO = 90;

    /** Minutos que se recuerda que el failed() de una ficha de tramo ya corrió (ver failed()). */
    const MINUTOS_DE_MEMORIA_DEL_FAILED = 1440;

    /** @var int La corrida a procesar. */
    protected $run_id;

    /**
     * @var string Ficha de ESTE tramo: se genera al despachar (viaja en el payload, así handle() y failed()
     * ven la misma) y se escribe en cada artículo que el tramo reclama.
     *
     * 🔴 Es lo que evita procesar dos veces un artículo. Sin ella, el failed() de un tramo que murió hace
     * una hora devolvía a pendiente TODOS los artículos «procesando» de la corrida, incluido el que en ese
     * momento procesaba un tramo vivo.
     */
    protected $tramo = '';

    /**
     * @param int $run_id
     */
    public function __construct($run_id)
    {
        $this->run_id = (int) $run_id;
        $this->tramo  = (string) Str::uuid();

        // Shared hosting: cola 'excel' (separada del asistente). VPS: la default (patrón de ProcessArticleChunk).
        $this->queue = config('app.VPS') ? null : 'excel';
    }

    /**
     * Un tramo.
     *
     * @return void
     */
    public function handle()
    {
        $this->asegurar_memoria_minima();

        $run = DimensionSearchRun::find($this->run_id);

        if (is_null($run) || !$run->esta_activa()) {
            return;
        }

        $owner = User::find($run->user_id);

        if (is_null($owner)) {
            DimensionSearchRunHelper::terminar($run, DimensionSearchRun::STATUS_FALLIDA, 'El dueño de la corrida ya no existe.');

            return;
        }

        // Le sacaron la clave de Serper con la corrida en marcha: no tiene sentido recorrer los artículos
        // fallando uno por uno. Se frena y se puede reanudar cuando vuelva la clave.
        if (!ImageSearchProviderFactory::serper_configurado($owner)) {
            DimensionSearchRunHelper::terminar($run, DimensionSearchRun::STATUS_FALLIDA, 'Se quitó la clave de Serper (no está ni la del comercio ni SERPER_API_KEY en el servidor) y la corrida no puede seguir. Cuando esté cargada de nuevo, se puede reanudar.');

            return;
        }

        $ia = DimensionSearchRunHelper::ia_disponible($owner);

        if (!$ia['configurada']) {
            DimensionSearchRunHelper::terminar($run, DimensionSearchRun::STATUS_FALLIDA, $ia['motivo'].' Sin la IA, la búsqueda no sigue (no hay quién lea las páginas); cuando esté configurada, se puede reanudar.');

            return;
        }

        $this->pasar_a_en_proceso($run);

        $motor = new ArticleDimensionSearchEngine($run, $owner);

        $inicio      = microtime(true);
        $presupuesto = $this->segundos_por_tramo();

        // Artículos que este tramo intentó (hayan salido bien o con error): es lo que mide el fin del tramo.
        $trabajados = 0;

        while (true) {
            // Fin del tramo: siempre después de al menos un artículo (si no, un presupuesto chico
            // re-encolaría para siempre sin avanzar).
            if ($trabajados > 0 && (microtime(true) - $inicio) >= $presupuesto) {
                // Siempre el job de producción (una subclase de prueba no se re-encola a sí misma).
                ProcessDimensionSearchRunJob::dispatch($run->id);

                return;
            }

            $run->refresh();

            // La detuvieron (o la cerró otro tramo): se corta sin tocar nada más.
            if ($run->status !== DimensionSearchRun::STATUS_EN_PROCESO) {
                return;
            }

            $item = DimensionSearchItem::where('run_id', $run->id)
                ->where('status', DimensionSearchItem::STATUS_PENDIENTE)
                ->orderBy('orden')
                ->orderBy('id')
                ->first();

            if (is_null($item)) {
                // Si otro tramo todavía está procesando un artículo, la cierra ese cuando termine.
                $en_curso = DimensionSearchItem::where('run_id', $run->id)
                    ->where('status', DimensionSearchItem::STATUS_PROCESANDO)
                    ->exists();

                if (!$en_curso) {
                    DimensionSearchRunHelper::terminar($run, DimensionSearchRun::STATUS_TERMINADA, null);
                }

                return;
            }

            // En una demo, con las búsquedas agotadas se corta la corrida, con el motivo de la demo.
            if (TopeSerperDemo::agotado($run->user_id)) {
                $this->cortar_por_tope_demo($run);

                return;
            }

            if (!$this->reclamar($item)) {
                continue;
            }

            $item->refresh();

            $trabajados++;

            // Ya falló las veces permitidas (volvió a pendiente por una reanudación): no se insiste.
            if ((int) $item->intentos > self::MAX_INTENTOS_POR_ARTICULO) {
                $this->descartar_por_error($item, 'Falló '.self::MAX_INTENTOS_POR_ARTICULO.' veces al procesarlo y se lo dejó de lado para no trabar la corrida. El detalle quedó en el registro del sistema.');
                continue;
            }

            try {
                $resultado = $motor->procesar($item);
            } catch (\Throwable $e) {
                Log::error('[MedidasMasivas] El motor falló con un artículo.', [
                    'run_id'     => $run->id,
                    'item_id'    => $item->id,
                    'article_id' => $item->article_id,
                    'intentos'   => (int) $item->intentos,
                    'error'      => $e->getMessage(),
                    'archivo'    => $e->getFile().':'.$e->getLine(),
                ]);

                $this->devolver_o_descartar($item->fresh(), $e->getMessage());
                continue;
            }

            $this->registrar_avance($run);

            if ($resultado['cupo_agotado']) {
                // El motor se quedó sin las búsquedas de la demo: se corta con el motivo de la demo.
                $this->cortar_por_tope_demo($run);

                return;
            }

            // Un artículo que no intentó ninguna búsqueda (borrado, ya tenía medidas) no dice nada del proveedor.
            if ((int) $resultado['busquedas_intentadas'] > 0
                && $this->contar_error_de_proveedor($run, (bool) $resultado['error_de_proveedor']) >= self::MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR) {
                $ultimo_error = $this->ultimo_error_del_diagnostico($item->fresh());

                DimensionSearchRunHelper::terminar(
                    $run,
                    DimensionSearchRun::STATUS_FALLIDA,
                    'El proveedor de búsqueda falló en '.self::MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR.' artículos seguidos'
                        .($ultimo_error !== '' ? ' ('.$ultimo_error.')' : '')
                        .'. La corrida se frenó para no seguir recorriendo artículos sin poder buscar; se puede reanudar cuando esté resuelto.'
                );

                return;
            }

            if ($this->contar_articulo_sin_ia($run, $resultado) >= self::MAX_ARTICULOS_SEGUIDOS_SIN_IA) {
                $causa = isset($resultado['ia_error']) ? trim((string) $resultado['ia_error']) : '';

                DimensionSearchRunHelper::terminar(
                    $run,
                    DimensionSearchRun::STATUS_FALLIDA,
                    'La IA no responde en '.self::MAX_ARTICULOS_SEGUIDOS_SIN_IA.' artículos seguidos'
                        .($causa !== '' ? ' (último error: '.$causa.')' : '')
                        .'. La corrida se frenó para no gastar búsquedas; cuando esté resuelto, se puede reanudar.'
                );

                return;
            }
        }
    }

    /**
     * Niveles de transacción que NO son del tramo y que failed() no tiene que deshacer. En un worker no
     * hay ninguno; existe para que los tests (que corren adentro de su propia transacción) puedan decir
     * que la suya no es del tramo.
     *
     * @return int
     */
    protected function niveles_ajenos_de_transaccion()
    {
        return 0;
    }

    /**
     * El presupuesto de un tramo: config medidas_masivas.segundos_por_tramo acotado a
     * [SEGUNDOS_POR_TRAMO_MINIMO, SEGUNDOS_POR_TRAMO_MAXIMO].
     *
     * @return int
     */
    protected function segundos_por_tramo()
    {
        $segundos = (int) config('services.medidas_masivas.segundos_por_tramo', 50);

        return max(self::SEGUNDOS_POR_TRAMO_MINIMO, min(self::SEGUNDOS_POR_TRAMO_MAXIMO, $segundos));
    }

    /**
     * Lleva, EN LA CORRIDA, la cuenta de artículos seguidos en los que se le pidió algo a la IA y ninguna
     * llamada respondió, y devuelve el valor actual. Un artículo en el que la IA respondió la pone en cero;
     * uno que no la necesitó no la toca.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  array $resultado  El de ArticleDimensionSearchEngine::procesar().
     * @return int
     */
    protected function contar_articulo_sin_ia(DimensionSearchRun $run, array $resultado)
    {
        if (!empty($resultado['ia_sin_respuesta'])) {
            DB::table('dimension_search_runs')
                ->where('id', $run->id)
                ->update(['errores_ia_seguidos' => DB::raw('errores_ia_seguidos + 1')]);

            return (int) DB::table('dimension_search_runs')->where('id', $run->id)->value('errores_ia_seguidos');
        }

        if (!empty($resultado['ia_respondio']) && (int) $run->errores_ia_seguidos > 0) {
            DB::table('dimension_search_runs')->where('id', $run->id)->update(['errores_ia_seguidos' => 0]);
        }

        return 0;
    }

    /**
     * El tramo murió (una excepción fuera del manejo por artículo, un timeout, el worker que se reinició).
     * Con $tries = 1, Laravel llega acá.
     *
     * - El artículo que quedó `procesando` vuelve a `pendiente`, o queda `error_interno` si ya iba por su
     *   segundo intento (es el que hace caer el proceso: no se lo reintenta más).
     * - Se suma un fallo seguido y se re-encola la continuación; con MAX_FALLOS_CONSECUTIVOS seguidos, la
     *   corrida queda `fallida`.
     *
     * @param  \Throwable|null $e
     * @return void
     */
    public function failed($e)
    {
        try {
            // Si el tramo murió adentro de una transacción, la conexión puede seguir con ella abierta: lo
            // que se escriba acá quedaría adentro y se perdería con ella. Se deshacen TODOS los niveles.
            while (DB::transactionLevel() > $this->niveles_ajenos_de_transaccion()) {
                DB::rollBack();
            }
        } catch (\Throwable $error_de_rollback) {
            Log::warning('[MedidasMasivas] failed() no pudo deshacer una transacción abierta.', [
                'run_id' => $this->run_id,
                'error'  => $error_de_rollback->getMessage(),
            ]);
        }

        // Una sola vez por ficha de tramo: con la cola `database`, el borrado de la fila de `jobs` puede
        // haber quedado adentro de la transacción deshecha y otro worker darlo por fallido de nuevo.
        if ($this->tramo !== '') {
            try {
                $primera_vez = Cache::add('medidas-masivas:failed:'.$this->tramo, true, Carbon::now()->addMinutes(self::MINUTOS_DE_MEMORIA_DEL_FAILED));
            } catch (\Throwable $error_de_cache) {
                // Sin caché no hay memoria: mejor correrlo que no correrlo nunca.
                $primera_vez = true;
            }

            if (!$primera_vez) {
                Log::info('[MedidasMasivas] failed() ya había corrido para este tramo: no se repite.', [
                    'run_id' => $this->run_id,
                    'tramo'  => $this->tramo,
                ]);

                return;
            }
        }

        try {
            $run = DimensionSearchRun::find($this->run_id);

            if (is_null($run) || !$run->esta_activa()) {
                return;
            }

            $mensaje = !is_null($e) && $e->getMessage() !== ''
                ? $e->getMessage()
                : 'El proceso se interrumpió sin dejar traza (probable falta de memoria, timeout o worker reiniciado).';

            // El detalle crudo, solo al log: al usuario le llega un texto genérico. Sin las claves del
            // servidor ni la de Serper del dueño.
            Log::error('[MedidasMasivas] Se interrumpió un tramo.', [
                'run_id' => $this->run_id,
                'tramo'  => $this->tramo,
                'error'  => ImageServiceCallLogger::sin_claves($mensaje, ImageServiceCallLogger::claves_del_dueno($run->user_id)),
            ]);

            // Solo el artículo que reclamó ESTE tramo: uno «procesando» con otra ficha es de un tramo vivo.
            $en_curso = DimensionSearchItem::where('run_id', $run->id)
                ->where('status', DimensionSearchItem::STATUS_PROCESANDO)
                ->where('tramo', $this->tramo)
                ->get();

            foreach ($en_curso as $item) {
                $this->devolver_o_descartar($item, $mensaje);
            }

            DB::table('dimension_search_runs')
                ->where('id', $run->id)
                ->update([
                    'fallos_consecutivos' => DB::raw('fallos_consecutivos + 1'),
                    'updated_at'          => Carbon::now(),
                ]);

            $run->refresh();

            if ((int) $run->fallos_consecutivos >= self::MAX_FALLOS_CONSECUTIVOS) {
                DimensionSearchRunHelper::terminar(
                    $run,
                    DimensionSearchRun::STATUS_FALLIDA,
                    'La corrida se interrumpió '.self::MAX_FALLOS_CONSECUTIVOS.' veces seguidas. El detalle quedó en el registro del sistema; se puede reanudar.'
                );

                return;
            }

            // Siempre el job de producción (una subclase de prueba no se re-encola a sí misma).
            ProcessDimensionSearchRunJob::dispatch($run->id);
        } catch (\Throwable $error) {
            Log::error('[MedidasMasivas] failed() no pudo reencolar la corrida.', [
                'run_id' => $this->run_id,
                'error'  => $error->getMessage(),
            ]);
        }
    }

    /**
     * La corrida arranca: pendiente → en_proceso (atómico).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return void
     */
    protected function pasar_a_en_proceso(DimensionSearchRun $run)
    {
        if ($run->status === DimensionSearchRun::STATUS_PENDIENTE) {
            $ahora = Carbon::now();

            DB::table('dimension_search_runs')
                ->where('id', $run->id)
                ->where('status', DimensionSearchRun::STATUS_PENDIENTE)
                ->update([
                    'status'           => DimensionSearchRun::STATUS_EN_PROCESO,
                    'started_at'       => is_null($run->started_at) ? $ahora : $run->started_at,
                    'last_progress_at' => $ahora,
                    'updated_at'       => $ahora,
                ]);

            $run->refresh();
        }
    }

    /**
     * Reclama un artículo de forma atómica: solo uno de dos tramos concurrentes lo consigue.
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @return bool
     */
    protected function reclamar(DimensionSearchItem $item)
    {
        $afectadas = DB::table('dimension_search_items')
            ->where('id', $item->id)
            ->where('status', DimensionSearchItem::STATUS_PENDIENTE)
            ->update([
                'status'     => DimensionSearchItem::STATUS_PROCESANDO,
                'intentos'   => DB::raw('intentos + 1'),
                'tramo'      => $this->tramo,
                'updated_at' => Carbon::now(),
            ]);

        return $afectadas > 0;
    }

    /**
     * Un artículo que hizo fallar el motor: vuelve a `pendiente` para otro intento, o queda
     * `error_interno` si ya iba por el último.
     *
     * @param  \App\Models\DimensionSearchItem|null $item
     * @param  string $mensaje
     * @return void
     */
    protected function devolver_o_descartar($item, $mensaje)
    {
        if (is_null($item) || $item->status !== DimensionSearchItem::STATUS_PROCESANDO) {
            return;
        }

        if ((int) $item->intentos >= self::MAX_INTENTOS_POR_ARTICULO) {
            // El mensaje crudo ya quedó en el log de quien llamó: al usuario, un texto genérico.
            $this->descartar_por_error($item, 'Falló '.self::MAX_INTENTOS_POR_ARTICULO.' veces al procesarlo y se lo dejó de lado para no trabar la corrida. El detalle quedó en el registro del sistema.');

            return;
        }

        DB::table('dimension_search_items')
            ->where('id', $item->id)
            ->where('status', DimensionSearchItem::STATUS_PROCESANDO)
            ->where('tramo', $this->tramo)
            ->update([
                'status'     => DimensionSearchItem::STATUS_PENDIENTE,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Cierra un artículo como `error_interno` y lo cuenta como procesado.
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @param  string $detalle
     * @return void
     */
    protected function descartar_por_error(DimensionSearchItem $item, $detalle)
    {
        $ahora = Carbon::now();

        DB::transaction(function () use ($item, $detalle, $ahora) {
            $afectadas = DB::table('dimension_search_items')
                ->where('id', $item->id)
                ->where('status', DimensionSearchItem::STATUS_PROCESANDO)
                ->where('tramo', $this->tramo)
                ->update([
                    'status'         => DimensionSearchItem::STATUS_ERROR_INTERNO,
                    'motivo'         => 'error_interno',
                    'motivo_detalle' => $detalle,
                    'procesado_at'   => $ahora,
                    'updated_at'     => $ahora,
                ]);

            if ($afectadas > 0) {
                DB::table('dimension_search_runs')
                    ->where('id', $item->run_id)
                    ->update([
                        'procesados'       => DB::raw('procesados + 1'),
                        'last_progress_at' => $ahora,
                        'updated_at'       => $ahora,
                    ]);
            }
        });
    }

    /**
     * La demo llegó a su tope de búsquedas de Serper: lo pendiente queda `sin_procesar` / `sin_cupo` y la
     * corrida termina diciéndolo. Los artículos quedan SIN marcar como «ya buscados».
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return void
     */
    protected function cortar_por_tope_demo(DimensionSearchRun $run)
    {
        $ahora = Carbon::now();

        DB::table('dimension_search_items')
            ->where('run_id', $run->id)
            ->where('status', DimensionSearchItem::STATUS_PENDIENTE)
            ->update([
                'status'         => DimensionSearchItem::STATUS_SIN_PROCESAR,
                'motivo'         => 'sin_cupo',
                'motivo_detalle' => TopeSerperDemo::DETALLE_SIN_BUSCAR,
                'updated_at'     => $ahora,
            ]);

        $sin_procesar = DimensionSearchItem::where('run_id', $run->id)
            ->where('status', DimensionSearchItem::STATUS_SIN_PROCESAR)
            ->count();

        $limite = 'Llegaste al límite de búsquedas de la demo ('.TopeSerperDemo::TOPE.')';

        $motivo = $sin_procesar > 0
            ? $limite.': '.($sin_procesar === 1 ? '1 artículo quedó' : $sin_procesar.' artículos quedaron').' sin procesar. Podés cargar las medidas a mano.'
            : $limite.' con el último artículo. Podés cargar las medidas a mano.';

        DimensionSearchRunHelper::terminar($run, DimensionSearchRun::STATUS_TERMINADA, $motivo);
    }

    /**
     * Reinicio del contador de fallos seguidos: si un artículo terminó, el tramo está sano.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return void
     */
    protected function registrar_avance(DimensionSearchRun $run)
    {
        $run->refresh();

        if ((int) $run->fallos_consecutivos > 0) {
            DB::table('dimension_search_runs')->where('id', $run->id)->update(['fallos_consecutivos' => 0]);
        }
    }

    /**
     * Lleva la cuenta, EN LA CORRIDA, de los artículos seguidos en los que todas las búsquedas fallaron
     * por el proveedor, y devuelve el valor actual. Vive en la corrida y no en una variable del tramo: con
     * el proveedor colgado entran uno o dos artículos por tramo y un contador local volvía a cero en cada
     * tramo sin llegar nunca al corte.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  bool $fallo  El artículo recién procesado falló por el proveedor.
     * @return int
     */
    protected function contar_error_de_proveedor(DimensionSearchRun $run, $fallo)
    {
        if ($fallo) {
            DB::table('dimension_search_runs')
                ->where('id', $run->id)
                ->update(['errores_proveedor_seguidos' => DB::raw('errores_proveedor_seguidos + 1')]);

            return (int) DB::table('dimension_search_runs')->where('id', $run->id)->value('errores_proveedor_seguidos');
        }

        if ((int) $run->errores_proveedor_seguidos > 0) {
            DB::table('dimension_search_runs')->where('id', $run->id)->update(['errores_proveedor_seguidos' => 0]);
        }

        return 0;
    }

    /**
     * El error del proveedor que quedó en el diagnóstico del artículo (para el motivo de la corrida
     * fallida). Se tachan las claves del servidor y la de Serper del dueño, por las dudas.
     *
     * @param  \App\Models\DimensionSearchItem|null $item
     * @return string
     */
    protected function ultimo_error_del_diagnostico($item)
    {
        if (is_null($item) || !is_array($item->diagnostico)) {
            return '';
        }

        $ultimo = '';

        foreach ($item->diagnostico as $entrada) {
            if (is_array($entrada) && !empty($entrada['error'])) {
                $ultimo = (string) $entrada['error'];
            }
        }

        // Sin el punto final: el motivo lo pone entre paréntesis y sigue la frase.
        return rtrim(Str::limit(ImageServiceCallLogger::sin_claves($ultimo, ImageServiceCallLogger::claves_del_dueno($item->user_id)), 200, '…'), '. ');
    }
}
