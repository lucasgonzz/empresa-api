<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compra de origen de una nota de crédito a proveedor (misión devoluciones-compras-y-rediseno,
 * 1/10/2026). La NC que nace en el módulo de Devoluciones sobre una compra queda atada a esa compra
 * por esta columna. Ver App\Http\Controllers\Helpers\Devoluciones\NotaCreditoProveedorHelper.
 *
 * 🔴 Por qué una columna NUEVA y no `current_acounts.provider_order_id`, que ya existe: esa columna
 * es la que identifica el DÉBITO de la compra. `NewProviderOrderHelper::set_current_acount()` y
 * `check_cambio_moneda()`, y `ProviderOrderHelper::deleteCurrentAcount()`, buscan ese débito con
 * `where('provider_order_id', ...)->first()`, sin mirar el status. Una NC con el mismo
 * `provider_order_id` podía salir primera en ese `first()` y ser tratada como el débito: editar la
 * compra le pisaba el `debe` a la NC, y borrarla se llevaba la NC en vez del débito.
 *
 * Sin foreign key (convención del repo) y con índice de nombre corto: el listado de la compra y el
 * reporte de la NC la buscan por esta columna. Nullable: todas las filas existentes y todas las NC
 * que no salen de una compra quedan en NULL, y `tienda-api` (que comparte la base y lee
 * `current_acounts`) no se entera.
 *
 * Idempotente con `hasColumn`, por si alguna base ya la tiene.
 */
class AddDevolucionProviderOrderIdToCurrentAcounts extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('current_acounts', 'devolucion_provider_order_id')) {
            Schema::table('current_acounts', function (Blueprint $table) {
                $table->unsignedBigInteger('devolucion_provider_order_id')->nullable()->default(null);
                $table->index('devolucion_provider_order_id', 'ca_dev_po_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('current_acounts', 'devolucion_provider_order_id')) {
            Schema::table('current_acounts', function (Blueprint $table) {
                $table->dropIndex('ca_dev_po_idx');
                $table->dropColumn('devolucion_provider_order_id');
            });
        }
    }
}
