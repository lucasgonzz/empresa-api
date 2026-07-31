<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración para crear la tabla de corridas de análisis de Excel.
 *
 * Almacena el estado y resultado del análisis de archivos Excel de artículos.
 * Esta tabla es fundamental para mover el análisis de archivos grandes desde
 * requests HTTP síncronos a procesamiento en segundo plano (colas).
 */
class CreateExcelAnalysisRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('excel_analysis_runs', function (Blueprint $table) {
            // Identificador único de la tabla.
            $table->bigIncrements('id');

            // UUID único para cada corrida, usado por el frontend.
            // Nunca se expone el id interno; siempre se usa el UUID.
            $table->string('uuid', 64);
            $table->index('uuid');

            // Usuario propietario de la corrida.
            $table->unsignedInteger('user_id');
            $table->index('user_id');

            // Tipo de análisis: 'analisis' o 'recomendacion'.
            $table->string('tipo', 32);

            // Estado de la corrida: pendiente, procesando, listo, error.
            $table->string('estado', 32)->default('pendiente');

            // Ruta relativa del archivo dentro de storage/app.
            // Se achica a string(191) para permitir índice compuesto sin complicaciones.
            // Las rutas reales (ej. imported_files/ai_import_1753900000_a1b2c3d4.xlsx)
            // son muy cortas, bien por debajo de este límite.
            $table->string('excel_path', 191);

            // Parámetros de entrada de la corrida, almacenados como JSON.
            $table->longText('payload')->nullable();

            // Respuesta armada para el frontend, almacenada como JSON.
            $table->longText('resultado')->nullable();

            // Lista de códigos de proveedor distintos del archivo, en JSON.
            // No se expone al frontend; usada por prompt 03 para evitar releer el archivo.
            $table->longText('codigos_proveedor')->nullable();

            // Mensaje legible en caso de error (estado = 'error').
            $table->text('error')->nullable();

            // Porcentaje de progreso de la corrida (0 a 100).
            $table->unsignedTinyInteger('progreso')->default(0);

            // Texto corto para mostrar al usuario durante el procesamiento.
            // Ej: "Leyendo el archivo…", "Analizando artículos…".
            $table->string('paso', 160)->nullable();

            // Timestamps created_at y updated_at.
            $table->timestamps();

            // Índice compuesto para búsquedas de prompt 03 (recuperar códigos de proveedor).
            // Busca por combinación de user_id y excel_path.
            $table->index(['user_id', 'excel_path'], 'ear_user_excel_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('excel_analysis_runs');
    }
}
