<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión 56 — el update de una venta no puede cambiarle sola la cuenta corriente.
 *
 * El bug de San Cayetano tenía dos mitades. La del front —los defaults del comercio pisando los
 * valores de la venta que se estaba editando— se arregla con guards en `empresa-spa`. Esta es la
 * mitad del backend, y es la que convierte un valor pisado en pérdida de dato:
 *
 *  - `SaleController::update()` asignaba `omitir_en_cuenta_corriente` y
 *    `current_acount_payment_method_id` a secas desde el request. Un payload que no manda la clave
 *    los dejaba en null, y con eso la venta cambiaba de comportamiento sin que nadie la tocara.
 *  - `SaleHelper::updateCurrentAcountsAndCommissions()` borra el movimiento de cuenta corriente
 *    SIEMPRE y después decide si lo recrea. Con `omitir` en 1 el borrado ocurre y la recreación
 *    no, y la venta sale de la cuenta corriente del cliente sin dejar rastro en ningún lado.
 *
 * DatabaseTransactions y no RefreshDatabase: la base de testing del slot está sembrada de antes.
 */
class Update_no_pisa_omitir_en_cuenta_corriente_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var int
     */
    const USER_ID = 500;

    /**
     * El cliente de las pruebas. Se toma uno real de la base del comercio: la cuenta corriente
     * cuelga del cliente, así que sin cliente no hay nada que medir.
     *
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $cliente = Client::where('user_id', self::USER_ID)->first();

        $this->assertNotNull($cliente, 'La base de testing no tiene ningún cliente del user 500.');

        return $cliente;
    }

    /**
     * Crea una venta que va a la cuenta corriente del cliente: no omitida y con
     * `save_current_acount`.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta_en_cuenta_corriente($overrides = [])
    {
        /*
         * Con cliente, y no es un detalle: `SaleController::update()` solo llama a
         * `updateCurrentAcountsAndCommissions()` si la venta tiene `client_id`, y
         * `create_current_acount()` exige lo mismo. Una venta sin cliente no puede estar en
         * ninguna cuenta corriente, así que estos tests no ejercitarían nada de lo que la misión
         * toca en `SaleHelper`.
         */
        return Sale::create(array_merge([
            'user_id'                    => self::USER_ID,
            'client_id'                  => $this->cliente()->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 1,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'caja_id'                    => null,
            'sub_total'                  => 500,
            'total'                      => 500,
        ], $overrides));
    }

    /**
     * El payload mínimo del update, sin las dos claves que esta misión protege.
     *
     * @param  \App\Models\Sale  $sale
     * @return array
     */
    protected function payload($sale)
    {
        /*
         * `discounts_in_services`, `surchages_in_services`, `to_check`, `checked` y `confirmed`
         * van porque `update()` los asigna a secas desde el request y son NOT NULL en la tabla:
         * sin ellos el update revienta con una violación de integridad.
         * Es el mismo patrón que esta misión arregla para `omitir_en_cuenta_corriente` y
         * `current_acount_payment_method_id`, salvo que estos fallan ruidosamente en vez de
         * pisar el dato en silencio. Ver el hallazgo del INFORME.
         */
        return [
            'client_id'              => $sale->client_id,
            'save_current_acount'    => $sale->save_current_acount,
            'items'                  => [],
            'discounts'              => [],
            'surchages'              => [],
            'returned_items'         => [],
            'sub_total'              => $sale->sub_total,
            'total'                  => $sale->total,
            'discounts_in_services'  => 0,
            'surchages_in_services'  => 0,
            'to_check'               => 0,
            'checked'                => 0,
            'confirmed'              => 0,
        ];
    }

    /**
     * La cuenta del cliente para la moneda de la venta, creándola si no está.
     *
     * 🔴 Tiene que existir ANTES de cualquier update que deje la venta en la cuenta corriente:
     * `CurrentAcountFromSaleHelper::__construct()` la busca y `crear_current_acount()` usa
     * `$this->credit_account->id` sin chequear null, así que sin ella el update responde 500 con
     * "Trying to get property 'id' of non-object". Eso es un bug real y preexistente —queda
     * fichado como hallazgo—, pero acá lo que se está midiendo es otra cosa, así que se siembra.
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Models\CreditAccount
     */
    protected function credit_account_del_cliente($sale)
    {
        return CreditAccount::firstOrCreate(
            [
                'model_name' => 'client',
                'model_id'   => $sale->client_id,
                'moneda_id'  => $sale->moneda_id ? $sale->moneda_id : 1,
            ],
            [
                'user_id' => self::USER_ID,
            ]
        );
    }

    /**
     * Le siembra a la venta el movimiento de cuenta corriente que tendría si hubiera ido a la
     * cuenta del cliente.
     *
     * Se inserta a mano en vez de dejar que lo cree el flujo de alta: lo que estos tests miden es
     * qué le pasa a ese movimiento en el UPDATE, y sembrarlo hace explícito el estado del que se
     * parte. La forma —`whereNull('haber')` y `sale_id`— es la que usan tanto el borrado como la
     * consulta del log.
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Models\CurrentAcount
     */
    protected function sembrar_movimiento_de_cuenta_corriente($sale)
    {
        $credit_account = $this->credit_account_del_cliente($sale);

        return CurrentAcount::create([
            'user_id'           => self::USER_ID,
            'client_id'         => $sale->client_id,
            'sale_id'           => $sale->id,
            'credit_account_id' => $credit_account->id,
            'debe'              => $sale->total,
            'haber'             => null,
            'saldo'             => $sale->total,
            'detalle'           => 'Movimiento sembrado por el test de la misión 56',
        ]);
    }

    /**
     * Hace el update y exige que haya guardado.
     *
     * El assertStatus no es decorativo: sin él, los tests de "no cambia" quedarían en verde por
     * la razón equivocada — un update rechazado tampoco cambia nada.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array  $extra
     * @return void
     */
    protected function actualizar($sale, $extra = [])
    {
        /*
         * La cuenta del cliente tiene que existir antes: si la venta queda en la cuenta corriente,
         * el update la recrea y revienta sin ella. Ver credit_account_del_cliente().
         */
        $this->credit_account_del_cliente($sale);

        $respuesta = $this->put('api/sale/' . $sale->id, array_merge($this->payload($sale), $extra));

        $respuesta->assertStatus(200);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::find(self::USER_ID), 'web');
    }

    /**
     * 🔴 El caso del bug: el request no manda `omitir_en_cuenta_corriente` y el valor guardado no
     * se toca. Antes quedaba en null.
     *
     * @group sales
     * @test
     * @return void
     */
    public function un_request_sin_la_clave_no_cambia_omitir_en_cuenta_corriente()
    {
        $venta = $this->crear_venta_en_cuenta_corriente(['omitir_en_cuenta_corriente' => 0]);

        $this->actualizar($venta);

        $guardado = Sale::find($venta->id)->omitir_en_cuenta_corriente;

        /*
         * El assertNotNull va primero y no sobra: la columna es nullable, y sin la guarda del
         * controlador el campo queda en NULL — que casteado a int da 0 y dejaría este test en
         * verde con el bug puesto. Es el test que lleva el rótulo del caso, así que tiene que
         * distinguir "sigue en 0" de "quedó en null".
         */
        $this->assertNotNull($guardado, 'El campo quedó en NULL: el request sin la clave lo pisó.');
        $this->assertSame(
            0,
            (int) $guardado,
            'Un request sin la clave le cambió omitir_en_cuenta_corriente a la venta.'
        );
    }

    /**
     * 🔴 El comportamiento que la misión NO quiere cambiar: una venta que el usuario pasa a
     * omitida tiene que salir de la cuenta corriente del cliente.
     *
     * Es el test que ejercita `updateCurrentAcountsAndCommissions()` de verdad —con cliente, que
     * es lo que el controlador exige para llamarlo— y por lo tanto el bloque de log nuevo.
     *
     * @group sales
     * @test
     * @return void
     */
    public function pasar_una_venta_a_omitida_la_saca_de_la_cuenta_corriente()
    {
        $venta = $this->crear_venta_en_cuenta_corriente(['omitir_en_cuenta_corriente' => 0]);

        $this->sembrar_movimiento_de_cuenta_corriente($venta);

        $this->actualizar($venta, ['omitir_en_cuenta_corriente' => 1]);

        /*
         * Se cuenta si la venta tiene movimiento, no si sobrevivió UNO en particular: el update
         * borra y recrea, así que el id cambia aunque la venta siga en la cuenta corriente.
         */
        $this->assertSame(
            0,
            CurrentAcount::where('sale_id', $venta->id)->whereNull('haber')->count(),
            'La venta pasó a omitida y siguió con movimiento de cuenta corriente: cambió el comportamiento.'
        );
    }

    /**
     * Y el inverso, que es el bug: un update que no manda la clave no puede sacar la venta de la
     * cuenta corriente.
     *
     * @group sales
     * @test
     * @return void
     */
    public function un_update_sin_la_clave_deja_la_venta_en_la_cuenta_corriente()
    {
        $venta = $this->crear_venta_en_cuenta_corriente(['omitir_en_cuenta_corriente' => 0]);

        $this->sembrar_movimiento_de_cuenta_corriente($venta);

        $this->actualizar($venta);

        /*
         * Mismo criterio que el test de arriba: lo que importa es que la venta SIGA teniendo un
         * movimiento, no que sea el mismo objeto. El update borra y recrea, así que el id cambia.
         */
        $this->assertSame(
            1,
            CurrentAcount::where('sale_id', $venta->id)->whereNull('haber')->count(),
            'Un update que no manda omitir_en_cuenta_corriente sacó la venta de la cuenta corriente del cliente.'
        );
    }

    /**
     * Lo mismo para una venta que sí estaba omitida: tampoco se le toca.
     *
     * @group sales
     * @test
     * @return void
     */
    public function un_request_sin_la_clave_no_cambia_una_venta_omitida()
    {
        $venta = $this->crear_venta_en_cuenta_corriente(['omitir_en_cuenta_corriente' => 1]);

        $this->actualizar($venta);

        $this->assertSame(
            1,
            (int) Sale::find($venta->id)->omitir_en_cuenta_corriente,
            'Un request sin la clave le cambió omitir_en_cuenta_corriente a la venta.'
        );
    }

    /**
     * El método de pago tampoco se pisa cuando el request no lo manda.
     *
     * @group sales
     * @test
     * @return void
     */
    public function un_request_sin_la_clave_no_cambia_el_metodo_de_pago()
    {
        $venta = $this->crear_venta_en_cuenta_corriente(['current_acount_payment_method_id' => 3]);

        $this->actualizar($venta);

        $this->assertSame(
            3,
            (int) Sale::find($venta->id)->current_acount_payment_method_id,
            'Un request sin la clave le cambió el método de pago a la venta.'
        );
    }

    /**
     * Mandar la clave sí cambia el valor: la guarda no puede volver el campo de solo lectura.
     *
     * @group sales
     * @test
     * @return void
     */
    public function mandar_la_clave_si_cambia_el_valor()
    {
        $venta = $this->crear_venta_en_cuenta_corriente(['omitir_en_cuenta_corriente' => 0]);

        $this->actualizar($venta, [
            'omitir_en_cuenta_corriente'       => 1,
            'current_acount_payment_method_id' => 3,
        ]);

        $venta_despues = Sale::find($venta->id);

        $this->assertSame(1, (int) $venta_despues->omitir_en_cuenta_corriente);
        $this->assertSame(3, (int) $venta_despues->current_acount_payment_method_id);
    }

    /**
     * La decisión de si la venta corresponde a la cuenta corriente vive en un solo lugar, que es
     * lo que evita que el borrado y la recreación dejen de estar de acuerdo — que es exactamente
     * la forma que tomó el bug.
     *
     * @group sales
     * @test
     * @return void
     */
    public function va_a_volver_a_la_cuenta_corriente_decide_por_las_dos_condiciones()
    {
        $en_cuenta = $this->crear_venta_en_cuenta_corriente();
        $this->assertTrue(SaleHelper::va_a_volver_a_la_cuenta_corriente($en_cuenta));

        $omitida = $this->crear_venta_en_cuenta_corriente(['omitir_en_cuenta_corriente' => 1]);
        $this->assertFalse(
            SaleHelper::va_a_volver_a_la_cuenta_corriente($omitida),
            'Una venta omitida no vuelve a la cuenta corriente.'
        );

        $sin_guardar = $this->crear_venta_en_cuenta_corriente(['save_current_acount' => 0]);
        $this->assertFalse(
            SaleHelper::va_a_volver_a_la_cuenta_corriente($sin_guardar),
            'Una venta sin save_current_acount no vuelve a la cuenta corriente.'
        );
    }
}
