<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migración Prompt 260 (Fase 2 — precios/costos, Capa 1).
 *
 * Agrega la columna `tipo` a los descuentos y recargos de artículo (y a sus tablas espejo
 * "en blanco") para distinguir la naturaleza contable de cada descuento/recargo. Es aditiva:
 * columna nullable + backfill no destructivo de las filas existentes a 'otro'. No modifica
 * ningún cálculo de precio (eso lo hace el prompt 261).
 *
 * Valores válidos documentados (ver también las constantes en los modelos):
 * - article_surchages / article_surchage_blancos: 'transporte', 'seguro',
 *   'arancel_importacion', 'otro'.
 * - article_discounts / article_discount_blancos: 'bonificacion_proveedor', 'otro'.
 */
class AddTipoToArticleDiscountsAndSurchagesTables extends Migration
{
    /**
     * Ejecuta la migración: agrega la columna `tipo` (nullable) a las 4 tablas y
     * backfillea las filas existentes con 'otro' para no romper compatibilidad.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_discounts', function (Blueprint $table) {
            // Naturaleza contable del descuento (ej: bonificacion_proveedor, otro)
            $table->string('tipo', 60)->nullable()->after('amount');
        });
        Schema::table('article_surchages', function (Blueprint $table) {
            // Naturaleza contable del recargo (ej: transporte, seguro, arancel_importacion, otro)
            $table->string('tipo', 60)->nullable()->after('amount');
        });
        Schema::table('article_discount_blancos', function (Blueprint $table) {
            // Mismo campo que article_discounts, para mantener simetría entre tabla normal y blanco
            $table->string('tipo', 60)->nullable()->after('percentage');
        });
        Schema::table('article_surchage_blancos', function (Blueprint $table) {
            // Mismo campo que article_surchages, para mantener simetría entre tabla normal y blanco
            $table->string('tipo', 60)->nullable()->after('percentage');
        });

        // Backfill no destructivo: todo lo existente queda como 'otro' (no cambia ningún cálculo,
        // la lógica de precios todavía no mira este campo).
        DB::table('article_discounts')->whereNull('tipo')->update(['tipo' => 'otro']);
        DB::table('article_surchages')->whereNull('tipo')->update(['tipo' => 'otro']);
        DB::table('article_discount_blancos')->whereNull('tipo')->update(['tipo' => 'otro']);
        DB::table('article_surchage_blancos')->whereNull('tipo')->update(['tipo' => 'otro']);
    }

    /**
     * Revierte la migración: elimina la columna `tipo` de las 4 tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_discounts', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        Schema::table('article_surchages', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        Schema::table('article_discount_blancos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        Schema::table('article_surchage_blancos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
}
