<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Prompt 260 (Fase 2 — precios/costos, Capa 2).
 *
 * Crea `sale_taxes` (impuestos sobre ventas, ej. IIBB) y su tabla pivot `article_sale_tax`
 * para el caso en que el impuesto no aplique a todos los artículos (`apply_to_all = false`).
 * Sin foreign keys (regla del workspace) — la relación se resuelve en Eloquent.
 */
class CreateSaleTaxesTable extends Migration
{
    /**
     * Ejecuta la migración: crea `sale_taxes` y la tabla pivot `article_sale_tax`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sale_taxes', function (Blueprint $table) {
            $table->id();

            // Dueño del impuesto (owner). Index simple, sin FK.
            $table->integer('user_id')->unsigned();
            $table->index('user_id', 'stx_user_idx');

            // Nombre visible del impuesto (ej: "IIBB Entre Ríos", "Tasa municipal")
            $table->string('name', 120);

            // Alícuota del impuesto, se traslada al precio con fórmula de división (no es costo)
            $table->decimal('percentage', 8, 4);

            // Si es true, el impuesto aplica a todos los artículos del usuario.
            // Si es false, aplica solo a los artículos vinculados en article_sale_tax.
            $table->boolean('apply_to_all')->default(true);

            // Permite desactivar el impuesto sin borrarlo
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });

        Schema::create('article_sale_tax', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id')->unsigned();
            $table->index('article_id', 'ast_article_idx');

            $table->integer('sale_tax_id')->unsigned();
            $table->index('sale_tax_id', 'ast_sale_tax_idx');

            $table->timestamps();
        });
    }

    /**
     * Revierte la migración: elimina `article_sale_tax` y `sale_taxes`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_sale_tax');
        Schema::dropIfExists('sale_taxes');
    }
}
