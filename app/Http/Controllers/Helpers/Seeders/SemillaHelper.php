<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Http\Controllers\Helpers\expense\ExpenseCajaHelper;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\Expense;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de la semilla de datos de prueba para reportes (grupo 321, prompt 04).
 *
 * Regla que gobierna toda la clase: cada primitiva ejecuta la operación por el camino REAL del
 * sistema (el mismo helper que usa el controller correspondiente), nunca `Model::create()` a
 * mano para la entidad principal. Un insert directo se saltea `SaleCajaHelper` /
 * `CurrentAcountCajaHelper`, que son quienes mandan `aplica_liquidacion => true` a
 * `MovimientoCajaHelper::crear_movimiento()` -- sin ellos no hay movimiento de caja, ni
 * liquidación, ni comisión, y el Flujo de Caja queda vacío mientras el Estado de Resultados
 * muestra números. El criterio y el orden de llamadas están tomados de
 * `tests/Concerns/EscenariosDePlata.php` (grupo 242), adaptado de HTTP (`postJson`) a llamada
 * directa de helper porque esto corre desde un comando de consola, no desde un test.
 *
 * El reloj: `MovimientoCajaHelper::crear_movimiento()` arma el `MovimientoCaja::create([...])`
 * SIN `created_at` y le pasa `Carbon::now()` a `CajaLiquidacionHelper::calcular()` -- un
 * movimiento de caja siempre se fecha cuando se ejecuta el código, no cuando dice la venta. Por
 * eso cada primitiva mueve el reloj con `Carbon::setTestNow($fecha)` antes de operar y lo
 * restaura en un `finally` (nunca queda fijado si algo tira excepción a mitad de camino).
 * `Carbon::setTestNow()` en código de aplicación es aceptable ACÁ solo porque este comando no
 * puede correr fuera de local (guarda del prompt 05) -- no es un patrón para copiar en código de
 * request/response real.
 */
class SemillaHelper
{
    /**
     * Resumen acumulado de lo que se fue ejecutando (tipo de operación, fecha, monto, modelo
     * creado, métodos de pago usados). El comando del prompt 05 arma la planilla de control a
     * partir de esto.
     *
     * @var array<int, array<string, mixed>>
     */
    public $resumen = [];

