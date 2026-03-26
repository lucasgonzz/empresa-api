<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega nombre visible en UI; el encabezado del PDF sigue usando `label`.
 */
class AddNameToPdfColumnOptionsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_options', 'name')) {
            Schema::table('pdf_column_options', function (Blueprint $table) {
                $table->string('name', 120)->nullable()->after('model_name');
            });
        }

        DB::table('pdf_column_options')->whereNull('name')->update([
            'name' => DB::raw('`label`'),
        ]);

        try {
            DB::statement('ALTER TABLE pdf_column_options MODIFY name VARCHAR(120) NOT NULL');
        } catch (\Throwable $e) {
            /**
             * En instalaciones nuevas la columna ya puede ser NOT NULL; otros drivers pueden no soportar MODIFY.
             */
        }
    }

    public function down()
    {
        Schema::table('pdf_column_options', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
}
