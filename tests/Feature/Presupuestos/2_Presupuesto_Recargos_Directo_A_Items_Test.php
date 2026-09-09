<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Discount;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Surchage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Guardar un presupuesto desde VENDER con "aplicar los recargos de esta venta directamente a los
 * precios de los articulos" activo.
 *
 * EL BUG QUE FIJAN ESTOS TESTS: la columna `aplicar_recargos_directo_a_items` existia en `sales`
 * (migracion 2026_02_20_124235) pero NO en `budgets`. Con la opcion activa la SPA manda cada
 * articulo con el `price` YA RECARGADO y un `total` que no vuelve a sumar el recargo, pero
 * `BudgetHelper::getTotal()` se lo re-aplicaba siempre: la diferencia se pasaba del margen de 3 de
 * `BudgetController::store()` y guardar moria con 500 "El total del presupuesto no corresponde con
 * los productos ingresados".
 *
 * El caso con el flag APAGADO no es relleno: es el que prueba que el camino de siempre —el 99% de
 * los presupuestos— sigue sumando el recargo como antes.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un refresh
 * la vaciaria. Mismo criterio que `1_Confirmar_y_anular_presupuesto_Test`.
 */
class Presupuesto_Recargos_Directo_A_Items_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** Recargo de la prueba, en porcentaje. */
    const PORCENTAJE_RECARGO = 10;

    /**
     * Descuento de la prueba, en porcentaje. La opcion es SOLO de recargos: el precio del pivot no
     * trae descuentos adentro, asi que estos se tienen que seguir aplicando con el flag activo.
     */
    const PORCENTAJE_DESCUENTO = 10;

    /** Slug de la extension que gatea `BudgetController::duplicate()`. */
    const EXTENCION_DUPLICAR = 'duplicar_presupuestos';

    /** Precio de lista del articulo, ANTES del recargo. */
    const PRECIO_SIN_RECARGO = 100;

    /** El mismo precio con el 10% adentro, que es lo que manda la SPA con la opcion activa. */
    const PRECIO_CON_RECARGO = 110;

    /** Cantidad por renglon. Con 5 la diferencia entre aplicar y no aplicar es 55: bien lejos del margen de 3. */
    const CANTIDAD = 5;

    /**
     * ⚠️ `budget_statuses` puede venir vacia en la base del slot (medido el 21/8/2026). Se siembra
     * con ids explicitos para no depender del auto-increment; `DatabaseTransactions` lo revierte.
     *
     * Importa mas de lo que parece: `BudgetHelper::checkStatus()` hace `$budget->budget_status->name`
     * sin chequear null, asi que sin estas filas el flujo de confirmar revienta en vez de fallar.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $estados = [
            Self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            Self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            $existente = BudgetStatus::find($id);

            if (is_null($existente)) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * Autentica al usuario de testing, o saltea el test si la base no lo tiene sembrado.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Cliente del usuario de testing, con su `credit_account` en pesos.
     *
     * El fixture trae `credit_accounts` para los PROVEEDORES y ninguna para los clientes, y
     * `CurrentAcountFromSaleHelper::crear_current_acount()` la usa sin chequear null: sin esta fila
     * el test de confirmar revienta. Mismo apaño que en `1_Confirmar_y_anular_presupuesto_Test`.
     *
     * @return \App\Models\Client
     */
    protected function cliente_de_testing()
    {
        $client = Client::where('user_id', 500)->first();

        if (is_null($client)) {
            $this->markTestSkipped('La base de testing no tiene ningun cliente del usuario 500.');
        }

        $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $client->id)
                                        ->where('moneda_id', 1)
                                        ->first();

        if (is_null($credit_account)) {

            CreditAccount::create([
                'model_name' => 'client',
                'model_id'   => $client->id,
                'moneda_id'  => 1,
                'saldo'      => 0,
                'user_id'    => 500,
            ]);
        }

        return $client;
    }

    /**
     * @return \App\Models\Article
     */
    protected function articulo_de_testing()
    {
        return Article::create([
            'name'       => 'zz Test recargos directo a items '.uniqid(),
            'user_id'    => 500,
            'costo_real' => 50,
        ]);
    }

    /**
     * @return \App\Models\Surchage
     */
    protected function recargo_de_testing()
    {
        return Surchage::create([
            'name'       => 'zz Recargo test '.uniqid(),
            'percentage' => Self::PORCENTAJE_RECARGO,
            'user_id'    => 500,
        ]);
    }

    /**
     * @return \App\Models\Discount
     */
    protected function descuento_de_testing()
    {
        return Discount::create([
            'name'       => 'zz Descuento test '.uniqid(),
            'percentage' => Self::PORCENTAJE_DESCUENTO,
            'user_id'    => 500,
        ]);
    }

    /**
     * @param float $precio Precio del servicio, que despues viaja igual en el pivot.
     * @return \App\Models\Service
     */
    protected function servicio_de_testing($precio)
    {
        return Service::create([
            'name'    => 'zz Servicio test recargos '.uniqid(),
            'price'   => $precio,
            'user_id' => 500,
        ]);
    }

    /**
     * Le da al usuario de testing la extension que gatea `duplicate()`, creando la fila del catalogo
     * si la base del slot no la tiene sembrada.
     *
     * `extencion_empresas` se siembra por `ExtencionDuplicarPresupuestosSeeder`, que puede no haber
     * corrido en esta base (medido el 9/9/2026: no estaba). Sin la extension, `duplicate()` corta en
     * 403 antes de tocar nada y el test no mediria el flag. `DatabaseTransactions` revierte las dos
     * filas.
     *
     * @return void
     */
    protected function dar_extension_duplicar()
    {
        $extencion = ExtencionEmpresa::where('slug', Self::EXTENCION_DUPLICAR)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => Self::EXTENCION_DUPLICAR,
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $user = User::find(500);

        if (!$user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $user->extencions()->attach($extencion->id);
        }
    }

    /**
     * Payload de POST api/budget con un articulo y un recargo.
     *
     * `$precio` y `$total` los decide cada test a proposito: son justamente la diferencia entre el
     * camino con la opcion activa (precio ya recargado, total sin re-sumar) y el de siempre.
     *
     * @param \App\Models\Client $client
     * @param \App\Models\Article $article
     * @param \App\Models\Surchage $surchage
     * @param float $precio Precio por unidad que viaja en el renglon.
     * @param float $total Total declarado del presupuesto.
     * @param int|null $flag Valor de `aplicar_recargos_directo_a_items`.
     * @return array
     */
    protected function payload_crear($client, $article, $surchage, $precio, $total, $flag)
    {
        return [
            'client_id'              => $client->id,
            'start_at'               => null,
            'finish_at'              => null,
            'observations'           => null,
            'price_type_id'          => null,
            'sale_status_id'         => null,
            'discount_stock'         => 0,
            'iva_aplicado'           => 1,
            'total'                  => $total,
            'budget_status_id'       => Self::ESTADO_SIN_CONFIRMAR,
            'address_id'             => null,
            'surchages_in_services'  => 1,
            'discounts_in_services'  => 1,
            'aplicar_recargos_directo_a_items' => $flag,
            'moneda_id'              => 1,
            'valor_dolar'            => null,
            'discounts'              => [],
            'surchages'              => [[
                'id'         => $surchage->id,
                'percentage' => Self::PORCENTAJE_RECARGO,
            ]],
            'services'               => [],
            'promocion_vinotecas'    => [],
            'articles'               => [[
                'id'                          => $article->id,
                'status'                      => $article->status,
                'cost_in_dollars'             => null,
                'name'                        => $article->name,
                'name_vender_personalizado'   => null,
                'amount'                      => Self::CANTIDAD,
                'price'                       => $precio,
                'costo_real'                  => 50,
                'unidades_individuales'       => null,
                'presentacion'                => null,
                'price_type_personalizado_id' => null,
                'bonus'                       => null,
                'location'                    => null,
            ]],
        ];
    }

    /**
     * Arma un presupuesto directamente en la base (sin pasar por el controlador) con un renglon y
     * un recargo adjuntos. Sirve para medir `getTotal()` en aislamiento.
     *
     * @param float $precio Precio del pivot del renglon.
     * @param int|null $flag Valor de `aplicar_recargos_directo_a_items`.
     * @return \App\Models\Budget
     */
    protected function presupuesto_armado_a_mano($precio, $flag)
    {
        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $surchage = $this->recargo_de_testing();

        $budget = Budget::create([
            'user_id'               => 500,
            'client_id'             => $client->id,
            'budget_status_id'      => Self::ESTADO_SIN_CONFIRMAR,
            'total'                 => $precio * Self::CANTIDAD,
            'discount_stock'        => 0,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
            'aplicar_recargos_directo_a_items' => $flag,
        ]);

        $budget->articles()->attach($article->id, [
            'amount' => Self::CANTIDAD,
            'price'  => $precio,
        ]);

        $budget->surchages()->attach($surchage->id, [
            'percentage' => Self::PORCENTAJE_RECARGO,
        ]);

        return Budget::withAll()->find($budget->id);
    }

    /**
     * Igual que `presupuesto_armado_a_mano()` pero con un SERVICIO en vez de un articulo, y con
     * `surchages_in_services` en 1.
     *
     * Es el tercer bloque de `getTotal()`, el unico donde la guarda vive dentro de otra condicion
     * (`$budget->surchages_in_services && $aplicar_surchages`). Va sin articulos a proposito: asi el
     * numero que se mide es solo el del servicio y no hay que restarle nada.
     *
     * @param float $precio Precio del pivot del servicio.
     * @param int|null $flag Valor de `aplicar_recargos_directo_a_items`.
     * @return \App\Models\Budget
     */
    protected function presupuesto_con_servicio($precio, $flag)
    {
        $client = $this->cliente_de_testing();
        $service = $this->servicio_de_testing($precio);
        $surchage = $this->recargo_de_testing();

        $budget = Budget::create([
            'user_id'               => 500,
            'client_id'             => $client->id,
            'budget_status_id'      => Self::ESTADO_SIN_CONFIRMAR,
            'total'                 => $precio * Self::CANTIDAD,
            'discount_stock'        => 0,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
            'aplicar_recargos_directo_a_items' => $flag,
        ]);

        $budget->services()->attach($service->id, [
            'amount' => Self::CANTIDAD,
            'price'  => $precio,
        ]);

        $budget->surchages()->attach($surchage->id, [
            'percentage' => Self::PORCENTAJE_RECARGO,
        ]);

        return Budget::withAll()->find($budget->id);
    }

    /**
     * 🔴 EL TEST DEL BUG: con la opcion activa, guardar responde 201 y no 500.
     *
     * El precio del renglon ya trae el 10% adentro (110, no 100) y el total NO lo vuelve a sumar
     * (550, no 605). Antes de este arreglo `getTotal()` devolvia 605, la diferencia contra 550 era
     * 55 —muy por encima del margen de 3— y `store()` tiraba la Exception.
     *
     * @group presupuestos
     * @test
     */
    public function guardar_presupuesto_con_recargos_directo_a_items_responde_201()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $surchage = $this->recargo_de_testing();

        $total = Self::PRECIO_CON_RECARGO * Self::CANTIDAD;

        $payload = $this->payload_crear(
            $client,
            $article,
            $surchage,
            Self::PRECIO_CON_RECARGO,
            $total,
            1
        );

        $response = $this->post('api/budget', $payload);

        $response->assertStatus(201);

        $budget_id = $response->json('model.id');
        $this->assertNotNull($budget_id);

        $budget = Budget::find($budget_id);

        $this->assertEquals(
            1,
            (int) $budget->aplicar_recargos_directo_a_items,
            'El presupuesto tiene que quedar persistido con la opcion activa.'
        );

        $this->assertEquals(
            $total,
            (float) $budget->total,
            'El total guardado es el que mando la SPA, sin re-sumar el recargo.'
        );
    }

    /**
     * El camino de siempre no se toco: precios SIN recargar y total con el recargo sumado.
     *
     * Este es el test que prueba que el arreglo no rompio el 99% de los presupuestos.
     *
     * @group presupuestos
     * @test
     */
    public function guardar_presupuesto_sin_recargos_directo_a_items_sigue_respondiendo_201()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $surchage = $this->recargo_de_testing();

        $sub_total = Self::PRECIO_SIN_RECARGO * Self::CANTIDAD;
        $total = $sub_total + ($sub_total * Self::PORCENTAJE_RECARGO / 100);

        $payload = $this->payload_crear(
            $client,
            $article,
            $surchage,
            Self::PRECIO_SIN_RECARGO,
            $total,
            null
        );

        $response = $this->post('api/budget', $payload);

        $response->assertStatus(201);

        $budget = Budget::find($response->json('model.id'));

        $this->assertNull(
            $budget->aplicar_recargos_directo_a_items,
            'Sin la opcion, la columna queda en null: un presupuesto viejo se comporta igual.'
        );

        /*
            La columna en null sola no mide nada: un 201 con el total mal guardado la dejaria igual.
            Lo que prueba que el camino de siempre sigue vivo es el NUMERO: 100 x 5 = 500 mas el 10%
            de recargo = 550, que es el total que mando la SPA y el que `getTotal()` tiene que
            reproducir renglon por renglon (si no, `store()` habria tirado el 500 del margen de 3).
        */
        $this->assertEquals(
            $total,
            (float) $budget->total,
            'El total guardado tiene que ser el sub total mas el recargo, como siempre.'
        );

        $this->assertEquals(
            $total,
            round(BudgetHelper::getTotal(Budget::withAll()->find($budget->id)), 2),
            'Y getTotal() sobre el presupuesto guardado tiene que dar ese mismo numero.'
        );
    }

    /**
     * `getTotal()` con la opcion activa devuelve la suma cruda de los precios del pivot.
     *
     * @group presupuestos
     * @test
     */
    public function get_total_con_la_opcion_activa_no_re_aplica_el_recargo()
    {
        $this->autenticar();

        $budget = $this->presupuesto_armado_a_mano(Self::PRECIO_CON_RECARGO, 1);

        $this->assertEquals(
            Self::PRECIO_CON_RECARGO * Self::CANTIDAD,
            round(BudgetHelper::getTotal($budget), 2),
            'El recargo ya viene adentro del precio del pivot: getTotal() no lo puede volver a sumar.'
        );
    }

    /**
     * Y con la opcion apagada lo sigue aplicando, como siempre.
     *
     * @group presupuestos
     * @test
     */
    public function get_total_con_la_opcion_apagada_si_aplica_el_recargo()
    {
        $this->autenticar();

        $budget = $this->presupuesto_armado_a_mano(Self::PRECIO_SIN_RECARGO, null);

        $sub_total = Self::PRECIO_SIN_RECARGO * Self::CANTIDAD;
        $esperado = $sub_total + ($sub_total * Self::PORCENTAJE_RECARGO / 100);

        $this->assertEquals(
            $esperado,
            round(BudgetHelper::getTotal($budget), 2),
            'Sin la opcion, getTotal() tiene que seguir sumando el recargo sobre el precio de lista.'
        );
    }

    /**
     * 🔴 Un PUT que NO trae el campo no puede apagar la opcion.
     *
     * No es hipotetico: la spa actual manda el campo, pero la api y la spa no llegan juntas a
     * produccion, y en la ventana entre un despliegue y el otro la spa anterior hace exactamente
     * este PUT. Si `update()` asignara pelado, editar un presupuesto guardado con la opcion activa
     * lo dejaria con el flag en null y los precios del pivot todavia recargados. El update no
     * valida el total, asi que no falla ahi: revienta despues, al confirmar, con una venta inflada
     * el porcentaje del recargo.
     *
     * @group presupuestos
     * @test
     */
    public function un_update_sin_el_campo_no_apaga_la_opcion()
    {
        $this->autenticar();

        $budget = $this->presupuesto_armado_a_mano(Self::PRECIO_CON_RECARGO, 1);

        $payload = [
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'update de una spa que no manda el campo',
            'total'                 => $budget->total,
            'budget_status_id'      => Self::ESTADO_SIN_CONFIRMAR,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'moneda_id'             => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ];

        $this->put('api/budget/'.$budget->id, $payload)->assertStatus(200);

        $budget->refresh();

        $this->assertEquals(
            1,
            (int) $budget->aplicar_recargos_directo_a_items,
            'Un PUT sin el campo tiene que preservar la opcion, no apagarla.'
        );
    }

    /**
     * Y un PUT que SI manda el campo en 0 lo apaga: preservar es solo para el campo ausente.
     *
     * @group presupuestos
     * @test
     */
    public function un_update_que_manda_el_campo_en_cero_si_apaga_la_opcion()
    {
        $this->autenticar();

        $budget = $this->presupuesto_armado_a_mano(Self::PRECIO_CON_RECARGO, 1);

        $payload = [
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'el usuario desmarco la opcion',
            'total'                 => $budget->total,
            'budget_status_id'      => Self::ESTADO_SIN_CONFIRMAR,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id'             => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ];

        $this->put('api/budget/'.$budget->id, $payload)->assertStatus(200);

        $budget->refresh();

        $this->assertEquals(
            0,
            (int) $budget->aplicar_recargos_directo_a_items,
            'Un 0 explicito tiene que apagar la opcion.'
        );
    }

    /**
     * 🔴 Pedido explicito de Lucas: al confirmar, la venta que nace del presupuesto se lleva LOS
     * RECARGOS y LA OPCION.
     *
     * Si se llevara solo los recargos, `SaleHelper::getTotalSale()` —que tiene la misma guarda—
     * los volveria a sumar sobre precios que ya los traen adentro, y la venta quedaria inflada un
     * 10% respecto del presupuesto que el cliente firmo.
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_lleva_la_opcion_y_los_recargos_a_la_venta()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $surchage = $this->recargo_de_testing();

        $total = Self::PRECIO_CON_RECARGO * Self::CANTIDAD;

        $payload = $this->payload_crear(
            $client,
            $article,
            $surchage,
            Self::PRECIO_CON_RECARGO,
            $total,
            1
        );

        $budget_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $this->post('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $this->assertEquals(
            1,
            (int) $sale->aplicar_recargos_directo_a_items,
            'La venta que nace del presupuesto tiene que heredar la opcion.'
        );

        $sale->load('surchages');

        $this->assertCount(
            1,
            $sale->surchages,
            'La venta tiene que quedar con el recargo attacheado, no solo con la opcion.'
        );

        $this->assertEquals(
            Self::PORCENTAJE_RECARGO,
            (float) $sale->surchages->first()->pivot->percentage,
            'El porcentaje del recargo se copia tal cual del presupuesto.'
        );
    }

    /**
     * 🔴 EL NUMERO DEL BUG, del lado de la venta: confirmar tiene que dejar la venta en 550, no en
     * 605.
     *
     * El test de arriba mira el flag y el recargo adjunto, que son el MECANISMO. Este mira el
     * RESULTADO, que es lo unico que el cliente ve en el papel: `sales.total` se copia de
     * `budgets.total` en `BudgetHelper::saveSale()`, y `SaleHelper::getTotalSale()` lo tiene que
     * reproducir sin re-aplicar el recargo sobre precios que ya lo traen adentro.
     *
     * Que las dos cuentas coincidan no es redundante: son dos caminos distintos (una copia de
     * columna y un recorrido de los pivots). Si divergen, la venta se guarda con un total y se
     * imprime, factura y cobra con otro.
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_deja_la_venta_con_el_total_del_presupuesto()
    {
        $this->autenticar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $surchage = $this->recargo_de_testing();

        $total = Self::PRECIO_CON_RECARGO * Self::CANTIDAD;

        $payload = $this->payload_crear(
            $client,
            $article,
            $surchage,
            Self::PRECIO_CON_RECARGO,
            $total,
            1
        );

        $budget_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $this->post('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $this->assertEquals(
            $total,
            (float) $sale->total,
            'La venta nace con el total del presupuesto (110 x 5 = 550), no con el recargo sumado '
                .'de nuevo (605).'
        );

        $this->assertEquals(
            $total,
            round(SaleHelper::getTotalSale($sale, true, true, false, true), 2),
            'Y recalcular la venta con getTotalSale() tiene que dar ese mismo 550: los precios de '
                .'los renglones ya traen el 10% adentro.'
        );
    }

    /**
     * 🔴 Duplicar un presupuesto con la opcion activa responde 201 y no 500.
     *
     * `BudgetController::duplicate()` valida el total contra `getTotal()` con el mismo margen de 3
     * que `store()`, y `BudgetDuplicarHelper` re-adjunta los articulos con el MISMO precio del
     * origen (ya recargado). Si el duplicado no se llevara el flag, `getTotal()` volveria a sumar el
     * recargo sobre esos precios, la diferencia contra el total copiado seria 55 y duplicar moriria
     * con la misma Exception que el alta.
     *
     * @group presupuestos
     * @test
     */
    public function duplicar_copia_la_opcion_y_no_re_aplica_el_recargo()
    {
        $this->autenticar();
        $this->dar_extension_duplicar();

        $client = $this->cliente_de_testing();
        $article = $this->articulo_de_testing();
        $surchage = $this->recargo_de_testing();

        $total = Self::PRECIO_CON_RECARGO * Self::CANTIDAD;

        $payload = $this->payload_crear(
            $client,
            $article,
            $surchage,
            Self::PRECIO_CON_RECARGO,
            $total,
            1
        );

        $origen_id = $this->post('api/budget', $payload)
                            ->assertStatus(201)
                            ->json('model.id');

        $response = $this->post('api/budget/'.$origen_id.'/duplicate');

        $response->assertStatus(201);

        $duplicado_id = $response->json('model.id');

        $this->assertNotNull($duplicado_id);

        $this->assertNotEquals(
            $origen_id,
            $duplicado_id,
            'Duplicar tiene que crear un presupuesto nuevo, no devolver el mismo.'
        );

        $duplicado = Budget::withAll()->find($duplicado_id);

        $this->assertEquals(
            1,
            (int) $duplicado->aplicar_recargos_directo_a_items,
            'El duplicado tiene que copiar la opcion del origen.'
        );

        $this->assertEquals(
            $total,
            (float) $duplicado->total,
            'Y el total copiado es el del origen: 110 x 5 = 550.'
        );

        $this->assertEquals(
            $total,
            round(BudgetHelper::getTotal($duplicado), 2),
            'getTotal() sobre el duplicado no puede re-aplicar el recargo: sus renglones se '
                .'adjuntaron con el precio ya recargado del origen.'
        );
    }

    /**
     * Con la opcion activa los DESCUENTOS se siguen aplicando: la opcion es solo de recargos.
     *
     * El numero lo dice todo: 110 x 5 = 550, menos el 10% de descuento = 495. Si la guarda hubiera
     * quedado envolviendo tambien los `foreach ($budget->discounts ...)` —estan pegados, uno arriba
     * del otro, en los tres bloques de `getTotal()`— este test daria 550 y todo presupuesto con
     * descuento y la opcion activa se guardaria con el total del cliente inflado.
     *
     * @group presupuestos
     * @test
     */
    public function los_descuentos_se_siguen_aplicando_con_la_opcion_activa()
    {
        $this->autenticar();

        $budget = $this->presupuesto_armado_a_mano(Self::PRECIO_CON_RECARGO, 1);

        $discount = $this->descuento_de_testing();

        $budget->discounts()->attach($discount->id, [
            'percentage' => Self::PORCENTAJE_DESCUENTO,
        ]);

        $budget = Budget::withAll()->find($budget->id);

        $sub_total = Self::PRECIO_CON_RECARGO * Self::CANTIDAD;
        $esperado = $sub_total - ($sub_total * Self::PORCENTAJE_DESCUENTO / 100);

        $this->assertEquals(
            $esperado,
            round(BudgetHelper::getTotal($budget), 2),
            'Con la opcion activa el descuento se sigue restando: 550 menos el 10% = 495.'
        );
    }

    /**
     * Tercer bloque de `getTotal()`: los SERVICIOS con `surchages_in_services` en 1.
     *
     * Es el unico lugar donde la guarda vive dentro de otra condicion
     * (`$budget->surchages_in_services && $aplicar_surchages`), asi que es el mas facil de romper
     * sin que ningun otro test se entere: los presupuestos de servicios no tienen articulos.
     *
     * @group presupuestos
     * @test
     */
    public function get_total_de_servicios_con_la_opcion_activa_no_re_aplica_el_recargo()
    {
        $this->autenticar();

        $budget = $this->presupuesto_con_servicio(Self::PRECIO_CON_RECARGO, 1);

        $this->assertEquals(
            Self::PRECIO_CON_RECARGO * Self::CANTIDAD,
            round(BudgetHelper::getTotal($budget), 2),
            'El servicio ya viene con el recargo adentro del precio del pivot: 110 x 5 = 550.'
        );
    }

    /**
     * Y con la opcion apagada el mismo presupuesto de servicios SI suma el recargo, como siempre.
     *
     * Sin este par, la guarda podria haber apagado el recargo de los servicios para todo el mundo y
     * el test de arriba seguiria verde.
     *
     * @group presupuestos
     * @test
     */
    public function get_total_de_servicios_con_la_opcion_apagada_si_aplica_el_recargo()
    {
        $this->autenticar();

        $budget = $this->presupuesto_con_servicio(Self::PRECIO_SIN_RECARGO, null);

        $sub_total = Self::PRECIO_SIN_RECARGO * Self::CANTIDAD;
        $esperado = $sub_total + ($sub_total * Self::PORCENTAJE_RECARGO / 100);

        $this->assertEquals(
            $esperado,
            round(BudgetHelper::getTotal($budget), 2),
            'Con surchages_in_services en 1 y la opcion apagada, el servicio suma el recargo: 550.'
        );
    }

    /**
     * 🔴 Pedido explicito de Lucas: el PDF del presupuesto no lista los recargos en el pie cuando la
     * opcion esta activa. Con el recargo ya adentro del precio de cada renglon, un "+10% Recargo"
     * abajo del total se lee como si se sumara dos veces.
     *
     * ⚠️ ESTE TEST LEE EL CODIGO FUENTE A PROPOSITO, NO LO "MEJORES" INSTANCIANDO LA CLASE: las
     * clases de `Pdf/` hacen `require` de fpdf.php (sin `_once`) y terminan su constructor con
     * `$this->Output(); exit;`. Instanciar `BudgetPdf` desde PHPUnit mata el proceso, o revienta con
     * "Constant FPDF_VERSION already defined" si ya se cargo otra en la misma corrida. Es el mismo
     * criterio que `tests/Feature/Pdf/3_Observaciones_del_cliente_opcionales_Test.php` y
     * `2_Logo_Por_Sucursal_Test.php`, que son los unicos tests de PDF que hay en el repo.
     *
     * Lo que se verifica es que la guarda existe Y QUE ENVUELVE AL FOREACH DE RECARGOS, no al de
     * descuentos: los dos loops estan pegados y mover la llave un renglon para arriba dejaria de
     * imprimir los descuentos, que si se siguen listando.
     *
     * @group presupuestos
     * @test
     */
    public function el_pdf_no_lista_los_recargos_en_el_pie_con_la_opcion_activa()
    {
        $codigo = file_get_contents(app_path('Http/Controllers/Pdf/BudgetPdf.php'));

        $desde = strpos($codigo, 'function discountsSurchages()');
        $hasta = strpos($codigo, 'function total()');

        $this->assertNotFalse($desde, 'BudgetPdf tiene que seguir teniendo discountsSurchages().');
        $this->assertNotFalse($hasta, 'BudgetPdf tiene que seguir teniendo total().');
        $this->assertGreaterThan($desde, $hasta, 'total() va despues de discountsSurchages().');

        $cuerpo = substr($codigo, $desde, $hasta - $desde);

        // La guarda abre y lo primero que hay adentro es el foreach de recargos.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\$this->budget->aplicar_recargos_directo_a_items\s*\)\s*\{\s*'
                .'foreach\s*\(\s*\$this->budget->surchages\s+as\s+\$surchage\s*\)/',
            $cuerpo,
            'El foreach que imprime los recargos en el pie tiene que estar envuelto por '
                .'if (!$this->budget->aplicar_recargos_directo_a_items).'
        );

        $pos_descuentos = strpos($cuerpo, 'foreach ($this->budget->discounts as $discount)');
        $pos_guarda = strpos($cuerpo, '!$this->budget->aplicar_recargos_directo_a_items');

        $this->assertNotFalse($pos_descuentos, 'El pie tiene que seguir listando los descuentos.');

        $this->assertLessThan(
            $pos_guarda,
            $pos_descuentos,
            'El foreach de descuentos tiene que quedar ARRIBA de la guarda, o sea fuera: la opcion '
                .'es solo de recargos y los descuentos se siguen listando siempre.'
        );
    }
}