    /**
     * Venta de mostrador (sin cliente), cobrada de contado por uno o más métodos de pago.
     * Camino real: `SaleSeederHelper::create_sales()`, que hace `Sale::create()` +
     * `SaleHelper::attachProperies()` y de ahí cae en `SaleCajaHelper::check_caja()`.
     *
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param int $address_id Sucursal de la venta.
     * @param array $metodos Lista de ['current_acount_payment_method_id' => N, 'amount' => X, 'caja_id' => C].
     * @return \App\Models\Sale
     */
    function venta_mostrador($fecha, $monto, $address_id, $metodos)
    {
        $this->asegurar_semilla_user_id_coincide_con_app_user_id();

        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $articulo = $this->articulo_de_operacion();
            $num = $this->next_num('sales');

            SaleSeederHelper::create_sales([
                [
                    'num'             => $num,
                    'total'           => $monto,
                    'address_id'      => $address_id,
                    'employee_id'     => null,
                    'client_id'       => null,
                    'created_at'      => Carbon::now(),
                    'terminada'       => 1,
                    'confirmed'       => 1,
                    'articles'        => [
                        [
                            'id'           => $articulo->id,
                            'price_vender' => $monto,
                            'cost'         => $monto / 2,
                            'amount'       => 1,
                        ],
                    ],
                    'payment_methods' => $metodos,
                ],
            ]);

            $sale = Sale::where('user_id', config('app.USER_ID'))->where('num', $num)->first();

            $this->registrar('venta_mostrador', $fecha, $monto, $sale, $metodos);

            return $sale;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Venta a cuenta corriente de un cliente (sin métodos de pago: queda como deuda). Mismo
     * camino que `venta_mostrador()`, con `client_id` seteado y `payment_methods` vacío.
     *
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param int $client_id
     * @param int $address_id
     * @return \App\Models\Sale
     */
    function venta_cuenta_corriente($fecha, $monto, $client_id, $address_id)
    {
        $this->asegurar_semilla_user_id_coincide_con_app_user_id();

        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $articulo = $this->articulo_de_operacion();
            $num = $this->next_num('sales');

            SaleSeederHelper::create_sales([
                [
                    'num'             => $num,
                    'total'           => $monto,
                    'address_id'      => $address_id,
                    'employee_id'     => null,
                    'client_id'       => $client_id,
                    'created_at'      => Carbon::now(),
                    'terminada'       => 1,
                    'confirmed'       => 1,
                    'articles'        => [
                        [
                            'id'           => $articulo->id,
                            'price_vender' => $monto,
                            'cost'         => $monto / 2,
                            'amount'       => 1,
                        ],
                    ],
                    'payment_methods' => [],
                ],
            ]);

            $sale = Sale::where('user_id', config('app.USER_ID'))->where('num', $num)->first();

            $this->registrar('venta_cuenta_corriente', $fecha, $monto, $sale);

            return $sale;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Cobro de cuenta corriente a un cliente. Camino real: el mismo que
     * `CurrentAcountController::pago()` -- crear el `CurrentAcount` (haber), adjuntar métodos de
     * pago vía `CurrentAcountPagoHelper::attachPaymentMethods()` (que es quien manda
     * `aplica_liquidacion => true` a través de `CurrentAcountCajaHelper::guardar_pago()`),
     * calcular el saldo resultante e imputar contra los débitos pendientes con
     * `CurrentAcountPagoHelper->init()` -- igual que hace `ReportesMesSeeder` hoy.
     *
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param int $client_id
     * @param array $metodos
     * @return \App\Models\CurrentAcount
     */
    function cobro_cuenta_corriente($fecha, $monto, $client_id, $metodos)
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $credit_account = $this->credit_account_para('client', $client_id);

            $pago = CurrentAcount::create([
                'haber'              => $monto,
                'description'        => 'Cobro sembrado',
                'credit_account_id'  => $credit_account->id,
                'is_provisorio'      => 0,
                'status'             => 'pago_from_client',
                'user_id'            => config('semilla.user_id'),
                // getNumReceipt() filtra por UserHelper::userId(), que en consola (sin sesion)
                // cae al mismo fallback que config('app.USER_ID').
                'num_receipt'        => CurrentAcountHelper::getNumReceipt(),
                'client_id'          => $client_id,
                'created_at'         => Carbon::now(),
                'employee_id'        => null,
            ]);

            $pago->detalle = 'Pago N°'.$pago->num_receipt;
            $pago->save();

            CurrentAcountPagoHelper::attachPaymentMethods($pago, $metodos, 'client');

            $pago->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $pago) - (float) $monto;
            $pago->save();

            CurrentAcountHelper::update_credit_account_saldo($credit_account->id);

            // Pago con fecha actual (Carbon::now() ya vive en $fecha por el setTestNow): alcanza
            // con imputar el pago nuevo, no hace falta recalcular la cuenta corriente entera.
            $pago_helper = new CurrentAcountPagoHelper($credit_account->id, 'client', $client_id, $pago);
            $pago_helper->init();

            $this->registrar('cobro_cuenta_corriente', $fecha, $monto, $pago, $metodos);

            return $pago;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Compra a un proveedor: pedido + débito en su cuenta corriente.
     *
     * `ProviderOrderHelper::attachArticles()` -> `saveCurrentAcount()` -> `createCurrentAcount()`
     * llama `CurrentAcountHelper::getSaldo('provider', $provider_order->provider_id,
     * $current_acount)` con TRES argumentos, pero la firma real es
     * `getSaldo($credit_account_id, $until_current_acount = null)` -- DOS. El string 'provider'
     * se toma como `$credit_account_id` (no matchea ninguna cuenta real) y el tercer argumento
     * PHP lo descarta en silencio: la query de saldo queda rota. Verificado el 3/8/2026 leyendo
     * `ProviderOrderHelper.php` en esta misma rama (`refractor`) -- el bug sigue ahí, no se
     * arregló. Por eso NO se usa `attachArticles()`: se replica a mano el mismo camino que ya
     * usa `ReportesMesSeeder::crear_compra_proveedor()` (adjuntar el artículo directo al pivot +
     * crear el `CurrentAcount` con la firma CORRECTA de `getSaldo()`).
     *
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param int $provider_id
     * @return \App\Models\ProviderOrder
     */
    function compra_a_proveedor($fecha, $monto, $provider_id)
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $articulo = $this->articulo_de_operacion();
            $num = $this->next_num('provider_orders');

            $order = ProviderOrder::create([
                'num'                                     => $num,
                'total_with_iva'                          => 0,
                'total_from_provider_order_afip_tickets'  => 0,
                'provider_id'                              => $provider_id,
                'provider_order_status_id'                 => 1,
                'days_to_advise'                            => 2,
                'created_at'                                 => Carbon::now(),
                'user_id'                                     => config('semilla.user_id'),
            ]);

            $order->articles()->attach($articulo->id, [
                'amount'          => 1,
                'notes'           => null,
                'received'        => 1,
                'cost'            => $monto,
                'price'           => null,
                'received_cost'   => null,
                'update_cost'     => null,
                'update_provider' => null,
                'cost_in_dollars' => null,
                'add_to_articles' => null,
                'address_id'      => null,
                'iva_id'          => null,
            ]);

            $credit_account = $this->credit_account_para('provider', $provider_id);

            $current_acount = CurrentAcount::create([
                'detalle'            => 'Pedido N°'.$order->num,
                'debe'               => $monto,
                'status'             => 'sin_pagar',
                'user_id'            => config('semilla.user_id'),
                'provider_id'        => $provider_id,
                'provider_order_id'  => $order->id,
                'credit_account_id'  => $credit_account->id,
                'created_at'         => Carbon::now(),
            ]);

            // Firma correcta (dos argumentos), a diferencia del bug documentado arriba.
            $current_acount->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $current_acount) + $monto;
            $current_acount->save();

