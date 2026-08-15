<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retención de los eventos crudos de comportamiento de compradores: borra lo que
 * tiene más de DIAS_RETENCION días de antigüedad
 * (misión tracking-buyers-tienda).
 *
 * `buyer_tracking_events` es la tabla de alto volumen del sistema —una fila por
 * vista de producto de cada visitante anónimo— y sin retención crece sin techo.
 * Lo que sobrevive al corte es el agregado de `buyer_tracking_daily`, que este
 * comando no toca nunca.
 *
 * El borrado va POR LOTES y no de un saque, y ese es el punto del comando. Un
 * DELETE de cientos de miles de filas mantiene el lock mientras dura y es
 * exactamente el mecanismo del `1205 Lock wait timeout` que ya tumbó ventas en
 * producción en este sistema. El bucle de lotes chicos deja huecos entre
 * sentencia y sentencia para que el resto de la aplicación siga escribiendo.
 */
class PurgarBuyerTracking extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'tracking:purgar-buyers {--dias= : Días de retención; por defecto DIAS_RETENCION}';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Borra por lotes los eventos crudos de tracking de compradores más viejos que la retención';

    /**
     * Días de retención de los eventos crudos.
     *
     * 90 es decisión de Lucas del 15/8/2026, tomada antes de construir: es la
     * ventana en la que el comportamiento reciente todavía sirve para el motor de
     * ofertas. Más atrás la pregunta que se hace el ERP es de tendencia, y esa la
     * contesta el agregado diario, que no se purga.
     *
     * @var int
     */
    const DIAS_RETENCION = 90;

    /**
     * Filas por sentencia DELETE. Chico a propósito: es el tamaño del lock que se
     * toma por vez.
     *
     * @var int
     */
    const FILAS_POR_LOTE = 5000;

    /**
     * Tope de vueltas del bucle de borrado, para que no pueda quedar girando para
     * siempre si algo reinserta filas viejas mientras purga. FILAS_POR_LOTE x
     * MAX_VUELTAS = 5 millones de filas por corrida; lo que sobre se lleva la
     * corrida de mañana.
     *
     * @var int
     */
    const MAX_VUELTAS = 1000;

    /**
     * Ejecuta la purga sobre el dueño de la instancia (config app.USER_ID).
     *
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        /*
         * Guarda barata primero, como las tres guardas ordenadas de
         * DemoEventoEmitter::emitir(). Es la compatibilidad hacia atrás del
         * contrato con tienda-api: hay instancias con el código nuevo y todavía
         * sin las tablas, y el cron corre igual todas las noches.
         */
        if (!Schema::hasTable('buyer_tracking_events')) {
            return 0;
        }

        $user = $this->resolver_comercio();

        if (!$user) {
            $this->info('tracking:purgar-buyers — no hay comercio configurado (app.USER_ID), no se purga nada.');
            return 0;
        }

        /*
         * Doble gate: el Kernel ya filtra por extensión, pero el comando se puede
         * correr a mano y la extensión es lo que se le vendió (o no) al cliente.
         */
        if (!UserHelper::hasExtencion('tracking_buyers', $user)) {
            $this->info('tracking:purgar-buyers — el comercio no tiene la extensión tracking_buyers, no se purga nada.');
            return 0;
        }

        $dias   = $this->resolver_dias();
        $limite = now()->subDays($dias);

        $borradas = $this->purgar_por_lotes($user->id, $limite);

        $this->info(
            'tracking:purgar-buyers — se borraron ' . $borradas . ' eventos anteriores a '
            . $limite->format('Y-m-d H:i:s') . ' (retención de ' . $dias . ' días).'
        );

        return 0;
    }

    /**
     * Días de retención efectivos: los de --dias si vino un entero positivo, la
     * constante si no. Un --dias=0 borraría todo el histórico de un saque, así que
     * no se acepta.
     *
     * @return int
     */
    protected function resolver_dias()
    {
        $opcion = $this->option('dias');

        if (!is_null($opcion) && ctype_digit((string) $opcion) && (int) $opcion > 0) {
            return (int) $opcion;
        }

        return self::DIAS_RETENCION;
    }

    /**
     * Bucle de borrado por lotes. Corta cuando un DELETE no borró nada (no queda
     * nada viejo) o cuando se agotaron las vueltas.
     *
     * @param int $user_id
     * @param \Carbon\Carbon $limite Eventos con occurred_at anterior a esto se borran
     * @return int Cantidad total de filas borradas
     */
    protected function purgar_por_lotes($user_id, $limite)
    {
        $borradas = 0;

        for ($vuelta = 0; $vuelta < self::MAX_VUELTAS; $vuelta++) {

            /*
             * El where por user_id no es decorativo: es lo que le permite a MySQL
             * entrar por bte_user_ocurrido_index (user_id, occurred_at). Sin el
             * user_id fijo, el rango sobre occurred_at no tiene prefijo por donde
             * empezar y el DELETE degrada a full scan.
             */
            $lote = DB::table('buyer_tracking_events')
                ->where('user_id', $user_id)
                ->where('occurred_at', '<', $limite)
                ->limit(self::FILAS_POR_LOTE)
                ->delete();

            $borradas += $lote;

            if ($lote < self::FILAS_POR_LOTE) {
                break;
            }
        }

        return $borradas;
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
