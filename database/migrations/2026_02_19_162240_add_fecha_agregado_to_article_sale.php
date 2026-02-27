<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFechaAgregadoToArticleSale extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Pivot article_sale
        Schema::table('article_sale', function (Blueprint $table) {
            if (!Schema::hasColumn('article_sale', 'fecha_agregado')) {
                $table->timestamp('fecha_agregado')->nullable();
            }
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_sale', function (Blueprint $table) {
            //
        });
    }
}
