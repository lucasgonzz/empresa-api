<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina columnas obsoletas del catálogo pdf_column_options.
 */
class DropKeyAndAllowFadeFromPdfColumnOptionsTable extends Migration
{
    public function up()
    {
        Schema::table('pdf_column_options', function (Blueprint $table) {
            $columns_to_drop = [];
            if (Schema::hasColumn('pdf_column_options', 'key')) {
                $columns_to_drop[] = 'key';
            }
            if (Schema::hasColumn('pdf_column_options', 'allow_fade_when_truncated')) {
                $columns_to_drop[] = 'allow_fade_when_truncated';
            }
            if (count($columns_to_drop)) {
                $table->dropColumn($columns_to_drop);
            }
        });
    }

    public function down()
    {
        Schema::table('pdf_column_options', function (Blueprint $table) {
            $table->string('key', 80)->after('model_name');
            $table->boolean('allow_fade_when_truncated')->default(true)->after('allow_wrap_content');
        });
    }
}
