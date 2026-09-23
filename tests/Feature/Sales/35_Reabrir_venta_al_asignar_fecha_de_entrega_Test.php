<?php

namespace Tests\Feature\Sales;

use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use Carbon\Carbon;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión reabrir-venta-al-poner-fecha-entrega (23/9/2026).
 *
 * Una venta nacida de un pedido de la tienda queda `terminada = 1` (`CreateSaleOrderHelper::is_terminada()`:
 * el pedido no traía fecha). Al editarla y ponerle `fecha_entrega`, `SaleController::update()` solo
 * asignaba la fecha y nunca recalculaba `terminada`, así que la venta no aparecía en Por entregar,
 * que lista `terminada = 0` (visto con la venta 2299 de Unicas).
 *
 * La regla: con la extensión `ventas_con_fecha_de_entrega`, asignar una fecha que antes no había
 * reabre la venta (`terminada = 0`, `terminada_at = null`). Cambiar una fecha por otra o no mandarla
 * no la toca. `confirmed`, `to_check` y `checked` son del circuito de chequeo de depósito y no se
 * tocan nunca.
 *
 * DatabaseTransactions (vía EmpresaTestCase): la base del slot está sembrada de antes.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Reabrir_venta_al_asignar_fecha_de_entrega_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** @var string Slug de la extensión que habilita la fecha de entrega en Vender. */
    const SLUG = 'ventas_con_fecha_de_entrega';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    /**
     * Le da la extensión al comercio del fixture. forceCreate porque el modelo no declara $fillable
     * (ver tests/Feature/Extenciones/1); `extencion_empresas` puede estar vacía en la base del slot.
     *
     * @return void
     */
    protected function dar_extension_de_fecha()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Ventas con fecha de entrega',
            ]);
        }

        $user = \App\Models\User::find($this->user_id());

        $user->extencions()->syncWithoutDetaching([$extencion->id]);
    }

    /**
     * Venta de mostrador guardada directo en la base: lo que se mide es el update.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function venta_guardada($overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $this->user_id(),
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'terminada_at'               => Carbon::now(),
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'iva_aplicado'               => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => 200,
            'total'                      => 200,
        ], $overrides));
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id} (molde de Sales/31), sin la clave `fecha_entrega`
     * salvo que el test la pase.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update($overrides = [])
    {
        $articulo = $this->articulo(\Database\Seeders\testing\TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        return array_merge([
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => 200,
            'total'                      => 200,
            'moneda_id'                  => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'name'         => $articulo->name,
                    'price_vender' => 100,
                    'amount'       => 2,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * Fecha de entrega de prueba, dentro de la ventana que consulta `por_entregar()`.
     *
     * @param  int  $dias
     * @return string
     */
    protected function fecha($dias)
    {
        return Carbon::now()->addDays($dias)->format('Y-m-d');
    }

    /**
     * 1. El caso del bug: venta terminada sin fecha, con la extensión, se edita con fecha y se reabre.
     *
     * @group sales
     * @test
     */
    public function poner_fecha_a_una_venta_terminada_sin_fecha_la_reabre()
    {
        $this->dar_extension_de_fecha();

        $venta = $this->venta_guardada();

        $this->assertNull($venta->fresh()->fecha_entrega);

        $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'fecha_entrega' => $this->fecha(2),
        ]))->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertNotNull($despues->fecha_entrega, 'La fecha de entrega no se guardó.');
        $this->assertSame(0, (int) $despues->terminada, 'Asignar fecha de entrega no reabrió la venta.');
        $this->assertNull($despues->terminada_at, 'La venta reabierta tiene que quedar sin terminada_at.');
    }

    /**
     * 2. Sin la extensión no cambia nada, igual que en el alta.
     *
     * @group sales
     * @test
     */
    public function sin_la_extension_la_venta_sigue_terminada()
    {
        $venta = $this->venta_guardada();

        $this->assertFalse(
            \App\Models\User::find($this->user_id())->extencions()->where('slug', self::SLUG)->exists(),
            'Este test mide la cuenta SIN la extensión; si la base del slot la tiene sembrada para el comercio, no mide nada.'
        );

        $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'fecha_entrega' => $this->fecha(2),
        ]))->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertSame(1, (int) $despues->terminada, 'Sin la extensión, poner fecha no puede reabrir la venta.');
        $this->assertNotNull($despues->terminada_at);
    }

    /**
     * 3. Una venta que ya tenía fecha y se cambia por otra (o se repite la misma) no se reabre: pudo
     *    haberla marcado terminada alguien a mano con el botón "Terminada".
     *
     * @group sales
     * @test
     */
    public function cambiar_una_fecha_por_otra_no_toca_terminada()
    {
        $this->dar_extension_de_fecha();

        $venta = $this->venta_guardada(['fecha_entrega' => $this->fecha(1)]);

        $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'fecha_entrega' => $this->fecha(3),
        ]))->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertSame(1, (int) $despues->terminada, 'Cambiar una fecha por otra reabrió una venta terminada a mano.');
        $this->assertNotNull($despues->terminada_at);

        $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'fecha_entrega' => $this->fecha(3),
        ]))->assertStatus(200);

        $this->assertSame(1, (int) Sale::find($venta->id)->terminada, 'Repetir la misma fecha reabrió la venta.');
    }

    /**
     * 4. Un update que no trae fecha no toca `terminada`.
     *
     * @group sales
     * @test
     */
    public function un_update_sin_fecha_no_toca_terminada()
    {
        $this->dar_extension_de_fecha();

        $venta = $this->venta_guardada();

        $this->putJson('api/sale/'.$venta->id, $this->payload_update())->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertNull($despues->fecha_entrega);
        $this->assertSame(1, (int) $despues->terminada, 'Un update sin fecha reabrió la venta.');
        $this->assertNotNull($despues->terminada_at);
    }

    /**
     * 5. El caso real: la venta nace al confirmar un pedido de la tienda (terminada = 1), se edita con
     *    fecha y pasa a Por entregar. `confirmed`, `to_check` y `checked` quedan como estaban.
     *
     * @group sales
     * @test
     */
    public function la_venta_de_un_pedido_confirmado_pasa_a_por_entregar_al_ponerle_fecha()
    {
        $this->dar_extension_de_fecha();

        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar el pedido no creó la venta.');
        $this->assertSame(
            1,
            (int) $venta->terminada,
            'La venta del pedido debería nacer terminada: si no, este test no mide el bug.'
        );

        $items = [];

        foreach ($this->renglones() as $renglon) {
            $items[] = [
                'is_article'   => true,
                'id'           => $renglon['article']->id,
                'name'         => $renglon['article']->name,
                'price_vender' => $renglon['price'],
                'amount'       => $renglon['amount'],
            ];
        }

        $antes = [
            'confirmed' => (int) $venta->confirmed,
            'to_check'  => (int) $venta->to_check,
            'checked'   => (int) $venta->checked,
        ];

        $fecha = $this->fecha(2);

        $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'client_id'           => $cliente->id,
            'save_current_acount' => 1,
            'sub_total'           => $this->total_esperado(),
            'total'               => $this->total_esperado(),
            'items'               => $items,
            'to_check'            => $antes['to_check'],
            'checked'             => $antes['checked'],
            'confirmed'           => $antes['confirmed'],
            'fecha_entrega'       => $fecha,
        ]))->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertSame(0, (int) $despues->terminada, 'La venta del pedido no se reabrió al ponerle fecha.');
        $this->assertNull($despues->terminada_at);
        $this->assertSame($antes['confirmed'], (int) $despues->confirmed);
        $this->assertSame($antes['to_check'], (int) $despues->to_check);
        $this->assertSame($antes['checked'], (int) $despues->checked);

        $respuesta = $this->getJson('api/sale/por-entregar/'.$this->fecha(0).'/'.$this->fecha(7))
                          ->assertStatus(200);

        $ids = collect($respuesta->json('models'))->pluck('id')->all();

        $this->assertContains($venta->id, $ids, 'Por entregar no devuelve la venta reabierta.');
    }
}
