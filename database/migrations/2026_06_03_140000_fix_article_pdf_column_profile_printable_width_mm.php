<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige perfiles article creados con printable_width_mm=200 (ancho neto) cuando el validador
 * espera ancho imprimible bruto (210) y descuenta margin_mm por cada lado.
 */
class FixArticlePdfColumnProfilePrintableWidthMm extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        DB::table('pdf_column_profiles')
            ->where('model_name', 'article')
            ->where('paper_width_mm', 210)
            ->where('printable_width_mm', 200)
            ->where('margin_mm', 5)
            ->update(['printable_width_mm' => 210]);
    }

    /**
     * @return void
     */
    public function down()
    {
        DB::table('pdf_column_profiles')
            ->where('model_name', 'article')
            ->where('paper_width_mm', 210)
            ->where('printable_width_mm', 210)
            ->where('margin_mm', 5)
            ->update(['printable_width_mm' => 200]);
    }
}
