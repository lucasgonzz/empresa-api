<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rollup diario de los eventos de comportamiento de compradores: colapsa un día
 * de `buyer_tracking_events` en `buyer_tracking_daily`
 * (misión tracking-buyers-tienda).
 *
 * Existe porque los eventos crudos se purgan a los 90 días y el agregado no: esto
 * es lo que le deja al ERP responder "cuántas veces vieron este artículo en 2025"
 * sin tener que guardar millones de filas para siempre.
 *
 * Es IDEMPOTENTE por construcción: borra el día completo del comercio antes de
 * reinsertarlo. Esa es la propiedad que lo hace seguro de correr a mano, de
 * reintentar si el cron falló, y de recorrer el histórico hacia atrás sin
 * duplicar nada. La idempotencia NO viene de un unique en la tabla — no podría,
 * porque tres de las seis columnas que formarían la clave son nullable y en
 * MySQL NULL != NULL (el porqué largo está en la migración
 * 2026_08_15_140100_create_buyer_tracking_daily_table).
 *
 * 🔴 Ese borrar-y-reinsertar es también el punto peligroso del comando, y por eso
 * tiene TRES defensas encima. `buyer_tracking_daily` es la única memoria de largo
 * plazo del comportamiento de la tienda (los crudos se purgan a los 90 días), así
 * que un borrado que no llega a reinsertar es pérdida PERMANENTE de un día. Las
 * defensas, de adentro hacia afuera:
 *
 *   1. `LEAST(SUM(amount), TECHO_AMOUNT_TOTAL)` en el propio SELECT del rollup,
 *      para que un dato sucio no pueda voltear la corrida (ver agregar_dia()).
 *   2. Borrado + inserción dentro de una sola DB::transaction(): si la inserción
 *      falla igual, el borrado se revierte y el día queda como estaba.
 *   3. try/catch en handle() que loguea y devuelve código de salida 1, para que
 *      una corrida fallida no pase por buena en el cron.
 */
class AgregarBuyerTracking extends Command
{
    /**
     * Nombre y firma del comando Artisan. Sin --fecha procesa AYER, que es lo que
     * corresponde cuando lo dispara el cron a las 03:30: el día de ayer ya está
     * cerrado y no le van a llegar eventos nuevos.
     *
     * @var string
     */
    protected $signature = 'tracking:agregar-buyers {--fecha= : Día a agregar en formato Y-m-d; por defecto, ayer}';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Colapsa un día de buyer_tracking_events en el agregado diario buyer_tracking_daily';

    /**
     * Filas por sentencia INSERT. Un día de una tienda grande puede dar decenas de
     * miles de grupos y una sola sentencia gigante se choca contra max_allowed_packet
     * (y mantiene el lock mucho más tiempo del necesario).
     *
     * @var int
     */
    const FILAS_POR_INSERT = 500;

    /**
     * Techo del monto sumado de un grupo, exactamente el máximo que entra en
     * `buyer_tracking_daily.amount_total` = decimal(22,2).
     *
     * Existe porque el SUM del rollup puede desbordar la columna y tumbar la
     * corrida entera: todos los `checkout_complete` de visitantes anónimos caen en
     * un solo grupo (buyer_id y article_id en NULL), así que alcanzan DOS eventos
     * con un amount grande para que el SUM dé más de lo que entra y MySQL conteste
     * `1264 Out of range value` — con la corrida abortada después del borrado del
     * día. Medido contra el MySQL local, no es teórico.
     *
     * Se ACOTA con LEAST y no se descarta el grupo, y esa es la decisión: descartar
     * el grupo perdería también el `total` de eventos, que es un dato sano y es el
     * que más se lee; acotar deja el conteo intacto y sólo el monto tocado. Para
     * que el valor acotado no sea "una medición que falla devolviendo un valor
     * tranquilizador", cada grupo acotado sale por Log::warning diciendo cuál fue
     * (ver insertar()).
     *
     * @var string
     */
    const TECHO_AMOUNT_TOTAL = '99999999999999999999.99';

    /**
     * Ejecuta el rollup sobre el dueño de la instancia (config app.USER_ID).
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        /*
         * Guarda barata primero, como las tres guardas ordenadas de
         * DemoEventoEmitter::emitir(). Esta es la compatibilidad hacia atrás del
         * contrato con tienda-api: el esquema vive en `refractor` hasta que Lucas
         * lo mergee y salga por release, así que hay instancias con el código
         * nuevo y sin las tablas. El cron corre igual todas las noches: acá tiene
         * que salir sin ruido, no explotar.
         */
        if (!Schema::hasTable('buyer_tracking_events') || !Schema::hasTable('buyer_tracking_daily')) {
            return 0;
        }

        $user = $this->resolver_comercio();

        if (!$user) {
            $this->info('tracking:agregar-buyers — no hay comercio configurado (app.USER_ID), no se agrega nada.');
            return 0;
        }

