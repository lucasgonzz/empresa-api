<?php

namespace Database\Seeders;

use App\Models\DepositMovementStatus;
use Illuminate\Database\Seeder;

/**
 * Siembra los dos estados FIJOS de los movimientos de depósito: "En proceso" y "Recibido".
 *
 * Desde la misión movimientos-deposito-auditoria (3/10/2026) es IDEMPOTENTE: `UserSetupHelper` y
 * `DemoSetupHelper` lo corren en cada instalación, y con `create()` en una base compartida por
 * varios comercios cada alta nueva duplicaba "En proceso" y "Recibido". Ahora busca la fila fija
 * (`user_id` NULL) por nombre y solo la crea si no existe.
 *
 * Los estados propios de cada comercio (`user_id` = dueño) no los toca: se crean desde
 * ABM > Inventario.
 */
class DepositMovementStatusSeeder extends Seeder
{
    /**
     * Crea los estados fijos que falten.
     *
     * @return void
     */
    public function run()
    {
        $nombres = [
            'En proceso',
            'Recibido',
        ];

        foreach ($nombres as $nombre) {
            // where('user_id', null) se traduce a `user_id IS NULL`: busca solo entre los fijos.
            DepositMovementStatus::firstOrCreate([
                'name'      => $nombre,
                'user_id'   => null,
            ]);
        }
    }
}
