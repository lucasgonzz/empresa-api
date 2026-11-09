<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tours', function (Blueprint $table) {
            $table->unsignedBigInteger('import_history_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_tours', function (Blueprint $table) {
            $table->dropColumn('import_history_id');
        });
    }
};
