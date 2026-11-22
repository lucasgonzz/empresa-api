<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\VenderLayoutHelper;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Siembra el "Diseño ágil" de Vender (sin uso) para cada dueño que todavía no tiene ningún diseño de
 * plantilla ágil (misión vender-secciones-personalizables, 22/11/2026).
 *
 * El diseño ágil se arma en código en el SPA (`diseno_agil.js`): la base sólo guarda
 * `{"version":2,"plantilla":"agil"}`, así que si el diseño ágil mejora no hay que volver a correr
 * nada sobre las bases.
 *
 * STANDALONE E IDEMPOTENTE: se corre una vez como seeder de la versión sobre las bases de
 * producción que ya existen, y también lo llaman `UserSetupHelper`, `DemoSetupHelper` y
 * `DatabaseSeeder`. Correrlo dos veces no duplica nada.
 *
 * 🔴 NO cambia cuál diseño está en uso (el ágil nace con `en_uso = false`), asegura antes el
 * predeterminado del dueño (así el ágil nunca queda como único diseño) y NO le crea nada a un
 * empleado: los diseños son siempre del dueño. La decisión y el alta viven en
 * `VenderLayoutHelper::crear_agil_si_no_tiene()`.
 *
 * Límite conocido: si un dueño borra el ágil y alguien vuelve a correr este seeder a mano,
 * reaparece. El release lo corre una sola vez.
 */
class VenderLayoutAgilSeeder extends Seeder
{
    /**
     * Recorre los dueños (`owner_id` null) y le siembra el diseño ágil al que no tiene ninguno.
     *
     * chunkById en vez de get(): mismo patrón que `VenderLayoutSeeder`, para no cargar en memoria
     * todos los usuarios de una base grande.
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
                    VenderLayoutHelper::crear_agil_si_no_tiene($owner->id);
                }
            });
    }
}
