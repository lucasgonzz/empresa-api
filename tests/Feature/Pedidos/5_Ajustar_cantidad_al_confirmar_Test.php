<?php

namespace Tests\Feature\Pedidos;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Article;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Ajustar la cantidad de un renglon antes de confirmar el pedido.
 *
 * 🔴 Es el gesto central del pedido de la tienda —el comprador pide siete y el negocio le puede dar
 * tres— y hasta el 31/8/2026 FACTURABA EL IMPORTE ORIGINAL. El pedido guardaba la cantidad nueva y
 * todo lo demas salia con la vieja: `orders.total`, el aviso de limite de credito, la venta que
 * nace, el stock que se descuenta y el movimiento de cuenta corriente del cliente.
 *
 * Medido sobre `demo` antes del arreglo, con un renglon de 7 unidades a $24.947,16 bajado a 3:
 * el pedido quedaba con `amount=3` y `total=174630.12` (el de 7), y confirmando por 2 unidades la
 * cuenta corriente del cliente subia $174.630,12 en vez de $49.894,32 — $124.735,80 de mas en una
 * sola confirmacion.
 *
 * La causa: `OrderController::adjuntar_renglones()` fotografia los pivotes previos con un
 * `foreach ($model->articles ...)`, y eso deja la relacion CARGADA EN MEMORIA con los valores
 * viejos. El `detach()`/`attach()` que sigue escribe la base y no la refresca, asi que
 * `OrderHelper::get_total()` y `CreateSaleOrderHelper::save_sale()` —los dos recorren
 * `$order->articles`— leen los pivotes que el formulario acaba de reemplazar.
 *
 * ⚠️ Estos tests tienen que FALLAR contra el codigo anterior al arreglo. Si pasan sin el
 * `unsetRelation('articles')`, estan mirando otra cosa: el defecto no estaba en que la cantidad no
 * se guardara —eso siempre funciono— sino en todo lo que se calcula despues.
 *
 * Se corren con `vendor/bin/phpunit tests/Feature/Pedidos/5_Ajustar_cantidad_al_confirmar_Test.php`.
 */