        /*
         * Doble gate: el Kernel ya filtra por extensión, pero el comando se puede
         * correr a mano y la extensión es lo que se le vendió (o no) al cliente.
         */
        if (!UserHelper::hasExtencion('tracking_buyers', $user)) {
            $this->info('tracking:agregar-buyers — el comercio no tiene la extensión tracking_buyers, no se agrega nada.');
            return 0;
        }

        $fecha = $this->resolver_fecha();

        if (!$fecha) {
            $this->error('tracking:agregar-buyers — la opción --fecha no tiene formato Y-m-d válido, no se agrega nada.');
            return 1;
        }

        /*
         * 🔴 Borrado + inserción en UNA transacción, y no es un detalle de prolijidad.
         * borrar_dia() se lleva el agregado del día y sólo insertar() lo repone; si
         * insertar() tira en el medio —un desborde de columna, una desconexión, un
         * deadlock—, sin transacción el día queda BORRADO Y SIN REINSERTAR. Y como los
         * eventos crudos se purgan a los 90 días, después de esa ventana ese día ya no
         * se puede reconstruir con nada: es pérdida permanente.
         */
        try {
            $resultado = DB::transaction(function () use ($user, $fecha) {
                $borradas = $this->borrar_dia($user->id, $fecha);
                $grupos   = $this->agregar_dia($user->id, $fecha);

                return [
                    'borradas'   => $borradas,
                    'insertadas' => $this->insertar($user->id, $fecha, $grupos),
                ];
            });
        } catch (\Throwable $e) {
            /*
             * La transacción ya revirtió el borrado, así que el agregado del día quedó
             * como estaba. Lo que falta es que no pase inadvertido: el cron lo corre de
             * noche y sin nadie mirando, así que va al log Y con código de salida != 0.
             * Un rollup que falla en silencio son cifras del ERP que dejan de moverse
             * sin que nada lo denuncie.
             */
            Log::error('tracking:agregar-buyers: falló el rollup diario, la transacción se revirtió y el agregado del día quedó intacto.', [
                'fecha'     => $fecha,
                'user_id'   => $user->id,
                'excepcion' => get_class($e),
                'mensaje'   => $e->getMessage(),
            ]);

            $this->error(
                'tracking:agregar-buyers — ' . $fecha . ': falló el rollup (' . get_class($e) . ': '
                . $e->getMessage() . '). No se modificó el agregado del día.'
            );

            return 1;
        }

        $this->info(
            'tracking:agregar-buyers — ' . $fecha . ': se borraron ' . $resultado['borradas']
            . ' filas previas y se insertaron ' . $resultado['insertadas'] . '.'
        );

