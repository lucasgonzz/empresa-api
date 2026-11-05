<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImportacionesDePrueba extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('importaciones_de_prueba')) {
            $this->crear_importaciones();
        }
        if (!Schema::hasColumn('ai_avisos_del_asistente', 'support_ticket_id')) {
            Schema::table('ai_avisos_del_asistente', function (Blueprint $table) {
                $table->unsignedBigInteger('support_ticket_id')->nullable();
            });
        }
    }

    private function crear_importaciones(): void
    {
        Schema::create('importaciones_de_prueba', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('support_ticket_id');
            $table->string('clave', 64);
            $table->string('archivo');
            $table->string('nombre');
            $table->string('origen', 40);
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('estado', 30)->index();
            $table->unsignedInteger('revision')->default(0);
            $table->json('hojas')->nullable();
            $table->json('preguntas')->nullable();
            $table->json('decisiones')->nullable();
            $table->json('hitos')->nullable();
            $table->unsignedBigInteger('import_history_id')->nullable()->unique();
            $table->unsignedBigInteger('image_assignment_run_id')->nullable();
            $table->unsignedBigInteger('embedding_run_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'support_ticket_id', 'clave'], 'importacion_de_prueba_origen');
        });

    }

    public function down()
    {
        if (Schema::hasColumn('ai_avisos_del_asistente', 'support_ticket_id')) {
            Schema::table('ai_avisos_del_asistente', function (Blueprint $table) {
                $table->dropColumn('support_ticket_id');
            });
        }
        Schema::dropIfExists('importaciones_de_prueba');
    }
}