            CurrentAcountHelper::checkSaldos($credit_account->id);

            $this->registrar('compra_a_proveedor', $fecha, $monto, $order);

            return $order;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Pago a un proveedor. Mismo camino que `cobro_cuenta_corriente()` (los dos son
     * `CurrentAcountController::pago()`, agnóstico de `client`/`provider` vía `model_name`), con
     * `provider_id` en vez de `client_id`.
     *
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param int $provider_id
     * @param array $metodos
     * @return \App\Models\CurrentAcount
     */
    function pago_a_proveedor($fecha, $monto, $provider_id, $metodos)
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $credit_account = $this->credit_account_para('provider', $provider_id);

            $pago = CurrentAcount::create([
                'haber'              => $monto,
                'description'        => 'Pago a proveedor sembrado',
                'credit_account_id'  => $credit_account->id,
                'is_provisorio'      => 0,
                'status'             => 'pago_from_client',
                'user_id'            => config('semilla.user_id'),
                'num_receipt'        => CurrentAcountHelper::getNumReceipt(),
                'provider_id'        => $provider_id,
                'created_at'         => Carbon::now(),
                'employee_id'        => null,
            ]);

            $pago->detalle = 'Pago N°'.$pago->num_receipt;
            $pago->save();

            CurrentAcountPagoHelper::attachPaymentMethods($pago, $metodos, 'provider');

