<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía la precisión de cost en article_provider_order para permitir hasta 4 decimales.
 * Alineado con el formateo variable en empresa-spa (2 decimales por defecto, 4 si el usuario los usa).
 */
class ExtendCostDecimalsOnArticleProviderOrderTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            $table->decimal('cost', 12, 4)->nullable()->change();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('article_provider_order', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->change();
        });
    }
}
