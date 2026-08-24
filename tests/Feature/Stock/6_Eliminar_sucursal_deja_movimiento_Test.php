<?php

namespace Tests\Feature\Stock;

use App\Models\Address;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\ConceptoStockMovementEliminacionDeSucursalSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tanda correctivos 24/8 — ítem 7: borrar una sucursal evaporaba su stock sin dejar
 * rastro. AddressController::destroy() hacía articles()->detach() directo: el pivot
 * desaparecía, el stock global del artículo quedaba inflado hasta el próximo recálculo y
 * el historial de movimientos no explicaba nada.
 *
 * Ahora, antes del detach, queda un StockMovement por cada artículo con stock en esa
 * sucursal (concepto "Eliminacion de sucursal", nuevo: seeder general + standalone
 * ConceptoStockMovementEliminacionDeSucursalSeeder para producción), que además deja el
 * pivot en 0 y recalcula el stock global por el camino normal de stock.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Eliminar_sucursal_deja_movimiento_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Borrar la sucursal deja el movimiento de stock con el concepto nuevo, descuenta el
     * stock global y no genera movimiento para artículos sin stock en ella.
     *
     * @group stock
     * @test
     */
    public function borrar_una_sucursal_deja_stock_movement_por_articulo_con_stock()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        /*
         * El concepto nuevo, por el standalone de producción (esta base de testing se
         * sembró antes del ítem 7). De paso queda ejercitado el seeder y su idempotencia.
         */
        $this->seed(ConceptoStockMovementEliminacionDeSucursalSeeder::class);
        $this->seed(ConceptoStockMovementEliminacionDeSucursalSeeder::class);

        $this->assertEquals(
            1,
            ConceptoStockMovement::where('name', 'Eliminacion de sucursal')->count(),
            'El standalone tiene que crear el concepto una sola vez (idempotente).'
        );

        /** Sucursal a borrar, creada por el endpoint real. */
        $response = $this->postJson('api/address', [
            'street'          => 'zz Sucursal a borrar 2408',
            'street_number'   => null,
            'city'            => null,
            'province'        => null,
            'default_address' => 0,
        ]);
        $response->assertStatus(201);

        $sucursal = Address::find(json_decode($response->getContent(), true)['model']['id']);

        /** Artículo CON stock en la sucursal (7 unidades, cargadas por el camino real de stock). */
        $articulo_con_stock = Article::create([
            'name'    => 'zz Art sucursal 2408 con stock',
            'user_id' => 500,
        ]);

        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo_con_stock->id,
            'amount'                       => 7,
            'to_address_id'                => $sucursal->id,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(7, (float) $articulo_con_stock->fresh()->stock, 'El escenario no quedó armado: el ingreso no dejó el stock en 7.');

        /** Artículo SIN stock en la sucursal (pivot en 0): no tiene que generar movimiento. */
        $articulo_sin_stock = Article::create([
            'name'    => 'zz Art sucursal 2408 sin stock',
            'user_id' => 500,
        ]);

        $articulo_sin_stock->addresses()->attach($sucursal->id, ['amount' => 0]);

        /* El borrado, por el endpoint real. */
        $this->deleteJson('api/address/'.$sucursal->id)->assertStatus(200);

        /** El movimiento que deja el rastro del stock evaporado. */
        $movimiento = StockMovement::where('article_id', $articulo_con_stock->id)
                                    ->where('from_address_id', $sucursal->id)
                                    ->orderBy('id', 'DESC')
                                    ->first();

        $this->assertNotNull($movimiento, 'Borrar la sucursal no dejó StockMovement del artículo con stock.');
        $this->assertEquals(-7, (float) $movimiento->amount);

        $concepto = ConceptoStockMovement::find($movimiento->concepto_stock_movement_id);
        $this->assertNotNull($concepto, 'El movimiento quedó sin concepto.');
        $this->assertSame('Eliminacion de sucursal', $concepto->name);

        // El stock global ya no cuenta lo que tenía la sucursal borrada.
        $this->assertEquals(
            0,
            (float) $articulo_con_stock->fresh()->stock,
            'El stock global tiene que reflejar que el stock de la sucursal borrada ya no existe.'
        );

        // El artículo sin stock en la sucursal no genera movimiento.
        $this->assertEquals(
            0,
            StockMovement::where('article_id', $articulo_sin_stock->id)
                        ->where('from_address_id', $sucursal->id)
                        ->count(),
            'Un artículo con 0 en la sucursal no tiene que generar movimiento al borrarla.'
        );

        // Y la sucursal quedó borrada con sus pivots.
        $this->assertNull(Address::find($sucursal->id));
        $this->assertEquals(0, (int) $articulo_con_stock->fresh()->addresses()->count());
    }
}
