<?php

namespace Tests\Feature\Cheques;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Base de la suite de cheques (misión cheques-endoso-y-bancos, 21/9/2026): el endoso de un cheque
 * recibido desde un pago a proveedor, desde un gasto y desde el botón del módulo, y el catálogo
 * de bancos.
 *
 * Todo lo que escribe pasa por los endpoints reales (`POST current-acount/pago`, `POST expense`,
 * `PUT cheque/endosar`, `cheque-banco`): el cheque recibido que después se endosa nace de un cobro
 * a un cliente con una fila de tipo cheque, igual que en la pantalla. Lo único que se inserta a
 * mano son los cheques que tienen que estar en un estado que ningún endpoint deja (cobrado,
 * rechazado, de otro dueño), y eso se dice en cada test.
 *
 * 🔴 Los payloads están COPIADOS de lo que manda la SPA: la fila de pago del
 * `payment_method_factory` de current-acounts/pago/PaymentMethods.vue y de
 * expenses/modals/payment-methods/Index.vue, más las claves nuevas `cheque_id` y
 * `cheque_banco_id` (§4 del plan). No se escriben mirando el helper.
 */
abstract class ChequesTestCase extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** Monto del cheque que se endosa en la mayoría de los casos. */
    const MONTO_CHEQUE = 45000;

    /** Deuda que se le siembra al proveedor antes de pagarle con el endoso. */
    const DEUDA_PROVEEDOR = 60000;

    /** @var User */
    protected $dueno;

    /** @var CurrentAcountPaymentMethod El método de pago de tipo cheque del catálogo. */
    protected $metodo_cheque;

    /** @var int Marca de agua de `cheques`, para borrar en tearDown solo lo que este test creó. */
    protected $max_cheque_id_antes = 0;

    /** @var int Ídem para `cheque_bancos`. */
    protected $max_cheque_banco_id_antes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->metodo_cheque = CurrentAcountPaymentMethod::whereHas('type', function ($q) {
            $q->where('slug', 'cheque');
        })->orderBy('id')->first();

        if (is_null($this->metodo_cheque)) {
            $this->fail('El catálogo current_acount_payment_methods no tiene ningún método de tipo cheque.');
        }

        $this->max_cheque_id_antes = (int) (Cheque::max('id') ?? 0);
        $this->max_cheque_banco_id_antes = (int) (ChequeBanco::max('id') ?? 0);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        // Cinturón y tiradores sobre el rollback de DatabaseTransactions, como hace el trait.
        Cheque::where('id', '>', $this->max_cheque_id_antes)->delete();
        ChequeBanco::where('id', '>', $this->max_cheque_banco_id_antes)->delete();

        parent::tearDown();
    }

    /**
     * Un cliente del dueño con sus cuentas corrientes.
     *
     * @param string $nombre
     * @return array{0: Client, 1: CreditAccount}
     */
    protected function cliente_con_cuenta($nombre)
    {
        $cliente = Client::create([
            'num'     => (int) Client::where('user_id', $this->dueno->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);

        return [$cliente, $this->cuenta_de('client', $cliente->id)];
    }

    /**
     * Un proveedor del dueño con sus cuentas corrientes y, si se pide, una deuda sembrada por el
     * endpoint real de nota de débito.
     *
     * @param string $nombre
     * @param float $deuda
     * @return array{0: Provider, 1: CreditAccount}
     */
    protected function proveedor_con_cuenta($nombre, $deuda = 0)
    {
        $proveedor = Provider::create([
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
            'status'  => 'active',
        ]);

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $this->dueno->id);

        $cuenta = $this->cuenta_de('provider', $proveedor->id);

        if ($deuda > 0) {

            $response = $this->postJson('api/current-acount/nota-debito', [
                'credit_account_id' => $cuenta->id,
                'model_name'        => 'provider',
                'model_id'          => $proveedor->id,
                'debe'              => $deuda,
                'description'       => 'Deuda sembrada por la suite de cheques',
            ]);

            $response->assertStatus(201);

            $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');
        }

        return [$proveedor, $cuenta];
    }

    /**
     * La cuenta corriente en pesos de un cliente o un proveedor.
     *
     * @param string $model_name
     * @param int $model_id
     * @return CreditAccount
     */
    protected function cuenta_de($model_name, $model_id)
    {
        return CreditAccount::where('model_name', $model_name)
                            ->where('model_id', $model_id)
                            ->where('moneda_id', 1)
                            ->first();
    }

    /**
     * Una fila de método de pago tal como la manda MultiPaymentMethods desde el modal de PAGO de
     * cuenta corriente (payment_method_factory de current-acounts/pago/PaymentMethods.vue), con las
     * claves del cheque que emite CheckInfo y las dos nuevas de esta misión (`cheque_id`,
     * `cheque_banco_id`). Sin caja: el cheque no mueve caja al cargarse.
     *
     * @param array $overrides
     * @return array
     */
    protected function fila_de_pago($overrides = [])
    {
        return array_merge([
            '__row_id'                         => '1789500000000_' . uniqid(),
            'current_acount_payment_method_id' => $this->metodo_cheque->id,
            'amount'                           => self::MONTO_CHEQUE,
            'bank'                             => '',
            'payment_date'                     => '',
            'num'                              => '',
            'credit_card_id'                   => 0,
            'credit_card_payment_plan_id'      => 0,
            'caja_id'                          => 0,
            'moneda_id'                        => 1,
            'cotizacion'                       => 0,
            'amount_cotizado'                  => '',
            'numero'                           => '',
            'banco'                            => '',
            'cheque_banco_id'                  => 0,
            'fecha_emision'                    => '',
            'fecha_pago'                       => '',
            'es_echeq'                         => 0,
            'notes'                            => '',
            'cheque_id'                        => 0,
            'cuota_id'                         => 0,
        ], $overrides);
    }

    /**
     * Una fila de método de pago tal como la manda el modal de métodos de pago del GASTO
     * (payment_method_factory de expenses/modals/payment-methods/Index.vue), con las claves del
     * cheque que emite CheckInfo y las dos nuevas.
     *
     * @param array $overrides
     * @return array
     */
    protected function fila_de_gasto($overrides = [])
    {
        return array_merge([
            'current_acount_payment_method_id' => $this->metodo_cheque->id,
            'amount'                           => self::MONTO_CHEQUE,
            'bank'                             => '',
            'payment_date'                     => '',
            'num'                              => '',
            'credit_card_id'                   => 0,
            'credit_card_payment_plan_id'      => 0,
            'caja_id'                          => 0,
            'moneda_id'                        => 1,
            'cotizacion'                       => 0,
            'amount_cotizado'                  => '',
            'cuota_id'                         => 0,
            'numero'                           => '',
            'banco'                            => '',
            'cheque_banco_id'                  => 0,
            'fecha_emision'                    => '',
            'fecha_pago'                       => '',
            'es_echeq'                         => 0,
            'notes'                            => '',
            'cheque_id'                        => 0,
        ], $overrides);
    }

    /**
     * Las claves del cheque que la SPA copia del cheque elegido a la fila cuando se endosa (patch
     * de `fields_change` de CheckInfo, §3.3 del plan), con `cheque_id` y el monto fijo.
     *
     * @param Cheque $cheque
     * @return array
     */
    protected function claves_de_endoso(Cheque $cheque)
    {
        return [
            'cheque_id'       => $cheque->id,
            'numero'          => $cheque->numero,
            'banco'           => $cheque->banco,
            'cheque_banco_id' => is_null($cheque->cheque_banco_id) ? 0 : $cheque->cheque_banco_id,
            'fecha_emision'   => Carbon::parse($cheque->fecha_emision)->format('Y-m-d'),
            'fecha_pago'      => Carbon::parse($cheque->fecha_pago)->format('Y-m-d'),
            'es_echeq'        => (int) $cheque->es_echeq,
            'notes'           => (string) $cheque->notes,
            'amount'          => (float) $cheque->amount,
        ];
    }

    /**
     * Payload de `POST api/current-acount/pago` tal como lo manda la SPA (el objeto `pago` del modal
     * más las claves que agrega hacerPago()).
     *
     * @param string $model_name 'client' | 'provider'
     * @param int $model_id
     * @param CreditAccount $cuenta
     * @param array $filas
     * @param float|null $haber Default: la suma de los `amount` de las filas.
     * @return array
     */
    protected function payload_de_pago($model_name, $model_id, $cuenta, array $filas, $haber = null)
    {
        if (is_null($haber)) {
            $haber = 0;

            foreach ($filas as $fila) {
                $haber += (float) $fila['amount'];
            }
        }

        return [
            'credit_account_id'              => $cuenta->id,
            'model_name'                     => $model_name,
            'model_id'                       => $model_id,
            'current_date'                   => true,
            'description'                    => 'Pago de la suite de cheques',
            'created_at'                     => '',
            'haber'                          => $haber,
            'is_provisorio'                  => 0,
            'current_acount_payment_methods' => $filas,
            'to_pay'                         => null,
            'payment_plan_cuota'             => null,
        ];
    }

    /**
     * Payload de `POST api/expense` (el mismo que arma crear_gasto() del trait), con las filas.
     *
     * @param array $filas
     * @param float|null $monto Default: la suma de las filas.
     * @return array
     */
    protected function payload_de_gasto(array $filas, $monto = null)
    {
        if (is_null($monto)) {
            $monto = 0;

            foreach ($filas as $fila) {
                $monto += (float) $fila['amount'];
            }
        }

        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        return [
            'expense_concept_id' => $concepto->id,
            'amount'             => $monto,
            'moneda_id'          => 1,
            'importe_iva'        => 0,
            'observations'       => 'Gasto de la suite de cheques',
            'created_at'         => Carbon::now()->format('Y-m-d H:i:s'),
            'payment_methods'    => $filas,
        ];
    }

    /**
     * Cobra a un cliente con un cheque NUEVO por el endpoint real, y devuelve el cheque recibido
     * que quedó en cartera.
     *
     * @param Client $cliente
     * @param CreditAccount $cuenta
     * @param array $cheque Claves del cheque a pisar (numero, banco, cheque_banco_id, fecha_pago, amount...).
     * @return Cheque
     */
    protected function cobrar_con_cheque($cliente, $cuenta, array $cheque = [])
    {
        $fila = $this->fila_de_pago(array_merge([
            'numero'        => 'CH-' . substr(uniqid(), -6),
            'banco'         => 'Banco Nación',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(10)->format('Y-m-d'),
        ], $cheque));

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('client', $cliente->id, $cuenta, [$fila]));

        if ($response->getStatusCode() !== 201) {
            $this->fail('El cobro con cheque nuevo tenía que dar 201 y dio ' . $response->getStatusCode() . ': ' . $response->getContent());
        }

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $recibido = Cheque::where('current_acount_id', $pago_id)->where('tipo', 'recibido')->first();

        $this->assertNotNull($recibido, 'El cobro con una fila de tipo cheque tenía que dejar un cheque recibido.');

        return $recibido;
    }

    /**
     * Un cheque insertado a mano, para los estados que ningún endpoint deja (cobrado, rechazado,
     * de otro dueño, endosado sin pago).
     *
     * @param array $atributos
     * @return Cheque
     */
    protected function cheque_a_mano(array $atributos = [])
    {
        return Cheque::create(array_merge([
            'numero'        => 'MANO-' . substr(uniqid(), -6),
            'banco'         => 'Banco Galicia',
            'amount'        => self::MONTO_CHEQUE,
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(5)->format('Y-m-d'),
            'tipo'          => 'recibido',
            'user_id'       => $this->dueno->id,
            'estado_manual' => null,
        ], $atributos));
    }

    /**
     * Las copias emitidas que nacieron de un recibido.
     *
     * @param Cheque $origen
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function copias_de(Cheque $origen)
    {
        return Cheque::where('endosado_desde_cheque_id', $origen->id)->orderBy('id')->get();
    }

    /**
     * Los ids de los cheques que devuelve `GET cheque/disponibles-para-endosar`.
     *
     * @return array<int, int>
     */
    protected function ids_disponibles_para_endosar()
    {
        $response = $this->getJson('api/cheque/disponibles-para-endosar');

        $response->assertStatus(200);

        $ids = [];

        foreach ($response->json('models') as $model) {
            $ids[] = (int) $model['id'];
        }

        return $ids;
    }

    /**
     * En qué solapa de `GET cheque` cayó un cheque: "recibido.endosados", "emitido.pendientes"...
     * null si no está en ninguna.
     *
     * @param int $cheque_id
     * @return string|null
     */
    protected function solapa_de($cheque_id)
    {
        $response = $this->getJson('api/cheque');

        $response->assertStatus(200);

        foreach ($response->json('models') as $tipo => $solapas) {
            foreach ($solapas as $solapa => $cheques) {
                foreach ($cheques as $cheque) {
                    if ((int) $cheque['id'] === (int) $cheque_id) {
                        return $tipo . '.' . $solapa;
                    }
                }
            }
        }

        return null;
    }

    /**
     * El cheque tal como lo devuelve `GET cheque` (con sus relaciones), o null.
     *
     * @param int $cheque_id
     * @return array|null
     */
    protected function cheque_del_listado($cheque_id)
    {
        $response = $this->getJson('api/cheque');

        $response->assertStatus(200);

        foreach ($response->json('models') as $solapas) {
            foreach ($solapas as $cheques) {
                foreach ($cheques as $cheque) {
                    if ((int) $cheque['id'] === (int) $cheque_id) {
                        return $cheque;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Todo lo observable que dejó un endoso a un proveedor, sin ids ni fechas: el recibido marcado,
     * la copia emitida, el pago del proveedor con su desglose y el saldo de la cuenta. Sirve para
     * comparar el endoso desde el pago de cuenta corriente con el del botón del módulo.
     *
     * @param Cheque $recibido
     * @param Provider $proveedor
     * @param CreditAccount $cuenta
     * @return array
     */
    protected function radiografia_del_endoso(Cheque $recibido, Provider $proveedor, CreditAccount $cuenta)
    {
        $recibido = $recibido->fresh();
        $copias = $this->copias_de($recibido);
        $copia = $copias->first();

        $pago = null;

        if (!is_null($copia) && !is_null($copia->current_acount_id)) {
            $pago = CurrentAcount::find($copia->current_acount_id);
        }

        $metodos = [];

        if (!is_null($pago)) {
            $pago->load('current_acount_payment_methods');

            foreach ($pago->current_acount_payment_methods as $metodo) {
                $metodos[] = [
                    'metodo_de_pago' => (int) $metodo->id,
                    'amount'         => round((float) $metodo->pivot->amount, 2),
                    'caja_id'        => (int) $metodo->pivot->caja_id,
                ];
            }
        }

        return [
            'recibido' => [
                'endosado_al_proveedor'    => (int) $recibido->endosado_a_provider_id === (int) $proveedor->id,
                'endosado_en_gasto'        => $recibido->endosado_en_expense_id,
                'tiene_fecha_endoso'       => !is_null($recibido->fecha_endoso),
                'estado_manual'            => $recibido->estado_manual,
                'solapa'                   => $this->solapa_de($recibido->id),
            ],
            'copias'   => count($copias),
            'copia'    => is_null($copia) ? null : [
                'tipo'                    => $copia->tipo,
                'numero'                  => $copia->numero,
                'banco'                   => $copia->banco,
                'cheque_banco_id'         => $copia->cheque_banco_id,
                'amount'                  => round((float) $copia->amount, 2),
                'fecha_pago'              => Carbon::parse($copia->fecha_pago)->format('Y-m-d'),
                'es_echeq'                => (int) $copia->es_echeq,
                'es_del_proveedor'        => (int) $copia->provider_id === (int) $proveedor->id,
                'client_id'               => $copia->client_id,
                'endosado_desde_cliente'  => (int) $copia->endosado_desde_client_id === (int) $recibido->client_id,
                'expense_id'              => $copia->expense_id,
                'tiene_pago'              => !is_null($pago),
                'en_cartera'              => empty($copia->endosado_a_provider_id) && is_null($copia->endosado_en_expense_id),
                'estado_manual'           => $copia->estado_manual,
                'solapa'                  => $this->solapa_de($copia->id),
            ],
            'pago'     => is_null($pago) ? null : [
                'haber'              => round((float) $pago->haber, 2),
                'status'             => $pago->status,
                'es_del_proveedor'   => (int) $pago->provider_id === (int) $proveedor->id,
                'tiene_client_id'    => !is_null($pago->client_id),
                'credit_account'     => (int) $pago->credit_account_id === (int) $cuenta->id,
                'metodos'            => $metodos,
            ],
            'saldo_de_la_cuenta' => round((float) CreditAccount::find($cuenta->id)->saldo, 2),
        ];
    }
}
