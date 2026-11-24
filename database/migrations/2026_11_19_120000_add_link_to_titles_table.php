<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link opcional de los banners de la tienda (pedido de Truvari, 19/11/2026): si tiene link, el
 * banner se puede clickear (una categoria, un producto, una promo o una pagina externa).
 */
class AddLinkToTitlesTable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('titles', 'link')) {
            return;
        }
        Schema::table('titles', function (Blueprint $table) {
            $table->string('link', 500)->nullable();
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('titles', 'link')) {
            return;
        }
        Schema::table('titles', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
}
