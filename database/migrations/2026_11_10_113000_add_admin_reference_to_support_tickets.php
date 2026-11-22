<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAdminReferenceToSupportTickets extends Migration
{
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('support_tickets', 'admin_ticket_id')) {
                $table->unsignedBigInteger('admin_ticket_id')->nullable();
            }
            if (!Schema::hasColumn('support_tickets', 'remote_last_message_at')) {
                $table->dateTime('remote_last_message_at')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['admin_ticket_id', 'remote_last_message_at']);
        });
    }
}
