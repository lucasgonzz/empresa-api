<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accesos por link a un informe del mostrador (misión asistente-por-whatsapp, 16/9/2026).
 *
 * Lucas pidió que el informe de la mañana llegue por WhatsApp como "resumen + link", y que ese
 * link abra el informe en el celular SIN pedir usuario y contraseña: el dueño está en la calle y
 * no va a tipear una contraseña para leer cuánto vendió ayer.
 *
 * 🔴 EL TOKEN NUNCA SE GUARDA EN CLARO. Se guarda solo su hash, igual que demo_ingreso_tokens
 * (`token_hash` de 64, unique): quien lea esta tabla —un backup, un dump, un empleado con acceso a
 * la base— no puede abrir ningún informe. El token en claro existe una sola vez, en el instante en
 * que se emite, y viaja al admin para armar la URL.
 *
 * `expira_at` no es nullable: un link a los números del negocio que vale para siempre es un link
 * que dentro de un año sigue abriendo la facturación de hoy. MostradorAccesoHelper lo emite a 7
 * días.
 *
 * `usado_at` se sella la primera vez que se abre y NO invalida el link (a diferencia de un token
 * de un solo uso): el dueño abre el informe, lo cierra y lo vuelve a abrir desde el mismo WhatsApp
 * un rato después. Sirve para saber si el link se usó.
 *
 * ⚠️ POR QUÉ `timestamp` Y NO `dateTime` EN `expira_at`, que es un NOT NULL. Con
 * `explicit_defaults_for_timestamp` en OFF, MySQL le agrega solo `DEFAULT CURRENT_TIMESTAMP ON
 * UPDATE CURRENT_TIMESTAMP` a la PRIMERA columna TIMESTAMP NOT NULL de una tabla. Acá eso sería
 * grave y mudo: `MostradorAccesoHelper::resolver()` hace un `save()` para sellar `usado_at`, así
 * que el `ON UPDATE` le correría el vencimiento en cada apertura.
 *
 * Se deja `timestamp` igual, y no por comodidad: `demo_ingreso_tokens` tiene EXACTAMENTE el mismo
 * patrón —`expires_at` TIMESTAMP NOT NULL como primer timestamp de la tabla, con `revoked_at` que
 * se escribe después— y ya vive en producción en los 40+ clientes desde el 27/7/2026. Medido el
 * 16/9/2026 en el MySQL 8 local: `explicit_defaults_for_timestamp = 1` y las dos tablas quedan con
 * `EXTRA` vacío, sin ningún `ON UPDATE`. Cambiar solo esta tabla a `dateTime` la dejaría distinta
 * de la que ya está probada en producción sin ninguna evidencia de que haga falta.
 *
 * 🔴 Si algún día aparece un cliente con esa variable en OFF, el síntoma es "el link del informe
 * se vence apenas lo abro" y hay que mirar `SHOW CREATE TABLE` de ESTA tabla Y de
 * `demo_ingreso_tokens`: las dos tendrían el mismo problema y se arreglan juntas.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateMostradorAccesosTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('mostrador_accesos')) {
            return;
        }

        Schema::create('mostrador_accesos', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* Informe que abre este link (mostrador_reportes.id) */
            $table->unsignedBigInteger('mostrador_reporte_id');

            /* Dueño de la cuenta, para filtrar sin join y para auditar */
            $table->unsignedBigInteger('user_id');

            /* hash del token que viaja en la URL (nunca se guarda en claro de este lado) */
            $table->string('token_hash', 64)->unique();

            /* Vencimiento del link. Nunca null: un acceso a los números del negocio siempre vence */
            $table->timestamp('expira_at');

            /* Primera apertura. No invalida el link: es traza */
            $table->timestamp('usado_at')->nullable();

            $table->timestamps();

            /* Los accesos vivos de un informe */
            $table->index(['mostrador_reporte_id', 'expira_at'], 'ma_reporte_expira_idx');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mostrador_accesos');
    }
}
