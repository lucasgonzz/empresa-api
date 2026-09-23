<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recargos de venta vinculados a un cliente (misión descuentos-recargos-por-cliente, 23/9/2026).
 *
 * Gemela de `client_discount`: la escribe la ficha del cliente y la leen Vender y `tienda-api`.
 *
 * Sin foreign keys físicas, como todo el esquema, y con guard `hasTable` para que sea segura de
 * re-ejecutar. El nombre sigue la convención de Laravel para `belongsToMany` (orden alfabético,
 * singular): así la relación no necesita segundo argumento. Ojo que `tienda-api` NO tiene
 * migraciones y lee esta tabla por nombre: renombrarla rompe la tienda en silencio.
 */
class CreateClientSurchageTable extends Migration
{
    /**
     * Crea la tabla si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_surchage')) {
            return;
        }

        Schema::create('client_surchage', function (Blueprint $table) {
            $table->id();
            $table->integer('client_id')->unsigned()->index();
            $table->integer('surchage_id')->unsigned();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_surchage');
    }
}
