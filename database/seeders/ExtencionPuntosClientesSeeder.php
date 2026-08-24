<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Sistema de puntos para clientes" en la tabla
 * extencion_empresas (misión puntos-clientes-mvp).
 *
 * Esta extensión es EL interruptor del módulo, para la empresa entera: sin ella el grupo de
 * rutas de puntos devuelve 403, el reconciliador de acumulación sale en su primera línea
 * sin tocar la base, el comando puntos:vencer no hace ni un SELECT y la SPA no muestra el
 * panel de canje en VENDER. Es una pregunta distinta de "¿esta persona tiene permiso?",
 * que se resuelve adentro de cada controller.
 *
 * 🔴 EL SEEDER CREA LA FILA DEL CATÁLOGO, NO LA ASIGNACIÓN. La extensión queda APAGADA para
 * todos los comercios: prenderla es asignarla en `extencion_empresa_user`, que se hace desde
 * el admin, comercio por comercio.
 *
 * En producción se corre SOLO este seeder (nunca ExtencionSeeder, que hace create() en un
 * foreach y correrlo dos veces duplica el catálogo entero):
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionPuntosClientesSeeder
 */
class ExtencionPuntosClientesSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /*
         * firstOrCreate para que sea idempotente: si el slug ya existe (por ejemplo porque
         * ExtencionSeeder ya lo sembró en una instancia nueva), no genera un duplicado al
         * ejecutar el seeder más de una vez.
         */
        ExtencionEmpresa::firstOrCreate(
            ['slug' => 'puntos_clientes'],
            ['name' => 'Sistema de puntos para clientes']
        );
    }
}
