<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Asistente IA" en la tabla extencion_empresas.
 *
 * Esta extensión habilita, por empresa, el chat con el asistente de IA del
 * negocio: el botón flotante y el panel en la SPA, las rutas REST del chat en
 * la API y la creación automática de conversaciones desde las sugerencias de
 * stock. Con la extensión apagada no aparece el botón, las rutas devuelven 403
 * y las sugerencias siguen notificando exactamente como hoy.
 *
 * La extensión es el interruptor, no el cobro: como el chat va incluido en la
 * suscripción, el despliegue incluye activarla masivamente en las cuentas.
 *
 * En producción se corre SOLO este seeder (nunca ExtencionSeeder, que hace
 * create() en un foreach y correrlo dos veces duplica todo el catálogo):
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionAsistenteIaSeeder
 */
class ExtencionAsistenteIaSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /*
         * firstOrCreate para que sea idempotente: si el slug ya existe (por ejemplo
         * porque ExtencionSeeder ya lo sembró en una instancia nueva), no genera un
         * duplicado al ejecutar el seeder más de una vez.
         */
        ExtencionEmpresa::firstOrCreate(
            ['slug' => 'asistente_ia'],
            ['name' => 'Asistente IA']
        );
    }
}
