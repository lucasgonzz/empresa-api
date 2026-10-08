<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equivalencias recordadas de la importación de Excel (misión importacion-relaciones-parecidas,
 * 8/10/2026).
 *
 * Cuando el comerciante confirma en el paso 3 que "Del norte" del Excel es la categoría que ya
 * tiene cargada como "DEL NORTE", esa decisión queda acá: la próxima importación la trae
 * preseleccionada ("confirmada antes") y el worker la aplica aunque el camino no tenga paso 3
 * (AdminSync, import clásico).
 *
 * - `tipo`: proveedor | categoria | sub_categoria | marca | provincia | localidad | vendedor |
 *   tipo_de_precio.
 * - `contexto_id`: la categoría para `sub_categoria` y la provincia para `localidad` con
 *   provincia (las dos se comparan DENTRO de su padre); 0 para el resto.
 * - `valor`: el texto tal como vino en el Excel (para mostrarlo); `valor_clave`: el mismo texto
 *   con trim y los espacios internos colapsados, RESPETANDO mayúsculas y tildes, que es por el
 *   que se busca.
 *
 * 🔴 `valor_clave` va con collation utf8mb4_bin, y no con la de la tabla: con la collation por
 * defecto (que ignora mayúsculas y tildes) "DEL NORTE"→usar y "Del norte"→crear del mismo Excel se
 * pisaban en la misma fila del índice único, y "pintureria"/"pinturería" también. Una equivalencia
 * recuerda la decisión sobre ESE texto, no sobre todos los que se le parecen: para lo parecido
 * está el paso 3, que vuelve a preguntar.
 *
 * 🔴 `valor_clave` es string(191) y no 255 a propósito: forma parte del índice único, y con
 * utf8mb4 una columna de 255 son 1020 bytes, más que los 767 que acepta un índice en las tablas
 * con formato COMPACT (el mismo motivo del `Schema::defaultStringLength(191)` del proyecto).
 * EquivalenciasResolver::clave_guardable() recorta con el mismo largo al guardar y al buscar.
 *
 * Sin foreign keys físicas, como el resto del schema.
 */
class CreateImportEquivalenciasTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('import_equivalencias')) {
            return;
        }

        Schema::create('import_equivalencias', function (Blueprint $table) {

            $table->bigIncrements('id');

            /* Dueño del comercio (users con owner_id null): las equivalencias son del comercio. */
            $table->unsignedBigInteger('user_id')->index();

            $table->string('tipo', 40);

            /* category_id para sub_categoria, provincia_id para localidad con provincia, 0 el resto. */
            $table->unsignedBigInteger('contexto_id')->default(0);

            /* El valor como vino en el Excel. */
            $table->string('valor', 255);

            /* EquivalenciasResolver::clave_guardable($valor). Binaria: ver el comentario de la clase. */
            $table->string('valor_clave', 191)->collation('utf8mb4_bin');

            /* Id del modelo (categoría, marca, proveedor, provincia...) al que equivale. */
            $table->unsignedBigInteger('model_id');

            $table->timestamps();

            $table->unique(['user_id', 'tipo', 'contexto_id', 'valor_clave'], 'import_equivalencias_unica');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_equivalencias');
    }
}
