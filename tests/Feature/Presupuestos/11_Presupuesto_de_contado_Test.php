<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\Budget\BudgetCobroHelper;
use App\Http\Controllers\Helpers\Budget\CobroPresupuestoEsquemaHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Pdf\BudgetPdf;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Caja;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión presupuesto-contado-o-cuenta-corriente (1/10/2026): al guardar un presupuesto el vendedor
 * elige si va a la cuenta corriente o si se cobra al confirmar, y en ese caso guarda el reparto de
 * métodos de pago igual que en Vender.
 *
 * Esto levanta PARCIALMENTE la decisión de Lucas del 18/9/2026 ("un presupuesto va SIEMPRE a la
 * cuenta corriente", fijada por `Presupuestos/8_Omitir_cuenta_corriente`): ahora el presupuesto
 * GUARDA el reparto, así que la venta que nace al confirmar ya no queda "de contado" sin método ni
 * caja. El default sigue siendo cuenta corriente, y los tests de este archivo fijan los dos lados:
 * el cobro de contado completo, y todo lo que tiene que seguir igual que hoy.
 *
 * LO QUE SE MIDE:
 *  - de contado: venta omitida, pivote con los métodos, UN movimiento de caja por fila con caja,
 *    cero movimiento de cuenta corriente, comisión, stock descontado;
 *  - el ajuste por método (descuento / recargo) entra en el total que valida el alta;
 *  - las validaciones V1 a V4, al guardar y al confirmar (con rollback completo);
 *  - el PUT con y sin la clave del cobro;
 *  - la compatibilidad hacia atrás (omitir sin reparto, columna null, columna sin migrar);
 *  - el duplicado con ajuste, la anulación, `check_sales` y el PDF.
 *
 * DatabaseTransactions sobre la base sembrada del slot. Las cajas, los métodos y los artículos los
 * crea cada test (nada depende de qué cajas tenga sembradas el slot); los métodos de pago reales
 * (Efectivo, Transferencia, Crédito) salen del catálogo GLOBAL sembrado, por nombre.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Presupuesto_de_contado_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var int Precio del renglón. */
    const PRECIO = 100;

    /** @var int Cantidad del renglón: el total bruto del presupuesto es PRECIO × CANTIDAD = 200. */
    const CANTIDAD = 2;

    /** @var int Stock inicial del artículo, para medir el descuento (y que el rollback lo devuelve). */
    const STOCK = 20;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\PriceType */
    protected $lista;

    /** @var \App\Models\Article */
    protected $article;

    /** @var int Id de "Efectivo" en el catálogo global. */
    protected $efectivo_id;

    /** @var int Id de "Transferencia" en el catálogo global. */
    protected $transferencia_id;

    /** @var int Id de "Credito" en el catálogo global. */
    protected $credito_id;

    protected function setUp(): void
    {
        parent::setUp();

        /*
            La columna la agrega la migración de esta misión. Si la base del slot no la tiene (se
            quedó a mitad de un test de la guarda que se cortó, o falta migrar), se vuelve a poner
            antes de medir nada: los demás tests de este archivo dan por sentado que existe.
        */
        $this->restaurar_columna_si_falta();

        CobroPresupuestoEsquemaHelper::olvidar();

        $this->sembrar_estados();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');

        $this->efectivo_id      = $this->metodo_del_catalogo('Efectivo');
        $this->transferencia_id = $this->metodo_del_catalogo('Transferencia');
        $this->credito_id       = $this->metodo_del_catalogo('Credito');

        $this->lista = PriceType::create([
            'name'     => 'zz Lista (presupuesto de contado)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        $this->article = Article::create([
            'name'        => 'zz Articulo presupuesto de contado',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'costo_real'  => 50,
            'stock'       => self::STOCK,
            'status'      => 'active',
        ]);
    }

    /**
     * El memo de la guarda es estático y vive todo el proceso de PHPUnit: si un test de este
     * archivo lo dejara en `false`, TODOS los que corren después —de esta carpeta y de cualquier
     * otra— verían la columna como inexistente y pasarían sin medir nada. Se limpia en los dos
     * extremos.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        CobroPresupuestoEsquemaHelper::olvidar();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    protected function sembrar_estados()
    {
        $estados = [
            self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            if (is_null(BudgetStatus::find($id))) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * El id de un método del catálogo GLOBAL sembrado (1 Cheque, 3 Efectivo, 4 Transferencia, 5
     * Credito…), buscado por nombre y no escrito a mano. Si el slot no lo tiene, el test se saltea
     * en vez de medir otra cosa.
     *
     * @param  string  $nombre
     * @return int
     */
    protected function metodo_del_catalogo($nombre)
    {
        $metodo = CurrentAcountPaymentMethod::where('name', $nombre)->first();

        if (is_null($metodo)) {
            $this->markTestSkipped('El catalogo de metodos de pago de la base de testing no tiene "'.$nombre.'".');
        }

        return (int) $metodo->id;
    }

    /**
     * Cliente propio del test, con sus cuentas (confirmar a cuenta corriente las necesita) y, si se
     * pide, un vendedor asignado.
     *
     * @param  int|null  $seller_id
     * @return \App\Models\Client
     */
    protected function cliente($seller_id = null)
    {
        $client = Client::create([
            'name'      => 'zz Cliente presupuesto de contado '.uniqid(),
            'user_id'   => self::USER_ID,
            'seller_id' => $seller_id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $client->id, self::USER_ID);

        return $client;
    }

    /**
     * Una caja del dueño, creada por el test y ABIERTA de verdad (con su apertura): el movimiento
     * de caja cuelga de la última apertura, y sin ella `MovimientoCajaHelper` tira una excepción.
     *
     * @param  string  $nombre
     * @return \App\Models\Caja
     */
    protected function caja_abierta($nombre)
    {
        $caja = Caja::create([
            'num'     => (int) Caja::where('user_id', self::USER_ID)->max('num') + 1,
            'name'    => $nombre.' '.uniqid(),
            'user_id' => self::USER_ID,
            'saldo'   => 0,
        ]);

        (new CajaAperturaHelper($caja->id))->abrir_caja();

        return Caja::find($caja->id);
    }

    /**
     * Cierra la caja como lo ve la regla V2 (`cajas.abierta = 0`).
     *
     * @param  \App\Models\Caja  $caja
     * @return void
     */
    protected function cerrar_caja($caja)
    {
        Caja::where('id', $caja->id)->update(['abierta' => 0]);
    }

    /**
     * Una fila del reparto tal como la arma el modal de Vender (las claves que lee el back).
     *
     * @param  int         $metodo_id
     * @param  float       $amount
     * @param  int|null    $caja_id
     * @param  array       $extra     Claves extra: `discount_amount`, `surchage_amount`, `cuota_id`…
     * @return array
     */
    protected function fila($metodo_id, $amount, $caja_id = null, $extra = [])
    {
        return array_merge([
            'current_acount_payment_method_id' => $metodo_id,
            'amount'                           => $amount,
            'amount_cotizado'                  => null,
            'cotizacion'                       => null,
            'moneda_id'                        => 1,
            'cuota_id'                         => null,
            'caja_id'                          => is_null($caja_id) ? 0 : $caja_id,
            'discount_amount'                  => null,
            'surchage_amount'                  => null,
        ], $extra);
    }

    /**
     * El reparto de dos métodos con caja que suma el total bruto (200): 120 en efectivo y 80 por
     * transferencia, cada uno en su caja.
     *
     * @param  \App\Models\Caja  $caja_efectivo
     * @param  \App\Models\Caja  $caja_transferencia
     * @return array
     */
    protected function reparto_dos_metodos($caja_efectivo, $caja_transferencia)
    {
        return [
            $this->fila($this->efectivo_id, 120, $caja_efectivo->id),
            $this->fila($this->transferencia_id, 80, $caja_transferencia->id),
        ];
    }

    /**
     * Payload de POST api/budget con un renglón (molde de Presupuestos/8), tal como lo manda
     * `vender_presupuestos.js::crear()`. SIN cobro: cada test decide `omitir_en_cuenta_corriente`,
     * `selected_payment_methods` y `total`.
     *
     * @param  \App\Models\Client  $client
     * @param  array               $overrides
     * @return array
     */
    protected function payload_crear($client, $overrides = [])
    {
        return array_merge([
            'client_id'                        => $client->id,
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => null,
            'price_type_id'                    => $this->lista->id,
            'sale_status_id'                   => null,
            'discount_stock'                   => 1,
            'iva_aplicado'                     => 1,
            'total'                            => self::PRECIO * self::CANTIDAD,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => null,
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'articles'                         => [
                [
                    'id'                          => $this->article->id,
                    'status'                      => $this->article->status,
                    'cost_in_dollars'             => null,
                    'name'                        => $this->article->name,
                    'name_vender_personalizado'   => null,
                    'amount'                      => self::CANTIDAD,
                    'price'                       => self::PRECIO,
                    'costo_real'                  => 50,
                    'unidades_individuales'       => null,
                    'presentacion'                => null,
                    'price_type_personalizado_id' => null,
                    'bonus'                       => null,
                    'location'                    => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * Payload de PUT api/budget/{id} que conserva los renglones, SIN las claves del cobro: cada test
     * decide si las manda. Es el form genérico del módulo (o una SPA vieja).
     *
     * @param  \App\Models\Budget  $budget
     * @param  array               $overrides
     * @return array
     */
    protected function payload_actualizar($budget, $overrides = [])
    {
        $payload = $this->payload_crear(Client::find($budget->client_id), [
            'observations'     => 'actualizado por el test',
            'total'            => $budget->total,
            'budget_status_id' => $budget->budget_status_id,
        ]);

        return array_merge($payload, $overrides);
    }

    /**
     * Presupuesto DE CONTADO creado por el endpoint, devuelto fresco.
     *
     * @param  \App\Models\Client  $client
     * @param  array               $filas      El reparto.
     * @param  float               $total      El total NETO (con el ajuste adentro).
     * @param  array               $overrides
     * @return \App\Models\Budget
     */
    protected function presupuesto_de_contado($client, $filas, $total = 200, $overrides = [])
    {
        $id = $this->postJson('api/budget', $this->payload_crear($client, array_merge([
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => $filas,
            'total'                      => $total,
        ], $overrides)))->assertStatus(201)->json('model.id');

        return Budget::find($id);
    }

    /**
     * Confirma por el endpoint y devuelve la respuesta (el caller decide qué estado espera).
     *
     * @param  \App\Models\Budget  $budget
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($budget)
    {
        return $this->post('api/budget/'.$budget->id.'/confirmar');
    }

    /**
     * Le da a la cuenta una extensión por slug, creando la fila del catálogo si el slot no la tiene.
     *
     * @param  string  $slug
     * @param  string  $name
     * @return void
     */
    protected function dar_extension($slug, $name)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $name]);
        }

        if (!$this->user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $this->user->extencions()->attach($extencion->id);
        }
    }

    /**
     * Un vendedor del usuario 500 con comisión del 10 % que se cobra en el acto (mismo molde que
     * Presupuestos/7).
     *
     * @return \App\Models\Seller
     */
    protected function vendedor()
    {
        return Seller::create([
            'num'                       => 999002,
            'name'                      => 'zz Vendedor presupuesto de contado '.uniqid(),
            'user_id'                   => self::USER_ID,
            'percentage_commission'     => 10,
            'commission_after_pay_sale' => 0,
            'commission_with_iva'       => 1,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  1. El cobro de contado de punta a punta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 EL CASO COMPLETO. Un presupuesto guardado de contado con dos métodos, cada uno en su caja,
     * al confirmarlo crea una venta que:
     *  - nace con `omitir_en_cuenta_corriente = 1`;
     *  - tiene DOS filas en el pivote de métodos de pago, con sus cajas;
     *  - mueve UNA caja por fila (un movimiento de ingreso por el monto de cada una);
     *  - NO deja ningún movimiento en la cuenta corriente del cliente;
     *  - genera la comisión del vendedor del cliente;
     *  - descuenta el stock (el rollback del test de la caja cerrada lo devuelve).
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_de_contado_confirma_una_venta_cobrada_en_caja()
    {
        $vendedor = $this->vendedor();
        $client = $this->cliente($vendedor->id);
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $budget = $this->presupuesto_de_contado($client, $this->reparto_dos_metodos($caja_a, $caja_b));

        $this->assertSame(1, (int) $budget->omitir_en_cuenta_corriente, 'El alta de contado guarda omitir en 1.');
        $this->assertCount(2, $budget->selected_payment_methods, 'El presupuesto guarda las dos filas del reparto.');

        $movimientos_de_cuenta_antes = CurrentAcount::where('client_id', $client->id)->count();

        $this->confirmar($budget)->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');
        $this->assertSame(1, (int) $sale->omitir_en_cuenta_corriente, 'La venta de un presupuesto de contado nace omitida de la cuenta corriente.');
        $this->assertEquals(200, (float) $sale->total);

        // El pivote: las dos filas, con su caja.
        $pivote = DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->orderBy('id')->get();

        $this->assertCount(2, $pivote, 'La venta tiene los dos metodos de pago del reparto.');
        $this->assertEquals($this->efectivo_id, $pivote[0]->current_acount_payment_method_id);
        $this->assertEquals(120, (float) $pivote[0]->amount);
        $this->assertEquals($caja_a->id, $pivote[0]->caja_id);
        $this->assertEquals($this->transferencia_id, $pivote[1]->current_acount_payment_method_id);
        $this->assertEquals(80, (float) $pivote[1]->amount);
        $this->assertEquals($caja_b->id, $pivote[1]->caja_id);

        // La caja: UN movimiento por fila con caja, por el monto de esa fila, en la caja de esa fila.
        $movimientos = MovimientoCaja::where('sale_id', $sale->id)->orderBy('id')->get();

        $this->assertCount(2, $movimientos, 'Un movimiento de caja por cada fila del reparto que tiene caja.');
        $this->assertEquals($caja_a->id, $movimientos[0]->caja_id);
        $this->assertEquals(120, (float) $movimientos[0]->ingreso);
        $this->assertEquals($caja_b->id, $movimientos[1]->caja_id);
        $this->assertEquals(80, (float) $movimientos[1]->ingreso);

        // La cuenta corriente: ni un movimiento.
        $this->assertFalse(CurrentAcount::where('sale_id', $sale->id)->exists(), 'Una venta de contado no entra a la cuenta corriente.');
        $this->assertEquals(
            $movimientos_de_cuenta_antes,
            CurrentAcount::where('client_id', $client->id)->count(),
            'La cuenta corriente del cliente no se movio.'
        );

        // La comisión del vendedor del cliente (el mismo molde de Presupuestos/7).
        $comision = SellerCommission::where('sale_id', $sale->id)->first();

        $this->assertNotNull($comision, 'La venta de contado genera la comision del vendedor.');
        $this->assertEquals($vendedor->id, (int) $comision->seller_id);
        $this->assertEquals(
            self::PRECIO * self::CANTIDAD * 10 / 100,
            (float) $comision->debe,
            'La comision es el 10 % del total de la venta (200), no alcanza con que exista.'
        );

        // El stock: la venta descuenta como cualquier otra.
        $this->assertEquals(self::STOCK - self::CANTIDAD, (float) Article::find($this->article->id)->stock, 'Confirmar descuenta el stock.');
    }

    /**
     * Una fila SIN caja (`caja_id` 0) también es de contado: el método se adjunta, pero no hay
     * movimiento de caja para esa fila. Es lo que pasa en Vender cuando se elige un método sin caja.
     *
     * @group presupuestos
     * @test
     */
    public function una_fila_sin_caja_adjunta_el_metodo_y_no_mueve_ninguna_caja()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [
            $this->fila($this->efectivo_id, 150, $caja->id),
            $this->fila($this->transferencia_id, 50, 0),
        ]);

        $this->confirmar($budget)->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertEquals(2, DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->count());
        $this->assertEquals(1, MovimientoCaja::where('sale_id', $sale->id)->count(), 'Solo la fila con caja mueve plata.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  2. El ajuste por método de pago entra en el total
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 DESCUENTO por transferencia: el bruto es 200, la transferencia descuenta 10 y el total del
     * presupuesto es 190. Sin el ajuste dentro de `getTotal()`, el alta moriría con "El total del
     * presupuesto no corresponde" (la diferencia, 10, pasa el margen de 3). Y `getTotal()` coincide
     * con el total guardado, que es lo que usan el PDF y la validación.
     *
     * @group presupuestos
     * @test
     */
    public function un_descuento_por_metodo_de_pago_entra_en_el_total_y_el_alta_lo_acepta()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $budget = $this->presupuesto_de_contado($client, [
            $this->fila($this->efectivo_id, 100, $caja_a->id),
            $this->fila($this->transferencia_id, 90, $caja_b->id, ['discount_amount' => 10]),
        ], 190);

        $this->assertEquals(190, (float) $budget->total);
        $this->assertEquals(-10, BudgetCobroHelper::ajuste_por_metodos_de_pago($budget), 'El ajuste se deriva de las filas: el descuento resta.');
        $this->assertEquals(190, BudgetHelper::getTotal($budget), 'getTotal() incluye el ajuste y coincide con el total guardado.');

        $this->confirmar($budget)->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertEquals(190, (float) $sale->total, 'La venta nace con el total NETO.');

        // La caja recibe el NETO de cada fila: la fila con descuento mueve 90, no 100.
        $movimientos = MovimientoCaja::where('sale_id', $sale->id)->orderBy('id')->get();

        $this->assertCount(2, $movimientos);
        $this->assertEquals($caja_a->id, $movimientos[0]->caja_id);
        $this->assertEquals(100, (float) $movimientos[0]->ingreso, 'El efectivo mueve sus 100.');
        $this->assertEquals($caja_b->id, $movimientos[1]->caja_id);
        $this->assertEquals(90, (float) $movimientos[1]->ingreso, 'La transferencia con descuento mueve el neto (90), no el bruto.');

        // Y lo que entra a las cajas suma EXACTAMENTE lo que la venta cobra: el descuento no se pierde ni se duplica.
        $this->assertEquals((float) $sale->total, (float) $movimientos->sum('ingreso'), 'Los movimientos de caja suman el total de la venta.');
        $this->assertEquals(
            (float) $sale->total,
            (float) DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->sum('amount'),
            'Y los metodos de pago del pivote tambien.'
        );
    }

    /**
     * RECARGO por cuotas: el bruto es 200, el crédito suma 20 y el total es 220.
     *
     * @group presupuestos
     * @test
     */
    public function un_recargo_por_metodo_de_pago_entra_en_el_total_y_el_alta_lo_acepta()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [
            $this->fila($this->efectivo_id, 100, $caja->id),
            $this->fila($this->credito_id, 120, $caja->id, ['surchage_amount' => 20]),
        ], 220);

        $this->assertEquals(220, (float) $budget->total);
        $this->assertEquals(20, BudgetCobroHelper::ajuste_por_metodos_de_pago($budget));
        $this->assertEquals(220, BudgetHelper::getTotal($budget));
    }

    /**
     * El ajuste se suma SOLO si el presupuesto es de contado: un presupuesto a cuenta corriente con
     * filas colgadas en la columna (un dato viejo, o escrito a mano) no puede inflarse el total.
     *
     * @group presupuestos
     * @test
     */
    public function el_ajuste_es_cero_si_el_presupuesto_no_es_de_contado()
    {
        $client = $this->cliente();

        $id = $this->postJson('api/budget', $this->payload_crear($client))->assertStatus(201)->json('model.id');

        // Filas colgadas, pero omitir en 0: cuenta corriente.
        Budget::where('id', $id)->update([
            'selected_payment_methods' => json_encode([$this->fila($this->transferencia_id, 190, 0, ['discount_amount' => 10])]),
        ]);

        $budget = Budget::find($id);

        $this->assertEquals(0, BudgetCobroHelper::ajuste_por_metodos_de_pago($budget));
        $this->assertEquals(200, BudgetHelper::getTotal($budget));
    }

    /**
     * `BudgetHelper::getTotal()` lo llaman también `OrderProductionPdf` y `Pdf/__base.php` con
     * modelos que NO son presupuestos y nunca van a tener `omitir_en_cuenta_corriente` ni
     * `selected_payment_methods`. Sobre ellos el ajuste es 0, sin leer atributos que no existen ni
     * hacer una sola consulta de esquema (mismo cuidado que `SaleHelper::get_forzar_total_monto()`).
     *
     * @group presupuestos
     * @test
     */
    public function el_ajuste_tolera_modelos_que_no_son_presupuestos()
    {
        $orden_de_produccion = new \App\Models\OrderProduction();

        $this->assertFalse(BudgetCobroHelper::es_de_contado($orden_de_produccion));
        $this->assertEquals(0, BudgetCobroHelper::ajuste_por_metodos_de_pago($orden_de_produccion));
        $this->assertSame([], BudgetCobroHelper::filas($orden_de_produccion));

        $this->assertFalse(BudgetCobroHelper::es_de_contado(null));
        $this->assertFalse(BudgetCobroHelper::es_de_contado(new \stdClass()));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  3. Validaciones al guardar (422 antes de la transacción)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * V3: el reparto tiene que sumar el total del presupuesto.
     *
     * @group presupuestos
     * @test
     */
    public function un_reparto_que_no_suma_el_total_se_rechaza_con_422()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $presupuestos_antes = Budget::where('user_id', self::USER_ID)->count();

        $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'total'                      => 200,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 150, $caja->id)],
        ]));

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('cobro_invalido'));
        $this->assertStringContainsString('no coincide con el total', $respuesta->json('message'));
        $this->assertEquals($presupuestos_antes, Budget::where('user_id', self::USER_ID)->count(), 'Un rechazo no deja nada guardado.');
    }

    /**
     * V3 tiene tolerancia de centavos (el modal de la SPA redondea por fila): 5 centavos de
     * diferencia pasan, 6 no.
     *
     * @group presupuestos
     * @test
     */
    public function el_reparto_tolera_hasta_cinco_centavos_de_diferencia()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 199.95, $caja->id)],
        ]))->assertStatus(201);

        $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 199.94, $caja->id)],
        ]))->assertStatus(422);
    }

    /**
     * V1: sin ningún método de pago real (el 0 del placeholder, o un id que no existe) → 422 con
     * `sin_metodo_de_pago`, el mismo de la venta de Vender.
     *
     * @group presupuestos
     * @test
     */
    public function un_reparto_sin_metodo_de_pago_valido_se_rechaza_con_sin_metodo_de_pago()
    {
        $client = $this->cliente();

        foreach ([0, 999999999] as $metodo_invalido) {

            $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
                'omitir_en_cuenta_corriente' => 1,
                'selected_payment_methods'   => [$this->fila($metodo_invalido, 200, 0)],
            ]));

            $respuesta->assertStatus(422);
            $this->assertTrue($respuesta->json('sin_metodo_de_pago'), 'El rechazo trae la marca sin_metodo_de_pago.');
            $this->assertTrue($respuesta->json('cobro_invalido'));
        }
    }

    /**
     * V1, segunda mitad: con DOS filas y una apuntando a un método que no existe, el reparto
     * "tiene un método válido" pero la venta nacería cobrada por la mitad. Se rechaza igual.
     *
     * @group presupuestos
     * @test
     */
    public function una_fila_con_plata_apuntando_a_un_metodo_inexistente_se_rechaza()
    {
        $client = $this->cliente();

        $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [
                $this->fila($this->efectivo_id, 120, 0),
                $this->fila(999999999, 80, 0),
            ],
        ]))->assertStatus(422)->assertJson(['sin_metodo_de_pago' => true]);
    }

    /**
     * V2: una caja cerrada al guardar → 422 con `caja_cerrada` y el nombre de la caja.
     *
     * @group presupuestos
     * @test
     */
    public function una_caja_cerrada_al_guardar_se_rechaza_con_422()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja que se cierra');
        $this->cerrar_caja($caja);

        $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, $caja->id)],
        ]));

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('caja_cerrada'));
        $this->assertTrue($respuesta->json('cobro_invalido'));
        $this->assertStringContainsString('está cerrada', $respuesta->json('message'));
        $this->assertStringContainsString($caja->name, $respuesta->json('message'));
    }

    /**
     * V2: una caja que no existe → 422 (no un 500 más adelante).
     *
     * @group presupuestos
     * @test
     */
    public function una_caja_inexistente_se_rechaza_con_422()
    {
        $this->postJson('api/budget', $this->payload_crear($this->cliente(), [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, 999999999)],
        ]))->assertStatus(422)->assertJson(['cobro_invalido' => true]);
    }

    /**
     * V4: una fila que endosa un cheque recibido (`cheque_id`) no se puede cobrar en una venta:
     * reventaría al confirmar. Se rechaza al guardar.
     *
     * @group presupuestos
     * @test
     */
    public function una_fila_que_endosa_un_cheque_se_rechaza_con_422()
    {
        $this->postJson('api/budget', $this->payload_crear($this->cliente(), [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, 0, ['cheque_id' => 12345])],
        ]))->assertStatus(422)->assertJson(['cobro_invalido' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  4. Re-validación al confirmar (422 con rollback)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 LA CAJA SE CERRÓ ENTRE EL GUARDADO Y LA CONFIRMACIÓN. El presupuesto se guardó con la caja
     * abierta; días después alguien cierra la caja y se confirma desde el listado. Sin la
     * re-validación, `MovimientoCajaHelper` metería el ingreso en la última apertura de una caja ya
     * cerrada. Con ella: 422 `caja_cerrada` y ROLLBACK COMPLETO — sin venta, sin stock descontado y
     * el presupuesto sigue sin confirmar (el `save()` del estado ya había corrido).
     *
     * @group presupuestos
     * @test
     */
    public function una_caja_cerrada_al_confirmar_da_422_y_no_deja_nada_a_medias()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $budget = $this->presupuesto_de_contado($client, $this->reparto_dos_metodos($caja_a, $caja_b));

        $this->cerrar_caja($caja_b);

        $movimientos_antes = MovimientoCaja::count();

        $respuesta = $this->confirmar($budget);

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('caja_cerrada'));
        $this->assertTrue($respuesta->json('cobro_invalido'));

        /*
            ⚠️ QUE PRUEBA CADA ASERCION, para que nadie lea de mas:

            - "No se creo la venta", "no se desconto el stock" y "ninguna caja se movio" prueban que la
              RE-VALIDACION corre ANTES de crear nada (`saveSale()` la hace primero): no habia nada que
              revertir, simplemente no se llego a crear. NO prueban el rollback.
            - El estado "sin confirmar" SI prueba un rollback, pero solo uno: `confirmar()` hace
              `$model->save()` del estado 2 ANTES de llamar a `saveSale()`, asi que esa escritura es la
              unica que el `DB::rollBack()` del catch tiene que deshacer.

            La prueba del rollback REAL --venta creada, stock descontado y un movimiento de caja ya
            escrito, y recien despues un fallo-- son los tests de la seccion "Atomicidad".
        */
        $this->assertFalse(Sale::where('budget_id', $budget->id)->exists(), 'No se creo la venta (la validacion corrio antes de crearla).');
        $this->assertEquals(self::ESTADO_SIN_CONFIRMAR, (int) Budget::find($budget->id)->budget_status_id, 'El presupuesto sigue sin confirmar: el rollback deshizo el save() del estado.');
        $this->assertEquals(self::STOCK, (float) Article::find($this->article->id)->stock, 'No se desconto el stock (no se llego a crear la venta).');
        $this->assertEquals($movimientos_antes, MovimientoCaja::count(), 'Ninguna caja se movio, ni siquiera la que estaba abierta.');
    }

    /**
     * El método de pago se borró entre el guardado y la confirmación → 422 `sin_metodo_de_pago`,
     * con el mismo rollback.
     *
     * @group presupuestos
     * @test
     */
    public function un_metodo_borrado_antes_de_confirmar_da_422()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $metodo_temporal = CurrentAcountPaymentMethod::create(['name' => 'zz Metodo temporal contado']);

        $budget = $this->presupuesto_de_contado($client, [$this->fila($metodo_temporal->id, 200, $caja->id)]);

        $metodo_temporal->delete();

        $respuesta = $this->confirmar($budget);

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('sin_metodo_de_pago'));
        $this->assertFalse(Sale::where('budget_id', $budget->id)->exists());
        $this->assertEquals(self::ESTADO_SIN_CONFIRMAR, (int) Budget::find($budget->id)->budget_status_id);
        $this->assertEquals(self::STOCK, (float) Article::find($this->article->id)->stock);
    }

    /**
     * El presupuesto se editó por otro camino (el form genérico) y su reparto guardado quedó con un
     * total viejo: al confirmar, V3 lo atrapa. El vendedor lo vuelve a repartir desde Vender.
     *
     * @group presupuestos
     * @test
     */
    public function un_reparto_viejo_que_ya_no_suma_el_total_da_422_al_confirmar()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        // El total cambio por afuera (otro camino) y el reparto guardado quedo en 200.
        Budget::where('id', $budget->id)->update(['total' => 250]);

        $respuesta = $this->confirmar(Budget::find($budget->id));

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no coincide con el total', $respuesta->json('message'));
        $this->assertFalse(Sale::where('budget_id', $budget->id)->exists());
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  5. La edición (PUT)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Una SPA VIEJA (o un cliente HTTP que arma el PUT a mano) edita un campo cualquiera y NO
     * manda las claves del cobro: el cobro guardado se PRESERVA. Si no, cambiar una observación
     * pasaría a cuenta corriente un presupuesto cobrado de contado y el reparto se perdería en
     * silencio.
     *
     * ⚠️ NO es el caso del form genérico del módulo Presupuestos: ese manda el modelo ENTERO en cada
     * PUT (`getModelToSend()` hace `{...this.model}`), o sea que las claves del cobro viajan SIEMPRE,
     * con el cobro que ya estaba. Ese caso lo miden los tests de "la forma del modelo recargado".
     *
     * @group presupuestos
     * @test
     */
    public function un_put_sin_la_clave_del_cobro_conserva_el_cobro_guardado()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget))->assertStatus(200);

        $recargado = Budget::find($budget->id);

        $this->assertSame('actualizado por el test', $recargado->observations);
        $this->assertSame(1, (int) $recargado->omitir_en_cuenta_corriente, 'Un PUT sin la clave no pasa el presupuesto a cuenta corriente.');
        $this->assertCount(1, $recargado->selected_payment_methods, 'Un PUT sin la clave no pierde el reparto.');
        $this->assertEquals(200, (float) $recargado->selected_payment_methods[0]['amount']);
    }

    /**
     * El PUT con las claves del cobro las REEMPLAZA: el vendedor re-reparte desde "Actualizar en
     * VENDER". Se valida igual que el alta.
     *
     * @group presupuestos
     * @test
     */
    public function un_put_con_el_cobro_reemplaza_el_reparto_y_lo_valida()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        // Un reparto nuevo que NO suma: 422 y el guardado queda como estaba.
        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 100, $caja->id)],
        ]))->assertStatus(422)->assertJson(['cobro_invalido' => true]);

        $this->assertEquals(200, (float) Budget::find($budget->id)->selected_payment_methods[0]['amount']);

        // Un reparto nuevo que suma: se reemplaza.
        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [
                $this->fila($this->efectivo_id, 50, $caja->id),
                $this->fila($this->transferencia_id, 150, $caja->id),
            ],
        ]))->assertStatus(200);

        $this->assertCount(2, Budget::find($budget->id)->selected_payment_methods);
    }

    /**
     * Un PUT con `omitir_en_cuenta_corriente = 0` pasa el presupuesto a cuenta corriente y le vacía
     * el reparto (el cartel de la SPA contestó "Sí, a la cuenta corriente"; o una SPA vieja).
     *
     * @group presupuestos
     * @test
     */
    public function un_put_con_omitir_en_cero_lo_pasa_a_cuenta_corriente_y_vacia_el_reparto()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, [
            'omitir_en_cuenta_corriente' => 0,
            'selected_payment_methods'   => [],
        ]))->assertStatus(200);

        $recargado = Budget::find($budget->id);

        $this->assertSame(0, (int) $recargado->omitir_en_cuenta_corriente);
        $this->assertNull($recargado->selected_payment_methods, 'Cuenta corriente = sin reparto.');
        $this->assertFalse(BudgetCobroHelper::es_de_contado($recargado));
    }

    /**
     * Un PUT que CONFIRMA un presupuesto de contado, sin las claves del cobro y con un reparto que
     * ya no suma los renglones nuevos, responde 422 (no 500) y revierte todo: los renglones y los
     * campos quedan como estaban.
     *
     * @group presupuestos
     * @test
     */
    public function un_put_que_confirma_con_un_reparto_viejo_da_422_y_revierte()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        // El PUT sube el total a 300 y confirma, sin hablar del cobro: el reparto guardado (200) queda viejo.
        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, [
            'total'            => 300,
            'budget_status_id' => self::ESTADO_CONFIRMADO,
            'observations'     => 'no tiene que quedar',
        ]))->assertStatus(422)->assertJson(['cobro_invalido' => true]);

        $recargado = Budget::find($budget->id);

        $this->assertEquals(self::ESTADO_SIN_CONFIRMAR, (int) $recargado->budget_status_id);
        $this->assertEquals(200, (float) $recargado->total, 'El rollback devolvio el total.');
        $this->assertNotSame('no tiene que quedar', $recargado->observations);
        $this->assertFalse(Sale::where('budget_id', $budget->id)->exists());
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  6. Compatibilidad hacia atrás: lo que NO es de contado sigue igual
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Una SPA vieja que arrastra el toggle del store de Vender manda `omitir_en_cuenta_corriente`
     * en 1 SIN reparto: es cuenta corriente, como desde el 18/9/2026. Sin la condición "al menos una
     * fila", esa SPA crearía una venta de contado sin cobro, que es lo que causó la decisión. Con
     * `selected_payment_methods: []` (la SPA nueva en cuenta corriente) también.
     *
     * @group presupuestos
     * @test
     */
    public function omitir_en_uno_sin_reparto_es_cuenta_corriente()
    {
        $client = $this->cliente();

        foreach ([null, []] as $reparto) {

            $payload = $this->payload_crear($client, ['omitir_en_cuenta_corriente' => 1]);

            if (!is_null($reparto)) {
                $payload['selected_payment_methods'] = $reparto;
            }

            $id = $this->postJson('api/budget', $payload)->assertStatus(201)->json('model.id');

            $budget = Budget::find($id);

            $this->assertSame(0, (int) $budget->omitir_en_cuenta_corriente);
            $this->assertNull($budget->selected_payment_methods);
            $this->assertFalse(BudgetCobroHelper::es_de_contado($budget));
        }

        // Y confirmarlo sigue yendo a la cuenta corriente, con su movimiento.
        $this->confirmar($budget)->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertSame(0, (int) $sale->omitir_en_cuenta_corriente);
        $this->assertTrue(CurrentAcount::where('sale_id', $sale->id)->exists());
        $this->assertSame(0, $sale->current_acount_payment_methods()->count());
    }

    /**
     * La tabla de verdad de la regla: omitir verdadero Y al menos una fila.
     *
     * @group presupuestos
     * @test
     */
    public function la_regla_de_contado_exige_omitir_y_al_menos_una_fila()
    {
        $filas = [$this->fila($this->efectivo_id, 200, 0)];

        $casos = [
            // omitir          filas     de contado
            [1,                $filas,   true],
            ['1',              $filas,   true],
            [true,             $filas,   true],
            [1,                [],       false],
            [1,                null,     false],
            [0,                $filas,   false],
            ['0',              $filas,   false],
            [null,             $filas,   false],
            [false,            $filas,   false],
        ];

        foreach ($casos as $caso) {

            $budget = new Budget();
            $budget->omitir_en_cuenta_corriente = $caso[0];
            $budget->selected_payment_methods = $caso[1];

            $this->assertSame($caso[2], BudgetCobroHelper::es_de_contado($budget), 'es_de_contado con omitir='.var_export($caso[0], true).' y '.count((array) $caso[1]).' filas.');
        }
    }

    /**
     * Un presupuesto VIEJO (columna null, omitir en 0) confirma exactamente como antes: la venta a la
     * cuenta corriente, con su movimiento, sin métodos de pago ni movimiento de caja. Y con un 1
     * suelto escrito a mano (el residuo de la tanda 2 del 18/9) tampoco cambia nada.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_viejo_confirma_a_la_cuenta_corriente_como_siempre()
    {
        foreach ([0, 1] as $omitir_viejo) {

            $client = $this->cliente();

            $id = $this->postJson('api/budget', $this->payload_crear($client))->assertStatus(201)->json('model.id');

            Budget::where('id', $id)->update(['omitir_en_cuenta_corriente' => $omitir_viejo, 'selected_payment_methods' => null]);

            $movimientos_de_caja_antes = MovimientoCaja::count();

            $this->confirmar(Budget::find($id))->assertStatus(200);

            $sale = Sale::where('budget_id', $id)->first();

            $this->assertSame(0, (int) $sale->omitir_en_cuenta_corriente, 'omitir viejo '.$omitir_viejo.': la venta nace en cuenta corriente.');
            $this->assertTrue(CurrentAcount::where('sale_id', $sale->id)->exists(), 'Deja su movimiento en la cuenta corriente.');
            $this->assertSame(0, $sale->current_acount_payment_methods()->count());
            $this->assertEquals($movimientos_de_caja_antes, MovimientoCaja::count(), 'No mueve ninguna caja.');
        }
    }

    /**
     * Con `check_sales`, `saveSale()` crea la venta `to_check` y se saltea cuenta corriente,
     * comisión y caja. Para un presupuesto de contado eso queda así:
     *  - los métodos de pago SE ADJUNTAN (igual que `SaleController::store()` con una venta
     *    chequeada);
     *  - NO se mueve ninguna caja.
     *
     * 🔴 Esto último NO es una decisión de la misión sino un hueco PREEXISTENTE de Vender, medido el
     * 1/10/2026: `SaleCajaHelper::check_caja()` se llama desde un solo lugar
     * (`SaleHelper::attachProperies()` con `$from_store && !to_check && !checked`) y la confirmación
     * de una venta chequeada entra por `SaleController::update()`, que pasa `$from_store = false`.
     * Hoy una venta de contado chequeada no mueve nunca la caja, ni en Vender ni acá. Este test lo
     * deja escrito; si algún día Vender lo registra al confirmar la venta chequeada, el presupuesto
     * tiene que seguirlo y este test se actualiza con él.
     *
     * @group presupuestos
     * @test
     */
    public function con_check_sales_adjunta_los_metodos_y_no_mueve_la_caja_igual_que_vender()
    {
        $this->dar_extension('check_sales', 'Chequear ventas');

        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        $movimientos_antes = MovimientoCaja::count();

        $this->confirmar($budget)->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertSame(1, (int) $sale->to_check, 'Con check_sales la venta nace a chequear.');
        $this->assertSame(1, (int) $sale->omitir_en_cuenta_corriente);
        $this->assertEquals(1, DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->count(), 'Los metodos se adjuntan.');
        $this->assertEquals($movimientos_antes, MovimientoCaja::count(), 'Como en Vender, una venta chequeada no mueve la caja en el acto.');
        $this->assertFalse(CurrentAcount::where('sale_id', $sale->id)->exists());
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  7. Duplicar y anular
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Duplicar un presupuesto de contado con un ajuste mayor a 3 no revienta, y el duplicado nace
     * a CUENTA CORRIENTE, sin reparto y con el total BRUTO. El `total` del origen es neto (190); si
     * se copiara tal cual, `BudgetController::duplicate()` compararía 190 contra el `getTotal()` del
     * duplicado (200, sin ajuste) y moriría con el 500 "El total del presupuesto no corresponde".
     *
     * @group presupuestos
     * @test
     */
    public function duplicar_un_presupuesto_de_contado_con_ajuste_nace_en_cuenta_corriente_con_el_total_bruto()
    {
        $this->dar_extension('duplicar_presupuestos', 'Duplicar presupuestos');

        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $origen = $this->presupuesto_de_contado($client, [
            $this->fila($this->efectivo_id, 100, $caja->id),
            $this->fila($this->transferencia_id, 90, $caja->id, ['discount_amount' => 10]),
        ], 190);

        $respuesta = $this->post('api/budget/'.$origen->id.'/duplicate')->assertStatus(201);

        $duplicado = Budget::find($respuesta->json('model.id'));

        $this->assertNotEquals($origen->id, $duplicado->id);
        $this->assertSame(0, (int) $duplicado->omitir_en_cuenta_corriente, 'El duplicado nace a cuenta corriente.');
        $this->assertNull($duplicado->selected_payment_methods, 'Y sin reparto.');
        $this->assertEquals(200, (float) $duplicado->total, 'El total del duplicado es el bruto (sin el ajuste del origen).');
        $this->assertEquals(200, BudgetHelper::getTotal($duplicado));

        // El origen no se toco.
        $this->assertEquals(190, (float) Budget::find($origen->id)->total);
        $this->assertCount(2, Budget::find($origen->id)->selected_payment_methods);
    }

    /**
     * Anular un presupuesto cobrado en caja → 422, igual que una venta cobrada en caja (decisión de
     * Lucas, 1/10/2026): `anular()` no se tocó, lo frena `motivo_por_el_que_no_se_puede_editar()`
     * porque la venta tiene métodos de pago y el comercio tiene cajas. El presupuesto sigue
     * confirmado y la venta, viva, con su plata en la caja.
     *
     * @group presupuestos
     * @test
     */
    public function anular_un_presupuesto_cobrado_en_caja_se_rechaza_con_422()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        $this->confirmar($budget)->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->post('api/budget/'.$budget->id.'/anular')->assertStatus(422);

        $this->assertEquals(self::ESTADO_CONFIRMADO, (int) Budget::find($budget->id)->budget_status_id, 'Sigue confirmado.');
        $this->assertNotNull(Sale::find($sale->id), 'La venta sigue viva.');
        $this->assertEquals(1, MovimientoCaja::where('sale_id', $sale->id)->count(), 'Y su plata sigue en la caja.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  8. El presupuesto que NACE confirmado y el modelo que vuelve
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un presupuesto que nace en estado "Confirmado" (la SPA vieja, o el alta con el estado ya
     * elegido) con un cobro de contado crea la venta adentro de la transacción del alta. La
     * re-validación corre ahí también: con la caja abierta, la venta se cobra.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_que_nace_confirmado_de_contado_crea_la_venta_cobrada()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)], 200, [
            'budget_status_id' => self::ESTADO_CONFIRMADO,
        ]);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale);
        $this->assertSame(1, (int) $sale->omitir_en_cuenta_corriente);
        $this->assertEquals(1, MovimientoCaja::where('sale_id', $sale->id)->count());
    }

    /**
     * El modelo que vuelve del alta y del GET trae `omitir_en_cuenta_corriente` y
     * `selected_payment_methods` (la SPA los usa para restaurar el cobro al reabrir y para detectar
     * una API vieja que ignoró el cobro).
     *
     * @group presupuestos
     * @test
     */
    public function la_respuesta_trae_el_cobro_guardado()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, $caja->id)],
        ]))->assertStatus(201);

        $this->assertEquals(1, $respuesta->json('model.omitir_en_cuenta_corriente'));
        $this->assertCount(1, $respuesta->json('model.selected_payment_methods'));
        $this->assertEquals($this->efectivo_id, $respuesta->json('model.selected_payment_methods.0.current_acount_payment_method_id'));

        $this->getJson('api/budget/'.$respuesta->json('model.id'))
            ->assertStatus(200)
            ->assertJsonPath('model.selected_payment_methods.0.amount', 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Montos y cajas mal formados (tanda de correcciones del 1/10/2026)
    // ─────────────────────────────────────────────────────────────────────────

    /** Un dueño que NO es el del test: ninguna caja suya sirve para cobrar un presupuesto del 500. */
    const OTRO_DUENO_ID = 999999;

    /**
     * Una caja abierta del dueño que se pide (por defecto, otro comercio), SIN ninguna apertura: la
     * regla V2 solo mira `cajas.abierta`, así que la acepta, pero `MovimientoCajaHelper` no tiene
     * una apertura donde colgar el movimiento y tira una excepción. Es la forma de provocar un fallo
     * REAL después de que `saveSale()` ya creó la venta y descontó el stock.
     *
     * @param  string  $nombre
     * @param  int     $user_id
     * @return \App\Models\Caja
     */
    protected function caja_abierta_sin_apertura($nombre, $user_id = self::USER_ID)
    {
        return Caja::create([
            'num'     => (int) Caja::where('user_id', $user_id)->max('num') + 1,
            'name'    => $nombre.' '.uniqid(),
            'user_id' => $user_id,
            'saldo'   => 0,
            'abierta' => 1,
        ]);
    }

    /**
     * El presupuesto TAL COMO lo devuelve el listado/`show`: es lo que el form genérico del módulo
     * Presupuestos reenvía entero en cada PUT (`getModelToSend()` hace `{...this.model}`).
     *
     * @param  \App\Models\Budget  $budget
     * @return array
     */
    protected function modelo_recargado($budget)
    {
        return $this->getJson('api/budget/'.$budget->id)->assertStatus(200)->json('model');
    }

    /**
     * 🔴 EL CASO MEDIDO. Total 200, reparto Efectivo 250 + Transferencia −50: suma 200, así que V3
     * solo no lo ve, y antes llegaba a la caja B como un ingreso NEGATIVO de −50. Un monto negativo
     * se rechaza al guardar, y no deja ni presupuesto ni movimientos.
     *
     * @group presupuestos
     * @test
     */
    public function un_monto_negativo_se_rechaza_con_422_y_no_llega_a_la_caja()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $presupuestos_antes = Budget::where('user_id', self::USER_ID)->count();
        $movimientos_antes  = MovimientoCaja::count();

        $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'total'                      => 200,
            'selected_payment_methods'   => [
                $this->fila($this->efectivo_id, 250, $caja_a->id),
                $this->fila($this->transferencia_id, -50, $caja_b->id),
            ],
        ]));

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('cobro_invalido'));
        $this->assertSame('Los montos de los métodos de pago no pueden ser negativos.', $respuesta->json('message'));
        $this->assertEquals($presupuestos_antes, Budget::where('user_id', self::USER_ID)->count(), 'No quedo ningun presupuesto.');
        $this->assertEquals($movimientos_antes, MovimientoCaja::count(), 'Y ninguna caja se movio.');

        // Tambien el `amount_cotizado` (la plata en pesos de una fila en otra moneda).
        $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, $caja_a->id, ['amount_cotizado' => -5])],
        ]))->assertStatus(422)->assertJson(['message' => 'Los montos de los métodos de pago no pueden ser negativos.']);
    }

    /**
     * Un monto que no es un número (`'abc'`, un booleano, un array) pasaba V3 —se sumaba como 0— y
     * reventaba recién al confirmar con un 500 de SQL crudo ("1366 Incorrect decimal value"). Ahora
     * se rechaza al guardar, también en `amount_cotizado`.
     *
     * @group presupuestos
     * @test
     */
    public function un_monto_que_no_es_un_numero_se_rechaza_con_422()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $casos = [
            ['amount' => 'abc'],
            ['amount' => true],
            ['amount' => [1]],
            ['amount_cotizado' => 'abc'],
        ];

        foreach ($casos as $extra) {

            $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
                'omitir_en_cuenta_corriente' => 1,
                'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, $caja->id, $extra)],
            ]));

            $respuesta->assertStatus(422);
            $this->assertTrue($respuesta->json('cobro_invalido'), 'Caso '.json_encode($extra));
            $this->assertSame('Hay un método de pago con un monto que no es un número.', $respuesta->json('message'), 'Caso '.json_encode($extra));
        }
    }

    /**
     * 🔴 UNA FILA SIN MONTO NO SE GUARDA. El modal de Vender agrega por defecto una fila (método 3,
     * monto ''), y un reparto con un método dejado en 0 manda otra: con caja, cada una creaba un
     * movimiento de $ 0. Se descartan al normalizar —null, '', 0 y "0.00"—: la columna guarda solo
     * la fila que cobra, la venta tiene un método y la caja de las filas descartadas no se mueve.
     *
     * @group presupuestos
     * @test
     */
    public function las_filas_sin_monto_se_descartan_y_no_llegan_a_la_caja()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $budget = $this->presupuesto_de_contado($client, [
            $this->fila($this->efectivo_id, 200, $caja_a->id),
            $this->fila($this->transferencia_id, 0, $caja_b->id),
            $this->fila($this->efectivo_id, '', $caja_b->id),
            $this->fila($this->credito_id, null, $caja_b->id),
            $this->fila($this->efectivo_id, '0.00', $caja_b->id),
        ]);

        $this->assertCount(1, $budget->selected_payment_methods, 'Solo la fila que cobra se guarda.');
        $this->assertEquals(200, (float) $budget->selected_payment_methods[0]['amount']);

        $this->confirmar($budget)->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertEquals(1, DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->count(), 'La venta tiene UN metodo de pago.');

        $movimientos = MovimientoCaja::where('sale_id', $sale->id)->get();

        $this->assertCount(1, $movimientos, 'Y UN movimiento de caja: ninguno de $ 0.');
        $this->assertEquals($caja_a->id, $movimientos[0]->caja_id);
        $this->assertEquals(200, (float) $movimientos[0]->ingreso);
        $this->assertEquals(0, MovimientoCaja::where('caja_id', $caja_b->id)->count(), 'La caja B no recibio nada.');
    }

    /**
     * Si tras descartar las filas sin monto no queda ninguna, el 422 es el de siempre:
     * `sin_metodo_de_pago`. No cae a cuenta corriente en silencio: el vendedor pidió cobrar de contado.
     *
     * @group presupuestos
     * @test
     */
    public function si_todas_las_filas_estan_en_cero_se_rechaza_con_sin_metodo_de_pago()
    {
        $respuesta = $this->postJson('api/budget', $this->payload_crear($this->cliente(), [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [
                $this->fila($this->efectivo_id, 0, 0),
                $this->fila($this->transferencia_id, '', 0),
            ],
        ]));

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('sin_metodo_de_pago'));
        $this->assertTrue($respuesta->json('cobro_invalido'));
    }

    /**
     * 🔴 `V2` y `attach_payment_methods()` tienen que leer el `caja_id` igual. Esa función adjunta con
     * `caja_id != 0` (laxo): `true` se guardaba como la caja 1 y `"190.5"` pasaba la validación de la
     * caja 190 y reventaba el INSERT al confirmar. Solo se acepta lo que tiene una lectura única:
     * ausente, null, '' o 0 (sin caja), o un entero positivo / string de dígitos puros.
     *
     * @group presupuestos
     * @test
     */
    public function un_caja_id_mal_formado_se_rechaza_con_422_y_uno_de_digitos_se_acepta()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        foreach ([true, '190.5', 'abc', 190.5, -3, [1]] as $caja_id_malo) {

            $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
                'omitir_en_cuenta_corriente' => 1,
                'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, 0, ['caja_id' => $caja_id_malo])],
            ]));

            $respuesta->assertStatus(422);
            $this->assertTrue($respuesta->json('caja_inexistente'), 'caja_id '.json_encode($caja_id_malo));
            $this->assertTrue($respuesta->json('cobro_invalido'), 'caja_id '.json_encode($caja_id_malo));
        }

        foreach ([(string) $caja->id, $caja->id, null, '', 0, '0'] as $caja_id_bueno) {

            $this->postJson('api/budget', $this->payload_crear($client, [
                'omitir_en_cuenta_corriente' => 1,
                'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, 0, ['caja_id' => $caja_id_bueno])],
            ]))->assertStatus(201);
        }
    }

    /**
     * 🔴 LA CAJA DE OTRO COMERCIO NO SIRVE. `Caja::find()` a secas aceptaba cualquier caja abierta
     * (en las bases compartidas viejas conviven 51 comercios) y el movimiento se escribía en el
     * arqueo ajeno. Se rechaza al guardar, contestada igual que una inexistente.
     *
     * @group presupuestos
     * @test
     */
    public function una_caja_de_otro_dueno_se_rechaza_al_guardar()
    {
        $client = $this->cliente();
        $ajena = $this->caja_abierta_sin_apertura('zz Caja ajena contado', self::OTRO_DUENO_ID);

        $this->assertSame(1, (int) Caja::find($ajena->id)->abierta, 'Control: la caja ajena esta abierta, asi que sin el filtro de dueño pasaria.');

        $movimientos_antes = MovimientoCaja::count();

        $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, $ajena->id)],
        ]));

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('caja_inexistente'));
        $this->assertTrue($respuesta->json('cobro_invalido'));
        $this->assertEquals($movimientos_antes, MovimientoCaja::count());
    }

    /**
     * Lo mismo AL CONFIRMAR: el presupuesto se guardó con una caja propia y después la caja cambió de
     * dueño (en una base compartida, un traspaso o un dato mal cargado). La re-validación usa el
     * dueño del PRESUPUESTO (`budgets.user_id`) y la rechaza, sin crear nada.
     *
     * @group presupuestos
     * @test
     */
    public function una_caja_que_pasa_a_otro_dueno_antes_de_confirmar_da_422()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        Caja::where('id', $caja->id)->update(['user_id' => self::OTRO_DUENO_ID]);

        $movimientos_antes = MovimientoCaja::count();

        $respuesta = $this->confirmar($budget);

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('caja_inexistente'));
        $this->assertFalse(Sale::where('budget_id', $budget->id)->exists());
        $this->assertEquals(self::ESTADO_SIN_CONFIRMAR, (int) Budget::find($budget->id)->budget_status_id);
        $this->assertEquals($movimientos_antes, MovimientoCaja::count(), 'Ninguna caja se movio, ni la ajena.');
    }

    /**
     * Las MISMAS reglas de montos sobre lo que ya estaba guardado (filas viejas, escritas antes de la
     * limpieza o a mano): las filas en 0 se ignoran y la venta se cobra con las que quedan; una fila
     * negativa corta con 422; si todas están en 0, `sin_metodo_de_pago`. Nada de eso llega a la caja.
     *
     * @group presupuestos
     * @test
     */
    public function las_filas_viejas_se_normalizan_o_se_rechazan_al_confirmar()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        // (i) Una fila que cobra y dos en 0 colgadas: se cobra solo la que cobra.
        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja_a->id)]);

        Budget::where('id', $budget->id)->update(['selected_payment_methods' => json_encode([
            $this->fila($this->efectivo_id, 200, $caja_a->id),
            $this->fila($this->transferencia_id, 0, $caja_b->id),
            $this->fila($this->efectivo_id, '', $caja_b->id),
        ])]);

        $this->confirmar(Budget::find($budget->id))->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertEquals(1, DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->count());
        $this->assertEquals(1, MovimientoCaja::where('sale_id', $sale->id)->count());
        $this->assertEquals(0, MovimientoCaja::where('caja_id', $caja_b->id)->count());

        // (ii) Una fila negativa guardada: el reparto suma 200 (250 - 50) y de todos modos se rechaza.
        $con_negativo = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja_a->id)]);

        Budget::where('id', $con_negativo->id)->update(['selected_payment_methods' => json_encode([
            $this->fila($this->efectivo_id, 250, $caja_a->id),
            $this->fila($this->transferencia_id, -50, $caja_b->id),
        ])]);

        $this->confirmar(Budget::find($con_negativo->id))
            ->assertStatus(422)
            ->assertJson(['message' => 'Los montos de los métodos de pago no pueden ser negativos.', 'cobro_invalido' => true]);

        $this->assertFalse(Sale::where('budget_id', $con_negativo->id)->exists());

        // (iii) Todas en 0: sigue siendo "de contado" y recibe el 422 de siempre, no pasa a cuenta corriente.
        $en_cero = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja_a->id)]);

        Budget::where('id', $en_cero->id)->update(['selected_payment_methods' => json_encode([
            $this->fila($this->efectivo_id, 0, $caja_a->id),
        ])]);

        $this->confirmar(Budget::find($en_cero->id))->assertStatus(422)->assertJson(['sin_metodo_de_pago' => true]);

        $this->assertFalse(Sale::where('budget_id', $en_cero->id)->exists());
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  El PUT del form genérico: manda el modelo ENTERO, con el cobro adentro
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 `getModelToSend()` del form genérico de Presupuestos hace `{...this.model}`: en CADA PUT viaja
     * `omitir_en_cuenta_corriente` y `selected_payment_methods` tal como los devolvió el listado. La
     * clave está presente siempre, y sin la comparación contra lo guardado, un presupuesto de contado
     * guardado a la mañana con la Caja 1 no dejaba editar ni las observaciones a la noche, con la caja
     * cerrada (422 `caja_cerrada`).
     *
     * Con el MISMO cobro no se revalidan ni el método ni la caja: se conserva lo guardado, tal cual.
     *
     * @group presupuestos
     * @test
     */
    public function un_put_con_la_forma_del_modelo_recargado_conserva_el_cobro_aunque_la_caja_se_haya_cerrado()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $budget = $this->presupuesto_de_contado($client, $this->reparto_dos_metodos($caja_a, $caja_b));

        $cobro_antes = Budget::find($budget->id)->selected_payment_methods;

        $modelo = $this->modelo_recargado($budget);

        // Pasa la noche: las dos cajas se cierran.
        $this->cerrar_caja($caja_a);
        $this->cerrar_caja($caja_b);

        $modelo['observations'] = 'editada por el form generico';

        $this->putJson('api/budget/'.$budget->id, $modelo)->assertStatus(200);

        $recargado = Budget::find($budget->id);

        $this->assertSame('editada por el form generico', $recargado->observations, 'La edicion se guardo.');
        $this->assertSame(1, (int) $recargado->omitir_en_cuenta_corriente, 'Sigue de contado.');
        $this->assertEquals($cobro_antes, $recargado->selected_payment_methods, 'El cobro se conserva tal cual.');
        $this->assertEquals(200, (float) $recargado->total);
    }

    /**
     * La comparación ignora lo que el form genérico y la SPA agregan o reordenan sin cambiar el
     * cobro: el `__row_id`, el orden de las claves y los tipos (120 / "120.00").
     *
     * @group presupuestos
     * @test
     */
    public function el_mismo_cobro_con_row_id_otro_orden_de_claves_y_otros_tipos_se_conserva()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $budget = $this->presupuesto_de_contado($client, $this->reparto_dos_metodos($caja_a, $caja_b));

        $cobro_antes = Budget::find($budget->id)->selected_payment_methods;

        $modelo = $this->modelo_recargado($budget);

        $filas = [];

        foreach ($modelo['selected_payment_methods'] as $indice => $fila) {

            $fila['__row_id'] = 'fila-'.$indice;
            $fila['amount'] = number_format($fila['amount'], 2, '.', '');

            $filas[] = array_reverse($fila, true);
        }

        $modelo['selected_payment_methods'] = $filas;

        $this->cerrar_caja($caja_a);
        $this->cerrar_caja($caja_b);

        $this->putJson('api/budget/'.$budget->id, $modelo)->assertStatus(200);

        $this->assertEquals($cobro_antes, Budget::find($budget->id)->selected_payment_methods, 'Se conserva el cobro guardado, sin el __row_id ni los tipos nuevos.');
    }

    /**
     * Si el form genérico CAMBIA el reparto, la validación es la completa: con la caja cerrada, 422
     * `caja_cerrada`, y no se toca nada (ni la edición de las observaciones, ni el cobro).
     *
     * @group presupuestos
     * @test
     */
    public function el_mismo_put_cambiando_el_reparto_con_la_caja_cerrada_da_422()
    {
        $client = $this->cliente();
        $caja_a = $this->caja_abierta('zz Caja A contado');
        $caja_b = $this->caja_abierta('zz Caja B contado');

        $budget = $this->presupuesto_de_contado($client, $this->reparto_dos_metodos($caja_a, $caja_b));

        $cobro_antes = Budget::find($budget->id)->selected_payment_methods;

        $modelo = $this->modelo_recargado($budget);

        $this->cerrar_caja($caja_b);

        // Otro reparto (suma lo mismo, 200, pero 100 + 100): el cobro cambio, asi que se valida entero.
        $modelo['observations'] = 'no tiene que quedar';
        $modelo['selected_payment_methods'] = [
            $this->fila($this->efectivo_id, 100, $caja_a->id),
            $this->fila($this->transferencia_id, 100, $caja_b->id),
        ];

        $respuesta = $this->putJson('api/budget/'.$budget->id, $modelo);

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('caja_cerrada'));

        $recargado = Budget::find($budget->id);

        $this->assertNotSame('no tiene que quedar', $recargado->observations);
        $this->assertEquals($cobro_antes, $recargado->selected_payment_methods);
    }

    /**
     * V3 SÍ se sigue pidiendo con el mismo cobro: el `total` del request pudo cambiar (cambiaron los
     * renglones) y el reparto guardado ya no suma. Y se pide SIN volver a mirar las cajas: el 422 es
     * el de la suma, no el de la caja cerrada.
     *
     * @group presupuestos
     * @test
     */
    public function el_mismo_cobro_con_otro_total_da_422_por_la_suma_y_no_por_la_caja()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $budget = $this->presupuesto_de_contado($client, [$this->fila($this->efectivo_id, 200, $caja->id)]);

        $modelo = $this->modelo_recargado($budget);

        $this->cerrar_caja($caja);

        $modelo['total'] = 300;

        $respuesta = $this->putJson('api/budget/'.$budget->id, $modelo);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no coincide con el total', $respuesta->json('message'));
        $this->assertNull($respuesta->json('caja_cerrada'), 'Con el mismo cobro no se vuelve a mirar la caja.');
        $this->assertEquals(200, (float) Budget::find($budget->id)->total);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Atomicidad REAL: un fallo DESPUÉS de crear la venta y descontar el stock
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un reparto que pasa todas las validaciones y revienta recién en `check_caja()`: la fila 1 va a
     * una caja normal (su movimiento se escribe) y la fila 2 a una caja marcada abierta SIN apertura
     * (V2 la acepta, `MovimientoCajaHelper` tira "no tiene ninguna apertura registrada"). Para ese
     * momento `saveSale()` ya creó la venta, descontó el stock, adjuntó los métodos y escribió el
     * movimiento de la fila 1: es lo que el rollback tiene que deshacer.
     *
     * @return array  [reparto, caja_rota]
     */
    protected function reparto_que_revienta_en_la_segunda_fila()
    {
        $caja_a    = $this->caja_abierta('zz Caja A contado');
        $caja_rota = $this->caja_abierta_sin_apertura('zz Caja sin apertura contado');

        return [
            [
                $this->fila($this->efectivo_id, 120, $caja_a->id),
                $this->fila($this->transferencia_id, 80, $caja_rota->id),
            ],
            $caja_rota,
        ];
    }

    /**
     * 🔴 ESTA SÍ ES LA PRUEBA DEL ROLLBACK de `confirmar()`. A diferencia de la caja cerrada (que se
     * detecta ANTES de crear nada), acá el fallo ocurre con la venta creada, el stock descontado y un
     * movimiento de caja ya escrito. Después del 500: sin venta, stock intacto, presupuesto en estado
     * 1 y ni un movimiento nuevo (tampoco el de la fila 1).
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_con_un_fallo_despues_de_crear_la_venta_deja_todo_como_estaba()
    {
        $client = $this->cliente();

        list($reparto, $caja_rota) = $this->reparto_que_revienta_en_la_segunda_fila();

        $budget = $this->presupuesto_de_contado($client, $reparto);

        $movimientos_antes = MovimientoCaja::count();
        $ventas_antes      = Sale::where('user_id', self::USER_ID)->count();
        $cuentas_antes     = CurrentAcount::where('client_id', $client->id)->count();

        $respuesta = $this->confirmar($budget);

        $respuesta->assertStatus(500);
        $this->assertStringContainsString('no tiene ninguna apertura', $respuesta->json('message'), 'Control: el fallo es el de check_caja(), o sea DESPUES de crear la venta y descontar el stock.');

        $this->assertFalse(Sale::where('budget_id', $budget->id)->exists(), 'Sin venta.');
        $this->assertEquals($ventas_antes, Sale::where('user_id', self::USER_ID)->count());
        $this->assertEquals(self::STOCK, (float) Article::find($this->article->id)->stock, 'El stock descontado volvio.');
        $this->assertEquals(self::ESTADO_SIN_CONFIRMAR, (int) Budget::find($budget->id)->budget_status_id, 'El presupuesto sigue sin confirmar.');
        $this->assertEquals($movimientos_antes, MovimientoCaja::count(), 'Ni un movimiento nuevo: tampoco el de la fila 1.');
        $this->assertEquals($cuentas_antes, CurrentAcount::where('client_id', $client->id)->count());
    }

    /**
     * El mismo fallo real, pero por el PUT que CAMBIA el estado a "Confirmado" (la SPA vieja confirma
     * así). El `update()` ya había escrito los campos y rehecho los renglones: todo vuelve atrás, el
     * presupuesto sigue a cuenta corriente y sin reparto, con sus observaciones y su renglón.
     *
     * @group presupuestos
     * @test
     */
    public function un_update_que_confirma_y_falla_despues_de_crear_la_venta_deja_todo_como_estaba()
    {
        $client = $this->cliente();

        list($reparto, $caja_rota) = $this->reparto_que_revienta_en_la_segunda_fila();

        // Un presupuesto a cuenta corriente, como lo guarda la SPA en "No hay cobro".
        $id = $this->postJson('api/budget', $this->payload_crear($client))->assertStatus(201)->json('model.id');
        $budget = Budget::find($id);

        $movimientos_antes = MovimientoCaja::count();
        $ventas_antes      = Sale::where('user_id', self::USER_ID)->count();

        $respuesta = $this->putJson('api/budget/'.$id, $this->payload_actualizar($budget, [
            'budget_status_id'           => self::ESTADO_CONFIRMADO,
            'observations'               => 'no tiene que quedar',
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => $reparto,
        ]));

        $respuesta->assertStatus(500);
        $this->assertStringContainsString('no tiene ninguna apertura', $respuesta->json('message'));

        $recargado = Budget::find($id);

        $this->assertEquals(self::ESTADO_SIN_CONFIRMAR, (int) $recargado->budget_status_id);
        $this->assertNotSame('no tiene que quedar', $recargado->observations, 'Las observaciones nuevas se revirtieron.');
        $this->assertSame(0, (int) $recargado->omitir_en_cuenta_corriente, 'Sigue a cuenta corriente.');
        $this->assertNull($recargado->selected_payment_methods, 'Y sin reparto.');
        $this->assertEquals(1, DB::table('article_budget')->where('budget_id', $id)->count(), 'El renglon sigue.');
        $this->assertEquals($ventas_antes, Sale::where('user_id', self::USER_ID)->count(), 'Sin venta.');
        $this->assertFalse(Sale::where('budget_id', $id)->exists());
        $this->assertEquals(self::STOCK, (float) Article::find($this->article->id)->stock, 'Stock intacto.');
        $this->assertEquals($movimientos_antes, MovimientoCaja::count(), 'Sin movimientos nuevos.');
    }

    /**
     * Y el mismo fallo real en el ALTA de un presupuesto que nace ya confirmado: no queda ni el
     * presupuesto (la fila del INSERT se revierte), ni la venta, ni el stock tocado, ni movimientos.
     *
     * @group presupuestos
     * @test
     */
    public function un_alta_ya_confirmada_que_falla_despues_de_crear_la_venta_no_deja_nada()
    {
        $client = $this->cliente();

        list($reparto, $caja_rota) = $this->reparto_que_revienta_en_la_segunda_fila();

        $presupuestos_antes = Budget::where('user_id', self::USER_ID)->count();
        $movimientos_antes  = MovimientoCaja::count();
        $ventas_antes       = Sale::where('user_id', self::USER_ID)->count();

        $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
            'budget_status_id'           => self::ESTADO_CONFIRMADO,
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => $reparto,
        ]));

        $respuesta->assertStatus(500);
        $this->assertStringContainsString('no tiene ninguna apertura', $respuesta->json('message'));

        $this->assertEquals($presupuestos_antes, Budget::where('user_id', self::USER_ID)->count(), 'No quedo el presupuesto.');
        $this->assertEquals($ventas_antes, Sale::where('user_id', self::USER_ID)->count(), 'Ni la venta.');
        $this->assertEquals(self::STOCK, (float) Article::find($this->article->id)->stock, 'Stock intacto.');
        $this->assertEquals($movimientos_antes, MovimientoCaja::count(), 'Sin movimientos nuevos.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Cheque normal (no endoso)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Una fila de método Cheque con los datos del cheque (el modal de Vender los manda en la fila): al
     * confirmar se crea UN cheque recibido con esos datos, a nombre del cliente. No es un endoso (no
     * trae `cheque_id`), así que V4 no lo toca. Un cheque no mueve caja: sin `caja_id`, el único
     * movimiento es el del efectivo.
     *
     * @group presupuestos
     * @test
     */
    public function una_fila_de_cheque_con_sus_datos_crea_el_cheque_al_confirmar()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');
        $cheque_metodo_id = $this->metodo_del_catalogo('Cheque');

        $numero = 'CH-'.uniqid();

        $budget = $this->presupuesto_de_contado($client, [
            $this->fila($cheque_metodo_id, 150, 0, [
                'numero'          => $numero,
                'banco'           => 'Banco zz de prueba',
                'cheque_banco_id' => 0,
                'fecha_emision'   => '2026-10-01',
                'fecha_pago'      => '2026-10-31',
                'es_echeq'        => 0,
                'notes'           => 'cheque de prueba',
            ]),
            $this->fila($this->efectivo_id, 50, $caja->id),
        ]);

        $this->assertSame($numero, $budget->selected_payment_methods[0]['numero'], 'Los datos del cheque viajan guardados con el presupuesto.');
        $this->assertEquals(0, Cheque::where('numero', $numero)->count(), 'Guardar el presupuesto no crea el cheque: se crea al confirmar.');

        $this->confirmar($budget)->assertStatus(200);

        $cheques = Cheque::where('numero', $numero)->get();

        $this->assertCount(1, $cheques, 'Se creo UN cheque.');

        $cheque = $cheques[0];

        $this->assertSame('recibido', $cheque->tipo);
        $this->assertEquals(150, (float) $cheque->amount);
        $this->assertEquals($client->id, (int) $cheque->client_id, 'A nombre del cliente de la venta.');
        $this->assertEquals(self::USER_ID, (int) $cheque->user_id);
        $this->assertSame('Banco zz de prueba', $cheque->banco);
        $this->assertSame('2026-10-01', $cheque->fecha_emision->format('Y-m-d'));
        $this->assertSame('2026-10-31', $cheque->fecha_pago->format('Y-m-d'));
        $this->assertSame('cheque de prueba', $cheque->notes);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertEquals(2, DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->count(), 'La venta tiene los dos metodos.');

        $movimientos = MovimientoCaja::where('sale_id', $sale->id)->get();

        $this->assertCount(1, $movimientos, 'Solo el efectivo mueve una caja: el cheque no tiene caja.');
        $this->assertEquals(50, (float) $movimientos[0]->ingreso);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  8 bis. El PDF del presupuesto explica el ajuste por método de pago
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El PDF sin comprimir, para poder leer el texto de las filas del pie. Se le saca el logo al
     * usuario (se revierte con el rollback del test) porque `PdfHelper::header()` sale a la RED a
     * chequear que la imagen exista, y un test que depende de internet no mide el PDF.
     *
     * ⚠️ El texto del PDF queda en Latin-1 (`fpdf::Cell()` hace `utf8_decode()`), así que lo que se
     * busca adentro del string tiene que pasar por `utf8_decode()` también: "método" en UTF-8 no está.
     *
     * @param  \App\Models\Budget  $budget
     * @return string
     */
    protected function pdf_del_presupuesto($budget)
    {
        $this->user->image_url = null;
        $this->user->save();

        BudgetPdfDeContadoSinSalir::$ultimo_pdf = null;

        $genero = false;

        try {
            new BudgetPdfDeContadoSinSalir(Budget::find($budget->id), 1, 0);
        } catch (PdfDeContadoGeneradoSinSalir $e) {
            $genero = true;
        }

        $this->assertTrue($genero, 'El PDF del presupuesto dejo de generarse.');
        $this->assertStringStartsWith('%PDF', (string) BudgetPdfDeContadoSinSalir::$ultimo_pdf);

        return (string) BudgetPdfDeContadoSinSalir::$ultimo_pdf;
    }

    /**
     * 🔴 El "Total:" del pie sale de `getTotal()`, que ya incluye el ajuste por método de pago. Sin
     * una fila que lo explique, el PDF dice "Sub Total sin descuentos: $200" y abajo "Total: $190"
     * sin que nada justifique los diez pesos: el pie no suma. Con un descuento por transferencia
     * aparece "Descuento por método de pago"; con un recargo por cuotas, "Recargo por método de
     * pago". Un presupuesto a cuenta corriente no imprime ninguna de las dos.
     *
     * @group presupuestos
     * @test
     */
    public function el_pdf_explica_el_ajuste_por_metodo_de_pago()
    {
        $client = $this->cliente();
        $caja = $this->caja_abierta('zz Caja contado');

        $con_descuento = $this->presupuesto_de_contado($client, [
            $this->fila($this->efectivo_id, 100, $caja->id),
            $this->fila($this->transferencia_id, 90, $caja->id, ['discount_amount' => 10]),
        ], 190);

        $pdf = $this->pdf_del_presupuesto($con_descuento);

        // El IMPORTE y el SIGNO, no solo el texto: el descuento RESTA 10 y el total del pie es el neto.
        $this->assertStringContainsString(utf8_decode('- $10 Descuento por método de pago'), $pdf, 'El pie explica el descuento: signo menos e importe 10.');
        $this->assertStringNotContainsString(utf8_decode('+ $10 Descuento'), $pdf, 'Un descuento no puede salir con signo mas.');
        $this->assertStringContainsString('Sub Total sin descuentos: $200', $pdf, 'Y muestra de donde se parte: la suma de los renglones.');
        $this->assertStringContainsString('Total: $190', $pdf, 'El total del pie es el neto: 200 - 10.');
        $this->assertStringNotContainsString(utf8_decode('Recargo por método de pago'), $pdf);

        $con_recargo = $this->presupuesto_de_contado($client, [
            $this->fila($this->efectivo_id, 100, $caja->id),
            $this->fila($this->credito_id, 120, $caja->id, ['surchage_amount' => 20]),
        ], 220);

        $pdf = $this->pdf_del_presupuesto($con_recargo);

        $this->assertStringContainsString(utf8_decode('+ $20 Recargo por método de pago'), $pdf, 'El pie explica el recargo: signo mas e importe 20.');
        $this->assertStringNotContainsString(utf8_decode('- $20 Recargo'), $pdf, 'Un recargo no puede salir con signo menos.');
        $this->assertStringContainsString('Sub Total sin descuentos: $200', $pdf, 'El recargo tambien muestra de donde se parte (la suma de los renglones es MENOR que el total).');
        $this->assertStringContainsString('Total: $220', $pdf, 'El total del pie es el neto: 200 + 20.');
        $this->assertStringNotContainsString(utf8_decode('Descuento por método de pago'), $pdf);

        $en_cuenta_corriente = Budget::find($this->postJson('api/budget', $this->payload_crear($client))->assertStatus(201)->json('model.id'));

        $pdf = $this->pdf_del_presupuesto($en_cuenta_corriente);

        $this->assertStringNotContainsString(utf8_decode('Descuento por método de pago'), $pdf);
        $this->assertStringNotContainsString(utf8_decode('Recargo por método de pago'), $pdf);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  9. La guarda de esquema: sin la columna, todo guarda como hoy
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 LA GUARDA DE ESQUEMA DE `budgets.selected_payment_methods`. Un deploy de empresa sube los
     * archivos ANTES de migrar; en esa ventana el cliente tiene este código y no la columna, y como
     * `Budget` declara `$guarded = []`, una clave nueva en el INSERT/UPDATE tumbaría el alta y la
     * edición de TODO presupuesto con `Unknown column`. Sin la columna, un request que pide cobro de
     * contado se guarda a cuenta corriente: igual que antes de la misión.
     *
     * Misma técnica que `Guarda_de_esquema_de_combos_Test` (ver su docblock): el DDL va por una
     * conexión PDO APARTE porque en MySQL hace COMMIT implícito, y la transacción del test se cierra
     * antes y se abre una nueva después (en MySQL 8 `information_schema` responde con el snapshot de
     * la transacción abierta). Acá se saca la COLUMNA y no se renombra una tabla.
     *
     * @group presupuestos
     * @test
     */
    public function sin_la_columna_el_alta_guarda_a_cuenta_corriente_y_no_revienta()
    {
        $this->sin_la_columna(function () {

            $client = $this->cliente();

            $respuesta = $this->postJson('api/budget', $this->payload_crear($client, [
                'omitir_en_cuenta_corriente' => 1,
                'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, 0)],
            ]));

            $respuesta->assertStatus(201);

            $budget = Budget::find($respuesta->json('model.id'));

            $this->assertSame(0, (int) $budget->omitir_en_cuenta_corriente, 'Sin la columna el presupuesto va a cuenta corriente.');
            $this->assertNull($budget->selected_payment_methods, 'Leer el atributo ausente da null: no es una excepcion.');
            $this->assertFalse(BudgetCobroHelper::es_de_contado($budget));
            $this->assertEquals(0, BudgetCobroHelper::ajuste_por_metodos_de_pago($budget));
        });
    }

    /**
     * @group presupuestos
     * @test
     */
    public function sin_la_columna_la_edicion_guarda_y_no_revienta()
    {
        $this->sin_la_columna(function () {

            $client = $this->cliente();

            $id = $this->postJson('api/budget', $this->payload_crear($client))->assertStatus(201)->json('model.id');

            $budget = Budget::find($id);

            // Con las claves del cobro (SPA nueva en la ventana del deploy) y sin ellas (form generico).
            $this->putJson('api/budget/'.$id, $this->payload_actualizar($budget, [
                'omitir_en_cuenta_corriente' => 1,
                'selected_payment_methods'   => [$this->fila($this->efectivo_id, 200, 0)],
            ]))->assertStatus(200);

            $this->putJson('api/budget/'.$id, $this->payload_actualizar($budget))->assertStatus(200);

            $this->assertSame(0, (int) Budget::find($id)->omitir_en_cuenta_corriente);
        });
    }

    /**
     * @group presupuestos
     * @test
     */
    public function sin_la_columna_confirmar_y_duplicar_siguen_andando()
    {
        $this->sin_la_columna(function () {

            $this->dar_extension('duplicar_presupuestos', 'Duplicar presupuestos');

            $client = $this->cliente();

            $id = $this->postJson('api/budget', $this->payload_crear($client))->assertStatus(201)->json('model.id');

            $this->post('api/budget/'.$id.'/duplicate')->assertStatus(201);

            $this->confirmar(Budget::find($id))->assertStatus(200);

            $sale = Sale::where('budget_id', $id)->first();

            $this->assertNotNull($sale);
            $this->assertSame(0, (int) $sale->omitir_en_cuenta_corriente);
            $this->assertTrue(CurrentAcount::where('sale_id', $sale->id)->exists(), 'A cuenta corriente, como siempre.');
        });
    }

    /**
     * Centinela: la columna quedó en su lugar. Va último a propósito (PHPUnit corre los tests en el
     * orden en que están declarados): si alguno de los de arriba se cortó sin devolverla, este lo
     * denuncia en vez de dejar la base del slot rota para las corridas siguientes.
     *
     * @group presupuestos
     * @test
     */
    public function la_columna_selected_payment_methods_quedo_en_su_lugar()
    {
        CobroPresupuestoEsquemaHelper::olvidar();

        $this->assertTrue(
            Schema::hasColumn('budgets', CobroPresupuestoEsquemaHelper::COLUMNA),
            'La columna budgets.selected_payment_methods no volvio a su lugar.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Mecánica de la guarda de esquema (copiada de Guarda_de_esquema_de_combos_Test)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Corre el cuerpo del test con la base SIN la columna `budgets.selected_payment_methods`.
     *
     * Deja armado el escenario y lo VERIFICA con dos aserciones de control. Sin ellas, un test que
     * fallara en sacar la columna pasaría igual —con la columna puesta— y no probaría nada. El
     * fixture se arma ADENTRO del closure: lo que se cree antes se lo lleva el `rollBack()`.
     *
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function sin_la_columna($cuerpo)
    {
        DB::rollBack();

        $this->sacar_columna();

        DB::beginTransaction();

        try {

            $this->sembrar_estados();

            // Los fixtures del setUp() se los llevo el rollBack: se vuelven a armar adentro.
            $this->actingAs(User::find(self::USER_ID), 'web');

            $this->lista = PriceType::create([
                'name'     => 'zz Lista (presupuesto de contado)',
                'user_id'  => self::USER_ID,
                'position' => 5,
            ]);

            $this->article = Article::create([
                'name'        => 'zz Articulo presupuesto de contado',
                'user_id'     => self::USER_ID,
                'final_price' => self::PRECIO,
                'costo_real'  => 50,
                'stock'       => self::STOCK,
                'status'      => 'active',
            ]);

            $this->assertFalse(
                CobroPresupuestoEsquemaHelper::hay_columna(),
                'El escenario no se armo: la guarda sigue viendo la columna selected_payment_methods.'
            );

            // Control: escribir la columna tiene que reventar de verdad, como en un cliente sin migrar.
            $exploto = false;

            try {
                DB::table('budgets')->where('id', 0)->update(['selected_payment_methods' => null]);
            } catch (\Illuminate\Database\QueryException $e) {
                $exploto = true;
            }

            $this->assertTrue($exploto, 'El escenario no se armo: escribir la columna todavia funciona.');

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->devolver_columna();

            // Para que el rollback del tearDown del trait tenga una transaccion que cerrar.
            DB::beginTransaction();
        }
    }

    /**
     * Conexión PDO aparte contra la MISMA base, para correr el DDL sin cerrar la transacción del
     * test. Se arma con la config de Laravel, nunca con valores escritos a mano.
     *
     * @return \PDO
     */
    protected function conexion_aparte()
    {
        $config = config('database.connections.'.config('database.default'));

        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // Sin esto un metadata lock cuelga la suite: el default de MySQL para MDL es 1 año.
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        return $pdo;
    }

    /**
     * @return void
     */
    protected function sacar_columna()
    {
        $this->conexion_aparte()->exec('ALTER TABLE `budgets` DROP COLUMN `selected_payment_methods`');

        CobroPresupuestoEsquemaHelper::olvidar();
    }

    /**
     * Vuelve a poner la columna con la misma definición que la migración.
     *
     * @return void
     */
    protected function devolver_columna()
    {
        $this->conexion_aparte()->exec('ALTER TABLE `budgets` ADD COLUMN `selected_payment_methods` LONGTEXT NULL');

        CobroPresupuestoEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se cortó entre el DROP y el ADD, la columna quedó
     * sin devolver. Se devuelve sola antes de que ningún test la necesite.
     *
     * @return void
     */
    protected function restaurar_columna_si_falta()
    {
        if (Schema::hasColumn('budgets', 'selected_payment_methods')) {
            return;
        }

        $this->devolver_columna();
    }
}

/**
 * Excepción de control del test del PDF: la tira `BudgetPdfDeContadoSinSalir::Output()` cuando el
 * PDF ya está armado, para frenar el constructor de `BudgetPdf` justo antes de su `exit`.
 */
class PdfDeContadoGeneradoSinSalir extends \Exception
{
}

/**
 * `BudgetPdf` sin el `exit` del final del constructor y SIN compresión.
 *
 * 🔴 El `exit` mataría el proceso de PHPUnit (ver `BudgetPdfSinSalir` en
 * `Guarda_de_esquema_de_combos_Test`, de donde sale este molde). Se repite con otro nombre y no se
 * reusa esa clase porque viven en otro archivo de test y la suite carga los dos en el mismo
 * proceso: dos clases con el mismo nombre serían un fatal.
 *
 * Y se apaga la compresión de fpdf justo antes de pedir el string: el texto de las filas del pie es
 * lo que se asevera, y comprimido con gzcompress no se puede leer.
 */
class BudgetPdfDeContadoSinSalir extends BudgetPdf
{
    /** El PDF que devolvió fpdf en la última instancia, para poder aseverar sobre él. */
    public static $ultimo_pdf = null;

    /**
     * @param  string  $dest
     * @param  string  $name
     * @param  bool    $isUTF8
     * @return void
     *
     * @throws \Tests\Feature\Presupuestos\PdfDeContadoGeneradoSinSalir siempre.
     */
    function Output($dest = '', $name = '', $isUTF8 = false)
    {
        $this->SetCompression(false);

        Self::$ultimo_pdf = parent::Output('S', '', true);

        throw new PdfDeContadoGeneradoSinSalir();
    }
}
