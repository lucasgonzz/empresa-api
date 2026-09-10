<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Encola la generación del reporte de inventario (stock mínimo, artículos sin stock, valuación del
 * inventario a costo y a precio) de cada comercio. Corre de noche desde el scheduler (Kernel,
 * 04:00) y se puede correr a mano.
 *
 * Misión optimizacion-vps-fase1 (10/9/2026, release 4.0.24). Hasta la 4.0.23 el reporte se
 * regeneraba desde InventoryPerformanceController::index() cada vez que alguien entraba al sistema
 * con un reporte de más de `duracion_reporte_inventario` minutos (30 por defecto). Como la SPA lo
 * pide al arrancar, en la práctica el job corría todo el día: en servian (537k artículos) cada
 * corrida tardaba 18-20 minutos y el worker no hacía otra cosa. Ahora se genera UNA vez por noche
 * acá, y a pedido con el botón Actualizar (POST inventory-performance/generate); index() sólo
 * encola si no hay ningún reporte o si el último tiene más de 7 días.
 *
 * Tres formas de elegir a quién se le genera, en este orden:
 *   --user_id=N           ese comercio (si N es un empleado, el dueño de su cuenta).
 *   app.USER_ID           el dueño de la instancia: es lo que tiene toda instancia de cliente y lo
 *                         que usa el scheduler.
 *   ninguna de las dos    (dev/testing, bases con varios comercios adentro) todos los dueños con
 *                         actividad en los últimos 30 días, de a uno.
 *
 * No hace el trabajo acá: despacha un ProcessInventoryPerformanceJob por comercio, con el mismo
 * candado atómico (Cache::add) que usa el controller, así que si ya hay una generación en curso
 * para ese comercio no se encola otra. Un job por comercio, secuencial en la cola.
 *
 * Uso manual:
 *   php artisan inventario:generar
 *   php artisan inventario:generar --user_id=500
 */
class GenerarReporteDeInventario extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'inventario:generar
                            {--user_id= : Comercio puntual; sin esta opción se usa app.USER_ID y, si tampoco está, todos los comercios con actividad reciente}';

    /**
     * Descripción para php artisan list.
     *
     * @var string
     */
    protected $description = 'Encola la generación del reporte de inventario (stock mínimo y valuación) de cada comercio; corre de noche desde el scheduler';

    /**
     * Días hacia atrás en los que tiene que haber actividad en la cuenta (del dueño o de algún
     * empleado) para que un comercio entre en la corrida "todos los dueños". Un comercio que nadie
     * abrió en un mes no necesita un reporte nuevo cada noche: cuando alguien vuelva a entrar,
     * index() lo encola solo por la red de seguridad de los 7 días.
     *
     * @var int
     */
    const DIAS_DE_ACTIVIDAD = 30;

    /**
     * Tamaño de tanda del recorrido de dueños en la corrida "todos" (chunkById).
     *
     * @var int
     */
    const TANDA_DE_DUENOS = 200;

    /**
     * Cuántas generaciones se encolaron en esta corrida.
     *
     * @var int
     */
    protected $encolados = 0;

    /**
     * Cuántos comercios ya tenían una generación en curso (candado tomado) y se saltearon.
     *
     * @var int
     */
    protected $en_curso = 0;

    /**
     * Ejecuta el comando.
     *
     * @return int 0 si terminó; 1 si el --user_id pedido no existe.
     */
    public function handle()
    {
        $user_id = $this->option('user_id');

        if (! empty($user_id)) {
            $owner_id = $this->resolver_dueno($user_id);

            if (is_null($owner_id)) {
                $this->error('inventario:generar: no existe el usuario ' . $user_id . '.');

                return 1;
            }

            $this->encolar_uno($owner_id);
            $this->resumen('comercio pedido por --user_id');

            return 0;
        }

        // El caso de toda instancia de cliente (y del scheduler): un solo comercio, el de la instancia.
        if (! empty(config('app.USER_ID'))) {
            $this->encolar_uno((int) config('app.USER_ID'));
            $this->resumen('dueño de la instancia (app.USER_ID)');

            return 0;
        }

        $this->encolar_duenos_con_actividad();
        $this->resumen('todos los dueños con actividad en los últimos ' . self::DIAS_DE_ACTIVIDAD . ' días');

        return 0;
    }

    /**
     * Id del dueño de la cuenta a la que pertenece $user_id: el mismo id si es un dueño, su
     * owner_id si es un empleado. Null si el usuario no existe.
     *
     * El reporte es por cuenta (articles.user_id es siempre el dueño), así que pedirlo para un
     * empleado tiene que generar el de su dueño, no un reporte vacío de un id sin artículos.
     *
     * @param  int|string  $user_id
     * @return int|null
     */
    protected function resolver_dueno($user_id)
    {
        $user = User::find($user_id);

        if (is_null($user)) {
            return null;
        }

        return empty($user->owner_id) ? (int) $user->id : (int) $user->owner_id;
    }

    /**
     * Corrida "todos": dueños (owner_id IS NULL) con actividad propia, o de algún empleado de su
     * cuenta, en los últimos DIAS_DE_ACTIVIDAD días. chunkById para no cargar todos los users de
     * una en una base con muchos comercios.
     *
     * @return void
     */
    protected function encolar_duenos_con_actividad()
    {
        $desde = Carbon::now()->subDays(self::DIAS_DE_ACTIVIDAD);

        User::query()
            ->select('id')
            ->whereNull('owner_id')
            ->where(function ($query) use ($desde) {
                $query->where('last_activity', '>=', $desde)
                    ->orWhereExists(function ($sub) use ($desde) {
                        $sub->select(DB::raw(1))
                            ->from('users as empleados')
                            ->whereColumn('empleados.owner_id', 'users.id')
                            ->where('empleados.last_activity', '>=', $desde);
                    });
            })
            ->chunkById(self::TANDA_DE_DUENOS, function ($duenos) {
                foreach ($duenos as $dueno) {
                    $this->encolar_uno((int) $dueno->id);
                }
            });
    }

    /**
     * Encola la generación para un comercio (o avisa que ya había una en curso) y lleva la cuenta.
     *
     * @param  int  $owner_id
     * @return void
     */
    protected function encolar_uno($owner_id)
    {
        if (InventoryPerformanceHelper::encolar_generacion($owner_id)) {
            $this->encolados++;
            $this->line('  comercio ' . $owner_id . ': generación encolada.');

            return;
        }

        $this->en_curso++;
        $this->line('  comercio ' . $owner_id . ': ya había una generación en curso, no se encola otra.');
    }

    /**
     * Línea final con los totales de la corrida.
     *
     * @param  string  $alcance  A quién se le generó, para que el log del scheduler lo diga.
     * @return void
     */
    protected function resumen($alcance)
    {
        $this->info(
            'inventario:generar (' . $alcance . '): ' . $this->encolados . ' generación(es) encolada(s), '
            . $this->en_curso . ' ya en curso.'
        );
    }
}
