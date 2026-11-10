<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAdminReferenceToSupportTickets extends Migration
{
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_ticket_id')->nullable();
            $table->dateTime('remote_last_message_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['admin_ticket_id', 'remote_last_message_at']);
        });
    }
}
