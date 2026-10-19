<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deja en 'manual' toda compra cuyo `modo_facturacion` no es ninguno de los tres modos válidos
 * (misión `factura-compra-tres-defectos`, 9/10/2026).
 *
 * De dónde salen esas filas: el select de la SPA arranca en la opción 0 ("Seleccione Modo
 * Facturacion"), y una compra guardada sin elegir modo quedaba con `"0"` en la columna. Las
 * compras del asistente de WhatsApp, las de las sugerencias de compra y las más viejas que la
 * columna quedaron en NULL.
 *
 * Después de esta migración ningún alta deja NULL: la pantalla guarda 'automatico' si no vino un
 * modo válido, y el asistente, las sugerencias de compra (`PurchaseSuggestionController`), la compra
 * espejo de una venta entre comercios (`SaleProviderOrderHelper`) y la copia entre bases
 * (`DatabaseProviderOrderHelper`) guardan 'manual'.
 *
 * 🔴 Por qué 'manual' y no 'automatico'. Es exactamente como se comportan HOY esas compras al
 * editarlas: `"0"` o NULL no matchean ningún modo en una edición (`"0" == 'automatico'` es falso
 * en PHP 7.4, y null también), así que `ModoFacturacionHelper` no les toca las facturas — igual que
 * a una manual. Pasarlas a 'automatico' cambiaría números: el próximo guardado de la compra le
 * recalcularía la factura desde los artículos y pisaría lo que el usuario hubiera cargado a mano.
 * Con 'manual' no cambia ningún número; lo único que cambia es que el select de la compra deja de
 * decir "Seleccione" y dice lo que la compra efectivamente hace.
 *
 * La comparación es BINARIA a propósito: con la intercalación de la columna (`utf8mb4_unicode_ci`)
 * MySQL compara sin mayúsculas y sin espacios finales, así que un `'Automatico'` o un
 * `'manual '` pasarían por válidos. PHP no los reconoce (`ModoFacturacionHelper::normalizar()`
 * compara exacto), así que hoy se comportan como manual y acá tienen que caer igual.
 *
 * Solo toca `modo_facturacion`: el query builder no actualiza `updated_at`, así que ninguna compra
 * aparece como "modificada hoy".
 */
class NormalizarModoFacturacionDeProviderOrders extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        DB::table('provider_orders')
            ->where(function ($q) {
                $q->whereNull('modo_facturacion')
                  ->orWhereRaw('CAST(modo_facturacion AS BINARY) NOT IN (?, ?, ?)', ['automatico', 'manual', 'sin factura']);
            })
            ->update(['modo_facturacion' => 'manual']);
    }

    /**
     * No hay vuelta atrás: el valor anterior ("0", NULL) no se guardó en ningún lado y no tenía
     * ningún significado propio — se comportaba como 'manual', que es lo que quedó.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
