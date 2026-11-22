<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CotizacionPorArticulo extends Migration
{
    public function up()
    {
        foreach (['article_sale', 'article_budget'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                // Guarda de reintento: cada columna se crea sólo si falta (una vuelta atrás pudo dejar algunas).
                if (!Schema::hasColumn($tabla, 'cotizacion_aplicada')) {
                    $table->decimal('cotizacion_aplicada', 20, 6)->nullable();
                }
                if (!Schema::hasColumn($tabla, 'cotizacion_origen')) {
                    $table->string('cotizacion_origen', 16)->nullable();
                }
                if (!Schema::hasColumn($tabla, 'moneda_base_precio')) {
                    $table->unsignedTinyInteger('moneda_base_precio')->nullable();
                }
                if (!Schema::hasColumn($tabla, 'moneda_base_costo')) {
                    $table->unsignedTinyInteger('moneda_base_costo')->nullable();
                }
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
