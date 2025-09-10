<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AgregarColumnaOcultaAArticleVariants extends Migration
{
    public function up()
    {
        Schema::table('article_variants', function (Blueprint $table) {
            $table->boolean('oculta')->default(false)->after('stock');
        });
    }

    public function down()
    {
        Schema::table('article_variants', function (Blueprint $table) {
            $table->dropColumn('oculta');
        });
    }
}
