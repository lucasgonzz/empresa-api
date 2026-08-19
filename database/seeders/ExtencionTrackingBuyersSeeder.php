<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Seguimiento de compradores en la tienda" en la
 * tabla extencion_empresas (misión tracking-buyers-tienda).
 *
 * Esta extensión es EL interruptor del tracking, y lo es de las dos puntas a la vez:
 * el SPA de la tienda no arma ni un evento sin ella (la lee del payload de commerce),
 * la ingesta de tienda-api no escribe ni una fila sin ella, y los dos comandos
 * agendados de empresa-api salen en su primera línea sin tocar la base. Con la
 * extensión apagada, la tienda navega exactamente como hoy y no se manda un solo
 * request de tracking.
 *
 * El front y el back leen la MISMA extensión por el MISMO slug, y si divergen manda
 * el back: el guard del SPA es una optimización de tráfico, el del helper de la API
 * es el que decide.
 *
 * En producción se corre SOLO este seeder (nunca ExtencionSeeder, que hace create()
 * en un foreach y correrlo dos veces duplica todo el catálogo):
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionTrackingBuyersSeeder
 */
class ExtencionTrackingBuyersSeeder extends Seeder
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
            ['slug' => 'tracking_buyers'],
            ['name' => 'Seguimiento de compradores en la tienda']
        );
    }
}
