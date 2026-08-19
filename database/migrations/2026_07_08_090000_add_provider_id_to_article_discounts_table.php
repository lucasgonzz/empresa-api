<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 305 (Fase 2 — precios/costos).
 *
 * Base del rediseño de descuentos de proveedor: agrega la columna `provider_id` (nullable)
 * a `article_discounts` para poder atar cada descuento al proveedor que lo originó y así
 * poder barrerlos/reemplazarlos cuando el proveedor del artículo cambia. Es aditiva y sin
 * backfill: los descuentos existentes quedan con `provider_id = null` (= "manuales del
 * usuario", nunca se tocan automáticamente). La lógica que usa esta columna llega en 306+.
 *
 * Sin foreign key (motor MyISAM, convención del repo): solo columna + index para
 * rendimiento en filtros/joins por proveedor.
 *
 * Alcance: SOLO `article_discounts`. NO se toca `article_discount_blancos` (fuera de
 * alcance de este prompt).
 */
class AddProviderIdToArticleDiscountsTable extends Migration
{
    /**
     * Ejecuta la migración: agrega `provider_id` (unsignedBigInteger nullable) con index
     * a `article_discounts`. No hace backfill.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_discounts', function (Blueprint $table) {
            // Proveedor que originó el descuento (null = descuento manual del usuario, se
            // preserva siempre; solo los descuentos con provider_id seteado son candidatos
            // a ser barridos/reemplazados cuando cambia el proveedor del artículo).
            $table->unsignedBigInteger('provider_id')->nullable()->after('temporal_id');
            $table->index('provider_id', 'adi_provider_idx');
        });
    }

    /**
     * Revierte la migración: elimina el index y la columna `provider_id`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_discounts', function (Blueprint $table) {
            $table->dropIndex('adi_provider_idx');
            $table->dropColumn('provider_id');
        });
    }
}
