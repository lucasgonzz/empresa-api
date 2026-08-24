<?php

namespace Tests\Concerns;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\ArticlePurchase;
use App\Models\Caja;
use App\Models\CajaLiquidacionConfig;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\AperturaCaja;
use App\Models\MovimientoCaja;
use App\Models\MovimientoEntreCaja;
use App\Models\Sale;
use Carbon\Carbon;

/**
 * Grupo 242 · Prompt 03 — trait de escenarios de plata reusable por las suites de tesorería
 * (grupos 243 a 246).
 *
 * Todos los escenarios se arman pegándole a los endpoints HTTP reales (`POST api/sale`,
 * `POST api/expense`, etc.), NUNCA con `Model::create()` directo: un insert a mano se saltea
 * `SaleCajaHelper`/`CurrentAcountCajaHelper`, que son justamente quienes mandan
 * `aplica_liquidacion => true` a `MovimientoCajaHelper::crear_movimiento()`. La única excepción es
 * `configurar_override_caja()`, que escribe directo en `caja_liquidacion_configs`: es setup de la
 * caja, no el comportamiento bajo prueba.
 *
 * Este trait se apoya en `Tests\EmpresaTestCase` (usa `$this->fail()`, `$this->postJson()`, etc.,
 * heredados de `Illuminate\Foundation\Testing\TestCase`): la clase que lo use tiene que extender
 * esa base.
 */
trait EscenariosDePlata
{
    /**
     * Instante base del escenario, para mover el reloj de forma determinista (ver
     * avanzar_reloj_de_ventas()).
     *
     * @var \Carbon\Carbon|null
     */
    protected $reloj_base_escenarios = null;

    /**
     * Segundos ya desplazados en este test (10 por venta posteada).
     *
     * @var int
     */
    protected $segundos_desplazados_escenarios = 0;

    /**
     * Grupo 260 · Prompt 01 — último instante que el propio trait dejó en Carbon::setTestNow()
     * (vía avanzar_reloj_de_ventas() o fijar_reloj_en()). Sirve para distinguir "el reloj se movió
     * porque este trait lo movió" de "el reloj se movió porque alguien más (fijar_reloj_en() u otro
     * setTestNow() directo del test) lo cambió por fuera": si el reloj vigente no coincide con este
     * valor, avanzar_reloj_de_ventas() sabe que tiene que re-basear en vez de pisarlo.
     *
     * @var \Carbon\Carbon|null
     */
    protected $ultimo_reloj_aplicado = null;

    /**
     * Ids de las Sale creadas por crear_venta_cobrada(), para limpiar_escenarios().
     *
     * @var array<int,int>
     */
    protected $ventas_creadas_por_escenarios = [];

    /**
     * Ids de los Expense creados directamente por crear_gasto() (no los gastos automáticos de
     * comisión, esos se resuelven vía movimiento_caja->comision_expense_id en el cleanup).
     *
     * @var array<int,int>
     */
    protected $gastos_creados_por_escenarios = [];

    /**
     * Ids de los CurrentAcount (pagos/cobros) creados por cobrar_cuenta_corriente().
     *
     * @var array<int,int>
     */
    protected $cobros_cc_creados_por_escenarios = [];

    /**
     * Ids de los MovimientoEntreCaja creados por transferir_entre_cajas().
     *
     * @var array<int,int>
     */
    protected $movimientos_entre_caja_creados_por_escenarios = [];

    /**
     * Ids de TODOS los MovimientoCaja creados por cualquiera de los métodos de este trait,
     * capturados por "marca de agua" de id (ver registrar_movimientos_caja_nuevos()): no hay forma
     * uniforme de llegar a ellos por FK (un cobro de cuenta corriente no guarda el id del
     * movimiento que generó), así que se detectan por rango de id en vez de por relación.
     *
     * @var array<int,int>
     */
    protected $movimientos_caja_creados_por_escenarios = [];

    /**
     * Ids de los CajaLiquidacionConfig creados/actualizados por configurar_override_caja().
     *
     * @var array<int,int>
     */
    protected $overrides_creados_por_escenarios = [];

    /**
     * Cajas que este trait dejó abiertas (`caja_id => true`) porque estaban cerradas antes de que
     * algún escenario las necesitara. Solo esas se vuelven a cerrar en el cleanup.
     *
     * @var array<int,bool>
     */
    protected $cajas_abiertas_por_escenarios = [];

