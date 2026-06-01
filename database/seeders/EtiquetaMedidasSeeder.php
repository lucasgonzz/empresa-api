<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\EtiquetaMedidaHelper;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea medidas predeterminadas de etiquetas solo para usuarios dueños (owners).
 * Los empleados (owner_id no null) comparten la configuración del owner vía API.
 */
class EtiquetaMedidasSeeder extends Seeder
{
    /**
     * Ejecuta el seeder sobre owners de la base actual.
     *
     * @return void
     */
    public function run()
    {
        User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    EtiquetaMedidaHelper::seed_defaults_for_user($user->id);
                }
            });
    }
}
