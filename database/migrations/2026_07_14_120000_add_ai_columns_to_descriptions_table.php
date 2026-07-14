<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columnas de trazabilidad de IA a la tabla `descriptions`.
 *
 * Permiten distinguir una descripcion escrita a mano de una generada por el
 * flujo de "descripciones inteligentes" (a partir del codigo de barras del
 * articulo), registrar el nivel de confianza con el que se genero, guardar
 * las fuentes usadas para poder auditar el dato, y marcar cuando un humano
 * revisa/aprueba una descripcion de baja confianza.
 *
 * Sin foreign keys (regla del proyecto para migraciones nuevas): la relacion
 * logica con `articles` ya existe via `article_id` y se resuelve en Eloquent.
 */
class AddAiColumnsToDescriptionsTable extends Migration
{
    /**
     * Ejecuta la migracion: agrega las columnas de trazabilidad de IA y un
     * indice compuesto para poder filtrar rapido por articulo + generado por IA.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('descriptions', function (Blueprint $table) {
            /* true si la descripcion fue generada por el flujo de descripciones inteligentes. */
            $table->boolean('ai_generated')->default(false)->after('content');

            /* 'high' | 'medium' | 'low'. Null si no fue generada por IA. */
            $table->string('ai_confidence', 10)->nullable()->after('ai_generated');

            /* JSON con las fuentes usadas: [{source, url, title}]. Auditoria. */
            $table->text('ai_sources')->nullable()->after('ai_confidence');

            /* Fecha en la que un humano aprobo/edito una descripcion de baja confianza. */
            $table->timestamp('ai_reviewed_at')->nullable()->after('ai_sources');

            /* Indice para filtrar rapido descripciones de IA pendientes de revision por articulo. */
            $table->index(['article_id', 'ai_generated'], 'desc_article_ai_gen_idx');
        });
    }

    /**
     * Revierte la migracion: elimina el indice compuesto y las columnas de
     * trazabilidad de IA agregadas en `up()`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('descriptions', function (Blueprint $table) {
            $table->dropIndex('desc_article_ai_gen_idx');
            $table->dropColumn([
                'ai_generated',
                'ai_confidence',
                'ai_sources',
                'ai_reviewed_at',
            ]);
        });
    }
}