            $pago->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $pago) - (float) $monto;
            $pago->save();

            CurrentAcountHelper::update_credit_account_saldo($credit_account->id);

            $pago_helper = new CurrentAcountPagoHelper($credit_account->id, 'provider', $provider_id, $pago);
            $pago_helper->init();

            $this->registrar('pago_a_proveedor', $fecha, $monto, $pago, $metodos);

            return $pago;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Gasto, opcionalmente pagado por caja. Camino real: el mismo que `ExpenseController::store()`
     * -- `Expense::create()`, `PaymentMethodHelper::attach_payment_methods()` (que ya corrige el
     * bug del prompt 03: `continue`, no `return`) y, por cada método de pago no-cheque con
     * `caja_id`, `ExpenseCajaHelper::guardar_movimiento_caja()`. Un gasto sin método de pago no
     * mueve caja y el egreso no aparece en el Flujo de Caja -- es comportamiento esperado, no un
     * caso a evitar (hay gastos que se cargan sin pagar, ej. a cuenta corriente de proveedor).
     *
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param int $expense_concept_id
     * @param array $metodos
     * @return \App\Models\Expense
     */
    function gasto($fecha, $monto, $expense_concept_id, $metodos)
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $num = $this->next_num('expenses');

            $expense = Expense::create([
                'num'                 => $num,
                'expense_concept_id'  => $expense_concept_id,
                'amount'              => $monto,
                'moneda_id'           => 1,
                'importe_iva'         => 0,
                'observations'        => 'Gasto sembrado',
                'created_at'          => Carbon::now(),
                'user_id'             => config('semilla.user_id'),
                'caja_id'             => 0,
            ]);

            PaymentMethodHelper::attach_payment_methods($expense, $metodos);

            $expense->load('current_acount_payment_methods');

            foreach ($expense->current_acount_payment_methods as $payment_method) {
                // Comparación copiada TAL CUAL de ExpenseController::store(): el camino real que
                // este sembrador imita, con sus mismas reglas (bugs incluidos, si los hay) -- no
                // corresponde "arreglarla" acá, eso cambiaría el comportamiento que se está
                // sembrando para probar.
                if (
                    $payment_method->type != 'cheque'
                    && $payment_method->pivot->caja_id
                ) {
                    ExpenseCajaHelper::guardar_movimiento_caja($expense, [
                        'amount'  => $payment_method->pivot->amount,
                        'caja_id' => $payment_method->pivot->caja_id,
                    ]);
                }
            }

            $this->registrar('gasto', $fecha, $monto, $expense, $metodos);

            return $expense;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Devolución (nota de crédito) a un cliente. Camino real:
     * `CurrentAcountHelper::notaCredito()`, que ya existe y ya usa la firma CORRECTA de
     * `getSaldo()` -- crea el `CurrentAcount` con `status = 'nota_credito'` y `haber`, que es
     * exactamente lo que lee `ContabilidadRepository::query_devoluciones()`.
     *
     * 🔴 Se le pasa un `$items` con el mismo costo al 50% que usa `venta_mostrador()`/
     * `venta_cuenta_corriente()` (ver `attachNotaCreditoArticles()`), para que
     * `ContabilidadRepository::costo_mercaderia_devuelta()` (que lee `article_current_acount.cost`)
     * neteé contra `costo_mercaderia_vendida()` -- sin esto, toda devolución queda con costo
     * devuelto en 0 y el Estado de Resultados deja de cerrar "resultado_bruto = mitad de las
     * ventas netas" apenas hay devoluciones sembradas (hallazgo del prompt 06, grupo 321,
     * 3/8/2026: con `DEVOLUCIONES_FRACCION=0.10` y costo al 50%, sin este neteo el resultado
     * bruto daba 4/9 de las ventas netas, no 1/2).
     *
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param int $client_id
     * @return \App\Models\CurrentAcount
     */
    function devolucion($fecha, $monto, $client_id)
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $credit_account = $this->credit_account_para('client', $client_id);
            $articulo = $this->articulo_de_operacion();

            $items = [
                [
                    'is_article'         => true,
                    'unidades_devueltas' => 1,
                    'costo_real'         => round($monto * 0.5, 2),
                    'id'                 => $articulo->id,
                    'price_vender'       => $monto,
                    'discount'           => 0,
                ],
            ];

            $nota_credito = CurrentAcountHelper::notaCredito(
                $credit_account->id,
                $monto,
                'Devolución sembrada',
                'client',
                $client_id,
                null,
                $items
            );

            $this->registrar('devolucion', $fecha, $monto, $nota_credito);

            return $nota_credito;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Comprobante de venta (factura A) para una venta ya creada. Es lo único que lee
     * `ContabilidadRepository::iva_debito()`, que filtra por `afip_fecha_emision` (fecha de
     * EMISIÓN), no por `created_at` de la venta -- por eso este método completa ese campo
     * explícitamente y no confía en el timestamp automático.
     *
     * @param string|\Carbon\Carbon $fecha
     * @param int $sale_id
     * @param float $importe_iva
     * @return \App\Models\AfipTicket
     */
    function comprobante_de_venta($fecha, $sale_id, $importe_iva)
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $afip_ticket = AfipTicket::create([
                'sale_id'             => $sale_id,
                'resultado'           => 'A',
                // Campo que iva_debito() realmente filtra -- ver PHPDoc del método.
                'afip_fecha_emision'  => Carbon::now(),
                'importe_iva'         => $importe_iva,
                // cbte_numero es cosmetico (afip_tickets no tiene columna num, y ningun reporte
                // de ContabilidadRepository lo lee): alcanza con que sea distinguible.
                'cbte_numero'         => (string) $sale_id,
            ]);

            $this->registrar('comprobante_de_venta', $fecha, $importe_iva, $afip_ticket);

            return $afip_ticket;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Comprobante de compra (factura de proveedor) para un pedido ya creado. Es la fuente de
     * `iva_credito()`, `percepciones_sufridas()` y `retenciones_sufridas()`, todos filtrados por
     * `issued_at` (fecha de emisión) y `user_id` -- confirmado leyendo
     * `ContabilidadRepository::query_iva_credito_compras/percepciones_sufridas/retenciones_sufridas()`.
     *
     * @param string|\Carbon\Carbon $fecha
     * @param int $provider_order_id
     * @param array $datos Puede traer total_iva, percepcion_iva, percepcion_iibb, retencion_iva,
     *                      retencion_iibb, retencion_ganancias. Lo que no venga queda en 0.
     * @return \App\Models\ProviderOrderAfipTicket
     */
    function comprobante_de_compra($fecha, $provider_order_id, $datos = [])
    {
        Carbon::setTestNow(Carbon::parse($fecha));

        try {
            $afip_ticket = ProviderOrderAfipTicket::create(array_merge([
                'provider_order_id'    => $provider_order_id,
                'user_id'              => config('semilla.user_id'),
                'issued_at'            => Carbon::now(),
                'total_iva'            => 0,
                'percepcion_iva'       => 0,
                'percepcion_iibb'      => 0,
                'retencion_iva'        => 0,
                'retencion_iibb'       => 0,
                'retencion_ganancias'  => 0,
            ], $datos));

            $this->registrar('comprobante_de_compra', $fecha, $afip_ticket->total_iva, $afip_ticket);

            return $afip_ticket;
        } finally {
            Carbon::setTestNow(null);
        }
    }

    /**
     * Resuelve el `caja_id` por defecto de un método de pago para una sucursal, consultando
     * `DefaultPaymentMethodCaja` (grupo 321, prompt 02) -- NUNCA hardcodeado, para que el
     * sembrador siga siendo correcto si mañana cambia la estructura de cajas. Falla ruidoso si
     * no encuentra: seguir sin caja es exactamente el modo de falla silencioso que este grupo
     * entero viene a cerrar (ver prompt 03).
     *
     * 🔴 `$address_id` es OBLIGATORIO en el caso normal, también para las cajas COMPARTIDAS
     * (Mercado Pago, Banco, Tarjetas, Cheques). Verificado leyendo `CajaSeeder::set_cajas_por_defecto()`
     * (prompt 02): cuando hay sucursales sembradas, esa rama crea UNA FILA DE
     * `DefaultPaymentMethodCaja` POR SUCURSAL para cada caja compartida, todas apuntando al
     * mismo `caja_id` -- nunca escribe una fila con `address_id` null. Pasale cualquiera de las
     * sucursales existentes; el resultado es el mismo `caja_id` sin importar cuál elijas. Solo
     * pasá `null` a propósito para el caso degenerado de `CajaSeeder` sin NINGUNA `Address`
     * sembrada, donde la caja `Efectivo` compartida sí tiene su única fila con `address_id` null.
     *
     * @param int $current_acount_payment_method_id
     * @param int|null $address_id
     * @return int
     */
    function caja_para($current_acount_payment_method_id, $address_id)
    {
        $query = DefaultPaymentMethodCaja::where('current_acount_payment_method_id', $current_acount_payment_method_id)
            ->where('user_id', config('semilla.user_id'));

        if (is_null($address_id)) {
            $query->whereNull('address_id');
        } else {
            $query->where('address_id', $address_id);
        }

        $default = $query->first();

        if (is_null($default)) {
            throw new \Exception(
                'SemillaHelper::caja_para() no encontró caja por defecto para el método de pago '
                .$current_acount_payment_method_id.' y address_id '
                .(is_null($address_id) ? 'null' : $address_id)
                .'. Verificar que el prompt 02 (CajaSeeder) ya haya corrido para este usuario.'
            );
        }

        return (int) $default->caja_id;
    }

    /**
     * `SaleSeederHelper::create_sales()` usa `config('app.USER_ID')` puertas adentro -- no lo
     * parametriza. `venta_mostrador()` y `venta_cuenta_corriente()` después buscan la venta recién
     * creada por `num` + ese mismo `user_id`, para poder devolverla. Si `SEMILLA_USER_ID`
     * llegara a divergir de `USER_ID` (config/semilla.php lo permite explícitamente), el
     * `Sale::where(...)->first()` buscaría con el `user_id` equivocado -- en el mejor caso no
     * encuentra nada (`null`), en el peor trae una venta de otra cuenta con el mismo `num`
     * (coincidencia posible, porque `next_num()` sí corre sobre `semilla.user_id`). Fallar acá,
     * ruidoso, antes de sembrar nada, es más seguro que dejar que compras/comprobantes
     * posteriores del prompt 05 se cuelguen de una venta ajena en silencio.
     *
     * @return void
     */
    protected function asegurar_semilla_user_id_coincide_con_app_user_id()
    {
        if ((int) config('semilla.user_id') !== (int) config('app.USER_ID')) {
            throw new \Exception(
                'SemillaHelper: config(\'semilla.user_id\') ('.config('semilla.user_id').') difiere de '
                .'config(\'app.USER_ID\') ('.config('app.USER_ID').'). SaleSeederHelper::create_sales() '
                .'siempre crea la venta con app.USER_ID, así que venta_mostrador()/venta_cuenta_corriente() '
                .'no pueden garantizar encontrar la venta correcta con SEMILLA_USER_ID distinto.'
            );
        }
    }

    /**
     * Cuenta corriente (moneda 1) de un cliente o proveedor. Falla ruidoso si no existe -- el
     * prompt 01 ya garantiza `credit_account` para los 10 clientes y los 10 proveedores.
     *
     * @param string $model_name 'client' o 'provider'.
     * @param int $model_id
     * @return \App\Models\CreditAccount
     */
    protected function credit_account_para($model_name, $model_id)
    {
        $credit_account = CreditAccount::where('model_name', $model_name)
            ->where('model_id', $model_id)
            ->where('moneda_id', 1)
            ->first();

        if (is_null($credit_account)) {
            throw new \Exception(
                'SemillaHelper: no se encontró credit_account de '.$model_name.' id '.$model_id.' (moneda 1).'
            );
        }

        return $credit_account;
    }

    /**
     * Cualquier artículo real del usuario que se está sembrando, para las primitivas que
     * necesitan un renglón de artículo (venta, compra). No depende de un id fijo: la semilla no
     * crea artículos nuevos, usa los que ya existen en la cuenta.
     *
     * @return \App\Models\Article
     */
    protected function articulo_de_operacion()
    {
        $articulo = Article::where('user_id', config('semilla.user_id'))->first();

        if (is_null($articulo)) {
            throw new \Exception('SemillaHelper: el usuario '.config('semilla.user_id').' no tiene ningún artículo para armar el renglón de la operación.');
        }

        return $articulo;
    }

    /**
     * Correlativo siguiente de `num` para una tabla, igual de criterio que `Controller::num()`
     * (MAX + 1 por `user_id`) pero sin el `lockForUpdate`: este comando corre en un solo proceso,
     * sin concurrencia que serializar.
     *
     * @param string $table
     * @return int
     */
    protected function next_num($table)
    {
        $last = DB::table($table)->where('user_id', config('semilla.user_id'))->max('num');

        return is_null($last) ? 1 : ((int) $last) + 1;
    }

    /**
     * Acumula un renglón en el resumen de lo ejecutado, para la planilla de control del prompt 05.
     *
     * @param string $tipo
     * @param string|\Carbon\Carbon $fecha
     * @param float $monto
     * @param mixed $modelo Modelo creado (o null).
     * @param array|null $metodos Métodos de pago usados, si aplica.
     * @return void
     */
    protected function registrar($tipo, $fecha, $monto, $modelo, $metodos = null)
    {
        $this->resumen[] = [
            'tipo'     => $tipo,
            'fecha'    => Carbon::parse($fecha)->format('Y-m-d'),
            'monto'    => $monto,
            'modelo'   => $modelo ? get_class($modelo) : null,
            'model_id' => $modelo ? $modelo->id : null,
            'metodos'  => $metodos,
        ];
    }
}
