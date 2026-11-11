<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `dimension_reference_sites`: los sitios de referencia de cada comercio para la búsqueda masiva de peso y
 * medidas (misión medidas-sitios-de-referencia, 11/11/2026).
 *
 * Son las páginas donde el comercio sabe que están cargados los pesos y medidas de sus productos (para Masquito:
 * obi.de, verdefacile.eu, meteorpiston.com, carbydoctor.com.au). El motor las busca PRIMERO, con una consulta
 * `site:` por los códigos del artículo. Se guarda solo el dominio ya normalizado (sin esquema, sin `www.`, sin
 * ruta), como mucho 8 por comercio (lo arma DimensionReferenceSiteHelper, que reemplaza la lista entera).
 *
 * Sin foreign keys por convención del proyecto. Tabla nueva: una instalación vieja no la lee.
 */
class CreateDimensionReferenceSitesTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('dimension_reference_sites')) {
            return;
        }

        Schema::create('dimension_reference_sites', function (Blueprint $table) {
            $table->id();

            // Dueño del comercio: todo se filtra por esta columna.
            $table->unsignedBigInteger('user_id');
            $table->index('user_id', 'drs_user_idx');

            // El dominio normalizado («obi.de», «tienda.ejemplo.com.ar»).
            $table->string('dominio', 120);

            $table->timestamps();

            // Un dominio una sola vez por comercio.
            $table->unique(['user_id', 'dominio'], 'drs_user_dominio_unique');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dimension_reference_sites');
    }
}
