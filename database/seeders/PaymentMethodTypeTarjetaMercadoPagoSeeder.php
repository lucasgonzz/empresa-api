<?php

namespace Database\Seeders;

use App\Models\PaymentMethodType;
use Illuminate\Database\Seeder;

/**
 * Seeder SUELTO de la misión tienda-cobro-tarjeta (24/11/2026): crea el tipo de método de pago
 * «Tarjeta Mercado Pago» en una base de producción ya existente.
 *
 * 🔴 Por qué no es una migración: el `migrate` del upgrade del ERP corre en todas las bases antes de
 * que la tienda nueva esté desplegada, y la tienda vieja trataría esa fila como un pago offline
 * (pedido sin cobro). Se corre a mano, base por base, DESPUÉS de actualizar la tienda del comercio.
 *
 * Es IDEMPOTENTE (`firstOrCreate`): se puede correr las veces que haga falta sin duplicar. Los tipos
 * son globales (la tabla no tiene dueño), así que no se itera por usuarios.
 *
 * Uso: php artisan db:seed --class=PaymentMethodTypeTarjetaMercadoPagoSeeder
 */
class PaymentMethodTypeTarjetaMercadoPagoSeeder extends Seeder
{
    /**
     * Crea el tipo si no existe.
     *
     * @return void
     */
    public function run()
    {
        PaymentMethodType::firstOrCreate([
            'name' => 'Tarjeta Mercado Pago',
        ]);
    }
}
