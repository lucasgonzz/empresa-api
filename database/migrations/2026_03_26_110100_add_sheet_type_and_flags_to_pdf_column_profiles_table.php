<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSheetTypeAndFlagsToPdfColumnProfilesTable extends Migration
{
    /**
     * Agrega tipo de hoja y flags de impresión al perfil PDF.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            /**
             * FK al tipo de hoja para separar A4/tickets.
             */
            $table->unsignedBigInteger('sheet_type_id')->nullable()->after('printable_width_mm');
            /**
             * Indica si el perfil es de comprobante fiscal AFIP.
             */
            $table->boolean('is_afip_ticket')->default(false)->after('sheet_type_id');
            /**
             * Define si los totales se repiten en cada hoja.
             */
            $table->boolean('show_totals_on_each_page')->default(false)->after('is_afip_ticket');
            $table->index('sheet_type_id', 'pcp_sheet_type_idx');
        });

        /**
         * Backfill inicial para que perfiles existentes queden asociados a A4.
         */
        DB::table('pdf_column_profiles')->update([
            'sheet_type_id' => DB::raw("(SELECT id FROM sheet_types WHERE name = 'A4' LIMIT 1)"),
        ]);

        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->foreign('sheet_type_id', 'pcp_sheet_type_fk')
                ->references('id')
                ->on('sheet_types')
                ->onDelete('set null');
        });
    }

    /**
     * Revierte nuevos campos y relación agregada al perfil PDF.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pdf_column_profiles', function (Blueprint $table) {
            $table->dropForeign('pcp_sheet_type_fk');
            $table->dropIndex('pcp_sheet_type_idx');
            $table->dropColumn(['sheet_type_id', 'is_afip_ticket', 'show_totals_on_each_page']);
        });
    }
}
