<?php

namespace Tests\Feature\Presupuestos;

use App\Models\AfipTicket;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de confirmar y anular presupuestos (mision 161, 21/8/2026).
 *
 * Lo que fijan estos tests, ademas de la funcionalidad nueva, es un comportamiento destructivo que
 * existia hasta esta mision: `BudgetController::update()` llamaba a `BudgetHelper::checkStatus()` en
 * CADA update, y `checkStatus()` arranca siempre por `deleteCurrentAcount()` + `deleteSale()` antes
 * de mirar el estado. O sea que guardar un presupuesto confirmado —aunque no se le tocara nada—
 * borraba su venta y creaba una nueva CON NUMERO NUEVO.
 *
 * El test `update_de_presupuesto_confirmado_no_toca_la_venta` es el que fija ese arreglo: chequea
 * `id` y `num` de la venta, no solo que exista una.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un refresh
 * la vaciaria, rompiendo el resto de las suites. Mismo criterio que `tests/Feature/Sales`.
 */
class Confirmar_y_anular_presupuesto_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /**
     * ⚠️ La base de testing del slot viene con `budget_statuses` VACIA (medido el 21/8/2026 en
     * `empresa_testing_s2`). Es una tabla global, sembrada por `BudgetStatusSeeder`, que ninguna
     * suite anterior necesitaba porque no habia tests de presupuestos.
     *
     * Se siembra acá, con ids explicitos y no por auto-increment, para no depender de en que estado
     * quedo el contador. `DatabaseTransactions` lo revierte al terminar cada test.
     *
     * Importa mas de lo que parece: `BudgetHelper::checkStatus()` hace
     * `$budget->budget_status->name` sin chequear null, asi que sin estas filas el flujo de
     * confirmar por `update()` no falla con un assert, revienta.
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
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Autentica al usuario de testing, o saltea el test si la base no lo tiene sembrado.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = $this->usuario_de_testing();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente_de_testing()
    {
        /*
            Cualquier cliente del usuario de testing sirve: a estos tests no les importa quien es el
            cliente sino que la venta quede atada a uno. Se busca por user_id y no por nombre para
            no atarse al fixture: la base del slot tiene "Cliente Cuenta Corriente", "Cliente
            Contado" y "Cliente Exento", no el nombre que usan los tests viejos de ventas.
        */
        $client = Client::where('user_id', 500)->first();

        if (is_null($client)) {
            $this->markTestSkipped('La base de testing no tiene ningun cliente del usuario 500.');
        }

        /*
            🔴 El fixture trae `credit_accounts` para los PROVEEDORES y ninguna para los clientes
            (medido el 21/8/2026 en `empresa_testing_s2`). Sin esta fila,
            `CurrentAcountFromSaleHelper::crear_current_acount()` revienta con "Trying to get
            property 'id' of non-object" en su linea 54, porque busca la cuenta por
            (model_name, model_id, moneda_id) y usa el resultado sin chequear null.

            Se crea acá, y `DatabaseTransactions` la revierte. Va como hallazgo al informe: el mismo
            repo ya sabe que un cliente puede no tener cuenta para una moneda —
            `SaleController::destroy()` lo chequea y loguea "no tiene credit account para la moneda
            X. Se saltea el chequeo de saldos"— pero el camino de creacion no se protege igual.
        */
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
     * Presupuesto minimo, sin articulos: a estos tests no les importa el contenido sino si se crea
     * o se borra la venta.
     *
     * @param array $overrides
     * @return \App\Models\Budget
     */
    protected function crear_presupuesto($overrides = [])
    {
        $client = $this->cliente_de_testing();

        return Budget::create(array_merge([
            'user_id'               => 500,
            'client_id'             => $client->id,
            'budget_status_id'      => Self::ESTADO_SIN_CONFIRMAR,
            'total'                 => 100,
            'discount_stock'        => 0,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
        ], $overrides));
    }

    /**
     * Payload minimo y valido para PUT api/budget/{id}. Los tres arrays van vacios pero presentes:
     * `attachArticles`/`attachServices`/`attachPromocionVinotecas` hacen foreach sin chequear null.
     *
     * @param \App\Models\Budget $budget
     * @param array $overrides
     * @return array
     */
    protected function payload_actualizar($budget, $overrides = [])
    {
        return array_merge([
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'actualizado por el test',
            'total'                 => $budget->total,
            'budget_status_id'      => $budget->budget_status_id,
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
        ], $overrides);
    }

    /**
     * @return \App\Models\Sale|null
     */
    protected function venta_del_presupuesto($budget_id)
    {
        return Sale::where('budget_id', $budget_id)->first();
    }

    /**
     * @group presupuestos
     * @test
     */
    public function confirmar_crea_la_venta_y_deja_el_presupuesto_confirmado()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $this->assertNull($this->venta_del_presupuesto($budget->id));

        $response = $this->post('api/budget/'.$budget->id.'/confirmar');

        $response->assertStatus(200);

        $budget->refresh();
        $this->assertEquals(Self::ESTADO_CONFIRMADO, $budget->budget_status_id);

        $sale = $this->venta_del_presupuesto($budget->id);
        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');
        $this->assertEquals($budget->client_id, $sale->client_id);
    }

    /**
     * Idempotencia: el boton se puede apretar dos veces, o dos pestañas pueden mandar el mismo POST.
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_dos_veces_no_crea_dos_ventas()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);
        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $this->assertEquals(
            1,
            Sale::where('budget_id', $budget->id)->count(),
            'Confirmar dos veces tiene que dejar UNA sola venta.'
        );
    }

    /**
     * @group presupuestos
     * @test
     */
    public function anular_borra_la_venta_y_desconfirma_el_presupuesto()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = $this->venta_del_presupuesto($budget->id);
        $this->assertNotNull($sale);

        $response = $this->post('api/budget/'.$budget->id.'/anular');

        $response->assertStatus(200);

        $budget->refresh();
        $this->assertEquals(Self::ESTADO_SIN_CONFIRMAR, $budget->budget_status_id);

        $this->assertNull(
            $this->venta_del_presupuesto($budget->id),
            'Anular tiene que haber borrado la venta.'
        );
    }

    /**
     * Despues de anular se tiene que poder volver a confirmar. Por eso `confirmar()` busca la venta
     * sin `withTrashed`: la venta borrada por la anulacion no cuenta como venta existente.
     *
     * @group presupuestos
     * @test
     */
    public function despues_de_anular_se_puede_volver_a_confirmar()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);
        $this->post('api/budget/'.$budget->id.'/anular')->assertStatus(200);
        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $budget->refresh();
        $this->assertEquals(Self::ESTADO_CONFIRMADO, $budget->budget_status_id);

        $this->assertNotNull(
            $this->venta_del_presupuesto($budget->id),
            'Volver a confirmar tiene que crear una venta nueva.'
        );
    }

    /**
     * 🔴 Anular con la venta ya facturada se rechaza y NO toca nada.
     *
     * El motivo sale de `SaleHelper::motivo_por_el_que_no_se_puede_editar()`, que cuenta
     * `afip_tickets` sin mirar el CAE: un intento de facturacion fallido tambien frena la anulacion,
     * a proposito. Primero se resuelve la situacion con ARCA.
     *
     * @group presupuestos
     * @test
     */
    public function anular_con_la_venta_facturada_se_rechaza_y_no_toca_nada()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = $this->venta_del_presupuesto($budget->id);
        $this->assertNotNull($sale);

        /*
            Alcanza con la fila: `motivo_por_el_que_no_se_puede_editar()` cuenta `afip_tickets` y no
            mira el CAE, asi que un intento de facturacion fallido tambien frena la anulacion. Es a
            proposito: primero se resuelve la situacion con ARCA.
            `afip_tickets` no tiene columna `user_id`; el dueño se resuelve por la venta.
        */
        AfipTicket::create([
            'sale_id' => $sale->id,
        ]);

        $response = $this->post('api/budget/'.$budget->id.'/anular');

        $response->assertStatus(422);

        $budget->refresh();
        $this->assertEquals(
            Self::ESTADO_CONFIRMADO,
            $budget->budget_status_id,
            'Un 422 no puede dejar el presupuesto desconfirmado.'
        );

        $this->assertNotNull(
            $this->venta_del_presupuesto($budget->id),
            'La venta facturada tiene que seguir viva.'
        );
    }

    /**
     * 🔴 El test del arreglo destructivo: hasta esta mision, cualquier update de un presupuesto
     * confirmado borraba su venta y creaba otra con numero nuevo. Ahora el update se rechaza.
     *
     * Se comparan `id` y `num`, no la mera existencia de una venta: el bug creaba una venta nueva,
     * asi que un assert de "existe una venta" habria pasado igual con el bug adentro.
     *
     * @group presupuestos
     * @test
     */
    public function update_de_presupuesto_confirmado_no_toca_la_venta()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = $this->venta_del_presupuesto($budget->id);
        $this->assertNotNull($sale);

        $id_original = $sale->id;
        $num_original = $sale->num;

        $budget->refresh();

        $response = $this->put('api/budget/'.$budget->id, $this->payload_actualizar($budget));

        $response->assertStatus(422);

        $sale_despues = $this->venta_del_presupuesto($budget->id);

        $this->assertNotNull($sale_despues, 'La venta no se tiene que haber borrado.');
        $this->assertEquals($id_original, $sale_despues->id, 'La venta no se tiene que haber recreado.');
        $this->assertEquals($num_original, $sale_despues->num, 'El numero de la venta no puede cambiar.');
    }

    /**
     * Un update que NO cambia el estado no pasa mas por `checkStatus()`, asi que no crea ni borra
     * ninguna venta.
     *
     * @group presupuestos
     * @test
     */
    public function update_sin_cambiar_el_estado_no_crea_ninguna_venta()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $response = $this->put('api/budget/'.$budget->id, $this->payload_actualizar($budget));

        $response->assertStatus(200);

        $budget->refresh();
        $this->assertEquals(Self::ESTADO_SIN_CONFIRMAR, $budget->budget_status_id);

        $this->assertNull(
            $this->venta_del_presupuesto($budget->id),
            'Un update sin cambio de estado no tiene que crear una venta.'
        );
    }

    /**
     * Compatibilidad hacia atras: una SPA todavia no desplegada confirma cambiando el select y
     * guardando. Ese camino tiene que seguir creando la venta, que es por lo que `checkStatus()`
     * quedo condicional en vez de removido (decision de Lucas, 21/8/2026).
     *
     * @group presupuestos
     * @test
     */
    public function update_que_cambia_el_estado_a_confirmado_sigue_creando_la_venta()
    {
        $this->autenticar();

        $budget = $this->crear_presupuesto();

        $payload = $this->payload_actualizar($budget, [
            'budget_status_id' => Self::ESTADO_CONFIRMADO,
        ]);

        $response = $this->put('api/budget/'.$budget->id, $payload);

        $response->assertStatus(200);

        $budget->refresh();
        $this->assertEquals(Self::ESTADO_CONFIRMADO, $budget->budget_status_id);

        $this->assertNotNull(
            $this->venta_del_presupuesto($budget->id),
            'Cambiar el estado a Confirmado por update tiene que seguir creando la venta.'
        );
    }
}
