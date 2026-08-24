<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migracion: crea demo_tracking_config, la fila unica que configura el canal de eventos
 * de una instancia de demo (mision 50).
 *
 * La tabla se crea en TODAS las instancias, incluidas las de los ~40 clientes reales,
 * porque las migraciones son las mismas para todos. Lo que distingue a una demo es que
 * la fila EXISTA: la escribe DemoSetupHelper::run() con las claves que manda admin-api
 * en el payload del setup. En un cliente real la tabla queda vacia para siempre y
 * DemoTrackingConfigHelper::hay_canal() devuelve false.
 *
 * El canal se autoconfigura por payload y no por variables de entorno a proposito: el
 * modo de falla de depender del .env por instancia ya se pago con el panel de novedades.
 *
 * Sin foreign keys fisicas, siguiendo el estilo del resto del schema.
 */
class CreateDemoTrackingConfigTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('demo_tracking_config')) {
            return;
        }

        Schema::create('demo_tracking_config', function (Blueprint $table) {
            $table->id();

            /**
             * Token que emite admin-api y que ES el identificador del lead del otro lado.
             * Viaja como header X-Demo-Eventos-Key en cada push. Es un secreto: nunca sale
             * en una respuesta HTTP ni en un log (ver DemoTrackingConfigHelper).
             */
            $table->string('eventos_token', 64);

            /** URL completa del canal de ingesta del admin (POST). */
            $table->string('eventos_url');

            /**
             * El plan de la demo ya resuelto y congelado del lado del admin (mision 48).
             * La instancia no lo recalcula ni lo interpreta: lo guarda para que el panel
             * del lead (mision 51) lo pueda leer sin volver a pedirselo al admin.
             */
            $table->json('plan')->nullable();

            /**
             * Mapa slot_id => url de los videos. Va aparte del plan porque las URLs se
             * cargan despues y no pueden quedar congeladas adentro de la foto del plan.
             */
            $table->json('media_urls')->nullable();

            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('demo_tracking_config');
    }
}
