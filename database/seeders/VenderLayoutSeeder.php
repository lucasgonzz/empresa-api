<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\VenderLayoutHelper;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea el "Diseño predeterminado" de Vender para cada dueño que no tiene ningún diseño (misión
 * diseno-vender-configurable, 28/9/2026).
 *
 * El diseño se guarda con `layout = NULL`, que significa "el diseño del sistema": el SPA lo arma en
 * código (`diseno_predeterminado.js`), así un negocio que no tocó nada sigue viendo Vender
 * exactamente como hasta hoy, y el día que el diseño por defecto cambie no hay que volver a correr
 * nada sobre las bases.
 *
 * STANDALONE E IDEMPOTENTE: se corre como seeder de la versión sobre las bases de producción que ya
 * existen, y también lo llaman `UserSetupHelper`, `DemoSetupHelper` y `DatabaseSeeder`. Correrlo dos
 * veces no duplica nada.
 *
 * 🔴 NO TOCA a un dueño que ya tiene diseños, aunque ninguno se llame "Diseño predeterminado" ni
 * tenga `layout` null: si el dueño ya armó los suyos (o borró el predeterminado a propósito), el
 * seeder no se lo vuelve a crear. Y NO le crea nada a un empleado: el diseño es uno para todo el
 * negocio y siempre es del dueño.
 *
 * La decisión "¿tiene alguno?" y el alta viven en `VenderLayoutHelper::crear_predeterminado_si_no_tiene()`,
 * que es la MISMA que usa el index del ABM: el seeder y un dueño que entra al sistema en ese mismo
 * momento no pueden crearle dos predeterminados (ver el candado en el helper).
 */
class VenderLayoutSeeder extends Seeder
{
    /**
     * Recorre los dueños (`owner_id` null) y le crea el predeterminado al que no tiene ninguno.
     *
     * chunkById en vez de get(): mismo patrón que GlobalSearchDefaultsSeeder, para no cargar en
     * memoria todos los usuarios de una base grande (las bases compartidas tienen decenas de
     * comercios).
     *
     * @return void
     */
    public function run()
    {
        User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($owners) {
                foreach ($owners as $owner) {
                    VenderLayoutHelper::crear_predeterminado_si_no_tiene($owner->id);
                }
            });
    }
}
