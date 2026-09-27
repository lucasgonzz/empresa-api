<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Jobs\Concerns\InstrumentaMemoria;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use App\Services\ImageAssignment\ArticleImageAssignmentEngine;
use App\Services\ImageAssignment\ImageServiceCallLogger;
use App\Services\ImageSearch\ImageSearchProviderFactory;
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
 * Procesa una asignación inteligente de imágenes POR TRAMOS encadenados (misión
 * imagenes-catalogo-completo, 27/9/2026). Reemplaza, para las asignaciones nuevas, a
 * ProcessArticleBatchImagesJob (que se queda para poder correr los que ya estaban encolados).
 *
 * Cada tramo trabaja artículos durante ~50 segundos (config imagenes_inteligentes.segundos_por_tramo)
 * y, si quedan pendientes, se vuelve a encolar. Por qué por tramos y no un job de horas:
 *   - Hostinger mata los procesos largos, y "todo el catálogo" son 5.000 artículos (~11 horas);
 *   - en el VPS hay UN worker por cliente: el asistente y Tienda Nube no pueden esperar más que un
 *     tramo para que el worker los atienda;
 *   - si algo se corta, la corrida se retoma desde el artículo siguiente, no desde cero.
 *
 * En cada vuelta: mira el estado de la asignación (si la detuvieron, corta), el cupo diario si
 * aplica (sin cupo, el resto queda `sin_procesar` / `sin_cupo` y la asignación termina con ese
 * motivo), RECLAMA el próximo artículo de forma atómica (UPDATE ... WHERE status = 'pendiente':
 * dos tramos vivos a la vez nunca procesan el mismo) y se lo pasa al motor.
 *
 * Un artículo "venenoso" (uno que hace fallar el motor) no traba la corrida: al segundo intento
 * queda `no_asignada` / `error_interno` y se sigue con el próximo.
 *
 * La cola: `excel` en el shared hosting (ahí ese worker corre con --memory=512 --max-time=1200 y
 * no retiene al del asistente), la default en el VPS. Mismo criterio que ProcessArticleChunk.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 */
class ProcessImageAssignmentRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InstrumentaMemoria;

    /**
     * @var int Sin reintentos automáticos de Laravel: el reintento lo maneja failed(), que sabe
     * devolver el artículo a pendiente y contar los fallos seguidos.
     */
    public $tries = 1;

    /**
     * @var int 7 minutos (sexta pasada, B13). Un tramo son hasta SEGUNDOS_POR_TRAMO_MAXIMO (90 s) más
     * el último artículo, y el peor artículo ronda los 190 s (dos búsquedas de 15 s, 16 descargas y
     * hasta 4 llamadas a la IA de 25 s): con 300 s un tramo largo podía morir por timeout en el medio
     * de un artículo sano. Tiene que quedar por debajo del retry_after de las colas (config/queue.php:
     * 4200 en database, 9000 en redis), o el mismo tramo se volvería a levantar mientras corre.
     */
    public $timeout = 420;

    /** Intentos por artículo antes de darlo por "venenoso" (error_interno). */
    const MAX_INTENTOS_POR_ARTICULO = 2;

    /** Tramos seguidos que murieron sin terminar antes de dar la asignación por fallida. */
    const MAX_FALLOS_CONSECUTIVOS = 3;

    /**
     * Artículos seguidos en los que TODAS las búsquedas fallaron por el proveedor (sin créditos en
     * Serper, clave revocada, Google caído) antes de frenar la asignación como fallida. Sin este
     * corte, una clave sin créditos recorría miles de artículos en minutos dejándolos a todos
     * "no asignados" por error de búsqueda. Se puede reanudar cuando el proveedor vuelve.
     */
    const MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR = 5;

    /**
     * Artículos seguidos en los que se le pidió algo a la IA y NINGUNA llamada respondió (clave
     * vencida, sin saldo, Anthropic caído) antes de frenar la asignación como fallida (plan §13, B2).
     * Sin IA nada se asigna solo: seguir sería pagar búsquedas para dejar todo "a revisar". Un
     * artículo que no necesitó la IA no toca el contador. Se puede reanudar cuando la IA vuelve.
     */
    const MAX_ARTICULOS_SEGUIDOS_SIN_IA = 5;

    /**
     * Presupuesto de un tramo, en segundos: lo de config acotado a este rango (plan §13, B13). El
     * máximo bajó de 120 a 90 en la sexta pasada: 90 s más el peor artículo (~190 s) entran holgados
     * en el $timeout de 420.
     */
    const SEGUNDOS_POR_TRAMO_MINIMO = 20;
    const SEGUNDOS_POR_TRAMO_MAXIMO = 90;

    /** Minutos que se recuerda que el failed() de una ficha de tramo ya corrió (ver failed()). */
    const MINUTOS_DE_MEMORIA_DEL_FAILED = 1440;

    /** @var int La asignación a procesar. */
    protected $run_id;

    /**
     * @var string Ficha de ESTE tramo: se genera al despachar (viaja en el payload, así que handle()
     * y failed() ven la misma) y se escribe en cada artículo que el tramo reclama.
     *
     * 🔴 Es lo que evita procesar dos veces un artículo. Sin ella, el failed() de un tramo que murió
     * hace una hora (el worker del shared lo levanta recién al vencer retry_after) devolvía a
     * pendiente TODOS los artículos "procesando" de la asignación, incluido el que en ese momento
     * procesaba un tramo vivo (por ejemplo después de una reanudación): otro tramo lo volvía a
     * reclamar y el artículo terminaba con dos imágenes.
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
        // GD necesita memoria para decodificar fotos grandes: best effort, como los jobs de importación.
        $this->asegurar_memoria_minima();

        $run = ImageAssignmentRun::find($this->run_id);

        if (is_null($run) || !$run->esta_activa()) {
            return;
        }

        $owner = User::find($run->user_id);

        if (is_null($owner)) {
            ImageAssignmentRunHelper::terminar($run, ImageAssignmentRun::STATUS_FALLIDA, 'El dueño de la asignación ya no existe.');

            return;
        }

        // La corrida se creó con Serper y alguien sacó la clave: no tiene sentido recorrer los
        // artículos fallando uno por uno. Se frena y se puede reanudar cuando vuelva la clave.
        if ($run->proveedor === ImageAssignmentRun::PROVEEDOR_SERPER && !ImageSearchProviderFactory::serper_configurado()) {
            ImageAssignmentRunHelper::terminar($run, ImageAssignmentRun::STATUS_FALLIDA, 'Se quitó la clave de Serper (SERPER_API_KEY) del servidor y la asignación no puede seguir. Cuando esté cargada de nuevo, se puede reanudar.');

            return;
        }

        /*
         * Todo el catálogo necesita la IA (sin ella el POST da 422 de entrada: todo terminaría "a
         * revisar" gastando búsquedas). Si la apagaron o le sacaron la clave con la corrida en
         * marcha, se corta con la causa real (sexta pasada, B2). Las de selección y del asistente
         * NO se cortan por esto: siguen, todo a revisar, como dice el contrato.
         */
        if ($run->origen === ImageAssignmentRun::ORIGEN_CATALOGO) {
            $ia = ImageAssignmentRunHelper::ia_disponible();

            if (!$ia['configurada']) {
                ImageAssignmentRunHelper::terminar(
                    $run,
                    ImageAssignmentRun::STATUS_FALLIDA,
                    $ia['motivo'].' Sin la IA, la búsqueda de todo el catálogo no sigue (todo quedaría a revisar gastando búsquedas); cuando esté configurada, se puede reanudar.'
                );

                return;
            }
        }

        $this->pasar_a_en_proceso($run);

        $motor = new ArticleImageAssignmentEngine($run, $owner);

        $inicio      = microtime(true);
        $presupuesto = $this->segundos_por_tramo();

        // Artículos que este tramo intentó (hayan salido bien o con error): es lo que mide el fin del
        // tramo. Contar solo los que salieron bien dejaba que una racha de artículos con error se
        // comiera el tiempo del tramo sin cortarlo nunca.
        $trabajados = 0;

        while (true) {
            // Fin del tramo: siempre después de al menos un artículo (si no, un presupuesto chico
            // re-encolaría para siempre sin avanzar).
            if ($trabajados > 0 && (microtime(true) - $inicio) >= $presupuesto) {
                // Siempre el job de producción (una subclase de prueba no se re-encola a sí misma).
                ProcessImageAssignmentRunJob::dispatch($run->id);

                return;
            }

            $run->refresh();

            // La detuvieron (o la cerró otro tramo): se corta sin tocar nada más. El aviso de salida
            // lo emitió quien la cambió de estado.
            if ($run->status !== ImageAssignmentRun::STATUS_EN_PROCESO) {
                return;
            }

            $item = ImageAssignmentItem::where('run_id', $run->id)
                ->where('status', ImageAssignmentItem::STATUS_PENDIENTE)
                ->orderBy('orden')
                ->orderBy('id')
                ->first();

            if (is_null($item)) {
                // Si otro tramo todavía está procesando un artículo, la cierra ese cuando termine.
                $en_curso = ImageAssignmentItem::where('run_id', $run->id)
                    ->where('status', ImageAssignmentItem::STATUS_PROCESANDO)
                    ->exists();

                if (!$en_curso) {
                    ImageAssignmentRunHelper::terminar($run, ImageAssignmentRun::STATUS_TERMINADA, null);
                }

                return;
            }

            // El cupo se mira DESPUÉS de saber que queda algo por hacer: si el último artículo gastó
            // la última búsqueda del día, la asignación terminó bien y no por falta de cupo.
            if ($run->aplica_tope_diario && (int) ImagenesAutomaticasHelper::cuota_de($owner)['disponibles'] <= 0) {
                $this->cortar_por_cupo($run);

                return;
            }

            // El techo de validaciones con IA, también DESPUÉS de saber que queda algo por hacer
            // (sexta pasada, B6): alcanzado, no se paga ni una búsqueda más que nadie va a evaluar.
            if ($motor->techo_de_ia_alcanzado()) {
                $this->cortar_por_techo_de_ia($run, $motor);

                return;
            }

            if (!$this->reclamar($item)) {
                continue;
            }

            $item->refresh();

            $trabajados++;

            // Ya falló las veces permitidas (volvió a pendiente por una reanudación): no se insiste.
            if ((int) $item->intentos > self::MAX_INTENTOS_POR_ARTICULO) {
                $this->descartar_por_error($item, 'Falló '.self::MAX_INTENTOS_POR_ARTICULO.' veces al procesarlo y se lo dejó de lado para no trabar la asignación. El detalle quedó en el registro del sistema.');
                continue;
            }

            try {
                $resultado = $motor->procesar($item);
            } catch (\Throwable $e) {
                Log::error('[ImagenesInteligentes] El motor falló con un artículo.', [
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
                $this->cortar_por_cupo($run);

                return;
            }

            // Un artículo que no intentó ninguna búsqueda (sin datos, borrado, ya tenía imagen, en
            // otra asignación) no dice nada del proveedor: no toca el contador (plan §13, B5).
            $intentadas = isset($resultado['busquedas_intentadas']) ? (int) $resultado['busquedas_intentadas'] : 1;

            if ($intentadas > 0 && $this->contar_error_de_proveedor($run, (bool) $resultado['error_de_proveedor']) >= self::MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR) {
                $ultimo_error = $this->ultimo_error_del_diagnostico($item->fresh());

                ImageAssignmentRunHelper::terminar(
                    $run,
                    ImageAssignmentRun::STATUS_FALLIDA,
                    'El proveedor de búsqueda falló en '.self::MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR.' artículos seguidos'
                        .($ultimo_error !== '' ? ' ('.$ultimo_error.')' : '')
                        .'. Se frenó para no seguir recorriendo artículos sin poder buscar; se puede reanudar cuando esté resuelto.'
                );

                return;
            }

            if ($this->contar_articulo_sin_ia($run, $resultado) >= self::MAX_ARTICULOS_SEGUIDOS_SIN_IA) {
                // Con la causa real (sexta pasada, B2): "Revisá la clave" era una suposición.
                $causa = isset($resultado['ia_error']) ? trim((string) $resultado['ia_error']) : '';

                ImageAssignmentRunHelper::terminar(
                    $run,
                    ImageAssignmentRun::STATUS_FALLIDA,
                    'La validación con IA no responde en '.self::MAX_ARTICULOS_SEGUIDOS_SIN_IA.' artículos seguidos'
                        .($causa !== '' ? ' (último error: '.$causa.')' : '')
                        .'. Se frenó para no gastar búsquedas; cuando esté resuelto, se puede reanudar.'
                );

                return;
            }
        }
    }

    /**
     * Niveles de transacción que NO son del tramo y que failed() no tiene que deshacer. En un worker
     * no hay ninguno; existe para que los tests (que corren adentro de su propia transacción) puedan
     * decir que la suya no es del tramo.
     *
     * @return int
     */
    protected function niveles_ajenos_de_transaccion()
    {
        return 0;
    }

    /**
     * El presupuesto de un tramo: config imagenes_inteligentes.segundos_por_tramo acotado a
     * [SEGUNDOS_POR_TRAMO_MINIMO, SEGUNDOS_POR_TRAMO_MAXIMO] (plan §13, B13): con 0 se re-encolaría
     * por cada artículo, y con horas volvería el job largo que el tramo existe para evitar.
     *
     * @return int
     */
    protected function segundos_por_tramo()
    {
        $segundos = (int) config('services.imagenes_inteligentes.segundos_por_tramo', 50);

        return max(self::SEGUNDOS_POR_TRAMO_MINIMO, min(self::SEGUNDOS_POR_TRAMO_MAXIMO, $segundos));
    }

    /**
     * Lleva, EN LA ASIGNACIÓN, la cuenta de artículos seguidos en los que se le pidió algo a la IA y
     * ninguna llamada respondió (plan §13, B2), y devuelve el valor actual. Un artículo en el que la
     * IA respondió la pone en cero; uno que no la necesitó no la toca.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  array $resultado  El de ArticleImageAssignmentEngine::procesar().
     * @return int
     */
    protected function contar_articulo_sin_ia(ImageAssignmentRun $run, array $resultado)
    {
        if (!empty($resultado['ia_sin_respuesta'])) {
            DB::table('image_assignment_runs')
                ->where('id', $run->id)
                ->update(['errores_ia_seguidos' => DB::raw('errores_ia_seguidos + 1')]);

            return (int) DB::table('image_assignment_runs')->where('id', $run->id)->value('errores_ia_seguidos');
        }

        if (!empty($resultado['ia_respondio']) && (int) $run->errores_ia_seguidos > 0) {
            DB::table('image_assignment_runs')->where('id', $run->id)->update(['errores_ia_seguidos' => 0]);
        }

        return 0;
    }

    /**
     * El tramo murió (una excepción fuera del manejo por artículo, un timeout, el worker que se
     * reinició). Con $tries = 1, Laravel llega acá.
     *
     * - El artículo que quedó `procesando` vuelve a `pendiente`, o queda `error_interno` si ya iba
     *   por su segundo intento (es el que hace caer el proceso: no se lo reintenta más).
     * - Se suma un fallo seguido y se re-encola la continuación; con MAX_FALLOS_CONSECUTIVOS
     *   seguidos, la asignación queda `fallida` (aviso de salida y registro visible en fallo).
     *
     * @param  \Throwable|null $e
     * @return void
     */
    public function failed($e)
    {
        try {
            /*
             * Si el tramo murió adentro de una transacción (plan §13, B8), la conexión puede seguir
             * con ella abierta: lo que se escriba acá quedaría adentro y se perdería con ella. Se
             * deshacen TODOS los niveles antes de escribir nada.
             */
            while (DB::transactionLevel() > $this->niveles_ajenos_de_transaccion()) {
                DB::rollBack();
            }
        } catch (\Throwable $error_de_rollback) {
            Log::warning('[ImagenesInteligentes] failed() no pudo deshacer una transacción abierta.', [
                'run_id' => $this->run_id,
                'error'  => $error_de_rollback->getMessage(),
            ]);
        }

        /*
         * 🔴 Una sola vez por ficha de tramo (sexta pasada, B8). Con la cola `database`, Job::fail()
         * borra la fila de `jobs` y recién después llama a este método; si el tramo murió adentro de
         * una transacción, ese borrado quedó ADENTRO de ella y el rollback de arriba lo deshace. La
         * fila vuelve a estar reservada y, al vencer retry_after (4200 s), otro worker la levanta y
         * la da por fallida de nuevo: este método corría dos veces para el mismo tramo (un fallo
         * seguido de más y un tramo extra despachado). La marca va DESPUÉS del rollback, así no se la
         * lleva la misma transacción aunque la caché sea la base.
         */
        if ($this->tramo !== '') {
            try {
                $primera_vez = Cache::add('imagenes-inteligentes:failed:'.$this->tramo, true, Carbon::now()->addMinutes(self::MINUTOS_DE_MEMORIA_DEL_FAILED));
            } catch (\Throwable $error_de_cache) {
                // Sin caché no hay memoria: mejor correrlo (lo de siempre) que no correrlo nunca.
                $primera_vez = true;
            }

            if (!$primera_vez) {
                Log::info('[ImagenesInteligentes] failed() ya había corrido para este tramo: no se repite.', [
                    'run_id' => $this->run_id,
                    'tramo'  => $this->tramo,
                ]);

                return;
            }
        }

        try {
            $run = ImageAssignmentRun::find($this->run_id);

            if (is_null($run) || !$run->esta_activa()) {
                return;
            }

            $mensaje = !is_null($e) && $e->getMessage() !== ''
                ? $e->getMessage()
                : 'El proceso se interrumpió sin dejar traza (probable falta de memoria, timeout o worker reiniciado).';

            // El detalle crudo, solo al log (plan §13, S2): al usuario le llega un texto genérico.
            Log::error('[ImagenesInteligentes] Se interrumpió un tramo.', [
                'run_id' => $this->run_id,
                'tramo'  => $this->tramo,
                'error'  => ImageServiceCallLogger::sin_claves($mensaje),
            ]);

            // Solo el artículo que reclamó ESTE tramo (ver $tramo): uno "procesando" con otra ficha
            // es de un tramo vivo y no se toca.
            $en_curso = ImageAssignmentItem::where('run_id', $run->id)
                ->where('status', ImageAssignmentItem::STATUS_PROCESANDO)
                ->where('tramo', $this->tramo)
                ->get();

            foreach ($en_curso as $item) {
                $this->devolver_o_descartar($item, $mensaje);
            }

            DB::table('image_assignment_runs')
                ->where('id', $run->id)
                ->update([
                    'fallos_consecutivos' => DB::raw('fallos_consecutivos + 1'),
                    'updated_at'          => Carbon::now(),
                ]);

            $run->refresh();

            if ((int) $run->fallos_consecutivos >= self::MAX_FALLOS_CONSECUTIVOS) {
                ImageAssignmentRunHelper::terminar(
                    $run,
                    ImageAssignmentRun::STATUS_FALLIDA,
                    'Se interrumpió '.self::MAX_FALLOS_CONSECUTIVOS.' veces seguidas. El detalle quedó en el registro del sistema; se puede reanudar.'
                );

                return;
            }

            // Siempre el job de producción (una subclase de prueba no se re-encola a sí misma).
            ProcessImageAssignmentRunJob::dispatch($run->id);
        } catch (\Throwable $error) {
            Log::error('[ImagenesInteligentes] failed() no pudo reencolar la asignación.', [
                'run_id' => $this->run_id,
                'error'  => $error->getMessage(),
            ]);
        }
    }

    /**
     * La asignación arranca: pendiente → en_proceso (atómico), y el registro visible deja de
     * decir "En espera del procesador".
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @return void
     */
    protected function pasar_a_en_proceso(ImageAssignmentRun $run)
    {
        if ($run->status === ImageAssignmentRun::STATUS_PENDIENTE) {
            $ahora = Carbon::now();

            DB::table('image_assignment_runs')
                ->where('id', $run->id)
                ->where('status', ImageAssignmentRun::STATUS_PENDIENTE)
                ->update([
                    'status'           => ImageAssignmentRun::STATUS_EN_PROCESO,
                    'started_at'       => is_null($run->started_at) ? $ahora : $run->started_at,
                    'last_progress_at' => $ahora,
                    'updated_at'       => $ahora,
                ]);

            $run->refresh();
        }

        // El primer avance saca al registro visible de `pendiente` (BackgroundProcessHelper).
        BackgroundProcessHelper::avanzar($run->background_process_id, null, [
            'etapa'            => 'Buscando imágenes',
            'forzar_broadcast' => false,
        ]);
    }

    /**
     * Reclama un artículo de forma atómica: solo uno de dos tramos concurrentes lo consigue.
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @return bool
     */
    protected function reclamar(ImageAssignmentItem $item)
    {
        $afectadas = DB::table('image_assignment_items')
            ->where('id', $item->id)
            ->where('status', ImageAssignmentItem::STATUS_PENDIENTE)
            ->update([
                'status'     => ImageAssignmentItem::STATUS_PROCESANDO,
                'intentos'   => DB::raw('intentos + 1'),
                'tramo'      => $this->tramo,
                'updated_at' => Carbon::now(),
            ]);

        return $afectadas > 0;
    }

    /**
     * Un artículo que hizo fallar el motor: vuelve a `pendiente` para otro intento, o queda
     * `no_asignada` / `error_interno` si ya iba por el último.
     *
     * @param  \App\Models\ImageAssignmentItem|null $item
     * @param  string $mensaje
     * @return void
     */
    protected function devolver_o_descartar($item, $mensaje)
    {
        if (is_null($item) || $item->status !== ImageAssignmentItem::STATUS_PROCESANDO) {
            return;
        }

        if ((int) $item->intentos >= self::MAX_INTENTOS_POR_ARTICULO) {
            // El mensaje crudo ya quedó en el log de quien llamó: al usuario, un texto genérico (S2).
            $this->descartar_por_error($item, 'Falló '.self::MAX_INTENTOS_POR_ARTICULO.' veces al procesarlo y se lo dejó de lado para no trabar la asignación. El detalle quedó en el registro del sistema.');

            return;
        }

        DB::table('image_assignment_items')
            ->where('id', $item->id)
            ->where('status', ImageAssignmentItem::STATUS_PROCESANDO)
            ->where('tramo', $this->tramo)
            ->update([
                'status'     => ImageAssignmentItem::STATUS_PENDIENTE,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Cierra un artículo como `no_asignada` / `error_interno` y lo cuenta como procesado.
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @param  string $detalle
     * @return void
     */
    protected function descartar_por_error(ImageAssignmentItem $item, $detalle)
    {
        $ahora = Carbon::now();

        DB::transaction(function () use ($item, $detalle, $ahora) {
            $afectadas = DB::table('image_assignment_items')
                ->where('id', $item->id)
                ->where('status', ImageAssignmentItem::STATUS_PROCESANDO)
                ->where('tramo', $this->tramo)
                ->update([
                    'status'         => ImageAssignmentItem::STATUS_NO_ASIGNADA,
                    'motivo'         => 'error_interno',
                    'motivo_detalle' => $detalle,
                    'procesado_at'   => $ahora,
                    'updated_at'     => $ahora,
                ]);

            if ($afectadas > 0) {
                DB::table('image_assignment_runs')
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
     * Se agotó el cupo diario: todo lo que sigue pendiente queda `sin_procesar` / `sin_cupo` y la
     * asignación termina diciéndolo (se puede volver a mandar mañana).
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @return void
     */
    protected function cortar_por_cupo(ImageAssignmentRun $run)
    {
        $ahora = Carbon::now();

        DB::table('image_assignment_items')
            ->where('run_id', $run->id)
            ->where('status', ImageAssignmentItem::STATUS_PENDIENTE)
            ->update([
                'status'         => ImageAssignmentItem::STATUS_SIN_PROCESAR,
                'motivo'         => 'sin_cupo',
                'motivo_detalle' => 'No se llegó a buscar: se agotó el cupo diario de búsquedas.',
                'updated_at'     => $ahora,
            ]);

        $sin_procesar = ImageAssignmentItem::where('run_id', $run->id)
            ->where('status', ImageAssignmentItem::STATUS_SIN_PROCESAR)
            ->count();

        // Sin nada sin procesar, el cupo se agotó en la búsqueda del ÚLTIMO artículo (el motor lo
        // avisó): no hay "N artículos" que decir.
        $motivo = $sin_procesar > 0
            ? 'Se agotó el cupo diario de búsquedas: '.($sin_procesar === 1 ? '1 artículo quedó' : $sin_procesar.' artículos quedaron').' sin procesar. Se pueden volver a mandar mañana.'
            : 'Se agotó el cupo diario de búsquedas con el último artículo: no se llegó a completar su búsqueda.';

        ImageAssignmentRunHelper::terminar($run, ImageAssignmentRun::STATUS_TERMINADA, $motivo, true);
    }

    /**
     * La asignación llegó a su techo de validaciones con IA (sexta pasada, B6): se corta como
     * `fallida`, reanudable, igual que los otros cortes automáticos (plan §13). Los artículos que
     * faltaban quedan pendientes; al reanudar, el techo vuelve a arrancar desde lo ya validado
     * (ImageAssignmentRunHelper::reanudar() mueve `validaciones_ia_base`).
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  \App\Services\ImageAssignment\ArticleImageAssignmentEngine $motor
     * @return void
     */
    protected function cortar_por_techo_de_ia(ImageAssignmentRun $run, ArticleImageAssignmentEngine $motor)
    {
        ImageAssignmentRunHelper::terminar(
            $run,
            ImageAssignmentRun::STATUS_FALLIDA,
            'Se alcanzó el techo de validaciones con IA de esta búsqueda ('.$motor->techo_de_ia().' consultas). Los artículos que faltaban quedaron pendientes: se puede reanudar.'
        );
    }

    /**
     * Avance del registro visible (con throttle de BackgroundProcessHelper) y reinicio del contador
     * de fallos seguidos: si un artículo terminó, el tramo está sano.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @return void
     */
    protected function registrar_avance(ImageAssignmentRun $run)
    {
        $run->refresh();

        if ((int) $run->fallos_consecutivos > 0) {
            DB::table('image_assignment_runs')->where('id', $run->id)->update(['fallos_consecutivos' => 0]);
        }

        $conteos = ImageAssignmentRunHelper::conteos_de([$run->id])[$run->id];

        BackgroundProcessHelper::incrementar($run->background_process_id, 1, [
            'etapa'     => 'Artículo '.min((int) $run->procesados, (int) $run->total_articulos).' de '.(int) $run->total_articulos,
            'resultado' => [
                'asignadas'    => $conteos['asignadas'],
                'a_revisar'    => $conteos['a_revisar'],
                'no_asignadas' => $conteos['no_asignadas'],
                'busquedas'    => (int) $run->busquedas,
            ],
        ]);
    }

    /**
     * Lleva la cuenta, EN LA ASIGNACIÓN, de los artículos seguidos en los que todas las búsquedas
     * fallaron por el proveedor, y devuelve el valor actual. Vive en la asignación y no en una
     * variable del tramo: con el proveedor colgado (15 s de timeout por búsqueda) entran uno o dos
     * artículos por tramo, y un contador local volvía a cero en cada tramo sin llegar nunca al corte.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  bool $fallo  El artículo recién procesado falló por el proveedor.
     * @return int
     */
    protected function contar_error_de_proveedor(ImageAssignmentRun $run, $fallo)
    {
        if ($fallo) {
            DB::table('image_assignment_runs')
                ->where('id', $run->id)
                ->update(['errores_proveedor_seguidos' => DB::raw('errores_proveedor_seguidos + 1')]);

            return (int) DB::table('image_assignment_runs')->where('id', $run->id)->value('errores_proveedor_seguidos');
        }

        if ((int) $run->errores_proveedor_seguidos > 0) {
            DB::table('image_assignment_runs')->where('id', $run->id)->update(['errores_proveedor_seguidos' => 0]);
        }

        return 0;
    }

    /**
     * El error del proveedor que quedó en el diagnóstico del artículo (para el motivo de la corrida
     * fallida). Nunca trae claves: los proveedores no las ponen en sus mensajes.
     *
     * @param  \App\Models\ImageAssignmentItem|null $item
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
        return rtrim(Str::limit(ImageServiceCallLogger::sin_claves($ultimo), 200, '…'), '. ');
    }
}
