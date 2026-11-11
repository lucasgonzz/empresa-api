<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Le prende la extensión de Soporte (`support_chat`) al dueño de una instancia de PRUEBA GRATUITA que ya
 * existe (misión prueba-ayuda-unificada-en-soporte, E2, 10/11/2026).
 *
 * Las instancias nuevas ya nacen con ella: DemoSetupHelper la suma cuando el setup arma una prueba
 * (EXTENCION_SOPORTE_DE_LA_PRUEBA). Este seeder es para las que se armaron antes —el pool que ya está
 * esperando lead y las pruebas en curso—, y va como tarea del despliegue, en cada instancia de prueba:
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionSupportChatEnPruebaSeeder --force
 *
 * 🔴 SOLO EN UNA INSTANCIA DE PRUEBA (`FOR_USER=prueba`). En un cliente real o en la demo no hace nada:
 * a un cliente Soporte se le prende a mano, y la demo tiene que quedar idéntica. Es idempotente:
 * busca la fila por slug antes de crearla (no ExtencionSeeder, que duplica el catálogo) y syncWithoutDetaching.
 */
class ExtencionSupportChatEnPruebaSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        if (! PruebaHelper::puede_haber_prueba()) {
            Log::info('ExtencionSupportChatEnPruebaSeeder: no es una instancia de prueba, no se toca nada.');

            return;
        }

        $dueno = PruebaHelper::dueno();

        if (is_null($dueno)) {
            Log::info('ExtencionSupportChatEnPruebaSeeder: la instancia todavía no tiene dueño.');

            return;
        }

        // Sin firstOrCreate: ExtencionEmpresa no tiene `slug` en $fillable y, corrido suelto (sin el
        // unguard de db:seed), rebotaría con MassAssignmentException.
        $extension = ExtencionEmpresa::where('slug', DemoSetupHelper::EXTENCION_SOPORTE_DE_LA_PRUEBA)->first();

        if (is_null($extension)) {
            $extension = new ExtencionEmpresa();
            $extension->name = 'Chat de soporte';
            $extension->slug = DemoSetupHelper::EXTENCION_SOPORTE_DE_LA_PRUEBA;
            $extension->save();
        }

        $dueno->extencions()->syncWithoutDetaching([$extension->id]);
    }
}
