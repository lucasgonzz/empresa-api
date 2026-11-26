<?php

namespace Tests\Feature\Devoluciones;

use App\Models\CurrentAcount;
use App\Models\MovimientoCaja;
use App\Models\StockMovement;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Misión cc-pivotes-huerfanos-signo-20261125 (ticket 171), ampliación del 25/11/2026: QA encontró que
 * NotaCreditoProveedorHelper::store() tragaba, con su `catch (\Throwable)`, la ValidationException `cuenta_corriente`
 * que CurrentAcountHelper::notaCredito() lanza para un monto negativo o no numérico, y respondía 500 sin cuerpo.
 * Tiene que responder 422 (como la devolución de venta) y no dejar NINGUNA escritura: ni NC, ni stock, ni saldo.
 *
 * Todo por el camino real: compra por `POST api/provider-order` y devolución por `POST api/devoluciones/`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Nota_de_credito_a_proveedor_monto_invalido_Test extends NotaCreditoProveedorTestCase
{
    /**
     * Foto de todo lo que una devolución de compra podría escribir.
     *
     * @param \App\Models\Article $articulo
     * @param \App\Models\CreditAccount $cuenta
     * @return array
     */
    protected function foto_de_escrituras($articulo, $cuenta)
    {
        return [
            'current_acounts' => CurrentAcount::count(),
            'pivotes'         => DB::table('pagado_por')->count(),
            'movimientos_stock' => StockMovement::count(),
            'cajas'           => MovimientoCaja::count(),
            'stock'           => $this->stock($articulo),
            'saldo'           => (float) $cuenta->fresh()->saldo,
        ];
    }

    /**
     * @return array
     */
    public function montos_invalidos()
    {
        return [
            'negativo'        => [-10, 'negativo'],
            'vacío'           => ['', 'número'],
            'no numérico'     => ['abc', 'número'],
        ];
    }

    /**
     * Con cuenta corriente: negativo, vacío o no numérico → 422 con el motivo y sin escribir nada.
     *
     * @test
     * @dataProvider montos_invalidos
     */
    public function con_cuenta_corriente_un_monto_invalido_responde_422_y_no_escribe_nada($monto, $palabra)
    {
        $articulo = $this->crear_articulo('zz NC proveedor monto invalido cc');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);
        $cuenta = $this->cuenta_del_proveedor($proveedor, 1);

        $this->assertNotNull($cuenta, 'El proveedor no tiene cuenta en pesos; el escenario no sirve.');

        $antes = $this->foto_de_escrituras($articulo, $cuenta);

        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 2)],
            $monto,
            ['generar_current_acount' => 1]
        ));

        $response->assertStatus(422);
        $this->assertStringContainsString($palabra, (string) $response->json('message'), 'El 422 tiene que decir qué monto no sirve.');

        $this->assertEquals($antes, $this->foto_de_escrituras($articulo, $cuenta), 'Un monto inválido no puede dejar escrituras (NC, stock ni saldo).');
        $this->assertNull($this->nota_credito_de($compra));
    }

    /**
     * Sin cuenta corriente la NC también pasa por notaCredito(): mismo 422 y el stock no sale.
     *
     * @test
     * @dataProvider montos_invalidos
     */
    public function sin_cuenta_corriente_un_monto_invalido_responde_422_y_no_saca_stock($monto, $palabra)
    {
        $articulo = $this->crear_articulo('zz NC proveedor monto invalido sin cc');
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 4)]);
        $cuenta = $this->cuenta_del_proveedor($proveedor, 1);

        $antes = $this->foto_de_escrituras($articulo, $cuenta);

        $response = $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 2)],
            $monto,
            ['generar_current_acount' => 0]
        ));

        $response->assertStatus(422);
        $this->assertStringContainsString($palabra, (string) $response->json('message'));

        $this->assertEquals($antes, $this->foto_de_escrituras($articulo, $cuenta));
        $this->assertNull($this->nota_credito_de($compra));
    }
}
