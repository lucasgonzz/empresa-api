<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('support_tickets', 'desde_agentes')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->boolean('desde_agentes')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('desde_agentes');
        });
    }
};
