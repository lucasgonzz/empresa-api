<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixToProvinciasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
       

        if (Schema::hasColumn('provincias', 'nombre')) {
            Schema::table('provincias', function (Blueprint $table) {
                $table->dropColumn('nombre');
            });
        }
        if (Schema::hasColumn('provincias', 'num')) {
            Schema::table('provincias', function (Blueprint $table) {
                $table->integer('num')->nullable()->change();
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
        Schema::table('provincias', function (Blueprint $table) {
            //
        });
    }
}
