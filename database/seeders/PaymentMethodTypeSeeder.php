<?php

namespace Database\Seeders;

use App\Models\PaymentMethodType;
use Illuminate\Database\Seeder;

/**
 * Tipos de metodo de pago de la TIENDA ONLINE (`payment_method_types`).
 *
 * 🔴 No confundir con `CAPaymentMethodTypeSeeder`, que siembra los tipos de metodo de pago de
 * CUENTA CORRIENTE (los del mostrador) en otra tabla. Los nombres se parecen tanto que este
 * seeder vivio huerfano: hasta el 3/9/2026 NADIE lo llamaba, ni `DatabaseSeeder`, ni
 * `UserSetupHelper`, ni `DemoSetupHelper` -- el que estaba en las tres listas era el `CA...`.
 *
 * La consecuencia era silenciosa y grande: ninguna instalacion nueva tenia estos tipos, asi que
 * en ABM -> Tienda online -> Metodos de pago el desplegable "Tipo" salia VACIO y no habia forma
 * de dar de alta el cobro online. La pantalla estaba, el campo estaba, y no se podia elegir nada.
 * Se descubrio el 3/9/2026 armando el cobro con Mercado Pago.
 *
 * Ahora se llama desde `UserSetupHelper` y `DemoSetupHelper`, junto al resto. Para las bases que
 * ya existen en produccion lo cubre la migracion `asegurar_payment_method_type_mercado_pago`, que
 * no depende de que alguien se acuerde de correr un seeder en el upgrade.
 */
class PaymentMethodTypeSeeder extends Seeder
{
    /**
     * Siembra los tipos de metodo de pago de la tienda online.
     *
     * Es IDEMPOTENTE a proposito (`firstOrCreate` y no `create`): lo llaman los dos setups y
     * ademas se puede correr a mano sobre una base que ya lo tenga. Con `create` cada corrida
     * agregaba una fila duplicada, y el select del ABM terminaba mostrando "MercadoPago" tres
     * veces sin que ninguna estuviera mal.
     *
     * @return void
     */
    public function run()
    {
        /*
         * Solo MercadoPago (decision de Lucas, 3/9/2026: "payway sacalo").
         *
         * Payway estaba en esta lista pero no se ofrece mas, asi que una instalacion nueva no
         * tiene por que arrancar con un tipo que nadie va a usar. No se BORRA de las bases que ya
         * lo tienen: sacarlo de la siembra no toca lo existente, y un comercio que hoy cobre por
         * Payway sigue funcionando igual -- la rama que lo mira (`OrderHelper:175`) compara por
         * nombre y simplemente no entra si no hay ningun metodo de ese tipo.
         */
        $models = [
            [
                'name' => 'MercadoPago',
            ],
        ];

        foreach ($models as $model) {
            PaymentMethodType::firstOrCreate([
                'name' => $model['name'],
            ]);
        }
    }
}
