<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CotizacionPorArticulo extends Migration
{
    public function up()
    {
        foreach (['article_sale', 'article_budget'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->decimal('cotizacion_aplicada', 20, 6)->nullable();
                $table->string('cotizacion_origen', 16)->nullable();
                $table->unsignedTinyInteger('moneda_base_precio')->nullable();
                $table->unsignedTinyInteger('moneda_base_costo')->nullable();
            });
        }
    }

    public function down()
    {
        foreach (['article_sale', 'article_budget'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['cotizacion_aplicada', 'cotizacion_origen', 'moneda_base_precio', 'moneda_base_costo']);
            });
        }
    }
}
