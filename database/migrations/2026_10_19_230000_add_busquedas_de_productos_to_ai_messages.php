<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBusquedasDeProductosToAiMessages extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('ai_messages', 'busquedas_de_productos')) {
            Schema::table('ai_messages', function (Blueprint $table) {
                $table->json('busquedas_de_productos')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('ai_messages', 'busquedas_de_productos')) {
            Schema::table('ai_messages', function (Blueprint $table) {
                $table->dropColumn('busquedas_de_productos');
            });
        }
    }
}
