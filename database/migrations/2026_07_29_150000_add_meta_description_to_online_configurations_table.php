<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMetaDescriptionToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega la columna meta_description a online_configurations.
     *
     * Grupo 270 · prompt 01: al compartir el link de la tienda por WhatsApp/Instagram, el crawler
     * de Meta no ejecuta JS y necesita una descripción propia del comercio para armar la vista
     * previa (og:description / twitter:description). Hoy no existe ningún campo utilizable para
     * esto: `online_description` (columna vieja, ver comentario en el prompt) no está expuesta en
     * la UI y puede tener datos arbitrarios/HTML de largo indefinido, así que no se reutiliza.
     *
     * Se deja `nullable()` sin default y SIN backfill a propósito: si el comercio no cargó nada,
     * la tienda simplemente no emite `og:description`. Inventar una descripción genérica para los
     * clientes existentes sería peor que no tener ninguna.
     *
     * Largo acotado a 300 caracteres (no `text`): es un campo de metadatos para redes sociales, no
     * un editor de contenido libre. WhatsApp/Facebook recortan la vista previa entre 160 y 200
     * caracteres aproximadamente; 300 da margen sin habilitar textos enormes.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->string('meta_description', 300)->nullable();
        });
    }

    /**
     * Revierte la agregación de meta_description.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('meta_description');
        });
    }
}
