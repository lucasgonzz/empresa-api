<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2-M (vinculacion-iva-copia, 21/11/2026): dos columnas boolean con default 0, sin índice ni FK.
 *
 * - `articles.costo_neto_de_vinculacion`: la copia vinculada guarda el costo según
 *   `InventoryLinkageHelper::costo_para_la_copia` (neto cuando la cuenta del cliente carga costo bruto).
 * - `inventory_linkages.copia_en_costo_neto`: el interruptor de la vinculación. Apagado por defecto.
 *
 * Con las dos en 0, toda fila y toda vinculación se comportan exactamente como antes; el código viejo
 * ignora las dos columnas.
 */
class AddCostoNetoDeVinculacion extends Migration
{
    /**
     * Agrega las dos columnas con default 0.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('articles', 'costo_neto_de_vinculacion')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->boolean('costo_neto_de_vinculacion')->default(0)->after('aplicar_iva');
            });
        }

        if (!Schema::hasColumn('inventory_linkages', 'copia_en_costo_neto')) {
            Schema::table('inventory_linkages', function (Blueprint $table) {
                $table->boolean('copia_en_costo_neto')->default(0);
            });
        }
    }

    /**
     * Quita las dos columnas.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('articles', 'costo_neto_de_vinculacion')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropColumn('costo_neto_de_vinculacion');
            });
        }

        if (Schema::hasColumn('inventory_linkages', 'copia_en_costo_neto')) {
            Schema::table('inventory_linkages', function (Blueprint $table) {
                $table->dropColumn('copia_en_costo_neto');
            });
        }
    }
}
