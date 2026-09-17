<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `nombre` a provider_discounts: el nombre o descripcion que el comercio le pone a cada
 * bonificacion de la ficha del proveedor ("Bonificacion por volumen", "Acuerdo anual").
 *
 * 🔴 POR QUE EXISTE. Hasta hoy `provider_discounts` tenia una sola columna util: `percentage`. Un
 * proveedor con tres bonificaciones distintas mostraba tres numeros sueltos, sin forma de saber
 * cual era cual ni por que estaba negociada. Y cuando esos descuentos se copian al articulo
 * (`article_discounts` tagueados), el articulo tampoco tenia como nombrarlos.
 *
 * Nullable a proposito: todas las filas que ya existen se quedan sin nombre y nadie puede asumir
 * que este cargado. Es texto para mostrar, nunca un dato con el que se decida nada.
 *
 * Sin foreign keys y con guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema
 * distintos, y la misma migracion corre en todas. Mismo estilo que las de 2026_09_04.
 *
 * La base es compartida con `tienda`, que no escribe esta tabla. Migracion aditiva, compatible
 * hacia atras en las dos direcciones; nada se renombra ni se saca.
 */
class AddNombreToProviderDiscountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('provider_discounts', 'nombre')) {
            return;
        }

        Schema::table('provider_discounts', function (Blueprint $table) {
            $table->string('nombre', 191)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('provider_discounts', 'nombre')) {
            return;
        }

        Schema::table('provider_discounts', function (Blueprint $table) {
            $table->dropColumn('nombre');
        });
    }
}
