<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria (17/9/2026), auditoría del módulo Vender: `SaleController::update()`
 * FORZABA valores cuando la clave no viajaba en el PUT, la misma clase de bug que el de San Cayetano
 * con `omitir_en_cuenta_corriente` (tests/Feature/Sales/9), en tres campos:
 *
 *  - `aplicar_recargos_directo_a_items`, asignado pelado: sin la clave quedaba en null con los
 *    precios del pivot todavía recargados, y el recargo se sumaba DOS veces al confirmar una venta
 *    chequeada, al puntuar y al facturar.
 *  - `discount_stock`, con `!is_null(...) ? ... : 1`: la ausencia de la clave ACTIVABA el
 *    descuento de stock de una venta que no lo hacía, y `$se_activando_discount_stock` descontaba
 *    el renglón entero.
 *  - `iva_aplicado`, con el mismo `: 1`: una venta con `iva_aplicado = 0` pasaba a 1, y
 *    `PuntosBaseHelper` la lee para la base de puntos. El comentario decía "se preserva" y no
 *    preservaba.
 *
 * `BudgetController::update()` ya preservaba los tres; este test fija que la venta haga lo mismo:
 * una SPA que no manda la clave no puede cambiar el comportamiento de la venta. Y que mandarla sí
 * la cambia, para que la guarda no vuelva los campos de solo lectura.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Update_preserva_campos_que_no_viajan_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var int Precio del renglón. */
    const PRECIO = 100;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var \App\Models\Article */
    protected $article;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::find(self::USER_ID);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        /*
         * Sin stock a propósito: `SaleHelper::usa_stock()` da false con stock null, así que el
         * PUT que activa `discount_stock` no descuenta nada y el test mide solo las columnas.
         */
        $this->article = Article::create([
            'name'        => 'zz Articulo update preserva campos',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'status'      => 'active',
        ]);
    }

    /**
     * Venta de mostrador guardada con los tres campos en el valor OPUESTO al que forzaba el update:
     * `discount_stock = 0`, `iva_aplicado = 0`, `aplicar_recargos_directo_a_items = 1`. Va directo
     * a la base (molde de Sales/9): lo que se mide es el update.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function venta_guardada($overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                          => self::USER_ID,
            'client_id'                        => null,
            'omitir_en_cuenta_corriente'       => 0,
            'save_current_acount'              => 0,
            'terminada'                        => 1,
            'is_cerrada'                       => 0,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 0,
            'aplicar_recargos_directo_a_items' => 1,
            'moneda_id'                        => 1,
            'sub_total'                        => self::PRECIO * self::CANTIDAD,
            'total'                            => self::PRECIO * self::CANTIDAD,
        ], $overrides));
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id} (molde de tests/Feature/Vender/2 y Sales/9),
     * SIN `discount_stock`, `iva_aplicado` ni `aplicar_recargos_directo_a_items`: es el PUT de una
     * SPA anterior a marzo de 2026, que no conoce esas claves.
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
     * 🔴 EL CASO DEL BUG: el PUT no trae las tres claves y los tres campos quedan como estaban.
     * Antes: `discount_stock` pasaba a 1, `iva_aplicado` pasaba a 1 y
     * `aplicar_recargos_directo_a_items` quedaba en null.
     *
     * @group sales
     * @test
     */
    public function un_put_sin_las_claves_deja_los_tres_campos_como_estaban()
    {
        $venta = $this->venta_guardada();

        $this->putJson('api/sale/'.$venta->id, $this->payload_update())->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertSame(
            0,
            (int) $despues->discount_stock,
            'Un PUT sin discount_stock activó el descuento de stock de una venta que no lo hacía.'
        );

        $this->assertSame(
            0,
            (int) $despues->iva_aplicado,
            'Un PUT sin iva_aplicado le cambió el IVA aplicado a la venta.'
        );

        /*
         * assertNotNull primero y no sobra: la columna es nullable, y con la asignación pelada el
         * campo quedaba en NULL, que casteado a int da 0 y no 1, pero un assertSame(1, ...) sobre
         * null explicaría menos que este mensaje.
         */
        $this->assertNotNull(
            $despues->aplicar_recargos_directo_a_items,
            'Un PUT sin aplicar_recargos_directo_a_items dejó el campo en NULL: lo pisó.'
        );
        $this->assertSame(1, (int) $despues->aplicar_recargos_directo_a_items);
    }

    /**
     * Mandar las claves sí cambia los valores: la guarda no puede volver los campos de solo
     * lectura. `discount_stock` en 1 es la activación (permitida), `iva_aplicado` se prende y la
     * opción de recargos se apaga.
     *
     * @group sales
     * @test
     */
    public function un_put_con_las_claves_si_las_pisa()
    {
        $venta = $this->venta_guardada();

        $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'discount_stock'                   => 1,
            'iva_aplicado'                     => 1,
            'aplicar_recargos_directo_a_items' => 0,
        ]))->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertSame(1, (int) $despues->discount_stock);
        $this->assertSame(1, (int) $despues->iva_aplicado);
        $this->assertSame(0, (int) $despues->aplicar_recargos_directo_a_items);
    }

    /**
     * La regla que ya existía y que el arreglo no toca: `discount_stock` solo puede activarse,
     * nunca desactivarse una vez que ya descontó stock. Un PUT con `discount_stock = 0` sobre una
     * venta que ya lo tenía en 1 lo deja en 1.
     *
     * @group sales
     * @test
     */
    public function discount_stock_no_se_puede_desactivar_una_vez_activado()
    {
        $venta = $this->venta_guardada(['discount_stock' => 1]);

        $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'discount_stock' => 0,
        ]))->assertStatus(200);

        $this->assertSame(
            1,
            (int) Sale::find($venta->id)->discount_stock,
            'Una venta que ya descontó stock no puede dejar de hacerlo por un PUT.'
        );
    }

    /**
     * Le da a la cuenta la extensión `enviar_mail_a_clientes`, que es la única condición bajo la
     * cual `update()` toca `send_mail`. forceCreate porque el modelo no declara $fillable (ver
     * tests/Feature/Extenciones/1); DatabaseTransactions revierte todo.
     *
     * @return void
     */
    protected function dar_extension_de_mail()
    {
        $extencion = ExtencionEmpresa::where('slug', 'enviar_mail_a_clientes')->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'enviar_mail_a_clientes',
                'name' => 'Enviar mail a clientes',
            ]);
        }

        $user = User::find(self::USER_ID);

        if (!$user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $user->extencions()->attach($extencion->id);
        }
    }

    /**
     * 🔴 `send_mail`, la cuarta de la misma clase (tanda 2 de la misión, 18/9/2026, ítem A8): con
     * la extensión `enviar_mail_a_clientes`, un PUT sin la clave APAGABA el envío de una venta que
     * lo tenía prendido, aunque el comentario de al lado dijera "no borrar histórico". La SPA
     * anterior a abril de 2026 no manda `send_mail`. Ahora la ausencia preserva; mandarla sí la
     * cambia (también a false), para que la guarda no vuelva el campo de solo lectura.
     *
     * @group sales
     * @test
     */
    public function un_put_sin_send_mail_lo_deja_como_estaba_y_con_la_clave_lo_cambia()
    {
        $this->dar_extension_de_mail();

        $venta = $this->venta_guardada(['send_mail' => 1]);

        $this->putJson('api/sale/'.$venta->id, $this->payload_update())->assertStatus(200);

        $this->assertSame(
            1,
            (int) Sale::find($venta->id)->send_mail,
            'Un PUT sin send_mail apagó el envío de una venta que lo tenía prendido.'
        );

        $this->putJson('api/sale/'.$venta->id, $this->payload_update(['send_mail' => 0]))->assertStatus(200);

        $this->assertSame(0, (int) Sale::find($venta->id)->send_mail, 'Con la clave en 0 el envío se apaga.');

        $this->putJson('api/sale/'.$venta->id, $this->payload_update(['send_mail' => 1]))->assertStatus(200);

        $this->assertSame(1, (int) Sale::find($venta->id)->send_mail, 'Con la clave en 1 el envío se prende.');
    }

    /**
     * Sin la extensión, `send_mail` no se toca nunca, viaje o no la clave: es la regla que ya
     * existía ("sin extensión no se altera send_mail") y la guarda nueva no la cambia.
     *
     * @group sales
     * @test
     */
    public function sin_la_extension_de_mail_send_mail_no_se_toca_aunque_viaje()
    {
        $venta = $this->venta_guardada(['send_mail' => 1]);

        $this->assertFalse(
            User::find(self::USER_ID)->extencions()->where('slug', 'enviar_mail_a_clientes')->exists(),
            'Este test mide la cuenta SIN la extensión; si la base del slot la tiene sembrada para el 500, no mide nada.'
        );

        $this->putJson('api/sale/'.$venta->id, $this->payload_update(['send_mail' => 0]))->assertStatus(200);

        $this->assertSame(1, (int) Sale::find($venta->id)->send_mail);
    }
}
