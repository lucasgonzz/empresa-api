<?php

namespace Tests\Feature\VentasEnDolares;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Article;
use App\Models\Caja;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Base común de las suites de CUENTAS CORRIENTES EN PESOS Y EN DÓLARES (comercio 2R).
 *
 * Escenario de cada test (misma configuración que el cliente real 2R):
 *  - dueño del fixture (id 500) con `cotizar_precios_en_dolares = 0`, sin listas de precio, con la
 *    extensión `ventas_en_dolares` y el dólar de venta en 1200;
 *  - dos cajas abiertas, una por moneda (`caja_pesos`, `caja_dolares`);
 *  - monedas: 1 = Peso, 2 = Dólar.
 *
 * 🔴 TODO SE ARMA PEGÁNDOLE A LOS ENDPOINTS REALES (`POST api/sale`, `POST api/current-acount/pago`,
 * etc.). Nunca se crea una venta ni un movimiento con `Model::create()`: se saltearía los helpers
 * que son justamente lo que se prueba. Las únicas escrituras directas son las de SETUP (cliente,
 * cajas, configuración del dueño, límite de crédito de la cuenta).
 *
 * 🔴 LOS PAYLOADS EMULAN A LA SPA, no al helper del back: el precio de un artículo en pesos vendido
 * en dólares viaja ya dividido por `valor_dolar` (la SPA convierte antes de enviar), y una fila de
 * pago en otra moneda que la de la cuenta viaja con `cotizacion` y `amount_cotizado` calculado como
 * en `PaymentMethodsStep.vue::check_moneda()` (fila en pesos → `amount / cotizacion`; fila en
 * dólares → `amount * cotizacion`), mientras que una fila en la moneda de la cuenta viaja con
 * `cotizacion` = dólar del dueño y `amount_cotizado` vacío (`PaymentMethods.vue::payment_method_factory()`).
 * El `haber` del pago es la suma de `amount_cotizado` (si > 0) o `amount` de cada fila
 * (`PaymentMethods.vue::update_total()`).
 *
 * PHP 7.4: sin `match`, `?->`, argumentos nombrados, union types ni propiedades tipadas.
 */
