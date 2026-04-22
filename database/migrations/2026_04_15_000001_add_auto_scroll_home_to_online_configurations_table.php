<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAutoScrollHomeToOnlineConfigurationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->integer('auto_scroll_home')->nullable()->after('scroll_infinito_en_home');
            $table->integer('auto_scroll_home_init')->nullable()->after('auto_scroll_home');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn(['auto_scroll_home', 'auto_scroll_home_init']);
        });
    }
}
