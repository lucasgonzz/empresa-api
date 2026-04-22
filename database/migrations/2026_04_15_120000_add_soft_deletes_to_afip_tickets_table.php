<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSoftDeletesToAfipTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('afip_tickets', 'deleted_at')) {
            Schema::table('afip_tickets', function (Blueprint $table) {
                $table->softDeletes();
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
        if (Schema::hasColumn('afip_tickets', 'deleted_at')) {
            Schema::table('afip_tickets', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
}
