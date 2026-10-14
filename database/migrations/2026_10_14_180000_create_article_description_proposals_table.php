<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propuestas de descripción que la IA no pudo confirmar (misión alertas-descripciones, 14/10/2026).
 *
 * 🔴 POR QUÉ UNA TABLA APARTE y no una marca en `descriptions`: todo lo que está en `descriptions`
 * lo muestran la tienda propia (`tienda-api` lee `Article::descriptions()` sin filtro, y también lo
 * indexa Google por las páginas SEO) y la ficha del artículo en el ERP. Una descripción de baja
 * confianza guardada ahí se publicaba sola, aunque "no se publica sin revisión" se cumpliera para
 * Tienda Nube. Guardándola acá, ninguna de las dos la ve hasta que alguien la aprueba desde
 * Alertas → Catálogo → Descripciones, y recién ahí pasa a ser una fila de `descriptions`. La tienda
 * no se toca: sigue leyendo lo mismo de siempre.
 *
 * Una fila por artículo y por propuesta (no por sección): el artículo se revisa entero. `status`
 * guarda también lo resuelto (`aprobada` / `descartada`), que es lo que impide que el lote
 * automático le vuelva a proponer al dueño lo que ya descartó.
 *
 * Sin foreign keys (regla del proyecto para migraciones nuevas).
 */
class CreateArticleDescriptionProposalsTable extends Migration
{
    /**
     * Crea la tabla con sus índices.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_description_proposals', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* El dueño del artículo (`articles.user_id`), no la persona que lanzó el lote. */
            $table->unsignedBigInteger('user_id')->index();

            $table->unsignedBigInteger('article_id')->index();

            /* JSON [{title, content}], de 1 a 3 secciones, tal como las propuso la IA. */
            $table->text('sections');

            /* 'low' hoy (lo único que viene acá); se guarda igual por si cambia el criterio. */
            $table->string('ai_confidence', 10)->nullable();

            /* JSON [{source, url, title}]: de dónde sacó la IA lo que escribió. */
            $table->text('ai_sources')->nullable();

            /* a_revisar | aprobada | descartada. */
            $table->string('status', 20)->default('a_revisar');

            $table->timestamp('resolved_at')->nullable();

            /* La persona real que la resolvió (empleado o dueño), no el dueño. */
            $table->unsignedBigInteger('resolved_by')->nullable();

            $table->timestamps();

            /* El número rojo y la solapa "A revisar" del dueño. */
            $table->index(['user_id', 'status'], 'adp_user_status_idx');

            /* El lote automático pregunta por artículo si ya hay una propuesta a revisar o descartada. */
            $table->index(['article_id', 'status'], 'adp_article_status_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_description_proposals');
    }
}
