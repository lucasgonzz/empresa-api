<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tanda 2 de la misión vender-lista-obligatoria (18/9/2026), ítem A5: EDITAR una venta no le
 * cambia el empleado, salvo que el PUT mande explícitamente un `employee_id` mayor a cero.
 *
 * EL BUG: `SaleController::update()` resolvía `employee_id` con `SaleHelper::getEmployeeId($request)`,
 * que es el resolvedor del ALTA: sin `employee_id` en el request (o con 0) devuelve el empleado
 * LOGUEADO. Una venta del DUEÑO (`employee_id` null) editada por un empleado quedaba a nombre del
 * empleado —la SPA restauraba `employee_id` solo si era truthy y mandaba el logueado—, y con
 * ella se movían las comisiones y los reportes por empleado sin que nadie lo pidiera.
 *
 * Lo que fijan estos tests: el PUT sin la clave, con null y con 0 deja el empleado guardado (null
 * incluido, que es el caso del dueño; y el de otro empleado, que es el caso simétrico); y el PUT
 * con un id lo reasigna, para que la guarda no vuelva el campo de solo lectura.
 *
 * DatabaseTransactions sobre la base sembrada del slot; la venta y los empleados se crean adentro
 * de la transacción con prefijo `zz`. La venta va sin cliente, sin métodos de pago y sin caja
 * para que sea editable (`SaleHelper::motivo_por_el_que_no_se_puede_editar()`), y el PUT no
 * habla ni del cobro ni de la lista de precios, así que ninguno de los otros 422 de update()
 * entra en juego.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Update_no_cambia_el_empleado_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario (dueño) del fixture de testing. */
    const USER_ID = 500;

    /** @var int Precio del renglón. */
    const PRECIO = 100;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var \App\Models\User */
    protected $owner;

    /** @var \App\Models\Article */
    protected $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::find(self::USER_ID);

        if (is_null($this->owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        /*
         * Sin stock a propósito: `SaleHelper::usa_stock()` da false con stock null y el PUT no
         * mueve stock. Acá se mide el empleado, nada más.
         */
        $this->article = Article::create([
            'name'        => 'zz Articulo update no cambia el empleado',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'status'      => 'active',
        ]);
    }

    /**
     * Un empleado del usuario 500 (molde de tests/Feature/Vender/4).
     *
     * @param  string  $sufijo
     * @return \App\Models\User
     */
    protected function empleado($sufijo)
    {
        return User::create([
            'name'     => 'zz Empleado '.$sufijo.' (update no cambia el empleado)',
            'email'    => 'zz-update-empleado-'.$sufijo.'-'.uniqid().'@test.local',
            'password' => Hash::make('zz-password-testing'),
            'status'   => 'commerce',
            'owner_id' => self::USER_ID,
        ]);
    }

    /**
     * Venta de mostrador guardada a nombre de quien se pida (null = el dueño). Va directo a la
     * base (molde de Sales/9 y Vender/4): lo que se mide es el update.
     *
     * @param  int|null  $employee_id
     * @return \App\Models\Sale
     */
    protected function venta_guardada($employee_id)
    {
        return Sale::create([
            'user_id'                    => self::USER_ID,
            'employee_id'                => $employee_id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => self::PRECIO * self::CANTIDAD,
            'total'                      => self::PRECIO * self::CANTIDAD,
        ]);
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id} (molde de tests/Feature/Vender/4), SIN
     * `employee_id`: cada test decide si la clave viaja y con qué.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update($overrides = [])
    {
        $total = self::PRECIO * self::CANTIDAD;

        return array_merge([
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'discount_stock'             => 0,
            'sub_total'                  => $total,
            'total'                      => $total,
            'moneda_id'                  => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => self::PRECIO,
                    'amount'       => self::CANTIDAD,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * 🔴 EL CASO DEL BUG: la venta del dueño (empleado null), editada por un empleado logueado y
     * sin `employee_id` en el PUT. Antes quedaba a nombre del empleado; ahora sigue siendo del
     * dueño.
     *
     * @group sales
     * @test
     */
    public function la_venta_del_dueno_editada_por_un_empleado_sin_la_clave_sigue_siendo_del_dueno()
    {
        $venta = $this->venta_guardada(null);

        $this->actingAs($this->empleado('a'), 'web');

        $this->putJson('api/sale/'.$venta->id, $this->payload_update())->assertStatus(200);

        $this->assertNull(
            Sale::find($venta->id)->employee_id,
            'Un PUT sin employee_id le puso el empleado logueado a una venta del dueño.'
        );
    }

    /**
     * Lo mismo con la clave en null y en 0, que es lo que manda la SPA de esta misión para una
     * venta del dueño (restaura el `employee_id` de la venta, null incluido) y lo que mandaba el
     * select en su placeholder.
     *
     * @group sales
     * @test
     */
    public function la_venta_del_dueno_con_employee_id_en_null_o_en_cero_sigue_siendo_del_dueno()
    {
        $venta = $this->venta_guardada(null);

        $this->actingAs($this->empleado('a'), 'web');

        $this->putJson('api/sale/'.$venta->id, $this->payload_update(['employee_id' => null]))->assertStatus(200);

        $this->assertNull(Sale::find($venta->id)->employee_id, 'employee_id: null en el PUT no puede reasignar la venta.');

        $this->putJson('api/sale/'.$venta->id, $this->payload_update(['employee_id' => 0]))->assertStatus(200);

        $this->assertNull(Sale::find($venta->id)->employee_id, 'employee_id: 0 en el PUT no puede reasignar la venta.');
    }

    /**
     * El caso simétrico: la venta del empleado A editada por el empleado B sin la clave sigue
     * siendo de A.
     *
     * @group sales
     * @test
     */
    public function la_venta_de_un_empleado_editada_por_otro_sin_la_clave_sigue_siendo_del_primero()
    {
        $empleado_a = $this->empleado('a');
        $empleado_b = $this->empleado('b');

        $venta = $this->venta_guardada($empleado_a->id);

        $this->actingAs($empleado_b, 'web');

        $this->putJson('api/sale/'.$venta->id, $this->payload_update())->assertStatus(200);

        $this->assertEquals(
            $empleado_a->id,
            (int) Sale::find($venta->id)->employee_id,
            'Un PUT sin employee_id le cambió el empleado a la venta.'
        );
    }

    /**
     * Mandar un `employee_id` sí reasigna: la guarda no vuelve el campo de solo lectura. Es lo que
     * manda la SPA anterior a esta misión (el empleado logueado) y sigue haciendo lo mismo que
     * hacía.
     *
     * @group sales
     * @test
     */
    public function un_put_con_employee_id_reasigna_la_venta()
    {
        $empleado_a = $this->empleado('a');
        $empleado_b = $this->empleado('b');

        $venta = $this->venta_guardada($empleado_a->id);

        $this->actingAs($this->owner, 'web');

        $this->putJson('api/sale/'.$venta->id, $this->payload_update(['employee_id' => $empleado_b->id]))->assertStatus(200);

        $this->assertEquals(
            $empleado_b->id,
            (int) Sale::find($venta->id)->employee_id,
            'Un PUT con employee_id tiene que reasignar la venta a ese empleado.'
        );

        // Y de vuelta al dueño no se puede por este camino (null y 0 preservan): queda con B.
        $this->putJson('api/sale/'.$venta->id, $this->payload_update(['employee_id' => null]))->assertStatus(200);

        $this->assertEquals($empleado_b->id, (int) Sale::find($venta->id)->employee_id);
    }
}
