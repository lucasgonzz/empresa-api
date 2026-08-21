<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot entre el programa de puntos y las listas de precio en las que aplica.
 *
 * NOMBRE DE LA TABLA: para el modelo `SistemaDePuntos`, Laravel derivaría
 * 'price_type_sistema_de_punto' (singular). Lucas fijó 'price_type_sistema_de_puntos'.
 * Por eso la relación belongsToMany del modelo declara tabla y las DOS claves a mano: si
 * se dejan los defaults, Eloquent busca una tabla que no existe.
 *
 * SEMÁNTICA DEL FILTRO, que vale la pena tener escrita acá porque decide plata: si el
 * programa NO tiene ninguna fila en esta tabla, aplica a TODAS las listas (el "sin filtro"
 * natural). Si tiene al menos una, solo suman los renglones cuya lista efectiva esté acá.
 *
 * Sin foreign keys físicas, igual que todo el schema.
 */
class CreatePriceTypeSistemaDePuntosTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('price_type_sistema_de_puntos')) {
            return;
        }

        Schema::create('price_type_sistema_de_puntos', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* `sistemas_de_puntos.id` es $table->id() -> bigint unsigned */
            $table->unsignedBigInteger('sistema_de_puntos_id');

            /*
             * 🔴 unsignedBigInteger y NO unsignedInteger: `price_types.id` es bigint unsigned.
             * Es una columna NUEVA, así que espejar la fuente exacta cuesta cero. Es la
             * asimetría deliberada con el INT UNSIGNED de
             * 2026_08_22_100000_widen_price_type_id_in_sales_table.php, que es una columna
             * vieja y caliente de `sales` donde duplicar bytes sí tiene costo. El porqué de
             * cada una queda escrito en su propia migración.
             */
            $table->unsignedBigInteger('price_type_id');

            // 🔴 NO BORRAR porque "no se usa": es un hedge deliberado (decisión de Lucas, 21/8/2026).
            // null = se aplica la tasa global del programa. La interfaz del MVP no la expone a proposito;
            // exponerla despues es agregar un `properties_to_set` en src/models/sistema_de_puntos.js,
            // no una migracion con backfill sobre las bases de los 40 y pico de clientes.
            $table->decimal('multiplicador', 6, 2)->nullable();

            $table->timestamps();

            /*
             * Una lista no puede estar dos veces en el mismo programa. El nombre va explícito
             * porque el que genera Laravel para estas dos columnas pasa los 64 caracteres que
             * MySQL admite para un identificador de índice.
             */
            $table->unique(['sistema_de_puntos_id', 'price_type_id'], 'price_type_sistema_de_puntos_unique');

            /*
             * La pregunta caliente del motor es al revés de como se lee el ABM: "¿esta lista
             * está habilitada?", con el price_type_id del renglón en la mano.
             */
            $table->index(['price_type_id'], 'price_type_sistema_de_puntos_price_type_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('price_type_sistema_de_puntos');
    }
}
