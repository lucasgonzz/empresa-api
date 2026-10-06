<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * PARIDAD con el motor (misión sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * Todos los otros tests arman la fila fantasma a mano (`attach` a una sucursal muerta). Este
 * prueba que esa fila a mano ES la que deja el motor de verdad: una venta real por
 * `StockMovementController::crear()` con un `from_address_id` que ya no existe.
 *
 * Es la garantía de que el criterio del saneo (qué es un fantasma, qué stock queda) se midió sobre
 * lo que de verdad pasa en producción y no sobre un fixture cómodo.
 *
 * 🔴 Es condicional. La misión hermana `eliminar-sucursal-con-stock` agrega una guarda al motor que
 * IMPIDE que nazcan fantasmas nuevos; cuando se mergee, este test se saltea con un mensaje claro
 * (el motor ya no abre filas fantasma) y los demás siguen valiendo, porque ninguno depende de que
 * el motor las abra.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Paridad_con_el_motor_Test extends SaneoStockSucursalesTestCase
{
    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_fantasma_que_abrio_el_motor_tiene_la_forma_del_fixture_y_el_comando_lo_sanea()
    {
        // El usuario logueado del test (el 500): `crear()` toma el dueño de la sesión.
        $dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($dueno, 'Falta el usuario del fixture.');

        $s1 = $this->sucursal($dueno, 'zz Sucursal paridad');
        $muerta = $this->sucursal_muerta($dueno);

        // El artículo reparte por sucursales (una fila viva de 10): la condición para que el motor
        // abra una fila nueva en lugar de aplicar la venta al stock global.
        $articulo = $this->crear_articulo($dueno, 'Paridad con el motor');

        $this->fila($articulo, $s1->id, 10);
        $this->stock_como_el_motor($articulo);

        $this->assertEquals(10.0, $this->stock($articulo));

        // Una venta REAL contra la sucursal que ya no existe.
        $controlador = new StockMovementController();

        $controlador->crear([
            'model_id' => $articulo->id,
            'amount' => -1,
            'from_address_id' => $muerta,
            'concepto_stock_movement_name' => 'Venta',
        ], false);

        $del_motor = DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $muerta)->first();

        if (is_null($del_motor)) {
            $this->markTestSkipped('El motor ya no abre filas fantasma para una sucursal borrada (la guarda de eliminar-sucursal-con-stock está mergeada): este test de paridad ya no aplica. Los demás tests no dependen de eso.');
        }

        // La misma fila armada como la arman los demás tests.
        $del_fixture = DB::table('address_article')
            ->where('id', $this->articulo_con_fantasmas($dueno, 'Paridad fixture', [$s1->id => 10], [[$muerta, -1]])['fantasmas'][0])
            ->first();

        foreach (['address_id', 'amount', 'created_at', 'updated_at', 'stock_min', 'stock_max'] as $columna) {
            $this->assertSame($del_motor->$columna, $del_fixture->$columna, 'La fila fantasma del fixture no tiene la forma de la que deja el motor: difiere la columna ' . $columna . '.');
        }

        $this->assertEquals(-1.0, (float) $del_motor->amount);
        $this->assertNull($del_motor->created_at, 'El motor la abre con attach(): sin timestamps.');

        // El motor dejó el stock con la suma CRUDA: 10 − 1.
        $this->assertEquals(9.0, $this->stock($articulo), 'El motor suma el fantasma en articles.stock: tiene que haber quedado en 9.');

        // El comando la sanea y lo explica.
        $codigo = $this->aplicar($dueno, ['--articulo_id' => $articulo->id]);

        $this->assertSame(0, $codigo, 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $muerta), 'El comando no borró el fantasma que abrió el motor.');
        $this->assertEquals(10.0, $this->stock($articulo), 'El stock vuelve a la suma de lo que se ve.');

        $movimientos = $this->movimientos($articulo);

        $this->assertCount(2, $movimientos, 'La venta del motor y el movimiento del saneo.');

        $venta = $movimientos[0];
        $saneo = $movimientos[1];

        $this->assertEquals(-1.0, (float) $venta->amount);
        $this->assertEquals(9.0, (float) $venta->stock_resultante, 'La venta del motor dejó 9 (con el fantasma adentro).');

        $this->assertEquals(1.0, (float) $saneo->amount, 'El saneo devuelve la unidad que el fantasma se llevó.');
        $this->assertEquals(9.0, (float) $saneo->stock_anterior, 'El saneo parte del stock que dejó el motor.');
        $this->assertEquals(10.0, (float) $saneo->stock_resultante);
        $this->assertSame((int) $dueno->id, (int) $saneo->user_id);
        $this->assertNotSame((int) $venta->concepto_stock_movement_id, (int) $saneo->concepto_stock_movement_id, 'Son dos conceptos distintos: Venta y Actualizacion de deposito.');
    }
}
