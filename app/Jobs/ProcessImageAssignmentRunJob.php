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
use App\Services\ImageSearch\ImageSearchProviderFactory;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    /** @var int 5 minutos: un tramo son ~50 s más el último artículo (a lo sumo un par de minutos). */
    public $timeout = 300;

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

    /** @var int La asignación a procesar. */
    protected $run_id;

    /**
     * @param int $run_id
     */
    public function __construct($run_id)
    {
        $this->run_id = (int) $run_id;

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

        $this->pasar_a_en_proceso($run);

        $motor = new ArticleImageAssignmentEngine($run, $owner);

        $inicio      = microtime(true);
        $presupuesto = max(0, (int) config('services.imagenes_inteligentes.segundos_por_tramo', 50));

        // Artículos que este tramo intentó (hayan salido bien o con error): es lo que mide el fin del
        // tramo. Contar solo los que salieron bien dejaba que una racha de artículos con error se
        // comiera el tiempo del tramo sin cortarlo nunca.
        $trabajados = 0;

        $articulos_con_error_de_proveedor = 0;
        $ultimo_error_de_proveedor        = '';

        while (true) {
            // Fin del tramo: siempre después de al menos un artículo (si no, un presupuesto chico
            // re-encolaría para siempre sin avanzar).
            if ($trabajados > 0 && (microtime(true) - $inicio) >= $presupuesto) {
                self::dispatch($run->id);

                return;
            }

            $run->refresh();

            // La detuvieron (o la cerró otro tramo): se corta sin tocar nada más. El aviso de salida
            // lo emitió quien la cambió de estado.
            if ($run->status !== ImageAssignmentRun::STATUS_EN_PROCESO) {
                return;
            }

            if ($run->aplica_tope_diario && (int) ImagenesAutomaticasHelper::cuota_de($owner)['disponibles'] <= 0) {
                $this->cortar_por_cupo($run);

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

            if (!$this->reclamar($item)) {
                continue;
            }

            $item->refresh();

            $trabajados++;

            // Ya falló las veces permitidas (volvió a pendiente por una reanudación): no se insiste.
            if ((int) $item->intentos > self::MAX_INTENTOS_POR_ARTICULO) {
                $this->descartar_por_error($item, 'Falló '.self::MAX_INTENTOS_POR_ARTICULO.' veces al procesarlo y se lo dejó de lado para no trabar la asignación.');
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

            if ($resultado['error_de_proveedor']) {
                $articulos_con_error_de_proveedor++;
                $ultimo_error_de_proveedor = $this->ultimo_error_del_diagnostico($item->fresh());
            } else {
                $articulos_con_error_de_proveedor = 0;
            }

            if ($articulos_con_error_de_proveedor >= self::MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR) {
                ImageAssignmentRunHelper::terminar(
                    $run,
                    ImageAssignmentRun::STATUS_FALLIDA,
                    'El proveedor de búsqueda falló en '.self::MAX_ARTICULOS_SEGUIDOS_CON_ERROR_DE_PROVEEDOR.' artículos seguidos'
                        .($ultimo_error_de_proveedor !== '' ? ' ('.$ultimo_error_de_proveedor.')' : '')
                        .'. Se frenó para no seguir recorriendo artículos sin poder buscar; se puede reanudar cuando esté resuelto.'
                );

                return;
            }
        }
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
            $run = ImageAssignmentRun::find($this->run_id);

            if (is_null($run) || !$run->esta_activa()) {
                return;
            }

            $mensaje = !is_null($e) && $e->getMessage() !== ''
                ? $e->getMessage()
                : 'El proceso se interrumpió sin dejar traza (probable falta de memoria, timeout o worker reiniciado).';

            $en_curso = ImageAssignmentItem::where('run_id', $run->id)
                ->where('status', ImageAssignmentItem::STATUS_PROCESANDO)
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
                    'Se interrumpió '.self::MAX_FALLOS_CONSECUTIVOS.' veces seguidas. Último error: '.Str::limit($mensaje, 300, '…')
                );

                return;
            }

            self::dispatch($run->id);
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
            $this->descartar_por_error($item, 'Falló '.self::MAX_INTENTOS_POR_ARTICULO.' veces al procesarlo y se lo dejó de lado para no trabar la asignación. Último error: '.Str::limit((string) $mensaje, 200, '…'));

            return;
        }

        DB::table('image_assignment_items')
            ->where('id', $item->id)
            ->where('status', ImageAssignmentItem::STATUS_PROCESANDO)
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

        ImageAssignmentRunHelper::terminar(
            $run,
            ImageAssignmentRun::STATUS_TERMINADA,
            'Se agotó el cupo diario de búsquedas: '.($sin_procesar === 1 ? '1 artículo quedó' : $sin_procesar.' artículos quedaron').' sin procesar. Se pueden volver a mandar mañana.',
            true
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

        return Str::limit($ultimo, 200, '…');
    }
}
