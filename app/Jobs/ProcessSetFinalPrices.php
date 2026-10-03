<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\PriceUpdateRunHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\PriceUpdateRun;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\FinalizeSetFinalPrices;

/**
 * El productor del recálculo de precios: abre la corrida, reparte los artículos del alcance en
 * lotes (un ProcessChunkSetFinalPrices por lote) y encola el finalizador.
 *
 * Misión recalculo-precios-motor-rapido (28/9/2026):
 *
 *  - Los ids ya no se recorren con ->chunk(), que pagina con OFFSET: en Servian,
 *    `provider_id = ? ... OFFSET 20000 LIMIT 100` tardaba 112 ms por página y cada página leía y
 *    descartaba todas las anteriores. Todo el catálogo y el dólar global van por keyset
 *    (`id > último ORDER BY id LIMIT n`); un alcance por columna, con una sola consulta sin
 *    ORDER BY y los ids ordenados en PHP (ver repartir_en_lotes()).
 *  - Toda consulta lleva `user_id`: así el alcance por proveedor usa el índice
 *    articles_user_provider_status_idx (user_id, provider_id, status, deleted_at), y un id de otra
 *    cuenta nunca entra al recálculo de ésta.
 *  - Lotes de RecalculoDePreciosEnLote::tamanio_de_lote() (1.000) en vez de 100.
 *  - El alcance del dólar global (unión de costo en dólares, listas en dólares y cotización cruzada) sin la
 *    subconsulta correlacionada por fila del orWhereHas; y el nuevo alcance "dólar del
 *    proveedor" (columna + from_dolar = true: solo los artículos de esa columna con costo en
 *    dólares, los únicos que leen el dólar del proveedor en ArticleHelper::cotizar()).
 */
class ProcessSetFinalPrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Columnas por las que se puede acotar el recálculo (from_model_id). Es una lista blanca: el
     * nombre entra a la consulta, y una columna fuera de la lista es un error de programación que
     * se informa en vez de recalcular lo que no era.
     *
     * @var array
     */
    const COLUMNAS_DE_ALCANCE = [
        'provider_id',
        'category_id',
        'sub_category_id',
        'provider_price_list_id',
    ];

    /**
     * El id fijo de la moneda dólar en article_price_type_monedas: el mismo `$usd_id = 2` que usa
     * ArticlePriceTypeMonedaHelper para decidir qué entradas se calculan con el dólar de la cuenta.
     *
     * @var int
     */
    const MONEDA_DOLAR = 2;

    /**
     * Segundos. Repartir los lotes es solo leer ids y encolar: con keyset, un catálogo de 574.000
     * artículos son 575 consultas de ids. 1.200 es holgado a propósito, y tiene que estar en el
     * job: el worker de la cola 'excel' del shared corre con el --timeout por defecto (60 s).
     *
     * @var int
     */
    public $timeout = 1200;

    /**
     * 🔴 Un solo intento, a propósito. Un reintento del productor abriría OTRA corrida y volvería
     * a encolar todos los lotes, mientras la primera quedaría abierta hasta que su finalizador la
     * cierre en error por el tope de reloj: dos corridas y un aviso de error por un solo
     * recálculo. Si el productor muere, el finalizador que se encola antes del reparto es la red.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Create a new job instance.
     *
     * @return void
     */

    public $user_id, $from_model_id, $model_id, $from_dolar, $origen, $origen_detalle;

    /**
     * Id del registro visible (background_processes) abierto en `pendiente` al encolar.
     *
     * Se abre en el constructor y no en handle() a propósito: `dispatch()` construye el job en
     * el request, así que el usuario ve "Recálculo de precios · en espera" en el momento en que
     * guardó el proveedor, y no cuando el worker lo levanta —en el shared hosting eso es hasta
     * un minuto después—. Viaja serializado con el job; handle() se lo pasa a
     * PriceUpdateRunHelper::abrir(), que lo retoma y le cuelga la corrida. Es público y con
     * default por compatibilidad con los jobs ya encolados antes de este cambio.
     *
     * @var int|null
     */
    public $background_process_id = null;

    /**
     * La PERSONA que disparó el recálculo (dueño o empleado), resuelta al encolar en
     * anunciar_en_pendiente(), que es el único momento en que hay sesión (seguimiento del
     * 29/9/2026). Viaja a cada lote (ProcessChunkSetFinalPrices) y de ahí al motor, que la pone
     * como employee_id de los price_changes.
     *
     * Por qué: en el worker no hay sesión, así que todo recálculo encolado dejaba employee_id =
     * config('app.USER_ID') (el motor resuelve con UserHelper::userId(false)). Con esta misión,
     * el recálculo por el guardado de una categoría y el de los recargos de una lista, que antes
     * corrían en el request y registraban a la persona logueada, pasaron a la cola: sin esto
     * perdían ese dato.
     *
     * null sin sesión (la cotización automática del dólar, un comando): el motor resuelve como
     * siempre. Pública y con default null por compatibilidad con los jobs ya encolados antes de
     * este cambio, igual que $background_process_id.
     *
     * @var int|null
     */
    public $auth_user_id = null;

    /**
     * $origen y $origen_detalle van AL FINAL de la firma y con default a propósito: así los
     * llamados que ya existen siguen andando sin tocarlos, y los que quieran contar por qué
     * se recalcularon los precios lo agregan de a uno.
     *
     * Alcance del recálculo según los argumentos (ver alcance()):
     *  - $from_model_id (una de COLUMNAS_DE_ALCANCE) + $model_id: los artículos del dueño con esa
     *    columna en ese valor; y si además $from_dolar es true, solo los que tienen el costo en
     *    dólares (el cambio de dólar de un proveedor).
     *  - sin $from_model_id y $from_dolar true: los del dueño con costo en dólares, o con alguna
     *    lista por moneda en dólares o que cotiza desde otra moneda (el cambio del dólar global).
     *  - sin nada: todo el catálogo del dueño.
     */
    public function __construct($user_id, $from_model_id = null, $model_id = null, $from_dolar = false, $origen = 'otro', $origen_detalle = null)
    {

        $this->user_id = $user_id;
        $this->from_model_id = $from_model_id;
        $this->model_id = $model_id;
        $this->from_dolar = $from_dolar;
        $this->origen = $origen;
        $this->origen_detalle = $origen_detalle;

        /*
         * En el shared hosting va a la cola 'excel', como los lotes y el finalizador (ver
         * ProcessChunkSetFinalPrices::__construct): el recálculo es un job pesado y no tiene que
         * retener al worker del asistente. En el VPS, null: 'default', la que consume supervisor.
         */
        $this->queue = config('app.VPS') ? null : 'excel';

        $this->anunciar_en_pendiente();
    }

    /**
     * Abre el registro visible en `pendiente` (ver $background_process_id). Nunca tira: el
     * helper atrapa todo, y si el registro no se pudo crear el recálculo sale igual y se
     * anuncia recién cuando arranca.
     *
     * `auth_user_id` se resuelve acá porque es el único momento en que hay sesión: en el
     * worker `Auth::check()` da false y queda null. `UserHelper::userId(false)` devuelve la
     * PERSONA (dueño o empleado), no el dueño. Se guarda en $this->auth_user_id ANTES de abrir
     * el registro, así viaja a los lotes aunque el registro no se pueda crear.
     *
     * @return void
     */
    protected function anunciar_en_pendiente()
    {
        try {
            $auth_user_id = Auth::check() ? UserHelper::userId(false) : null;

            $this->auth_user_id = is_null($auth_user_id) ? null : (int) $auth_user_id;

            $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'recalculo_precios', 'Recálculo de precios', [
                'auth_user_id' => $auth_user_id,
                'unidad'       => 'lotes',
                'status'       => 'pendiente',
                'etapa'        => 'En espera del procesador',
                'detalle'      => $this->detalle_del_origen(),
            ]);

            $this->background_process_id = is_null($proceso) ? null : (int) $proceso->id;
        } catch (\Throwable $e) {
            Log::warning('ProcessSetFinalPrices: no se pudo anunciar el recálculo (sale igual): ' . $e->getMessage());
        }
    }

    /**
     * El mismo texto que después arma PriceUpdateRunHelper::detalle_del_origen(), pero antes
     * de que exista la corrida: se instancia un PriceUpdateRun sin guardar solo para leer su
     * accessor `origen_texto`, que es donde vive la traducción del origen.
     *
     * @return string
     */
    protected function detalle_del_origen()
    {
        $run = new PriceUpdateRun(['origen' => $this->origen]);
        $detalle = (string) $run->origen_texto;

        if (!is_null($this->origen_detalle) && trim((string) $this->origen_detalle) !== '') {
            $detalle .= ' · ' . trim((string) $this->origen_detalle);
        }

        return $detalle;
    }


    public function handle()
    {
        Log::info('ProcessSetFinalPrices');

        /*
         * Fuera del try a propósito: si la excepción salta después de abrir la corrida, el
         * catch tiene que poder cerrarla y decir por qué. Antes se notificaba el error sin
         * el id, así que la corrida quedaba en_proceso y el usuario recibía un aviso que no
         * apuntaba a nada.
         */
        $run = null;

        try {

            /*
             * Su propia corrida, siempre. Ver PriceUpdateRunHelper::abrir(): reusar la
             * corrida abierta del usuario hacía que dos productores compartieran contador y
             * flag, y el que terminaba primero cerraba por el otro con números parciales.
             */
            $run = PriceUpdateRunHelper::abrir($this->user_id, $this->origen, $this->origen_detalle, $this->background_process_id);

            /*
             * 🔴 Un finalizador ACA, antes del reparto, además del de siempre que va al final.
             *
             * Es la red que le queda a la corrida si este job se muere en el medio del
             * reparto: ahí no hay catch que valga —el worker mata el proceso— y failed()
             * tampoco sirve, porque Laravel lo llama sobre una instancia deserializada del
             * payload original y el id de la corrida que se abrió recién no existe en ella.
             * Encolado desde el principio, el tope de reloj cierra la corrida y avisa igual.
             *
             * ⚠️ Con una cola que ejecuta inline no es una red: este finalizador corre acá
             * mismo, vuelve porque todavía no hay nada procesado, y no queda ningún worker
             * que lo retome. Es el precio de que ahí un re-despacho sea una recursión (ver
             * FinalizeSetFinalPrices::la_cola_corre_inline). En este proyecto la cola es
             * `database` con su worker, así que la red es real; en una instalación sin worker
             * una muerte dura del productor deja la corrida abierta y sin aviso.
             *
             * No cierra de más: el finalizador exige chunks_encolados, que este reparto pone
             * recién al final, así que mientras el productor viva sólo se re-despacha.
             */
            dispatch(new FinalizeSetFinalPrices($this->user_id, $run->id));

            /*
             * El alcance se resuelve (y se valida) DESPUÉS de abrir la corrida y de encolar el
             * primer finalizador: si es inválido, el catch de abajo cierra ESTA corrida en error
             * y avisa, en vez de fallar sin dejar rastro.
             */
            $alcance = $this->alcance();

            Log::info('ProcessSetFinalPrices: alcance ' . $alcance['descripcion']);

            /** Chunks despachados por este job, que es el único productor de esta corrida. */
            $chunks_despachados = 0;

            /*
             * Cada lote lleva a la persona que disparó el recálculo (ver $auth_user_id). Un job
             * encolado antes de ese cambio no la trae: queda null y el motor resuelve como siempre.
             */
            $this->repartir_en_lotes($alcance, RecalculoDePreciosEnLote::tamanio_de_lote(), function (array $ids) use ($run, &$chunks_despachados) {
                dispatch(new ProcessChunkSetFinalPrices($ids, $this->user_id, $run->id, $this->auth_user_id));
                $chunks_despachados++;
            });

            if ($chunks_despachados > 0) {
                DB::table('price_update_runs')
                    ->where('id', $run->id)
                    ->update(['total_chunks' => (int) $chunks_despachados]);

                /*
                 * Recién acá el registro visible conoce su total: la barra pasa de
                 * indeterminada a "X de N lotes". Se escribe solo el total, no los
                 * procesados —esos los suman los chunks con incrementar(), y con una cola
                 * inline ya pueden haber sumado todos antes de llegar a esta línea.
                 */
                BackgroundProcessHelper::avanzar(BackgroundProcessHelper::por_referencia($run), null, [
                    'total' => (int) $chunks_despachados,
                    'etapa' => 'Recalculando',
                ]);
            } else {
                /*
                 * No hay un solo artículo que recalcular. Se cierra acá y se notifica igual:
                 * un recálculo que no encontró nada es información, no silencio (decisión de
                 * Lucas). Sin esto la corrida quedaría abierta para siempre.
                 *
                 * Con la misma excepción que el finalizador (29/9/2026): una categoría o
                 * subcategoría sin artículos, guardada, no avisa; cerrar_sin_articulos() la
                 * deja en 'sin_cambios' y la píldora lo muestra (ver
                 * FinalizeSetFinalPrices::ORIGENES_QUE_NO_AVISAN_SIN_CAMBIOS).
                 */
                /*
                 * Combos calculados (F5): esta rama NO recalcula combos, y es a propósito. No hay un
                 * solo artículo en el alcance de la corrida, o sea que no se escribió ningún precio
                 * ni costo: los combos calculados no tienen nada nuevo que reflejar. (Las otras dos
                 * salidas de FinalizeSetFinalPrices, el cierre normal y la corrida dada por perdida,
                 * sí encolan el recálculo.)
                 */
                PriceUpdateRunHelper::cerrar_sin_articulos($run);

                if (FinalizeSetFinalPrices::corresponde_avisar_el_cierre($run)) {
                    SetFinalPricesNotificationHelper::notify_prices_updated($this->user_id, $run);
                }

                return;
            }

            /*
             * Recién acá se declara que ya no se despachan más chunks. El finalizador exige
             * este flag ADEMAS del conteo: sin él cerraría la corrida apenas los primeros
             * chunks terminen, mientras este mismo reparto todavía está despachando el resto.
             */
            DB::table('price_update_runs')
                ->where('id', $run->id)
                ->update(['chunks_encolados' => 1]);

            /*
             * 🔴 Acá ya NO se notifica. El aviso "Precios actualizados" se mandaba en este
             * mismo punto, o sea cuando el proceso RECIEN ARRANCABA: el usuario lo leía como
             * "listo" con el catálogo todavía sin recalcular. Ahora notifica el finalizador,
             * cuando los números son ciertos. Consecuencia aceptada: el aviso llega más
             * tarde que antes, minutos en un catálogo grande.
             *
             * Este segundo finalizador es el que cierra en el camino normal, y con una cola
             * que ejecuta inline es el único que puede: el de arriba corrió cuando todavía no
             * había un solo chunk procesado.
             */
            dispatch(new FinalizeSetFinalPrices($this->user_id, $run->id));

        } catch (\Throwable $e) {
            /*
             * \Throwable y no \Exception: en PHP 7 un TypeError o cualquier otro \Error no es
             * una Exception, así que con el catch anterior se escapaba sin avisarle a nadie.
             */
            Log::error("Error en ProcessSetFinalPrices: " . $e->getMessage());

            SetFinalPricesNotificationHelper::notify_prices_update_failed(
                $this->user_id,
                is_null($run) ? null : $run->id,
                'No se pudo iniciar el recálculo de precios: ' . $e->getMessage()
            );
        }
    }

    /**
     * Qué artículos entran a este recálculo, según los argumentos del job.
     *
     * @return array ['tipo' => 'columna'|'dolar_global'|'todo', 'columna' => string|null,
     *                'solo_en_dolares' => bool, 'descripcion' => string]
     */
    protected function alcance()
    {
        if (!is_null($this->from_model_id)) {

            if (!in_array($this->from_model_id, self::COLUMNAS_DE_ALCANCE, true)) {
                throw new \InvalidArgumentException('ProcessSetFinalPrices: "' . $this->from_model_id . '" no es una columna de alcance del recálculo (se admiten: ' . implode(', ', self::COLUMNAS_DE_ALCANCE) . ').');
            }

            /*
             * Columna + from_dolar: solo los artículos de ese alcance con el costo en dólares.
             * Es el cambio del dólar de un proveedor: ese dólar solo lo lee
             * ArticleHelper::cotizar(), y solo para los artículos con cost_in_dollars (las listas
             * por moneda cotizan con el dólar de la cuenta, no con el del proveedor).
             */
            $solo_en_dolares = !is_null($this->from_dolar) && (bool) $this->from_dolar;

            return [
                'tipo'            => 'columna',
                'columna'         => $this->from_model_id,
                'solo_en_dolares' => $solo_en_dolares,
                'descripcion'     => $this->from_model_id . ' = ' . $this->model_id . ($solo_en_dolares ? ', solo con costo en dólares' : ''),
            ];
        }

        if (!is_null($this->from_dolar) && $this->from_dolar) {
            return [
                'tipo'            => 'dolar_global',
                'columna'         => null,
                'solo_en_dolares' => false,
                'descripcion'     => 'costo en dólares, listas en dólares o listas que cotizan desde otra moneda',
            ];
        }

        return [
            'tipo'            => 'todo',
            'columna'         => null,
            'solo_en_dolares' => false,
            'descripcion'     => 'todo el catálogo',
        ];
    }

    /**
     * Recorre los ids del alcance en orden de id y le pasa cada lote de hasta $lote ids a
     * $despachar. Nunca con OFFSET, que en un catálogo grande hacía que cada página leyera y
     * tirara todas las anteriores.
     *
     * - Todo el catálogo del dueño: el chunkById() de Laravel, `id > último ORDER BY id LIMIT n`.
     *   Ahí el keyset es barato: articles_user_deleted_idx (user_id, deleted_at) ya sale ordenado
     *   por id y cubre la consulta entera.
     * - Una columna (proveedor, categoría, subcategoría, lista de precios del proveedor, con o sin
     *   la intersección con el costo en dólares): UNA consulta sin ORDER BY, y los ids se ordenan
     *   y se parten en PHP (ver repartir_de_una_sola_consulta()).
     * - El dólar global: dos consultas (ver repartir_el_alcance_del_dolar()), paginadas con el
     *   mismo keyset, a mano.
     *
     * @param  array    $alcance
     * @param  int      $lote
     * @param  callable $despachar  Recibe un array de ids (int).
     * @return void
     */
    protected function repartir_en_lotes(array $alcance, $lote, callable $despachar)
    {
        if ($alcance['tipo'] === 'dolar_global') {
            $this->repartir_el_alcance_del_dolar($lote, $despachar);
            return;
        }

        /*
         * 🔴 Siempre con user_id adelante: es lo que hace que el alcance por proveedor use el
         * índice (user_id, provider_id, ...) y lo que impide que un id de otra cuenta de una base
         * compartida entre al recálculo de ésta. SoftDeletes deja afuera los borrados, como antes.
         */
        $query = Article::where('user_id', $this->user_id);

        if ($alcance['tipo'] === 'columna') {

            $query->where($alcance['columna'], $this->model_id);

            if ($alcance['solo_en_dolares']) {
                $query->where('cost_in_dollars', 1);
            }

            $this->repartir_de_una_sola_consulta($query, $lote, $despachar);

            return;
        }

        $query->toBase()
                ->select('articles.id')
                ->chunkById($lote, function ($filas) use ($despachar) {

                    $ids = [];

                    foreach ($filas as $fila) {
                        $ids[] = (int) $fila->id;
                    }

                    $despachar($ids);

                }, 'articles.id', 'id');
    }

    /**
     * Reparte un alcance por columna con UNA consulta sin ORDER BY: junta todos los ids, los
     * ordena en PHP y los parte en lotes de $lote (medición a escala del 29/9/2026). Salen el
     * mismo conjunto y los mismos lotes que con el keyset.
     *
     * 🔴 Por qué no chunkById(), que es lo que había: con `ORDER BY id`, MySQL no puede sacar los
     * ids ordenados de articles_user_provider_status_idx (user_id, provider_id, status,
     * deleted_at), porque status está en el medio y no está en el WHERE. Entonces recorre
     * articles_user_deleted_idx, que sí sale ordenado por id, y va a buscar cada fila para mirar
     * el provider_id: medido con ETMAN (64.500 artículos de un dueño con 250.000, en
     * empresa_bench_s24), el reparto tardaba 8,6 a 9,9 segundos en 65 consultas, 168 ms por página
     * con las filas frías. Sin ORDER BY, la misma consulta es una lectura del índice cubriente:
     * 70 a 90 ms en total. Poner `status` en el WHERE también usaría el índice, pero achicaría el
     * alcance: hoy entran los artículos inactivos, y tienen que seguir entrando.
     *
     * Con cursor() y no con pluck(): es la misma consulta, pero pluck() arma de una vez un objeto
     * por fila y cursor() los trae de a uno. Medido con ETMAN: +35 MB de memoria con pluck()
     * contra +7,6 MB con cursor(), en un worker que corre con --memory=512 y una columna que puede
     * tener cientos de miles de artículos.
     *
     * Las columnas sin índice propio (category_id, sub_category_id, provider_price_list_id) no
     * empeoran: el keyset también recorría todos los artículos del dueño para encontrarlas, y
     * ahora es una sola pasada.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query  Con user_id y la columna (SoftDeletes
     *                                                        agrega deleted_at IS NULL).
     * @param  int                                   $lote
     * @param  callable                              $despachar
     * @return void
     */
    protected function repartir_de_una_sola_consulta($query, $lote, callable $despachar)
    {
        $ids = [];

        foreach ($query->toBase()->select('articles.id')->cursor() as $fila) {
            $ids[] = (int) $fila->id;
        }

        /* Sin ORDER BY, el índice los devuelve agrupados por status: el orden de id se arma acá. */
        sort($ids, SORT_NUMERIC);

        foreach (array_chunk($ids, $lote) as $ids_del_lote) {
            $despachar($ids_del_lote);
        }
    }

    /**
     * Reparte el alcance del dólar global: los artículos del dueño cuyo precio depende del dólar de
     * la cuenta. Son dos ramas (ver ids_con_costo_en_dolares() e
     * ids_con_listas_que_dependen_del_dolar()), cada una paginada por keyset —sus primeros $lote
     * ids mayores que el último despachado—, y cada lote es la unión de las dos, ordenada y cortada
     * en $lote. Es correcto: los $lote ids más chicos de la unión están, cada uno, entre los $lote
     * más chicos de su rama.
     *
     * Una rama se deja de consultar cuando se AGOTÓ (seguimiento del 29/9/2026): si devolvió menos
     * de $lote ids, no tiene más que esos; y si además el último de ellos ya quedó despachado (es
     * menor o igual que el nuevo cursor), no le queda nada por delante. Sin esto, en una cuenta con
     * muchos artículos en dólares y pocas listas en dólares, la rama de las listas se volvía a
     * consultar en cada página para devolver nada. 🔴 Las dos condiciones hacen falta: una rama
     * que devolvió menos de $lote pero cuyo último id quedó afuera del corte de la unión todavía
     * tiene ids por despachar, y dejarla de consultar los perdería.
     *
     * @param  int      $lote
     * @param  callable $despachar  Recibe un array de ids (int).
     * @return void
     */
    protected function repartir_el_alcance_del_dolar($lote, callable $despachar)
    {
        $ultimo_id = 0;

        $articulos_agotada = false;
        $listas_agotada    = false;

        while (!$articulos_agotada || !$listas_agotada) {

            $de_articulos = $articulos_agotada ? [] : $this->ids_con_costo_en_dolares($ultimo_id, $lote);
            $de_listas    = $listas_agotada ? [] : $this->ids_con_listas_que_dependen_del_dolar($ultimo_id, $lote);

            $ids = [];

            foreach (array_merge($de_articulos, $de_listas) as $id) {
                $ids[(int) $id] = (int) $id;
            }

            ksort($ids);

            $ids = array_slice(array_values($ids), 0, $lote);

            if (empty($ids)) {
                break;
            }

            $despachar($ids);

            $ultimo_id = (int) end($ids);

            if (count($de_articulos) < $lote && (empty($de_articulos) || (int) end($de_articulos) <= $ultimo_id)) {
                $articulos_agotada = true;
            }

            if (count($de_listas) < $lote && (empty($de_listas) || (int) end($de_listas) <= $ultimo_id)) {
                $listas_agotada = true;
            }
        }
    }

    /**
     * Rama 1 del dólar global: artículos del dueño con el costo en dólares (ArticleHelper::cotizar()
     * los multiplica por el dólar).
     *
     * @param  int $ultimo_id
     * @param  int $lote
     * @return int[]
     */
    protected function ids_con_costo_en_dolares($ultimo_id, $lote)
    {
        return Article::where('user_id', $this->user_id)
                        ->where('cost_in_dollars', 1)
                        ->toBase()
                        ->where('articles.id', '>', $ultimo_id)
                        ->orderBy('articles.id')
                        ->limit($lote)
                        ->pluck('articles.id')
                        ->map(function ($id) { return (int) $id; })
                        ->all();
    }

    /**
     * Rama 2 del dólar global: artículos del dueño (no borrados) con alguna entrada por lista y
     * moneda (extensión ventas_en_dolares) cuyo precio sale del dólar de la cuenta:
     *
     *  - las que cotizan desde otra moneda (cotizar_desde_otra_moneda = 1), como antes;
     *  - 🔴 las entradas EN DÓLARES (moneda_id = 2), también de artículos con el costo en pesos
     *    (seguimiento del 29/9/2026): ArticlePriceTypeMonedaHelper calcula el precio en dólares de
     *    un artículo en pesos DIVIDIENDO el costo por el dólar de la cuenta, así que al cambiar el
     *    dólar ese precio cambia. Antes esos artículos no entraban al alcance y su precio en
     *    dólares quedaba viejo hasta el próximo recálculo completo.
     *
     * Sin la subconsulta correlacionada del orWhereHas de antes, que se evaluaba por cada fila del
     * catálogo. Con DISTINCT: un artículo con varias entradas que dependen del dólar aparece una
     * sola vez (sin él, sus filas repetidas le robarían lugar al corte de la rama y el keyset se
     * salteaba ids).
     *
     * @param  int $ultimo_id
     * @param  int $lote
     * @return int[]
     */
    protected function ids_con_listas_que_dependen_del_dolar($ultimo_id, $lote)
    {
        return DB::table('article_price_type_monedas')
                    ->join('articles', 'articles.id', '=', 'article_price_type_monedas.article_id')
                    ->where('articles.user_id', $this->user_id)
                    ->whereNull('articles.deleted_at')
                    ->where(function ($query) {
                        $query->where('article_price_type_monedas.cotizar_desde_otra_moneda', 1)
                              ->orWhere('article_price_type_monedas.moneda_id', self::MONEDA_DOLAR);
                    })
                    ->where('article_price_type_monedas.article_id', '>', $ultimo_id)
                    ->distinct()
                    ->orderBy('article_price_type_monedas.article_id')
                    ->limit($lote)
                    ->pluck('article_price_type_monedas.article_id')
                    ->map(function ($id) { return (int) $id; })
                    ->all();
    }

    /*
     * ⚠️ Este job NO tiene failed(), y no es un olvido.
     *
     * Laravel llama a failed() sobre una instancia deserializada del payload original, así
     * que nada de lo que handle() haya escrito en el objeto —incluido el id de la corrida que
     * abrió— existe ahí. Buscar "la corrida abierta de este usuario" sería adivinar y podría
     * cerrar la de otro productor que está sano.
     *
     * Y notificar sin poder cerrar la corrida tampoco sirve: el finalizador que se encola
     * antes del reparto va a avisar igual cuando salte su tope de reloj, así que lo único que
     * se lograría es mandarle al usuario dos avisos de error con textos distintos por la
     * misma falla. El aviso lo da el finalizador, que sí sabe de qué corrida está hablando.
     */
}
