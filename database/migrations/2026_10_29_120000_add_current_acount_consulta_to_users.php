<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCurrentAcountConsultaToUsers extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'current_acount_consulta')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('current_acount_consulta')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'current_acount_consulta')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('current_acount_consulta');
            });
        }
    }
}
