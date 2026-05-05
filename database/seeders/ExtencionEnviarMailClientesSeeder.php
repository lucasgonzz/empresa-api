<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Catálogo de extensión: envío de correo de notificación de venta al cliente (vender y listado ventas).
 * Idempotente: no duplica filas si el slug ya existe.
 */
class ExtencionEnviarMailClientesSeeder extends Seeder
{
    /**
     * Inserta la extensión (mismo slug que hasExtencion('enviar_mail_a_clientes') en la SPA).
     *
     * @return void
     */
    public function run()
    {
        /** Clave estable compartida con UserHelper::hasExtencion y con el front. */
        $slug = 'enviar_mail_a_clientes';

        /** Nombre mostrado al asignar la extensión al comercio. */
        $name = 'Enviar mail a clientes';

        ExtencionEmpresa::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }
}
