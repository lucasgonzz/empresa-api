<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddBackgroundColorToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega la columna background_color a online_configurations.
     *
     * Esta columna permite que cada tienda configure su propio color de fondo independientemente
     * de la plantilla elegida. El backfill preserva exactamente el fondo que cada cliente ve hoy,
     * derivado de su plantilla actual: plantillas "moderno" obtienen #EBEBEB (gris), "clasico"
     * obtienen #FFFFFF (blanco). Esto evita que 40+ clientes en producción vean cambios no
     * solicitados en el diseño visual de su tienda al desplegar la migración.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->string('background_color', 20)->nullable()->default('#FFFFFF');
        });

        // Backfill: preservar el color de fondo que cada cliente ve hoy, basado en su plantilla actual.
        // Solo toca registros existentes; nuevos clientes o cambios futuros usan el default (#FFFFFF).
        if (Schema::hasTable('online_templates')) {
            // Resolver el id de la plantilla "moderno" por su slug, sin hardcodear ID.
            $moderno_template = DB::table('online_templates')->where('slug', 'moderno')->first();
            if ($moderno_template) {
                DB::table('online_configurations')
                    ->where('online_template_id', $moderno_template->id)
                    ->update(['background_color' => '#EBEBEB']);
            }

            // Resolver el id de la plantilla "clasico" por su slug.
            $clasico_template = DB::table('online_templates')->where('slug', 'clasico')->first();
            if ($clasico_template) {
                DB::table('online_configurations')
                    ->where('online_template_id', $clasico_template->id)
                    ->update(['background_color' => '#FFFFFF']);
            }
        }

        // Los registros con online_template_id en NULL (sin plantilla asignada) o apuntando a
        // una plantilla inexistente quedan con el default #FFFFFF, que es lo que ven hoy
        // (ninguna clase CSS de plantilla aplicada en tienda-spa → fondo blanco).
    }

    /**
     * Revierte la agregacion de background_color.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('background_color');
        });
    }
}