    /**
     * Ids de los AperturaCaja creados por asegurar_caja_abierta(), para borrarlos en el cleanup.
     *
     * @var array<int,int>
     */
    protected $aperturas_creadas_por_escenarios = [];

    /**
     * Saldo contable original (columna `cajas.saldo`) de cada caja tocada por un escenario,
     * capturado la primera vez que se resuelve esa caja por nombre. `MovimientoCajaHelper::set_saldos()`
     * escribe ese número directo sobre `cajas.saldo`; borrar los `movimiento_cajas` después no lo
     * revierte solo, así que hay que restaurarlo a mano en el cleanup.
     *
     * @var array<int,float|null>
     */
    protected $saldos_originales_cajas = [];

    /**
     * Crea y postea una venta contra el endpoint real (`POST api/sale`), cobrada de contado por
     * la caja y método de pago indicados. La venta se hace sin cliente y con
     * `omitir_en_cuenta_corriente = 1` para que `SaleHelper::attachSelectedPaymentMethods()` adjunte
     * los métodos de pago (esa función solo lo hace si `is_null(client_id) || omitir_en_cuenta_corriente`).
     *
     * @param string $caja_nombre Nombre de la caja del fixture (constante de TestingFerreteriaSeeder).
     * @param string $metodo_pago_nombre Nombre del método de pago de cuenta corriente del fixture.
     * @param float $monto Monto cobrado.
     * @param array<string,mixed> $overrides Claves del payload de `POST api/sale` a pisar/agregar.
     * @return \App\Models\Sale Venta recién creada, refrescada desde la base.
     */
    protected function crear_venta_cobrada($caja_nombre, $metodo_pago_nombre, $monto, $overrides = [])
    {
        $caja = $this->resolver_caja_por_nombre($caja_nombre);
        $metodo_pago = $this->resolver_metodo_pago_por_nombre($metodo_pago_nombre);

        $this->asegurar_caja_abierta($caja);

        // Bug 3 del prompt: sin este avance de reloj, la segunda venta con el mismo total cae en
        // el guard anti-duplicado de SaleController::venta_ya_cread() y desaparece en silencio.
        $this->avanzar_reloj_de_ventas();

        /** Payload mínimo de una venta de mostrador cobrada de contado, pisable por $overrides. */
        $payload = array_merge([
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                          => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                       => null,
            'sub_total'                         => $monto,
            'total'                              => $monto,
            'terminada'                          => 1,
            'seller_id'                          => null,
            'cantidad_cuotas'                   => null,
            'cuota_descuento'                   => 0,
            'cuota_recargo'                      => 0,
            'caja_id'                            => null,
            'afip_tipo_comprobante_id'          => null,
            'descuento'                          => 0,
            'discounts'                          => [],
            'surchages'                          => [],
            'items'                               => [],
            'selected_payment_methods'          => [
                [
                    'current_acount_payment_method_id' => $metodo_pago->id,
                    'amount'                             => $monto,
                    'caja_id'                             => $caja->id,
                ],
            ],
        ], $overrides);

        /** Marca de agua: id máximo de movimiento_cajas antes de postear, para detectar los nuevos. */
        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/sale', $payload);

        $venta_id = $this->extraer_id_de_respuesta(
            $response,
            'api/sale',
            'Motivo probable: el guard anti-duplicado SaleController::venta_ya_cread() descartó esta '.
            'venta por coincidir en client_id/employee_id/total ('.$monto.') con otra creada hace '.
            'menos de 5 segundos — revisar que avanzar_reloj_de_ventas() haya corrido antes de este POST.'
        );

        $this->ventas_creadas_por_escenarios[] = $venta_id;

        $this->registrar_movimientos_caja_nuevos($antes);

        return Sale::find($venta_id);
    }

    /**
     * Crea y postea un gasto contra el endpoint real (`POST api/expense`). No fija caja ni método
     * de pago por defecto (un gasto sin `payment_methods` no genera movimiento de caja): quien
     * necesite que el gasto impacte una caja concreta lo manda en $overrides, ej.:
     * `crear_gasto($concepto, $monto, ['payment_methods' => [[...]]])`.
     *
     * @param string $concepto_nombre Nombre del ExpenseConcept del fixture.
     * @param float $monto
     * @param array<string,mixed> $overrides Claves del payload de `POST api/expense` a pisar/agregar.
     * @return \App\Models\Expense Gasto recién creado, refrescado desde la base.
     */
    protected function crear_gasto($concepto_nombre, $monto, $overrides = [])
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre($concepto_nombre);

