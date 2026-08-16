<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Motor de ofertas por cliente" en la tabla
 * extencion_empresas.
 *
 * Esta extensión habilita, por empresa, el motor de ofertas personalizadas: la
 * pantalla de ofertas en la SPA (módulo IA y Tienda Online → Promociones), las
 * rutas REST en la API, el comando automático ofertas:generar y —del otro lado
 * de la base compartida— las promociones que la tienda le muestra al comprador.
 * Con la extensión apagada no aparecen ni el módulo ni las rutas (403) y nada
 * del resto del sistema cambia de comportamiento.
 *
 * Extensión propia, no reusar sugerencias_compras ni sugerencias_inteligentes:
 * es funcionalidad vendible aparte, mismo precedente que asistente_ia.
 *
 * 🔴 En producción se corre SOLO este seeder. NUNCA ExtencionSeeder: ese hace
 * create() en un foreach sobre todo el catálogo, así que correrlo una segunda
 * vez sobre una base que ya lo tiene duplica TODAS las extensiones, no solo
 * esta. Este usa firstOrCreate y es idempotente:
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionMotorDeOfertasSeeder
 */
class ExtencionMotorDeOfertasSeeder extends Seeder
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
            ['slug' => 'motor_de_ofertas'],
            ['name' => 'Motor de ofertas por cliente']
        );
    }
}
