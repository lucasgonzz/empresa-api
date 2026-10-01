<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\combo\ComboCalculadoEsquemaHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\Combo;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Rehace la cuenta de los combos calculados (misión combos-calculados, 30/9/2026).
 *
 * Es la RED DE SEGURIDAD de `ComboCalculadoHelper`: los combos calculados se recalculan solos cuando
 * se guarda un artículo, en las masivas, al cerrar una importación o un recálculo de precios, pero
 * hay escrituras que ningún gancho ve (consultas directas a `articles`, correcciones por comando,
 * cambios de cotización que no pasan por `setFinalPrice()`). Corre una vez por noche desde el
 * scheduler (Kernel, 02:30) y se puede correr a mano, por ejemplo después de un cambio de datos
 * directo en la base.
 *
 * Tres formas de elegir a quién se le recalcula, en este orden (mismo criterio que
 * `inventario:generar`):
 *   --user=N             ese comercio (si N es un empleado, el dueño de su cuenta).
 *   app.USER_ID          el dueño de la instancia: es lo que tiene toda instancia de cliente.
 *   ninguna de las dos   (dev/testing, bases con varios comercios adentro) todos los DUEÑOS que
 *                        tengan algún combo calculado.
 *
 * 🔴 Solo dueños (`owner_id IS NULL`): los combos y los artículos son del dueño de la cuenta, y un
 * empleado no tiene catálogo propio. Recorrer empleados haría el mismo trabajo dos veces, contra
 * el mismo dueño.
 *
 * Es idempotente: solo escribe los combos cuyo costo o precio realmente cambió, así que correrlo
 * dos veces seguidas no hace nada la segunda vez.
 *
 * Uso manual:
 *   php artisan combos:recalcular
 *   php artisan combos:recalcular --user=500
 */
class RecalcularCombos extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'combos:recalcular
                            {--user= : Comercio puntual; sin esta opción se usa app.USER_ID y, si tampoco está, todos los dueños con combos calculados}';

    /**
     * Descripción para php artisan list.
     *
     * @var string
     */
    protected $description = 'Recalcula el costo y el precio de los combos que se calculan en base a sus artículos (red de seguridad diaria)';

    /**
     * Ejecuta el comando.
     *
     * @return int 0 si terminó; 1 si el --user pedido no existe.
     */
    public function handle()
    {
        if (!ComboCalculadoEsquemaHelper::disponible()) {
            $this->info('combos:recalcular: la base todavía no tiene el esquema de los combos calculados; no hay nada que hacer.');

            return 0;
        }

        $user = $this->option('user');

        if (!empty($user)) {

            $owner_id = $this->resolver_dueno($user);

            if (is_null($owner_id)) {
                $this->error('combos:recalcular: no existe el usuario ' . $user . '.');

                return 1;
            }

            $this->recalcular($owner_id);

            return 0;
        }

        /* El caso de toda instancia de cliente (y del scheduler): un solo comercio, el de la instancia. */
        if (!empty(config('app.USER_ID'))) {
            $this->recalcular((int) config('app.USER_ID'));

            return 0;
        }

        /*
         * Dev/testing: todos los dueños que tengan combos calculados. `owner_id IS NULL` y no la
         * lista de user_id de los combos: un combo creado por un empleado lleva el id del dueño
         * (Controller::userId() resuelve al dueño), pero un dato viejo podría traer el del empleado.
         */
        $duenos = User::whereNull('owner_id')
                        ->whereIn('id', Combo::where('calcular_desde_articulos', 1)->select('user_id'))
                        ->orderBy('id', 'ASC')
                        ->pluck('id');

        foreach ($duenos as $owner_id) {
            $this->recalcular((int) $owner_id);
        }

        return 0;
    }

    /**
     * Recalcula los combos calculados de un dueño y cuenta el resultado.
     *
     * @param  int  $owner_id
     * @return void
     */
    protected function recalcular($owner_id)
    {
        $cantidad = ComboCalculadoHelper::recalcular_de_un_dueno($owner_id);

        $this->info('combos:recalcular: comercio ' . $owner_id . ', ' . $cantidad . ' combo(s) recalculado(s).');
    }

    /**
     * El id del dueño de la cuenta a la que pertenece el usuario pedido, o null si no existe.
     *
     * @param  mixed  $user_id
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
}