        $payload = array_merge([
            'expense_concept_id'   => $concepto->id,
            'amount'                 => $monto,
            'moneda_id'              => 1,
            'importe_iva'            => 0,
            'observations'           => 'Escenario de test — '.$concepto_nombre,
            'created_at'             => Carbon::now()->format('Y-m-d H:i:s'),
            'payment_methods'        => [],
        ], $overrides);

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/expense', $payload);

        $gasto_id = $this->extraer_id_de_respuesta($response, 'api/expense');

        $this->gastos_creados_por_escenarios[] = $gasto_id;

        $this->registrar_movimientos_caja_nuevos($antes);

        return Expense::find($gasto_id);
    }

    /**
     * Registra un cobro de cuenta corriente a un cliente (`POST api/current-acount/pago`), el
     * segundo camino (junto con SaleCajaHelper) que manda `aplica_liquidacion => true` a
     * `MovimientoCajaHelper::crear_movimiento()` (ver CurrentAcountCajaHelper::guardar_pago()).
     * Garantiza que el cliente tenga `credit_account` antes de cobrar (no toda la fixture de
     * clientes trae una precargada, ver comentario de CLIENTE_CC en TestingFerreteriaSeeder).
     *
     * Prompt 380/02 — `CurrentAcountController::pago()` (línea ~118 al momento de este prompt)
     * devuelve el pago bajo la clave `current_acount`, no `model`. Es la única diferencia real:
     * relevamiento completo de los endpoints que usa este trait, mirando el `return
     * response()->json(...)` real de cada controller (no lo que "debería" devolver):
     *
     *   | Endpoint                        | Controller@método                  | Clave   |
     *   |----------------------------------|-------------------------------------|---------|
     *   | POST api/sale                    | SaleController::store()             | model   |
     *   | POST api/expense                 | ExpenseController::store()          | model   |
     *   | POST api/movimiento-entre-caja    | MovimientoEntreCajaController::store() | model |
     *   | POST api/current-acount/pago      | CurrentAcountController::pago()     | current_acount |
     *
     * No se toca `CurrentAcountController::pago()` para unificarlo a `model`: esa respuesta la
     * consume `empresa-spa` en producción, y cambiarle la forma es un cambio de contrato de API
     * que merece su propio grupo, con el front adelante — acá el que estaba mal era este helper de
     * tests, que asumía una convención que este endpoint en particular nunca tuvo.
     *
     * @param string $cliente_nombre Nombre del Client del fixture.
     * @param string $caja_nombre Nombre de la caja que recibe el cobro.
     * @param string $metodo_pago_nombre Nombre del método de pago de cuenta corriente.
     * @param float $monto
     * @return \App\Models\CurrentAcount Pago recién creado, refrescado desde la base.
     */
    protected function cobrar_cuenta_corriente($cliente_nombre, $caja_nombre, $metodo_pago_nombre, $monto)
    {
        $cliente = $this->resolver_cliente_por_nombre($cliente_nombre);
        $caja = $this->resolver_caja_por_nombre($caja_nombre);
        $metodo_pago = $this->resolver_metodo_pago_por_nombre($metodo_pago_nombre);

        // Idempotente (firstOrCreate por dentro): no duplica si el cliente ya tenía credit_account.
        CreditAccountHelper::crear_credit_accounts('client', $cliente->id);

        $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $cliente->id)
                                        ->where('moneda_id', 1)
                                        ->first();

        if (is_null($credit_account)) {
            $this->fail(
                'No se pudo resolver la credit_account (moneda 1) del cliente "'.$cliente_nombre.'" '.
                'despues de CreditAccountHelper::crear_credit_accounts().'
            );
        }

        $payload = [
            'description'                         => 'Cobro escenario de test',
            'credit_account_id'                   => $credit_account->id,
            'is_provisorio'                        => 0,
            'model_name'                            => 'client',
            'model_id'                              => $cliente->id,
            'haber'                                  => $monto,
            // Evita que pago() dispare el recálculo completo de la cuenta corriente
            // (CurrentAcountHelper::check_saldos_y_pagos), que es carísimo y no hace falta acá.
            'current_date'                           => 1,
            'current_acount_payment_methods'        => [
                [
                    'current_acount_payment_method_id' => $metodo_pago->id,
                    'amount'                             => $monto,
                    'caja_id'                             => $caja->id,
                ],
            ],
        ];

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/current-acount/pago', $payload);

        $pago_id = $this->extraer_id_de_respuesta(
            $response,
            'api/current-acount/pago',
            'Revisar que el cliente "'.$cliente_nombre.'" tenga credit_account de moneda 1 y que la '.
            'caja/metodo de pago existan en el fixture.',
            ['current_acount', 'model']
        );

        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $this->registrar_movimientos_caja_nuevos($antes);

        return CurrentAcount::find($pago_id);
    }

    /**
     * Transfiere plata entre dos cajas (`POST api/movimiento-entre-caja`). Existe para probar el
     * caso negativo del grupo 223: una transferencia hacia una caja comisionada NO tiene que
     * generar ningún Expense (MovimientoEntreCajaHelper no manda `aplica_liquidacion`).
     *
     * @param string $caja_origen Nombre de la caja de origen.
     * @param string $caja_destino Nombre de la caja de destino.
     * @param float $monto
     * @return \App\Models\MovimientoEntreCaja Transferencia recién creada, refrescada desde la base.
     */
    protected function transferir_entre_cajas($caja_origen, $caja_destino, $monto)
    {
        $origen = $this->resolver_caja_por_nombre($caja_origen);
        $destino = $this->resolver_caja_por_nombre($caja_destino);

        $this->asegurar_caja_abierta($origen);
        $this->asegurar_caja_abierta($destino);

        $payload = [
            'from_caja_id' => $origen->id,
            'to_caja_id'    => $destino->id,
            'amount'         => $monto,
        ];

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/movimiento-entre-caja', $payload);

        $movimiento_id = $this->extraer_id_de_respuesta($response, 'api/movimiento-entre-caja');

        $this->movimientos_entre_caja_creados_por_escenarios[] = $movimiento_id;

        $this->registrar_movimientos_caja_nuevos($antes);

        return MovimientoEntreCaja::find($movimiento_id);
    }

    /**
     * Crea (o actualiza) el override de liquidación/comisión de una caja para un método de pago
     * puntual, escribiendo directo en `caja_liquidacion_configs` (única excepción a la regla de
     * "todo por endpoint": es configuración de la caja, no el comportamiento bajo prueba).
     *
     * Solo setea las claves presentes en $campos (más caja_id/current_acount_payment_method_id/
     * user_id): el resto queda en null, que es lo que permite probar la cascada por campo del
     * grupo 223 (un override que solo define comision_porcentaje hereda dias_liquidacion de la caja).
     *
     * @param string $caja_nombre
     * @param string $metodo_pago_nombre
     * @param array<string,mixed> $campos Columnas de caja_liquidacion_configs a setear explícitamente.
     * @return \App\Models\CajaLiquidacionConfig
     */
    protected function configurar_override_caja($caja_nombre, $metodo_pago_nombre, $campos)
    {
        $caja = $this->resolver_caja_por_nombre($caja_nombre);
        $metodo_pago = $this->resolver_metodo_pago_por_nombre($metodo_pago_nombre);

        // Bug 2 del prompt: user_id es NOT NULL y tiene que ser el DUEÑO de la caja (no el usuario
        // de sesión del test), o el scope del sistema no va a encontrar este override.
        $datos = array_merge($campos, [
            'caja_id'                            => $caja->id,
            'current_acount_payment_method_id' => $metodo_pago->id,
            'user_id'                            => $caja->user_id,
        ]);

        // updateOrCreate sobre el unique clc_caja_payment_method_unq: dos llamadas seguidas en el
        // mismo test (o entre tests, si el rollback de la transacción no aplicara) no chocan.
        $override = CajaLiquidacionConfig::updateOrCreate(
            [
                'caja_id'                            => $caja->id,
                'current_acount_payment_method_id' => $metodo_pago->id,
            ],
            $datos
        );

        $this->overrides_creados_por_escenarios[] = $override->id;

        return $override;
    }

    /**
     * Devuelve los tres saldos de una caja tal cual los expone `GET api/caja` (payload real de
     * `CajaController@index`), sin recalcularlos con una fórmula propia del test.
     *
     * @param string $caja_nombre
     * @return array<string,float> Array con saldo_contable, saldo_disponible y saldo_a_liquidar.
     */
    protected function saldos_de_caja($caja_nombre)
    {
        $caja = $this->resolver_caja_por_nombre($caja_nombre);

        $response = $this->getJson('api/caja');

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/caja devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        $caja_encontrada = null;

        foreach ($body['models'] as $model) {
            if ((int) $model['id'] === (int) $caja->id) {
                $caja_encontrada = $model;
                break;
            }
        }

        if (is_null($caja_encontrada)) {
            $this->fail('GET api/caja no devolvió la caja "'.$caja_nombre.'" (id '.$caja->id.') en el listado.');
        }

        return [
            'saldo_contable'   => (float) $caja_encontrada['saldo_contable'],
            'saldo_disponible' => (float) $caja_encontrada['saldo_disponible'],
            'saldo_a_liquidar' => (float) $caja_encontrada['saldo_a_liquidar'],
        ];
    }

    /**
     * Grupo 260 · Prompt 01 — ubica el escenario en una fecha/hora concreta, elegida por el test.
     *
     * Existe para que un test pueda controlar en qué mes/día caen las ventas/gastos/cobros que
     * genere a partir de acá (necesario para reportes por rango de fechas, donde cada test necesita
     * un rango exclusivo de verdad, no ventas separadas por segundos). Deja el reloj "propio" del
     * trait (reloj_base_escenarios) re-baseado en este instante, para que la próxima llamada a
     * avanzar_reloj_de_ventas() arranque a contar los 10 segundos de guard anti-duplicado desde acá
     * y no desde donde había quedado antes.
     *
     * @param string|\Carbon\Carbon $momento Fecha/hora a fijar. String aceptado por Carbon::parse()
     *                                        (ej. '2026-01-05' o '2026-01-05 10:00:00') o instancia
     *                                        de Carbon ya construida.
     * @return void
     */
    public function fijar_reloj_en($momento)
    {
        // Normaliza el parámetro a Carbon, acepte string o instancia ya armada.
        $instante = $momento instanceof Carbon ? $momento->copy() : Carbon::parse($momento);

        Carbon::setTestNow($instante);

        // Re-basea el reloj propio del trait en este instante: la próxima venta que se cree va a
        // contar sus 10 segundos de guard anti-duplicado a partir de acá, no del reloj anterior.
        $this->reloj_base_escenarios = $instante->copy();
        $this->segundos_desplazados_escenarios = 0;

        // Registra que este instante fue aplicado por el propio trait, para que
        // avanzar_reloj_de_ventas() no lo confunda con un setTestNow() externo en la próxima llamada.
        $this->ultimo_reloj_aplicado = $instante->copy();
    }

    /**
     * Corre el reloj de la aplicacion 10 segundos hacia adelante antes de cada venta.
     *
     * Motivo: SaleController::venta_ya_cread() descarta una venta identica (mismo cliente,
     * mismo empleado, mismo total) creada en los ultimos 5 segundos, y devuelve 200 con
     * cuerpo vacio sin crear nada. En produccion evita el doble click; en un test que crea
     * dos ventas iguales seguidas hace desaparecer la segunda en silencio.
     *
     * Se mueve el reloj en vez de tocar el guard: el guard es comportamiento de produccion
     * y tiene que seguir corriendo tal cual durante el test.
     *
     * Grupo 260 · Prompt 01 — antes este método pisaba ciegamente cualquier setTestNow() hecho
     * por fuera (ej. fijar_reloj_en()) una vez que reloj_base_escenarios quedaba fijado en la
     * primera venta del test. Ahora, si el reloj vigente no coincide con el último que este mismo
     * método (o fijar_reloj_en()) dejó aplicado, entiende que alguien lo movió por fuera y
     * re-basea en vez de pisar.
     *
     * @return void
     */
    protected function avanzar_reloj_de_ventas()
    {
        // Reloj vigente al momento de llamar: puede venir null si nadie llamó setTestNow() todavía.
        $reloj_vigente = Carbon::getTestNow();

        // Se re-basea cuando: (a) es la primera vez que se llama en este test, o (b) el reloj
        // vigente no coincide con el último que este trait aplicó — señal de que fijar_reloj_en()
        // u otro setTestNow() directo del test lo movió por fuera.
        $hay_que_rebasear = is_null($this->reloj_base_escenarios)
            || is_null($this->ultimo_reloj_aplicado)
            || is_null($reloj_vigente)
            || !$reloj_vigente->eq($this->ultimo_reloj_aplicado);

        if ($hay_que_rebasear) {
            // Carbon::now() respeta el setTestNow() vigente (o devuelve la hora real si no hay
            // ninguno), así que esto toma exactamente el instante que el test dejó puesto.
            $this->reloj_base_escenarios = Carbon::now();
            $this->segundos_desplazados_escenarios = 0;
        }

        $this->segundos_desplazados_escenarios += 10;

        $nuevo_instante = $this->reloj_base_escenarios->copy()->addSeconds($this->segundos_desplazados_escenarios);

        Carbon::setTestNow($nuevo_instante);

        $this->ultimo_reloj_aplicado = $nuevo_instante->copy();
    }

    /**
     * Abre una caja por el endpoint real (`PUT api/abrir-caja/{id}`) si todavía no está abierta,
     * y deja registrado que este trait la abrió (para cerrarla en limpiar_escenarios(), sin tocar
     * una caja que ya estaba abierta por otro motivo).
     *
     * @param \App\Models\Caja $caja
     * @return void
     */
    protected function asegurar_caja_abierta($caja)
    {
        $caja->refresh();

        if ($caja->abierta) {
            return;
        }

        $response = $this->putJson('api/abrir-caja/'.$caja->id);

        if ($response->getStatusCode() !== 200) {
            $this->fail(
                'PUT api/abrir-caja/'.$caja->id.' devolvió '.$response->getStatusCode().'. '.
                'Cuerpo completo: '.$response->getContent()
            );
        }

        $caja->refresh();

        $this->cajas_abiertas_por_escenarios[$caja->id] = true;

        if (!is_null($caja->current_apertura_caja_id)) {
            $this->aperturas_creadas_por_escenarios[] = $caja->current_apertura_caja_id;
        }
    }

    /**
     * Resuelve una Caja del fixture por nombre y aborta el test con un mensaje claro si no existe.
     * De paso, guarda el saldo contable original de esa caja la primera vez que se toca (ver
     * $saldos_originales_cajas), para poder restaurarlo en el cleanup.
     *
     * @param string $nombre
     * @return \App\Models\Caja
     */
    protected function resolver_caja_por_nombre($nombre)
    {
        $caja = Caja::where('name', $nombre)->first();

        if (is_null($caja)) {
            $this->fail('No existe la caja "'.$nombre.'" en el fixture de testing (TestingFerreteriaSeeder).');
        }

        if (!array_key_exists($caja->id, $this->saldos_originales_cajas)) {
            $this->saldos_originales_cajas[$caja->id] = $caja->saldo;
        }

        return $caja;
    }

    /**
     * Resuelve un CurrentAcountPaymentMethod del fixture por nombre.
     *
     * @param string $nombre
     * @return \App\Models\CurrentAcountPaymentMethod
     */
    protected function resolver_metodo_pago_por_nombre($nombre)
    {
        $metodo_pago = CurrentAcountPaymentMethod::where('name', $nombre)->first();

        if (is_null($metodo_pago)) {
            $this->fail('No existe el método de pago "'.$nombre.'" en el fixture de testing.');
        }

        return $metodo_pago;
    }

    /**
     * Resuelve un Client del fixture por nombre.
     *
     * @param string $nombre
     * @return \App\Models\Client
     */
    protected function resolver_cliente_por_nombre($nombre)
    {
        $cliente = Client::where('name', $nombre)->first();

        if (is_null($cliente)) {
            $this->fail('No existe el cliente "'.$nombre.'" en el fixture de testing.');
        }

        return $cliente;
    }

    /**
     * Resuelve un ExpenseConcept del fixture por nombre.
     *
     * @param string $nombre
     * @return \App\Models\ExpenseConcept
     */
    protected function resolver_concepto_gasto_por_nombre($nombre)
    {
        $concepto = ExpenseConcept::where('name', $nombre)->first();

        if (is_null($concepto)) {
            $this->fail('No existe el concepto de gasto "'.$nombre.'" en el fixture de testing.');
        }

        return $concepto;
    }

    /**
     * Id máximo actual de movimiento_cajas, usado como marca de agua para detectar los movimientos
     * que genera cada acción de este trait (no todos los caminos guardan el id del movimiento en el
     * modelo padre, ej. un cobro de cuenta corriente no lo hace).
     *
     * @return int
     */
    protected function max_id_movimiento_caja()
    {
        return (int) (MovimientoCaja::max('id') ?? 0);
    }

    /**
     * Registra en $movimientos_caja_creados_por_escenarios todos los MovimientoCaja con id mayor
     * al capturado antes de la acción (ver max_id_movimiento_caja()).
     *
     * @param int $id_anterior
     * @return void
     */
    protected function registrar_movimientos_caja_nuevos($id_anterior)
    {
        $nuevos_ids = MovimientoCaja::where('id', '>', $id_anterior)->pluck('id');

        foreach ($nuevos_ids as $id) {
            $this->movimientos_caja_creados_por_escenarios[] = $id;
        }
    }

    /**
     * Extrae el id del modelo persistido de una respuesta JSON, o corta el test con `fail()` con el
     * detalle completo (status + cuerpo) si el endpoint no creó nada.
     *
     * Prompt 380/02: no todos los controllers de empresa-api devuelven el modelo bajo la misma
     * clave (`SaleController`, `ExpenseController` y `MovimientoEntreCajaController` usan `model`,
     * pero `CurrentAcountController::pago()` devuelve `current_acount` -- ver la tabla completa en
     * el docblock de `cobrar_cuenta_corriente()`). Antes este helper asumía `model` siempre, así que
     * cualquier endpoint con otra convención hacía morir el test acá, con un mensaje que ni
     * mencionaba la clave real. Ahora recorre una lista de claves candidatas, en orden, hasta
     * encontrar un id.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @param string $endpoint Nombre del endpoint, solo para el mensaje de error.
     * @param string|null $mensaje_guard Pista adicional específica del endpoint (ej. el guard anti-duplicado de ventas).
     * @param string[]|null $claves Claves candidatas del body donde puede venir el modelo, probadas
     *        en orden. Default `['model']`: los llamadores existentes (que nunca pasan este
     *        parámetro) siguen funcionando exactamente igual que antes.
     * @return int
     */
    protected function extraer_id_de_respuesta($response, $endpoint, $mensaje_guard = null, $claves = null)
    {
        if (is_null($claves)) {
            $claves = ['model'];
        }

        $status = $response->getStatusCode();
        $contenido = $response->getContent();

        if ($status >= 500) {
            $this->fail('POST '.$endpoint.' devolvió '.$status.' (error interno). Cuerpo completo: '.$contenido);
        }

        $body = json_decode($contenido, true);

        $model_id = null;
        foreach ($claves as $clave) {
            if (isset($body[$clave]['id'])) {
                $model_id = $body[$clave]['id'];
                break;
            }
        }

        if (is_null($model_id)) {
            $claves_probadas = implode(', ', $claves);
            $claves_en_body = is_array($body) ? implode(', ', array_keys($body)) : '(el body no es un array/objeto)';

            $mensaje = 'POST '.$endpoint.' respondió '.$status.' pero no devolvió un registro nuevo '.
                '(busqué el id bajo las claves ['.$claves_probadas.'] y no encontré ninguna; '.
                'el body trajo estas claves de primer nivel: ['.$claves_en_body.']).';

            if (!is_null($mensaje_guard)) {
                $mensaje .= ' '.$mensaje_guard;
            }

            $mensaje .= ' Cuerpo completo de la respuesta: '.$contenido;

            $this->fail($mensaje);
        }

        return (int) $model_id;
    }

    /**
     * Borra todo lo que haya creado este trait durante el test (ventas, gastos, cobros de cuenta
     * corriente, transferencias, movimientos de caja, overrides y aperturas), restaura el saldo
     * contable original de cada caja tocada, y devuelve el reloj a su lugar.
     *
     * `DatabaseTransactions` DEBERÍA encargarse de todo esto solo. Este cleanup explícito es la red
     * real mientras el guard de InnoDB de EmpresaTestCase no esté confirmado en verde en la máquina
     * de Lucas (ver el comentario de ese guard).
     *
     * @return void
     */
    protected function limpiar_escenarios()
    {
        $ids_movimientos = array_unique($this->movimientos_caja_creados_por_escenarios);

        if (count($ids_movimientos)) {

            // Los gastos automáticos de comisión (generados por MovimientoCajaHelper::crear_gasto_comision())
            // se borran antes que su movimiento: no hay FK real, pero es el orden lógico correcto.
            $movimientos = MovimientoCaja::whereIn('id', $ids_movimientos)->get();

            foreach ($movimientos as $movimiento) {
                if (!is_null($movimiento->comision_expense_id)) {
                    Expense::where('id', $movimiento->comision_expense_id)->delete();
                }
            }

            MovimientoCaja::whereIn('id', $ids_movimientos)->delete();
        }

        // Cobros de cuenta corriente creados por cobrar_cuenta_corriente().
        if (count($this->cobros_cc_creados_por_escenarios)) {
            CurrentAcount::whereIn('id', $this->cobros_cc_creados_por_escenarios)->delete();
        }

        // Gastos creados directamente por crear_gasto(): se desvincula el pivot de métodos de pago
        // antes de borrar, para no dejar filas huérfanas en expense_current_acount_payment_method.
        foreach ($this->gastos_creados_por_escenarios as $gasto_id) {
            $gasto = Expense::find($gasto_id);

            if (!is_null($gasto)) {
                $gasto->current_acount_payment_methods()->detach();
                $gasto->delete();
            }
        }

        // Ventas creadas por crear_venta_cobrada(): ídem con el pivot de métodos de pago, más los
        // article_purchase que pudiera haber generado (items siempre va vacío en este trait, pero
        // se limpia igual por si algún $overrides futuro agrega artículos).
        foreach ($this->ventas_creadas_por_escenarios as $venta_id) {
            $venta = Sale::find($venta_id);

            if (!is_null($venta)) {
                $venta->current_acount_payment_methods()->detach();
                ArticlePurchase::where('sale_id', $venta_id)->delete();
                $venta->delete();
            }
        }

        // Transferencias entre cajas creadas por transferir_entre_cajas().
        if (count($this->movimientos_entre_caja_creados_por_escenarios)) {
            MovimientoEntreCaja::whereIn('id', $this->movimientos_entre_caja_creados_por_escenarios)->delete();
        }

        // Overrides de liquidación configurados por configurar_override_caja().
        if (count($this->overrides_creados_por_escenarios)) {
            CajaLiquidacionConfig::whereIn('id', $this->overrides_creados_por_escenarios)->delete();
        }

        // Restaura el saldo contable original de cada caja tocada: set_saldos() lo escribe directo
        // sobre cajas.saldo, y borrar los movimiento_cajas de arriba no lo revierte solo.
        foreach ($this->saldos_originales_cajas as $caja_id => $saldo_original) {
            Caja::where('id', $caja_id)->update(['saldo' => $saldo_original]);
        }

        // Cierra (a mano, no por endpoint: es limpieza de infraestructura de test, no el
        // comportamiento bajo prueba) solo las cajas que este trait dejó abiertas, y borra las
        // aperturas que creó, para devolver la caja al mismo estado en que estaba antes del test.
        foreach (array_keys($this->cajas_abiertas_por_escenarios) as $caja_id) {
            Caja::where('id', $caja_id)->update([
                'abierta'                  => 0,
                'abierta_at'                => null,
                'cerrada_at'                => null,
                'current_apertura_caja_id' => null,
            ]);
        }

        if (count($this->aperturas_creadas_por_escenarios)) {
            AperturaCaja::whereIn('id', $this->aperturas_creadas_por_escenarios)->delete();
        }

        // Reset del reloj y de todos los contadores/listas, sin excepción: un setTestNow() que
        // sobrevive al test contamina toda la suite que corra después.
        Carbon::setTestNow(null);

        $this->reloj_base_escenarios = null;
        // Grupo 260 · Prompt 01 — sin este reset, un ultimo_reloj_aplicado viejo podría hacer que
        // avanzar_reloj_de_ventas() del próximo test crea (por coincidencia de timestamp) que el
        // reloj vigente todavía es "suyo" y no re-basee cuando debería.
        $this->ultimo_reloj_aplicado = null;
        $this->segundos_desplazados_escenarios = 0;
        $this->ventas_creadas_por_escenarios = [];
        $this->gastos_creados_por_escenarios = [];
        $this->cobros_cc_creados_por_escenarios = [];
        $this->movimientos_entre_caja_creados_por_escenarios = [];
        $this->movimientos_caja_creados_por_escenarios = [];
        $this->overrides_creados_por_escenarios = [];
        $this->cajas_abiertas_por_escenarios = [];
        $this->aperturas_creadas_por_escenarios = [];
        $this->saldos_originales_cajas = [];
    }
}