        return 0;
    }

    /**
     * Fecha a procesar: la de --fecha si vino y es válida, ayer si no.
     *
     * @return string|null Fecha en Y-m-d, o null si --fecha vino con formato inválido
     */
    protected function resolver_fecha()
    {
        $opcion = $this->option('fecha');

        if (empty($opcion)) {
            return now()->subDay()->format('Y-m-d');
        }

        $fecha = \DateTime::createFromFormat('Y-m-d', $opcion);

        /*
         * createFromFormat es permisivo: '2026-13-45' le parece bien y lo desborda
         * a 2027-02-14. La comparación contra el string original es lo que
         * distingue una fecha real de una que sólo tiene la forma.
         */
        if (!$fecha || $fecha->format('Y-m-d') !== $opcion) {
            return null;
        }

        return $opcion;
    }

    /**
     * Borra el agregado previo del día, que es lo que vuelve idempotente al comando.
     *
     * @param int $user_id
     * @param string $fecha Y-m-d
     * @return int Cantidad de filas borradas
     */
    protected function borrar_dia($user_id, $fecha)
    {
        return DB::table('buyer_tracking_daily')
            ->where('user_id', $user_id)
            ->where('fecha', $fecha)
            ->delete();
    }

    /**
     * Agrupa los eventos crudos del día por (buyer_id, article_id, event_type,
     * search_term).
     *
     * `search_term` entra en el GROUP BY y no es un capricho: sin él, todos los
     * eventos `search` del día colapsan en UNA fila con article_id NULL, y a los 90
     * días —cuando la purga ya se llevó los crudos— no queda registro de qué buscó
     * la gente. Es el dato que el motor de ofertas viene a leer.
     *
     * El filtro va por RANGO de occurred_at y no por DATE(occurred_at): una función
     * sobre la columna anula el índice bte_user_ocurrido_index y convierte esto en
     * un full scan de la tabla más grande del sistema.
     *
     * @param int $user_id
     * @param string $fecha Y-m-d
     * @return \Illuminate\Support\Collection
     */
    protected function agregar_dia($user_id, $fecha)
    {
        $desde = $fecha . ' 00:00:00';
        $hasta = $fecha . ' 23:59:59';

        return DB::table('buyer_tracking_events')
            ->select('buyer_id', 'article_id', 'event_type', 'search_term')
            ->selectRaw('COUNT(*) as total')
            /*
             * Visitantes DISTINTOS, no eventos: 40 vistas de un artículo pueden ser
             * 40 personas o una sola que recargó 40 veces, y son dos cosas muy
             * distintas para decidir una oferta. `total` ya cuenta los eventos.
             */
            ->selectRaw('COUNT(DISTINCT visitor_id) as visitantes')
            /*
             * MÁXIMO de resultados, no suma ni promedio, y no es obvio: el valor que
             * importa es el CERO. MAX(results_count) = 0 significa que esa búsqueda
             * no devolvió nada NINGUNA de las veces que la hicieron en el día — hay
             * demanda y no hay oferta, que es la pregunta que el motor de ofertas le
             * va a hacer a esta tabla. Con un SUM el cero se diluye en cuanto una
             * sola de las búsquedas devolvió algo, y con un promedio queda un número
             * que no contesta ninguna pregunta. MAX ignora los NULL, así que un grupo
             * que no es de búsquedas queda en NULL = no aplica.
             */
            ->selectRaw('MAX(results_count) as results_count')
            ->selectRaw('COALESCE(SUM(dwell_ms), 0) as dwell_ms_total')
            /*
             * El monto va ACOTADO al techo de la columna destino. Sin el LEAST, un
             * SUM que no entra en decimal(22,2) hace fallar el INSERT y se lleva
             * puesta la corrida justo después de haber borrado el día (ver el
             * porqué completo en TECHO_AMOUNT_TOTAL). Es la segunda línea de
             * defensa: la primera es el tope de monto de la ingesta en tienda-api,
             * pero ese tope vive en otro repo, se despliega en otro momento y no
             * cubre los datos que ya estén escritos.
             */
            ->selectRaw('LEAST(COALESCE(SUM(amount), 0), ' . self::TECHO_AMOUNT_TOTAL . ') as amount_total')
            /*
             * La comparación la hace MySQL sobre los decimales, no PHP sobre floats:
             * a esta escala un float no distingue el techo de un valor apenas mayor,
             * y este flag es lo único que hace visible un grupo acotado.
             */
            ->selectRaw('CASE WHEN COALESCE(SUM(amount), 0) > ' . self::TECHO_AMOUNT_TOTAL . ' THEN 1 ELSE 0 END as amount_desbordado')
            ->where('user_id', $user_id)
            ->whereBetween('occurred_at', [$desde, $hasta])
            ->groupBy('buyer_id', 'article_id', 'event_type', 'search_term')
            ->get();
    }

    /**
     * Inserta los grupos agregados, de a FILAS_POR_INSERT.
     *
     * @param int $user_id
     * @param string $fecha Y-m-d
     * @param \Illuminate\Support\Collection $grupos
     * @return int Cantidad de filas insertadas
     */
    protected function insertar($user_id, $fecha, $grupos)
    {
        if ($grupos->isEmpty()) {
            return 0;
        }

        $ahora = now();
        $filas = [];

        foreach ($grupos as $grupo) {

            /*
             * Un grupo acotado se avisa fuerte: el número que queda en la tabla es
             * el techo y no la suma real, y eso tiene que poder diagnosticarse
             * después. Se nombra el grupo completo para poder ir a buscar los
             * eventos crudos mientras todavía existan (90 días).
             */
            if (!empty($grupo->amount_desbordado)) {
                Log::warning('tracking:agregar-buyers: el monto sumado de un grupo superó el techo de amount_total y se acotó. Revisar los eventos crudos de ese grupo, hay datos sucios.', [
                    'fecha'       => $fecha,
                    'user_id'     => $user_id,
                    'buyer_id'    => $grupo->buyer_id,
                    'article_id'  => $grupo->article_id,
                    'event_type'  => $grupo->event_type,
                    'search_term' => $grupo->search_term,
                    'techo'       => self::TECHO_AMOUNT_TOTAL,
                ]);
            }

            $filas[] = [
                'user_id'        => $user_id,
                'fecha'          => $fecha,
                'buyer_id'       => $grupo->buyer_id,
                'article_id'     => $grupo->article_id,
                'event_type'     => $grupo->event_type,
                'search_term'    => $grupo->search_term,
                /* Se conserva el NULL a propósito: null = no aplica, 0 = buscó y no encontró nada */
                'results_count'  => is_null($grupo->results_count) ? null : (int) $grupo->results_count,
                'total'          => (int) $grupo->total,
                'visitantes'     => (int) $grupo->visitantes,
                'dwell_ms_total' => (int) $grupo->dwell_ms_total,
                'amount_total'   => $grupo->amount_total,
                'created_at'     => $ahora,
                'updated_at'     => $ahora,
            ];
        }

        foreach (array_chunk($filas, self::FILAS_POR_INSERT) as $lote) {
            DB::table('buyer_tracking_daily')->insert($lote);
        }

        return count($filas);
    }

    /**
     * Dueño de la instancia con sus extensiones cargadas (mismo criterio que el
     * resolve del Kernel).
     *
     * @return User|null
     */
    protected function resolver_comercio()
    {
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            return null;
        }

        return User::with('extencions')->find($user_id);
    }
}
