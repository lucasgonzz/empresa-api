<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
 * porque tres de las cinco columnas que formarían la clave son nullable y en
 * MySQL NULL != NULL (el porqué largo está en la migración
 * 2026_08_15_140100_create_buyer_tracking_daily_table).
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

        $borradas = $this->borrar_dia($user->id, $fecha);
        $grupos   = $this->agregar_dia($user->id, $fecha);
        $insertadas = $this->insertar($user->id, $fecha, $grupos);

        $this->info(
            'tracking:agregar-buyers — ' . $fecha . ': se borraron ' . $borradas
            . ' filas previas y se insertaron ' . $insertadas . '.'
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
     * Agrupa los eventos crudos del día por (buyer_id, article_id, event_type).
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
            ->select('buyer_id', 'article_id', 'event_type')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(dwell_ms), 0) as dwell_ms_total')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount_total')
            ->where('user_id', $user_id)
            ->whereBetween('occurred_at', [$desde, $hasta])
            ->groupBy('buyer_id', 'article_id', 'event_type')
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
            $filas[] = [
                'user_id'        => $user_id,
                'fecha'          => $fecha,
                'buyer_id'       => $grupo->buyer_id,
                'article_id'     => $grupo->article_id,
                'event_type'     => $grupo->event_type,
                'total'          => (int) $grupo->total,
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