class Ajustar_cantidad_al_confirmar_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /**
     * Cantidades a las que se baja cada renglon, en el mismo orden que `renglones()`.
     *
     * El fixture arma el pedido con 2 unidades a $100 y 3 a $50 (total 350). Bajandolo a 1 y 1 el
     * total correcto pasa a 150, que es un numero distinto por los dos lados: ni coincide con el
     * viejo ni con el de un solo renglon mal calculado.
     *
     * @var array<int,int>
     */
    const CANTIDADES_NUEVAS = [1, 1];

    /**
     * Limites originales de las `credit_accounts` que el test toca, para devolverlas como estaban.
     *
     * `DatabaseTransactions` ya revierte, pero el fixture del cliente se comparte entre suites y
     * dejar el limite escrito seria pisar el escenario de otra: se restaura explicitamente, igual
     * que hace `3_Limite_de_credito_al_confirmar_pedido_Test`.
     *
     * @var array<int,array<string,mixed>>
     */
    protected $limites_a_restaurar = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->limites_a_restaurar as $item) {
            CreditAccount::where('id', $item['id'])->update(['limite_credito' => $item['limite_credito']]);
        }

        $this->limites_a_restaurar = [];

        parent::tearDown();
    }

    /**
     * 1. Bajar las cantidades y confirmar: el TOTAL DEL PEDIDO tiene que quedar en el importe nuevo.
     *
     * Es la asercion mas barata de las tres y la que primero se rompia: `orders.total` quedaba con
     * el numero viejo aunque `article_order.amount` guardara el nuevo.
     *
     * @group pedidos
     * @test
     */
    public function bajar_la_cantidad_recalcula_el_total_del_pedido()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_con_cantidades_nuevas($pedido, 'Confirmado'))
             ->assertStatus(200);

        $pedido = $pedido->fresh();

        $this->assertEquals(
            $this->total_con_cantidades_nuevas(),
            (float) $pedido->total,
            'El total del pedido quedo con las cantidades VIEJAS: se bajaron los renglones y `orders.total` no se recalculo.'
        );
    }

    /**
     * 2. La cantidad nueva tiene que quedar guardada en el pivote.
     *
     * Esto YA andaba antes del arreglo, y esta puesto a proposito: separa "la cantidad no se
     * guarda" —que era la sospecha equivocada— de "la cantidad se guarda y todo lo que se calcula
     * despues usa la vieja", que es el defecto real. Sin esta asercion, un test rojo en las otras
     * dos no dice cual de las dos cosas se rompio.
     *
     * @group pedidos
     * @test
     */
    public function bajar_la_cantidad_la_guarda_en_el_renglon()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_con_cantidades_nuevas($pedido, 'Confirmado'))
             ->assertStatus(200);

        $pedido = $pedido->fresh();
        $pedido->load('articles');

        foreach ($this->renglones() as $indice => $renglon) {

            $guardado = $pedido->articles->firstWhere('id', $renglon['article']->id);

            $this->assertNotNull($guardado, 'El renglon de "'.$renglon['article']->name.'" desaparecio del pedido.');

            $this->assertEquals(
                self::CANTIDADES_NUEVAS[$indice],
                (int) $guardado->pivot->amount,
                'La cantidad nueva de "'.$renglon['article']->name.'" no se guardo en el pivote.'
            );
        }
    }

    /**
     * 3. La VENTA que nace del pedido tiene que salir con las cantidades nuevas: sus renglones, su
     *    total, el stock que descuenta y el movimiento de cuenta corriente.
     *
     * Es la asercion que mide la plata. Antes del arreglo, `CreateSaleOrderHelper::save_sale()`
     * leia `$order->articles` de memoria y creaba la venta con las cantidades originales.
     *
     * @group pedidos
     * @test
     */
    public function la_venta_nace_con_las_cantidades_nuevas()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $this->putJson('api/order/'.$pedido->id, $this->payload_con_cantidades_nuevas($pedido, 'Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar el pedido no creo la venta.');

        $this->assertEquals(
            $this->total_con_cantidades_nuevas(),
            (float) $venta->total,
            'La venta nacio con el total VIEJO: se confirmo por las cantidades nuevas y se facturo por las originales.'
        );

        // Los renglones de la venta, uno por uno.
        $venta->load('articles');

        foreach ($this->renglones() as $indice => $renglon) {

            $item = $venta->articles->firstWhere('id', $renglon['article']->id);

            $this->assertNotNull($item, 'La venta no trajo el renglon de "'.$renglon['article']->name.'".');

            $this->assertEquals(
                self::CANTIDADES_NUEVAS[$indice],
                (int) $item->pivot->amount,
                'La venta arrastro la cantidad VIEJA de "'.$renglon['article']->name.'".'
            );
        }

        // El stock: tiene que haber descontado la cantidad NUEVA, no la del pedido original.
        foreach ($this->renglones() as $indice => $renglon) {

            $this->assertEquals(
                $stock_previo[$renglon['article']->id] - self::CANTIDADES_NUEVAS[$indice],
                (float) Article::find($renglon['article']->id)->stock,
                'El stock de "'.$renglon['article']->name.'" se descontó por la cantidad VIEJA.'
            );
        }

        // Y la cuenta corriente del cliente, que es donde el error se vuelve plata.
        $movimiento = CurrentAcount::where('sale_id', $venta->id)->first();

        $this->assertNotNull($movimiento, 'La venta del pedido no genero movimiento de cuenta corriente.');

        // El importe que se le carga al cliente vive en `debe` (`CurrentAcountFromSaleHelper`
        // lo escribe con `$this->sale->total`), no en un campo `amount`: ese no existe en la tabla
        // y leerlo devuelve 0, que se lee como "no se cargo nada" y no como "estoy mirando mal".
        $this->assertEquals(
            $this->total_con_cantidades_nuevas(),
            (float) $movimiento->debe,
            'A la cuenta corriente del cliente se le cargo el importe VIEJO.'
        );
    }

    /**
     * 4. El aviso de limite de credito tiene que mirar el importe NUEVO.
     *
     * El chequeo compara contra `$model->total`, asi que heredaba el mismo error: un pedido que con
     * las cantidades nuevas entra holgado dentro del limite era rechazado igual por el importe
     * viejo. Se fija el limite en un valor que deja pasar el total nuevo (150) y no el viejo (350).
     *
     * @group pedidos
     * @test
     */
    public function el_limite_de_credito_se_evalua_contra_el_importe_nuevo()
    {
        $cliente = $this->cliente_cc();

        $cuenta = $this->fijar_limite_en_pesos($cliente, 200);

        $saldo_previo = (float) CurrentAcountHelper::getSaldo($cuenta->id);

        $pedido = $this->crear_pedido($cliente->id);

        $response = $this->putJson(
            'api/order/'.$pedido->id,
            $this->payload_con_cantidades_nuevas($pedido, 'Confirmado')
        );

        $response->assertStatus(200);

        $this->assertTrue(
            Sale::where('order_id', $pedido->id)->exists(),
            'El aviso de limite de credito rechazo un pedido que con las cantidades nuevas ('
                .$this->total_con_cantidades_nuevas().') entra dentro del limite (200): se evaluo contra el importe viejo ('
                .$this->total_esperado().').'
        );

        $this->assertEquals(
            $saldo_previo + $this->total_con_cantidades_nuevas(),
            (float) CurrentAcountHelper::getSaldo($cuenta->id),
            'El saldo no se movio por el importe nuevo.'
        );
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /**
     * El payload del FORMULARIO con las cantidades cambiadas, que es el camino que rompia.
     *
     * Es `payload_del_select()` del trait, pero pisando `amount` con `CANTIDADES_NUEVAS`. No se
     * toca el helper compartido: las otras suites lo usan para medir que confirmar SIN cambiar
     * nada conserva los renglones, que es el escenario opuesto a este.
     *
     * @param  \App\Models\Order  $pedido
     * @param  string             $nombre_estado
     * @return array<string,mixed>
     */
    protected function payload_con_cantidades_nuevas($pedido, $nombre_estado)
    {
        $articles = [];

        foreach ($this->renglones() as $indice => $renglon) {
            $articles[] = [
                'id'    => $renglon['article']->id,
                'pivot' => [
                    'price'  => $renglon['price'],
                    'amount' => self::CANTIDADES_NUEVAS[$indice],
                ],
            ];
        }

        return [
            'order_status_id' => $this->estado($nombre_estado)->id,
            'address_id'      => $pedido->address_id,
            'articles'        => $articles,
        ];
    }

    /**
     * El total que corresponde a `CANTIDADES_NUEVAS`, con los precios del fixture.
     *
     * @return float
     */
    protected function total_con_cantidades_nuevas()
    {
        $total = 0;

        foreach ($this->renglones() as $indice => $renglon) {
            $total += $renglon['price'] * self::CANTIDADES_NUEVAS[$indice];
        }

        return (float) $total;
    }

    /**
     * Fija el limite de credito en pesos del cliente, guardando el valor previo para restaurarlo.
     *
     * @param  \App\Models\Client  $cliente
     * @param  float|null          $limite
     * @return \App\Models\CreditAccount
     */
    protected function fijar_limite_en_pesos($cliente, $limite)
    {
        $cuenta = CreditAccount::where('model_name', 'client')
                               ->where('model_id', $cliente->id)
                               ->orderBy('moneda_id')
                               ->first();

        $this->assertNotNull($cuenta, 'El cliente "'.$cliente->name.'" no tiene credit_account.');

        if (!isset($this->limites_a_restaurar[$cuenta->id])) {
            $this->limites_a_restaurar[$cuenta->id] = [
                'id'             => $cuenta->id,
                'limite_credito' => $cuenta->limite_credito,
            ];
        }

        $cuenta->limite_credito = $limite;
        $cuenta->save();

        return $cuenta->fresh();
    }
}
