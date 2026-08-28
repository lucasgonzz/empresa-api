<?php

namespace Tests\Feature\Pedidos;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Tanda correctivos 24/8 — ítem 10: la doble confirmación de un pedido bajo carrera.
 *
 * La transacción de OrderController::update() ya existía, pero el candado de idempotencia
 * era un `Sale::where('order_id')->exists()` SIN lock: dos updates simultáneos del mismo
 * pedido leían los dos "no hay venta" y creaban DOS. El arreglo toma un
 * `SELECT ... FOR UPDATE` sobre la fila del pedido dentro de la transacción, antes de
 * evaluar el candado — el mismo patrón con el que BudgetController::confirmar() cerró la
 * misma carrera en presupuestos.
 *
 * Una carrera real no se puede reproducir en el único proceso de PHPUnit, así que acá se
 * fija (a) que el update emite el FOR UPDATE sobre el pedido dentro de la transacción —
 * que ES el candado — y (b) la idempotencia secuencial de siempre (complementa el test
 * `confirmar_dos_veces_no_crea_dos_ventas` de 1_Confirmar_pedido_Test).
 *
 * NO se agregó unique a `sales.order_id` a propósito: `sales` usa SoftDeletes y la venta
 * borrada por una cancelación sigue ocupando el valor — un unique rompería cualquier flujo
 * legítimo que vuelva a crear la venta del pedido.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Lock_de_confirmacion_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    /**
     * El update que confirma el pedido toma el lock de la fila (SELECT ... FOR UPDATE
     * sobre orders) antes de decidir si crea la venta.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_el_pedido_toma_el_lock_de_la_fila()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        /** Queries emitidas durante el update, para verificar el FOR UPDATE del candado. */
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        /** Hubo un select de orders con for update entre las queries del request. */
        $hubo_lock = false;

        foreach ($queries as $sql) {
            if (
                strpos($sql, 'select') === 0
                && strpos($sql, '`orders`') !== false
                && strpos($sql, 'for update') !== false
            ) {
                $hubo_lock = true;
                break;
            }
        }

        $this->assertTrue(
            $hubo_lock,
            'El update del pedido no tomó el SELECT ... FOR UPDATE sobre la fila de orders: '.
            'sin ese lock, dos confirmaciones simultáneas del mismo pedido crean dos ventas.'
        );

        $this->assertEquals(1, Sale::where('order_id', $pedido->id)->count());
    }

    /**
     * La idempotencia de siempre sigue en pie con el lock adelante: repetir la
     * confirmación (mismo estado y estado siguiente) no crea una segunda venta.
     *
     * @group pedidos
     * @test
     */
    public function repetir_la_confirmacion_con_el_lock_sigue_creando_una_sola_venta()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Terminado'))
             ->assertStatus(200);

        $this->assertEquals(
            1,
            Sale::where('order_id', $pedido->id)->count(),
            'Repetir la confirmación creó una segunda venta.'
        );
    }
}
