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
 * Un corte corto de la base (misión medidas-vigia-de-corridas, 30/10/2026) no es una falla del artículo: el
 * tramo espera hasta 150 s a que vuelva, devuelve el artículo a la fila sin gastar el intento y sigue. Si no
 * vuelve, muere como siempre y lo levanta el vigía (comando medidas:reanudar-trabadas).
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
     * @var int 8 minutos. Un tramo son hasta SEGUNDOS_POR_TRAMO_MAXIMO (90 s) más el último artículo. El peor
     * artículo posible, con todo en contra a la vez: tres búsquedas de Serper de 15 s (45 s) + 90 s de páginas
     * (presupuesto de DimensionPageReader) + tres scrapes del respaldo de 20 s (60 s; en el plan de nombre es uno
     * solo) + tres llamadas a la IA de 45 s (135 s) = 330 s. La resolución de enlaces agrega como máximo 40 s ACUMULADOS por artículo: 370 s.
     * Con los 90 s del tramo antes de empezarlo son 460 s, por debajo de los 480 s del job. El
     * valor anterior quedaba justo en el límite, así que se subió a 480 para dejar margen. En la práctica un
     * artículo tarda ~12 s. Tiene que quedar por debajo del retry_after de las colas (config/queue.php: 4200 en
     * database, 9000 en redis).
     *
     * 🔴 Las esperas a la base (corte de la conexión) salen de ESTE mismo tiempo: no tienen holgura propia. Por eso
     * tienen un presupuesto acumulado por tramo (SEGUNDOS_MAXIMOS_DE_ESPERA_POR_TRAMO) y cada espera se acota a lo
     * que queda del timeout menos un margen (MARGEN_DE_TIMEOUT_PARA_ESPERAR). Una espera que pasara del timeout haría
     * que el worker matara el tramo en pleno sueño, sin pasar por failed().
     */
    public $timeout = 480;

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

    /**
     * Cuánto espera un tramo a que vuelva la base cuando se le corta la conexión (misión
     * medidas-vigia-de-corridas, 30/10/2026). La base del VPS la mata el OOM del kernel cada noche y tarda
     * de uno a tres minutos en volver: 150 s cubren casi todos los cortes. Más que eso ya no es un corte corto
     * y el tramo muere como siempre (lo reanuda el vigía, medidas:reanudar-trabadas). Es el tope de UNA espera;
     * el total de un tramo está acotado aparte (SEGUNDOS_MAXIMOS_DE_ESPERA_POR_TRAMO) y por el $timeout.
     */
    const SEGUNDOS_MAXIMOS_DE_ESPERA_DE_LA_BASE = 150;

    /**
     * Total de segundos que un mismo tramo puede pasar esperando a la base, sumando todas sus esperas (reclamar,
     * el motor y devolver el artículo). Con 480 s de timeout, el artículo en curso y el presupuesto de ~90 s del
     * tramo, más de ~200 s de espera no entran. Agotado, se relanza la excepción y la recoge failed() y el vigía.
     */
    const SEGUNDOS_MAXIMOS_DE_ESPERA_POR_TRAMO = 200;

    /** Segundos que una espera deja libres antes del $timeout del tramo, para poder cerrar bien si algo falla. */
    const MARGEN_DE_TIMEOUT_PARA_ESPERAR = 30;

    /** Cada cuántos segundos se reintenta la conexión mientras se espera a la base. */
    const SEGUNDOS_ENTRE_PINGS_A_LA_BASE = 10;

    /**
     * Veces que UN mismo artículo puede salir «gratis» (sin gastar intento) por un corte de la base dentro
     * de un tramo. Es la red de seguridad de un falso positivo: un artículo que por sí mismo hace caer la
     * conexión (un paquete que supera max_allowed_packet responde «server has gone away» con la base viva)
     * se reintentaría para siempre. Pasadas estas veces, se lo cuenta como una falla normal.
     */
    const MAX_CORTES_DE_BASE_SIN_COSTO_POR_ARTICULO = 3;

    /** @var int La corrida a procesar. */
    protected $run_id;

    /**
     * @var array id del artículo => veces que este tramo lo devolvió a la fila sin gastar intento por un
     * corte de la base (ver MAX_CORTES_DE_BASE_SIN_COSTO_POR_ARTICULO). Vive en el tramo: arranca vacío.
     */
    protected $cortes_de_base_por_articulo = [];

    /** @var float Segundos que este tramo ya pasó esperando a la base (se reinicia al empezar handle()). */
    protected $espera_acumulada = 0.0;

    /** @var float|null Instante (en segundos, ver ahora_en_segundos()) en que arrancó handle(); null = todavía no arrancó. */
    protected $inicio_del_tramo = null;

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
        $this->iniciar_presupuesto_de_espera();

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

        $motor = $this->crear_motor($run, $owner);

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

            // El reclamo también necesita la base: si se corta justo acá, se espera a que vuelva en vez de matar el tramo.
            if (!$this->reclamar_aguantando_un_corte($item)) {
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
                // Un corte de la base NO es culpa del artículo: se espera a que vuelva y el artículo reclamado vuelve
                // a la fila sin gastar el intento. Si la base no vuelve a tiempo, esto relanza la excepción y el tramo
                // muere como antes (failed() y el vigía hacen el resto).
                if ($this->aguantar_corte_de_base($e, $item)) {
                    continue;
                }

                Log::error('[MedidasMasivas] El motor falló con un artículo.', [
                    'run_id'     => $run->id,
                    'item_id'    => $item->id,
                    'article_id' => $item->article_id,
                    'intentos'   => (int) $item->intentos,
                    'error'      => $e->getMessage(),
                    'archivo'    => $e->getFile().':'.$e->getLine(),
                ]);

                // Si la base se corta justo mientras se registra el fallo, se espera y se reintenta una vez.
                $mensaje_del_motor = $e->getMessage();

                $this->aguantando_un_corte_de_base(function () use ($item, $mensaje_del_motor) {
                    $this->devolver_o_descartar($item->fresh(), $mensaje_del_motor);
                });

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
        // Si el tramo murió porque la base no estaba, se deja dicho claro: lo que viene en el log (esta
        // función misma puede no poder escribir nada) tiene que apuntar al vigía y no a un error del artículo.
        if (!is_null($e) && $this->es_corte_de_base($e)) {
            Log::warning('[MedidasMasivas] El tramo murió porque la base no estaba; lo reanuda el vigía (medidas:reanudar-trabadas).', [
                'run_id' => $this->run_id,
                'tramo'  => $this->tramo,
            ]);
        }

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

            // Si el tramo murió porque la base no estaba, el artículo no tuvo la culpa: vuelve a la fila sin gastar
            // el intento (si no, dos noches de corte lo darían por «venenoso»). El fallo seguido de más abajo se
            // suma igual: es el que frena una corrida que se interrumpe una y otra vez.
            $murio_por_la_base = !is_null($e) && $this->es_corte_de_base($e);

            foreach ($en_curso as $item) {
                if ($murio_por_la_base) {
                    $this->devolver_sin_gastar_intento($item);
                } else {
                    $this->devolver_o_descartar($item, $mensaje);
                }
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
     * Arma el motor de búsqueda del tramo. Existe como método aparte para que los tests puedan reemplazarlo
     * por uno que falla a pedido (la base cortada se simula sin tocar la conexión real).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  \App\Models\User $owner
     * @return \App\Services\DimensionSearch\ArticleDimensionSearchEngine
     */
    protected function crear_motor(DimensionSearchRun $run, User $owner)
    {
        return new ArticleDimensionSearchEngine($run, $owner);
    }

    /**
     * ¿La excepción es un corte de la conexión con la base de datos (la base se cayó, la reiniciaron o la
     * mató el sistema operativo)? Mira la excepción y toda su cadena de causas, y SOLO cuenta las de la base
     * (QueryException / PDOException): un «Connection timed out» de Serper o de una página no es la base y no
     * puede salir gratis, o un proveedor colgado haría reintentar el mismo artículo para siempre.
     *
     * Se reconoce por el código del driver (2002 no se pudo conectar, 2006 la base se fue, 2013 se perdió la
     * conexión en medio de la consulta, 1053 el servidor se está apagando) o por el texto del mensaje.
     *
     * 🔴 El texto se busca SOLO en el mensaje crudo del driver, nunca en el SQL: el mensaje de una QueryException
     * trae la consulta con sus valores (" (SQL: insert ... values (...))"), y entre esos valores puede haber un
     * «cURL error 28: Connection timed out» guardado en el diagnóstico del artículo. Un fallo del guardado que no
     * tiene nada que ver con la conexión pasaría por corte y el artículo se reprocesaría gratis.
     *
     * @param  \Throwable|null $e
     * @return bool
     */
    protected function es_corte_de_base($e)
    {
        // Códigos de error de MySQL que significan «no hay base del otro lado» (1053: apagándose/reiniciándose).
        $codigos = [1053, 2002, 2006, 2013];

        // Fragmentos del mensaje del driver que significan lo mismo (a veces el código no queda a mano).
        $frases = [
            'Connection refused',
            'server has gone away',
            'Lost connection',
            'Connection timed out',
            'server closed the connection',
            'no connection to the server',
            'Error while sending',
            'Connection reset by peer',
            'Server shutdown in progress',
        ];

        $profundidad = 0;

        while (!is_null($e) && $profundidad < 10) {
            if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
                // El código de la excepción (PDOException lo deja entero en los errores de conexión)...
                if (is_numeric($e->getCode()) && in_array((int) $e->getCode(), $codigos, true)) {
                    return true;
                }

                // ...o el del driver, que viaja en errorInfo[1] cuando el código es el SQLSTATE.
                $info = isset($e->errorInfo) && is_array($e->errorInfo) ? $e->errorInfo : [];

                if (isset($info[1]) && in_array((int) $info[1], $codigos, true)) {
                    return true;
                }

                // El mensaje del driver, sin la consulta ni sus valores (que la QueryException pega después).
                $mensaje = (string) $e->getMessage();
                $corte   = strpos($mensaje, ' (SQL:');

                if ($corte !== false) {
                    $mensaje = substr($mensaje, 0, $corte);
                }

                foreach ($frases as $frase) {
                    if (stripos($mensaje, $frase) !== false) {
                        return true;
                    }
                }
            }

            $e = $e->getPrevious();
            $profundidad++;
        }

        return false;
    }

    /**
     * Arranca el presupuesto de espera del tramo: sin espera acumulada y con el reloj en el instante actual.
     * Se llama al empezar handle().
     *
     * @return void
     */
    protected function iniciar_presupuesto_de_espera()
    {
        $this->espera_acumulada = 0.0;
        $this->inicio_del_tramo = $this->ahora_en_segundos();
    }

    /**
     * El instante actual, en segundos con decimales. Existe aparte para que los tests simulen el paso del tiempo
     * sin dormir de verdad.
     *
     * @return float
     */
    protected function ahora_en_segundos()
    {
        return microtime(true);
    }

    /**
     * Cuántos segundos puede esperar AHORA el tramo a la base: lo menor entre el tope de una espera, lo que queda
     * del presupuesto acumulado del tramo y lo que queda del $timeout menos un margen (contando lo que ya
     * transcurrió desde que arrancó handle()).
     *
     * @return int  0 o menos = no se puede esperar más.
     */
    protected function segundos_de_espera_permitidos()
    {
        $transcurrido = is_null($this->inicio_del_tramo) ? 0.0 : $this->ahora_en_segundos() - $this->inicio_del_tramo;

        $del_presupuesto = self::SEGUNDOS_MAXIMOS_DE_ESPERA_POR_TRAMO - $this->espera_acumulada;
        $del_timeout     = (int) $this->timeout - self::MARGEN_DE_TIMEOUT_PARA_ESPERAR - $transcurrido;

        return (int) floor(min(self::SEGUNDOS_MAXIMOS_DE_ESPERA_DE_LA_BASE, $del_presupuesto, $del_timeout));
    }

    /**
     * Espera a la base dentro del presupuesto del tramo (ver segundos_de_espera_permitidos()) y anota lo que
     * tardó. Agotado el presupuesto no espera nada y contesta false.
     *
     * @return bool  true si la base respondió; false si no volvió a tiempo o no quedaba presupuesto.
     */
    protected function esperar_a_la_base_dentro_del_presupuesto()
    {
        $permitidos = $this->segundos_de_espera_permitidos();

        if ($permitidos <= 0) {
            Log::error('[MedidasMasivas] Se agotó el presupuesto de espera a la base de este tramo.', [
                'run_id'           => $this->run_id,
                'espera_acumulada' => round($this->espera_acumulada),
            ]);

            return false;
        }

        $inicio = $this->ahora_en_segundos();
        $volvio = $this->esperar_a_la_base($permitidos);

        $this->espera_acumulada += max(0.0, $this->ahora_en_segundos() - $inicio);

        return $volvio;
    }

    /**
     * Espera a que la base vuelva: reconecta y hace un ping (`select 1`) cada SEGUNDOS_ENTRE_PINGS_A_LA_BASE
     * segundos, hasta `$maximo` segundos. Es `protected` para que los tests lo reemplacen (reconectar adentro de
     * la transacción de un test la rompería, y nadie quiere dormir 150 s en una suite).
     *
     * @param  int $maximo  Segundos máximos de esta espera (el que calcula segundos_de_espera_permitidos()).
     * @return bool  true si la base respondió; false si pasó el tiempo y sigue caída.
     */
    protected function esperar_a_la_base($maximo = self::SEGUNDOS_MAXIMOS_DE_ESPERA_DE_LA_BASE)
    {
        $inicio = microtime(true);

        while (true) {
            try {
                DB::reconnect();
                DB::select('select 1');

                return true;
            } catch (\Throwable $error) {
                $restante = $maximo - (microtime(true) - $inicio);

                if ($restante <= 0) {
                    return false;
                }

                // No se duerme más de lo que queda de la espera.
                sleep((int) max(1, min(self::SEGUNDOS_ENTRE_PINGS_A_LA_BASE, ceil($restante))));
            }
        }
    }

    /**
     * Un artículo cuyo procesamiento reventó con un corte de la base: espera a que vuelva y, si vuelve, lo
     * devuelve a `pendiente` SIN gastar el intento (el corte no fue culpa del artículo).
     *
     * @param  \Throwable $e     Lo que reventó adentro del motor.
     * @param  \App\Models\DimensionSearchItem $item  El artículo que este tramo tenía reclamado.
     * @return bool  true = era un corte, la base volvió y el artículo está de vuelta en la fila (hay que seguir
     *               con el próximo); false = no era un corte (o ya salió gratis demasiadas veces): se maneja
     *               como cualquier otra falla. Si era un corte y la base NO volvió, relanza la excepción.
     * @throws \Throwable
     */
    protected function aguantar_corte_de_base(\Throwable $e, DimensionSearchItem $item)
    {
        if (!$this->es_corte_de_base($e)) {
            return false;
        }

        $veces = isset($this->cortes_de_base_por_articulo[$item->id]) ? $this->cortes_de_base_por_articulo[$item->id] : 0;

        // Falsa alarma: un artículo que ya salió gratis varias veces es el que rompe la conexión, no la base.
        if ($veces >= self::MAX_CORTES_DE_BASE_SIN_COSTO_POR_ARTICULO) {
            return false;
        }

        Log::warning('[MedidasMasivas] Se cortó la conexión con la base en medio de un artículo: se espera a que vuelva.', [
            'run_id'  => $this->run_id,
            'item_id' => $item->id,
            'error'   => $e->getMessage(),
        ]);

        if (!$this->esperar_a_la_base_dentro_del_presupuesto()) {
            Log::error('[MedidasMasivas] La base no volvió a tiempo: el tramo se interrumpe y lo levanta el vigía (medidas:reanudar-trabadas).', [
                'run_id' => $this->run_id,
                'espera_acumulada' => round($this->espera_acumulada),
            ]);

            throw $e;
        }

        $this->cortes_de_base_por_articulo[$item->id] = $veces + 1;

        // La escritura que devuelve el artículo también puede toparse con otro corte: se espera y se reintenta una vez.
        $this->aguantando_un_corte_de_base(function () use ($item) {
            $this->devolver_sin_gastar_intento($item);
        });

        Log::info('[MedidasMasivas] La base volvió: el artículo quedó en la fila sin gastar el intento y el tramo sigue.', [
            'run_id'  => $this->run_id,
            'item_id' => $item->id,
        ]);

        return true;
    }

    /**
     * Corre una operación contra la base y, si se corta la conexión justo ahí, espera a que vuelva y la repite
     * UNA vez. Sirve solo para operaciones que se pueden repetir sin efecto doble.
     *
     * @param  \Closure $operacion
     * @return mixed  Lo que devuelva la operación.
     * @throws \Throwable Si falla por otra causa, o si la base no vuelve, o si vuelve a cortarse al repetir.
     */
    protected function aguantando_un_corte_de_base(\Closure $operacion)
    {
        try {
            return $operacion();
        } catch (\Throwable $e) {
            if (!$this->es_corte_de_base($e)) {
                throw $e;
            }

            Log::warning('[MedidasMasivas] Se cortó la conexión con la base: se espera a que vuelva para repetir la operación.', [
                'run_id' => $this->run_id,
                'error'  => $e->getMessage(),
            ]);

            if (!$this->esperar_a_la_base_dentro_del_presupuesto()) {
                throw $e;
            }

            return $operacion();
        }
    }

    /**
     * Reclama un artículo (ver reclamar()) aguantando un corte de la base.
     *
     * Si la conexión se pierde DESPUÉS de que la base aplicó el UPDATE, la respuesta nunca llega y el reclamo
     * parece haber fallado: al volver se mira de quién quedó el artículo antes de repetirlo, para no dejarlo
     * «procesando» a nombre de este tramo sin que nadie lo trabaje.
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @return bool  true si este tramo se quedó con el artículo.
     */
    protected function reclamar_aguantando_un_corte(DimensionSearchItem $item)
    {
        try {
            return $this->reclamar($item);
        } catch (\Throwable $e) {
            if (!$this->es_corte_de_base($e)) {
                throw $e;
            }

            Log::warning('[MedidasMasivas] Se cortó la conexión con la base al reclamar un artículo: se espera a que vuelva.', [
                'run_id'  => $this->run_id,
                'item_id' => $item->id,
                'error'   => $e->getMessage(),
            ]);

            if (!$this->esperar_a_la_base_dentro_del_presupuesto()) {
                throw $e;
            }

            // ¿Llegó a aplicarse el reclamo antes del corte?
            $ya_es_de_este_tramo = DB::table('dimension_search_items')
                ->where('id', $item->id)
                ->where('status', DimensionSearchItem::STATUS_PROCESANDO)
                ->where('tramo', $this->tramo)
                ->exists();

            return $ya_es_de_este_tramo ? true : $this->reclamar($item);
        }
    }

    /**
     * Devuelve a `pendiente` el artículo que este tramo tenía reclamado, SIN gastar el intento: el reclamo
     * había sumado uno (`intentos`) y se lo resta, y se suelta la ficha del tramo. Solo toca al que sigue
     * `procesando` con la ficha de este tramo.
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @return void
     */
    protected function devolver_sin_gastar_intento(DimensionSearchItem $item)
    {
        DB::table('dimension_search_items')
            ->where('id', $item->id)
            ->where('status', DimensionSearchItem::STATUS_PROCESANDO)
            ->where('tramo', $this->tramo)
            ->update([
                'status'     => DimensionSearchItem::STATUS_PENDIENTE,
                // `intentos` es unsigned: nunca se baja de cero.
                'intentos'   => DB::raw('CASE WHEN intentos > 0 THEN intentos - 1 ELSE 0 END'),
                'tramo'      => null,
                'updated_at' => Carbon::now(),
            ]);
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
