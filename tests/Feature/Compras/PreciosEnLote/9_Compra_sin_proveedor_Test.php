<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La compra SIN proveedor (misión compras-precios-en-lote, 29/9/2026).
 *
 * Lo que el plan pedía probar: sin proveedor no corre la llamada #3, NewProviderOrderHelper no
 * difiere (se_difiere_el_recalculo()) y la compra tendría que quedar IDÉNTICA a la de hoy en todo,
 * incluidos los price_changes intermedios.
 *
 * 🔴 HALLAZGO (29/9/2026): ese escenario NO EXISTE con el esquema actual. `provider_orders.
 * provider_id` es `int unsigned` NOT NULL (database/migrations/2022_06_02_172623_create_provider_
 * orders_table.php:26, sin ninguna migración posterior que lo haga nullable), y la conexión corre
 * en modo estricto: el alta sin proveedor revienta en el INSERT (1048, "Column 'provider_id' cannot
 * be null") y la edición en el save() del controlador. Tampoco hay forma de llegar a
 * procesar_pedido() con el proveedor en null solo en memoria, porque set_totales() guarda la compra
 * antes de la #3. O sea que la rama "sin proveedor" de se_difiere_el_recalculo() hoy es
 * inalcanzable desde cualquier camino que confirme una compra.
 *
 * Lo que SÍ se prueba acá es que el camino nuevo no cambia eso: la compra sin proveedor se rechaza
 * igual en los dos caminos (mismo código de respuesta) y la base queda exactamente como estaba.
 *
 * ⚠️ Si algún día la columna pasa a admitir null (compras sin proveedor de verdad), este test deja
 * de pasar a propósito: hay que reemplazarlo por la comparación idéntica con dos_caminos() (un
 * renglón con costo nuevo y precio manual en un artículo sin margen, que hoy deja dos cambios de
 * precio, #1 y #2) y assert_identico().
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Compra_sin_proveedor_Test extends ComprasPreciosEnLoteTestCase
{
    /**
     * @group compras
     * @test
     */
    public function la_compra_sin_proveedor_se_rechaza_igual_en_los_dos_caminos()
    {
        $this->set_condicion_iva('RRII');

        $columna = DB::selectOne(
            'SELECT IS_NULLABLE AS nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['provider_orders', 'provider_id']
        );

        $this->assertSame('NO', $columna->nullable, 'provider_orders.provider_id ahora admite null: la compra sin proveedor existe y este test se tiene que reemplazar por la comparación idéntica (ver el docblock).');

        $sin_margen = $this->crear_articulo([
            'cost'                           => 400,
            'percentage_gain'                => null,
            'apply_provider_percentage_gain' => 0,
        ]);

        $pinza = $this->articulo('Pinza');

        $ids = [$sin_margen->id, $pinza->id];

        $payload = function () use ($sin_margen, $pinza) {
            return $this->payload_compra([
                'provider_id'             => null,
                'generate_current_acount' => 0,
                'articles'                => [
                    $this->renglon($sin_margen, 440, 7, ['price' => 1500]),
                    $this->renglon($pinza, 1070, 3),
                ],
            ]);
        };

        Carbon::setTestNow(self::AHORA);

        try {
            $marca  = (int) DB::table('price_changes')->max('id');
            $antes  = $this->foto($ids, $marca);
            $compras_antes = DB::table('provider_orders')->count();

            $hoy   = $this->rechazo($payload, true, $ids, $marca);
            $motor = $this->rechazo($payload, false, $ids, $marca);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertGreaterThanOrEqual(400, $hoy['status'], 'Referencia: hoy la compra sin proveedor se rechaza.');
        $this->assertSame($hoy['status'], $motor['status'], 'El camino nuevo tiene que rechazar la compra sin proveedor igual que el de hoy.');

        $this->assertSame($compras_antes, $hoy['compras'], 'Hoy, la compra rechazada no deja ninguna compra creada.');
        $this->assertSame($compras_antes, $motor['compras'], 'Con el camino nuevo, tampoco.');

        $this->assertEquals($antes, $hoy['foto'], 'Hoy, la compra rechazada no toca los artículos.');
        $this->assertEquals($antes, $motor['foto'], 'Con el camino nuevo, tampoco: ni precios, ni listas, ni cambios de precio.');
    }

    /**
     * Intenta el alta sin proveedor en un savepoint, con el interruptor en $por_articulo, y
     * devuelve el código de respuesta y cómo quedó la base (antes del rollback del savepoint).
     *
     * @param  callable $payload
     * @param  bool     $por_articulo
     * @param  int[]    $ids
     * @param  int      $marca
     * @return array ['status' => int, 'compras' => int, 'foto' => array]
     */
    protected function rechazo(callable $payload, $por_articulo, array $ids, $marca)
    {
        $this->limpiar_estado_del_proceso();

        NewProviderOrderHelper::recalcular_por_articulo($por_articulo);

        $nivel = DB::transactionLevel();

        DB::beginTransaction();

        try {

            $respuesta = $this->postJson('api/provider-order', $payload());

            $resultado = [
                'status'  => $respuesta->getStatusCode(),
                'compras' => DB::table('provider_orders')->count(),
                'foto'    => $this->foto($ids, $marca),
            ];

        } finally {

            while (DB::transactionLevel() > $nivel) {
                DB::rollBack();
            }

            $this->limpiar_estado_del_proceso();
        }

        return $resultado;
    }
}
