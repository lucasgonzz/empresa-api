<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: los impuestos de retención que el comercio da de alta por su cuenta (misión
 * retenciones-abm-impuestos, 8/10/2026).
 *
 * Los tres impuestos de siempre (Ganancias, IVA, Ingresos Brutos) NO viven acá: siguen siendo
 * constantes de `RetencionSufrida::IMPUESTOS`, no se siembran, no se editan y no se borran. Esta
 * tabla guarda SOLO los nuevos (SUSS, Seguridad e Higiene municipal, lo que le retenga un cliente
 * al comercio), por dueño, y arranca vacía en todas las bases.
 *
 * El certificado referencia al impuesto nuevo por TEXTO, no por una columna nueva: en
 * `retenciones_sufridas.impuesto` (string 20) se guarda `imp_<id>`. Así `retenciones_sufridas` no
 * necesita ninguna migración y los certificados ya cargados no cambian. Sin foreign keys físicas,
 * como el resto del schema.
 */
class CreateRetencionImpuestosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('retencion_impuestos')) {
            return;
        }

        Schema::create('retencion_impuestos', function (Blueprint $table) {
            $table->id();

            /** Nombre del impuesto, tal como se muestra en el selector del cobro. */
            $table->string('name');

            /** Dueño del comercio: el catálogo es por cuenta, no global. */
            $table->integer('user_id')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('retencion_impuestos');
    }
}
