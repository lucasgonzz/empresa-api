<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asegura que exista el tipo de metodo de pago "MercadoPago" de la TIENDA ONLINE.
 *
 * 🔴 Por que una migracion y no alcanza con el seeder. `PaymentMethodTypeSeeder` vivio huerfano:
 * hasta el 3/9/2026 no lo llamaba NADIE (el que estaba en las listas de `UserSetupHelper` y
 * `DemoSetupHelper` era `CAPaymentMethodTypeSeeder`, que es el de cuenta corriente -- otra tabla,
 * nombre casi igual). Resultado: en toda base ya instalada el desplegable "Tipo" de
 * ABM -> Tienda online -> Metodos de pago sale VACIO, y no hay forma de dar de alta el cobro
 * online.
 *
 * Ese seeder ahora si se llama desde los dos setups, pero eso solo arregla las instalaciones
 * NUEVAS. Las que ya existen se arreglan aca, porque el upgrade de un cliente corre las
 * migraciones y no necesariamente los seeders -- mismo criterio que
 * `asegurar_plataforma_mercado_pago`, y que las filas de Mercado Libre / Tienda Nube, que
 * tambien las siembra una migracion.
 *
 * Solo MercadoPago (decision de Lucas, 3/9/2026). Payway no se siembra y TAMPOCO se borra de
 * las bases que ya lo tengan: esta migracion solo agrega lo que falta.
 */
class AsegurarPaymentMethodTypeMercadoPago extends Migration
{
    /** Nombre exacto que buscan `MercadoPagoCredentialsHelper` y el SPA de la tienda. */
    const NOMBRE = 'MercadoPago';

    /**
     * Inserta la fila si no esta. Es idempotente: correrla dos veces no duplica nada.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('payment_method_types')) {
            return;
        }

        $existe = DB::table('payment_method_types')
            ->where('name', self::NOMBRE)
            ->exists();

        if ($existe) {
            return;
        }

        $ahora = now();

        DB::table('payment_method_types')->insert([
            'name'       => self::NOMBRE,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    /**
     * No revierte nada, a proposito.
     *
     * Borrar la fila dejaria huerfanos los `payment_methods` que la referencian por
     * `payment_method_type_id`, y con ellos el cobro online del comercio. Un `down()` no puede
     * distinguir la fila que creo esta migracion de la que ya estaba en una base sembrada, asi
     * que la unica reversion segura es ninguna.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
