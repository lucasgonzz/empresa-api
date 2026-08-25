<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migracion: crea business_hours_configs, el ESPEJO local del horario comercial que empuja
 * admin-api (ClientScheduleSyncService) por PUT api/admin-sync/business-hours.
 *
 * Por que una tabla nueva y no columnas en online_configurations: esa tabla es la config del
 * comercio de cara al comprador y la COMPARTE tienda-api sobre la misma base, no garantiza una
 * fila por usuario, y la escribe la SPA de empresa. El horario tiene un unico escritor (el admin)
 * y necesita unicidad por owner garantizada por el motor, que online_configurations no da: su
 * controller hace where('user_id')->get(), en plural, y no hay unique en el schema.
 *
 * ⚠️ Lo que NO es motivo (verificado el 25/8/2026, para que nadie lo cite como precedente):
 * "un guardado de la pantalla de tienda pisaria el horario". No pasaria:
 * OnlineConfigurationController::update() asigna campo por campo, no $request->all(), asi que
 * una columna nueva no se pisaria sola. El motivo real es la falta de unicidad y la base
 * compartida con tienda-api.
 *
 * Reglas de esta tabla:
 *
 *  - Sin foreign keys fisicas, siguiendo el estilo del resto del schema.
 *
 *  - El unique('user_id') es el respaldo del motor a la idempotencia del updateOrCreate del
 *    controller. El push llega por job encolado y se puede repetir con el mismo contenido
 *    (boton de reintento, guardados sucesivos): N pushes iguales tienen que dejar UNA fila.
 *    Nunca va a haber dos horarios por owner: el contrato es una semana por comercio.
 *
 *  - `semana` y `dias_crudos` son columnas json que guardan el array TAL COMO LLEGO, verbatim,
 *    sin re-mapear campo por campo. Es a proposito: asi las subclaves que el admin agregue
 *    adentro de un dia (feriados, medio dia, lo que sea) quedan guardadas sin necesidad de
 *    migracion. Solo se ignoran las claves desconocidas de NIVEL 1, que es exactamente la
 *    compatibilidad hacia atras que pide el contrato. `json` ya se usa en el repo
 *    (demo_tracking_config.plan), asi que el MySQL de la flota lo soporta.
 *
 *  - `configurado` en false significa "NO HAY DATO", jamas "cerrado". Es el tercer estado del
 *    resolvedor del admin cruzando el cable: un comercio que todavia no cargo su horario no
 *    esta cerrado, no se sabe. Confundirlos le diria a un comprador que el comercio esta
 *    cerrado un martes a las 10.
 *
 *  - NO hay columna `payload` con el body crudo entero, a proposito: seria una segunda copia
 *    del mismo dato en la misma fila, o sea dos fuentes de verdad que pueden divergir.
 */
class CreateBusinessHoursConfigsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('business_hours_configs')) {
            return;
        }

        Schema::create('business_hours_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');                // espeja users.id (increments() -> int unsigned)
            $table->string('timezone', 64)->nullable();        // 'America/Argentina/Buenos_Aires'
            $table->string('actualizado_en', 40)->nullable();  // ISO8601 crudo del admin, tal como llego
            $table->boolean('configurado')->default(0);        // false = NO HAY DATO, nunca "cerrado"
            $table->json('semana')->nullable();                // los 7 dias YA RESUELTOS, verbatim
            $table->json('dias_crudos')->nullable();           // comodidad de lectura, NO fuente de verdad
            $table->timestamp('recibido_at')->nullable();      // cuando llego el ultimo push
            $table->timestamps();

            $table->unique('user_id', 'bhc_user_id_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('business_hours_configs');
    }
}
