<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuentos de venta vinculados a un cliente (misión descuentos-recargos-por-cliente, 23/9/2026).
 *
 * La escribe la ficha del cliente del ERP y la leen Vender (los activa solos al elegir el cliente)
 * y `tienda-api` (ajusta los precios del comprador vinculado a ese cliente). Es parte del
 * CONTRATO entre empresa y tienda, que comparten la base.
 *
 * Reemplaza en la práctica a la columna vieja `discounts.client_id` (un descuento para UN
 * cliente), que no se toca ni se borra: nadie la escribe, pero Vender la sigue leyendo.
 *
 * Sin foreign keys físicas, como todo el esquema, y con guard `hasTable` para que sea segura de
 * re-ejecutar. El nombre sigue la convención de Laravel para `belongsToMany` (orden alfabético,
 * singular): así la relación no necesita segundo argumento. Ojo que `tienda-api` NO tiene
 * migraciones y lee esta tabla por nombre: renombrarla rompe la tienda en silencio.
 */
class CreateClientDiscountTable extends Migration
{
    /**
     * Crea la tabla si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_discount')) {
            return;
        }

        Schema::create('client_discount', function (Blueprint $table) {
            $table->id();
            $table->integer('client_id')->unsigned()->index();
            $table->integer('discount_id')->unsigned();
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
        Schema::dropIfExists('client_discount');
    }
}
