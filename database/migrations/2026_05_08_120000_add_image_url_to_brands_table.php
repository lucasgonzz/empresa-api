<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega soporte de imagen a marcas, alineado con el modelo SPA y el seeder de ferreteria.
 */
class AddImageUrlToBrandsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('brands', 'image_url')) {
            Schema::table('brands', function (Blueprint $table) {
                /** URL de imagen (externa o propia); text para no truncar URLs largas. */
                $table->text('image_url')->nullable()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('brands', 'image_url')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->dropColumn('image_url');
            });
        }
    }
}