abstract class CuentaCorriente_Base extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Moneda 1 = Peso. */
    const PESOS = 1;

    /** Moneda 2 = Dólar. */
    const DOLARES = 2;

    /** Valor del dólar de venta del escenario 2R. */
    const DOLAR = 1200;

    /** Tolerancia para comparar montos con 2 decimales. */
    const DELTA = 0.01;

    /** @var \App\Models\User */
    protected $usuario;

    /** @var \App\Models\Caja */
    protected $caja_pesos;

    /** @var \App\Models\Caja */
    protected $caja_dolares;

    /** @var \App\Models\CurrentAcountPaymentMethod */
    protected $metodo_efectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // 2R: NO cotiza los precios en dólares, sin listas de precio. El dólar de venta es 1200.
        $this->usuario->cotizar_precios_en_dolares = 0;
        $this->usuario->listas_de_precio = 0;
        $this->usuario->dollar = self::DOLAR;
        $this->usuario->save();

        $extencion = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'ventas_en_dolares',
                'name' => 'Ventas en dolares',
            ]);
        }

        $this->usuario->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->metodo_efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $this->caja_dolares = $this->caja_de_moneda('Caja CC Dolares Test', self::DOLARES);
        $this->caja_pesos   = $this->caja_de_moneda('Caja CC Pesos Test', self::PESOS);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------------------------------

    /**
     * Crea (o reusa) una caja de la moneda pedida y la deja abierta.
     *
     * @param string $name
     * @param int $moneda_id
     * @param bool $abrir
     * @return \App\Models\Caja
     */
    protected function caja_de_moneda($name, $moneda_id, $abrir = true)
    {
        $caja = Caja::where('name', $name)->first();

        if (is_null($caja)) {

            $num = (int) Caja::where('user_id', $this->usuario->id)->max('num') + 1;

            $caja = Caja::create([
                'name'                  => $name,
                'num'                   => $num,
                'moneda_id'             => $moneda_id,
                'user_id'               => $this->usuario->id,
                'saldo'                 => 0,
                'abierta'               => 0,
                'comision_iva_incluido' => 0,
            ]);
        }

        if ($abrir) {
            $this->asegurar_caja_abierta($caja);
        }

        return $caja->fresh();
    }

    /**
     * Un cliente nuevo del dueño con sus dos cuentas corrientes en 0 (una por moneda), como lo
     * deja `ClientController::store()`. Con un cliente propio cada test parte de saldo cero sin
     * depender de lo que hayan dejado otras suites en `CLIENTE_CC`.
     *
     * @param string $prefijo
     * @return \App\Models\Client
     */
    protected function cliente_nuevo($prefijo = 'zz CC Monedas')
    {
        $cliente = Client::create([
            'num'     => (int) Client::where('user_id', $this->usuario->id)->max('num') + 1,
            'name'    => $prefijo.' '.uniqid(),
            'user_id' => $this->usuario->id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->usuario->id);

        return $cliente;
    }

    /**
     * La cuenta corriente del cliente en la moneda pedida, leída de la base.
     *
     * @param \App\Models\Client $cliente
     * @param int $moneda_id
     * @return \App\Models\CreditAccount
     */
    protected function cuenta($cliente, $moneda_id)
    {
        $cuenta = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $cliente->id)
                                ->where('moneda_id', $moneda_id)
                                ->first();

        $this->assertNotNull($cuenta, 'El cliente '.$cliente->id.' no tiene credit_account de moneda '.$moneda_id.'.');

        return $cuenta;
    }

    /**
     * Saldo de la cuenta guardado en `credit_accounts.saldo`, siempre releído.
     *
     * @param \App\Models\CreditAccount $cuenta
     * @return float
     */
    protected function saldo_de($cuenta)
    {
        return (float) CreditAccount::find($cuenta->id)->saldo;
    }

    /**
     * Los tres saldos denormalizados del cliente, releídos de la base.
     *
     * @param \App\Models\Client $cliente
     * @return array ['saldo' => ..., 'pesos' => ..., 'dolares' => ...] (null si la columna es null)
     */
    protected function saldos_del_cliente($cliente)
    {
        $c = DB::table('clients')->where('id', $cliente->id)->first();

        return [
            'saldo'   => is_null($c->saldo) ? null : (float) $c->saldo,
            'pesos'   => is_null($c->saldo_pesos) ? null : (float) $c->saldo_pesos,
            'dolares' => is_null($c->saldo_dolares) ? null : (float) $c->saldo_dolares,
        ];
    }

    /**
     * Los movimientos de una cuenta, en el orden de la cadena de saldos.
     *
     * @param \App\Models\CreditAccount $cuenta
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function movimientos($cuenta)
    {
        return CurrentAcount::where('credit_account_id', $cuenta->id)
                            ->orderBy('created_at', 'ASC')
                            ->orderBy('id', 'ASC')
                            ->get();
    }

    /**
     * Los movimientos de DÉBITO (ventas y notas de débito) de una cuenta.
     *
     * @param \App\Models\CreditAccount $cuenta
     * @return \Illuminate\Support\Collection
     */
    protected function debitos($cuenta)
    {
        return $this->movimientos($cuenta)->filter(function ($m) {
            return !is_null($m->debe);
        })->values();
    }

    // ------------------------------------------------------------------------------------------
    // Ventas
    // ------------------------------------------------------------------------------------------

    /**
     * Payload de `POST api/sale` de una venta a cuenta corriente con un solo renglón del artículo
     * centinela. `$total` está en LA MONEDA DE LA VENTA (la SPA ya convirtió el precio).
     *
     * @param int $client_id
     * @param int $moneda_id
     * @param float $total
     * @param array $overrides
     * @return array
     */
    protected function payload_de_venta($client_id, $moneda_id, $total, $overrides = [])
    {
        $articulo = Article::where('name', TestingFerreteriaSeeder::ARTICULO_CENTINELA)
                            ->where('user_id', $this->usuario->id)
                            ->first();

        $this->assertNotNull($articulo, 'No existe el artículo centinela del fixture.');

        return array_merge([
            'client_id'                  => $client_id,
            'address_id'                 => null,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => $total,
            'total'                      => $total,
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => $moneda_id,
            'valor_dolar'                => self::DOLAR,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => $total,
                    'amount'       => 1,
                ],
            ],
        ], $overrides);
    }

    /**
     * Postea una venta a cuenta corriente y devuelve la Sale creada. Mueve el reloj 10 segundos
     * antes (guard anti-duplicado de `SaleController::venta_ya_cread()`), lo que además deja cada
     * movimiento con su propio `created_at`, en el orden en que se hicieron.
     *
     * @param \App\Models\Client $cliente
     * @param int $moneda_id
     * @param float $total Total EN LA MONEDA DE LA VENTA.
     * @param array $overrides
     * @return \App\Models\Sale
     */
    protected function vender($cliente, $moneda_id, $total, $overrides = [])
    {
        $this->avanzar_reloj_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_de_venta($cliente->id, $moneda_id, $total, $overrides));

        $response->assertStatus(201);

        $venta = Sale::find($response->json('model.id'));

        $this->assertNotNull($venta, 'POST api/sale no devolvió la venta. Cuerpo: '.$response->getContent());

        $this->ventas_creadas_por_escenarios[] = $venta->id;

        return $venta;
    }

    /**
     * El movimiento de débito (el "debe") de una venta en la cuenta indicada.
     *
     * @param \App\Models\Sale $venta
     * @param \App\Models\CreditAccount $cuenta
     * @return \App\Models\CurrentAcount|null
     */
    protected function debito_de($venta, $cuenta)
    {
        return CurrentAcount::where('sale_id', $venta->id)
                            ->where('credit_account_id', $cuenta->id)
                            ->whereNull('haber')
                            ->first();
    }

    // ------------------------------------------------------------------------------------------
    // Pagos
    // ------------------------------------------------------------------------------------------

    /**
     * Una fila de `current_acount_payment_methods` armada como la arma la SPA.
     *
     * @param int $moneda_de_la_cuenta Moneda de la cuenta que se está pagando (la "base" de la SPA).
     * @param int $moneda_de_la_fila Moneda en la que el cliente entrega esta plata.
     * @param float $monto Monto EN LA MONEDA DE LA FILA.
     * @param \App\Models\Caja|null $caja Caja destino (null = sin caja).
     * @param float $cotizacion Cotización con la que se cotiza la fila si cruza monedas.
     * @param array $overrides Claves de la fila a pisar (para las pruebas de datos inconsistentes).
     * @return array
     */
    protected function fila_de_pago($moneda_de_la_cuenta, $moneda_de_la_fila, $monto, $caja = null, $cotizacion = self::DOLAR, $overrides = [])
    {
        $amount_cotizado = '';

        if ($moneda_de_la_fila != $moneda_de_la_cuenta) {

            // Idéntico a PaymentMethodsStep.vue::check_moneda(). Con cotización 0 el front divide por
            // cero (Infinity, que JSON serializa como null): acá se manda vacío, que para el back es lo mismo.
            if ($moneda_de_la_fila == self::PESOS) {
                $amount_cotizado = $cotizacion > 0 ? $monto / $cotizacion : '';
            } else {
                $amount_cotizado = $monto * $cotizacion;
            }
        }

        return array_merge([
            '__row_id'                         => uniqid('fila_'),
            'current_acount_payment_method_id' => $this->metodo_efectivo->id,
            'amount'                           => $monto,
            'bank'                             => '',
            'payment_date'                     => '',
            'num'                              => '',
            'credit_card_id'                   => 0,
            'credit_card_payment_plan_id'      => 0,
            'caja_id'                          => is_null($caja) ? 0 : $caja->id,
            'moneda_id'                        => $moneda_de_la_fila,
            'cotizacion'                       => $cotizacion,
            'amount_cotizado'                  => $amount_cotizado,
            'numero'                           => '',
            'banco'                            => '',
            'fecha_emision'                    => '',
            'fecha_pago'                       => '',
            'es_echeq'                         => 0,
            'cuota_id'                         => 0,
        ], $overrides);
    }

    /**
     * El `haber` que arma la SPA para un pago: la suma de `amount_cotizado` (si es > 0) o `amount`
     * de cada fila (`PaymentMethods.vue::update_total()`).
     *
     * @param array $filas
     * @return float
     */
    protected function haber_de_la_spa($filas)
    {
        $total = 0;

        foreach ($filas as $fila) {

            if (isset($fila['amount_cotizado']) && $fila['amount_cotizado'] !== '' && (float) $fila['amount_cotizado'] > 0) {
                $total += (float) $fila['amount_cotizado'];
            } else {
                $total += (float) $fila['amount'];
            }
        }

        return $total;
    }

    /**
     * Payload de `POST api/current-acount/pago`.
     *
     * @param \App\Models\Client $cliente
     * @param \App\Models\CreditAccount $cuenta
     * @param array $filas
     * @param array $overrides Claves del payload a pisar (`haber`, `current_date`, `to_pay`, ...).
     * @return array
     */
    protected function payload_de_pago($cliente, $cuenta, $filas, $overrides = [])
    {
        return array_merge([
            'credit_account_id'              => $cuenta->id,
            'model_name'                     => 'client',
            'model_id'                       => $cliente->id,
            'current_date'                   => true,
            'description'                    => 'Pago de test de monedas',
            'created_at'                     => '',
            'haber'                          => $this->haber_de_la_spa($filas),
            'is_provisorio'                  => 0,
            'current_acount_payment_methods' => $filas,
            'to_pay'                         => null,
            'payment_plan_cuota'             => null,
        ], $overrides);
    }

    /**
     * Postea un pago y devuelve la respuesta cruda. Mueve el reloj antes, para que el pago quede
     * después de la última venta en el orden de la cadena. Registra el pago y los movimientos de
     * caja nuevos para el cleanup.
     *
     * @param \App\Models\Client $cliente
     * @param \App\Models\CreditAccount $cuenta
     * @param array $filas
     * @param array $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_pago($cliente, $cuenta, $filas, $overrides = [])
    {
        $this->avanzar_reloj_de_ventas();

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago($cliente, $cuenta, $filas, $overrides));

        $this->registrar_movimientos_caja_nuevos($antes);

        if (!is_null($response->json('current_acount.id'))) {
            $this->cobros_cc_creados_por_escenarios[] = $response->json('current_acount.id');
        }

        return $response;
    }

    /**
     * Postea un pago que TIENE que salir bien (201) y devuelve el CurrentAcount del pago.
     *
     * @param \App\Models\Client $cliente
     * @param \App\Models\CreditAccount $cuenta
     * @param array $filas
     * @param array $overrides
     * @return \App\Models\CurrentAcount
     */
    protected function pagar($cliente, $cuenta, $filas, $overrides = [])
    {
        $response = $this->postear_pago($cliente, $cuenta, $filas, $overrides);

        $response->assertStatus(201);

        $pago = CurrentAcount::find($response->json('current_acount.id'));

        $this->assertNotNull($pago, 'El pago no se creó. Cuerpo: '.$response->getContent());

        return $pago;
    }

    /**
     * Los movimientos de caja de una caja con id mayor a la marca de agua.
     *
     * @param \App\Models\Caja $caja
     * @param int $desde
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function movimientos_de_caja($caja, $desde)
    {
        return MovimientoCaja::where('caja_id', $caja->id)->where('id', '>', $desde)->orderBy('id')->get();
    }

    /**
     * Saldo de una caja (`cajas.saldo`), releído.
     *
     * @param \App\Models\Caja $caja
     * @return float
     */
    protected function saldo_de_caja_bd($caja)
    {
        return (float) Caja::find($caja->id)->saldo;
    }

    // ------------------------------------------------------------------------------------------
    // Consistencia
    // ------------------------------------------------------------------------------------------

    /**
     * Afirma que la cuenta está consistente: la cadena de saldos cierra movimiento a movimiento
     * (`CurrentAcountHelper::primer_corte_de_la_cadena()`) y el saldo de la cuenta y el del cliente
     * (`saldo_pesos` / `saldo_dolares`) coinciden con el del último movimiento
     * (`CurrentAcountHelper::descuadre_del_saldo_final()`). Son los mismos chequeos del comando
     * `cuenta_corriente:reparar_cadenas`.
     *
     * @param \App\Models\CreditAccount $cuenta
     * @param string $contexto
     * @return void
     */
    protected function assertCuentaConsistente($cuenta, $contexto = '')
    {
        $corte = CurrentAcountHelper::primer_corte_de_la_cadena($cuenta->id);

        $this->assertNull(
            $corte,
            $contexto.' La cadena de saldos de la cuenta '.$cuenta->id.' (moneda '.$cuenta->moneda_id.') está cortada: '.json_encode($corte)
        );

        $descuadre = CurrentAcountHelper::descuadre_del_saldo_final(CreditAccount::find($cuenta->id));

        $this->assertNull(
            $descuadre,
            $contexto.' El saldo guardado de la cuenta '.$cuenta->id.' (moneda '.$cuenta->moneda_id.') no coincide con el de su último movimiento: '.json_encode($descuadre)
        );
    }

    /**
     * Compara un float contra otro con la tolerancia de centavos.
     *
     * @param float $esperado
     * @param float $obtenido
     * @param string $mensaje
     * @return void
     */
    protected function assertMonto($esperado, $obtenido, $mensaje = '')
    {
        $this->assertEqualsWithDelta((float) $esperado, (float) $obtenido, self::DELTA, $mensaje);
    }
}
