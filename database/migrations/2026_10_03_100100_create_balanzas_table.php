<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: las balanzas del comercio, para la lectura de tickets "por balanza" (misión
 * balanzas-configurables, 3/10/2026).
 *
 * Cada fila es una balanza que imprime tickets con un código que EMPIEZA con `prefijo` y que trae,
 * en los dígitos anteriores al verificador, un IMPORTE o un PESO (`tipo_dato`). El ticket se le
 * imputa a `article_id`. Reemplaza al artículo hardcodeado (6346) de la extensión vieja
 * `balanza_bar_code`: Panchito queda con una balanza '22' -> artículo Carniceria, importe.
 *
 * Se usa solo cuando el dueño tiene `users.tickets_de_balanza = 'balanzas'`. La regla de lectura
 * está entera en BalanzaHelper::leer_ticket_por_balanzas() (y la SPA la repite, igual, para leer
 * sin conexión).
 *
 * Molde: `cheque_bancos` (2026_09_21_100000). Catálogo por dueño, arranca vacío, sin foreign keys
 * físicas (regla del repo: el artículo puede borrarse y la balanza queda; la lectura lo detecta y
 * avisa con `balanza_sin_articulo`). Sin softDeletes, como el molde.
 */
class CreateBalanzasTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('balanzas')) {
            return;
        }

        Schema::create('balanzas', function (Blueprint $table) {
            $table->increments('id');

            /** Dueño del comercio: las balanzas son por cuenta, no globales. */
            $table->integer('user_id')->nullable()->index('balanzas_user_idx');

            /** Nombre libre para reconocerla en el ABM ("Balanza carnicería"). */
            $table->string('nombre', 80)->nullable();

            /**
             * Comienzo del código de sus tickets: solo dígitos, de 1 a 6 (se normaliza en
             * BalanzaController). Default '' solo para que la columna tenga default: una balanza
             * sin prefijo no se puede guardar por la API y la lectura la ignora.
             */
            $table->string('prefijo', 10)->default('');

            /** Artículo al que se le imputa el ticket. Sin foreign key física. */
            $table->integer('article_id')->nullable()->index('balanzas_article_idx');

            /** 'importe' (el código trae el precio) o 'peso' (el código trae la cantidad). */
            $table->string('tipo_dato', 10)->default('importe');

            /** Cuántos dígitos del código son el dato. NULL = 7 si es importe, 5 si es peso. */
            $table->unsignedTinyInteger('digitos')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('balanzas');
    }
}
